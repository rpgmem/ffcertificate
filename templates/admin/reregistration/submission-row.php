<?php
/**
 * Template: Reregistration admin — Submission row.
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
		<tr>
			<th class="check-column">
				<input type="checkbox" name="submission_ids[]" value="<?php echo esc_attr( $sub->id ); ?>">
			</th>
			<td><?php echo esc_html( $sub->user_name ?? '—' ); ?></td>
			<td><?php echo esc_html( $sub->user_email ?? '—' ); ?></td>
			<td>
				<span class="ffc-status-badge ffc-status-<?php echo esc_attr( $sub->status ); ?>">
					<?php echo esc_html( ReregistrationSubmissionReader::get_status_label( $sub->status ) ); ?>
				</span>
			</td>
			<td><?php echo esc_html( $submitted ); ?></td>
			<td><?php echo esc_html( $reviewed ); ?></td>
			<td>
				<?php if ( 'submitted' === $sub->status && $can_edit ) : ?>
					<a href="<?php echo esc_url( $approve_url ); ?>" class="button button-small"><?php esc_html_e( 'Approve', 'ffcertificate' ); ?></a>
					<a href="<?php echo esc_url( $reject_url ); ?>" class="button button-small button-link-delete"><?php esc_html_e( 'Reject', 'ffcertificate' ); ?></a>
				<?php elseif ( 'pending' === $sub->status ) : ?>
					<span class="description"><?php esc_html_e( 'Awaiting user', 'ffcertificate' ); ?></span>
				<?php elseif ( ! empty( $sub->notes ) ) : ?>
					<span class="description" title="<?php echo esc_attr( $sub->notes ); ?>"><?php esc_html_e( 'See notes', 'ffcertificate' ); ?></span>
				<?php else : ?>
					—
				<?php endif; ?>
				<?php if ( $can_return_to_draft && $can_edit ) : ?>
					<a href="<?php echo esc_url( $draft_url ); ?>" class="button button-small ffc-return-draft-btn" title="<?php esc_attr_e( 'Return to user for revision', 'ffcertificate' ); ?>">
						<span class="dashicons dashicons-edit ffc-rereg-icon"></span>
						<?php esc_html_e( 'Return to Draft', 'ffcertificate' ); ?>
					</a>
				<?php endif; ?>
				<button type="button" class="button button-small ffc-view-details-btn" data-submission-id="<?php echo esc_attr( $sub->id ); ?>">
					<span class="dashicons dashicons-visibility ffc-rereg-icon"></span>
					<?php esc_html_e( 'View Details', 'ffcertificate' ); ?>
				</button>
				<?php if ( in_array( $sub->status, array( 'submitted', 'approved' ), true ) ) : ?>
					<button type="button" class="button button-small ffc-ficha-btn" data-submission-id="<?php echo esc_attr( $sub->id ); ?>">
						<span class="dashicons dashicons-media-document ffc-rereg-icon"></span>
						<?php esc_html_e( 'Ficha', 'ffcertificate' ); ?>
					</button>
				<?php endif; ?>
			</td>
		</tr>
		<?php
