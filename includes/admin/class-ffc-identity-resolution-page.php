<?php
/**
 * Identity Resolution page
 *
 * The operator's worklist for stored identifiers that need correcting.
 * Registered under the Certificate menu and gated on `ffc_manage_identities`
 * (#1368), so the queue can be delegated on its own without handing anybody
 * the danger zone.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.29.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Maintenance\IdentityConflictQuery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Identity Resolution admin page.
 *
 * WHY THIS IS NOT THE AUDIT CARD
 *
 * Settings -> Migrations already counts the check-digit failures and lists
 * findings -- but only `the findings that name an account`: its builder drops
 * a row whose `SubmissionLinkAuditor::accounts_named_by()` is empty. A
 * check-digit failure on a recruitment candidacy names no account until
 * promotion, and `IdentityConflictQuery::rf_check_digit_failures()`
 * deliberately scans those rows, because that store holds thousands against
 * the appointment store's one. So the population the scan went out of its way
 * to include is counted on that card and invisible in its table.
 *
 * And `COLUMN_ROW_IDS` -- the only handle an operator has on a finding with no
 * account -- is read by no view at all. This screen reads it.
 *
 * It writes nothing. The repair is the next PR; what this delivers is the
 * queue that repair works through.
 */
class IdentityResolutionPage {

	/**
	 * Menu slug.
	 *
	 * The page-scope class is this with the `ffc-` prefix moved, per the
	 * convention `AdminPageScopeTest` freezes: `ffc-page-identities`.
	 */
	public const MENU_SLUG = 'ffc-identities';

	/**
	 * Parent menu.
	 *
	 * The Certificate menu rather than a Settings tab, and that is the point
	 * of the capability: a Settings tab is unreachable without a settings cap,
	 * so a queue living there could never be worked by somebody holding
	 * `ffc_manage_identities` alone -- which is the delegation #1368 split
	 * that capability out of the danger zone to make possible.
	 */
	public const PARENT = 'edit.php?post_type=ffc_form';

	/**
	 * Capability gating the screen (#1368).
	 */
	public const CAPABILITY = 'ffc_manage_identities';

	/**
	 * How many findings to read.
	 *
	 * Above the audit card's 50 because this is a worklist rather than a
	 * sample: an operator works it to zero, so a cap that hides the tail
	 * makes the screen lie about being finished. The scan reports its own
	 * truncation as a row of its own, which the view renders.
	 */
	public const LIMIT = 100;

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Register the submenu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			self::PARENT,
			__( 'Identity Resolution', 'ffcertificate' ),
			__( 'Identities', 'ffcertificate' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * The cross-store identity questions.
	 *
	 * A seam for the reason `SubmissionLinkAuditor::conflicts()` has one: the
	 * query reaches four tables through the global `$wpdb`, and a test needs
	 * to drive this screen without standing all four up.
	 *
	 * @return IdentityConflictQuery
	 */
	protected function conflicts(): IdentityConflictQuery {
		return new IdentityConflictQuery();
	}

	/**
	 * The worklist.
	 *
	 * Read LIVE rather than from the audit's cached report, deliberately. That
	 * report is produced by a button on the Migrations tab, which this
	 * screen's operator cannot reach -- reading it would make the queue depend
	 * on somebody holding a different capability pressing Run first, which is
	 * exactly the coupling this capability exists to remove.
	 *
	 * What that costs is bounded by DISTINCT hashes rather than by rows:
	 * `Encryption::encrypt()` uses a random IV, so a value submitted four
	 * hundred times has four hundred ciphertexts, one hash, and one
	 * decryption. See `IdentityConflictQuery::rf_check_digit_failures()`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function queue(): array {
		return $this->conflicts()->rf_check_digit_failures( self::LIMIT );
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ffcertificate' ) );
		}

		$ffc_identity_findings = $this->queue();

		require __DIR__ . '/views/identity-resolution-page.php';
	}
}
