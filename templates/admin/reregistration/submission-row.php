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
						<?php
						$ffc_rereg_reveal_button = \FreeFormCertificate\Core\PiiReveal::field_button(
							array(
								'type'          => 'reregistration',
								'field'         => 'email',
								'submission-id' => (string) $sub->id,
								'nonce'         => $reveal_nonce,
							)
						);
						echo $ffc_rereg_reveal_button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_button() escapes every value.
						?>
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
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_button(
						array(
							'label' => __( 'Approve', 'ffcertificate' ),
							'icon'  => 'checkmark',
							'href'  => $approve_url,
						)
					);
					\FreeFormCertificate\Admin\AdminUI::render_button(
						array(
							'label'    => __( 'Reject', 'ffcertificate' ),
							'category' => 'caution',
							'icon'     => 'cross',
							'href'     => $reject_url,
						)
					);
					?>
				<?php elseif ( 'pending' === $sub->status ) : ?>
					<span class="description"><?php esc_html_e( 'Awaiting user', 'ffcertificate' ); ?></span>
				<?php elseif ( ! empty( $sub->notes ) ) : ?>
					<span class="description" title="<?php echo esc_attr( $sub->notes ); ?>"><?php esc_html_e( 'See notes', 'ffcertificate' ); ?></span>
				<?php else : ?>
					—
				<?php endif; ?>
				<?php if ( $can_return_to_draft && $can_edit ) : ?>
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_button(
						array(
							'label'    => __( 'Return to Draft', 'ffcertificate' ),
							'category' => 'caution',
							'icon'     => 'restore',
							'href'     => $draft_url,
							'class'    => 'ffc-return-draft-btn',
							'attrs'    => array(
								'title' => __( 'Return to user for revision', 'ffcertificate' ),
							),
						)
					);
					?>
				<?php endif; ?>
				<?php if ( $can_reveal_pii ) : ?>
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_button(
						array(
							'label' => __( 'View Details', 'ffcertificate' ),
							'icon'  => 'eye',
							'class' => 'ffc-view-details-btn',
							'attrs' => array(
								'data-submission-id' => (string) $sub->id,
							),
						)
					);
					?>
				<?php endif; ?>
				<?php if ( $can_generate_record && in_array( $sub->status, array( 'submitted', 'approved' ), true ) ) : ?>
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_button(
						array(
							'label' => __( 'Record', 'ffcertificate' ),
							'icon'  => 'file',
							'class' => 'ffc-record-btn',
							'attrs' => array(
								'data-submission-id' => (string) $sub->id,
							),
						)
					);
					?>
				<?php endif; ?>
			</td>
		</tr>
		<?php
