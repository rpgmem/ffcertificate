<?php
/**
 * Template: Date Messages — send now.
 *
 * Included from page.php; see it for the variables in scope.
 *
 * @var bool                                             $can_manage  Whether the user may change anything.
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
?>
<p class="description">
	<?php
	echo esc_html(
		sprintf(
			/* translators: %d: maximum number of days */
			__( 'Sends one rule to everyone whose date falls in a range of up to %d days. People already sent this rule for that date are skipped, so running a range twice sends nothing new.', 'ffcertificate' ),
			Runner::MAX_RANGE_DAYS
		)
	);
	?>
</p>

<?php if ( ! $queue_ready ) : ?>
	<?php
	wp_admin_notice(
		esc_html__( 'No mail queue is active, so a large range is handed to wp_mail() as fast as PHP runs. Installing a mail queue plugin paces the sending.', 'ffcertificate' ),
		array(
			'type'               => 'info',
			'additional_classes' => array( 'inline' ),
		)
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
								<option value="<?php echo esc_attr( (string) $ffc_dm_rule->id ); ?>"><?php echo esc_html( $ffc_dm_rule->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Dates in the profile', 'ffcertificate' ); ?></th>
					<td>
						<label><?php esc_html_e( 'From', 'ffcertificate' ); ?> <input type="date" name="from" class="ffc-dm-from" required value="<?php echo esc_attr( $today->format( 'Y-m-d' ) ); ?>"></label>
						<label><?php esc_html_e( 'To', 'ffcertificate' ); ?> <input type="date" name="to" class="ffc-dm-to" required value="<?php echo esc_attr( $today->format( 'Y-m-d' ) ); ?>"></label>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" class="button ffc-dm-preview-button"><?php esc_html_e( 'Preview recipients', 'ffcertificate' ); ?></button>
				<?php if ( $can_manage ) : ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Send now', 'ffcertificate' ); ?></button>
				<?php endif; ?>
			</p>
			<div class="ffc-dm-preview-result" aria-live="polite"></div>
		</div>
	</form>
<?php endif; ?>
