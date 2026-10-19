<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationAdmin;

/**
 * Tests for ReregistrationAdmin's page routing, its capability tiers, the
 * campaign save transitions and the editor-only import assets.
 *
 * The renderer and the repositories are aliased, and a save ends in
 * `exit` after its redirect, so every test runs in its own process and the
 * redirect is made to throw.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationAdmin
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ReregistrationAdminPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<string> */
	private array $redirects = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Shared scripts are enqueued through AssetRegistry, which registers
		// them first; false is WordPress's answer for a handle already
		// registered, so nothing is localized twice (#1931).
		Functions\when( 'wp_register_script' )->justReturn( false );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
			return 'https://example.com/wp-admin/' . $path;
		} );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'wp_die' )->alias( function ( $message ) {
			throw new \RuntimeException( 'wp_die: ' . $message );
		} );

		$this->redirects = array();
		$redirects       = &$this->redirects;
		Functions\when( 'wp_safe_redirect' )->alias( function ( $url ) use ( &$redirects ) {
			$redirects[] = $url;
			throw new \RuntimeException( 'redirected' );
		} );
	}

	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param list<string> $caps Capabilities held (never manage_options unless listed).
	 */
	private function grant( array $caps ): void {
		Functions\when( 'current_user_can' )->alias( static fn ( $cap ) => in_array( $cap, $caps, true ) );
	}

	private function invoke( ReregistrationAdmin $admin, string $method ): void {
		$ref = new \ReflectionMethod( ReregistrationAdmin::class, $method );
		$ref->setAccessible( true );
		try {
			$ref->invoke( $admin );
		} catch ( \RuntimeException $e ) {
			if ( 'redirected' !== $e->getMessage() ) {
				throw $e;
			}
		}
	}

	// ==================================================================
	// render_page() — routing and tiers
	// ==================================================================

	private function renderPage(): string {
		ob_start();
		try {
			( new ReregistrationAdmin() )->render_page();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	public function test_a_viewer_cannot_open_the_campaign_editor(): void {
		$this->grant( array( 'ffc_view_reregistration' ) );
		$_GET['view'] = 'edit';
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationAdminRenderer' )->shouldNotReceive( 'render_form' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die: Permission denied.' );

		$this->renderPage();
	}

	public function test_a_viewer_gets_the_read_only_list(): void {
		$this->grant( array( 'ffc_view_reregistration' ) );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationAdminRenderer' )
			->shouldReceive( 'render_list' )->once()->with( 'ffc-reregistration', false );

		$html = $this->renderPage();

		$this->assertSame( '<div class="wrap ffc-admin-page ffc-page-reregistration "></div>', $html );
	}

	public function test_a_manager_gets_the_boxed_editor_for_a_new_campaign(): void {
		// Manage alone opens the screen: the 3-state model does not ask a
		// manage role to hold the view cap as well (#1698).
		$this->grant( array( 'ffc_manage_reregistration' ) );
		$_GET['view'] = 'new';
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationAdminRenderer' )
			->shouldReceive( 'render_form' )->once()->with( 'ffc-reregistration', 0 );

		$html = $this->renderPage();

		$this->assertStringContainsString( 'ffc-page-reregistration ffc-boxed', $html );
	}

	public function test_a_manage_only_role_gets_the_editable_list(): void {
		$this->grant( array( 'ffc_manage_reregistration' ) );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationAdminRenderer' )
			->shouldReceive( 'render_list' )->once()->with( 'ffc-reregistration', true );

		$this->renderPage();
	}

	public function test_a_role_with_neither_tier_is_refused(): void {
		$this->grant( array( 'ffc_view_audiences' ) );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationAdminRenderer' )->shouldNotReceive( 'render_list' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die: Permission denied.' );

		$this->renderPage();
	}

	/**
	 * @return array<string, array{0: list<string>, 1: string}>
	 */
	public static function menu_capability_cases(): array {
		return array(
			'view only'   => array( array( 'ffc_view_reregistration' ), 'ffc_view_reregistration' ),
			'manage only' => array( array( 'ffc_manage_reregistration' ), 'ffc_manage_reregistration' ),
			'both'        => array( array( 'ffc_view_reregistration', 'ffc_manage_reregistration' ), 'ffc_view_reregistration' ),
			'neither'     => array( array(), 'ffc_view_reregistration' ),
		);
	}

	/**
	 * WordPress gates a menu on one capability, so the menu names whichever
	 * tier the user holds; otherwise a manage-only role never reaches the page.
	 *
	 * @dataProvider menu_capability_cases
	 *
	 * @param list<string> $caps     Capabilities held.
	 * @param string       $expected Capability the campaign menu is registered with.
	 */
	public function test_the_menu_is_registered_under_the_tier_the_user_holds( array $caps, string $expected ): void {
		$this->grant( $caps );
		$registered = array();
		Functions\when( 'add_menu_page' )->alias(
			function () use ( &$registered ) {
				$registered[ func_get_arg( 3 ) ] = func_get_arg( 2 );
				return 'hook';
			}
		);
		Functions\when( 'add_submenu_page' )->alias(
			function () use ( &$registered ) {
				$registered[ 'sub:' . func_get_arg( 4 ) ] = func_get_arg( 3 );
				return 'hook';
			}
		);
		Mockery::mock( 'alias:FreeFormCertificate\Core\PluginAreas' )->shouldReceive( 'label' )->andReturn( 'Reregistration' );

		( new ReregistrationAdmin() )->add_menu();

		$this->assertSame( $expected, $registered['ffc-reregistration'] );
		$this->assertSame( $expected, $registered['sub:ffc-reregistration'] );
		$this->assertSame( 'ffc_manage_reregistration', $registered['sub:ffc-custom-fields'] );
	}

	public function test_the_submissions_view_carries_the_id_and_the_edit_tier(): void {
		$this->grant( array( 'manage_options' ) );
		$_GET['view'] = 'submissions';
		$_GET['id']   = '12';
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationAdminRenderer' )
			->shouldReceive( 'render_submissions' )->once()->with( 'ffc-reregistration', 12, true );

		$html = $this->renderPage();

		$this->assertStringNotContainsString( 'ffc-boxed', $html );
	}

	// ==================================================================
	// enqueue_assets() — palette registration and the import client
	// ==================================================================

	/**
	 * @return \ArrayObject<string, list<string>>
	 */
	private function recordEnqueues(): \ArrayObject {
		$log  = new \ArrayObject( array( 'registered' => array(), 'styles' => array(), 'scripts' => array(), 'localized' => array() ) );
		$push = static function ( string $key, string $value ) use ( $log ): void {
			$list       = $log[ $key ];
			$list[]     = $value;
			$log[ $key ] = $list;
		};
		Functions\when( 'wp_register_style' )->alias( static function ( $handle ) use ( $push ) { $push( 'registered', $handle ); } );
		Functions\when( 'wp_enqueue_style' )->alias( static function ( $handle ) use ( $push ) { $push( 'styles', $handle ); } );
		Functions\when( 'wp_enqueue_script' )->alias( static function ( $handle ) use ( $push ) { $push( 'scripts', $handle ); } );
		Functions\when( 'wp_localize_script' )->alias( static function ( $handle, $name ) use ( $push ) { $push( 'localized', $name ); return true; } );
		return $log;
	}

	public function test_the_editor_enqueues_the_palette_and_loads_the_import_client(): void {
		$log          = $this->recordEnqueues();
		$_GET['view'] = 'edit';

		( new ReregistrationAdmin() )->enqueue_assets( 'toplevel_page_ffc-reregistration' );

		$this->assertSame( array(), $log['registered'] );
		$this->assertContains( 'ffc-common', $log['styles'] );
		$this->assertContains( 'ffc-reregistration-import', $log['scripts'] );
		$this->assertSame( array( 'ffcRecordAutosave', 'ffcReregistrationAdmin', 'ffcReregImport' ), $log['localized'] );
		$this->assertNotContains( 'jspdf', $log['scripts'] );
	}

	public function test_the_custom_fields_screen_gets_the_assets_without_the_import_client(): void {
		$log = $this->recordEnqueues();

		( new ReregistrationAdmin() )->enqueue_assets( 'audience_page_ffc-custom-fields' );

		$this->assertContains( 'ffc-reregistration-admin', $log['scripts'] );
		$this->assertNotContains( 'ffc-reregistration-import', $log['scripts'] );
	}

	// ==================================================================
	// handle_actions() — messages and delegation
	// ==================================================================

	public function test_handle_actions_reports_a_known_message_and_delegates_the_workflow(): void {
		$this->grant( array( 'ffc_manage_reregistration' ) );
		$_GET['page']    = 'ffc-reregistration';
		$_GET['message'] = 'reminders_sent';
		$notices         = array();
		Functions\when( 'add_settings_error' )->alias(
			static function ( $setting, $code, $message, $type ) use ( &$notices ) {
				$notices[] = array( $message, $type );
			}
		);
		$actions = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationSubmissionActions' );
		foreach ( array( 'handle_approve', 'handle_reject', 'handle_return_to_draft', 'handle_bulk' ) as $handler ) {
			$actions->shouldReceive( $handler )->once();
		}

		( new ReregistrationAdmin() )->handle_actions();

		$this->assertSame( array( array( 'Reminder emails sent.', 'success' ) ), $notices );
	}

	public function test_handle_actions_ignores_an_unknown_message(): void {
		$this->grant( array( 'ffc_manage_reregistration' ) );
		$_GET['page']    = 'ffc-reregistration';
		$_GET['message'] = 'forged';
		$notices         = array();
		Functions\when( 'add_settings_error' )->alias(
			static function () use ( &$notices ) {
				$notices[] = func_get_args();
			}
		);
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationSubmissionActions' )->shouldIgnoreMissing();

		( new ReregistrationAdmin() )->handle_actions();

		$this->assertSame( array(), $notices );
	}

	/**
	 * After an activation the screen says nobody was mailed yet and names the
	 * daily run that will do it (#1762).
	 */
	public function test_handle_actions_announces_the_next_daily_run_after_activation(): void {
		$this->grant( array( 'ffc_manage_reregistration' ) );
		$_GET['page']        = 'ffc-reregistration';
		$_GET['invitations'] = 'scheduled';
		$notices             = array();
		Functions\when( 'add_settings_error' )->alias(
			static function ( $setting, $code, $message, $type ) use ( &$notices ) {
				$notices[] = array( $code, $message, $type );
			}
		);
		Functions\when( 'wp_next_scheduled' )->alias( static fn( $hook ) => 'ffcertificate_reregistration_expire_hook' === $hook ? 1800000000 : false );
		Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' )
			->shouldReceive( 'format_datetime' )->once()->with( 1800000000 )->andReturn( '15/01/2027 03:00' );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationSubmissionActions' )->shouldIgnoreMissing();

		( new ReregistrationAdmin() )->handle_actions();

		$this->assertCount( 1, $notices );
		$this->assertSame( 'ffc_invitations_scheduled', $notices[0][0] );
		$this->assertStringContainsString( 'on 15/01/2027 03:00', $notices[0][1] );
		$this->assertSame( 'info', $notices[0][2] );
	}

	/**
	 * With the daily event missing, the notice says so instead of naming a
	 * time nobody scheduled.
	 */
	public function test_the_invitations_notice_says_when_the_daily_run_is_not_scheduled(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );

		$message = ReregistrationAdmin::invitations_scheduled_message();

		$this->assertStringContainsString( 'not scheduled', $message );
		$this->assertStringContainsString( 'Settings → Scheduled Tasks', $message );
	}

	// ==================================================================
	// handle_save() — status transitions
	// ==================================================================

	/**
	 * @param array<string, mixed> $post Extra POST fields.
	 */
	private function postSave( int $id, string $status, array $post = array() ): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$_POST = array_merge(
			array(
				'ffc_action'               => 'save_reregistration',
				'ffc_reregistration_nonce' => 'n',
				'reregistration_id'        => (string) $id,
				'rereg_title'              => 'Census',
				'rereg_start_date'         => '2026-01-01T08:00',
				'rereg_end_date'           => '2026-02-01T18:00',
				'rereg_status'             => $status,
				'rereg_reminder_days'      => '3',
				'rereg_audience_ids'       => array( '4', '5' ),
			),
			$post
		);
	}

	public function test_saving_a_draft_campaign_as_active_reopens_and_seeds_in_order_without_mailing(): void {
		$this->postSave( 9, 'active', array( 'rereg_auto_approve' => '1', 'rereg_email_reminder' => '1' ) );
		$order = array();
		$repo  = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( (object) array( 'status' => 'draft' ) );
		$repo->shouldReceive( 'update' )->once()->withArgs(
			static function ( $id, $data ) {
				return 9 === $id && 1 === $data['auto_approve'] && 0 === $data['email_invitation_enabled']
					&& 1 === $data['email_reminder_enabled'] && 3 === $data['reminder_days'] && 'active' === $data['status'];
			}
		);
		$repo->shouldReceive( 'set_audience_ids' )->once()->with( 9, array( 4, 5 ) );
		$repo->shouldReceive( 'reopen_expired_submissions' )->once()->with( 9 )->andReturnUsing( function () use ( &$order ) { $order[] = 'reopen'; return 0; } );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationSubmissionWriter' )
			->shouldReceive( 'create_for_audience_members' )->once()->with( 9, array( 4, 5 ) )->andReturnUsing( function () use ( &$order ) { $order[] = 'seed'; return 0; } );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationEmailHandler' )->shouldNotReceive( 'send_invitations' );

		$this->invoke( new ReregistrationAdmin(), 'handle_save' );

		// Invitations are off in this post, so there is nothing to announce.
		$this->assertSame( array( 'reopen', 'seed' ), $order );
		$this->assertSame( array( 'https://example.com/wp-admin/admin.php?page=ffc-reregistration&view=edit&id=9&message=updated' ), $this->redirects );
	}

	public function test_saving_an_already_active_campaign_does_not_invite_again(): void {
		$this->postSave( 9, 'active' );
		$repo = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' );
		$repo->shouldReceive( 'get_by_id' )->andReturn( (object) array( 'status' => 'active' ) );
		$repo->shouldReceive( 'update' )->once();
		$repo->shouldReceive( 'set_audience_ids' )->once();
		$repo->shouldNotReceive( 'reopen_expired_submissions' );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationEmailHandler' )->shouldNotReceive( 'send_invitations' );

		$this->invoke( new ReregistrationAdmin(), 'handle_save' );

		$this->assertCount( 1, $this->redirects );
	}

	public function test_creating_an_active_campaign_seeds_and_announces_the_daily_invitations(): void {
		$this->postSave( 0, 'active', array( 'rereg_email_invitation' => '1' ) );
		$repo = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' );
		$repo->shouldNotReceive( 'get_by_id' );
		$repo->shouldReceive( 'create' )->once()->andReturn( 41 );
		$repo->shouldReceive( 'set_audience_ids' )->once()->with( 41, array( 4, 5 ) );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationSubmissionWriter' )
			->shouldReceive( 'create_for_audience_members' )->once()->with( 41, array( 4, 5 ) );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationEmailHandler' )->shouldNotReceive( 'send_invitations' );

		$this->invoke( new ReregistrationAdmin(), 'handle_save' );

		$this->assertSame( array( 'https://example.com/wp-admin/admin.php?page=ffc-reregistration&view=edit&id=41&message=created&invitations=scheduled' ), $this->redirects );
	}

	public function test_a_failed_create_neither_links_audiences_nor_redirects(): void {
		$this->postSave( 0, 'draft' );
		$repo = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' );
		$repo->shouldReceive( 'create' )->once()->andReturn( false );
		$repo->shouldNotReceive( 'set_audience_ids' );

		$this->invoke( new ReregistrationAdmin(), 'handle_save' );

		$this->assertSame( array(), $this->redirects );
	}

	// ==================================================================
	// AJAX delegators
	// ==================================================================

	public function test_ajax_view_submission_details_is_delegated_to_the_handler(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		$errors = array();
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data ) use ( &$errors ) {
				$errors[] = $data;
				throw new \RuntimeException( 'json' );
			}
		);

		try {
			( new ReregistrationAdmin() )->ajax_view_submission_details();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		$this->assertCount( 1, $errors, 'The handler refuses a caller without the capability.' );
	}
}
