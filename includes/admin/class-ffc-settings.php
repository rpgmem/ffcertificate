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
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

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
		add_action( 'wp_ajax_ffc_preview_date_format', array( $this, 'ajax_preview_date_format' ) );
		add_action( 'admin_init', array( $this, 'handle_cache_actions' ) );
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
			'general'       => '\\FreeFormCertificate\\Settings\\Tabs\\TabGeneral',
			'smtp'          => '\\FreeFormCertificate\\Settings\\Tabs\\TabSMTP',
			'cache'         => '\\FreeFormCertificate\\Settings\\Tabs\\TabCache',
			'url_shortener' => '\\FreeFormCertificate\\Settings\\Tabs\\TabUrlShortener',
			'rate_limit'    => '\\FreeFormCertificate\\Settings\\Tabs\\TabRateLimit',
			'geolocation'   => '\\FreeFormCertificate\\Settings\\Tabs\\TabGeolocation',
			'user_access'   => '\\FreeFormCertificate\\Settings\\Tabs\\TabUserAccess',
			'advanced'      => '\\FreeFormCertificate\\Settings\\Tabs\\TabAdvanced',
			'migrations'    => '\\FreeFormCertificate\\Settings\\Tabs\\TabMigrations',
			'documentation' => '\\FreeFormCertificate\\Settings\\Tabs\\TabDocumentation',
		);

		// Instantiate each tab.
		foreach ( $tab_classes as $tab_id => $class_name ) {
			if ( class_exists( $class_name ) ) {
				$this->tabs[ $tab_id ] = new $class_name();
			}
		}

		// Sort tabs by order.
		uasort(
			$this->tabs,
			function ( $a, $b ) {
				return $a->get_order() - $b->get_order();
			}
		);

		// Allow plugins to add custom tabs.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ffcertificate is the plugin prefix
		$this->tabs = apply_filters( 'ffcertificate_settings_tabs', $this->tabs );
	}

	/**
	 * Add settings page.
	 */
	public function add_settings_page(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=ffc_form',
			__( 'Settings', 'ffcertificate' ),
			__( 'Settings', 'ffcertificate' ),
			'ffc_view_settings',
			'ffc-settings',
			array( $this, 'display_settings_page' )
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
		// Get active tab (default to first tab).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- display-only URL parameter; sanitize_key applied.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		// If no tab specified, use first tab.
		if ( empty( $active_tab ) && ! empty( $this->tabs ) ) {
			reset( $this->tabs );
			$first_tab  = current( $this->tabs );
			$active_tab = $first_tab->get_id();
		}

		// 3-state Settings: the page menu opens on `ffc_view_settings` (só vê),
		// but saving requires `ffc_manage_settings`. For a view-only user the
		// whole tab body is wrapped in a disabled <fieldset> so the page is a
		// *real* read-only surface (no live inputs that silently fail at the
		// manage-gated save handler), mirroring the recruitment Settings tab.
		$can_edit = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings' );

		return array(
			'active_tab' => $active_tab,
			'can_edit'   => $can_edit,
		);
	}

	/**
	 * Echo the QR-cache-cleared success notice when the `msg` URL parameter
	 * carries it. Split out of {@see display_settings_page()} so the render
	 * method reads as markup emission; emitted at the same position as before.
	 *
	 * @return void
	 */
	private function render_qr_cache_message(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() existence check only.
		if ( isset( $_GET['msg'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only URL parameter.
			$msg = sanitize_key( wp_unslash( $_GET['msg'] ) );

			if ( 'qr_cache_cleared' === $msg ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- display-only URL parameter.
				$cleared = isset( $_GET['cleared'] ) ? absint( wp_unslash( $_GET['cleared'] ) ) : 0;
				echo '<div class="notice notice-success is-dismissible">';
				/* translators: %d: number of QR codes cleared */
				echo '<p>' . esc_html( sprintf( __( '%d QR Code(s) cleared from cache successfully.', 'ffcertificate' ), $cleared ) ) . '</p>';
				echo '</div>';
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() existence check only.
		if ( isset( $_GET['msg'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only URL parameter.
			$msg = sanitize_key( wp_unslash( $_GET['msg'] ) );

			if ( 'cache_warmed' === $msg ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- display-only URL parameter.
				$count = isset( $_GET['count'] ) ? absint( wp_unslash( $_GET['count'] ) ) : 0;
				echo '<div class="notice notice-success is-dismissible">';
				echo '<p>' . esc_html(
					sprintf(
					/* translators: %d: number of forms pre-loaded */
						__( '✅ Cache warmed! %d form(s) pre-loaded.', 'ffcertificate' ),
						$count
					)
				) . '</p>';
				echo '</div>';
			}

			if ( 'cache_cleared' === $msg ) {
				echo '<div class="notice notice-success is-dismissible">';
				echo '<p>' . esc_html__( '✅ Cache cleared successfully!', 'ffcertificate' ) . '</p>';
				echo '</div>';
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
				echo '<div class="notice notice-info inline"><p>'
					. esc_html__( 'Read-only — you can view these settings but do not have permission to change them.', 'ffcertificate' )
					. '</p></div>';
			}
			?>

			<?php
			// Display migration messages.
            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_text_field().
			if ( isset( $_GET['migration_success'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( urldecode( wp_unslash( $_GET['migration_success'] ) ) ) ) . '</p></div>';
			}
			if ( isset( $_GET['migration_error'] ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( urldecode( wp_unslash( $_GET['migration_error'] ) ) ) ) . '</p></div>';
			}
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			?>
			
			<div class="ffc-settings-tabs" data-ffc-settings-tabs>
				<ul class="ffc-settings-tabs__nav" role="tablist" aria-orientation="vertical">
					<?php $ffc_module_links_rendered = false; ?>
					<?php foreach ( $this->tabs as $tab_id => $tab_obj ) : ?>
						<?php
						// Module-settings links sit above the Advanced tab so
						// module pages read as part of the settings nav.
						if ( 'advanced' === $tab_id && ! $ffc_module_links_rendered ) {
							$this->render_module_settings_links();
							$ffc_module_links_rendered = true;
						}
						$is_active = ( $active_tab === $tab_id );
						?>
						<li class="ffc-settings-tabs__nav-item" role="presentation">
							<a href="?post_type=ffc_form&page=ffc-settings&tab=<?php echo esc_attr( $tab_id ); ?>"
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
					<?php
					if ( ! $ffc_module_links_rendered ) {
						$this->render_module_settings_links();
					}
					?>
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
					if ( isset( $this->tabs[ $active_tab ] ) ) {
						$this->tabs[ $active_tab ]->render();
					} elseif ( ! empty( $this->tabs ) ) {
						// Fallback: render first tab.
						reset( $this->tabs );
						$first_tab = current( $this->tabs );
						$first_tab->render();
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

		foreach ( $links as $link ) :
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
	 * @since 6.7.x
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
	 * @since 6.7.x
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
	 * @since 6.7.x
	 */
	public function handle_submission_link_audit(): void {
		$this->action_handler->handle_submission_link_audit();
	}

	/**
	 * AJAX handler for date format preview
	 *
	 * @since 2.10.0
	 */
	public function ajax_preview_date_format(): void {
		$this->action_handler->ajax_preview_date_format();
	}

	/**
	 * Handle cache actions.
	 */
	public function handle_cache_actions(): void {
		$this->action_handler->handle_cache_actions();
	}
}
