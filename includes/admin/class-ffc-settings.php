<?php
/**
 * Settings
 *
 * Manages plugin settings with modular tab system
 * Acts as coordinator, delegating save operations to SettingsSaveHandler
 *
 * Responsibilities:
 * - Load and manage settings tabs
 * - Render settings page UI
 * - Delegate saving to Save Handler (v3.1.1)
 * - Handle cache actions and QR cache clearing
 * - Handle migration execution
 * - AJAX handlers
 *
 * @package FreeFormCertificate\Admin
 * @since 1.0.0
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\LabelSorter;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings.
 */
class Settings {

	/**
	 * Tabs.
	 *
	 * @var array<string, object>
	 */
	/**
	 * Loaded settings tabs, keyed by tab id.
	 *
	 * @var array<string, \FreeFormCertificate\Settings\SettingsTab>
	 */
	private $tabs = array();
	/**
	 * Save handler.
	 *
	 * @var \FreeFormCertificate\Admin\SettingsSaveHandler
	 */
	private $save_handler;

	/**
	 * Action / maintenance request handler.
	 *
	 * @var \FreeFormCertificate\Admin\SettingsActionHandler
	 */
	private $action_handler;

	/**
	 * Constructor.
	 *
	 * @param \FreeFormCertificate\Submissions\SubmissionHandler $handler Handler.
	 */
	public function __construct( \FreeFormCertificate\Submissions\SubmissionHandler $handler ) {
		$this->save_handler   = new \FreeFormCertificate\Admin\SettingsSaveHandler( $handler );
		$this->action_handler = new \FreeFormCertificate\Admin\SettingsActionHandler( $this->save_handler );

		// Hooks.
		add_action( 'admin_menu', array( $this, 'add_settings_page' ), 20 );
		// Tabs MUST be instantiated before `admin_enqueue_scripts` fires so
		// each tab's own enqueue hook (registered in its constructor via
		// SettingsTab::init()) actually catches the event. The previous
		// lazy-load inside display_settings_page ran during the render
		// callback — long after admin_enqueue_scripts — and silently
		// dropped every tab's script enqueue. admin_init fires after init
		// (so __() in tab metadata is safe) and before admin_enqueue_scripts.
		add_action( 'admin_init', array( $this, 'load_tabs' ), 5 );
		add_action( 'admin_init', array( $this, 'handle_settings_submission' ) );
		add_action( 'admin_init', array( $this, 'handle_clear_qr_cache' ) );
		add_action( 'admin_init', array( $this, 'handle_send_test_email' ) );
		add_action( 'admin_init', array( $this, 'handle_migration_execution' ) );
		add_action( 'admin_init', array( $this, 'handle_obsolete_shortcode_cleanup' ) );
		add_action( 'admin_init', array( $this, 'handle_url_shortener_cleanup' ) );
		add_action( 'admin_init', array( $this, 'handle_public_access_disabler' ) );
		add_action( 'admin_init', array( $this, 'handle_submission_link_audit' ) );
		add_action( 'admin_init', array( $this, 'handle_cache_actions' ) );

		// Resolve the virtual `ffc_view_settings_page` menu cap dynamically:
		// the Settings menu appears iff the user can see at least one tab (see
		// grant_settings_page_meta_cap()).
		add_filter( 'user_has_cap', array( $this, 'grant_settings_page_meta_cap' ), 10, 3 );
	}

	/**
	 * Capabilities that, when held, grant access to the Settings page — i.e.
	 * that make at least one tab visible. The computed `ffc_view_settings_page`
	 * menu cap is `manage_options` ∪ these. Single place to widen the page's
	 * entry as tabs backed by their own cap are added (e.g. the Activity Log's
	 * `ffc_view_activity_log`).
	 *
	 * @return array<int, string>
	 */
	public static function page_entry_caps(): array {
		/**
		 * Filter the capabilities that grant entry to the FFC Settings page.
		 *
		 * @since 6.16.0
		 * @param array<int, string> $caps Capability slugs.
		 */
		return (array) apply_filters(
			'ffcertificate_settings_page_entry_caps',
			array(
				// Config tabs.
				'ffc_view_settings',
				// Activity Log tab — audit-only operators (e.g. ffc_readonly) hold
				// this without ffc_view_settings and must still reach the page.
				'ffc_view_activity_log',
			)
		);
	}

	/**
	 * Dynamically grant the virtual `ffc_view_settings_page` capability.
	 *
	 * `ffc_view_settings_page` is NOT a real, role-granted capability (it is
	 * deliberately absent from CapabilityManager/CapabilityCatalog) — it is a
	 * computed meta-cap resolved here so the Settings menu registration under it
	 * appears exactly when the user holds `manage_options` or any page-entry cap.
	 *
	 * @param array<string, bool> $allcaps All caps the user currently has.
	 * @param array<int, string>  $caps    Required primitive caps (unused).
	 * @param array<int, mixed>   $args    [ requested_cap, user_id, ... ].
	 * @return array<string, bool>
	 */
	public function grant_settings_page_meta_cap( array $allcaps, array $caps, array $args ): array {
		$requested = $args[0] ?? '';
		if ( 'ffc_view_settings_page' !== $requested ) {
			return $allcaps;
		}
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps['ffc_view_settings_page'] = true;
			return $allcaps;
		}
		foreach ( self::page_entry_caps() as $entry_cap ) {
			if ( ! empty( $allcaps[ $entry_cap ] ) ) {
				$allcaps['ffc_view_settings_page'] = true;
				return $allcaps;
			}
		}
		return $allcaps;
	}

	/**
	 * Load all tab classes
	 *
	 * @since 4.0.0 Uses autoloader and namespaces (Hotfix 9)
	 */
	public function load_tabs(): void {
		// Idempotent — admin_init may call this and display_settings_page
		// keeps a defensive fallback below for any code path that bypasses
		// the hook chain.
		if ( ! empty( $this->tabs ) ) {
			return;
		}

		// Autoloader handles class loading - no require_once needed.

		// Tab classes with proper namespaces.
		// v4.6.16: Reorganized tabs for better UX.
		$tab_classes = array(
			'general'        => '\\FreeFormCertificate\\Settings\\Tabs\\TabGeneral',
			'templates'      => '\\FreeFormCertificate\\Settings\\Tabs\\TabTemplates',
			'reregistration' => '\\FreeFormCertificate\\Settings\\Tabs\\TabReregistration',
			'modulos'        => '\\FreeFormCertificate\\Settings\\Tabs\\TabModulos',
			'smtp'           => '\\FreeFormCertificate\\Settings\\Tabs\\TabSMTP',
			'email_model'    => '\\FreeFormCertificate\\Settings\\Tabs\\TabEmailModel',
			'email_texts'    => '\\FreeFormCertificate\\Settings\\Tabs\\TabEmailTexts',
			'cache'          => '\\FreeFormCertificate\\Settings\\Tabs\\TabCache',
			'url_shortener'  => '\\FreeFormCertificate\\Settings\\Tabs\\TabUrlShortener',
			'captcha'        => '\\FreeFormCertificate\\Settings\\Tabs\\TabCaptcha',
			'rate_limit'     => '\\FreeFormCertificate\\Settings\\Tabs\\TabRateLimit',
			'geolocation'    => '\\FreeFormCertificate\\Settings\\Tabs\\TabGeolocation',
			'ip_diagnostics' => '\\FreeFormCertificate\\Settings\\Tabs\\TabIpDiagnostics',
			'user_access'    => '\\FreeFormCertificate\\Settings\\Tabs\\TabUserAccess',
			'activity_log'   => '\\FreeFormCertificate\\Settings\\Tabs\\TabActivityLog',
			'advanced'       => '\\FreeFormCertificate\\Settings\\Tabs\\TabAdvanced',
			'migrations'     => '\\FreeFormCertificate\\Settings\\Tabs\\TabMigrations',
			'documentation'  => '\\FreeFormCertificate\\Settings\\Tabs\\TabDocumentation',
		);

		// Instantiate each tab.
		foreach ( $tab_classes as $tab_id => $class_name ) {
			if ( class_exists( $class_name ) ) {
				$this->tabs[ $tab_id ] = new $class_name();
			}
		}

		// Order tabs alphabetically by their translated title, with the
		// entry-point tab (General) pinned first and the advanced/maintenance
		// tabs pinned last, via the shared LabelSorter (same rule the sub-tab
		// bars and capability catalog use). The per-tab tab_order/get_order()
		// values are retained as a legacy hint but no longer drive display
		// order — the alphabetical order follows the active translation.
		$this->tabs = LabelSorter::sort(
			$this->tabs,
			static function ( $tab ): string {
				return (string) $tab->get_title();
			},
			array( 'general' ),
			array( 'advanced', 'migrations', 'documentation' )
		);

		// Allow plugins to add custom tabs.
		$this->tabs = apply_filters( 'ffcertificate_settings_tabs', $this->tabs );
	}

	/**
	 * Add settings page.
	 */
	public function add_settings_page(): void {
		// Dedicated top-level menu (was a submenu of the `ffc_form` CPT until
		// the module-toggle work): the Settings surface — which now hosts the
		// "Modules" tab that can enable/disable the Certificate module itself —
		// cannot live inside the very menu it governs. Promoting it to a
		// top-level `admin.php?page=ffc-settings` gives it a module-agnostic
		// home. The page hook suffix consequently changes from the old
		// `ffc_form_page_ffc-settings` to `toplevel_page_ffc-settings`.
		$hook = add_menu_page(
			__( 'Certificate Settings', 'ffcertificate' ),
			__( 'FFC Settings', 'ffcertificate' ),
			// Virtual meta-cap resolved in grant_settings_page_meta_cap(): the
			// menu shows iff the user can see at least one tab, rather than being
			// tied to `ffc_view_settings` alone (which would hide the page from
			// operators who only hold a per-tab cap such as ffc_view_activity_log).
			'ffc_view_settings_page',
			'ffc-settings',
			array( $this, 'display_settings_page' ),
			'dashicons-admin-settings'
		);

		if ( $hook ) {
			// Render the floating "Back to top" button in the admin footer so it
			// lives at <body> level — outside `.wrap`, outside `.ffc-tab-content`
			// and outside any per-tab <form> or animated container — guaranteeing
			// `position: fixed` resolves against the viewport on every settings
			// tab. The page hook scopes the action to ffc-settings only.
			add_action( "admin_footer-{$hook}", array( $this, 'render_back_to_top_link' ) );
		}
	}

	/**
	 * Echo the floating "Back to top" link. Hooked to `admin_footer-{hook}`
	 * for the settings page so the markup ends up at the bottom of <body>,
	 * with no ancestor that could create a containing block for the
	 * `position: fixed` styling.
	 */
	public function render_back_to_top_link(): void {
		?>
		<a href="#ffc-settings-top" class="ffc-settings-back-to-top" aria-label="<?php esc_attr_e( 'Back to top', 'ffcertificate' ); ?>" title="<?php esc_attr_e( 'Back to top', 'ffcertificate' ); ?>">
			<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>
		</a>
		<?php
	}

	/**
	 * Get default settings
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		return array(
			'cleanup_enabled'            => false,
			'cleanup_days'               => 365,
			'smtp_mode'                  => 'wp',
			'smtp_host'                  => '',
			'smtp_port'                  => 587,
			'smtp_user'                  => '',
			'smtp_pass'                  => '',
			'smtp_secure'                => 'tls',
			'smtp_from_email'            => '',
			'smtp_from_name'             => '',
			'qr_cache_enabled'           => 0,
			'qr_default_size'            => 200,
			'qr_default_margin'          => 2,
			'qr_default_error_level'     => 'M',
			// `d/m/Y` default since #244 — Brazilian-locale friendly. Pre-
			// #244 default was 'F j, Y'; installs that explicitly saved
			// 'F j, Y' keep it because get_option() returns the persisted
			// value, not the default. Fresh installs and any user who
			// never visited Settings → General pick up `d/m/Y` now.
			'date_format'                => 'd/m/Y',
			'date_format_custom'         => '',
			// New in #244 — time-of-day formatting + per-context PDF
			// overrides. Empty `_pdf` values inherit the base format.
			// `*_custom` companions hold the user-typed format when
			// `date_format_pdf` / `time_format_pdf` equals 'custom'
			// (#248, same idiom as date_format / date_format_custom).
			'time_format'                => 'H:i',
			'time_format_custom'         => '',
			'date_format_pdf'            => '',
			'date_format_pdf_custom'     => '',
			'time_format_pdf'            => '',
			'time_format_pdf_custom'     => '',
			'cache_enabled'              => 1,      // Default: ON.
			'cache_expiration'           => 3600,   // 1 hour
			'cache_auto_warm'            => 0,      // Default: OFF.
			'public_csv_default_limit'   => 1,    // Default limit for public CSV downloads.
			'obsolete_shortcode_days'    => 90,   // Grace window (days) for obsolete shortcode cleanup.
			'url_cleanup_days'           => 90,   // Grace window (days) for the short-URL never-clicked criterion.
			'url_cleanup_orphaned'       => 1,    // Short-URL cleanup: target post deleted.
			'url_cleanup_never_clicked'  => 0,   // Short-URL cleanup: never clicked + older than the grace window.
			'url_cleanup_trashed'        => 1,    // Short-URL cleanup: status = 'trashed'.
			'public_access_disable_days' => 90, // Grace window (days) for disabling Public Operator Access on old forms.
			'code_editor_theme'          => 'dark', // 'dark' | 'light' | 'auto' (auto follows dark_mode).
			// Captcha (#1053). `math` on upgrade and on a fresh install: the
			// arithmetic challenge is the only one that runs without
			// JavaScript and without a secure context, so it is the choice
			// that cannot lock anyone out of a form they could use before.
			'captcha_provider'           => 'math', // 'math' | 'altcha' | 'both'.
			// Work factor for the ALTCHA proof of work — the upper bound of
			// the secret number the solver has to find, so expected work is
			// about half of it. Bounded by CaptchaSettings, not here.
			'captcha_altcha_complexity'  => 200000,
			'captcha_altcha_ttl'         => 600,  // Seconds a challenge stays valid.
			'captcha_altcha_type'        => 'checkbox', // 'checkbox' | 'switch'.
			'captcha_altcha_auto'        => 'off', // 'off' | 'onfocus' | 'onload' | 'onsubmit'.
			'captcha_altcha_display'     => 'standard', // 'standard' | 'bar' | 'floating'.
			'captcha_altcha_theme'       => '', // '' follows the visitor's system preference.
			'captcha_altcha_hide_logo'   => 0,
			'captcha_altcha_hide_footer' => 0,
		);
	}

	/**
	 * Get option value
	 *
	 * @param string $key Option key.
	 * @return mixed Option value (string|int|array|bool|'')
	 */
	public function get_option( string $key ) {
		$settings = get_option( 'ffc_settings', array() );
		$defaults = $this->get_default_settings();

		if ( isset( $settings[ $key ] ) ) {
			return $settings[ $key ];
		}

		if ( isset( $defaults[ $key ] ) ) {
			return $defaults[ $key ];
		}

		return '';
	}

	/**
	 * Handle settings form submission
	 */
	public function handle_settings_submission(): void {
		$this->action_handler->handle_settings_submission();
	}

	/**
	 * Handle QR Code cache clearing
	 */
	public function handle_clear_qr_cache(): void {
		$this->action_handler->handle_clear_qr_cache();
	}

	/**
	 * Handle the "Send a test email" action (Settings → SMTP).
	 */
	public function handle_send_test_email(): void {
		$this->action_handler->handle_send_test_email();
	}

	/**
	 * Resolve the display-time page state for {@see display_settings_page()}.
	 *
	 * Pure LOGIC pass — no markup. Reads the display-only URL parameters and
	 * the current user's capability to decide which tab is active and whether
	 * the page is editable. The message notices stay in the render method (they
	 * echo at order-dependent positions) and are emitted via dedicated helpers.
	 *
	 * @return array{active_tab: string, can_edit: bool} Resolved page state.
	 */
	private function resolve_page_state(): array {
		$visible_tabs = $this->visible_tabs();
		$active_tab   = $this->resolve_active_tab( $visible_tabs );

		// 3-state Settings, now PER TAB: saving requires the active tab's manage
		// cap (default `ffc_manage_settings`). For a view-only user the tab body
		// is wrapped in a disabled <fieldset> so the page is a *real* read-only
		// surface. A tab with its own tier (e.g. the Activity Log) overrides the
		// manage cap so the settings lock never disables its legitimate actions.
		$can_edit = false;
		if ( isset( $visible_tabs[ $active_tab ] ) ) {
			$can_edit = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or(
				$this->tab_manage_cap( $visible_tabs[ $active_tab ] )
			);
		}

		return array(
			'active_tab' => $active_tab,
			'can_edit'   => $can_edit,
		);
	}

	/**
	 * The subset of loaded tabs the current user is allowed to view. A tab is
	 * visible when the user holds its view cap OR its manage cap (manage implies
	 * view in the 3-state model), or is a full WP admin. Tabs are duck-typed —
	 * third-party tabs added via the filter need not extend SettingsTab — so the
	 * cap getters fall back to the page-wide defaults when absent.
	 *
	 * @return array<string, \FreeFormCertificate\Settings\SettingsTab>
	 */
	private function visible_tabs(): array {
		return array_filter(
			$this->tabs,
			function ( $tab ): bool {
				return \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( $this->tab_view_cap( $tab ) )
					|| \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( $this->tab_manage_cap( $tab ) );
			}
		);
	}

	/**
	 * Resolve the active tab id to one the user can actually see: the requested
	 * `?tab=` when visible, otherwise the first visible tab.
	 *
	 * @param array<string, \FreeFormCertificate\Settings\SettingsTab> $visible_tabs Tabs the user can view.
	 * @return string
	 */
	private function resolve_active_tab( array $visible_tabs ): string {
		$requested = RequestInput::get_get_key( 'tab' );
		if ( '' !== $requested && isset( $visible_tabs[ $requested ] ) ) {
			return $requested;
		}
		if ( ! empty( $visible_tabs ) ) {
			reset( $visible_tabs );
			return (string) key( $visible_tabs );
		}
		return '';
	}

	/**
	 * View cap for a (possibly duck-typed) tab, defaulting to the page-wide cap.
	 *
	 * @param object $tab Tab instance.
	 * @return string
	 */
	private function tab_view_cap( $tab ): string {
		return method_exists( $tab, 'get_view_cap' ) ? (string) $tab->get_view_cap() : 'ffc_view_settings';
	}

	/**
	 * Manage cap for a (possibly duck-typed) tab, defaulting to the page-wide cap.
	 *
	 * @param object $tab Tab instance.
	 * @return string
	 */
	private function tab_manage_cap( $tab ): string {
		return method_exists( $tab, 'get_manage_cap' ) ? (string) $tab->get_manage_cap() : 'ffc_manage_settings';
	}

	/**
	 * Echo the QR-cache-cleared success notice when the `msg` URL parameter
	 * carries it. Split out of {@see display_settings_page()} so the render
	 * method reads as markup emission; emitted at the same position as before.
	 *
	 * @return void
	 */
	private function render_qr_cache_message(): void {
		if ( RequestInput::has_get( 'msg' ) ) {
			$msg = RequestInput::get_get_key( 'msg' );

			if ( 'qr_cache_cleared' === $msg ) {
				$cleared = RequestInput::get_get_int( 'cleared' );
				wp_admin_notice(
					esc_html(
						sprintf(
							/* translators: %d: number of QR codes cleared */
							__( '%d QR Code(s) cleared from cache successfully.', 'ffcertificate' ),
							$cleared
						)
					),
					array(
						'type'        => 'success',
						'dismissible' => true,
					)
				);
			}
		}
	}

	/**
	 * Echo the cache-warmed / cache-cleared success notices when the `msg`
	 * URL parameter carries them. Split out of {@see display_settings_page()}
	 * so the render method reads as markup emission; emitted at the same
	 * position as before.
	 *
	 * @return void
	 */
	private function render_cache_messages(): void {
		if ( RequestInput::has_get( 'msg' ) ) {
			$msg = RequestInput::get_get_key( 'msg' );

			if ( 'cache_warmed' === $msg ) {
				$count = RequestInput::get_get_int( 'count' );
				wp_admin_notice(
					esc_html(
						sprintf(
						/* translators: %d: number of forms pre-loaded */
							__( '✅ Cache warmed! %d form(s) pre-loaded.', 'ffcertificate' ),
							$count
						)
					),
					array(
						'type'        => 'success',
						'dismissible' => true,
					)
				);
			}

			if ( 'cache_cleared' === $msg ) {
				wp_admin_notice(
					esc_html__( '✅ Cache cleared successfully!', 'ffcertificate' ),
					array(
						'type'        => 'success',
						'dismissible' => true,
					)
				);
			}
		}
	}

	/**
	 * Display settings page with modular tabs
	 */
	public function display_settings_page(): void {
		// Lazy-load tabs on first render (avoids translation calls before 'init' hook).
		if ( empty( $this->tabs ) ) {
			$this->load_tabs();
		}

		// Per-tab visibility: only the tabs the current user can view. The menu
		// cap already gates page entry, but a direct URL hit by someone with no
		// viewable tab must land on an access notice, not an empty shell.
		$visible_tabs = $this->visible_tabs();
		if ( empty( $visible_tabs ) ) {
			echo '<div class="wrap">';
			wp_admin_notice(
				esc_html__( 'You do not have permission to view any settings.', 'ffcertificate' ),
				array( 'type' => 'error' )
			);
			echo '</div>';
			return;
		}

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- These are display-only URL parameters from redirects.
		// Handle messages.
		$this->render_qr_cache_message();

		// Resolve the active tab + capability state up front (pure logic).
		$page_state = $this->resolve_page_state();
		$active_tab = $page_state['active_tab'];

		$this->render_cache_messages();

		?>
		<div class="wrap ffc-settings-wrap">
			<span id="ffc-settings-top" aria-hidden="true"></span>
			<h1><?php esc_html_e( 'Certificate Settings', 'ffcertificate' ); ?></h1>
			<?php settings_errors( 'ffc_settings' ); ?>
			<?php
			$ffc_settings_can_edit = $page_state['can_edit'];
			if ( ! $ffc_settings_can_edit ) {
				wp_admin_notice(
					esc_html__( 'Read-only — you can view these settings but do not have permission to change them.', 'ffcertificate' ),
					array(
						'type'               => 'info',
						'additional_classes' => array( 'inline' ),
					)
				);
			}
			?>

			<?php
			// Display migration messages.
            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_text_field().
			if ( isset( $_GET['migration_success'] ) ) {
				wp_admin_notice(
					esc_html( sanitize_text_field( urldecode( wp_unslash( $_GET['migration_success'] ) ) ) ),
					array(
						'type'        => 'success',
						'dismissible' => true,
					)
				);
			}
			if ( isset( $_GET['migration_error'] ) ) {
				wp_admin_notice(
					esc_html( sanitize_text_field( urldecode( wp_unslash( $_GET['migration_error'] ) ) ) ),
					array(
						'type'        => 'error',
						'dismissible' => true,
					)
				);
			}
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			?>
			
			<div class="ffc-settings-tabs" data-ffc-settings-tabs>
				<ul class="ffc-settings-tabs__nav" role="tablist" aria-orientation="vertical">
					<?php
					// Grouped nav (#951): tabs render under domain subheadings,
					// staying alphabetical within each group. Bucket the already
					// alphabetically-sorted visible tabs by their group; the
					// `external` group is the module-settings links.
					$ffc_groups  = self::nav_group_labels();
					$ffc_links   = $this->module_settings_links();
					$ffc_buckets = array();
					foreach ( $visible_tabs as $ffc_tid => $ffc_tobj ) {
						$ffc_g = $ffc_tobj->get_group();
						if ( ! isset( $ffc_groups[ $ffc_g ] ) || 'external' === $ffc_g ) {
							$ffc_g = 'tools'; // Unknown/reserved group → neutral bucket.
						}
						$ffc_buckets[ $ffc_g ][ $ffc_tid ] = $ffc_tobj;
					}

					// Explicit intra-group order (#976): a group may pin a leading
					// sequence of tabs (e.g. the email family SMTP → Email Model →
					// Email texts) that should read as a unit instead of the default
					// alphabetical order; tabs not named stay after them in their
					// alphabetical order.
					foreach ( self::nav_group_order() as $ffc_ordk => $ffc_seq ) {
						if ( empty( $ffc_buckets[ $ffc_ordk ] ) ) {
							continue;
						}
						$ffc_pinned = array();
						foreach ( $ffc_seq as $ffc_pid ) {
							if ( isset( $ffc_buckets[ $ffc_ordk ][ $ffc_pid ] ) ) {
								$ffc_pinned[ $ffc_pid ] = $ffc_buckets[ $ffc_ordk ][ $ffc_pid ];
								unset( $ffc_buckets[ $ffc_ordk ][ $ffc_pid ] );
							}
						}
						$ffc_buckets[ $ffc_ordk ] = $ffc_pinned + $ffc_buckets[ $ffc_ordk ];
					}

					foreach ( $ffc_groups as $ffc_gkey => $ffc_glabel ) :
						if ( 'external' === $ffc_gkey ) {
							if ( empty( $ffc_links ) ) {
								continue;
							}
							?>
							<li class="ffc-settings-tabs__group" role="presentation">
								<span class="ffc-settings-tabs__group-label"><?php echo esc_html( $ffc_glabel ); ?></span>
							</li>
							<?php
							$this->render_module_settings_links();
							continue;
						}
						if ( empty( $ffc_buckets[ $ffc_gkey ] ) ) {
							continue;
						}
						?>
						<li class="ffc-settings-tabs__group" role="presentation">
							<span class="ffc-settings-tabs__group-label"><?php echo esc_html( $ffc_glabel ); ?></span>
						</li>
						<?php foreach ( $ffc_buckets[ $ffc_gkey ] as $tab_id => $tab_obj ) : ?>
							<?php $is_active = ( $active_tab === $tab_id ); ?>
							<li class="ffc-settings-tabs__nav-item" role="presentation">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=ffc-settings&tab=' . $tab_id ) ); ?>"
									id="ffc-settings-tabnav-<?php echo esc_attr( $tab_id ); ?>"
									class="ffc-settings-tabs__tab<?php echo $is_active ? ' is-active' : ''; ?>"
									role="tab"
									aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
									aria-controls="ffc-settings-tabpanel-<?php echo esc_attr( $tab_id ); ?>"
									tabindex="<?php echo $is_active ? '0' : '-1'; ?>">
									<span class="ffc-settings-tabs__icon <?php echo esc_attr( $tab_obj->get_icon() ); ?>" aria-hidden="true"></span>
									<span class="ffc-settings-tabs__label"><?php echo esc_html( $tab_obj->get_title() ); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</ul>

				<div id="ffc-settings-tabpanel-<?php echo esc_attr( $active_tab ); ?>" class="ffc-settings-tabs__panel" role="tabpanel" aria-labelledby="ffc-settings-tabnav-<?php echo esc_attr( $active_tab ); ?>" tabindex="0">
					<?php
					// A disabled <fieldset> natively disables every descendant form
					// control (inputs, selects, textareas, submit + action buttons)
					// across whichever tab is active, so read-only is enforced for
					// all tabs without touching each tab's own template. The save
					// handlers + AJAX endpoints already gate on `ffc_manage_settings`
					// server-side; this just stops the UI from looking editable.
					if ( ! $ffc_settings_can_edit ) {
						echo '<fieldset disabled class="ffc-settings-readonly-lock">';
					}
					// $active_tab is resolved to a visible tab above (the set is
					// guaranteed non-empty by the early-return at the top), so it is
					// always a key of $visible_tabs; the isset() is defensive.
					if ( isset( $visible_tabs[ $active_tab ] ) ) {
						$visible_tabs[ $active_tab ]->render();
					}
					if ( ! $ffc_settings_can_edit ) {
						echo '</fieldset>';
					}
					?>
				</div>
			</div>
		</div>
		<?php
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Ordered Settings-nav groups: group key => translated label.
	 *
	 * The nav renders tabs under these domain subheadings (tabs stay
	 * alphabetical WITHIN a group; each tab picks its group via
	 * {@see \FreeFormCertificate\Settings\SettingsTab::get_group()}). The order
	 * of this map IS the display order. The `external` key is reserved for the
	 * module-settings links (Scheduling / Recruitment) that leave this page —
	 * no tab uses it — and its heading is suppressed when no link is visible.
	 *
	 * @return array<string, string>
	 */
	public static function nav_group_labels(): array {
		return array(
			'general'       => __( 'General', 'ffcertificate' ),
			'content'       => __( 'Content', 'ffcertificate' ),
			'communication' => __( 'Communication', 'ffcertificate' ),
			'security'      => __( 'Security & Access', 'ffcertificate' ),
			'tools'         => __( 'Tools', 'ffcertificate' ),
			'external'      => __( 'Go to', 'ffcertificate' ),
			'system'        => __( 'System', 'ffcertificate' ),
		);
	}

	/**
	 * Per-group explicit tab order (#976). Most groups sort alphabetically by
	 * translated title ({@see \FreeFormCertificate\Core\LabelSorter}); a group
	 * listed here pins a *leading* sequence of tab ids that should read as a
	 * deliberate unit, with any unnamed tabs following in alphabetical order.
	 *
	 * The email family (SMTP transport → Email Model chrome → Email texts bodies)
	 * is a workflow, not an alphabetical list, so it is pinned in that order.
	 *
	 * @return array<string, array<int, string>> Group key => ordered tab ids.
	 */
	public static function nav_group_order(): array {
		return array(
			'communication' => array( 'smtp', 'email_model', 'email_texts' ),
		);
	}

	/**
	 * Build the module-settings links the current user may open. Split from the
	 * renderer so the grouped nav can gate the "Go to" group heading on whether
	 * any link exists (the heading is suppressed when the list is empty).
	 *
	 * @return array<int, array{url:string, icon:string, label:string, title:string}>
	 */
	protected function module_settings_links(): array {
		$links = array();

		if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_view_audiences' ) ) {
			$links[] = array(
				'url'   => admin_url( 'admin.php?page=ffc-scheduling-settings' ),
				'icon'  => 'ffc-icon-calendar',
				'label' => __( 'Scheduling', 'ffcertificate' ),
				'title' => __( 'Global holidays and audience / self-scheduling visibility.', 'ffcertificate' ),
			);
		}

		if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_view_recruitment_settings' ) ) {
			$links[] = array(
				'url'   => admin_url( 'admin.php?page=ffc-recruitment&tab=settings' ),
				'icon'  => 'ffc-icon-users',
				'label' => __( 'Recruitment', 'ffcertificate' ),
				'title' => __( 'Convocation email, public listing tuning and status colors.', 'ffcertificate' ),
			);
		}

		return $links;
	}

	/**
	 * Render the module-settings links as items of the settings nav.
	 *
	 * A few modules keep their own settings next to the module rather than on
	 * this page (rpgmem/ffcertificate#711 — discoverability). Each link is
	 * gated by that module's own view cap so a user only sees links they can
	 * actually open, and navigates away from this page — hence a plain link
	 * (no `role="tab"`) with an external-link marker instead of a tab.
	 *
	 * @return void
	 */
	protected function render_module_settings_links(): void {
		foreach ( $this->module_settings_links() as $link ) :
			?>
			<li class="ffc-settings-tabs__nav-item" role="presentation">
				<a href="<?php echo esc_url( $link['url'] ); ?>"
					class="ffc-settings-tabs__tab ffc-settings-tabs__tab--module"
					title="<?php echo esc_attr( $link['title'] ); ?>">
					<span class="ffc-settings-tabs__icon <?php echo esc_attr( $link['icon'] ); ?>" aria-hidden="true"></span>
					<span class="ffc-settings-tabs__label"><?php echo esc_html( $link['label'] ); ?></span>
					<span class="ffc-settings-tabs__external" aria-hidden="true"></span>
					<span class="screen-reader-text"><?php echo esc_html( $link['title'] ); ?></span>
				</a>
			</li>
			<?php
		endforeach;
	}

	/**
	 * Handle migration execution from settings page
	 */
	public function handle_migration_execution(): void {
		$this->action_handler->handle_migration_execution();
	}

	/**
	 * Handle obsolete shortcode cleanup actions (preview / apply / save_days).
	 *
	 * Wired into `admin_init`. Reacts to `ffc_obsolete_cleanup=<mode>` coming
	 * either from GET (preview/apply links) or POST (save_days form submission).
	 * Each mode has its own nonce key (`ffc_obsolete_cleanup_<mode>`) and all
	 * modes require `manage_options`.
	 *
	 * Flow:
	 *  - `save_days`  → persist the grace window in `ffc_settings`.
	 *  - `preview`    → run `ObsoleteShortcodeCleaner::run()` in dry-run,
	 *                   store the report + a "preview OK" flag in transients
	 *                   so the UI can unlock the apply button.
	 *  - `apply`      → refuse unless a recent preview exists, then run the
	 *                   destructive pass and store the report.
	 *
	 * @since 5.1.0
	 */
	public function handle_obsolete_shortcode_cleanup(): void {
		$this->action_handler->handle_obsolete_shortcode_cleanup();
	}

	/**
	 * Handle the Short URL Cleanup maintenance action (Settings → Data Migrations).
	 *
	 * Two modes, each with its own nonce key (`ffc_url_cleanup_<mode>`), all
	 * requiring `ffc_manage_settings`:
	 *  - `preview` (POST): persist the chosen criteria + grace window into
	 *    `ffc_settings`, then run the {@see UrlShortenerCleaner} in dry-run and
	 *    store the report + a "preview OK" flag so the apply button unlocks.
	 *  - `apply`   (GET) : refuse unless a recent preview exists, then run the
	 *    destructive pass using the persisted options.
	 *
	 * @since 6.8.0
	 */
	public function handle_url_shortener_cleanup(): void {
		$this->action_handler->handle_url_shortener_cleanup();
	}

	/**
	 * Handle the "Disable Public Operator Access on old forms" maintenance
	 * action (Settings → Data Migrations).
	 *
	 * Two modes, each with its own nonce key (`ffc_pubaccess_<mode>`), all
	 * requiring `ffc_manage_settings`:
	 *  - `preview` (POST): persist the grace window into `ffc_settings`, then
	 *    run the {@see PublicOperatorAccessDisabler} in dry-run and store the
	 *    report + a "preview OK" flag so the apply button unlocks.
	 *  - `apply`   (GET) : refuse unless a recent preview exists, then run the
	 *    destructive pass using the persisted grace window.
	 *
	 * @since 6.8.0
	 */
	public function handle_public_access_disabler(): void {
		$this->action_handler->handle_public_access_disabler();
	}

	/**
	 * Handle the Submission ↔ user link audit (Settings → Data Migrations).
	 *
	 * Report-only: a single `scan` mode (nonce `ffc_submission_audit_scan`,
	 * `ffc_manage_settings`) runs the read-only {@see SubmissionLinkAuditor}
	 * and stores the report in a transient. Nothing is mutated.
	 *
	 * @since 6.8.0
	 */
	public function handle_submission_link_audit(): void {
		$this->action_handler->handle_submission_link_audit();
	}

	/**
	 * Handle cache actions.
	 */
	public function handle_cache_actions(): void {
		$this->action_handler->handle_cache_actions();
	}
}
