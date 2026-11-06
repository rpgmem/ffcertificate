<?php
/**
 * Template: the retired codes view of the Short URLs screen (#2337).
 *
 * Read only. A retired code is never issued again; the list says which
 * codes, what they pointed to, why and when. Included by
 * short-urls-page.php, whose locals are in scope.
 *
 * @var array<int,array<string,mixed>> $items Current page of retired codes.
 *
 * @package FreeFormCertificate\UrlShortener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_retired_reasons = array(
	'regenerated' => __( 'Replaced by Regenerate', 'ffcertificate' ),
	'deleted'     => __( 'Link deleted', 'ffcertificate' ),
);
?>
<?php
\FreeFormCertificate\Core\AdminNotice::render(
	'info',
	esc_html__( 'These codes are never issued again, so a printed link or QR code carrying one of them can never start pointing somewhere else.', 'ffcertificate' )
);
?>
<table class="wp-list-table widefat fixed striped ffc-shorturl-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Code', 'ffcertificate' ); ?></th>
			<th><?php esc_html_e( 'Pointed to', 'ffcertificate' ); ?></th>
			<th><?php esc_html_e( 'Reason', 'ffcertificate' ); ?></th>
			<th><?php esc_html_e( 'Retired on', 'ffcertificate' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( empty( $items ) ) : ?>
			<tr>
				<td colspan="4"><?php esc_html_e( 'No retired codes found.', 'ffcertificate' ); ?></td>
			</tr>
		<?php else : ?>
			<?php foreach ( $items as $ffc_retired ) : ?>
				<?php
				$ffc_reason_key   = (string) ( $ffc_retired['reason'] ?? '' );
				$ffc_reason_label = $ffc_retired_reasons[ $ffc_reason_key ] ?? $ffc_reason_key;
				$ffc_title        = (string) ( $ffc_retired['title'] ?? '' );
				?>
				<tr>
					<td><code class="ffc-shorturl-retired-code"><?php echo esc_html( (string) $ffc_retired['short_code'] ); ?></code></td>
					<td>
						<?php if ( '' !== $ffc_title ) : ?>
							<strong><?php echo esc_html( $ffc_title ); ?></strong>
						<?php else : ?>
							<span class="ffc-shorturl-dest-note"><?php echo esc_html( '(' . __( 'no title', 'ffcertificate' ) . ')' ); ?></span>
						<?php endif; ?>
						<br><small><?php echo esc_html( \FreeFormCertificate\Core\Utils::truncate( (string) $ffc_retired['target_url'], 60, '...' ) ); ?></small>
					</td>
					<td><span class="ffc-shorturl-reason ffc-shorturl-reason-<?php echo esc_attr( $ffc_reason_key ); ?>"><?php echo esc_html( $ffc_reason_label ); ?></span></td>
					<td><?php echo esc_html( \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $ffc_retired['retired_at'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
	</tbody>
</table>
