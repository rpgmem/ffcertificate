<?php
/**
 * Template: Reregistration admin — Submission row.
 *
 * Extracted from the matching ReregistrationAdminRenderer method
 * (rpgmem/ffcertificate#563 coverage hygiene). The markup has changed since
 * (the icon set, the Record label), so it is no longer a copy of that
 * method's output and is edited here. The renderer prepares the locals and
 * includes this file (method params + locals + self:: sibling renderers
 * resolve in the including method scope).
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.12.0
 */

use FreeFormCertificate\Reregistration\ReregistrationSubmissionReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
		<tr>
			<th class="check-column">
				<input type="checkbox" name="submission_ids[]" value="<?php echo esc_attr( $sub->id ); ?>">
			</th>
			<td><?php echo esc_html( $sub->user_name ?? '—' ); ?></td>
			<td>
				<?php if ( '' === $email_masked ) : ?>
					—
				<?php else : ?>
					<span class="ffc-pii-value" data-field="email"><?php echo esc_html( $email_masked ); ?></span>
					<?php if ( $can_reveal_pii ) : ?>
						<button type="button" class="button button-small ffc-reveal-pii ffc-icon-eye" data-type="reregistration" data-field="email" data-submission-id="<?php echo esc_attr( $sub->id ); ?>" data-nonce="<?php echo esc_attr( $reveal_nonce ); ?>"><?php esc_html_e( 'Reveal', 'ffcertificate' ); ?></button>
					<?php endif; ?>
				<?php endif; ?>
			</td>
			<td>
				<span class="ffc-reregistration-status-badge ffc-reregistration-status-<?php echo esc_attr( $sub->status ); ?>">
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
						<span class="ffc-icon-edit ffc-rereg-icon" aria-hidden="true"></span>
						<?php esc_html_e( 'Return to Draft', 'ffcertificate' ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $can_reveal_pii ) : ?>
					<button type="button" class="button button-small ffc-view-details-btn" data-submission-id="<?php echo esc_attr( $sub->id ); ?>">
						<span class="ffc-icon-eye ffc-rereg-icon" aria-hidden="true"></span>
						<?php esc_html_e( 'View Details', 'ffcertificate' ); ?>
					</button>
				<?php endif; ?>
				<?php if ( $can_generate_record && in_array( $sub->status, array( 'submitted', 'approved' ), true ) ) : ?>
					<button type="button" class="button button-small ffc-record-btn" data-submission-id="<?php echo esc_attr( $sub->id ); ?>">
						<span class="ffc-icon-file ffc-rereg-icon" aria-hidden="true"></span>
						<?php esc_html_e( 'Record', 'ffcertificate' ); ?>
					</button>
				<?php endif; ?>
			</td>
		</tr>
		<?php
