<?php
/**
 * Template: Reregistration admin — Create/edit form.
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
		<h1><?php echo esc_html( $title ); ?></h1>
		<a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to Reregistrations', 'ffcertificate' ); ?></a>

		<?php settings_errors( 'ffc_reregistration' ); ?>

		<form method="post" action="" class="ffc-form">
			<?php wp_nonce_field( 'save_reregistration', 'ffc_reregistration_nonce' ); ?>
			<input type="hidden" name="reregistration_id" value="<?php echo esc_attr( (string) $id ); ?>">
			<input type="hidden" name="ffc_action" value="save_reregistration">

			<table class="form-table" role="presentation"><tbody>
				<tr>
					<th scope="row"><label for="rereg_title"><?php esc_html_e( 'Title', 'ffcertificate' ); ?> <span class="required">*</span></label></th>
					<td><input type="text" name="rereg_title" id="rereg_title" class="regular-text" value="<?php echo esc_attr( $item->title ?? '' ); ?>" required></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Audiences', 'ffcertificate' ); ?> <span class="required">*</span></th>
					<td>
						<?php self::render_audience_transfer_list( $audiences, $selected_ids ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rereg_start"><?php esc_html_e( 'Start Date', 'ffcertificate' ); ?> <span class="required">*</span></label></th>
					<td><input type="datetime-local" name="rereg_start_date" id="rereg_start" value="<?php echo esc_attr( $item ? gmdate( 'Y-m-d\TH:i', (int) strtotime( $item->start_date ) ) : '' ); ?>" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="rereg_end"><?php esc_html_e( 'End Date', 'ffcertificate' ); ?> <span class="required">*</span></label></th>
					<td><input type="datetime-local" name="rereg_end_date" id="rereg_end" value="<?php echo esc_attr( $item ? gmdate( 'Y-m-d\TH:i', (int) strtotime( $item->end_date ) ) : '' ); ?>" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="rereg_status"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></label></th>
					<td>
						<select name="rereg_status" id="rereg_status">
							<?php foreach ( ReregistrationRepository::STATUSES as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $item->status ?? 'draft', $s ); ?>>
									<?php echo esc_html( ReregistrationRepository::get_status_label( $s ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Approval', 'ffcertificate' ); ?></th>
					<td>
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'rereg_auto_approve',
								'checked' => ! empty( $item->auto_approve ),
								'label'   => __( 'Auto-approve submissions (no manual review needed)', 'ffcertificate' ),
							)
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Email Notifications', 'ffcertificate' ); ?></th>
					<td>
						<?php
						// Plain <div> wrapper rather than <fieldset> — WP admin's
						// `.form-table td fieldset label { display: inline-block }`
						// rule was overriding `.ffc-toggle { display: inline-flex }`
						// and rendering the toggle track over the label text.
						?>
						<div class="ffc-rereg-email-toggles">
							<p>
								<?php
								\FreeFormCertificate\Admin\AdminUI::render_toggle(
									array(
										'name'    => 'rereg_email_invitation',
										'checked' => ! empty( $item->email_invitation_enabled ),
										'label'   => __( 'Send invitation email when activated', 'ffcertificate' ),
									)
								);
								?>
							</p>
							<p>
								<?php
								\FreeFormCertificate\Admin\AdminUI::render_toggle(
									array(
										'name'    => 'rereg_email_reminder',
										'checked' => ! empty( $item->email_reminder_enabled ),
										'label'   => __( 'Send reminder email before deadline', 'ffcertificate' ),
									)
								);
								?>
							</p>
							<p>
								<?php
								\FreeFormCertificate\Admin\AdminUI::render_toggle(
									array(
										'name'    => 'rereg_email_confirmation',
										'checked' => ! empty( $item->email_confirmation_enabled ),
										'label'   => __( 'Send confirmation email after submission', 'ffcertificate' ),
									)
								);
								?>
							</p>
							<p class="description"><?php esc_html_e( 'All email notifications are disabled by default.', 'ffcertificate' ); ?></p>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rereg_reminder_days"><?php esc_html_e( 'Reminder Days', 'ffcertificate' ); ?></label></th>
					<td>
						<input type="number" name="rereg_reminder_days" id="rereg_reminder_days" value="<?php echo esc_attr( $item->reminder_days ?? '7' ); ?>" min="1" max="30" class="small-text">
						<p class="description"><?php esc_html_e( 'Send reminder this many days before the end date.', 'ffcertificate' ); ?></p>
					</td>
				</tr>
			</tbody></table>

			<p class="description" id="ffc-affected-users">
				<?php
				if ( $id > 0 ) {
					$affected = ReregistrationRepository::get_affected_user_ids_for_reregistration( $id );
					printf(
						'<strong>%s</strong> %s',
						esc_html__( 'Affected users:', 'ffcertificate' ),
						esc_html( (string) count( $affected ) )
					);
				}
				?>
			</p>

			<?php submit_button( $id > 0 ? __( 'Update Reregistration', 'ffcertificate' ) : __( 'Create Reregistration', 'ffcertificate' ) ); ?>
		</form>
		<?php
