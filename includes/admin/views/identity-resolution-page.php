<?php
/**
 * Identity Resolution page markup.
 *
 * Rendered from `IdentityResolutionPage::render_page()`, which owns the
 * capability gate and supplies `$ffc_identity_findings`. Markup only -- the
 * same carve-out `phpstan.neon.dist` and `phpunit.xml.dist` make for this
 * directory.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.29.0
 *
 * @var array<int, array<string, mixed>> $ffc_identity_findings Findings from the check-digit scan.
 * @var array{type: string, text: string}|false                 $ffc_identity_outcome  Outcome of the last write, if any.
 */

// No `declare(strict_types=1)` here on purpose: none of the 17 view and
// template files in this repository carries one. A view is `require`d into a
// caller's scope, and the directive is per-file, so one view under a
// different calling convention from every sibling is a trap for anybody
// moving markup between them.

use FreeFormCertificate\Admin\IdentityResolutionPage;
use FreeFormCertificate\Maintenance\IdentityConflictQuery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_identity_truncated = false;
$ffc_identity_rows      = array();

foreach ( $ffc_identity_findings as $ffc_identity_finding ) {
	if ( ! empty( $ffc_identity_finding[ IdentityConflictQuery::COLUMN_SCAN_TRUNCATED ] ) ) {
		$ffc_identity_truncated = true;
		continue;
	}

	$ffc_identity_rows[] = $ffc_identity_finding;
}
?>
<div class="wrap ffc-admin-page ffc-page-identities">
	<h1><?php esc_html_e( 'Identity Resolution', 'ffcertificate' ); ?></h1>

	<?php if ( is_array( $ffc_identity_outcome ) && ! empty( $ffc_identity_outcome['text'] ) ) : ?>
		<?php
		wp_admin_notice(
			esc_html( (string) $ffc_identity_outcome['text'] ),
			array(
				'type'               => (string) ( $ffc_identity_outcome['type'] ?? 'info' ),
				'additional_classes' => array( 'inline' ),
			)
		);
		?>
	<?php endif; ?>

	<p class="description">
		<?php esc_html_e( 'Stored RF numbers whose own check digit does not match, so the value cannot be anybody\'s: it was mistyped on the way in. The digit says a number is wrong, never what the right one is — each row is a question for HR, answered one at a time. Grouping is by the stored hash; the digit is checked in memory and only a verdict leaves the scan, never a value.', 'ffcertificate' ); ?>
	</p>

	<?php if ( $ffc_identity_truncated ) : ?>
		<?php
		wp_admin_notice(
			esc_html__( 'The scan reached its cap before reading every stored value, so this list is partial. Work it down and run the screen again.', 'ffcertificate' ),
			array(
				'type'               => 'warning',
				'additional_classes' => array( 'inline' ),
			)
		);
		?>
	<?php endif; ?>

	<?php if ( array() === $ffc_identity_rows ) : ?>
		<p class="description">
			<?php esc_html_e( 'Nothing to resolve: every stored RF satisfies its check digit.', 'ffcertificate' ); ?>
		</p>
	<?php else : ?>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Accounts', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Stores', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Rows', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Which rows', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Correct it', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $ffc_identity_rows as $ffc_identity_row ) : ?>
				<?php
				$ffc_identity_accounts = IdentityConflictQuery::parse_accounts(
					$ffc_identity_row[ IdentityConflictQuery::COLUMN_RELATED ] ?? ''
				);
				$ffc_identity_by_store = IdentityConflictQuery::parse_row_ids(
					$ffc_identity_row[ IdentityConflictQuery::COLUMN_ROW_IDS ] ?? ''
				);
				?>
				<tr>
					<td>
						<?php if ( array() === $ffc_identity_accounts ) : ?>
							<span class="description">
								<?php esc_html_e( 'No account', 'ffcertificate' ); ?>
							</span>
						<?php else : ?>
							<?php foreach ( $ffc_identity_accounts as $ffc_identity_account ) : ?>
								<a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . rawurlencode( (string) $ffc_identity_account ) ) ); ?>">#<?php echo esc_html( (string) $ffc_identity_account ); ?></a>
							<?php endforeach; ?>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( (string) ( $ffc_identity_row[ IdentityConflictQuery::COLUMN_STORES ] ?? '' ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (int) ( $ffc_identity_row[ IdentityConflictQuery::ALIAS_ROW_COUNT ] ?? 0 ) ) ); ?></td>
					<td>
						<?php if ( ! empty( $ffc_identity_row[ IdentityConflictQuery::COLUMN_ROW_IDS_TRUNCATED ] ) ) : ?>
							<span class="description">
								<?php esc_html_e( 'Too many rows to list.', 'ffcertificate' ); ?>
							</span>
						<?php endif; ?>
						<?php foreach ( $ffc_identity_by_store as $ffc_identity_store => $ffc_identity_ids ) : ?>
							<div>
								<strong><?php echo esc_html( (string) $ffc_identity_store ); ?></strong>
								<code><?php echo esc_html( implode( ', ', array_map( 'strval', $ffc_identity_ids ) ) ); ?></code>
							</div>
						<?php endforeach; ?>
					</td>
					<td>
						<?php if ( count( $ffc_identity_accounts ) > 1 ) : ?>
							<span class="description">
								<?php esc_html_e( 'Names more than one account — the same wrong number was typed by more than one person, so one corrected value cannot serve it.', 'ffcertificate' ); ?>
							</span>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( IdentityResolutionPage::REPAIR_NONCE . (string) ( $ffc_identity_row['subject'] ?? '' ) ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::REPAIR_ACTION ); ?>">
								<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( (string) ( $ffc_identity_row['subject'] ?? '' ) ); ?>">
								<?php
								// `text` with `inputmode`, never `number`: an RF is a
								// fixed-width identifier, and a number input drops a
								// leading zero -- which `rf_normalized varchar(7)` says
								// is a digit, not formatting. That is also why the
								// screen is outside `RequiredNumericInputTest`'s scope.
								?>
								<label class="screen-reader-text" for="ffc-rf-<?php echo esc_attr( (string) ( $ffc_identity_row['subject'] ?? '' ) ); ?>">
									<?php esc_html_e( 'Corrected RF', 'ffcertificate' ); ?>
								</label>
								<input type="text" inputmode="numeric" pattern="[0-9]{7}" maxlength="7" size="8" required
									id="ffc-rf-<?php echo esc_attr( (string) ( $ffc_identity_row['subject'] ?? '' ) ); ?>"
									name="ffc_rf" placeholder="<?php esc_attr_e( '7 digits', 'ffcertificate' ); ?>">
								<button type="submit" class="button button-secondary">
									<?php esc_html_e( 'Correct', 'ffcertificate' ); ?>
								</button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<p class="description ffc-set-mt-10">
		<?php esc_html_e( 'A row naming no account is ordinary rather than an error: a recruitment candidacy carries no WordPress user until it is promoted, so its row ids are the only handle on it. Confirm the corrected number with HR before entering it: the check digit says a number is wrong, never what the right one is.', 'ffcertificate' ); ?>
	</p>
</div>
