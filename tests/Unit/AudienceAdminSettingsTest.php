<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAdminSettings;
use FreeFormCertificate\Tests\Support\CallLog;

/**
 * @covers \FreeFormCertificate\Audience\AudienceAdminSettings
 */
class AudienceAdminSettingsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// `LabelSorter::locale()` is guarded by `function_exists( 'get_locale' )`,
		// so this used to take the no-WordPress branch only because nothing in
		// the process had defined that function yet — an accident of file
		// ordering, not a property this test asserts. Pin it to the same locale
		// the fallback produced, so the behaviour under test is chosen.
		Functions\when( 'get_locale' )->justReturn( 'en_US' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( $key === 'ffc_global_holidays' ) return array();
			return $default !== false ? $default : '';
		} );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
			return array_merge( $defaults, (array) $args );
		} );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();

		// wp_count_posts returns an object
		$counts = (object) array( 'publish' => 2, 'draft' => 0, 'trash' => 0 );
		Functions\when( 'wp_count_posts' )->justReturn( $counts );

		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return func_get_arg(0); } )->byDefault();
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
		$wpdb->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
		$wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
	}

	protected function tearDown(): void {
		unset( $_POST['ffc_visibility_action'], $_POST['_wpnonce'], $_GET['tab'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// Constructor
	// ==================================================================

	public function test_constructor_creates_instance(): void {
		$page = new AudienceAdminSettings( 'ffc-scheduling', \Mockery::mock( \FreeFormCertificate\Audience\AudienceAdminImport::class )->shouldIgnoreMissing() );
		$this->assertInstanceOf( AudienceAdminSettings::class, $page );
	}

	// ==================================================================
	// handle_visibility_settings() — no action
	// ==================================================================

	/**
	 * The six message options are read as strings by every consumer, so a
	 * non-scalar must never reach `update_option()`. What the *old* code stored
	 * for an array is deliberately NOT asserted here — that depends on what
	 * WordPress's real `wp_kses_post()` does with an array, which a stubbed
	 * suite cannot answer and #1087 records as unverified. What is asserted is
	 * the contract this guard establishes: a non-string reads as ''.
	 */
	public function test_visibility_message_stores_an_empty_string_for_a_non_scalar(): void {
		Functions\when( 'add_settings_error' )->justReturn( null );

		$written = array();
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$written ) {
				$written[ $key ] = $value;
				return true;
			}
		);

		$_POST['ffc_action']                   = 'save_ss_visibility_settings';
		$_POST['ffc_ss_visibility_nonce']      = 'nonce';
		$_POST['ffc_ss_private_display_mode']  = 'hide';
		$_POST['ffc_ss_visibility_message']    = array( '<b>injected</b>' );
		$_POST['ffc_ss_scheduling_message']    = 'plain text';

		$page = new AudienceAdminSettings( 'ffc-audience', \Mockery::mock( \FreeFormCertificate\Audience\AudienceAdminImport::class )->shouldIgnoreMissing() );
		try {
			$page->handle_visibility_settings();
		} finally {
			// A leaked ffc_action makes the *next* test enter the save branch —
			// clear it even when the call above throws.
			unset(
				$_POST['ffc_action'],
				$_POST['ffc_ss_visibility_nonce'],
				$_POST['ffc_ss_private_display_mode'],
				$_POST['ffc_ss_visibility_message'],
				$_POST['ffc_ss_scheduling_message']
			);
		}

		$this->assertSame( '', $written['ffc_ss_visibility_message'], 'A non-scalar must not reach a string option' );
		$this->assertSame( 'plain text', $written['ffc_ss_scheduling_message'], 'A string value is unchanged' );
	}

	public function test_handle_visibility_settings_does_nothing_without_post(): void {
		unset( $_POST['ffc_visibility_action'] );

		// Past the capability check the method saves through update_option(),
		// so no option write is the proof nothing was persisted.
		$update_option = CallLog::record( 'update_option', true ); // expect()->never() cannot see past setUp's stub (#1699).

		$page = new AudienceAdminSettings( 'ffc-scheduling', \Mockery::mock( \FreeFormCertificate\Audience\AudienceAdminImport::class )->shouldIgnoreMissing() );
		$page->handle_visibility_settings();

		$this->assertSame( 0, $update_option->count() );
	}

	// ==================================================================
	// handle_global_holiday_actions() — no permission
	// ==================================================================

	public function test_handle_global_holiday_actions_returns_early_without_permission(): void {
		// #1030 — this test did not exercise what its name says. The capability
		// check in handle_global_holiday_actions() sits INSIDE the
		// add_global_holiday branch, after the nonce, so with no ffc_action in
		// $_POST the method returned for a completely different reason and the
		// permission guard was never reached. Queue the action and a valid
		// nonce so the capability really is the only thing left to stop it.
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$_POST = array(
			'ffc_action'                => 'add_global_holiday',
			'ffc_global_holiday_nonce'  => 'n',
			'global_holiday_date'       => '2026-12-25',
		);

		// The branch persists through update_option( 'ffc_global_holidays' ).
		$update_option = CallLog::record( 'update_option', true ); // expect()->never() cannot see past setUp's stub (#1699).

		$page = new AudienceAdminSettings( 'ffc-scheduling', \Mockery::mock( \FreeFormCertificate\Audience\AudienceAdminImport::class )->shouldIgnoreMissing() );
		$page->handle_global_holiday_actions();

		$this->assertSame( 0, $update_option->count() );
	}

	// ==================================================================
	// render_page() — default tab
	// ==================================================================

	public function test_render_page_renders_general_tab(): void {
		Functions\when( 'date_i18n' )->alias( function ( $f, $t = null ) { return date( $f, $t ?? time() ); } );
		Functions\when( 'wp_nonce_url' )->justReturn( '/' );

		$page = new AudienceAdminSettings( 'ffc-scheduling', \Mockery::mock( \FreeFormCertificate\Audience\AudienceAdminImport::class )->shouldIgnoreMissing() );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wrap', $output );
	}

	/**
	 * Regression: a holiday saved as 05/06 must render as 05/06, not 04/06.
	 * Holiday dates are wall-clock DATE strings; rendering them through the
	 * instant API on a UTC-3 site rolled them back one day. Drives a UTC-3
	 * site with a timezone-honouring wp_date stub and asserts the list shows
	 * the literal date.
	 */
	public function test_general_tab_renders_holiday_date_without_timezone_shift(): void {
		$prev_tz = date_default_timezone_get(); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_get
		date_default_timezone_set( 'UTC' ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set

		Functions\when( 'date_i18n' )->alias( function ( $f, $t = null ) { return date( $f, $t ?? time() ); } );
		Functions\when( 'wp_nonce_url' )->justReturn( '/' );
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( 'ffc_global_holidays' === $key ) {
				return array( array( 'date' => '2026-06-05', 'description' => 'Test holiday' ) );
			}
			if ( 'ffc_settings' === $key ) {
				return array();
			}
			return false !== $default ? $default : '';
		} );
		Functions\when( 'wp_timezone' )->alias( static function () {
			return new \DateTimeZone( '-03:00' );
		} );
		Functions\when( 'wp_date' )->alias( static function ( $format, $ts = null, $tz = null ) {
			$ts = null === $ts ? time() : (int) $ts;
			$dt = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone(
				$tz instanceof \DateTimeZone ? $tz : new \DateTimeZone( 'UTC' )
			);
			return $dt->format( $format );
		} );

		$page = new AudienceAdminSettings( 'ffc-scheduling', \Mockery::mock( \FreeFormCertificate\Audience\AudienceAdminImport::class )->shouldIgnoreMissing() );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		date_default_timezone_set( $prev_tz ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set

		$this->assertStringContainsString( '05/06/2026', $output );
		$this->assertStringNotContainsString( '04/06/2026', $output );
	}

	// ==================================================================
	// render_page() — every tab, the fallback and an extension tab
	// ==================================================================

	private function page( ?\FreeFormCertificate\Audience\AudienceAdminImport $import = null ): AudienceAdminSettings {
		return new AudienceAdminSettings(
			'ffc-scheduling',
			$import ?? \Mockery::mock( \FreeFormCertificate\Audience\AudienceAdminImport::class )->shouldIgnoreMissing()
		);
	}

	private function render( AudienceAdminSettings $page ): string {
		ob_start();
		try {
			$page->render_page();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	public function test_render_page_self_scheduling_tab_shows_the_stored_messages(): void {
		$_GET['tab'] = 'self-scheduling';
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( 'ffc_ss_private_display_mode' === $key ) {
				return 'hide';
			}
			return 'ffc_ss_business_hours_booking_message' === $key ? 'Stored booking text' : $default;
		} );

		$html = $this->render( $this->page() );

		// #1745: the display mode is a steps control, most shown first.
		$this->assertStringContainsString( 'id="ffc_ss_private_display_mode-3" name="ffc_ss_private_display_mode" value="hide" checked', $html );
		$this->assertStringNotContainsString( '<select name="ffc_ss_private_display_mode"', $html );

		$this->assertStringContainsString( 'id="ffc-scheduling-tabpanel-self-scheduling"', $html );
		$this->assertStringContainsString( 'Stored booking text', $html );
		$this->assertStringContainsString( 'save_ss_visibility_settings', $html );
	}

	public function test_render_page_audience_tab_shows_its_form(): void {
		$_GET['tab'] = 'audience';

		$html = $this->render( $this->page() );

		$this->assertStringContainsString( 'id="ffc-scheduling-tabpanel-audience"', $html );
		$this->assertStringContainsString( 'save_aud_visibility_settings', $html );
		$this->assertStringContainsString( 'aria-selected="true"', $html );
		$this->assertStringContainsString( 'id="ffc_aud_private_display_mode-2" name="ffc_aud_private_display_mode" value="show_message" checked', $html, 'The default is the middle position.' );
	}

	public function test_audience_tab_environments_button_opens_the_environments_view(): void {
		// #1761: Environments is a view of Audience Calendars.
		$_GET['tab'] = 'audience';
		Functions\when( 'admin_url' )->returnArg();

		$html = $this->render( $this->page() );

		$this->assertStringContainsString( '<a href="admin.php?page=ffc-scheduling-calendars&view=environments" class="ffc-btn ffc-btn-neutral ffc-icon-building">Environments</a>', $html );
		$this->assertStringNotContainsString( 'ffc-scheduling-environments', $html );
	}

	public function test_render_page_import_tab_delegates_to_the_import_page(): void {
		$_GET['tab'] = 'import';
		$import      = \Mockery::mock( \FreeFormCertificate\Audience\AudienceAdminImport::class );
		$import->shouldReceive( 'render_content' )->once()->andReturnUsing( function () { echo '<import-body>'; } );

		$html = $this->render( $this->page( $import ) );

		$this->assertStringContainsString( '<import-body>', $html );
	}

	public function test_render_page_falls_back_to_general_for_an_unknown_tab(): void {
		$_GET['tab'] = 'nope';
		Functions\when( 'wp_nonce_url' )->justReturn( '/' );

		$html = $this->render( $this->page() );

		$this->assertStringContainsString( 'id="ffc-scheduling-tabpanel-general"', $html );
		$this->assertStringNotContainsString( 'tabpanel-nope', $html );
	}

	public function test_render_page_renders_a_filter_contributed_tab_through_its_action(): void {
		$_GET['tab'] = 'receipt';
		\Brain\Monkey\Filters\expectApplied( 'ffc_scheduling_settings_tabs' )->once()->andReturnUsing(
			function ( $tabs ) {
				$tabs['receipt'] = array( 'label' => 'Receipt', 'icon' => 'ffc-icon-file' );
				return $tabs;
			}
		);
		\Brain\Monkey\Actions\expectDone( 'ffc_scheduling_settings_render_tab_receipt' )->once();

		$html = $this->render( $this->page() );

		$this->assertStringContainsString( 'id="ffc-scheduling-tabpanel-receipt"', $html );
		$this->assertStringContainsString( '<span class="ffc-settings-tabs__label">Receipt</span>', $html );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function every_tab(): array {
		return array(
			'general'         => array( 'general' ),
			'self-scheduling' => array( 'self-scheduling' ),
			'audience'        => array( 'audience' ),
			'import'          => array( 'import' ),
			'extension tab'   => array( 'receipt' ),
		);
	}

	/**
	 * @dataProvider every_tab
	 */
	public function test_render_page_prints_the_notices_once_on_every_tab( string $tab ): void {
		// Only the Import & Export tab printed `ffc_audience` notices, so the
		// "… settings saved." and `?message=holiday_*` feedback of the other
		// tabs never showed (#1771).
		$_GET['tab'] = $tab;
		Functions\when( 'wp_nonce_url' )->justReturn( '/' );
		\Brain\Monkey\Filters\expectApplied( 'ffc_scheduling_settings_tabs' )->andReturnUsing(
			static function ( $tabs ) {
				$tabs['receipt'] = array( 'label' => 'Receipt', 'icon' => 'ffc-icon-file' );
				return $tabs;
			}
		);
		$calls = array();
		Functions\when( 'get_settings_errors' )->alias(
			static function ( ...$args ) use ( &$calls ) {
				$calls[] = $args;
				return array();
			}
		);

		$this->render( $this->page() );

		$this->assertSame( array( array( 'ffc_audience' ) ), $calls );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function holiday_messages(): array {
		return array(
			'added'   => array( 'holiday_added', 'Holiday added successfully.' ),
			'deleted' => array( 'holiday_deleted', 'Holiday deleted successfully.' ),
		);
	}

	/**
	 * @dataProvider holiday_messages
	 */
	public function test_global_holiday_redirect_message_becomes_a_notice( string $message, string $text ): void {
		$_POST   = array();
		$_GET    = array( 'page' => 'ffc-scheduling-settings', 'tab' => 'general', 'message' => $message );
		$notices = array();
		Functions\when( 'add_settings_error' )->alias(
			static function ( $setting, $code, $msg, $type ) use ( &$notices ) {
				$notices[] = array( $setting, $msg, $type );
				return true;
			}
		);

		try {
			$this->page()->handle_global_holiday_actions();
		} finally {
			$_GET = array();
		}

		$this->assertSame( array( array( 'ffc_audience', $text, 'success' ) ), $notices );
	}

	public function test_global_holiday_message_is_ignored_on_another_screen(): void {
		$_POST = array();
		$_GET  = array( 'page' => 'ffc-scheduling-calendars', 'message' => 'holiday_deleted' );
		$log   = CallLog::record( 'add_settings_error', true );

		try {
			$this->page()->handle_global_holiday_actions();
		} finally {
			$_GET = array();
		}

		$this->assertSame( 0, $log->count() );
	}

	// ==================================================================
	// handle_visibility_settings() — the three forms
	// ==================================================================

	/**
	 * @return \ArrayObject<string, mixed> option => value written.
	 */
	private function record_writes(): \ArrayObject {
		$written = new \ArrayObject();
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( $written ) {
				$written[ $key ] = $value;
				return true;
			}
		);
		return $written;
	}

	/**
	 * @return \ArrayObject<int, string>
	 */
	private function record_notices(): \ArrayObject {
		$notices = new \ArrayObject();
		Functions\when( 'add_settings_error' )->alias(
			static function ( $setting, $code, $message ) use ( $notices ) {
				$notices[] = $message;
			}
		);
		return $notices;
	}

	public function test_visibility_settings_need_the_manage_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$written = $this->record_writes();
		$_POST   = array(
			'ffc_action'              => 'save_ss_visibility_settings',
			'ffc_ss_visibility_nonce' => 'n',
		);

		$this->page()->handle_visibility_settings();
		$_POST = array();

		$this->assertCount( 0, $written );
	}

	public function test_self_scheduling_visibility_with_a_bad_nonce_writes_nothing(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$written = $this->record_writes();
		$_POST   = array(
			'ffc_action'              => 'save_ss_visibility_settings',
			'ffc_ss_visibility_nonce' => 'forged',
		);

		$this->page()->handle_visibility_settings();
		$_POST = array();

		$this->assertCount( 0, $written );
	}

	public function test_self_scheduling_visibility_coerces_an_unknown_display_mode(): void {
		$written = $this->record_writes();
		$notices = $this->record_notices();
		$_POST   = array(
			'ffc_action'                  => 'save_ss_visibility_settings',
			'ffc_ss_visibility_nonce'     => 'n',
			'ffc_ss_private_display_mode' => 'shout',
		);

		$this->page()->handle_visibility_settings();
		$_POST = array();

		$this->assertSame( 'show_message', $written['ffc_ss_private_display_mode'] );
		$this->assertSame( '', $written['ffc_ss_visibility_message'], 'An absent field stores an empty string.' );
		$this->assertSame( array( 'Personal Calendars visibility settings saved.' ), $notices->getArrayCopy() );
	}

	public function test_business_hours_messages_are_saved(): void {
		$written = $this->record_writes();
		$notices = $this->record_notices();
		$_POST   = array(
			'ffc_action'                            => 'save_ss_business_hours_settings',
			'ffc_ss_business_hours_nonce'           => 'n',
			'ffc_ss_business_hours_viewing_message' => 'View during %hours%',
			'ffc_ss_business_hours_booking_message' => 'Book during %hours%',
		);

		$this->page()->handle_visibility_settings();
		$_POST = array();

		$this->assertSame(
			array(
				'ffc_ss_business_hours_viewing_message' => 'View during %hours%',
				'ffc_ss_business_hours_booking_message' => 'Book during %hours%',
			),
			$written->getArrayCopy()
		);
		$this->assertSame( array( 'Business hours restriction messages saved.' ), $notices->getArrayCopy() );
	}

	public function test_business_hours_messages_without_a_nonce_are_ignored(): void {
		$written = $this->record_writes();
		$_POST   = array( 'ffc_action' => 'save_ss_business_hours_settings' );

		$this->page()->handle_visibility_settings();
		$_POST = array();

		$this->assertCount( 0, $written );
	}

	public function test_audience_visibility_normalises_the_mode_and_the_colour(): void {
		$written = $this->record_writes();
		$notices = $this->record_notices();
		$_POST   = array(
			'ffc_action'                       => 'save_aud_visibility_settings',
			'ffc_aud_visibility_nonce'         => 'n',
			'ffc_aud_private_display_mode'     => 'show_title_message',
			'ffc_aud_visibility_message'       => 'Log in',
			'ffc_aud_scheduling_message'       => 'Book',
			'ffc_aud_multiple_audiences_color' => ' #AABBCC ',
		);

		$this->page()->handle_visibility_settings();
		$_POST = array();

		$this->assertSame(
			array(
				'ffc_aud_private_display_mode'     => 'show_title_message',
				'ffc_aud_visibility_message'       => 'Log in',
				'ffc_aud_scheduling_message'       => 'Book',
				'ffc_aud_multiple_audiences_color' => '#aabbcc',
			),
			$written->getArrayCopy()
		);
		$this->assertSame( array( 'Audience visibility settings saved.' ), $notices->getArrayCopy() );
	}

	public function test_audience_visibility_drops_an_invalid_colour_and_mode(): void {
		$written = $this->record_writes();
		$this->record_notices();
		$_POST = array(
			'ffc_action'                       => 'save_aud_visibility_settings',
			'ffc_aud_visibility_nonce'         => 'n',
			'ffc_aud_private_display_mode'     => 'loud',
			'ffc_aud_multiple_audiences_color' => 'red; background:url(x)',
		);

		$this->page()->handle_visibility_settings();
		$_POST = array();

		$this->assertSame( 'show_message', $written['ffc_aud_private_display_mode'] );
		$this->assertSame( '', $written['ffc_aud_multiple_audiences_color'] );
	}

	/**
	 * @return iterable<string, array{0: string, 1: int|null}>
	 */
	public static function posted_self_join_limits(): iterable {
		yield 'in range' => array( '5', 5 );
		yield 'above the ceiling clamps' => array( '99', 20 );
		yield 'below the floor clamps' => array( '0', 1 );
		yield 'empty keeps the stored value' => array( '', null );
		yield 'not a number keeps the stored value' => array( 'many', null );
	}

	/**
	 * The self-join limit is clamped on save, and a cleared or non-numeric
	 * field writes nothing rather than a zero limit (#1916).
	 *
	 * @dataProvider posted_self_join_limits
	 *
	 * @param string   $posted   Posted value.
	 * @param int|null $expected Written value, null for no write.
	 */
	public function test_audience_visibility_saves_the_self_join_limit( string $posted, ?int $expected ): void {
		$written = $this->record_writes();
		$this->record_notices();
		$_POST = array(
			'ffc_action'                   => 'save_aud_visibility_settings',
			'ffc_aud_visibility_nonce'     => 'n',
			'ffc_aud_max_self_join_groups' => $posted,
		);

		$this->page()->handle_visibility_settings();
		$_POST = array();

		if ( null === $expected ) {
			$this->assertArrayNotHasKey( 'ffc_aud_max_self_join_groups', $written->getArrayCopy() );
			$this->assertArrayHasKey( 'ffc_aud_private_display_mode', $written->getArrayCopy(), 'the rest of the form still saves' );
		} else {
			$this->assertSame( $expected, $written['ffc_aud_max_self_join_groups'] );
		}
	}

	public function test_self_join_limit_without_the_capability_is_not_saved(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$written = $this->record_writes();
		$_POST   = array(
			'ffc_action'                   => 'save_aud_visibility_settings',
			'ffc_aud_visibility_nonce'     => 'n',
			'ffc_aud_max_self_join_groups' => '5',
		);

		$this->page()->handle_visibility_settings();
		$_POST = array();

		$this->assertCount( 0, $written );
	}

	public function test_audience_tab_draws_the_self_join_limit_field(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				if ( 'ffc_aud_max_self_join_groups' === $key ) {
					return '6';
				}
				return false !== $default ? $default : '';
			}
		);
		$_GET['tab'] = 'audience';

		$html = $this->render( $this->page() );

		$this->assertMatchesRegularExpression(
			'/<input type="number" name="ffc_aud_max_self_join_groups" id="ffc_aud_max_self_join_groups" class="small-text" required\s+min="1"\s+max="20"\s+value="6">/',
			$html
		);
		$form_end = (int) strpos( $html, '</form>' );
		$this->assertLessThan( $form_end, (int) strpos( $html, 'name="ffc_aud_max_self_join_groups"' ), 'the Save button posts the limit too' );
	}

	public function test_audience_visibility_with_a_bad_nonce_writes_nothing(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$written = $this->record_writes();
		$_POST   = array(
			'ffc_action'               => 'save_aud_visibility_settings',
			'ffc_aud_visibility_nonce' => 'forged',
		);

		$this->page()->handle_visibility_settings();
		$_POST = array();

		$this->assertCount( 0, $written );
	}

	// ==================================================================
	// handle_global_holiday_actions() — add and delete
	// ==================================================================

	/**
	 * @return \ArrayObject<int, string> Redirect targets; the redirect throws to stand in for exit.
	 */
	private function record_redirects(): \ArrayObject {
		$redirects = new \ArrayObject();
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url ) use ( $redirects ) {
				$redirects[] = $url;
				throw new \RuntimeException( 'redirected' );
			}
		);
		return $redirects;
	}

	private function run_holiday_action(): void {
		try {
			$this->page()->handle_global_holiday_actions();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		} finally {
			$_POST = array();
			$_GET  = array();
		}
	}

	/**
	 * @param list<array{date: string, description: string}> $stored
	 */
	private function stored_holidays( array $stored ): void {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( $stored ) {
			return 'ffc_global_holidays' === $key ? $stored : $default;
		} );
	}

	public function test_adding_a_new_holiday_appends_it_and_redirects(): void {
		$this->stored_holidays( array( array( 'date' => '2026-01-01', 'description' => 'New Year' ) ) );
		$written   = $this->record_writes();
		$redirects = $this->record_redirects();
		$_POST     = array(
			'ffc_action'                 => 'add_global_holiday',
			'ffc_global_holiday_nonce'   => 'n',
			'global_holiday_date'        => '2026-12-25',
			'global_holiday_description' => 'Christmas',
		);

		$this->run_holiday_action();

		$this->assertSame(
			array(
				array( 'date' => '2026-01-01', 'description' => 'New Year' ),
				array( 'date' => '2026-12-25', 'description' => 'Christmas' ),
			),
			$written['ffc_global_holidays']
		);
		$this->assertSame( array( 'https://example.com/wp-admin/' ), $redirects->getArrayCopy() );
	}

	public function test_adding_a_duplicate_or_empty_date_changes_nothing(): void {
		$this->stored_holidays( array( array( 'date' => '2026-12-25', 'description' => 'Christmas' ) ) );
		$written   = $this->record_writes();
		$redirects = $this->record_redirects();

		$_POST = array(
			'ffc_action'               => 'add_global_holiday',
			'ffc_global_holiday_nonce' => 'n',
			'global_holiday_date'      => '2026-12-25',
		);
		$this->run_holiday_action();
		$_POST = array(
			'ffc_action'               => 'add_global_holiday',
			'ffc_global_holiday_nonce' => 'n',
			'global_holiday_date'      => '',
		);
		$this->run_holiday_action();

		$this->assertCount( 0, $written );
		$this->assertCount( 2, $redirects, 'Both still return to the General tab.' );
	}

	public function test_adding_a_holiday_with_a_bad_nonce_is_ignored(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$written   = $this->record_writes();
		$redirects = $this->record_redirects();
		$_POST     = array(
			'ffc_action'               => 'add_global_holiday',
			'ffc_global_holiday_nonce' => 'forged',
			'global_holiday_date'      => '2026-12-25',
		);

		$this->run_holiday_action();

		$this->assertCount( 0, $written );
		$this->assertCount( 0, $redirects );
	}

	public function test_deleting_a_holiday_removes_that_index(): void {
		$this->stored_holidays(
			array(
				array( 'date' => '2026-01-01', 'description' => 'A' ),
				array( 'date' => '2026-05-01', 'description' => 'B' ),
			)
		);
		$verified = array();
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, $action ) use ( &$verified ) {
				$verified[] = $action;
				return true;
			}
		);
		$written   = $this->record_writes();
		$redirects = $this->record_redirects();
		$_GET      = array(
			'ffc_action'               => 'delete_global_holiday',
			'holiday_index'            => '0',
			'ffc_global_holiday_nonce' => 'n',
		);

		$this->run_holiday_action();

		$this->assertSame( array( 'delete_global_holiday_0' ), $verified, 'The nonce is bound to the index.' );
		$this->assertSame( array( array( 'date' => '2026-05-01', 'description' => 'B' ) ), $written['ffc_global_holidays'] );
		$this->assertCount( 1, $redirects );
	}

	public function test_deleting_an_unknown_index_writes_nothing(): void {
		$this->stored_holidays( array( array( 'date' => '2026-01-01', 'description' => 'A' ) ) );
		$written   = $this->record_writes();
		$redirects = $this->record_redirects();
		$_GET      = array(
			'ffc_action'               => 'delete_global_holiday',
			'holiday_index'            => '5',
			'ffc_global_holiday_nonce' => 'n',
		);

		$this->run_holiday_action();

		$this->assertCount( 0, $written );
		$this->assertCount( 1, $redirects );
	}

	public function test_deleting_a_holiday_without_the_capability_is_refused(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$written   = $this->record_writes();
		$redirects = $this->record_redirects();
		$_GET      = array(
			'ffc_action'               => 'delete_global_holiday',
			'holiday_index'            => '0',
			'ffc_global_holiday_nonce' => 'n',
		);

		$this->run_holiday_action();

		$this->assertCount( 0, $written );
		$this->assertCount( 0, $redirects );
	}

	public function test_deleting_a_holiday_without_a_nonce_is_refused(): void {
		$written   = $this->record_writes();
		$redirects = $this->record_redirects();
		$_GET      = array(
			'ffc_action'    => 'delete_global_holiday',
			'holiday_index' => '0',
		);

		$this->run_holiday_action();

		$this->assertCount( 0, $written );
		$this->assertCount( 0, $redirects );
	}
}
