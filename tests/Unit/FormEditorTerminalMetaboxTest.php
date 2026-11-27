<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\FormEditorTerminalMetabox;
use FreeFormCertificate\Frontend\CertificateFormTarget;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The form editor's Terminal tab (#2892): the switch refuses to turn on
 * with the reason, the event's hours come from the Time tab, and each
 * required field the profile cannot fill is named.
 *
 * @covers \FreeFormCertificate\Admin\FormEditorTerminalMetabox
 * @covers \FreeFormCertificate\Frontend\CertificateFormTarget::cannot_enable
 * @covers \FreeFormCertificate\Frontend\CertificateFormTarget::unfilled_required
 */
class FormEditorTerminalMetaboxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed> */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === (int) $n ? $single : $plural );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) { echo $text; } );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ) );
		Functions\when( 'checked' )->alias( static fn( $a, $b = true, $echo = true ) => (string) $a === (string) $b ? ' checked="checked"' : '' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_date' )->alias( static fn( string $format, ?int $ts = null ) => gmdate( $format, $ts ?? time() ) );
		Functions\when( 'date_i18n' )->alias( static fn( string $format, $ts = null ) => gmdate( $format, (int) ( $ts ?? time() ) ) );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key = '' ) => $this->meta[ $key ] ?? '' );

		$this->meta = array(
			'_ffc_form_config'     => array(),
			'_ffc_form_fields'     => array(
				array( 'name' => 'nome_completo', 'label' => 'Name', 'type' => 'text', 'required' => '1' ),
				array( 'name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => '1' ),
				array( 'name' => 'cpf_rf', 'label' => 'CPF/RF', 'type' => 'text', 'required' => '1' ),
			),
			'_ffc_geofence_config' => array(
				'datetime_enabled' => '1',
				'date_start'       => '2026-12-15',
				'date_end'         => '2026-12-15',
				'time_start'       => '09:00',
				'time_end'         => '12:00',
			),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render(): string {
		$post     = Mockery::mock( 'WP_Post' );
		$post->ID = 44;
		ob_start();
		( new FormEditorTerminalMetabox() )->render( $post );
		return (string) ob_get_clean();
	}

	public function test_a_one_day_form_can_turn_the_terminal_on(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'id="' . FormEditorTerminalMetabox::TOGGLE_ID . '"', $html );
		$this->assertDoesNotMatchRegularExpression( '/id="' . FormEditorTerminalMetabox::TOGGLE_ID . '"[^>]*disabled/', $html );
		$this->assertStringContainsString( '09:00', $html );
		$this->assertStringContainsString( '12:00', $html );
		$this->assertStringContainsString( 'href="#ffc-tab-time"', $html, 'the hours link to where they are set' );
		$this->assertStringNotContainsString( 'does not come from the profile', $html, 'name, e-mail and CPF/RF always come from it' );
	}

	public function test_the_switch_refuses_to_turn_on_with_the_reason(): void {
		$this->meta['_ffc_geofence_config']['date_end'] = '2026-12-16';

		$html = $this->render();

		$this->assertMatchesRegularExpression( '/id="' . FormEditorTerminalMetabox::TOGGLE_ID . '"[^>]*disabled/', $html );
		$this->assertStringContainsString( 'set the form\'s opening and closing on the same date', $html );

		$this->meta['_ffc_geofence_config']['date_end']   = '2026-12-15';
		$this->meta['_ffc_form_config']['quiz_enabled'] = '1';
		$this->assertStringContainsString( 'A form with a quiz cannot issue at the terminal', $this->render() );
	}

	public function test_a_form_that_cannot_take_the_terminal_shows_it_off_whatever_was_stored(): void {
		$this->meta['_ffc_form_config'] = array(
			'quiz_enabled'                    => '1',
			CertificateFormTarget::CONFIG_KEY => array( 'enabled' => '1' ),
		);

		$html = $this->render();

		$this->assertMatchesRegularExpression( '/id="' . FormEditorTerminalMetabox::TOGGLE_ID . '"[^>]*disabled/', $html );
		$this->assertDoesNotMatchRegularExpression( '/id="' . FormEditorTerminalMetabox::TOGGLE_ID . '"[^>]*checked/', $html, 'it does not take the terminal, so the switch is off' );
	}

	public function test_a_form_that_never_set_the_switch_takes_the_terminal(): void {
		unset( $this->meta['_ffc_form_config'] );

		$this->assertMatchesRegularExpression( '/id="' . FormEditorTerminalMetabox::TOGGLE_ID . '"[^>]*checked/', $this->render(), 'on by default' );

		$this->meta['_ffc_form_config'] = array( CertificateFormTarget::CONFIG_KEY => array( 'enabled' => '' ) );
		$this->assertDoesNotMatchRegularExpression( '/id="' . FormEditorTerminalMetabox::TOGGLE_ID . '"[^>]*checked/', $this->render(), 'an explicit off stays off' );
	}

	public function test_each_required_field_without_a_profile_source_is_named(): void {
		$this->meta['_ffc_form_fields'][] = array( 'name' => 'school', 'label' => 'School unit', 'type' => 'text', 'required' => '1' );
		$this->meta['_ffc_form_fields'][] = array( 'name' => 'setor', 'label' => 'Division', 'type' => 'text', 'required' => '1', 'profile_source' => 'field:divisao_setor' );
		$this->meta['_ffc_form_fields'][] = array( 'name' => 'notes', 'label' => 'Notes', 'type' => 'text' );
		$this->meta['_ffc_form_fields'][] = array( 'name' => 'turma', 'type' => 'hidden', 'required' => '1' );

		$html = $this->render();

		$this->assertStringContainsString( '1 required field does not come from the profile', $html );
		$this->assertStringContainsString( 'School unit', $html );
		$this->assertStringNotContainsString( 'Division.', $html );
		$this->assertStringContainsString( 'href="#ffc-tab-builder"', $html );
		$this->assertSame( array( 'School unit' ), CertificateFormTarget::unfilled_required( $this->meta['_ffc_form_fields'] ) );
	}

	/**
	 * The side box leads to the readings screen only for whoever may see it
	 * (#2892).
	 *
	 * @param array<int, string> $caps Capabilities the viewer holds.
	 */
	private function render_side( array $caps ): string {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => in_array( $cap, $caps, true ) );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.test/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		Functions\when( 'esc_url' )->returnArg();
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$GLOBALS['wpdb'] = $wpdb;
		$post            = Mockery::mock( 'WP_Post' );
		$post->ID        = 44;
		ob_start();
		( new FormEditorTerminalMetabox() )->render_side( $post );
		return (string) ob_get_clean();
	}

	public function test_the_side_box_links_to_the_readings_for_whoever_may_see_them(): void {
		$html = $this->render_side( array( 'ffc_checkin_certificates' ) );
		$this->assertStringContainsString( 'View readings', $html );
		$this->assertStringContainsString( 'page=ffc-certificate-readings&form_id=44', $html );

		$this->assertStringNotContainsString( 'View readings', $this->render_side( array( 'ffc_edit_certificates' ) ) );
	}
}
