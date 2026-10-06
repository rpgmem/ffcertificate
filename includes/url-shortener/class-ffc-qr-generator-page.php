<?php
/**
 * Manual QR code generator — admin page.
 *
 * @package FreeFormCertificate\UrlShortener
 * @since   6.34.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Short URLs → QR Code Generator (#1563).
 *
 * A stateless tool: the operator picks a content type, fills it in and
 * styles the code, starting from the global design; the image is drawn on
 * the server and saved by the browser. Nothing is stored, except a short URL
 * when the operator explicitly asks for one and the operator's last design
 * (#1568), which the page opens with until "Reset to default" forgets it.
 */
class QrGeneratorPage {

	public const SLUG = 'ffc-qr-generator';

	/**
	 * Page hook suffix, known once the menu is registered.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Register hooks.
	 *
	 * The menu runs after the Short URLs top-level menu (priority 25), which
	 * this page hangs from.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 26 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the submenu.
	 */
	public function register_menu(): void {
		$hook       = add_submenu_page(
			'ffc-short-urls',
			__( 'QR Code Generator', 'ffcertificate' ),
			__( 'QR Code Generator', 'ffcertificate' ),
			QrGeneratorAjaxEndpoint::CAP,
			self::SLUG,
			array( $this, 'render_page' )
		);
		$this->hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Enqueue the generator's assets on its own screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->hook || $hook_suffix !== $this->hook ) {
			return;
		}

		\FreeFormCertificate\Core\AssetHelper::enqueue_common_style();
		\FreeFormCertificate\Core\AssetHelper::enqueue_dark_mode();
		wp_enqueue_media();

		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		// The design sections are a component shared with Settings → QR Code,
		// owned by their own sheet (#1570); this page's sheet builds on it.
		wp_enqueue_style( 'ffc-qr-design-fields', FFC_PLUGIN_URL . "assets/css/ffc-qr-design-fields{$s}.css", array( 'ffc-common' ), FFC_VERSION );
		wp_enqueue_style( 'ffc-qr-generator', FFC_PLUGIN_URL . "assets/css/ffc-qr-generator{$s}.css", array( 'ffc-common', 'ffc-qr-design-fields' ), FFC_VERSION );
		wp_enqueue_script( 'ffc-qr-raster', FFC_PLUGIN_URL . "assets/js/ffc-qr-raster{$s}.js", array(), FFC_VERSION, true );
		wp_enqueue_script( 'ffc-branding-media', FFC_PLUGIN_URL . "assets/js/ffc-branding-media{$s}.js", array( 'jquery' ), FFC_VERSION, true );
		wp_localize_script( 'ffc-branding-media', 'ffcBrandingMedia', array( 'chooseImage' => __( 'Select image', 'ffcertificate' ) ) );
		wp_enqueue_script( 'ffc-qr-design', FFC_PLUGIN_URL . "assets/js/ffc-qr-design{$s}.js", array( 'jquery', 'ffc-core' ), FFC_VERSION, true );
		wp_enqueue_script(
			'ffc-qr-generator',
			FFC_PLUGIN_URL . "assets/js/ffc-qr-generator{$s}.js",
			array( 'jquery', 'ffc-core', 'ffc-qr-design', 'ffc-qr-raster' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-qr-generator',
			'ffcQrGenerator',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'generate'      => QrGeneratorAjaxEndpoint::ACTION_GENERATE,
				'generateNonce' => wp_create_nonce( QrGeneratorAjaxEndpoint::ACTION_GENERATE ),
				'shorten'       => QrGeneratorAjaxEndpoint::ACTION_SHORTEN,
				'shortenNonce'  => wp_create_nonce( QrGeneratorAjaxEndpoint::ACTION_SHORTEN ),
				'remember'      => QrGeneratorAjaxEndpoint::ACTION_REMEMBER,
				'rememberNonce' => wp_create_nonce( QrGeneratorAjaxEndpoint::ACTION_REMEMBER ),
				'i18n'          => array(
					/* translators: 1: percentage of the code in use, 2: characters that still fit, 3: error correction level (L, M, Q or H) */
					'usage'           => __( '%1$d%% full: about %2$d more characters fit at error correction %3$s (an accented letter counts as two).', 'ffcertificate' ),
					/* translators: 1: percentage of the code in use, 2: characters that still fit */
					'usageForced'     => __( '%1$d%% full: about %2$d more characters fit at error correction H, which the logo requires (an accented letter counts as two).', 'ffcertificate' ),
					/* translators: %d: QR code version (1-40) */
					'dense'           => __( 'Dense code (version %d): print it at least 3 cm wide, or shorten the content.', 'ffcertificate' ),
					/* translators: %s: contrast ratio, e.g. 3.2 */
					'lowContrast'     => __( 'Low contrast (%s:1). Phones may fail to read this code; aim for 4:1 or more.', 'ffcertificate' ),
					'inverted'        => __( 'The modules are lighter than the background. Most readers cannot scan an inverted code.', 'ffcertificate' ),
					'captionContrast' => __( 'The frame caption is hard to read on this background; pick a darker frame colour.', 'ffcertificate' ),
					'transparent'     => __( 'Transparent background: contrast cannot be checked. Test the code on the surface it will be printed on; a dark one makes it unreadable.', 'ffcertificate' ),
					'ok'              => __( 'Readable: contrast and colours are fine.', 'ffcertificate' ),
					'error'           => __( 'The QR code could not be drawn.', 'ffcertificate' ),
					'untitled'        => __( '(no title)', 'ffcertificate' ),
					/* translators: 1: creation date, 2: number of clicks */
					'duplicateMeta'   => __( 'created %1$s, %2$d clicks', 'ffcertificate' ),
					'useThis'         => __( 'Use this', 'ffcertificate' ),
					'titleRequired'   => __( 'Enter a title for the short URL before downloading or printing.', 'ffcertificate' ),
					'acknowledge'     => __( 'A short URL already sends to this address: click "Use this", or tick the box to create another one.', 'ffcertificate' ),
					'copied'          => __( 'Copied.', 'ffcertificate' ),
					'copyFailed'      => __( 'Could not copy; select the address and copy it by hand.', 'ffcertificate' ),
					'reset'           => __( 'Design reset to the global default.', 'ffcertificate' ),
					/* translators: %s: title of the short URL */
					'saved'           => __( '"%s" is stored and now counts the scans. Copy the link to share it.', 'ffcertificate' ),
				),
			)
		);
	}

	/**
	 * Render the page.
	 */
	public function render_page(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( QrGeneratorAjaxEndpoint::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ffcertificate' ) );
		}

		// The user's last design when there is one, the global design otherwise.
		$ffc_qr_state     = QrGeneratorDesignMemory::state( get_current_user_id() );
		$ffc_qr_design    = QrGeneratorDesignMemory::design( $ffc_qr_state );
		$ffc_qr_logo_id   = (int) $ffc_qr_state['qr_design_logo_id'];
		$ffc_qr_gradient  = (bool) $ffc_qr_state['qr_design_gradient'];
		$ffc_qr_color_end = (string) $ffc_qr_state['qr_design_color_end'];
		$ffc_qr_margin    = (int) $ffc_qr_state['margin'];
		$ffc_qr_level     = (string) $ffc_qr_state['error_level'];
		$ffc_qr_name      = static fn( string $key ): string => 'design[' . $key . ']';

		include FFC_PLUGIN_DIR . 'templates/admin/qr/generator-page.php';
	}
}
