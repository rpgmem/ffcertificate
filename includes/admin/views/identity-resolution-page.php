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
use FreeFormCertificate\Maintenance\IdentityQueue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_identity_truncated = false;
$ffc_identity_rows      = array();
$ffc_identity_accountal = array();

foreach ( $ffc_identity_findings as $ffc_identity_finding ) {
	if ( ! empty( $ffc_identity_finding[ IdentityConflictQuery::COLUMN_SCAN_TRUNCATED ] ) ) {
		$ffc_identity_truncated = true;
		continue;
	}

	// The isolated tier keeps the row-level table below, because its finding
	// may name no account at all and `row_ids` is then the only handle on it.
	// Everything else is account-side and shares one table.
	if ( IdentityQueue::TIER_ISOLATED === ( $ffc_identity_finding[ IdentityQueue::COLUMN_TIER ] ?? '' ) ) {
		$ffc_identity_rows[] = $ffc_identity_finding;
		continue;
	}

	$ffc_identity_accountal[] = $ffc_identity_finding;
}

/**
 * One tier's name, as an operator reads it.
 *
 * @param string $tier The tier.
 * @return string
 */
$ffc_identity_tier_label = static function ( $tier ) {
	switch ( $tier ) {
		case IdentityQueue::TIER_MECHANICAL:
			return __( 'One is mistyped', 'ffcertificate' );
		case IdentityQueue::TIER_SHARED:
			return __( 'Two accounts, one number', 'ffcertificate' );
		default:
			return __( 'Needs a decision', 'ffcertificate' );
	}
};

/**
 * What the operator is being told to do about one tier.
 *
 * @param string $tier The tier.
 * @return string
 */
$ffc_identity_tier_note = static function ( $tier ) {
	switch ( $tier ) {
		case IdentityQueue::TIER_MECHANICAL:
			return __( 'The check digits identify which of the two is wrong, and the other one is this account\'s. No value has to be asked for.', 'ffcertificate' );
		case IdentityQueue::TIER_SHARED:
			return __( 'One number is stored against more than one account. That is a merge, not a correction: the decision is which account survives.', 'ffcertificate' );
		default:
			return __( 'The check digits do not single one out — none fails, or more than one does. Confirm with HR which number is this person\'s.', 'ffcertificate' );
	}
};
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
		<?php esc_html_e( 'Accounts and stored numbers that do not agree with each other, sorted by how much of the answer is already known. The check digits are what sort them: they can say a number is wrong, and never what the right one is. Identifiers are shown as a hash prefix — the values are decrypted in memory to be checked and none of them leaves the scan.', 'ffcertificate' ); ?>
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

	<?php if ( array() !== $ffc_identity_accountal ) : ?>
		<h2><?php esc_html_e( 'Accounts to resolve', 'ffcertificate' ); ?></h2>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'What is known', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Accounts', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Identifiers', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Stores', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Resolve it', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $ffc_identity_accountal as $ffc_identity_item ) : ?>
				<?php
				$ffc_identity_tier = (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_TIER ] ?? '' );

				// The two orientations of one finding, and they are mirror
				// images: an account holding several numbers names the account
				// in `subject`, while a number held by several accounts names
				// the NUMBER there and the accounts in `related`. Reading one
				// shape for both is how an account id gets printed as a hash.
				if ( IdentityQueue::TIER_SHARED === $ffc_identity_tier ) {
					$ffc_identity_who   = IdentityConflictQuery::parse_accounts( $ffc_identity_item[ IdentityConflictQuery::COLUMN_RELATED ] ?? '' );
					$ffc_identity_which = array( (string) ( $ffc_identity_item['subject'] ?? '' ) => '' );
				} else {
					$ffc_identity_who   = IdentityConflictQuery::parse_accounts( $ffc_identity_item['subject'] ?? '' );
					$ffc_identity_which = (array) ( $ffc_identity_item[ IdentityQueue::COLUMN_VERDICTS ] ?? array() );
				}
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $ffc_identity_tier_label( $ffc_identity_tier ) ); ?></strong>
						<p class="description"><?php echo esc_html( $ffc_identity_tier_note( $ffc_identity_tier ) ); ?></p>
					</td>
					<td>
						<?php if ( array() === $ffc_identity_who ) : ?>
							<span class="description"><?php esc_html_e( 'No account', 'ffcertificate' ); ?></span>
						<?php else : ?>
							<?php foreach ( $ffc_identity_who as $ffc_identity_account ) : ?>
								<div>
									<a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . rawurlencode( (string) $ffc_identity_account ) ) ); ?>">#<?php echo esc_html( (string) $ffc_identity_account ); ?></a>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</td>
					<td>
						<?php foreach ( $ffc_identity_which as $ffc_identity_hash => $ffc_identity_verdict ) : ?>
							<div>
								<code><?php echo esc_html( substr( (string) $ffc_identity_hash, 0, IdentityQueue::DISPLAY_PREFIX ) ); ?></code>
								<?php if ( IdentityConflictQuery::VERDICT_INVALID === $ffc_identity_verdict ) : ?>
									<span class="description"><?php esc_html_e( '— fails its check digits', 'ffcertificate' ); ?></span>
								<?php elseif ( IdentityConflictQuery::VERDICT_VALID === $ffc_identity_verdict ) : ?>
									<span class="description"><?php esc_html_e( '— well formed', 'ffcertificate' ); ?></span>
								<?php elseif ( IdentityConflictQuery::VERDICT_UNREADABLE === $ffc_identity_verdict ) : ?>
									<span class="description"><?php esc_html_e( '— could not be read, so nothing is known about it', 'ffcertificate' ); ?></span>
								<?php elseif ( IdentityConflictQuery::VERDICT_ABSENT === $ffc_identity_verdict ) : ?>
									<span class="description"><?php esc_html_e( '— not stored anywhere this check can read', 'ffcertificate' ); ?></span>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</td>
					<td><?php echo esc_html( (string) ( $ffc_identity_item[ IdentityConflictQuery::COLUMN_STORES ] ?? '' ) ); ?></td>
					<td>
						<?php if ( IdentityQueue::TIER_MECHANICAL === $ffc_identity_tier ) : ?>
							<?php
							$ffc_identity_wrong = (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_WRONG ] ?? '' );
							$ffc_identity_right = (string) ( $ffc_identity_item[ IdentityQueue::COLUMN_RIGHT ] ?? '' );
							?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( IdentityResolutionPage::CONSOLIDATE_NONCE . $ffc_identity_wrong ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::CONSOLIDATE_ACTION ); ?>">
								<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( $ffc_identity_wrong ); ?>">
								<?php
								// TWO HASHES AND NO VALUE.
								//
								// The number to write is the account's sound
								// identifier, which the service reads in
								// memory. Posting the value instead would put
								// a stored RF or CPF through the browser for
								// no reason -- and there is nothing here an
								// operator needs to read, which is what makes
								// this one click rather than a question.
								?>
								<input type="hidden" name="ffc_target" value="<?php echo esc_attr( $ffc_identity_right ); ?>">
								<input type="hidden" name="ffc_field" value="<?php echo esc_attr( str_replace( '_hash', '', (string) ( $ffc_identity_item['identifier_column'] ?? '' ) ) ); ?>">
								<button type="submit" class="button button-secondary">
									<?php esc_html_e( 'Consolidate', 'ffcertificate' ); ?>
								</button>
							</form>
						<?php elseif ( IdentityQueue::TIER_DECISION === $ffc_identity_tier ) : ?>
							<?php
							$ffc_identity_field = str_replace( '_hash', '', (string) ( $ffc_identity_item['identifier_column'] ?? '' ) );
							?>
							<?php foreach ( array_keys( $ffc_identity_which ) as $ffc_identity_move ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ffc-set-mb-2xs">
									<?php wp_nonce_field( IdentityResolutionPage::RELINK_NONCE . (string) $ffc_identity_move ); ?>
									<input type="hidden" name="action" value="<?php echo esc_attr( IdentityResolutionPage::RELINK_ACTION ); ?>">
									<input type="hidden" name="ffc_subject" value="<?php echo esc_attr( (string) $ffc_identity_move ); ?>">
									<input type="hidden" name="ffc_field" value="<?php echo esc_attr( $ffc_identity_field ); ?>">
									<label class="screen-reader-text" for="ffc-relink-<?php echo esc_attr( (string) $ffc_identity_move ); ?>">
										<?php
										printf(
											/* translators: %s: the identifier's hash prefix. */
											esc_html__( 'Account to move the records carrying %s to', 'ffcertificate' ),
											esc_html( substr( (string) $ffc_identity_move, 0, IdentityQueue::DISPLAY_PREFIX ) )
										);
										?>
									</label>
									<?php
									// A numeric account id, typed — the operator
									// arrives from the audit export, which names
									// accounts by id and links to `user-edit.php`.
									//
									// `required` because each row carries its OWN
									// form: an empty field cannot mean "leave this
									// alone" when submitting is already the way to
									// act on one row, and a cleared number field
									// posts the empty string that `absint()` reads
									// as zero (#1114).
									?>
									<input type="number" inputmode="numeric" min="1" step="1" size="6" required
										id="ffc-relink-<?php echo esc_attr( (string) $ffc_identity_move ); ?>"
										name="ffc_account" placeholder="<?php esc_attr_e( 'Account #', 'ffcertificate' ); ?>">
									<button type="submit" class="button button-secondary">
										<?php
										printf(
											/* translators: %s: the identifier's hash prefix. */
											esc_html__( 'Move %s', 'ffcertificate' ),
											esc_html( substr( (string) $ffc_identity_move, 0, IdentityQueue::DISPLAY_PREFIX ) )
										);
										?>
									</button>
								</form>
							<?php endforeach; ?>
						<?php else : ?>
							<span class="description">
								<?php esc_html_e( 'Open the account — this one is not decided here.', 'ffcertificate' ); ?>
							</span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'Consolidating writes the account\'s sound identifier over the mistyped one across every store that holds it. Moving sends the records carrying one identifier to another account — allowed only where the two already agree on the other identifier, and where the receiving account holds none of that kind it gains this one. Both run as a single transaction, rolled back whole if any part refuses, and neither shows a stored number.', 'ffcertificate' ); ?>
		</p>
	<?php endif; ?>

	<?php if ( array() !== $ffc_identity_rows ) : ?>
		<h2><?php esc_html_e( 'Numbers to correct', 'ffcertificate' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'A stored RF whose own check digit does not match, and which no account-side finding above explains: somebody mistyped once on their only row, or the row belongs to a candidacy that carries no account until promotion. The correct value comes from HR.', 'ffcertificate' ); ?>
		</p>
	<?php endif; ?>

	<?php if ( array() === $ffc_identity_rows && array() === $ffc_identity_accountal ) : ?>
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
	<?php elseif ( array() !== $ffc_identity_rows ) : ?>
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
