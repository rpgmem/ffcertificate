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
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Template variables scoped to this file (the include runs in the including renderer method scope).
?>
		<h1>
			<?php
			/* translators: %s: reregistration title */
			echo esc_html( sprintf( __( 'Submissions: %s', 'ffcertificate' ), $rereg->title ) );
			?>
		</h1>
		<a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to Reregistrations', 'ffcertificate' ); ?></a>

		<?php \FreeFormCertificate\Core\AdminNotice::settings_errors( 'ffc_reregistration' ); ?>

		<?php
		// One shared stat card per status (#1631), toned for what the status
		// means, then the total; the component the dashboards draw.
		$ffc_rereg_stat_look = array(
			'pending'     => array( 'clock', 'neutral' ),
			'in_progress' => array( 'edit', 'info' ),
			'submitted'   => array( 'inbox', 'warning' ),
			'approved'    => array( 'checkmark', 'success' ),
			'rejected'    => array( 'cross', 'danger' ),
			'expired'     => array( 'history', 'neutral' ),
		);
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_stat_card() escapes every value.
		?>
		<div class="ffc-stats">
			<?php
			foreach ( $stats as $status => $count ) {
				if ( 'total' === $status ) {
					continue;
				}
				$ffc_look = $ffc_rereg_stat_look[ $status ] ?? array( 'info', 'neutral' );
				// Each card is a filter (#2242): its link lists that status.
				echo \FreeFormCertificate\Admin\AdminUI::get_stat_card(
					array(
						'label' => ReregistrationSubmissionReader::get_status_label( (string) $status ),
						'value' => (int) $count,
						'icon'  => $ffc_look[0],
						'tone'  => $ffc_look[1],
						'url'   => add_query_arg( 'sub_status', (string) $status, $base_url ),
						'link'  => __( 'Show', 'ffcertificate' ),
					)
				);
			}
			echo \FreeFormCertificate\Admin\AdminUI::get_stat_card(
				array(
					'label' => __( 'Total', 'ffcertificate' ),
					'value' => (int) $stats['total'],
					'icon'  => 'users',
					'url'   => $base_url,
					'link'  => __( 'Show', 'ffcertificate' ),
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</div>

		<!-- Filters & actions -->
		<div class="tablenav top">
			<form method="get" class="ffc-rereg-filters ffc-rereg-inline">
				<input type="hidden" name="page" value="<?php echo esc_attr( $menu_slug ); ?>">
				<input type="hidden" name="view" value="submissions">
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
				<select name="sub_status" aria-label="<?php esc_attr_e( 'Status', 'ffcertificate' ); ?>">
					<option value=""><?php esc_html_e( 'All Statuses', 'ffcertificate' ); ?></option>
					<option value="<?php echo esc_attr( ReregistrationSubmissionReader::NOT_FINISHED_FILTER ); ?>" <?php selected( $status_filter, ReregistrationSubmissionReader::NOT_FINISHED_FILTER ); ?>><?php esc_html_e( 'Not finished (pending or in progress)', 'ffcertificate' ); ?></option>
					<?php foreach ( ReregistrationSubmissionReader::STATUSES as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status_filter, $s ); ?>><?php echo esc_html( ReregistrationSubmissionReader::get_status_label( $s ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( count( $audiences ) > 1 ) : ?>
				<select name="audience" aria-label="<?php esc_attr_e( 'Audience', 'ffcertificate' ); ?>">
					<option value="0"><?php esc_html_e( 'All Audiences', 'ffcertificate' ); ?></option>
					<?php foreach ( $audiences as $ffc_audience_id => $ffc_audience_name ) : ?>
						<option value="<?php echo esc_attr( (string) $ffc_audience_id ); ?>" <?php selected( $audience_id, $ffc_audience_id ); ?>><?php echo esc_html( $ffc_audience_name ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php endif; ?>
				<input type="search" name="s" value="<?php echo esc_attr( $search ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Name, email, CPF or RF', 'ffcertificate' ); ?>" aria-label="<?php esc_attr_e( 'Search', 'ffcertificate' ); ?>">
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_button(
					array(
						'label' => __( 'Filter', 'ffcertificate' ),
						'icon'  => 'filter',
						'type'  => 'submit',
					)
				);
				?>
			</form>
			<?php
			// Batched CSV export (#772): the button drives the shared
			// window.FFCBatchedExport engine through the unified dispatcher. The
			// applied filters ride along (#2242), so the file holds the rows
			// listed here -- every page of them, not only this one.
			?>
			<?php
			\FreeFormCertificate\Admin\AdminUI::render_button(
				array(
					'label' => __( 'Export CSV', 'ffcertificate' ),
					'icon'  => 'download',
					'id'    => 'ffc-rereg-export-btn',
					'class' => 'ffc-rereg-ml-10',
					'attrs' => array(
						'data-id'         => (string) $id,
						'data-sub-status' => (string) $status_filter,
						'data-search'     => (string) $search,
						'data-audience'   => (string) $audience_id,
					),
				)
			);
			?>
			<?php self::render_submissions_pagination( $total, $pagination ); ?>
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
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_button(
					array(
						'label' => __( 'Apply', 'ffcertificate' ),
						'icon'  => 'checkmark',
						'type'  => 'submit',
					)
				);
				?>
			</div>
			<?php endif; ?>

			<?php
			// One "Reveal this page" over the list (#1863).
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- page_button() escapes every value.
			echo \FreeFormCertificate\Core\PiiReveal::page_button( \FreeFormCertificate\Reregistration\ReregistrationAjaxHandler::PII_CAPABILITY );
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
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
			<div class="tablenav bottom">
				<?php self::render_submissions_pagination( $total, $pagination ); ?>
			</div>
		</form>

		<?php if ( $can_edit ) : ?>
		<!-- Review decision modal (#2242): Reject and Return to Draft ask for the
			reason the member reads, and the submit is the confirmation. -->
		<div id="ffc-review-decision-modal" class="ffc-rereg-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="ffc-review-decision-title">
			<div class="ffc-rereg-modal-backdrop"></div>
			<div class="ffc-rereg-modal-content">
				<div class="ffc-rereg-modal-header">
					<h2 id="ffc-review-decision-title"></h2>
					<?php
					\FreeFormCertificate\Core\Button::render(
						array(
							'label'     => __( 'Close', 'ffcertificate' ),
							'category'  => 'neutral',
							'icon'      => 'cross',
							'icon_only' => true,
							'class'     => 'ffc-rereg-modal-close',
						)
					);
					?>
				</div>
				<form method="post" class="ffc-rereg-modal-body ffc-review-decision-form">
					<?php wp_nonce_field( 'review_decision_' . $id, 'ffc_review_nonce' ); ?>
					<input type="hidden" name="ffc_action" value="review_decision">
					<input type="hidden" name="reregistration_id" value="<?php echo esc_attr( (string) $id ); ?>">
					<input type="hidden" name="decision" value="">
					<div class="ffc-review-decision-ids"></div>
					<p class="ffc-review-decision-intro"></p>
					<p>
						<label for="ffc-review-reason"><strong><?php esc_html_e( 'Reason sent to the member', 'ffcertificate' ); ?></strong></label>
						<textarea id="ffc-review-reason" name="review_reason" class="large-text" rows="4" required minlength="<?php echo esc_attr( (string) \FreeFormCertificate\Reregistration\ReregistrationSubmissionWriter::MIN_REASON_LENGTH ); ?>"></textarea>
					</p>
					<p class="description">
						<?php
						printf(
							/* translators: %d: minimum number of characters. */
							esc_html__( 'The member reads it on the dashboard and receives it by e-mail. At least %d characters.', 'ffcertificate' ),
							(int) \FreeFormCertificate\Reregistration\ReregistrationSubmissionWriter::MIN_REASON_LENGTH
						);
						?>
					</p>
					<p class="ffc-review-decision-actions">
						<?php
						\FreeFormCertificate\Core\Button::render(
							array(
								'label'    => __( 'Cancel', 'ffcertificate' ),
								'category' => 'neutral',
								'icon'     => 'cross',
								'class'    => 'ffc-rereg-modal-close',
							)
						);
						\FreeFormCertificate\Core\Button::render(
							array(
								'label'    => __( 'Reject', 'ffcertificate' ),
								'category' => 'caution',
								'icon'     => 'cross',
								'type'     => 'submit',
								'class'    => 'ffc-review-decision-confirm',
								'attrs'    => array( 'data-decision' => 'reject' ),
							)
						);
						\FreeFormCertificate\Core\Button::render(
							array(
								'label'    => __( 'Return to Draft', 'ffcertificate' ),
								'category' => 'caution',
								'icon'     => 'restore',
								'type'     => 'submit',
								'class'    => 'ffc-review-decision-confirm',
								'attrs'    => array( 'data-decision' => 'return_to_draft' ),
							)
						);
						?>
					</p>
				</form>
			</div>
		</div>
		<?php endif; ?>

		<!-- Submission details modal -->
		<div id="ffc-submission-details-modal" class="ffc-rereg-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="ffc-submission-details-title">
			<div class="ffc-rereg-modal-backdrop"></div>
			<div class="ffc-rereg-modal-content">
				<div class="ffc-rereg-modal-header">
					<h2 id="ffc-submission-details-title"><?php esc_html_e( 'Submission Details', 'ffcertificate' ); ?></h2>
					<?php
					\FreeFormCertificate\Core\Button::render(
						array(
							'label'     => __( 'Close', 'ffcertificate' ),
							'category'  => 'neutral',
							'icon'      => 'cross',
							'icon_only' => true,
							'class'     => 'ffc-rereg-modal-close',
						)
					);
					?>
				</div>
				<div class="ffc-rereg-modal-body">
					<p class="ffc-rereg-modal-loading"><?php esc_html_e( 'Loading…', 'ffcertificate' ); ?></p>
				</div>
			</div>
		</div>
		<?php
