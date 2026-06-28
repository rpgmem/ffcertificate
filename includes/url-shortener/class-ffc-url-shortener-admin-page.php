<?php
/**
 * URL Shortener Admin Page
 *
 * Admin submenu page listing all short URLs with CRUD operations.
 *
 * @package FreeFormCertificate\UrlShortener
 * @since 5.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Core\AjaxTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page for url shortener admin.
 */
class UrlShortenerAdminPage {

	use AjaxTrait;

	/**
	 * Description.
	 *
	 * @var UrlShortenerService
	 */
	private UrlShortenerService $service;

	/**
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * Constructor.
	 *
	 * @param UrlShortenerService $service Service.
	 */
	public function __construct( UrlShortenerService $service ) {
		$this->service = $service;
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 25 );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_ffc_create_short_url', array( $this, 'ajax_create' ) );
		add_action( 'wp_ajax_ffc_delete_short_url', array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_ffc_toggle_short_url', array( $this, 'ajax_toggle' ) );
		add_action( 'wp_ajax_ffc_trash_short_url', array( $this, 'ajax_trash' ) );
		add_action( 'wp_ajax_ffc_restore_short_url', array( $this, 'ajax_restore' ) );
		add_action( 'wp_ajax_ffc_empty_trash_short_urls', array( $this, 'ajax_empty_trash' ) );
	}

	/**
	 * Enqueue assets on the Short URLs admin page.
	 *
	 * @param string $hook_suffix Admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		$page = \FreeFormCertificate\Core\RequestInput::get_get_string( 'page' );
		if ( 'ffc-short-urls' !== $page ) {
			return;
		}

		wp_enqueue_style(
			'ffc-url-shortener-admin',
			FFC_PLUGIN_URL . 'assets/css/ffc-url-shortener-admin.css',
			array(),
			FFC_VERSION
		);
		wp_enqueue_script(
			'ffc-url-shortener-admin',
			FFC_PLUGIN_URL . 'assets/js/ffc-url-shortener-admin.js',
			// `ffc-core` carries `window.FFC.request()` which the QR-download
			// click handler calls. Declaring it as a dep makes the load
			// order survive JS-combining cache plugins (LiteSpeed Combine,
			// WP Rocket Combine, etc.) that flatten the bundle and would
			// otherwise drop `ffc-core` for not being in the chain. Same
			// fix shape as 6.6.7 (#367) applied to the 4 public-facing
			// sites; admin sites missed that pass.
			array( 'jquery', 'ffc-core' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-url-shortener-admin',
			'ffcUrlShortener',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ffc_short_url_nonce' ),
				'i18n'    => array(
					'copied'        => __( 'Copied!', 'ffcertificate' ),
					'copyFailed'    => __( 'Copy failed', 'ffcertificate' ),
					'error'         => __( 'An error occurred.', 'ffcertificate' ),
					'qrLoadFailed'  => __( 'Failed to load QR Code', 'ffcertificate' ),
					'copy'          => __( 'Copy', 'ffcertificate' ),
					'requestFailed' => __( 'Request failed', 'ffcertificate' ),
				),
			)
		);
	}

	/**
	 * Add submenu under the FFC Forms menu.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'edit.php?post_type=ffc_form',
			__( 'Short URLs', 'ffcertificate' ),
			__( 'Short URLs', 'ffcertificate' ),
			'ffc_view_url_shortener',
			'ffc-short-urls',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle non-AJAX admin actions (bulk delete, toggle).
	 */
	public function handle_actions(): void {
		if ( \FreeFormCertificate\Core\RequestInput::get_get_string( 'page' ) !== 'ffc-short-urls' ) {
			return;
		}

		// These GET-link actions are all writes (trash/restore/delete/toggle/
		// empty_trash) — read-only viewers never run them.
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_url_shortener' ) ) {
			return;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check only; nonce verified below via wp_verify_nonce.
		if ( ! isset( $_GET['ffc_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['ffc_action'] ) );
		$nonce  = \FreeFormCertificate\Core\RequestInput::get_get_string( '_wpnonce' );

		// Removal actions (trash/restore/delete/empty_trash) require the dedicated
		// destructive cap (GAP E). Toggle stays under manage above.
		$removal_actions = array( 'trash', 'restore', 'delete', 'empty_trash' );
		if ( in_array( $action, $removal_actions, true )
			&& ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_delete_url_shortener' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete short URLs.', 'ffcertificate' ) );
		}

		if ( 'trash' === $action && isset( $_GET['id'] ) ) {
			if ( ! wp_verify_nonce( $nonce, 'ffc_short_url_trash_' . absint( $_GET['id'] ) ) ) {
				wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
			}
			$this->service->trash_short_url( absint( wp_unslash( $_GET['id'] ) ) );
			wp_safe_redirect( admin_url( 'edit.php?post_type=ffc_form&page=ffc-short-urls&msg=trashed' ) );
			exit;
		}

		if ( 'restore' === $action && isset( $_GET['id'] ) ) {
			if ( ! wp_verify_nonce( $nonce, 'ffc_short_url_restore_' . absint( $_GET['id'] ) ) ) {
				wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
			}
			$this->service->restore_short_url( absint( wp_unslash( $_GET['id'] ) ) );
			wp_safe_redirect( admin_url( 'edit.php?post_type=ffc_form&page=ffc-short-urls&status=trashed&msg=restored' ) );
			exit;
		}

		if ( 'delete' === $action && isset( $_GET['id'] ) ) {
			if ( ! wp_verify_nonce( $nonce, 'ffc_short_url_delete_' . absint( $_GET['id'] ) ) ) {
				wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
			}
			$this->service->delete_short_url( absint( wp_unslash( $_GET['id'] ) ) );
			wp_safe_redirect( admin_url( 'edit.php?post_type=ffc_form&page=ffc-short-urls&status=trashed&msg=deleted' ) );
			exit;
		}

		if ( 'empty_trash' === $action ) {
			if ( ! wp_verify_nonce( $nonce, 'ffc_short_url_empty_trash' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
			}
			$trashed = $this->service->get_repository()->findPaginated(
				array(
					'status'   => 'trashed',
					'per_page' => 1000,
				)
			);
			foreach ( $trashed['items'] as $item ) {
				$this->service->delete_short_url( (int) $item['id'] );
			}
			wp_safe_redirect( admin_url( 'edit.php?post_type=ffc_form&page=ffc-short-urls&msg=emptied' ) );
			exit;
		}

		if ( 'toggle' === $action && isset( $_GET['id'] ) ) {
			if ( ! wp_verify_nonce( $nonce, 'ffc_short_url_toggle_' . absint( $_GET['id'] ) ) ) {
				wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
			}
			$this->service->toggle_status( absint( wp_unslash( $_GET['id'] ) ) );
			wp_safe_redirect( admin_url( 'edit.php?post_type=ffc_form&page=ffc-short-urls&msg=toggled' ) );
			exit;
		}
	}

	/**
	 * AJAX: Create a new short URL.
	 */
	public function ajax_create(): void {
		$this->verify_ajax_nonce( 'ffc_short_url_nonce' );
		$this->check_ajax_permission( 'ffc_manage_url_shortener' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->verify_ajax_nonce() above.
		$url   = esc_url_raw( wp_unslash( $_POST['target_url'] ?? '' ) );
		$title = \FreeFormCertificate\Core\RequestInput::get_post_string( 'title' );

		if ( empty( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'URL is required.', 'ffcertificate' ) ) );
		}

		$result = $this->service->create_short_url( $url, $title );

		if ( $result['success'] ) {
			$data              = $result['data'] ?? array();
			$data['short_url'] = $this->service->get_short_url( $data['short_code'] );
			wp_send_json_success( $data );
		} else {
			wp_send_json_error( array( 'message' => $result['error'] ?? '' ) );
		}
	}

	/**
	 * AJAX: Delete a short URL.
	 */
	public function ajax_delete(): void {
		$this->verify_ajax_nonce( 'ffc_short_url_nonce' );
		$this->check_ajax_permission( 'ffc_delete_url_shortener' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->verify_ajax_nonce() above.
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ID.', 'ffcertificate' ) ) );
		}

		$this->service->delete_short_url( $id );
		wp_send_json_success();
	}

	/**
	 * AJAX: Trash a short URL (soft delete).
	 */
	public function ajax_trash(): void {
		$this->verify_ajax_nonce( 'ffc_short_url_nonce' );
		$this->check_ajax_permission( 'ffc_delete_url_shortener' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->verify_ajax_nonce() above.
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ID.', 'ffcertificate' ) ) );
		}

		$this->service->trash_short_url( $id );
		wp_send_json_success();
	}

	/**
	 * AJAX: Restore a short URL from trash.
	 */
	public function ajax_restore(): void {
		$this->verify_ajax_nonce( 'ffc_short_url_nonce' );
		$this->check_ajax_permission( 'ffc_delete_url_shortener' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->verify_ajax_nonce() above.
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ID.', 'ffcertificate' ) ) );
		}

		$this->service->restore_short_url( $id );
		wp_send_json_success();
	}

	/**
	 * AJAX: Empty trash — permanently delete all trashed short URLs.
	 */
	public function ajax_empty_trash(): void {
		$this->verify_ajax_nonce( 'ffc_short_url_nonce' );
		$this->check_ajax_permission( 'ffc_delete_url_shortener' );

		$trashed = $this->service->get_repository()->findPaginated(
			array(
				'status'   => 'trashed',
				'per_page' => 1000,
			)
		);
		foreach ( $trashed['items'] as $item ) {
			$this->service->delete_short_url( (int) $item['id'] );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: Toggle short URL status.
	 */
	public function ajax_toggle(): void {
		$this->verify_ajax_nonce( 'ffc_short_url_nonce' );
		$this->check_ajax_permission( 'ffc_manage_url_shortener' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in $this->verify_ajax_nonce() above.
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ID.', 'ffcertificate' ) ) );
		}

		$this->service->toggle_status( $id );
		wp_send_json_success();
	}

	/**
	 * Render the admin page.
	 */
	public function render_page(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only pagination parameter.
		$page   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$search = \FreeFormCertificate\Core\RequestInput::get_get_string( 's' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only sort parameter.
		$orderby = sanitize_key( $_GET['orderby'] ?? 'created_at' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only sort direction parameter.
		$order = strtoupper( sanitize_key( $_GET['order'] ?? 'DESC' ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only status filter parameter.
		$status = sanitize_key( $_GET['status'] ?? 'all' );

		$per_page = 20;
		$result   = $this->service->get_repository()->findPaginated(
			array(
				'per_page' => $per_page,
				'page'     => $page,
				'orderby'  => $orderby,
				'order'    => $order,
				'search'   => $search,
				'status'   => $status,
			)
		);

		$items       = $result['items'];
		$total       = $result['total'];
		$total_pages = (int) ceil( $total / $per_page );
		$stats       = $this->service->get_stats();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flash message parameter.
		$msg = sanitize_key( $_GET['msg'] ?? '' );

		include FFC_PLUGIN_DIR . 'templates/admin/url-shortener/short-urls-page.php';
	}
}
