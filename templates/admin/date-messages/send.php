<?php
/**
 * Template: Date Messages — send now.
 *
 * Included from page.php; see it for the variables in scope.
 *
 * @var bool                                             $can_send  Whether the user may send a rule now.
 * @var array<int, \FreeFormCertificate\DateMessages\Rule> $rules       Every rule.
 * @var bool                                             $queue_ready Whether a mail queue is active.
 * @var \DateTimeImmutable                               $today       Today, site timezone.
 *
 * @package FreeFormCertificate\DateMessages
 * @since   6.33.0
 */

use FreeFormCertificate\DateMessages\DateMessagesAdminPage;
use FreeFormCertificate\DateMessages\Runner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// An announcement has no date to choose: the dates row starts hidden when the
// first rule is one (#1949). The script follows the rule picker.
$ffc_dm_first_ann = ! empty( $rules ) && array_values( $rules )[0]->is_announcement();
?>
<p class="description">
	<?php
	echo esc_html(
		sprintf(
			/* translators: %d: maximum number of days */
			__( 'Sends one rule to everyone whose date falls in a range of up to %d days. People already sent this rule for that date are skipped, so running a range twice sends nothing new. An announcement has no date: it goes to everyone in its audience, on the one day it is sent.', 'ffcertificate' ),
			Runner::MAX_RANGE_DAYS
		)
	);
	?>
</p>

<?php if ( ! $queue_ready ) : ?>
	<?php
	\FreeFormCertificate\Core\AdminNotice::callout(
		'info',
		esc_html__( 'No mail queue is active, so a large range is handed to wp_mail() as fast as PHP runs. Installing a mail queue plugin paces the sending.', 'ffcertificate' )
	);
	?>
<?php endif; ?>

<?php if ( array() === $rules ) : ?>
	<p><?php esc_html_e( 'No rules yet.', 'ffcertificate' ); ?></p>
<?php else : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ffc-dm-send-form" data-ffc-confirm="<?php esc_attr_e( 'Send these messages now?', 'ffcertificate' ); ?>">
		<?php wp_nonce_field( DateMessagesAdminPage::SEND_ACTION ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( DateMessagesAdminPage::SEND_ACTION ); ?>">
		<div class="ffc-dm-preview" data-ffc-dm-preview="saved">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ffc-dm-send-rule"><?php esc_html_e( 'Rule', 'ffcertificate' ); ?></label></th>
					<td>
						<select id="ffc-dm-send-rule" name="rule_id" class="ffc-dm-rule">
							<?php foreach ( $rules as $ffc_dm_rule ) : ?>
								<option value="<?php echo esc_attr( (string) $ffc_dm_rule->id ); ?>" data-ffc-dm-kind="<?php echo esc_attr( $ffc_dm_rule->kind ); ?>"><?php echo esc_html( $ffc_dm_rule->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr data-ffc-dm-dated-only
				<?php
				if ( $ffc_dm_first_ann ) :
					?>
					hidden<?php endif; ?>>
					<th scope="row"><?php esc_html_e( 'Dates in the profile', 'ffcertificate' ); ?></th>
					<td>
						<label><?php esc_html_e( 'From', 'ffcertificate' ); ?> <input type="date" name="from" class="ffc-dm-from" value="<?php echo esc_attr( $today->format( 'Y-m-d' ) ); ?>"></label>
						<label><?php esc_html_e( 'To', 'ffcertificate' ); ?> <input type="date" name="to" class="ffc-dm-to" value="<?php echo esc_attr( $today->format( 'Y-m-d' ) ); ?>"></label>
					</td>
				</tr>
			</table>
			<p>
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_button(
					array(
						'label' => __( 'Preview recipients', 'ffcertificate' ),
						'icon'  => 'users',
						'class' => 'ffc-dm-preview-button',
					)
				);
				if ( $can_send ) {
					\FreeFormCertificate\Admin\AdminUI::render_button(
						array(
							'label'    => __( 'Send now', 'ffcertificate' ),
							'category' => 'primary',
							'icon'     => 'send',
							'type'     => 'submit',
						)
					);
				}
				?>
			</p>
			<div class="ffc-dm-preview-result" aria-live="polite"></div>
		</div>
	</form>
<?php endif; ?>
