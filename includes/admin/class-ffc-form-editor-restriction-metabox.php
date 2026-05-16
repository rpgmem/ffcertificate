<?php
/**
 * Form Editor Restriction Metabox Renderer
 *
 * Extracted from FormEditorMetaboxRenderer as part of S3 god-object refactor.
 *
 * @since   3.1.1
 * @package FreeFormCertificate\Admin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form Editor Restriction Metabox Renderer.
 *
 * @since 3.1.1
 */
class FormEditorRestrictionMetabox {

	/**
	 * Composer for the Device Fingerprint Limit sub-section (rendered
	 * inline as the 5th item in the Form Restrictions list, with its
	 * sub-options trailing the standard restriction conditional rows).
	 * Optional so the metabox stays instantiable in tests / contexts
	 * where the device-limit metabox isn't wired in.
	 *
	 * @var FormEditorDeviceLimitMetabox|null
	 */
	private ?FormEditorDeviceLimitMetabox $device_limit;

	/**
	 * Constructor — accepts an optional Device Fingerprint Limit
	 * metabox composer. When supplied, it's woven into the restriction
	 * list as the 5th toggle (matching the password / allowlist /
	 * denylist / ticket visual) so admins see all "who can submit"
	 * controls in one place.
	 *
	 * @param FormEditorDeviceLimitMetabox|null $device_limit Optional
	 *        composer; pass null to render the standard 4 restrictions
	 *        without the device-fingerprint row (used in tests).
	 */
	public function __construct( ?FormEditorDeviceLimitMetabox $device_limit = null ) {
		$this->device_limit = $device_limit;
	}

	/**
	 * Section 3: Restrictions and Tickets
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render( WP_Post $post ): void {
		$config = get_post_meta( $post->ID, '_ffc_form_config', true );

		// Get restrictions (new structure).
		$restrictions     = isset( $config['restrictions'] ) ? $config['restrictions'] : array();
		$password_active  = ! empty( $restrictions['password'] ) && '1' === $restrictions['password'];
		$allowlist_active = ! empty( $restrictions['allowlist'] ) && '1' === $restrictions['allowlist'];
		$denylist_active  = ! empty( $restrictions['denylist'] ) && '1' === $restrictions['denylist'];
		$ticket_active    = ! empty( $restrictions['ticket'] ) && '1' === $restrictions['ticket'];

		// Legacy fields.
		$vcode     = isset( $config['validation_code'] ) ? $config['validation_code'] : '';
		$allow     = isset( $config['allowed_users_list'] ) ? $config['allowed_users_list'] : '';
		$deny      = isset( $config['denied_users_list'] ) ? $config['denied_users_list'] : '';
		$gen_codes = isset( $config['generated_codes_list'] ) ? $config['generated_codes_list'] : '';
		?>
		<table class="form-table">
			<tr>
				<th><label><?php esc_html_e( 'Form Restrictions', 'ffcertificate' ); ?></label></th>
				<td>
					<p class="description ffc-mb-15">
						<?php esc_html_e( 'Select which restrictions to apply (can combine multiple):', 'ffcertificate' ); ?>
					</p>

					<?php
					$ffc_restriction_rows = array(
						'password'  => array( 'ffc_restriction_password', $password_active, __( 'Single Password', 'ffcertificate' ), __( 'Shared password for all users', 'ffcertificate' ) ),
						'allowlist' => array( 'ffc_restriction_allowlist', $allowlist_active, __( 'Allowlist (CPF/RF)', 'ffcertificate' ), __( 'Only approved CPF/RF can submit', 'ffcertificate' ) ),
						'denylist'  => array( 'ffc_restriction_denylist', $denylist_active, __( 'Denylist (CPF/RF)', 'ffcertificate' ), __( 'Blocked CPF/RF cannot submit', 'ffcertificate' ) ),
						'ticket'    => array( 'ffc_restriction_ticket', $ticket_active, __( 'Ticket (Unique Codes)', 'ffcertificate' ), __( 'Requires valid ticket (consumed after use)', 'ffcertificate' ) ),
					);
					foreach ( $ffc_restriction_rows as $ffc_key => $ffc_row ) :
						list( $ffc_id, $ffc_active, $ffc_title, $ffc_hint ) = $ffc_row;
						?>
						<div class="ffc-restriction-label">
							<?php
							\FreeFormCertificate\Admin\AdminUI::render_toggle(
								array(
									'name'    => 'ffc_config[restrictions][' . $ffc_key . ']',
									'id'      => $ffc_id,
									'checked' => (bool) $ffc_active,
									'label'   => $ffc_title,
									'data'    => array( 'ffc-autosave-form-key' => 'restriction_' . $ffc_key ),
								)
							);
							?>
							<span class="description"> — <?php echo esc_html( $ffc_hint ); ?></span>
						</div>
					<?php endforeach; ?>

					<?php
					// Device Fingerprint Limit master toggle — composed
					// inline as the 5th item so it sits visually with the
					// other "who can submit" controls. Sub-options are
					// emitted further down, after the conditional rows
					// for the 4 standard restrictions, so they share the
					// same conditional-field layout.
					if ( $this->device_limit instanceof FormEditorDeviceLimitMetabox ) {
						$this->device_limit->render_master_toggle( $post );
					}
					?>

					<p class="description ffc-mt-15">
						<em><?php esc_html_e( 'Note: If no restriction is selected, form is Open (no restrictions).', 'ffcertificate' ); ?></em>
					</p>
				</td>
			</tr>

			<tr id="ffc_password_field"
				class="ffc-collapsed-target<?php echo $password_active ? '' : ' ffc-collapsed'; ?>"
				data-ffc-master="ffc_restriction_password"
				aria-hidden="<?php echo $password_active ? 'false' : 'true'; ?>">
				<th><label><?php esc_html_e( 'Password Value', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="text"
							name="ffc_config[validation_code]"
							value="<?php echo esc_attr( $vcode ); ?>"
							class="regular-text"
							placeholder="<?php esc_attr_e( 'Ex: PASS2025', 'ffcertificate' ); ?>">
					<p class="description"><?php esc_html_e( 'This password will be required from all users.', 'ffcertificate' ); ?></p>
				</td>
			</tr>

			<tr id="ffc_allowlist_field"
				class="ffc-collapsed-target<?php echo $allowlist_active ? '' : ' ffc-collapsed'; ?>"
				data-ffc-master="ffc_restriction_allowlist"
				aria-hidden="<?php echo $allowlist_active ? 'false' : 'true'; ?>">
				<th><label><?php esc_html_e( 'Allowlist (CPFs / IDs)', 'ffcertificate' ); ?></label></th>
				<td>
					<textarea name="ffc_config[allowed_users_list]"
								class="ffc-textarea-mono ffc-h120 ffc-w100"
								placeholder="<?php esc_attr_e( 'One per line...', 'ffcertificate' ); ?>"><?php echo esc_textarea( $allow ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Accepts formats: 12345678900 or 123.456.789-00', 'ffcertificate' ); ?></p>
				</td>
			</tr>

			<tr id="ffc_denylist_field"
				class="ffc-collapsed-target<?php echo $denylist_active ? '' : ' ffc-collapsed'; ?>"
				data-ffc-master="ffc_restriction_denylist"
				aria-hidden="<?php echo $denylist_active ? 'false' : 'true'; ?>">
				<th><label><?php esc_html_e( 'Denylist (Blocked)', 'ffcertificate' ); ?></label></th>
				<td>
					<textarea name="ffc_config[denied_users_list]"
								class="ffc-textarea-mono ffc-h80 ffc-w100"
								placeholder="<?php esc_attr_e( 'Banned users...', 'ffcertificate' ); ?>"><?php echo esc_textarea( $deny ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Has priority over Allowlist. Accepts same formats.', 'ffcertificate' ); ?></p>
				</td>
			</tr>

			<tr id="ffc_ticket_field"
				class="ffc-highlight-row ffc-collapsed-target<?php echo $ticket_active ? '' : ' ffc-collapsed'; ?>"
				data-ffc-master="ffc_restriction_ticket"
				aria-hidden="<?php echo $ticket_active ? 'false' : 'true'; ?>">
				<th><label class="ffc-label-accent"><?php esc_html_e( 'Ticket Generator', 'ffcertificate' ); ?></label></th>
				<td>
					<div class="ffc-admin-flex-row ffc-mb5">
						<input type="number" id="ffc_qty_codes" value="10" min="1" max="500" class="ffc-input-small">
						<button type="button" class="button button-secondary" id="ffc_btn_generate_codes"><?php esc_html_e( 'Generate Tickets', 'ffcertificate' ); ?></button>
						<span id="ffc_gen_status" class="ffc-gen-status"></span>
					</div>
					<textarea name="ffc_config[generated_codes_list]"
								id="ffc_generated_list"
								class="ffc-textarea-mono ffc-h120 ffc-w100"><?php echo esc_textarea( $gen_codes ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Tickets are consumed (removed) after successful use.', 'ffcertificate' ); ?></p>
				</td>
			</tr>
		</table>

		<?php
		// Device Fingerprint sub-options (max submissions / threshold /
		// message). Wrapped in `.ffc-collapsed-target` so they collapse
		// in unison with the master toggle that lives in the Form
		// Restrictions list above.
		if ( $this->device_limit instanceof FormEditorDeviceLimitMetabox ) {
			$this->device_limit->render_sub_options( $post );
		}
	}
}
