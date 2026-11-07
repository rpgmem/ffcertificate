<?php
/**
 * Template: Reregistration admin — Campaign list.
 *
 * Extracted verbatim from the matching ReregistrationAdminRenderer method
 * (rpgmem/ffcertificate#563 coverage hygiene); markup byte-identical. The
 * renderer prepares the locals and includes this file (method params + locals
 * + self:: sibling renderers resolve in the including method scope).
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.12.0
 */

use FreeFormCertificate\Reregistration\ReregistrationRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Template variables scoped to this file (the include runs in the including renderer method scope).
?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Reregistration', 'ffcertificate' ); ?></h1>
		<?php
		if ( $can_edit ) {
			\FreeFormCertificate\Admin\AdminUI::render_button(
				array(
					'label'    => __( 'New campaign', 'ffcertificate' ),
					'category' => 'create',
					'icon'     => 'plus',
					'href'     => $new_url,
				)
			);
		}
		?>
		<hr class="wp-header-end">

		<?php \FreeFormCertificate\Core\AdminNotice::settings_errors( 'ffc_reregistration' ); ?>

		<?php // The list top (#2498): a card with a label on every field. ?>
		<form method="get" class="ffc-list">
			<input type="hidden" name="page" value="<?php echo esc_attr( $menu_slug ); ?>">
			<div class="ffc-list-filters">
				<div class="ffc-list-filters__field">
					<label class="ffc-list-filters__label" for="ffc-rereg-status-filter"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></label>
					<select name="status" id="ffc-rereg-status-filter">
						<option value=""><?php esc_html_e( 'All statuses', 'ffcertificate' ); ?></option>
						<?php foreach ( ReregistrationRepository::STATUSES as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status_filter, $s ); ?>>
								<?php echo esc_html( ReregistrationRepository::get_status_label( $s ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="ffc-list-filters__field">
					<label class="ffc-list-filters__label" for="ffc-rereg-audience-filter"><?php esc_html_e( 'Audience', 'ffcertificate' ); ?></label>
					<select name="audience_id" id="ffc-rereg-audience-filter">
						<option value=""><?php esc_html_e( 'All Audiences', 'ffcertificate' ); ?></option>
						<?php self::render_audience_options( $audiences, $audience_filter ); ?>
					</select>
				</div>
				<div class="ffc-list-filters__actions">
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_button(
						array(
							'label' => __( 'Filter', 'ffcertificate' ),
							'icon'  => 'filter',
							'type'  => 'submit',
						)
					);
					if ( $status_filter || $audience_filter ) {
						\FreeFormCertificate\Admin\AdminUI::render_button(
							array(
								'label' => __( 'Clear', 'ffcertificate' ),
								'icon'  => 'eraser',
								'href'  => admin_url( 'admin.php?page=' . $menu_slug ),
							)
						);
					}
					?>
				</div>
			</div>
		</form>

		<div class="tablenav top">
			<div class="tablenav-pages one-page">
				<span class="displaying-num">
					<?php
					/* translators: %s: number of reregistration campaigns */
					echo esc_html( sprintf( _n( '%s campaign', '%s campaigns', count( $items ), 'ffcertificate' ), number_format_i18n( count( $items ) ) ) );
					?>
				</span>
			</div>
			<br class="clear">
		</div>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th class="column-title"><?php esc_html_e( 'Title', 'ffcertificate' ); ?></th>
					<th class="column-audience"><?php esc_html_e( 'Audience', 'ffcertificate' ); ?></th>
					<th class="column-status"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
					<th class="column-period"><?php esc_html_e( 'Period', 'ffcertificate' ); ?></th>
					<th class="column-submissions"><?php esc_html_e( 'Submissions', 'ffcertificate' ); ?></th>
					<th class="column-auto"><?php esc_html_e( 'Auto-approve', 'ffcertificate' ); ?></th>
					<th class="column-actions"><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $items ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No reregistrations found.', 'ffcertificate' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $items as $item ) : ?>
						<?php self::render_list_row( $menu_slug, $item, $can_edit ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
