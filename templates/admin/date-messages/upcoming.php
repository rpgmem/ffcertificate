<?php
/**
 * Template: Date Messages — upcoming dates.
 *
 * Included from page.php; see it for the variables in scope. Rendered only
 * for holders of the PII capability: it lists people by name.
 *
 * @var array<string, mixed> $upcoming    'from', 'to', 'rows', 'truncated'.
 * @var string               $period      Period key.
 * @var int                  $upcoming_rule      Rule filter, 0 for every active rule.
 * @var array<int, string>   $upcoming_audiences Audiences offered for that rule, id => name.
 * @var int                  $audience_id        Audience filter, 0 for all of them.
 * @var array<int, \FreeFormCertificate\DateMessages\Rule> $rules Every rule.
 * @var \DateTimeImmutable   $today       Today, site timezone.
 *
 * @package FreeFormCertificate\DateMessages
 * @since   6.33.0
 */

use FreeFormCertificate\DateMessages\DateMessagesAdminPage;
use FreeFormCertificate\DateMessages\RecipientResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_dm_rows = is_array( $upcoming['rows'] ?? null ) ? $upcoming['rows'] : array();
// Names arrive masked (#1863); each row reveals its own, audited, and the
// page button reveals them all in one audited request.
$ffc_dm_can_reveal   = \FreeFormCertificate\Core\PiiAccessPolicy::can_reveal_any( DateMessagesAdminPage::PII_CAP );
$ffc_dm_reveal_nonce = $ffc_dm_can_reveal ? wp_create_nonce( 'ffc_reveal_pii_nonce' ) : '';
?>
<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
	<input type="hidden" name="page" value="<?php echo esc_attr( DateMessagesAdminPage::MENU_SLUG ); ?>">
	<input type="hidden" name="tab" value="upcoming">
	<p>
		<label for="ffc-dm-period"><?php esc_html_e( 'Period', 'ffcertificate' ); ?></label>
		<select id="ffc-dm-period" name="period">
			<?php foreach ( DateMessagesAdminPage::upcoming_periods() as $ffc_dm_key => $ffc_dm_label ) : ?>
				<option value="<?php echo esc_attr( $ffc_dm_key ); ?>" <?php selected( $period, $ffc_dm_key ); ?>><?php echo esc_html( $ffc_dm_label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
		$ffc_dm_active = array_filter( $rules, static fn( $r ): bool => $r->is_active );
		?>
		<?php if ( array() !== $ffc_dm_active ) : ?>
			<label for="ffc-dm-upcoming-rule"><?php esc_html_e( 'Rule', 'ffcertificate' ); ?></label>
			<?php // A new rule changes which audiences the next filter offers, so it reloads the form. ?>
			<select id="ffc-dm-upcoming-rule" name="rule_filter" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e( 'All active rules', 'ffcertificate' ); ?></option>
				<?php foreach ( $ffc_dm_active as $ffc_dm_rule ) : ?>
					<option value="<?php echo esc_attr( (string) $ffc_dm_rule->id ); ?>" <?php selected( $upcoming_rule, $ffc_dm_rule->id ); ?>><?php echo esc_html( $ffc_dm_rule->name ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>
		<?php if ( array() !== $upcoming_audiences ) : ?>
			<label for="ffc-dm-upcoming-audience"><?php esc_html_e( 'Audience', 'ffcertificate' ); ?></label>
			<select id="ffc-dm-upcoming-audience" name="audience">
				<option value="0"><?php echo esc_html( $upcoming_rule > 0 ? __( 'All of the rule\'s audiences', 'ffcertificate' ) : __( 'Everyone', 'ffcertificate' ) ); ?></option>
				<?php foreach ( $upcoming_audiences as $ffc_dm_audience => $ffc_dm_name ) : ?>
					<option value="<?php echo esc_attr( (string) $ffc_dm_audience ); ?>" <?php selected( $audience_id, $ffc_dm_audience ); ?>><?php echo esc_html( $ffc_dm_name ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>
		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'ffcertificate' ); ?></button>
	</p>
</form>

<p class="description"><?php esc_html_e( 'Birthdays in the period, by day and month; the year of birth is never shown.', 'ffcertificate' ); ?></p>

<?php if ( array() === $ffc_dm_rows ) : ?>
	<p><?php esc_html_e( 'Nobody falls on these dates.', 'ffcertificate' ); ?></p>
<?php else : ?>
	<?php echo \FreeFormCertificate\Core\PiiReveal::page_button( DateMessagesAdminPage::PII_CAP ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- page_button() escapes every value. ?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Date', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Name', 'ffcertificate' ); ?></th>
				<?php if ( array() !== $upcoming_audiences ) : ?>
					<th scope="col"><?php esc_html_e( 'Audiences', 'ffcertificate' ); ?></th>
				<?php endif; ?>
				<th scope="col"><?php esc_html_e( 'In', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $ffc_dm_rows as $ffc_dm_row ) : ?>
				<?php
				$ffc_dm_day  = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $ffc_dm_row['date'], wp_timezone() );
				$ffc_dm_days = false === $ffc_dm_day ? 0 : (int) $today->diff( $ffc_dm_day )->format( '%r%a' );
				?>
				<tr>
					<td>
						<?php
						// Day and month only, which no DateFormatter context offers: its
						// formats all carry the year this panel must never show.
						echo esc_html( false === $ffc_dm_day ? '' : wp_date( _x( 'F j', 'day and month, without the year', 'ffcertificate' ), $ffc_dm_day->getTimestamp(), wp_timezone() ) );
						?>
					</td>
					<td>
						<span class="ffc-pii-row"><span class="ffc-pii-value" data-field="name"><?php echo esc_html( (string) $ffc_dm_row['name'] ); ?></span>
						<?php if ( $ffc_dm_can_reveal ) : ?>
							<button type="button" class="button button-small ffc-reveal-pii ffc-icon-eye" data-type="<?php echo esc_attr( DateMessagesAdminPage::REVEAL_TYPE ); ?>" data-field="name" data-submission-id="<?php echo esc_attr( (string) $ffc_dm_row['user_id'] ); ?>" data-nonce="<?php echo esc_attr( $ffc_dm_reveal_nonce ); ?>"><?php esc_html_e( 'Reveal', 'ffcertificate' ); ?></button>
						<?php endif; ?>
						</span>
					</td>
					<?php if ( array() !== $upcoming_audiences ) : ?>
						<td><?php echo esc_html( (string) ( $ffc_dm_row['audiences'] ?? '' ) ); ?></td>
					<?php endif; ?>
					<td>
						<?php
						echo esc_html(
							0 === $ffc_dm_days
								? __( 'Today', 'ffcertificate' )
								/* translators: %d: number of days */
								: sprintf( _n( '%d day', '%d days', $ffc_dm_days, 'ffcertificate' ), $ffc_dm_days )
						);
						?>
					</td>
					<td>
						<?php
						$ffc_dm_labels = DateMessagesAdminPage::decision_labels();
						echo esc_html( RecipientResolver::WILL_SEND === $ffc_dm_row['decision'] ? '' : ( $ffc_dm_labels[ $ffc_dm_row['decision'] ] ?? '' ) );
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( ! empty( $upcoming['truncated'] ) ) : ?>
		<p class="description"><?php esc_html_e( 'Only the first rows are listed; the totals count everyone.', 'ffcertificate' ); ?></p>
	<?php endif; ?>
<?php endif; ?>
