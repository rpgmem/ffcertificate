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
 * @since 6.28.2
 *
 * @var array<int, array<string, mixed>> $ffc_identity_findings Findings from the check-digit scan.
 * @var array{type: string, text: string}|false                 $ffc_identity_outcome  Outcome of the last write, if any.
 * @var array{stores: int, examined: int, unreadable: int}       $ffc_identity_coverage What the scan actually read.
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
		<?php
		// AN EMPTY LIST MEANS THREE DIFFERENT THINGS AND ONLY ONE IS GOOD NEWS.
		//
		// The scan returns failures, so it returns none when no store carries
		// the columns it needs, when nothing it read could be decrypted, and
		// when every value is genuinely fine. Reporting the third when it was
		// one of the first two is the `#1071` / `#1094` rule broken on a
		// screen instead of in a guard.
		$ffc_identity_examined   = (int) ( $ffc_identity_coverage['examined'] ?? 0 );
		$ffc_identity_unreadable = (int) ( $ffc_identity_coverage['unreadable'] ?? 0 );
		$ffc_identity_stores     = (int) ( $ffc_identity_coverage['stores'] ?? 0 );
		?>
		<?php if ( 0 === $ffc_identity_stores ) : ?>
			<?php
			wp_admin_notice(
				esc_html__( 'Nothing was scanned: no store on this install carries an RF in a form this check can read, which needs the hash, the ciphertext and a row id on the same table. This is not a clean result.', 'ffcertificate' ),
				array(
					'type'               => 'warning',
					'additional_classes' => array( 'inline' ),
				)
			);
			?>
		<?php elseif ( $ffc_identity_examined > 0 && $ffc_identity_examined === $ffc_identity_unreadable ) : ?>
			<?php
			wp_admin_notice(
				esc_html(
					sprintf(
						/* translators: %s: how many distinct stored values were found. */
						__( 'Found %s stored values and could not read any of them, so nothing was checked. That is what an encryption key which does not match this data looks like — this is not a clean result.', 'ffcertificate' ),
						number_format_i18n( $ffc_identity_examined )
					)
				),
				array(
					'type'               => 'error',
					'additional_classes' => array( 'inline' ),
				)
			);
			?>
		<?php elseif ( 0 === $ffc_identity_examined ) : ?>
			<?php
			// FOUR STATES, NOT THREE. The first pass at this fixed the two
			// obvious unread cases and left THIS one falling into the
			// reassuring branch, which then claimed zero values checked and
			// all of them sound -- vacuous, and it reads as reassurance.
			// (Phrased without quoting that sentence: a test below anchors on
			// it, and prose that repeats a literal is the same trap this
			// repository records for suppression scanners.) Zero examined is
			// not a clean result: it
			// says no row in any scanned store carries both a hash and a
			// ciphertext. On a fresh or test install that is ordinary; on an
			// install with submissions it is a signal.
			wp_admin_notice(
				esc_html(
					sprintf(
						/* translators: %s: how many stores were scanned. */
						__( 'No stored RF was found at all: none of the %s stores scanned holds a row with both a hash and a ciphertext, so there was nothing to check. Ordinary on an install that has not captured an RF yet — worth looking into on one that has.', 'ffcertificate' ),
						number_format_i18n( $ffc_identity_stores )
					)
				),
				array(
					'type'               => 'info',
					'additional_classes' => array( 'inline' ),
				)
			);
			?>
		<?php else : ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: values checked, 2: values that could not be read. */
					esc_html__( 'Nothing to resolve: %1$s stored values checked and every one satisfies its check digit. %2$s could not be read and were not checked.', 'ffcertificate' ),
					esc_html( number_format_i18n( $ffc_identity_examined ) ),
					esc_html( number_format_i18n( $ffc_identity_unreadable ) )
				);
				?>
			</p>
		<?php endif; ?>
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
