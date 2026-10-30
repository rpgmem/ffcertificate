<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Shortcodes\DashboardShortcode;

/**
 * Tests for DashboardShortcode: the login notice, the tab set each
 * capability opens, the admin view-as banner, the reregistration banners and
 * the no-cache headers.
 *
 * Every collaborator is the real class — DashboardViewMode,
 * DashboardAssetManager, AssetHelper, AssetRegistry, RequestInput,
 * ReregistrationFrontend over ReregistrationRepository and
 * ReregistrationSubmissionReader, AudienceReader, RecruitmentDashboardSection
 * over the recruitment readers, DateFormatter, Notice, Button — over a
 * `$wpdb` double that answers each read by its SQL text from the arrays a
 * test sets. Nothing here writes, so the double knows no write and any
 * attempt fails the test. Enqueued styles and scripts are recorded.
 *
 * @covers \FreeFormCertificate\Shortcodes\DashboardShortcode
 */
class DashboardShortcodeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<array<int, mixed>> Every prepare() call, as its flattened argument list. */
	private array $prepared = array();

	/** @var list<string> Style handles enqueued, in order. */
	private array $styles = array();

	/** @var list<string> Script handles enqueued, in order. */
	private array $scripts = array();

	/** @var array<string, mixed> The `ffc_settings` option. */
	private array $settings = array();

	/** @var list<object> The user's reregistration submissions (the tab gate). */
	private array $user_submissions = array();

	/** @var list<object> The user's audiences. */
	private array $user_audiences = array();

	/** @var array<int, object> Audiences by id. */
	private array $audiences = array();

	/** @var list<object> Active campaigns the user's audiences reach. */
	private array $active_campaigns = array();

	/** @var list<object> Expired campaigns with an approved submission of the user. */
	private array $expired_campaigns = array();

	/** @var array<int, object> The user's submission by campaign id. */
	private array $submissions_by_campaign = array();

	/** @var list<object> Recruitment candidates linked to the user. */
	private array $candidates = array();

	/** @var list<object> Classifications of those candidates. */
	private array $classifications = array();

	/** @var array<string, array<int, object>> Recruitment rows read by id, by table. */
	private array $recruitment_rows = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$post` global an earlier test left, restored afterwards. */
	private $post_backup;

	/** @var bool Whether `$post` was set before this test. */
	private bool $had_post = false;

	/** @var array<string, mixed> The `$_GET` an earlier test left, restored afterwards. */
	private array $get_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// The campaign window is read on the site's clock (#2242).
		Functions\when( 'current_time' )->alias(
			static function ( $type ) {
				return 'Y-m-d' === $type ? gmdate( 'Y-m-d' ) : ( 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time() );
			}
		);


		$this->get_backup  = $_GET ?? array();
		$_GET              = array();
		$this->had_post    = array_key_exists( 'post', $GLOBALS );
		$this->post_backup = $GLOBALS['post'] ?? null;

		// Shared scripts are enqueued through AssetRegistry, which registers
		// them first; false is WordPress's answer for a handle already
		// registered, so nothing is localized twice (#1931).
		Functions\when( 'wp_register_script' )->justReturn( false );

		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( '_n' )->alias( static fn ( $single, $plural, $n ) => 1 === $n ? $single : $plural );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_x' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr_e' )->alias( function ( $s ) { echo $s; } );
		Functions\when( 'esc_html_e' )->alias( function ( $s ) { echo $s; } );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'add_shortcode' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn ( $k ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $v ) => abs( (int) $v ) );
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n, $decimals = 0 ) => number_format( (float) $n, (int) $decimals ) );
		Functions\when( 'is_email' )->alias( static fn ( $e ) => false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'wp_parse_args' )->alias( static fn ( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// The plugin settings come from $this->settings; every other option
		// answers its default (no verification page, no dashboard page, no
		// custom redirect message). Dark mode is on, so its enqueue shows.
		$this->settings = array( 'dark_mode' => 'on' );
		Functions\when( 'get_option' )->alias(
			fn ( $key, $default = false ) => 'ffc_settings' === $key ? $this->settings : $default
		);
		Functions\when( 'wp_login_url' )->justReturn( 'https://example.com/login' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/dashboard' );
		Functions\when( 'home_url' )->alias( static fn ( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'admin_url' )->alias( static fn ( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'rest_url' )->alias( static fn ( $path = '' ) => 'https://example.com/wp-json/' . $path );
		Functions\when( 'wp_create_nonce' )->justReturn( 'n' );
		Functions\when( 'wp_logout_url' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
		// The approved banner's window is counted on the site clock, and the
		// real ReregistrationFrontend reads it whenever banners load (#1887).
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'America/Sao_Paulo' ) );
		Functions\when( 'wp_date' )->alias(
			static fn ( $format, $ts, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) $ts ) )
				->setTimezone( $tz instanceof \DateTimeZone ? $tz : new \DateTimeZone( 'UTC' ) )
				->format( $format )
		);
		// Not logged in, no capability and nobody to look up, unless a test
		// says otherwise.
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->styles  = array();
		$this->scripts = array();
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( $handle ) {
				$this->styles[] = $handle;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle ) {
				$this->scripts[] = $handle;
			}
		);
		Functions\when( 'wp_localize_script' )->justReturn( true );

		$this->prepared                = array();
		$this->user_submissions        = array();
		$this->user_audiences          = array();
		$this->audiences               = array();
		$this->active_campaigns        = array();
		$this->expired_campaigns       = array();
		$this->submissions_by_campaign = array();
		$this->candidates              = array();
		$this->classifications         = array();
		$this->recruitment_rows        = array();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->wpdbDouble();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		if ( $this->had_post ) {
			$GLOBALS['post'] = $this->post_backup;
		} else {
			unset( $GLOBALS['post'] );
		}
		$_GET = $this->get_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A `$wpdb` double answering the reads the dashboard runs.
	 *
	 * @return Mockery\MockInterface
	 */
	private function wpdbDouble() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( ...$args ) {
				$flat             = 2 === count( $args ) && is_array( $args[1] ) ? array_merge( array( $args[0] ), $args[1] ) : $args;
				$this->prepared[] = $flat;
				return 'Q' . ( count( $this->prepared ) - 1 ) . ':' . $flat[0];
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$text  = (string) $sql;
				$bound = $this->boundTo( $text );
				if ( false !== strpos( $text, 'SELECT s.*, r.title AS reregistration_title' ) ) {
					return $this->user_submissions;
				}
				if ( false !== strpos( $text, 'INNER JOIN %i m ON a.id = m.audience_id' ) ) {
					return $this->user_audiences;
				}
				if ( false !== strpos( $text, 'JOIN %i ra ON r.id = ra.reregistration_id' ) ) {
					return $this->active_campaigns;
				}
				if ( false !== strpos( $text, "r.status = 'expired'" ) ) {
					return $this->expired_campaigns;
				}
				if ( 'wp_ffc_recruitment_candidate' === ( $bound[1] ?? null ) && false !== strpos( $text, 'WHERE user_id = %d' ) ) {
					return $this->candidates;
				}
				if ( false !== strpos( $text, 'WHERE candidate_id = %d' ) ) {
					return $this->classifications;
				}
				if ( false !== strpos( $text, 'WHERE classification_id IN' ) ) {
					// No call yet: every classification is waiting.
					return array();
				}
				$this->fail( 'Unexpected get_results(): ' . $sql . ' with ' . var_export( $bound, true ) );
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				$text  = (string) $sql;
				$bound = $this->boundTo( $text );
				if ( false !== strpos( $text, 'WHERE reregistration_id = %d AND user_id = %d' ) ) {
					return $this->submissions_by_campaign[ (int) $bound[2] ] ?? null;
				}
				if ( false !== strpos( $text, 'SELECT * FROM %i WHERE id = %d' ) ) {
					if ( 'wp_ffc_audiences' === $bound[1] ) {
						return $this->audiences[ (int) $bound[2] ] ?? null;
					}
					if ( isset( $this->recruitment_rows[ $bound[1] ] ) ) {
						return $this->recruitment_rows[ $bound[1] ][ (int) $bound[2] ] ?? null;
					}
				}
				$this->fail( 'Unexpected get_row(): ' . $sql . ' with ' . var_export( $bound, true ) );
			}
		);

		return $wpdb;
	}

	/**
	 * The prepare() argument list behind a prepared query string.
	 *
	 * @return array<int, mixed>
	 */
	private function boundTo( string $sql ): array {
		if ( preg_match( '/^Q(\d+):/', $sql, $match ) ) {
			return $this->prepared[ (int) $match[1] ] ?? array();
		}
		return array( $sql );
	}

	/**
	 * Every prepare() call whose SQL contains $needle.
	 *
	 * @return list<array<int, mixed>>
	 */
	private function preparedWith( string $needle ): array {
		return array_values(
			array_filter( $this->prepared, static fn ( array $call ): bool => false !== strpos( (string) $call[0], $needle ) )
		);
	}

	/**
	 * A user row as get_user_by() answers it.
	 */
	private function user( int $id, string $name, string $email ): \WP_User {
		$user               = new \WP_User( $id );
		$user->display_name = $name;
		$user->user_email   = $email;
		return $user;
	}

	/**
	 * Store an active campaign the user's audience reaches, and the user's
	 * submission in it (none when $status is null).
	 */
	private function activeCampaign( int $id, string $title, string $end_date, ?string $status, ?int $reviewed_at = null ): void {
		$this->active_campaigns[] = (object) array(
			'id'           => (string) $id,
			'title'        => $title,
			'status'       => 'active',
			'start_date'   => '2019-01-01 08:00:00',
			'end_date'     => $end_date,
			'auto_approve' => '0',
		);
		if ( null !== $status ) {
			$this->submissions_by_campaign[ $id ] = $this->submission( $id, $status, $reviewed_at );
		}
	}

	/**
	 * A stored submission of user 1, with its magic token already issued so
	 * no read has to write one.
	 */
	private function submission( int $campaign_id, string $status, ?int $reviewed_at ): object {
		return (object) array(
			'id'                => (string) ( 100 + $campaign_id ),
			'reregistration_id' => (string) $campaign_id,
			'user_id'           => '1',
			'status'            => $status,
			'reviewed_at'       => null === $reviewed_at ? null : (string) $reviewed_at,
			'magic_token'       => 'tok' . $campaign_id,
			// The code exists only on an approved record (#2242).
			'auth_code'         => 'approved' === $status ? 'CODE' . $campaign_id : null,
		);
	}

	/**
	 * The banner's Record button for a campaign's submission (#2242): a
	 * button the dashboard script answers from the signed-in REST route,
	 * never a public link.
	 */
	private function recordButton( int $campaign_id ): string {
		return 'data-submission-id="' . ( 100 + $campaign_id ) . '"';
	}

	// ==================================================================
	// init()
	// ==================================================================

	public function test_init_registers_shortcode_and_action(): void {
		// #1030: "no exception = hooks registered" registered nothing of the
		// sort. An init() that wired neither would have passed.
		$shortcodes = array();
		$actions    = array();
		Functions\when( 'add_shortcode' )->alias(
			static function ( $tag ) use ( &$shortcodes ) {
				$shortcodes[] = $tag;
				return true;
			}
		);
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$actions ) {
				$actions[] = $hook;
				return true;
			}
		);

		DashboardShortcode::init();

		$this->assertContains( 'user_dashboard_personal', $shortcodes );
		$this->assertContains( 'template_redirect', $actions );
	}

	// ==================================================================
	// render() — not logged in
	// ==================================================================

	public function test_render_shows_login_when_not_logged_in(): void {
		$output = DashboardShortcode::render();

		$this->assertStringContainsString( 'logged in', $output );
		$this->assertStringContainsString( 'Login', $output );
		// #1832: the notice is a plugin root and loads the sheets that style it.
		$this->assertMatchesRegularExpression( '#<div class="ffc-shortcode">\s*<div class="ffc-notice ffc-notice-warning"#', $output );
		$this->assertStringContainsString( 'data-ffc-notice="login-required"', $output );
		$this->assertStringContainsString( '<div class="ffc-notice-actions"><a href=', $output );
		$this->assertSame( array( 'ffc-common', 'ffc-dashboard' ), $this->styles );
		$this->assertSame( array( 'ffc-dark-mode' ), $this->scripts, 'The dark-mode toggle is enqueued once, and nothing else.' );
		$this->assertSame( array(), $this->prepared, 'A visitor costs no query.' );
	}

	// ==================================================================
	// render() — logged in, shows dashboard
	// ==================================================================

	public function test_render_shows_dashboard_when_logged_in(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_by' )->justReturn( $this->user( 1, 'Member', 'member@example.org' ) );

		$output = DashboardShortcode::render();

		$this->assertStringContainsString( 'ffc-user-dashboard', $output );
		$this->assertStringContainsString( 'ffc-tabpanel-profile', $output );
		$this->assertStringContainsString( 'Profile', $output );
		$this->assertStringNotContainsString( 'ffc-tab-certificates', $output, 'Without a capability only the profile tab is drawn.' );
		$this->assertSame( 1, count( array_keys( $this->scripts, 'ffc-dark-mode', true ) ), 'The dark-mode toggle is enqueued once.' );
	}

	// ==================================================================
	// send_nocache_headers() — no post object (null)
	// ==================================================================

	public function test_send_nocache_headers_noop_when_no_post(): void {
		$GLOBALS['post'] = null;

		// Past both guards the method calls nocache_headers(); it never running
		// is the proof the guard returned.
		Functions\expect( 'nocache_headers' )->never();

		// is_a(null, 'WP_Post') returns false.
		DashboardShortcode::send_nocache_headers();
	}

	// ==================================================================
	// send_nocache_headers() — post without shortcode
	// ==================================================================

	public function test_send_nocache_headers_noop_when_no_shortcode(): void {
		$post               = new \WP_Post();
		$post->post_content = 'Hello world';
		$GLOBALS['post']    = $post;

		Functions\when( 'has_shortcode' )->justReturn( false );

		// A real WP_Post is present, so the shortcode check is the only thing
		// that can stop it — and nocache_headers() is what it stops.
		Functions\expect( 'nocache_headers' )->never();

		DashboardShortcode::send_nocache_headers();
	}

	// ==================================================================
	// render() — redirect message shown
	// ==================================================================

	public function test_render_shows_redirect_message_when_param_set(): void {
		$_GET['ffc_redirect'] = 'access_denied';

		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_by' )->justReturn( $this->user( 1, 'Member', 'member@example.org' ) );

		$output = DashboardShortcode::render();

		$this->assertStringContainsString( 'redirected from the admin panel', $output );
		$this->assertStringContainsString( 'data-ffc-notice="admin-redirect"', $output );
	}

	// ==================================================================
	// send_nocache_headers() — shortcode present → cache-busting fires
	// ==================================================================

	/**
	 * Runs in its own process: the method defines the process-wide
	 * DONOTCACHEPAGE constant, whose presence is what this test asserts and
	 * which would then hold for every later test, and it calls header(),
	 * which no stub can replace.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_send_nocache_headers_sets_donotcachepage_when_shortcode_present(): void {
		$post               = new \WP_Post();
		$post->post_content = '[user_dashboard_personal]';
		$GLOBALS['post']    = $post;

		Functions\when( 'has_shortcode' )->justReturn( true );

		$nocache_called = false;
		Functions\when( 'nocache_headers' )->alias( function () use ( &$nocache_called ) {
			$nocache_called = true;
		} );
		$action_fired = false;
		Functions\when( 'do_action' )->alias( function ( $hook ) use ( &$action_fired ) {
			if ( 'litespeed_control_set_nocache' === $hook ) {
				$action_fired = true;
			}
		} );
		// header() is a native PHP function Brain\Monkey cannot redefine. Under
		// CLI, output has already been emitted, so the real call would raise a
		// "headers already sent" warning. Suppress it — the assertions below
		// verify the observable side effects (DONOTCACHEPAGE, nocache_headers,
		// the LiteSpeed action) rather than the raw header() call.
		$prev = error_reporting( error_reporting() & ~E_WARNING );
		try {
			DashboardShortcode::send_nocache_headers();
		} finally {
			error_reporting( $prev );
		}

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
		$this->assertTrue( $nocache_called );
		$this->assertTrue( $action_fired );
	}

	// ==================================================================
	// render() — all capability tabs visible (full render path)
	// ==================================================================

	/**
	 * Store what the full render path reads so every tab branch opens: a
	 * user who holds every capability, one reregistration submission (the
	 * tab gate), one audience (the campaigns' reach) and one recruitment
	 * classification waiting for a call.
	 */
	private function wireFullRenderCollaborators( bool $view_as = false ): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_by' )->alias(
			fn ( $field, $id ) => 55 === (int) $id
				? $this->user( 55, 'Viewed User', 'viewed@example.org' )
				: $this->user( (int) $id, 'Member', 'member@example.org' )
		);
		Functions\when( 'wp_get_current_user' )->justReturn( $this->user( 1, 'Site Admin', 'admin@example.org' ) );
		// Every capability check returns true so all cap-gated tabs render.
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->alias( static fn ( $nonce, $action ) => 'view-nonce' === $nonce && 'ffc_view_as_user_55' === $action );
		// The tab bar prints the Log Out link (#1840).
		Functions\when( 'wp_logout_url' )->alias( static fn( $redirect = '' ) => 'https://example.com/wp-login.php?action=logout&redirect_to=' . rawurlencode( $redirect ) . '&_wpnonce=abc' );

		if ( $view_as ) {
			$_GET['ffc_view_as_user'] = '55';
			$_GET['ffc_view_nonce']   = 'view-nonce';
		}

		$this->user_submissions = array( (object) array( 'id' => '1' ) );
		$this->user_audiences   = array( (object) array( 'id' => '2', 'name' => 'Teachers', 'parent_id' => null ) );
		$this->audiences[2]     = (object) array( 'id' => '2', 'name' => 'Teachers', 'parent_id' => null );

		$this->candidates       = array( (object) array( 'id' => '7', 'user_id' => '1' ) );
		$this->classifications  = array(
			(object) array(
				'id'           => '9',
				'candidate_id' => '7',
				'notice_id'    => '3',
				'adjutancy_id' => '4',
				'list_type'    => 'definitive',
				'status'       => 'empty',
				'rank'         => '2',
				'score'        => '7.5',
			),
		);
		$this->recruitment_rows = array(
			'wp_ffc_recruitment_notice'    => array(
				3 => (object) array( 'id' => '3', 'code' => 'ED-01', 'name' => 'Teacher Call', 'status' => 'definitive' ),
			),
			'wp_ffc_recruitment_adjutancy' => array(
				4 => (object) array( 'id' => '4', 'name' => 'Mathematics' ),
			),
		);
	}

	public function test_render_all_tabs_visible_when_all_caps_granted(): void {
		$this->wireFullRenderCollaborators( false );

		$output = DashboardShortcode::render();

		// Every cap-gated tab button + panel present.
		$this->assertStringContainsString( 'ffc-tab-certificates', $output );
		$this->assertStringContainsString( 'ffc-tab-appointments', $output );
		$this->assertStringContainsString( 'ffc-tab-audience', $output );
		$this->assertStringContainsString( 'ffc-tab-reregistrations', $output );
		$this->assertStringContainsString( 'ffc-tab-recruitment', $output );
		$this->assertStringContainsString( 'ffc-tabpanel-certificates', $output );
		$this->assertStringContainsString( 'ffc-tabpanel-recruitment', $output );
		// Server-rendered recruitment body reused as tab content.
		$this->assertMatchesRegularExpression( '#id="ffc-tabpanel-recruitment"[^>]*>\s*<section class="ffc-shortcode ffc-recruitment-my-calls">#', $output );
		$this->assertStringContainsString( '<span class="ffc-mycalls-notice-code">ED-01</span>', $output );
		$this->assertStringContainsString( 'Mathematics', $output );
		// Reregistration form panel present when reregistrations tab visible.
		$this->assertStringContainsString( 'ffc-rereg-form-panel', $output );
		$this->assertSame( 1, $this->preparedWith( 'SELECT s.*, r.title AS reregistration_title' )[0][3] ?? null, 'The tab gate reads the current user\'s submissions.' );
	}

	/**
	 * Log Out sits in the tab bar, after the tablist and outside it, so it
	 * shows on every tab without being announced as a tab (#1840).
	 */
	public function test_render_prints_log_out_in_the_tab_bar_outside_the_tablist(): void {
		$this->wireFullRenderCollaborators( false );
		$escaped = array();
		Functions\when( 'esc_url' )->alias( static function ( $url ) use ( &$escaped ) {
			$escaped[] = $url;
			return str_replace( '&', '&#038;', $url );
		} );

		$output = DashboardShortcode::render();

		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?>' . $output );
		libxml_clear_errors();
		$xpath = new \DOMXPath( $doc );

		$links = $xpath->query( '//a[contains(concat(" ", normalize-space(@class), " "), " ffc-logout-link ")]' );
		$this->assertSame( 1, $links->length, 'Exactly one Log Out link is printed.' );
		$link = $links->item( 0 );
		$this->assertSame( 'https://example.com/wp-login.php?action=logout&redirect_to=https%3A%2F%2Fexample.com&_wpnonce=abc', $link->getAttribute( 'href' ) );
		$this->assertContains( 'https://example.com/wp-login.php?action=logout&redirect_to=https%3A%2F%2Fexample.com&_wpnonce=abc', $escaped, 'The URL goes through esc_url().' );
		$this->assertStringContainsString( 'action=logout&#038;redirect_to=', $output, 'The printed attribute is the escaped URL.' );
		$this->assertStringContainsString( 'Log Out', $link->textContent );
		$this->assertSame( 1, $xpath->query( './/span[contains(@class, "ffc-icon-logout")]', $link )->length );

		$parent = $link->parentNode;
		$this->assertInstanceOf( \DOMElement::class, $parent );
		$this->assertStringContainsString( 'ffc-dashboard-tabbar', $parent->getAttribute( 'class' ) );
		$this->assertSame( 0, $xpath->query( 'ancestor::*[@role="tablist"]', $link )->length, 'The link is not inside the tablist.' );
		$this->assertSame( 1, $xpath->query( '../nav[@role="tablist"]', $link )->length, 'The tablist is its sibling in the bar.' );
	}

	// ==================================================================
	// render() — admin view-as mode renders the banner
	// ==================================================================

	public function test_render_shows_admin_viewing_banner_in_view_as_mode(): void {
		// view_as user 55 differs from current user 1 → banner renders.
		$this->wireFullRenderCollaborators( true );

		$output = DashboardShortcode::render();

		$this->assertStringContainsString( 'data-ffc-notice="admin-viewing"', $output );
		$this->assertStringContainsString( 'Admin View Mode', $output );
		$this->assertStringContainsString( '<strong>Viewed User (v***@example.org)</strong>', $output, 'The viewed user is named, the address masked (#1863).' );
		$this->assertSame( 55, $this->preparedWith( 'SELECT s.*, r.title AS reregistration_title' )[0][3] ?? null, 'The dashboard reads the viewed user\'s data.' );
	}

	// ==================================================================
	// render() — reregistration banners (all status branches)
	// ==================================================================

	public function test_render_reregistration_banners_all_statuses(): void {
		$this->wireFullRenderCollaborators( false );
		// Completed / approved (not submittable).
		$this->activeCampaign( 10, 'Approved Campaign', '2030-01-01', 'approved' );
		// Submitted / pending review (not submittable).
		$this->activeCampaign( 11, 'Submitted Campaign', '2030-01-01', 'submitted' );
		// Actionable — in_progress, far deadline.
		$this->activeCampaign( 12, 'In Progress Campaign', '2030-01-01', 'in_progress' );
		// Actionable — rejected, urgent (deadline soon).
		$this->activeCampaign( 13, 'Rejected Campaign', gmdate( 'Y-m-d', time() + 86400 ), 'rejected' );
		// Actionable — no submission yet, the else branch.
		$this->activeCampaign( 14, 'New Campaign', gmdate( 'Y-m-d', time() + ( 5 * 86400 ) ), null );

		$output = DashboardShortcode::render();

		$this->assertStringContainsString( 'Approved Campaign', $output );
		$this->assertStringContainsString( 'class="ffc-notice ffc-notice-success"', $output );
		$this->assertStringContainsString( 'Submitted Campaign', $output );
		$this->assertStringContainsString( 'class="ffc-notice ffc-notice-info"', $output );
		$this->assertStringContainsString( 'In Progress Campaign', $output );
		$this->assertStringContainsString( 'Continue Reregistration', $output );
		$this->assertStringContainsString( 'Rejected Campaign', $output );
		$this->assertStringContainsString( 'Resubmit Reregistration', $output );
		// The near-deadline rejected banner takes the danger tone; the far
		// ones keep the warning tone, and each carries its campaign id.
		$this->assertStringContainsString( 'class="ffc-notice ffc-notice-danger" role="note" data-ffc-notice="rereg-banner" data-reregistration-id="13"', $output );
		$this->assertStringContainsString( 'class="ffc-notice ffc-notice-warning" role="note" data-ffc-notice="rereg-banner" data-reregistration-id="12"', $output );
		$this->assertStringContainsString( 'New Campaign', $output );
		$this->assertStringContainsString( 'Complete Reregistration', $output );
		$this->assertStringContainsString( $this->recordButton( 10 ), $output, 'The approved banner offers the record.' );
		$this->assertStringNotContainsString( $this->recordButton( 11 ), $output, 'A submission awaiting review has no record yet (#2242).' );
		$this->assertStringNotContainsString( '#token=', $output, 'No public link to a record is drawn.' );
	}

	// ==================================================================
	// render() — the approved banner's window and its button (#1887)
	// ==================================================================

	/**
	 * Two submissions of a campaign that ended on 2020-01-01 and is still
	 * stored active: one approved the day after, one still awaiting review.
	 * The margins are years wide, so the real clock the renderer reads
	 * cannot straddle the 30-day cut-off.
	 */
	private function storeEndedCampaignRows(): void {
		$this->activeCampaign( 20, 'Long Approved Campaign', '2020-01-01 17:00:00', 'approved', gmmktime( 12, 0, 0, 1, 2, 2020 ) );
		$this->activeCampaign( 21, 'Still Pending Campaign', '2020-01-01 17:00:00', 'submitted' );
	}

	public function test_render_omits_an_approved_banner_past_its_window_and_keeps_the_submitted_one(): void {
		$this->wireFullRenderCollaborators( false );
		$this->storeEndedCampaignRows();

		$output = DashboardShortcode::render();

		$this->assertStringNotContainsString( 'Long Approved Campaign', $output, 'Past the later of the end and the approval plus 30 days, the approved banner goes.' );
		$this->assertStringNotContainsString( 'has been approved', $output );
		$this->assertStringContainsString( 'Still Pending Campaign', $output, 'A submission awaiting review keeps its banner until a decision.' );
		$this->assertStringContainsString( 'pending review', $output );
	}

	public function test_render_keeps_an_expired_approved_banner_when_the_window_is_zero(): void {
		$this->wireFullRenderCollaborators( false );
		$this->storeEndedCampaignRows();
		$this->settings['rereg_approved_banner_days'] = 0;

		$output = DashboardShortcode::render();

		$this->assertStringContainsString( 'Long Approved Campaign', $output, '0 never hides, which is the behaviour before the setting.' );
	}

	public function test_render_shows_an_approved_banner_inside_its_window_with_the_family_sized_button(): void {
		$this->wireFullRenderCollaborators( false );
		$this->activeCampaign( 30, 'Recent Approved Campaign', '2099-12-31 17:00:00', 'approved', gmmktime( 12, 0, 0, 1, 2, 2020 ) );

		$output = DashboardShortcode::render();

		$this->assertStringContainsString( 'Recent Approved Campaign', $output );
		$this->assertStringContainsString( 'has been approved', $output );

		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?>' . $output );
		libxml_clear_errors();
		$xpath = new \DOMXPath( $doc );

		$links = $xpath->query( '//*[@data-ffc-notice="rereg-banner"]//button[@data-submission-id="130"]' );
		$this->assertSame( 1, $links->length, 'The banner carries its Record button.' );
		$classes = preg_split( '/\s+/', trim( $links->item( 0 )->getAttribute( 'class' ) ) );
		$this->assertContains( 'ffc-rereg-banner-record', $classes );
		$this->assertContains( 'ffc-rereg-download-record', $classes, 'The dashboard script downloads it.' );
		$this->assertNotContains( 'ffc-btn-pdf', $classes, 'The compact table-row class stays off the banner.' );
	}

	/**
	 * An expired campaign reaches the dashboard only as the approved banner
	 * with its Record button, never as an actionable one (#1887).
	 */
	public function test_render_shows_an_expired_campaign_from_the_approved_source_as_the_approved_banner(): void {
		$this->wireFullRenderCollaborators( false );
		$this->expired_campaigns           = array(
			(object) array(
				'id'           => '40',
				'title'        => 'Ended Last Week Campaign',
				'status'       => 'expired',
				'start_date'   => '2019-01-01 08:00:00',
				'end_date'     => gmdate( 'Y-m-d 17:00:00', time() - ( 7 * 86400 ) ),
				'auto_approve' => '0',
			),
		);
		$this->submissions_by_campaign[40] = $this->submission( 40, 'approved', time() - ( 10 * 86400 ) );

		$output = DashboardShortcode::render();

		$this->assertStringContainsString( 'Ended Last Week Campaign', $output );
		$this->assertStringContainsString( 'has been approved', $output );
		$this->assertStringContainsString( $this->recordButton( 40 ), $output );
		$this->assertStringNotContainsString( 'data-reregistration-id="40"', $output, 'No form to open: it is not the actionable banner.' );
		$this->assertSame( 1, $this->preparedWith( "r.status = 'expired'" )[0][3] ?? null, 'The expired source is read for the current user.' );
	}
}
