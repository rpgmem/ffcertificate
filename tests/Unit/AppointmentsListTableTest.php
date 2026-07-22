<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Core\RequestInput;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\AppointmentsListTable;

/**
 * Tests for the self-scheduling appointments WP_List_Table.
 *
 * Focus: the column data/formatting logic, prepare_items() query assembly, and
 * the filter dropdown nav. column_id() is skipped — it builds row-action HTML
 * via the parent's row_actions() (unavailable in the bootstrap stub) and is
 * pure markup/url assembly.
 *
 * @covers \FreeFormCertificate\SelfScheduling\AppointmentsListTable
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AppointmentsListTableTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $appt_repo;

    /** @var Mockery\MockInterface */
    private $cal_repo;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $t ) { echo $t; } );
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'admin_url' )->alias( fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );
        Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'submit_button' )->justReturn( null );

        // Constructor instantiates both repositories with `new`.
        $this->appt_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' );
        $this->appt_repo->shouldReceive( 'findAll' )->andReturn( array() )->byDefault();
        $this->appt_repo->shouldReceive( 'count' )->andReturn( 0 )->byDefault();
        $this->cal_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );
        $this->cal_repo->shouldReceive( 'findById' )->andReturn( null )->byDefault();
        $this->cal_repo->shouldReceive( 'getActiveCalendars' )->andReturn( array() )->byDefault();
    }

    protected function tearDown(): void {
        unset( $_GET['calendar_id'], $_GET['status'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    private function call_protected( AppointmentsListTable $table, string $method, array $args = array() ) {
        $ref = new \ReflectionMethod( $table, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( $table, $args );
    }

    public function test_get_columns_declares_expected_keys(): void {
        $table = new AppointmentsListTable();
        $cols  = $table->get_columns();

        $this->assertSame(
            array( 'cb', 'id', 'calendar', 'name', 'email', 'appointment_date', 'time', 'status', 'created_at' ),
            array_keys( $cols )
        );
    }

    public function test_get_sortable_columns_declares_sortable_set(): void {
        $table    = new AppointmentsListTable();
        $sortable = $table->get_sortable_columns();

        $this->assertSame(
            array( 'id', 'calendar', 'appointment_date', 'status', 'created_at' ),
            array_keys( $sortable )
        );
        $this->assertSame( array( 'id', true ), $sortable['id'] );
    }

    public function test_column_default_returns_value_or_dash(): void {
        $table = new AppointmentsListTable();

        $this->assertSame( 'abc', $table->column_default( array( 'foo' => 'abc' ), 'foo' ) );
        $this->assertSame( '-', $table->column_default( array(), 'missing' ) );
    }

    public function test_column_cb_emits_checkbox_with_id(): void {
        $table = new AppointmentsListTable();
        $html  = $table->column_cb( array( 'id' => 33 ) );

        $this->assertStringContainsString( 'name="appointment[]"', $html );
        $this->assertStringContainsString( 'value="33"', $html );
    }

    public function test_column_calendar_links_to_post_when_found(): void {
        $this->cal_repo->shouldReceive( 'findById' )->with( 9 )->andReturn(
            array( 'post_id' => 100, 'title' => 'Clinic' )
        );

        $table = new AppointmentsListTable();
        $out   = $table->column_calendar( array( 'calendar_id' => 9 ) );

        $this->assertStringContainsString( 'post=100', $out );
        $this->assertStringContainsString( 'Clinic', $out );
    }

    public function test_column_calendar_deleted_when_missing(): void {
        $this->cal_repo->shouldReceive( 'findById' )->with( 9 )->andReturn( null );

        $table = new AppointmentsListTable();
        $this->assertSame( '(Deleted)', $table->column_calendar( array( 'calendar_id' => 9 ) ) );
    }

    public function test_column_name_prefers_user_display_name(): void {
        Functions\when( 'get_user_by' )->justReturn( (object) array( 'display_name' => 'Alice' ) );

        $table = new AppointmentsListTable();
        $this->assertSame( 'Alice', $table->column_name( array( 'user_id' => 5, 'name' => 'Fallback' ) ) );
    }

    public function test_column_name_falls_back_to_name_field(): void {
        $table = new AppointmentsListTable();
        $this->assertSame( 'Guest Joe', $table->column_name( array( 'user_id' => 0, 'name' => 'Guest Joe' ) ) );
    }

    public function test_column_name_guest_placeholder_when_no_name(): void {
        $table = new AppointmentsListTable();
        $this->assertSame( '(Guest)', $table->column_name( array( 'user_id' => 0 ) ) );
    }

    /**
     * Alias-mock Core\PiiAccessPolicy with its TIER_* constants mapped and
     * resolve() pinned to the given tier.
     *
     * @param string $tier Tier resolve() should return.
     * @return void
     */
    private function mockPiiPolicy( string $tier ): void {
        Mockery::getConfiguration()->setConstantsMap(
            array(
                'FreeFormCertificate\Core\PiiAccessPolicy' => array(
                    'TIER_UNMASKED' => 'unmasked',
                    'TIER_REVEAL'   => 'reveal',
                    'TIER_MASKED'   => 'masked',
                ),
            )
        );
        Mockery::mock( 'alias:FreeFormCertificate\Core\PiiAccessPolicy' )
            ->shouldReceive( 'resolve' )->andReturn( $tier );
    }

    public function test_column_email_unmasked_tier_shows_plaintext(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
            ->shouldReceive( 'decrypt_field' )->andReturn( 'a@b.com', '' );
        $this->mockPiiPolicy( 'unmasked' );

        $table = new AppointmentsListTable();
        $this->assertSame( 'a@b.com', $table->column_email( array( 'email_encrypted' => 'X' ) ) );
        $this->assertSame( '-', $table->column_email( array() ) );
    }

    public function test_column_email_masked_tier_masks(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
            ->shouldReceive( 'decrypt_field' )->andReturn( 'a@b.com' );
        $this->mockPiiPolicy( 'masked' );
        Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' )
            ->shouldReceive( 'mask_email' )->andReturnUsing( fn( $e ) => 'MASKED:' . (string) $e );

        $table = new AppointmentsListTable();
        $this->assertSame( 'MASKED:a@b.com', $table->column_email( array( 'email_encrypted' => 'X' ) ) );
    }

    public function test_column_time_formats_range(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' )
            ->shouldReceive( 'format_wallclock_time' )->andReturn( '09:00', '10:00' );

        $table = new AppointmentsListTable();
        $this->assertSame(
            '09:00 - 10:00',
            $table->column_time( array( 'start_time' => '09:00:00', 'end_time' => '10:00:00' ) )
        );
    }

    public function test_column_status_known_label_and_fallback(): void {
        $table = new AppointmentsListTable();

        $this->assertStringContainsString( 'ffc-status-confirmed', $table->column_status( array( 'status' => 'confirmed' ) ) );
        // Unknown status → escaped raw value.
        $this->assertSame( 'weird', $table->column_status( array( 'status' => 'weird' ) ) );
    }

    public function test_prepare_items_builds_conditions_and_pagination(): void {
        $_GET['calendar_id'] = '9';
        $_GET['status']      = 'confirmed';

        Functions\when( 'get_user_by' )->justReturn( false );

        $captured = array();
        $this->appt_repo->shouldReceive( 'findAll' )->andReturnUsing(
            function ( $conditions ) use ( &$captured ) {
                $captured = $conditions;
                return array( array( 'id' => 1 ) );
            }
        );
        $this->appt_repo->shouldReceive( 'count' )->andReturn( 5 );

        // RequestInput::get_get_string for the status filter.
        Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
            ->shouldReceive( 'get_get_string' )->andReturn( 'confirmed' );

        $table = new AppointmentsListTable();
        $table->prepare_items();

        $this->assertSame( 9, $captured['calendar_id'] );
        $this->assertSame( 'confirmed', $captured['status'] );
    }

    public function test_extra_tablenav_returns_early_when_not_top(): void {
        $table = new AppointmentsListTable();

        ob_start();
        $this->call_protected( $table, 'extra_tablenav', array( 'bottom' ) );
        $out = ob_get_clean();

        $this->assertSame( '', $out );
    }

    public function test_extra_tablenav_renders_calendar_options(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
            ->shouldReceive( 'get_get_string' )->andReturn( '' );
        $this->cal_repo->shouldReceive( 'getActiveCalendars' )->andReturn(
            array( array( 'id' => 1, 'title' => 'Cal One' ) )
        );

        $table = new AppointmentsListTable();

        ob_start();
        $this->call_protected( $table, 'extra_tablenav', array( 'top' ) );
        $out = ob_get_clean();

        $this->assertStringContainsString( 'Cal One', $out );
        $this->assertStringContainsString( 'All Statuses', $out );
    }
}
