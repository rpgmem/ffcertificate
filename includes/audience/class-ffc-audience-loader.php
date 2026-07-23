<?php
/**
 * Audience Loader
 *
 * Initializes and loads all components of the audience booking system.
 *
 * @package FreeFormCertificate\Audience
 * @since 4.5.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loader for audience module.
 */
class AudienceLoader {

	/**
	 * Singleton instance
	 *
	 * @var AudienceLoader|null
	 */
	private static ?AudienceLoader $instance = null;

	/**
	 * Admin page handler
	 *
	 * @var AudienceAdminPage|null
	 */
	private ?AudienceAdminPage $admin_page = null;

	/**
	 * Admin-ajax endpoint controller
	 *
	 * @var AudienceAjaxController|null
	 */
	private ?AudienceAjaxController $ajax_controller = null;

	/**
	 * Get singleton instance
	 *
	 * @return AudienceLoader
	 */
	public static function get_instance(): AudienceLoader {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor for singleton
	 */
	private function __construct() {
		// Empty.
	}

	/**
	 * Initialize the audience system
	 *
	 * @return void
	 */
	public function init(): void {
		// Register hooks.
		$this->register_hooks();

		// Initialize admin components if in admin.
		if ( is_admin() ) {
			$this->init_admin();
		}

		// Initialize frontend components.
		$this->init_frontend();

		// Initialize REST API.
		$this->init_api();

		// Initialize notifications (email + ICS).
		$this->init_notifications();
	}

	/**
	 * Register WordPress hooks
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		// Register custom capabilities.
		add_action( 'init', array( $this, 'register_capabilities' ) );

		// AJAX endpoints live in the dedicated controller (frontend-audit Item 3).
		$this->ajax_controller = new AudienceAjaxController();
		$this->ajax_controller->register();
	}

	/**
	 * Register capabilities
	 *
	 * @return void
	 */
	public function register_capabilities(): void {
		// Capabilities are added per-user via schedule permissions.
		// This hook is for future global capability registration if needed.
		do_action( 'ffcertificate_audience_register_capabilities' );
	}

	/**
	 * Initialize admin components
	 *
	 * @return void
	 */
	private function init_admin(): void {
		// Load admin page handler.
		if ( class_exists( '\FreeFormCertificate\Audience\AudienceAdminPage' ) ) {
			$this->admin_page = new AudienceAdminPage();
			$this->admin_page->init();
		}

		// Bookings CSV exporter — registers its own `admin_post` handler; the
		// hook keeps the instance alive for the request.
		if ( class_exists( '\FreeFormCertificate\Audience\AudienceBookingCsvExporter' ) ) {
			new AudienceBookingCsvExporter();
		}

		// Load admin assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Initialize frontend components
	 *
	 * @return void
	 */
	private function init_frontend(): void {
		// Register shortcode.
		if ( class_exists( '\FreeFormCertificate\Audience\AudienceShortcode' ) ) {
			AudienceShortcode::init();
		}

		// Enqueue frontend assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	/**
	 * Initialize REST API
	 *
	 * @return void
	 */
	private function init_api(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Initialize notifications (email + ICS)
	 *
	 * @return void
	 */
	private function init_notifications(): void {
		if ( class_exists( '\FreeFormCertificate\Audience\AudienceNotificationHandler' ) ) {
			AudienceNotificationHandler::init();
		}
	}

	/**
	 * Register REST API routes
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		if ( class_exists( '\FreeFormCertificate\Audience\AudienceRestController' ) ) {
			$controller = new AudienceRestController();
			$controller->register_routes();
		}
	}

	/**
	 * Enqueue admin assets
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook ): void {
		// Only load on our admin pages.
		if ( strpos( $hook, 'ffc-audience' ) === false && strpos( $hook, 'ffc-scheduling' ) === false ) {
			return;
		}

		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();

		// Admin CSS.
		if ( function_exists( 'wp_style_is' ) && ! wp_style_is( 'ffc-common', 'registered' ) ) {
			wp_register_style(
				'ffc-common',
				FFC_PLUGIN_URL . "assets/css/ffc-common{$s}.css",
				array(),
				FFC_VERSION
			);
		}
		wp_enqueue_style(
			'ffc-audience-admin',
			FFC_PLUGIN_URL . "assets/css/ffc-audience-admin{$s}.css",
			array( 'ffc-common' ),
			FFC_VERSION
		);

		// Admin JS.
		wp_enqueue_script(
			'ffc-audience-admin',
			FFC_PLUGIN_URL . "assets/js/ffc-audience-admin{$s}.js",
			array( 'jquery', 'wp-util' ),
			FFC_VERSION,
			true
		);

		// Custom fields CSS + JS (on audiences page).
		$page = \FreeFormCertificate\Core\RequestInput::get_get_string( 'page' );
		if ( 'ffc-scheduling-audiences' === $page ) {
			wp_enqueue_script( 'jquery-ui-sortable' );

			wp_enqueue_style(
				'ffc-custom-fields-admin',
				FFC_PLUGIN_URL . "assets/css/ffc-custom-fields-admin{$s}.css",
				array( 'ffc-audience-admin' ),
				FFC_VERSION
			);

			wp_enqueue_script(
				'ffc-divisao-setor-editor',
				FFC_PLUGIN_URL . "assets/js/ffc-divisao-setor-editor{$s}.js",
				array( 'jquery' ),
				FFC_VERSION,
				true
			);
			wp_localize_script(
				'ffc-divisao-setor-editor',
				'ffcDivisaoSetorEditor',
				array(
					'strings' => array(
						'divisionName'   => __( 'Division name', 'ffcertificate' ),
						'departmentName' => __( 'Department name', 'ffcertificate' ),
						'removeDivision' => __( 'Remove division', 'ffcertificate' ),
						'removeSector'   => __( 'Remove department', 'ffcertificate' ),
						'addSector'      => __( '+ Add Department', 'ffcertificate' ),
					),
				)
			);

			wp_enqueue_script(
				'ffc-custom-fields-admin',
				FFC_PLUGIN_URL . "assets/js/ffc-custom-fields-admin{$s}.js",
				array( 'jquery', 'jquery-ui-sortable', 'wp-util', 'ffc-audience-admin', 'ffc-divisao-setor-editor' ),
				FFC_VERSION,
				true
			);
		}

		// Localize script.
		wp_localize_script(
			'ffc-audience-admin',
			'ffcAudienceAdmin',
			array(
				'ajaxUrl'                  => admin_url( 'admin-ajax.php' ),
				'restUrl'                  => rest_url( 'ffc/v1/audience/' ),
				'restNonce'                => wp_create_nonce( 'wp_rest' ),
				'searchUsersNonce'         => wp_create_nonce( 'ffc_search_users' ),
				'schedulePermissionsNonce' => wp_create_nonce( 'ffc_schedule_permissions' ),
				'adminNonce'               => wp_create_nonce( 'ffc_admin_nonce' ),
				'strings'                  => $this->get_admin_strings(),
			)
		);

		// Autosave infra so the "Create users…" toggle on the CSV import tab
		// (and any future autosave-keyed input on scheduling pages) binds
		// to SettingsAjaxEndpoint. Mirrors SettingsTab::enqueue_autosave_infra().
		wp_enqueue_script(
			'ffc-core',
			FFC_PLUGIN_URL . "assets/js/ffc-core{$s}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
		wp_enqueue_script(
			'ffc-admin-js',
			FFC_PLUGIN_URL . "assets/js/ffc-admin{$s}.js",
			array( 'jquery', 'ffc-core' ),
			FFC_VERSION,
			true
		);
		wp_enqueue_script(
			'ffc-admin-autosave',
			FFC_PLUGIN_URL . "assets/js/ffc-admin-autosave{$s}.js",
			array( 'jquery', 'ffc-core', 'ffc-admin-js' ),
			FFC_VERSION,
			true
		);
		wp_enqueue_script(
			'ffc-section-collapse',
			FFC_PLUGIN_URL . "assets/js/ffc-section-collapse{$s}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-admin-autosave',
			'ffcAdminAutosave',
			array(
				'nonce' => wp_create_nonce( \FreeFormCertificate\Admin\SettingsAjaxEndpoint::AJAX_ACTION ),
			)
		);
	}

	/**
	 * Enqueue frontend assets
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets(): void {
		// Only load when shortcode is present.
		global $post;
		if ( ! $post || ! has_shortcode( $post->post_content, 'ffc_audience' ) ) {
			return;
		}

		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();

		// Frontend CSS.
		wp_enqueue_style(
			'ffc-common',
			FFC_PLUGIN_URL . "assets/css/ffc-common{$s}.css",
			array(),
			FFC_VERSION
		);
		wp_enqueue_style(
			'ffc-audience',
			FFC_PLUGIN_URL . "assets/css/ffc-audience{$s}.css",
			array( 'ffc-common' ),
			FFC_VERSION
		);

		// Frontend JS. `ffc-core` provides window.FFC.request() used by
		// ffc-audience.js — without it, the AJAX call near the bottom of
		// the file throws `FFC is not defined` and breaks the calendar.
		wp_enqueue_script(
			'ffc-audience',
			FFC_PLUGIN_URL . "assets/js/ffc-audience{$s}.js",
			array( 'jquery', 'ffc-core' ),
			FFC_VERSION,
			true
		);
	}

	/**
	 * Get admin translation strings
	 *
	 * @return array<string, string>
	 */
	private function get_admin_strings(): array {
		return array(
			'confirmDelete'        => __( 'Are you sure you want to delete this item?', 'ffcertificate' ),
			'confirmCancel'        => __( 'Are you sure you want to cancel this booking?', 'ffcertificate' ),
			'saving'               => __( 'Saving...', 'ffcertificate' ),
			'saved'                => __( 'Saved!', 'ffcertificate' ),
			'error'                => __( 'An error occurred. Please try again.', 'ffcertificate' ),
			'loading'              => __( 'Loading...', 'ffcertificate' ),
			'noResults'            => __( 'No results found.', 'ffcertificate' ),
			'selectAudience'       => __( 'Select audience groups', 'ffcertificate' ),
			'selectUsers'          => __( 'Select users', 'ffcertificate' ),
			'requiredField'        => __( 'This field is required.', 'ffcertificate' ),
			'invalidTime'          => __( 'End time must be after start time.', 'ffcertificate' ),
			'allEnvironments'      => __( 'All Environments', 'ffcertificate' ),
			'environmentLabel'     => __( 'Environment', 'ffcertificate' ),
			'cancelReason'         => __( 'Please provide a reason for cancellation:', 'ffcertificate' ),
			'bookingCancelled'     => __( 'Booking cancelled successfully.', 'ffcertificate' ),
			'bookingDetails'       => __( 'Booking Details', 'ffcertificate' ),
			'date'                 => __( 'Date', 'ffcertificate' ),
			'time'                 => __( 'Time', 'ffcertificate' ),
			'description'          => __( 'Description', 'ffcertificate' ),
			'type'                 => __( 'Type', 'ffcertificate' ),
			'status'               => __( 'Status', 'ffcertificate' ),
			'createdBy'            => __( 'Created By', 'ffcertificate' ),
			'audiences'            => __( 'Audiences', 'ffcertificate' ),
			'users'                => __( 'Users', 'ffcertificate' ),
			'cancelReasonLabel'    => __( 'Cancel Reason', 'ffcertificate' ),
			'close'                => __( 'Close', 'ffcertificate' ),
			'allDay'               => __( 'All Day', 'ffcertificate' ),
			'audience'             => __( 'Audience', 'ffcertificate' ),
			'customUsers'          => __( 'Custom Users', 'ffcertificate' ),
			'active'               => __( 'Active', 'ffcertificate' ),
			'cancelled'            => __( 'Cancelled', 'ffcertificate' ),
			'alreadyAdded'         => __( 'already added', 'ffcertificate' ),
			'noUsersFound'         => __( 'No users found.', 'ffcertificate' ),
			'errorAddingUser'      => __( 'Error adding user.', 'ffcertificate' ),
			'confirmRemoveUser'    => __( "Remove this user's access?", 'ffcertificate' ),
			'noUsersYet'           => __( 'No users have been granted access yet.', 'ffcertificate' ),
			'cannotDeleteStandard' => __( 'Standard fields cannot be deleted. Deactivate instead.', 'ffcertificate' ),
			'confirmReplicate'     => __( "Copy this audience's option lists to all child and grandchild audiences? This overwrites their current lists.", 'ffcertificate' ),
		);
	}
}
