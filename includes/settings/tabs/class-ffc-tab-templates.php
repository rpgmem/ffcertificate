<?php
/**
 * Document Templates Tab
 *
 * A launcher tab (#951 Direction 1): the document-template pool (certificates +
 * appointment receipts, and future kinds) is managed on the native
 * `ffc_cert_template` list/edit screens. Rather than surface that list as a
 * standalone submenu (which drops you out of the Settings tabbed UI), this tab
 * lives inside Settings and links into the hub — Manage, per-kind filters and
 * New — so the models stay one click away without a second top-level surface.
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since   6.20.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;
use FreeFormCertificate\Admin\CertTemplateCpt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Document Templates launcher tab.
 */
class TabTemplates extends SettingsTab {

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'templates';
		$this->tab_group = 'content';
		$this->tab_title = __( 'Document Templates', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-doc';
		$this->tab_order = 15;
	}

	/**
	 * The tab is gated by the forms caps (the pool's own capability), not the
	 * page-wide settings caps — so a template operator sees it without holding
	 * `ffc_view_settings`.
	 */
	public function get_view_cap(): string {
		return 'ffc_view_forms';
	}

	/**
	 * Manage cap for the tab — the forms manage cap (see {@see self::get_view_cap()}).
	 */
	public function get_manage_cap(): string {
		return 'ffc_manage_forms';
	}

	/**
	 * The hub list URL, optionally pre-filtered to a template kind.
	 *
	 * @param string $kind One of the `CertTemplateCpt::KIND_*` values, or '' for all.
	 * @return string
	 */
	private static function hub_url( string $kind = '' ): string {
		$url = 'edit.php?post_type=' . CertTemplateCpt::POST_TYPE;
		if ( '' !== $kind ) {
			$url .= '&ffc_kind=' . $kind;
		}
		return admin_url( $url );
	}

	/**
	 * New-template URL, pre-setting the given kind (the CPT stamps it on save).
	 *
	 * @param string $kind One of the `CertTemplateCpt::KIND_*` values.
	 * @return string
	 */
	private static function new_url( string $kind ): string {
		return admin_url( 'post-new.php?post_type=' . CertTemplateCpt::POST_TYPE . '&ffc_kind=' . $kind );
	}

	/**
	 * Render the launcher: a short explainer + buttons into the hub.
	 *
	 * @return void
	 */
	public function render(): void {
		$can_manage = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_forms' );
		?>
		<h2><?php esc_html_e( 'Document Templates', 'ffcertificate' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Create, edit and duplicate the HTML templates for your certificates and appointment receipts. They all live in one pool — use the buttons below to open the full management screen.', 'ffcertificate' ); ?>
		</p>

		<p>
			<a class="button button-primary" href="<?php echo esc_url( self::hub_url() ); ?>">
				<?php esc_html_e( 'Manage all templates', 'ffcertificate' ); ?>
			</a>
		</p>

		<h3><?php esc_html_e( 'Certificates', 'ffcertificate' ); ?></h3>
		<p>
			<a class="button" href="<?php echo esc_url( self::hub_url( CertTemplateCpt::KIND_CERTIFICATE ) ); ?>">
				<?php esc_html_e( 'Manage certificate templates', 'ffcertificate' ); ?>
			</a>
			<?php if ( $can_manage ) : ?>
				<a class="button" href="<?php echo esc_url( self::new_url( CertTemplateCpt::KIND_CERTIFICATE ) ); ?>">
					<?php esc_html_e( '+ New certificate template', 'ffcertificate' ); ?>
				</a>
			<?php endif; ?>
		</p>

		<h3><?php esc_html_e( 'Appointment receipts', 'ffcertificate' ); ?></h3>
		<p>
			<a class="button" href="<?php echo esc_url( self::hub_url( CertTemplateCpt::KIND_APPOINTMENT_RECEIPT ) ); ?>">
				<?php esc_html_e( 'Manage receipt templates', 'ffcertificate' ); ?>
			</a>
			<?php if ( $can_manage ) : ?>
				<a class="button" href="<?php echo esc_url( self::new_url( CertTemplateCpt::KIND_APPOINTMENT_RECEIPT ) ); ?>">
					<?php esc_html_e( '+ New receipt template', 'ffcertificate' ); ?>
				</a>
			<?php endif; ?>
			<br>
			<span class="description">
				<?php esc_html_e( 'Which receipt template each scheduling mode uses is chosen in Scheduling → Settings → Receipt.', 'ffcertificate' ); ?>
			</span>
		</p>

		<h3><?php esc_html_e( 'Fichas (reregistration)', 'ffcertificate' ); ?></h3>
		<p>
			<a class="button" href="<?php echo esc_url( self::hub_url( CertTemplateCpt::KIND_FICHA ) ); ?>">
				<?php esc_html_e( 'Manage ficha templates', 'ffcertificate' ); ?>
			</a>
			<?php if ( $can_manage ) : ?>
				<a class="button" href="<?php echo esc_url( self::new_url( CertTemplateCpt::KIND_FICHA ) ); ?>">
					<?php esc_html_e( '+ New ficha template', 'ffcertificate' ); ?>
				</a>
			<?php endif; ?>
			<br>
			<span class="description">
				<?php esc_html_e( 'Which ficha template the reregistration PDF uses is chosen in Settings → Reregistration.', 'ffcertificate' ); ?>
			</span>
		</p>
		<?php
	}
}
