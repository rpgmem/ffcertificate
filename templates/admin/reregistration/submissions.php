<?php
/**
 * Template: Reregistration admin — Submissions list.
 *
 * Extracted verbatim from the matching ReregistrationAdminRenderer method
 * (rpgmem/ffcertificate#563 coverage hygiene); markup byte-identical. The
 * renderer prepares the locals and includes this file (method params + locals
 * + self:: sibling renderers resolve in the including method scope).
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.12.0
 */

use FreeFormCertificate\Reregistration\ReregistrationSubmissionReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Template variables scoped to this file (the include runs in the including renderer method scope).
?>
		<h1>
			<?php
			/* translators: %s: reregistration title */
			echo esc_html( sprintf( __( 'Submissions: %s', 'ffcertificate' ), $rereg->title ) );
			?>
		</h1>
		<a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to Reregistrations', 'ffcertificate' ); ?></a>

		<?php settings_errors( 'ffc_reregistration' ); ?>

		<!-- Stats summary -->
		<div class="ffc-rereg-stats">
			<?php foreach ( $stats as $status => $count ) : ?>
				<?php if ( 'total' !== $status ) : ?>
					<span class="ffc-stat-item">
						<span class="ffc-status-badge ffc-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ReregistrationSubmissionReader::get_status_label( $status ) ); ?></span>
						<strong><?php echo esc_html( (string) $count ); ?></strong>
					</span>
				<?php endif; ?>
			<?php endforeach; ?>
			<span class="ffc-stat-item">
				<?php esc_html_e( 'Total:', 'ffcertificate' ); ?> <strong><?php echo esc_html( (string) $stats['total'] ); ?></strong>
			</span>
		</div>

		<!-- Filters & actions -->
		<div class="tablenav top">
			<form method="get" class="ffc-rereg-filters ffc-rereg-inline">
				<input type="hidden" name="page" value="<?php echo esc_attr( $menu_slug ); ?>">
				<input type="hidden" name="view" value="submissions">
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
				<select name="sub_status">
					<option value=""><?php esc_html_e( 'All Statuses', 'ffcertificate' ); ?></option>
					<?php foreach ( ReregistrationSubmissionReader::STATUSES as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status_filter, $s ); ?>><?php echo esc_html( ReregistrationSubmissionReader::get_status_label( $s ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Search name or email...', 'ffcertificate' ); ?>">
				<?php submit_button( __( 'Filter', 'ffcertificate' ), '', '', false ); ?>
			</form>
			<?php
			// Batched CSV export (#772): the button drives the shared
			// window.FFCBatchedExport engine through the unified dispatcher. The
			// campaign id rides along via data-id; the export covers the whole
			// campaign (unaffected by the on-screen status/search filter, as before).
			?>
			<button type="button" id="ffc-rereg-export-btn" class="button ffc-rereg-ml-10" data-id="<?php echo esc_attr( (string) $id ); ?>">
				<?php esc_html_e( 'Export CSV', 'ffcertificate' ); ?>
			</button>
			<span id="ffc-rereg-export-progress" style="display:none;margin-left:8px;"></span>
		</div>

		<!-- Bulk actions form -->
		<form method="post" id="ffc-submissions-form">
			<?php wp_nonce_field( 'bulk_submissions_' . $id, 'ffc_bulk_nonce' ); ?>
			<input type="hidden" name="ffc_action" value="bulk_submissions">
			<input type="hidden" name="reregistration_id" value="<?php echo esc_attr( (string) $id ); ?>">

			<?php if ( $can_edit ) : ?>
			<div class="tablenav top">
				<select name="bulk_action">
					<option value=""><?php esc_html_e( 'Bulk Actions', 'ffcertificate' ); ?></option>
					<option value="approve"><?php esc_html_e( 'Approve', 'ffcertificate' ); ?></option>
					<option value="return_to_draft"><?php esc_html_e( 'Return to Draft', 'ffcertificate' ); ?></option>
					<option value="send_reminder"><?php esc_html_e( 'Send Reminder', 'ffcertificate' ); ?></option>
				</select>
				<?php submit_button( __( 'Apply', 'ffcertificate' ), 'action', '', false ); ?>
			</div>
			<?php endif; ?>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" id="cb-select-all"></td>
						<th><?php esc_html_e( 'User', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Email', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Submitted', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Reviewed', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $submissions ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No submissions found.', 'ffcertificate' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $submissions as $sub ) : ?>
							<?php self::render_submission_row( $menu_slug, $sub, $id, $can_edit ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</form>

		<!-- Submission details modal -->
		<div id="ffc-submission-details-modal" class="ffc-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="ffc-submission-details-title">
			<div class="ffc-modal-backdrop"></div>
			<div class="ffc-modal-content">
				<div class="ffc-modal-header">
					<h2 id="ffc-submission-details-title"><?php esc_html_e( 'Submission Details', 'ffcertificate' ); ?></h2>
					<button type="button" class="ffc-modal-close" aria-label="<?php esc_attr_e( 'Close', 'ffcertificate' ); ?>">&times;</button>
				</div>
				<div class="ffc-modal-body">
					<p class="ffc-modal-loading"><?php esc_html_e( 'Loading…', 'ffcertificate' ); ?></p>
				</div>
			</div>
		</div>
		<?php
