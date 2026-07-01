<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormListColumns;

/**
 * Tests for FormListColumns: column registration, column rendering, and ID search.
 *
 * @covers \FreeFormCertificate\Admin\FormListColumns
 */
class FormListColumnsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // pcov attribution: preload so its lines attribute to this test.
        class_exists( '\\FreeFormCertificate\\Admin\\FormListColumns' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( 'admin_url' )->alias( function ( $path ) { return 'https://example.test/wp-admin/' . $path; } );
        Functions\when( 'number_format_i18n' )->alias( function ( $n ) { return (string) $n; } );
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );

        // Reset the static cache between tests.
        $ref = new \ReflectionClass( FormListColumns::class );
        $prop = $ref->getProperty( 'submission_counts_cache' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Seed the private static submission-counts cache so render_column's
     * ffc_submissions branch resolves without touching the DB.
     *
     * @param array<int, int> $counts form_id => count.
     */
    private function set_counts_cache( array $counts ): void {
        $ref  = new \ReflectionClass( FormListColumns::class );
        $prop = $ref->getProperty( 'submission_counts_cache' );
        $prop->setAccessible( true );
        $prop->setValue( null, $counts );
    }

    // ------------------------------------------------------------------
    // add_columns
    // ------------------------------------------------------------------

    public function test_add_columns_inserts_id_and_shortcode_columns(): void {
        $columns = array(
            'cb'    => '',
            'title' => 'Title',
            'date'  => 'Date',
        );

        $result = FormListColumns::add_columns( $columns );

        $this->assertArrayHasKey( 'ffc_form_id', $result );
        $this->assertArrayHasKey( 'ffc_shortcode', $result );
        $this->assertArrayHasKey( 'ffc_submissions', $result );
        $this->assertArrayHasKey( 'ffc_csv_downloads', $result );
    }

    public function test_add_columns_preserves_original_columns(): void {
        $columns = array(
            'cb'    => '',
            'title' => 'Title',
            'date'  => 'Date',
        );

        $result = FormListColumns::add_columns( $columns );

        $this->assertArrayHasKey( 'cb', $result );
        $this->assertArrayHasKey( 'title', $result );
        $this->assertArrayHasKey( 'date', $result );
    }

    public function test_add_columns_places_id_after_cb(): void {
        $columns = array(
            'cb'    => '',
            'title' => 'Title',
        );

        $keys = array_keys( FormListColumns::add_columns( $columns ) );

        $this->assertSame( 'cb', $keys[0] );
        $this->assertSame( 'ffc_form_id', $keys[1] );
        $this->assertSame( 'title', $keys[2] );
    }

    // ------------------------------------------------------------------
    // render_column
    // ------------------------------------------------------------------

    public function test_render_column_form_id_outputs_code_tag(): void {
        ob_start();
        FormListColumns::render_column( 'ffc_form_id', 42 );
        $output = ob_get_clean();

        $this->assertStringContainsString( '<code>42</code>', $output );
    }

    public function test_render_column_shortcode_outputs_copy_button(): void {
        ob_start();
        FormListColumns::render_column( 'ffc_shortcode', 42 );
        $output = ob_get_clean();

        $this->assertStringContainsString( '[ffc_form id="42"]', $output );
        $this->assertStringContainsString( 'ffc-copy-shortcode', $output );
        $this->assertStringContainsString( 'data-shortcode=', $output );
    }

    public function test_render_column_csv_downloads_empty_when_disabled(): void {
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            return '';
        });

        ob_start();
        FormListColumns::render_column( 'ffc_csv_downloads', 42 );
        $output = ob_get_clean();

        $this->assertStringContainsString( '&mdash;', $output );
    }

    public function test_render_column_csv_downloads_shows_count_and_limit(): void {
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            $map = array(
                '_ffc_csv_public_enabled' => '1',
                '_ffc_csv_public_count'   => 5,
                '_ffc_csv_public_limit'   => 10,
            );
            return $map[ $key ] ?? '';
        });

        ob_start();
        FormListColumns::render_column( 'ffc_csv_downloads', 42 );
        $output = ob_get_clean();

        $this->assertStringContainsString( '5', $output );
        $this->assertStringContainsString( '10', $output );
    }

    public function test_render_column_csv_downloads_unlimited(): void {
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            $map = array(
                '_ffc_csv_public_enabled' => '1',
                '_ffc_csv_public_count'   => 7,
                '_ffc_csv_public_limit'   => 0,
            );
            return $map[ $key ] ?? '';
        });

        ob_start();
        FormListColumns::render_column( 'ffc_csv_downloads', 42 );
        $output = ob_get_clean();

        $this->assertStringContainsString( '7', $output );
        $this->assertStringNotContainsString( ' / ', $output );
    }

    public function test_render_column_submissions_empty_when_zero(): void {
        // Pre-seed the static cache so no DB query runs.
        $this->set_counts_cache( array( 42 => 0 ) );

        ob_start();
        FormListColumns::render_column( 'ffc_submissions', 42 );
        $output = ob_get_clean();

        $this->assertStringContainsString( '&mdash;', $output );
        $this->assertStringContainsString( 'ffc-empty-value', $output );
    }

    public function test_render_column_submissions_links_when_nonzero(): void {
        $this->set_counts_cache( array( 42 => 8 ) );

        ob_start();
        FormListColumns::render_column( 'ffc_submissions', 42 );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'filter_form_id=42', $output );
        $this->assertStringContainsString( '<strong>8</strong>', $output );
    }

    public function test_render_column_submissions_uses_zero_for_unknown_form(): void {
        // Cache present but form id absent => defaults to 0 (empty render).
        $this->set_counts_cache( array( 1 => 5 ) );

        ob_start();
        FormListColumns::render_column( 'ffc_submissions', 99 );
        $output = ob_get_clean();

        $this->assertStringContainsString( '&mdash;', $output );
    }

    public function test_render_column_unknown_key_outputs_nothing(): void {
        ob_start();
        FormListColumns::render_column( 'some_other_column', 42 );
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }

    // ------------------------------------------------------------------
    // render_column => ffc_features (render_features_column / get_feature_states)
    // ------------------------------------------------------------------

    public function test_render_column_features_renders_three_toggles(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            $map = array(
                '_ffc_csv_public_enabled' => '1',
                '_ffc_form_config'        => array( 'quiz_enabled' => 1 ),
                '_ffc_device_limit'       => array( 'enabled' => 1 ),
            );
            return $map[ $key ] ?? '';
        } );

        ob_start();
        FormListColumns::render_column( 'ffc_features', 42 );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'ffc-features-cell', $output );
        $this->assertStringContainsString( 'ffc_features_csv_public_enabled_42', $output );
        $this->assertStringContainsString( 'ffc_features_quiz_enabled_42', $output );
        $this->assertStringContainsString( 'ffc_features_device_enabled_42', $output );
        $this->assertStringContainsString( 'data-ffc-feature', $output );
        $this->assertStringContainsString( 'ffc-features-badge', $output );
        // All features on => checkboxes are checked.
        $this->assertStringContainsString( 'checked', $output );
    }

    public function test_render_column_features_disabled_when_user_cannot_edit(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'get_post_meta' )->justReturn( '' );

        ob_start();
        FormListColumns::render_column( 'ffc_features', 42 );
        $output = ob_get_clean();

        // No edit cap => toggles render disabled and unchecked.
        $this->assertStringContainsString( 'disabled', $output );
        $this->assertStringNotContainsString( 'checked', $output );
    }

    // ------------------------------------------------------------------
    // sortable_columns
    // ------------------------------------------------------------------

    public function test_sortable_columns_registers_form_id(): void {
        $result = FormListColumns::sortable_columns( array() );

        $this->assertArrayHasKey( 'ffc_form_id', $result );
        $this->assertSame( 'ID', $result['ffc_form_id'] );
    }

    // ------------------------------------------------------------------
    // search_by_id
    // ------------------------------------------------------------------

    public function test_search_by_id_does_nothing_outside_admin(): void {
        Functions\when( 'is_admin' )->justReturn( false );

        $query = Mockery::mock( '\WP_Query' );
        $query->shouldReceive( 'is_main_query' )->andReturn( true );
        $query->shouldNotReceive( 'set' );

        FormListColumns::search_by_id( $query );
        $this->assertTrue( true );
    }

    public function test_search_by_id_skips_non_numeric_search(): void {
        Functions\when( 'is_admin' )->justReturn( true );

        $query = Mockery::mock( '\WP_Query' );
        $query->shouldReceive( 'is_main_query' )->andReturn( true );
        $query->shouldReceive( 'get' )->with( 'post_type' )->andReturn( 'ffc_form' );
        $query->shouldReceive( 'get' )->with( 's' )->andReturn( 'abc' );
        $query->shouldNotReceive( 'set' );

        FormListColumns::search_by_id( $query );
        $this->assertTrue( true );
    }

    public function test_search_by_id_converts_numeric_search_to_post_in(): void {
        Functions\when( 'is_admin' )->justReturn( true );

        $query = Mockery::mock( '\WP_Query' );
        $query->shouldReceive( 'is_main_query' )->andReturn( true );
        $query->shouldReceive( 'get' )->with( 'post_type' )->andReturn( 'ffc_form' );
        $query->shouldReceive( 'get' )->with( 's' )->andReturn( '42' );
        $query->shouldReceive( 'set' )->once()->with( 'post__in', array( 42 ) );
        $query->shouldReceive( 'set' )->once()->with( 's', '' );

        FormListColumns::search_by_id( $query );
        $this->assertTrue( true );
    }

    public function test_search_by_id_skips_non_form_post_type(): void {
        Functions\when( 'is_admin' )->justReturn( true );

        $query = Mockery::mock( '\WP_Query' );
        $query->shouldReceive( 'is_main_query' )->andReturn( true );
        $query->shouldReceive( 'get' )->with( 'post_type' )->andReturn( 'post' );
        $query->shouldNotReceive( 'set' );

        FormListColumns::search_by_id( $query );
        $this->assertTrue( true );
    }
}
