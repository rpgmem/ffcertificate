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
use FreeFormCertificate\Maintenance\IdentityQueue;
use FreeFormCertificate\Maintenance\IdentityRelink;
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
	 * The `admin_post` action that consolidates a mechanical finding.
	 *
	 * Its own action rather than a mode on the repair, because the two take
	 * different input and make different promises: a repair carries a value
	 * somebody typed, a consolidation carries two hashes and NO value at all.
	 * One handler taking either would have to branch on which field arrived,
	 * and the branch that mistakes one for the other writes the wrong number.
	 *
	 * @since 6.28.3
	 */
	public const CONSOLIDATE_ACTION = 'ffc_consolidate_identity';

	/**
	 * Nonce action for a consolidation, keyed per finding.
	 *
	 * @since 6.28.3
	 */
	public const CONSOLIDATE_NONCE = 'ffc_consolidate_identity_';

	/**
	 * The `admin_post` action that moves records to another account.
	 *
	 * @since 6.28.3
	 */
	public const RELINK_ACTION = 'ffc_relink_identity';

	/**
	 * Nonce action for a relink, keyed per identifier.
	 *
	 * @since 6.28.3
	 */
	public const RELINK_NONCE = 'ffc_relink_identity_';

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
		add_action( 'admin_post_' . self::CONSOLIDATE_ACTION, array( $this, 'handle_consolidate' ) );
		add_action( 'admin_post_' . self::RELINK_ACTION, array( $this, 'handle_relink' ) );
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
		$queue = $this->queues();
		$out   = $queue->items( self::LIMIT );

		// Read AFTER the scan, from the same instance: what the list does not
		// carry is whether it is empty because the data is fine.
		$this->coverage = $queue->coverage();

		return $out;
	}

	/**
	 * The tiering, as a seam a test can replace.
	 *
	 * It is built around THIS screen's `conflicts()` rather than around its
	 * own, so the one seam a test already drives still drives everything --
	 * two seams answering the same question is how a test starts proving
	 * something about a double nothing under test uses.
	 *
	 * @since 6.28.3
	 * @return IdentityQueue
	 */
	protected function queues(): IdentityQueue {
		$query = $this->conflicts();

		return new class( $query ) extends IdentityQueue {

			/**
			 * The screen's query.
			 *
			 * @var IdentityConflictQuery
			 */
			private IdentityConflictQuery $query;

			/**
			 * Take the screen's query rather than build one.
			 *
			 * @param IdentityConflictQuery $query The screen's own query.
			 */
			public function __construct( IdentityConflictQuery $query ) {
				$this->query = $query;
			}

			/**
			 * The query this tiering reads through.
			 *
			 * @return IdentityConflictQuery
			 */
			protected function conflicts(): IdentityConflictQuery {
				return $this->query;
			}
		};
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
			get_current_user_id(),
			self::posted_field()
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
	 * Consolidate a mechanical finding into the account's sound identifier.
	 *
	 * WHAT THIS POSTS IS TWO HASHES AND NO VALUE.
	 *
	 * The correct number is the account's other identifier, which the service
	 * reads in memory -- so it never reaches a form field, a POST body, a URL
	 * or the operator's screen. That is the whole reason the mechanical tier
	 * can be one click: there is nothing to type, because there is nothing an
	 * operator needs to know.
	 *
	 * @since 6.28.3
	 * @return void
	 */
	public function handle_consolidate(): void {
		if ( ! Capabilities::current_user_can_admin_or( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		$wrong = RequestInput::get_post_string( 'ffc_subject', '' );

		check_admin_referer( self::CONSOLIDATE_NONCE . $wrong );

		$result = $this->repairs()->consolidate(
			$wrong,
			RequestInput::get_post_string( 'ffc_target', '' ),
			get_current_user_id(),
			self::posted_field()
		);

		$this->report(
			$result,
			__( 'Consolidated into the identifier this account already held. The rows, the identity index and the account\'s certificate access were updated together.', 'ffcertificate' )
		);
	}

	/**
	 * Move the records carrying one identifier to another account.
	 *
	 * The account is typed as a numeric id rather than picked from a list, and
	 * that is deliberate: the operator arrives here from the audit export,
	 * which names accounts by id and links straight to `user-edit.php`. A
	 * picker would be a second way to say the same thing, and a wrong pick is
	 * exactly as damaging as a wrong id.
	 *
	 * @since 6.28.3
	 * @return void
	 */
	public function handle_relink(): void {
		if ( ! Capabilities::current_user_can_admin_or( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		$subject = RequestInput::get_post_string( 'ffc_subject', '' );

		check_admin_referer( self::RELINK_NONCE . $subject );

		$result = $this->movements()->relink(
			$subject,
			(int) RequestInput::get_post_string( 'ffc_account', '0' ),
			get_current_user_id(),
			self::posted_field()
		);

		$this->report(
			$result,
			__( 'Moved. The records, the identity index and the receiving account\'s certificate access were updated together.', 'ffcertificate' )
		);
	}

	/**
	 * The relink, as a seam a test can replace.
	 *
	 * @since 6.28.3
	 * @return IdentityRelink
	 */
	protected function movements(): IdentityRelink {
		return new IdentityRelink();
	}

	/**
	 * Which identifier the form named, refusing anything else.
	 *
	 * An unknown value falls back to the default rather than reaching the
	 * service, which refuses it anyway -- two refusals for one mistake, and
	 * the inner one is the load-bearing half.
	 *
	 * @since 6.28.3
	 * @return string
	 */
	private static function posted_field(): string {
		$field = RequestInput::get_post_string( 'ffc_field', IdentityRepair::FIELD );

		return in_array( $field, IdentityRepair::FIELDS, true ) ? $field : IdentityRepair::FIELD;
	}

	/**
	 * Carry one outcome back to the screen and return to it.
	 *
	 * The SERVICE owns the failure wording, for the reason `handle_repair()`
	 * records: a second copy on this side drifts the first time one is edited
	 * and nothing reports it.
	 *
	 * @since 6.28.3
	 * @param array<string, mixed>|WP_Error $result  What the write returned.
	 * @param string                        $success What to say when it worked.
	 * @return void
	 */
	private function report( $result, string $success ): void {
		set_transient(
			self::OUTCOME_TRANSIENT . get_current_user_id(),
			$result instanceof WP_Error
				? array(
					'type' => 'error',
					'text' => $result->get_error_message(),
				)
				: array(
					'type' => 'success',
					'text' => $success,
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
