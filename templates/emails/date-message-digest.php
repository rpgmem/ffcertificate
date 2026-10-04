<?php
/**
 * Date-messages manager digest body.
 *
 * Wrapped by the configurable chrome (layout.php) at send. Rendered by
 * Digest::send(). A system e-mail, not hub-editable: what it reports is fixed.
 *
 * @var array<string, mixed> $args {
 *     @type string                $rule_name   Rule name.
 *     @type string                $trigger     'cron' or 'manual'.
 *     @type string                $target_from First target day, Y-m-d.
 *     @type string                $target_to   Last target day, Y-m-d.
 *     @type array<string, int>    $counts      Counter => value.
 *     @type array<int,string>|null $names      Recipients by name, or null when this manager may not see them.
 *     @type int                   $names_limit Most names listed.
 * }
 * @package FreeFormCertificate\DateMessages
 */

use FreeFormCertificate\Core\DateFormatter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_dm_counts = is_array( $args['counts'] ?? null ) ? $args['counts'] : array();
$ffc_dm_from   = (string) ( $args['target_from'] ?? '' );
$ffc_dm_to     = (string) ( $args['target_to'] ?? '' );
$ffc_dm_rows   = array(
	'sent'            => __( 'Sent', 'ffcertificate' ),
	'opted_out'       => __( 'Opted out', 'ffcertificate' ),
	'no_email'        => __( 'No valid e-mail', 'ffcertificate' ),
	'out_of_audience' => __( 'Outside the audience', 'ffcertificate' ),
	'failed'          => __( 'Failed', 'ffcertificate' ),
);
$ffc_dm_names  = $args['names'] ?? null;
?>
<h2 style="margin: 0 0 20px 0; font-size: 24px; color: #2271b1;"><?php echo esc_html( (string) ( $args['rule_name'] ?? '' ) ); ?></h2>
<p style="margin: 0 0 16px 0;">
	<?php
	echo esc_html(
		sprintf(
			/* translators: 1: "daily run" or "manual send", 2: date range */
			__( 'Summary of the %1$s for the dates %2$s.', 'ffcertificate' ),
			'manual' === ( $args['trigger'] ?? '' ) ? __( 'manual send', 'ffcertificate' ) : __( 'daily run', 'ffcertificate' ),
			DateFormatter::format_wallclock_date( $ffc_dm_from ) . ( $ffc_dm_to !== $ffc_dm_from ? ' – ' . DateFormatter::format_wallclock_date( $ffc_dm_to ) : '' )
		)
	);
	?>
</p>
<table role="presentation" cellpadding="6" cellspacing="0" style="border-collapse: collapse; margin: 0 0 16px 0;">
	<?php foreach ( $ffc_dm_rows as $ffc_dm_key => $ffc_dm_label ) : ?>
		<tr>
			<td style="border-bottom: 1px solid #dcdcde;"><?php echo esc_html( $ffc_dm_label ); ?></td>
			<td style="border-bottom: 1px solid #dcdcde; text-align: right;"><strong><?php echo esc_html( number_format_i18n( (int) ( $ffc_dm_counts[ $ffc_dm_key ] ?? 0 ) ) ); ?></strong></td>
		</tr>
	<?php endforeach; ?>
</table>
<p style="margin: 0 0 16px 0; font-size: 12px; color: #646970;"><?php esc_html_e( '"Sent" counts messages handed to wp_mail(); with a mail queue active, delivery happens afterwards from the queue.', 'ffcertificate' ); ?></p>
<?php if ( is_array( $ffc_dm_names ) && array() !== $ffc_dm_names ) : ?>
	<h3 style="margin: 0 0 8px 0; font-size: 16px;"><?php esc_html_e( 'Received the message', 'ffcertificate' ); ?></h3>
	<ul style="margin: 0 0 16px 20px; padding: 0;">
		<?php foreach ( $ffc_dm_names as $ffc_dm_name ) : ?>
			<li><?php echo esc_html( (string) $ffc_dm_name ); ?></li>
		<?php endforeach; ?>
	</ul>
	<?php if ( count( $ffc_dm_names ) >= (int) ( $args['names_limit'] ?? 0 ) ) : ?>
		<p style="margin: 0; font-size: 12px; color: #646970;"><?php esc_html_e( 'Only the first names are listed; the counts above include everyone.', 'ffcertificate' ); ?></p>
	<?php endif; ?>
<?php endif; ?>
