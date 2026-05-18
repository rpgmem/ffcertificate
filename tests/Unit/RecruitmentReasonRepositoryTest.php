<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentReasonRepository;

/**
 * Tests for RecruitmentReasonRepository — CRUD primitives + the
 * applies_to / color normalization helpers used by the admin UI.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentReasonRepository
 */
class RecruitmentReasonRepositoryTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb             = Mockery::mock( 'wpdb' );
        $wpdb->prefix     = 'wp_';
        $wpdb->insert_id  = 0;
        $wpdb->last_error = '';
        $this->wpdb       = $wpdb;

        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        Functions\when( 'current_time' )->justReturn( '2026-05-18 10:00:00' );
        Functions\when( 'do_action' )->justReturn( null );

        $this->wpdb->shouldReceive( 'prepare' )
            ->andReturnUsing( fn( $sql ) => $sql )
            ->byDefault();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Table name
    // ------------------------------------------------------------------

    public function test_get_table_name_returns_prefixed_name(): void {
        $this->assertSame( 'wp_ffc_recruitment_reason', RecruitmentReasonRepository::get_table_name() );
    }

    // ------------------------------------------------------------------
    // get_by_id() — cache miss + cache hit
    // ------------------------------------------------------------------

    public function test_get_by_id_queries_wpdb_on_cache_miss(): void {
        $row = (object) array( 'id' => '7', 'slug' => 'denied', 'label' => 'Denied' );
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

        $result = RecruitmentReasonRepository::get_by_id( 7 );

        $this->assertSame( $row, $result );
    }

    public function test_get_by_id_returns_null_when_row_missing(): void {
        $this->wpdb->shouldReceive( 'get_row' )->andReturn( null );

        $this->assertNull( RecruitmentReasonRepository::get_by_id( 9999 ) );
    }

    public function test_get_by_id_returns_cached_value_without_db_hit(): void {
        $cached = (object) array( 'id' => '1', 'slug' => 'granted' );
        Functions\when( 'wp_cache_get' )->justReturn( $cached );
        // No get_row call must happen.
        $this->wpdb->shouldReceive( 'get_row' )->never();

        $this->assertSame( $cached, RecruitmentReasonRepository::get_by_id( 1 ) );
    }

    // ------------------------------------------------------------------
    // get_all()
    // ------------------------------------------------------------------

    public function test_get_all_returns_results_ordered_by_label(): void {
        $rows = array(
            (object) array( 'id' => '1', 'label' => 'Aprovado' ),
            (object) array( 'id' => '2', 'label' => 'Negado' ),
        );
        $captured_sql = null;
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function ( $sql ) use ( &$captured_sql ) {
            $captured_sql = $sql;
            return $sql;
        } );
        $this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

        $this->assertSame( $rows, RecruitmentReasonRepository::get_all() );
        $this->assertStringContainsString( 'ORDER BY label ASC', $captured_sql );
    }

    public function test_get_all_returns_empty_array_when_no_rows(): void {
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( null );

        $this->assertSame( array(), RecruitmentReasonRepository::get_all() );
    }

    // ------------------------------------------------------------------
    // create()
    // ------------------------------------------------------------------

    public function test_create_inserts_normalized_row_and_returns_new_id(): void {
        $captured = null;
        $this->wpdb->shouldReceive( 'insert' )->andReturnUsing( function ( $table, $data ) use ( &$captured ) {
            $captured = $data;
            return 1;
        } );
        $this->wpdb->insert_id = 42;

        $id = RecruitmentReasonRepository::create( 'denied', 'Denied', '#ff0000', array( 'denied', 'appeal_denied' ) );

        $this->assertSame( 42, $id );
        $this->assertSame( 'denied', $captured['slug'] );
        $this->assertSame( 'Denied', $captured['label'] );
        $this->assertSame( 'denied,appeal_denied', $captured['applies_to'] );
        $this->assertSame( '2026-05-18 10:00:00', $captured['created_at'] );
        $this->assertSame( '2026-05-18 10:00:00', $captured['updated_at'] );
    }

    public function test_create_returns_false_when_insert_fails(): void {
        $this->wpdb->shouldReceive( 'insert' )->andReturn( false );

        $this->assertFalse( RecruitmentReasonRepository::create( 'duplicate', 'Dup' ) );
    }

    public function test_create_filters_unknown_applies_to_values(): void {
        $captured = null;
        $this->wpdb->shouldReceive( 'insert' )->andReturnUsing( function ( $table, $data ) use ( &$captured ) {
            $captured = $data;
            return 1;
        } );
        $this->wpdb->insert_id = 5;

        RecruitmentReasonRepository::create(
            'x',
            'X',
            '',
            array( 'denied', 'unknown_value', 'granted', 'another_bogus' )
        );

        $this->assertSame( 'denied,granted', $captured['applies_to'] );
    }

    // ------------------------------------------------------------------
    // update()
    // ------------------------------------------------------------------

    public function test_update_returns_false_when_payload_has_no_known_fields(): void {
        $this->wpdb->shouldReceive( 'update' )->never();

        $this->assertFalse( RecruitmentReasonRepository::update( 1, array( 'ignored_field' => 'x' ) ) );
    }

    public function test_update_succeeds_with_partial_payload_and_invalidates_cache(): void {
        $captured = null;
        $this->wpdb->shouldReceive( 'update' )->andReturnUsing( function ( $table, $data, $where ) use ( &$captured ) {
            $captured = compact( 'table', 'data', 'where' );
            return 1;
        } );

        $cache_deletes = array();
        Functions\when( 'wp_cache_delete' )->alias( function ( $key, $group = '' ) use ( &$cache_deletes ) {
            $cache_deletes[] = $key;
            return true;
        } );

        $ok = RecruitmentReasonRepository::update( 7, array( 'label' => 'New Label' ) );

        $this->assertTrue( $ok );
        $this->assertSame( 7, $captured['where']['id'] );
        $this->assertSame( 'New Label', $captured['data']['label'] );
        $this->assertSame( '2026-05-18 10:00:00', $captured['data']['updated_at'] );
        $this->assertContains( 'id_7', $cache_deletes );
    }

    public function test_update_normalizes_applies_to_array_into_csv(): void {
        $captured = null;
        $this->wpdb->shouldReceive( 'update' )->andReturnUsing( function ( $table, $data ) use ( &$captured ) {
            $captured = $data;
            return 1;
        } );

        RecruitmentReasonRepository::update( 1, array( 'applies_to' => array( 'granted', 'denied' ) ) );

        $this->assertSame( 'granted,denied', $captured['applies_to'] );
    }

    // ------------------------------------------------------------------
    // delete()
    // ------------------------------------------------------------------

    public function test_delete_returns_true_on_success_and_clears_cache(): void {
        $this->wpdb->shouldReceive( 'delete' )->andReturn( 1 );

        $deletes = array();
        Functions\when( 'wp_cache_delete' )->alias( function ( $key ) use ( &$deletes ) {
            $deletes[] = $key;
            return true;
        } );

        $this->assertTrue( RecruitmentReasonRepository::delete( 11 ) );
        $this->assertContains( 'id_11', $deletes );
    }

    public function test_delete_returns_false_when_wpdb_delete_returns_false(): void {
        $this->wpdb->shouldReceive( 'delete' )->andReturn( false );

        $this->assertFalse( RecruitmentReasonRepository::delete( 11 ) );
    }

    // ------------------------------------------------------------------
    // normalize_applies_to()
    // ------------------------------------------------------------------

    public function test_normalize_applies_to_dedupes_and_drops_unknowns(): void {
        $this->assertSame(
            'denied,granted',
            RecruitmentReasonRepository::normalize_applies_to( array( 'denied', 'denied', 'granted', 'unknown' ) )
        );
    }

    public function test_normalize_applies_to_empty_returns_empty_string(): void {
        $this->assertSame( '', RecruitmentReasonRepository::normalize_applies_to( array() ) );
    }

    public function test_normalize_applies_to_drops_non_string_entries(): void {
        $this->assertSame(
            'denied',
            RecruitmentReasonRepository::normalize_applies_to( array( 'denied', 42, null, false ) )
        );
    }

    // ------------------------------------------------------------------
    // decode_applies_to()
    // ------------------------------------------------------------------

    public function test_decode_applies_to_empty_returns_full_set(): void {
        $this->assertSame(
            RecruitmentReasonRepository::APPLIES_TO_VALUES,
            RecruitmentReasonRepository::decode_applies_to( '' )
        );
    }

    public function test_decode_applies_to_decodes_csv_back_into_list(): void {
        $this->assertSame(
            array( 'denied', 'granted' ),
            RecruitmentReasonRepository::decode_applies_to( 'denied,granted' )
        );
    }

    public function test_decode_applies_to_drops_unknown_values_and_dedupes(): void {
        $this->assertSame(
            array( 'denied', 'granted' ),
            RecruitmentReasonRepository::decode_applies_to( 'denied,unknown,granted,denied' )
        );
    }

    public function test_decode_applies_to_returns_full_set_when_all_values_unknown(): void {
        $this->assertSame(
            RecruitmentReasonRepository::APPLIES_TO_VALUES,
            RecruitmentReasonRepository::decode_applies_to( 'bogus,more_bogus' )
        );
    }

    // ------------------------------------------------------------------
    // count_references()
    // ------------------------------------------------------------------

    public function test_count_references_returns_int_count(): void {
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( '3' );

        $this->assertSame( 3, RecruitmentReasonRepository::count_references( 7 ) );
    }

    public function test_count_references_returns_zero_when_get_var_null(): void {
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

        $this->assertSame( 0, RecruitmentReasonRepository::count_references( 7 ) );
    }
}
