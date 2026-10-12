<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormEditorPublicCsvDownloadMetabox;

/**
 * @covers \FreeFormCertificate\Admin\FormEditorPublicCsvDownloadMetabox
 */
class FormEditorPublicCsvDownloadMetaboxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private FormEditorPublicCsvDownloadMetabox $metabox;

	/** @var array<string, mixed> */
	private array $meta_values = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'admin_url' )->alias( function ( $path = '' ) { return '/wp-admin/' . $path; } );
		Functions\when( 'wp_nonce_url' )->returnArg();
		Functions\when( 'site_url' )->alias( function ( $path = '' ) { return 'https://example.com' . $path; } );
		Functions\when( 'home_url' )->alias( function ( $path = '' ) { return 'https://example.com' . $path; } );
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		Functions\when( 'get_post_meta' )->alias( function ( $post_id, $key ) {
			return $this->meta_values[ $key ] ?? '';
		} );

		$this->metabox = new FormEditorPublicCsvDownloadMetabox();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render(): string {
		$post     = Mockery::mock( 'WP_Post' );
		$post->ID = 88;
		ob_start();
		$this->metabox->render( $post );
		return (string) ob_get_clean();
	}

	public function test_render_shows_inherit_hint_when_download_limit_blank(): void {
		// No per-form limit → field empty (placeholder) + a hint showing the
		// inherited global default in the subtle highlight chip.
		$html = $this->render();

		$this->assertStringContainsString( 'ffc-global-default', $html );
		$this->assertStringContainsString( 'inherit the global default', $html );
		$this->assertStringContainsString( 'placeholder="Inherit from global"', $html );
	}

	public function test_render_no_inherit_hint_when_download_limit_set(): void {
		$this->meta_values['_ffc_csv_public_limit'] = '5';

		$html = $this->render();

		$this->assertStringContainsString( 'value="5"', $html );
		$this->assertStringNotContainsString( 'ffc-global-default', $html );
		$this->assertStringNotContainsString( 'inherit the global default', $html );
	}

	public function test_render_emits_three_operator_sub_toggles(): void {
		$html = $this->render();

		// The 3 sibling sub-features gated under the master, named via
		// bracket notation on the ffc_csv_public form group.
		$this->assertStringContainsString( 'ffc_csv_public[download_enabled]', $html );
		$this->assertStringContainsString( 'ffc_csv_public[start_early_enabled]', $html );
		$this->assertStringContainsString( 'ffc_csv_public[extend_end_enabled]', $html );
	}

	public function test_render_emits_the_master_present_sentinel(): void {
		$html = $this->render();

		// Hidden sentinel that guarantees the group is present in $_POST even
		// when every toggle is off — required by the save handler.
		$this->assertStringContainsString( 'ffc_csv_public[present]', $html );
	}

	public function test_render_pre_populates_limit_from_meta(): void {
		$this->meta_values['_ffc_csv_public_enabled'] = '1';
		$this->meta_values['_ffc_csv_public_limit']   = 250;

		$html = $this->render();

		$this->assertStringContainsString( '250', $html );
	}

	public function test_render_pre_populates_count_from_meta(): void {
		$this->meta_values['_ffc_csv_public_count'] = 17;

		$html = $this->render();

		$this->assertStringContainsString( '17', $html );
	}

	public function test_render_does_not_throw_with_no_meta(): void {
		$html = $this->render();
		$this->assertNotSame( '', $html );
	}

	// ==================================================================
	// Settings-driven branches, the access link and the audit log
	// ==================================================================

	/**
	 * Serve ffc_settings from a fixed array (every other option stays empty).
	 *
	 * @param array<string, mixed> $settings Settings to expose.
	 */
	private function with_settings( array $settings ): void {
		Functions\when( 'get_option' )->alias(
			static fn( $name ) => 'ffc_settings' === $name ? $settings : array()
		);
	}

	/**
	 * Enable the master toggle with a hash and freeze the clock helpers to UTC.
	 */
	private function enable_master(): void {
		$this->meta_values['_ffc_csv_public_enabled'] = '1';
		$this->meta_values['_ffc_csv_public_hash']    = 'abc123';
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'current_time' )->alias( static fn( $format ) => gmdate( (string) $format ) );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
	}

	/**
	 * Return the text of the two status pills (start early, postpone close).
	 *
	 * @return array{0: string, 1: string}
	 */
	private function status_pills( string $html ): array {
		preg_match_all( '/ffc-status-pill--(\w+)">\s*(.*?)\s*<\/span>/s', $html, $m );
		$this->assertCount( 2, $m[0], 'both status pills render' );
		return array( $m[1][0] . ': ' . $m[2][0], $m[1][1] . ': ' . $m[2][1] );
	}

	public function test_render_clamps_a_global_limit_below_one(): void {
		$this->with_settings( array( 'public_csv_default_limit' => 0 ) );

		$html = $this->render();

		$this->assertStringContainsString( '<span class="ffc-global-default">1</span>', $html );
		$this->assertStringContainsString( 'Current usage: 0 of 1.', $html );
	}

	public function test_render_without_an_end_date_warns_and_with_one_does_not(): void {
		$this->assertStringContainsString( 'This form has no end date configured', $this->render() );

		$this->meta_values['_ffc_geofence_config'] = array( 'date_end' => '2030-01-01' );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );

		$this->assertStringNotContainsString( 'This form has no end date configured', $this->render() );
	}

	public function test_render_relative_share_link_without_a_download_page(): void {
		$this->enable_master();

		$html = $this->render();

		$this->assertStringContainsString( 'value="?form_id=88&hash=abc123"', $html );
		$this->assertStringContainsString( 'Append this query string', $html );
		$this->assertStringNotContainsString( 'A hash will be generated automatically', $html );
	}

	public function test_render_full_share_link_appends_to_the_page_query_string(): void {
		$this->enable_master();
		$this->with_settings( array( 'csv_download_page_url' => 'https://example.com/ops?lang=pt' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'value="https://example.com/ops?lang=pt&form_id=88&hash=abc123"', $html );
		$this->assertStringNotContainsString( 'Append this query string', $html );
	}

	public function test_render_full_share_link_opens_a_query_string_when_the_page_has_none(): void {
		$this->enable_master();
		$this->with_settings( array( 'csv_download_page_url' => 'https://example.com/ops' ) );

		$this->assertStringContainsString( 'value="https://example.com/ops?form_id=88&hash=abc123"', $this->render() );
	}

	public function test_render_audit_summary_cards_cap_notice_and_export_link(): void {
		$this->enable_master();
		$log = array_fill( 0, 100, array( 'result' => 'success' ) );
		$log[0] = array( 'result' => 'wrong_cpf' );
		$log[1] = array( 'result' => 'download_delivered' );
		$this->meta_values['_ffc_csv_public_download_log'] = $log;
		$this->meta_values['_ffc_csv_public_count']        = 3;

		$html = $this->render();

		$this->assertStringContainsString( 'ffc-csv-audit-summary', $html );
		$this->assertMatchesRegularExpression( '/Successful accesses<\/span>\s*<span class="ffc-csv-audit-card-value">98</', $html );
		$this->assertMatchesRegularExpression( '/Successful downloads<\/span>\s*<span class="ffc-csv-audit-card-value">3</', $html );
		$this->assertMatchesRegularExpression( '/Failed accesses<\/span>\s*<span class="ffc-csv-audit-card-value">1</', $html );
		$this->assertStringContainsString( 'Limit of 100 entries reached', $html );
		$this->assertStringContainsString( 'Download audit log (CSV)', $html );
		$this->assertStringContainsString( 'admin-post.php?', $html );
		$this->assertStringContainsString( 'CPFs are stored encrypted at-rest', $html );
		$this->assertStringNotContainsString( 'No download attempts have been logged yet.', $html );
	}

	public function test_render_audit_summary_without_cap_notice_below_the_limit(): void {
		$this->enable_master();
		$this->meta_values['_ffc_csv_public_download_log'] = array( array( 'result' => 'audit_pass' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'ffc-csv-audit-summary', $html );
		$this->assertStringNotContainsString( 'entries reached', $html );
	}

	public function test_status_when_both_sub_features_are_off(): void {
		$this->enable_master();

		list( $start, $end ) = $this->status_pills( $this->render() );

		$this->assertSame( 'info: Early-start is disabled for this form — toggle it on in the Operator features list above.', $start );
		$this->assertStringStartsWith( 'info: Postponing the close is disabled', $end );
	}

	public function test_status_without_dates_asks_for_them(): void {
		$this->enable_master();
		$this->meta_values['_ffc_csv_public_start_early_enabled'] = '1';
		$this->meta_values['_ffc_csv_public_extend_end_enabled']  = '1';

		list( $start, $end ) = $this->status_pills( $this->render() );

		$this->assertStringStartsWith( 'warning: Set a start date', $start );
		$this->assertStringStartsWith( 'warning: Set an end date', $end );
	}

	public function test_status_after_a_one_shot_action_was_used(): void {
		$this->enable_master();
		$this->meta_values['_ffc_csv_public_start_early_enabled'] = '1';
		$this->meta_values['_ffc_csv_public_extend_end_enabled']  = '1';
		$this->meta_values['_ffc_geofence_config']                = array(
			'date_start' => '2020-01-01',
			'date_end'   => '2099-01-01',
		);
		$this->meta_values['_ffc_csv_public_start_opened_at']     = '1700000000';
		$this->meta_values['_ffc_csv_public_start_opened_from']   = '08:00';
		$this->meta_values['_ffc_csv_public_end_postponed_at']    = '1700000000';

		list( $start, $end ) = $this->status_pills( $this->render() );

		$this->assertStringContainsString( 'Already started early once for this form (was 08:00)', $start );
		$this->assertStringContainsString( 'Already postponed once for this form (was —)', $end );
	}

	public function test_status_for_a_form_that_has_ended(): void {
		$this->enable_master();
		$this->meta_values['_ffc_csv_public_start_early_enabled'] = '1';
		$this->meta_values['_ffc_csv_public_extend_end_enabled']  = '1';
		$this->meta_values['_ffc_geofence_config']                = array(
			'date_start' => '2020-01-01',
			'date_end'   => '2020-01-02',
		);

		list( $start, $end ) = $this->status_pills( $this->render() );

		$this->assertStringStartsWith( 'info: This form has already ended — early-start', $start );
		$this->assertStringStartsWith( 'info: This form has already ended — postponing', $end );
	}

	public function test_status_for_a_form_that_is_open_but_closes_another_day(): void {
		$this->enable_master();
		$this->meta_values['_ffc_csv_public_start_early_enabled'] = '1';
		$this->meta_values['_ffc_csv_public_extend_end_enabled']  = '1';
		$this->meta_values['_ffc_geofence_config']                = array(
			'date_start' => '2020-01-01',
			'date_end'   => '2099-01-01',
		);

		list( $start, $end ) = $this->status_pills( $this->render() );

		$this->assertStringStartsWith( 'info: This form has already started', $start );
		$this->assertStringStartsWith( 'info: Available only on the scheduled close day', $end );
	}

	public function test_status_for_a_form_that_opens_on_a_later_day(): void {
		$this->enable_master();
		$this->meta_values['_ffc_csv_public_start_early_enabled'] = '1';
		$this->meta_values['_ffc_csv_public_extend_end_enabled']  = '1';
		$this->meta_values['_ffc_geofence_config']                = array(
			'date_start' => '2099-01-01',
			'date_end'   => '2099-01-02',
		);

		list( $start, $end ) = $this->status_pills( $this->render() );

		$this->assertStringStartsWith( 'info: Available only on the scheduled start day', $start );
		$this->assertStringStartsWith( 'info: Available once the form is open', $end );
	}

	public function test_status_is_available_on_the_start_and_close_day(): void {
		$this->enable_master();
		$this->meta_values['_ffc_csv_public_start_early_enabled'] = '1';
		$this->meta_values['_ffc_csv_public_extend_end_enabled']  = '1';
		$today = gmdate( 'Y-m-d' );

		// Starts later today: early start is available.
		$this->meta_values['_ffc_geofence_config'] = array(
			'date_start' => $today,
			'time_start' => '23:59:59',
			'date_end'   => '2099-01-01',
		);
		list( $start ) = $this->status_pills( $this->render() );
		$this->assertStringStartsWith( 'success: Available — operators visiting the public download page (URL above)', $start );

		// Opened yesterday, closes later today: postpone is available.
		$this->meta_values['_ffc_geofence_config'] = array(
			'date_start' => '2020-01-01',
			'date_end'   => $today,
			'time_end'   => '23:59:59',
		);
		list( , $end ) = $this->status_pills( $this->render() );
		$this->assertStringStartsWith( 'success: Available — operators visiting the public download page will see a "Postpone close" button', $end );
	}
}
