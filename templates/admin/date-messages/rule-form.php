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
	'audience_id'     => null,
	'subject'         => $ffc_dm_defaults['subject'],
	'body'            => $ffc_dm_defaults['body'],
	'send_to_user'    => 1,
	'is_active'       => 1,
	'digest_enabled'  => 0,
	'digest_mode'     => 'summary',
	'digest_user_ids' => '[]',
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
				<p class="description"><?php esc_html_e( '0 sends on the date itself; -7 sends seven days before it.', 'ffcertificate' ); ?></p>
			</td>
		</tr>
		<?php if ( array() !== $audiences ) : ?>
			<tr>
				<th scope="row"><label for="ffc-dm-audience"><?php esc_html_e( 'Audience', 'ffcertificate' ); ?></label></th>
				<td>
					<select id="ffc-dm-audience" name="rule[audience_id]">
						<option value=""><?php esc_html_e( 'Everyone', 'ffcertificate' ); ?></option>
						<?php foreach ( $audiences as $ffc_dm_audience_id => $ffc_dm_audience_name ) : ?>
							<option value="<?php echo esc_attr( (string) $ffc_dm_audience_id ); ?>" <?php selected( (string) $ffc_dm_values['audience_id'], (string) $ffc_dm_audience_id ); ?>><?php echo esc_html( $ffc_dm_audience_name ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Members of sub-audiences are included.', 'ffcertificate' ); ?></p>
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
		<?php if ( $can_manage ) : ?>
			<button type="button" class="button ffc-dm-test-button"><?php esc_html_e( 'Send test to me', 'ffcertificate' ); ?></button>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'The dates are the dates in the profile (such as birthdays), not the day the message goes out.', 'ffcertificate' ); ?></p>
		<div class="ffc-dm-preview-result" aria-live="polite"></div>
	</div>
	</div>

	<?php if ( $can_manage ) : ?>
		<?php submit_button( __( 'Save rule', 'ffcertificate' ) ); ?>
	<?php endif; ?>
</form>
