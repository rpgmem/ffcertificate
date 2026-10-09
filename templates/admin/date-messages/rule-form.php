<?php
/**
 * Template: Date Messages — rule editor.
 *
 * Included from page.php; see it for the variables in scope.
 *
 * @var bool                                          $can_manage Whether the user may change anything.
 * @var \FreeFormCertificate\DateMessages\Rule|null   $editing    Rule being edited, null for a new one.
 * @var array<string, mixed>                          $draft      Values submitted by a save that failed.
 * @var array<int, string>                            $audiences  Audience id => name.
 * @var array<int, string>                            $managers   Accounts that may receive the summary.
 * @var \DateTimeImmutable                            $today      Today, site timezone.
 *
 * @package FreeFormCertificate\DateMessages
 * @since   6.33.0
 */

use FreeFormCertificate\DateMessages\DateMessagesAdminPage;
use FreeFormCertificate\DateMessages\DateSources;
use FreeFormCertificate\DateMessages\MessageBuilder;
use FreeFormCertificate\DateMessages\Rule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_dm_defaults = MessageBuilder::defaults();
$ffc_dm_values   = null !== $editing ? array_merge( $editing->to_columns(), array( 'id' => $editing->id ) ) : array(
	'id'              => 0,
	'name'            => '',
	'source'          => 'birthday',
	'offset_days'     => 0,
	'audience_ids'    => '[]',
	'subject'         => $ffc_dm_defaults['subject'],
	'body'            => $ffc_dm_defaults['body'],
	'send_to_user'    => 1,
	'is_active'       => 1,
	'digest_enabled'  => 0,
	'digest_mode'     => 'summary',
	'digest_user_ids' => '[]',
	'appearance'      => '',
);
if ( array() !== $draft ) {
	$ffc_dm_values = array_merge( $ffc_dm_values, $draft );
}

$ffc_dm_offset = is_numeric( $ffc_dm_values['offset_days'] ) ? (int) $ffc_dm_values['offset_days'] : 0;
$ffc_dm_target = $today->modify( sprintf( '%+d days', -$ffc_dm_offset ) )->format( 'Y-m-d' );
$ffc_dm_flag   = static fn( $v ): bool => in_array( (string) $v, array( '1', 'true', 'on' ), true );
$ffc_dm_chosen = $ffc_dm_values['digest_user_ids'];
$ffc_dm_chosen = is_string( $ffc_dm_chosen ) ? json_decode( $ffc_dm_chosen, true ) : $ffc_dm_chosen;
$ffc_dm_chosen = array_map( 'intval', is_array( $ffc_dm_chosen ) ? $ffc_dm_chosen : array() );
// Stored as JSON, submitted (a failed save's draft) as a list.
$ffc_dm_audiences = $ffc_dm_values['audience_ids'];
$ffc_dm_audiences = is_string( $ffc_dm_audiences ) ? json_decode( $ffc_dm_audiences, true ) : $ffc_dm_audiences;
$ffc_dm_audiences = array_map( 'intval', is_array( $ffc_dm_audiences ) ? $ffc_dm_audiences : array() );
// Body appearance (#1660): stored as JSON, submitted as a list. Read raw, not
// through EmailBodyAppearance, so a failed save puts back what was typed.
$ffc_dm_app       = $ffc_dm_values['appearance'] ?? '';
$ffc_dm_app       = is_string( $ffc_dm_app ) ? json_decode( '' !== $ffc_dm_app ? $ffc_dm_app : '[]', true ) : $ffc_dm_app;
$ffc_dm_app       = array_merge(
	\FreeFormCertificate\Core\EmailBodyAppearance::none()->to_array(),
	is_array( $ffc_dm_app ) ? $ffc_dm_app : array()
);
$ffc_dm_model     = \FreeFormCertificate\Core\EmailTemplateOptions::all();
$ffc_dm_image_id  = is_numeric( $ffc_dm_app['image_id'] ) ? (int) $ffc_dm_app['image_id'] : 0;
$ffc_dm_image_src = $ffc_dm_image_id > 0 ? wp_get_attachment_image_url( $ffc_dm_image_id, 'medium' ) : false;
$ffc_dm_image_url = $ffc_dm_image_id > 0 ? wp_get_attachment_image_url( $ffc_dm_image_id, 'full' ) : false;
$ffc_dm_image_md  = $ffc_dm_image_id > 0 ? wp_get_attachment_metadata( $ffc_dm_image_id ) : false;
$ffc_dm_image_txt = '';
if ( is_array( $ffc_dm_image_md ) && isset( $ffc_dm_image_md['width'], $ffc_dm_image_md['height'] ) ) {
	$ffc_dm_image_txt = sprintf( '%s · %d × %d', wp_basename( (string) ( $ffc_dm_image_md['file'] ?? '' ) ), (int) $ffc_dm_image_md['width'], (int) $ffc_dm_image_md['height'] );
	if ( ! empty( $ffc_dm_image_md['filesize'] ) ) {
		$ffc_dm_image_txt .= ' · ' . size_format( (int) $ffc_dm_image_md['filesize'] );
	}
}
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ffc-dm-rule-form">
	<?php wp_nonce_field( DateMessagesAdminPage::SAVE_ACTION ); ?>
	<input type="hidden" name="action" value="<?php echo esc_attr( DateMessagesAdminPage::SAVE_ACTION ); ?>">
	<input type="hidden" name="rule[id]" value="<?php echo esc_attr( (string) (int) $ffc_dm_values['id'] ); ?>">

	<div class="card">
	<h2 class="ffc-icon-edit"><?php echo esc_html( (int) $ffc_dm_values['id'] > 0 ? __( 'Edit rule', 'ffcertificate' ) : __( 'New rule', 'ffcertificate' ) ); ?></h2>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="ffc-dm-name"><?php esc_html_e( 'Name', 'ffcertificate' ); ?></label></th>
			<td><input type="text" id="ffc-dm-name" name="rule[name]" class="regular-text" required value="<?php echo esc_attr( (string) $ffc_dm_values['name'] ); ?>"></td>
		</tr>
		<tr>
			<th scope="row"><label for="ffc-dm-source"><?php esc_html_e( 'Date', 'ffcertificate' ); ?></label></th>
			<td>
				<select id="ffc-dm-source" name="rule[source]">
					<?php foreach ( DateSources::ids() as $ffc_dm_source_id ) : ?>
						<?php $ffc_dm_source = DateSources::get( $ffc_dm_source_id ); ?>
						<option value="<?php echo esc_attr( $ffc_dm_source_id ); ?>" <?php selected( (string) $ffc_dm_values['source'], $ffc_dm_source_id ); ?>><?php echo esc_html( null === $ffc_dm_source ? $ffc_dm_source_id : $ffc_dm_source->label() ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="ffc-dm-offset"><?php esc_html_e( 'Days from the date', 'ffcertificate' ); ?></label></th>
			<td>
				<input type="number" id="ffc-dm-offset" name="rule[offset_days]" class="small-text" required step="1"
					min="<?php echo esc_attr( (string) -Rule::MAX_OFFSET_DAYS ); ?>" max="<?php echo esc_attr( (string) Rule::MAX_OFFSET_DAYS ); ?>"
					value="<?php echo esc_attr( (string) $ffc_dm_offset ); ?>">
				<p class="description"><?php esc_html_e( '0 sends on the date itself; -7 sends seven days before it, 7 seven days after it.', 'ffcertificate' ); ?></p>
			</td>
		</tr>
		<?php if ( array() !== $audiences ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Audiences', 'ffcertificate' ); ?></th>
				<td>
					<?php \FreeFormCertificate\Audience\AudienceTransferList::render( $ffc_dm_audiences, 'rule[audience_ids][]', false ); ?>
					<p class="description"><?php esc_html_e( 'A person receives the message when they belong to any audience on the right; members of sub-audiences are included. With none selected, the rule reaches everyone.', 'ffcertificate' ); ?></p>
				</td>
			</tr>
		<?php endif; ?>
	</table>
	</div>

	<div class="card">
	<h2 class="ffc-icon-email"><?php esc_html_e( 'Message', 'ffcertificate' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="ffc-dm-subject"><?php esc_html_e( 'Subject', 'ffcertificate' ); ?></label></th>
			<td><input type="text" id="ffc-dm-subject" name="rule[subject]" class="large-text" required value="<?php echo esc_attr( (string) $ffc_dm_values['subject'] ); ?>"></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Message', 'ffcertificate' ); ?></th>
			<td>
				<?php
				wp_editor(
					(string) $ffc_dm_values['body'],
					'ffc_dm_body',
					array(
						'textarea_name' => 'rule[body]',
						'textarea_rows' => 12,
						'media_buttons' => false,
					)
				);
				?>
				<p>
					<button type="button" class="button ffc-email-restore-default" data-editor="ffc_dm_body" data-default-key="date_message_body"><?php esc_html_e( 'Restore default text', 'ffcertificate' ); ?></button>
				</p>
				<p class="description">
					<?php esc_html_e( 'Placeholders:', 'ffcertificate' ); ?>
					<code>{{name}}</code> <code>{{first_name}}</code> <code>{{last_name}}</code> <code>{{full_name}}</code> <code>{{email}}</code> <code>{{date}}</code> <code>{{age}}</code> <code>{{days_until}}</code> <code>{{site_name}}</code> <code>{{dashboard_url}}</code> <code>{{unsubscribe_url}}</code>
				</p>
				<p class="description"><?php esc_html_e( 'The header and footer come from the Email Model in Settings → SMTP. A message that does not place {{unsubscribe_url}} gets an unsubscribe line added at the end.', 'ffcertificate' ); ?></p>
			</td>
		</tr>
	</table>
	</div>

	<div class="card ffc-dm-appearance" data-model-bg="<?php echo esc_attr( (string) $ffc_dm_model['body_bg'] ); ?>" data-model-text="<?php echo esc_attr( (string) $ffc_dm_model['body_text_color'] ); ?>">
	<h2 class="ffc-icon-image"><?php esc_html_e( 'Body appearance', 'ffcertificate' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Only the body of this message changes; the header and footer stay the Email Model\'s. Fields left empty follow the Email Model.', 'ffcertificate' ); ?></p>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Background image', 'ffcertificate' ); ?></th>
			<td>
				<input type="hidden" class="ffc-dm-image-id" name="rule[appearance][image_id]" value="<?php echo esc_attr( (string) $ffc_dm_image_id ); ?>">
				<p class="ffc-dm-image-preview<?php echo false === $ffc_dm_image_src ? ' ffc-hidden' : ''; ?>">
					<img class="ffc-dm-image-thumb" src="<?php echo esc_url( false !== $ffc_dm_image_src ? $ffc_dm_image_src : '' ); ?>" alt="" width="160">
					<br><span class="description ffc-dm-image-meta"><?php echo esc_html( $ffc_dm_image_txt ); ?></span>
				</p>
				<p>
					<button type="button" class="button ffc-dm-image-choose" data-title="<?php esc_attr_e( 'Choose a background image', 'ffcertificate' ); ?>" data-button="<?php esc_attr_e( 'Use this image', 'ffcertificate' ); ?>"><?php esc_html_e( 'Choose image', 'ffcertificate' ); ?></button>
					<button type="button" class="button ffc-dm-image-remove<?php echo 0 === $ffc_dm_image_id ? ' ffc-hidden' : ''; ?>"><?php esc_html_e( 'Remove', 'ffcertificate' ); ?></button>
				</p>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: e-mail width in pixels, from the Email Model */
							__( 'It covers the whole body, anchored on the side the text leaves free. Recommended: %d px wide (the Email Model width), up to 200 KB, with the strong details away from the text.', 'ffcertificate' ),
							(int) $ffc_dm_model['body_max_width']
						)
					);
					?>
				</p>
				<input type="hidden" class="ffc-dm-image-url" value="<?php echo esc_url( false !== $ffc_dm_image_url ? $ffc_dm_image_url : '' ); ?>">
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="ffc-dm-fallback-color"><?php esc_html_e( 'Fallback colour', 'ffcertificate' ); ?></label></th>
			<td>
				<input type="text" id="ffc-dm-fallback-color" class="ffc-dm-color ffc-dm-fallback" name="rule[appearance][fallback_color]" value="<?php echo esc_attr( (string) $ffc_dm_app['fallback_color'] ); ?>" data-default-color="<?php echo esc_attr( (string) $ffc_dm_model['body_bg'] ); ?>">
				<p class="description"><?php esc_html_e( 'Required with an image. It is what Outlook on Windows and readers that block images show instead of the image.', 'ffcertificate' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="ffc-dm-text-color"><?php esc_html_e( 'Text colour', 'ffcertificate' ); ?></label></th>
			<td>
				<input type="text" id="ffc-dm-text-color" class="ffc-dm-color ffc-dm-text" name="rule[appearance][text_color]" value="<?php echo esc_attr( (string) $ffc_dm_app['text_color'] ); ?>" data-default-color="<?php echo esc_attr( (string) $ffc_dm_model['body_text_color'] ); ?>">
				<?php
				/* translators: %s: contrast ratio, such as 7.2 */
				$ffc_dm_contrast_ok = __( 'Contrast %s:1 with the fallback colour.', 'ffcertificate' );
				/* translators: %s: contrast ratio, such as 3.1 */
				$ffc_dm_contrast_low = __( 'Contrast %s:1 with the fallback colour: below 4.5:1, hard to read.', 'ffcertificate' );
				?>
				<p class="ffc-dm-contrast" aria-live="polite" data-ok="<?php echo esc_attr( $ffc_dm_contrast_ok ); ?>" data-low="<?php echo esc_attr( $ffc_dm_contrast_low ); ?>"></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Text position', 'ffcertificate' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Text position', 'ffcertificate' ); ?></legend>
					<?php
					foreach (
						array(
							'full'  => __( 'Full width', 'ffcertificate' ),
							'left'  => __( 'On the left', 'ffcertificate' ),
							'right' => __( 'On the right', 'ffcertificate' ),
						) as $ffc_dm_pos => $ffc_dm_pos_label
					) :
						?>
						<label><input type="radio" name="rule[appearance][position]" value="<?php echo esc_attr( $ffc_dm_pos ); ?>" <?php checked( (string) $ffc_dm_app['position'], $ffc_dm_pos ); ?>> <?php echo esc_html( $ffc_dm_pos_label ); ?></label>&emsp;
					<?php endforeach; ?>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Text area width', 'ffcertificate' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Text area width', 'ffcertificate' ); ?></legend>
					<?php foreach ( \FreeFormCertificate\Core\EmailBodyAppearance::WIDTHS as $ffc_dm_width ) : ?>
						<label><input type="radio" name="rule[appearance][text_width]" value="<?php echo esc_attr( (string) $ffc_dm_width ); ?>" <?php checked( (int) $ffc_dm_app['text_width'], $ffc_dm_width ); ?>> <?php echo esc_html( $ffc_dm_width . '%' ); ?></label>&emsp;
					<?php endforeach; ?>
				</fieldset>
				<p class="description"><?php esc_html_e( 'Used when the text sits on one side. On phones the text takes the full width, over the image.', 'ffcertificate' ); ?></p>
			</td>
		</tr>
	</table>
	</div>

	<div class="card">
	<h2 class="ffc-icon-send"><?php esc_html_e( 'Sending', 'ffcertificate' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="ffc-dm-send-to-user"><?php esc_html_e( 'Recipients', 'ffcertificate' ); ?></label></th>
			<td>
				<?php
				// The toggle switch every other FFC setting uses; still a
				// checkbox named as before, so the save handler is unchanged.
				\FreeFormCertificate\Admin\AdminUI::render_toggle(
					array(
						'name'    => 'rule[send_to_user]',
						'id'      => 'ffc-dm-send-to-user',
						'checked' => $ffc_dm_flag( $ffc_dm_values['send_to_user'] ),
						'label'   => __( 'E-mail each person on their date', 'ffcertificate' ),
					)
				);
				?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="ffc-dm-is-active"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></label></th>
			<td>
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_toggle(
					array(
						'name'    => 'rule[is_active]',
						'id'      => 'ffc-dm-is-active',
						'checked' => $ffc_dm_flag( $ffc_dm_values['is_active'] ),
						'label'   => __( 'Active (the daily run sends it)', 'ffcertificate' ),
					)
				);
				?>
			</td>
		</tr>
	</table>
	</div>

	<div class="card">
	<h2 class="ffc-icon-users"><?php esc_html_e( 'Manager summary', 'ffcertificate' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="ffc-dm-digest-enabled"><?php esc_html_e( 'Summary', 'ffcertificate' ); ?></label></th>
			<td>
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_toggle(
					array(
						'name'    => 'rule[digest_enabled]',
						'id'      => 'ffc-dm-digest-enabled',
						'checked' => $ffc_dm_flag( $ffc_dm_values['digest_enabled'] ),
						'label'   => __( 'E-mail a summary of each run, 24 hours after it starts', 'ffcertificate' ),
					)
				);
				?>
				<p>
					<label for="ffc-dm-digest-mode"><?php esc_html_e( 'Content', 'ffcertificate' ); ?></label>
					<select id="ffc-dm-digest-mode" name="rule[digest_mode]">
						<option value="summary" <?php selected( (string) $ffc_dm_values['digest_mode'], 'summary' ); ?>><?php esc_html_e( 'Counts only', 'ffcertificate' ); ?></option>
						<option value="detailed" <?php selected( (string) $ffc_dm_values['digest_mode'], 'detailed' ); ?>><?php esc_html_e( 'Counts and the names of who received it', 'ffcertificate' ); ?></option>
					</select>
				</p>
				<p class="description"><?php esc_html_e( 'Names go only to recipients allowed to see who receives date messages; the others get the counts. A run that reached nobody sends no summary.', 'ffcertificate' ); ?></p>
				<?php if ( array() === $managers ) : ?>
					<p class="description"><?php esc_html_e( 'No account can receive the summary: it goes to administrators and to accounts holding a date-messages permission.', 'ffcertificate' ); ?></p>
				<?php else : ?>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Send the summary to', 'ffcertificate' ); ?></legend>
						<?php foreach ( $managers as $ffc_dm_manager_id => $ffc_dm_manager_label ) : ?>
							<label><input type="checkbox" name="rule[digest_user_ids][]" value="<?php echo esc_attr( (string) $ffc_dm_manager_id ); ?>" <?php checked( in_array( (int) $ffc_dm_manager_id, $ffc_dm_chosen, true ) ); ?>> <?php echo esc_html( $ffc_dm_manager_label ); ?></label><br>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	</div>

	<div class="card">
	<h2 class="ffc-icon-search"><?php esc_html_e( 'Check before saving', 'ffcertificate' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Uses the values on this form, saved or not. Nothing is sent and nothing is recorded.', 'ffcertificate' ); ?></p>
	<div class="ffc-dm-preview" data-ffc-dm-preview="form">
		<label><?php esc_html_e( 'From', 'ffcertificate' ); ?> <input type="date" class="ffc-dm-from" value="<?php echo esc_attr( $ffc_dm_target ); ?>"></label>
		<label><?php esc_html_e( 'To', 'ffcertificate' ); ?> <input type="date" class="ffc-dm-to" value="<?php echo esc_attr( $ffc_dm_target ); ?>"></label>
		<button type="button" class="button ffc-dm-preview-button"><?php esc_html_e( 'Preview recipients', 'ffcertificate' ); ?></button>
		<button type="button" class="button ffc-dm-message-button"><?php esc_html_e( 'Preview message', 'ffcertificate' ); ?></button>
		<?php if ( $can_manage ) : ?>
			<button type="button" class="button ffc-dm-test-button"><?php esc_html_e( 'Send test to me', 'ffcertificate' ); ?></button>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'The dates are the dates in the profile (such as birthdays), not the day the message goes out.', 'ffcertificate' ); ?></p>
		<div class="ffc-dm-preview-result" aria-live="polite"></div>
		<div class="ffc-dm-message ffc-hidden">
			<p>
				<strong class="ffc-dm-message-subject"></strong>
				<span class="ffc-dm-message-sizes">
					<button type="button" class="button button-small ffc-dm-message-size" data-width="<?php echo esc_attr( (string) ( (int) $ffc_dm_model['body_max_width'] + 40 ) ); ?>" aria-pressed="true"><?php esc_html_e( 'Computer', 'ffcertificate' ); ?></button>
					<button type="button" class="button button-small ffc-dm-message-size" data-width="360" aria-pressed="false"><?php esc_html_e( 'Phone', 'ffcertificate' ); ?></button>
				</span>
			</p>
			<iframe class="ffc-dm-message-frame" sandbox="" title="<?php esc_attr_e( 'Message preview', 'ffcertificate' ); ?>" width="<?php echo esc_attr( (string) ( (int) $ffc_dm_model['body_max_width'] + 40 ) ); ?>" height="640"></iframe>
			<p class="description"><?php esc_html_e( 'Sample values; images load as most readers show them. Outlook on Windows and readers that block images show the fallback colour instead.', 'ffcertificate' ); ?></p>
		</div>
	</div>
	</div>

	<?php if ( $can_manage ) : ?>
		<?php submit_button( __( 'Save rule', 'ffcertificate' ) ); ?>
	<?php endif; ?>
</form>
