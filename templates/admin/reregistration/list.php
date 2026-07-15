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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Template variables scoped to this file (the include runs in the including renderer method scope).
?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Reregistration', 'ffcertificate' ); ?></h1>
		<?php if ( $can_edit ) : ?>
		<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'ffcertificate' ); ?></a>
		<?php endif; ?>
		<hr class="wp-header-end">

		<?php settings_errors( 'ffc_reregistration' ); ?>

		<!-- Filters -->
		<div class="tablenav top">
			<form method="get" class="ffc-rereg-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( $menu_slug ); ?>">
				<select name="status">
					<option value=""><?php esc_html_e( 'All Statuses', 'ffcertificate' ); ?></option>
					<?php foreach ( ReregistrationRepository::STATUSES as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status_filter, $s ); ?>>
							<?php echo esc_html( ReregistrationRepository::get_status_label( $s ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<select name="audience_id">
					<option value=""><?php esc_html_e( 'All Audiences', 'ffcertificate' ); ?></option>
					<?php self::render_audience_options( $audiences, $audience_filter ); ?>
				</select>
				<?php submit_button( __( 'Filter', 'ffcertificate' ), '', '', false ); ?>
			</form>
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
