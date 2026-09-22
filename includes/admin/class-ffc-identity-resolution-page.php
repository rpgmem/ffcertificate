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
 * @since 6.28.2
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Maintenance\IdentityConflictQuery;
use FreeFormCertificate\Maintenance\IdentityRepair;
use WP_Error;

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
	 * The `admin_post` action that writes a correction.
	 */
	public const REPAIR_ACTION = 'ffc_repair_identity';

	/**
	 * Nonce action. Keyed per finding, so a nonce lifted from one row cannot
	 * confirm a correction on another.
	 */
	public const REPAIR_NONCE = 'ffc_repair_identity_';

	/**
	 * Transient prefix carrying one outcome from the write back to the screen.
	 *
	 * A transient and NOT a query argument, although the audit card next door
	 * passes its message as one: prose in a URL is a message anybody can
	 * choose to show an administrator. Scoped per user and short-lived, so two
	 * operators never read each other's result.
	 */
	public const OUTCOME_TRANSIENT = 'ffc_identity_outcome_';

	/**
	 * How long that outcome survives — one redirect, with slack.
	 */
	public const OUTCOME_TTL = 60;

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
		add_action( 'admin_post_' . self::REPAIR_ACTION, array( $this, 'handle_repair' ) );
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
	 * What the last scan read, for the empty state.
	 *
	 * @var array{stores: int, examined: int, unreadable: int}
	 */
	private array $coverage = array(
		'stores'     => 0,
		'examined'   => 0,
		'unreadable' => 0,
	);

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
		$query = $this->conflicts();
		$out   = $query->rf_check_digit_failures( self::LIMIT );

		// Read AFTER the scan, from the same instance: what the list does not
		// carry is whether it is empty because the data is fine.
		$this->coverage = $query->rf_scan_coverage();

		return $out;
	}

	/**
	 * What the last `queue()` call actually read.
	 *
	 * @return array{stores: int, examined: int, unreadable: int}
	 */
	public function coverage(): array {
		return $this->coverage;
	}

	/**
	 * The repair, as a seam a test can replace.
	 *
	 * @return IdentityRepair
	 */
	protected function repairs(): IdentityRepair {
		return new IdentityRepair();
	}

	/**
	 * Write one confirmed correction.
	 *
	 * What this posts is the SUBJECT HASH and the value, never the row ids the
	 * screen rendered. Between listing a finding and confirming it the
	 * operator went and asked a person, so the rows are resolved again at
	 * write time -- see `IdentityRepair::repair()`.
	 *
	 * @return void
	 */
	public function handle_repair(): void {
		if ( ! Capabilities::current_user_can_admin_or( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		$subject = RequestInput::get_post_string( 'ffc_subject', '' );

		check_admin_referer( self::REPAIR_NONCE . $subject );

		$result = $this->repairs()->repair(
			$subject,
			RequestInput::get_post_string( 'ffc_rf', '' ),
			get_current_user_id()
		);

		// The SERVICE owns the wording. A second copy on this side is the
		// shape that drifts: the two would disagree the first time one is
		// edited, and nothing would report it.
		set_transient(
			self::OUTCOME_TRANSIENT . get_current_user_id(),
			$result instanceof WP_Error
				? array(
					'type' => 'error',
					'text' => $result->get_error_message(),
				)
				: array(
					'type' => 'success',
					'text' => __( 'Corrected. The rows, the identity index and the account\'s certificate access were updated together.', 'ffcertificate' ),
				),
			self::OUTCOME_TTL
		);

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::MENU_SLUG ),
				admin_url( 'edit.php?post_type=ffc_form' )
			)
		);
		exit;
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

		$ffc_identity_key     = self::OUTCOME_TRANSIENT . get_current_user_id();
		$ffc_identity_outcome = get_transient( $ffc_identity_key );
		delete_transient( $ffc_identity_key );

		$ffc_identity_findings = $this->queue();
		$ffc_identity_coverage = $this->coverage();

		require __DIR__ . '/views/identity-resolution-page.php';
	}
}
