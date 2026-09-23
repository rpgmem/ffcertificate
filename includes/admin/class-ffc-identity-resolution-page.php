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
use FreeFormCertificate\Maintenance\IdentityMerge;
use FreeFormCertificate\Maintenance\IdentityQueue;
use FreeFormCertificate\Maintenance\IdentityRecordNames;
use FreeFormCertificate\Maintenance\IdentityRelink;
use FreeFormCertificate\Maintenance\IdentitySplit;
use FreeFormCertificate\Maintenance\IdentityRepair;
use FreeFormCertificate\Maintenance\IdentityWorklist;
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
	 * Capability gating a split (#1397).
	 *
	 * Its own, because a split CREATES a WordPress account -- a different
	 * power from correcting a number the check digits already judged, and a
	 * different one again from a merge. Whoever fixes typos is not
	 * necessarily whoever opens logins.
	 *
	 * @since 6.28.4
	 * @var string
	 */
	public const SPLIT_CAPABILITY = 'ffc_split_identities';

	/**
	 * Capability gating a merge (#1397).
	 *
	 * The only action on this screen no other undoes: afterwards nothing can
	 * say which record came from which login.
	 *
	 * @since 6.28.4
	 * @var string
	 */
	public const MERGE_CAPABILITY = 'ffc_merge_identities';

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
	 * The `admin_post` action that gives records an account of their own.
	 *
	 * @since 6.28.3
	 */
	public const SPLIT_ACTION = 'ffc_split_identity';

	/**
	 * Nonce action for a split, keyed per identifier.
	 *
	 * @since 6.28.3
	 */
	public const SPLIT_NONCE = 'ffc_split_identity_';

	/**
	 * The `admin_post` action that consolidates confirmed account pairs.
	 *
	 * @since 6.28.3
	 */
	public const MERGE_ACTION = 'ffc_merge_identity_pair';

	/**
	 * The `admin_post` action that takes the worklist again.
	 *
	 * A WRITE-SHAPED ACTION FOR A READ, DELIBERATELY.
	 *
	 * Re-scanning changes nothing in the database, but it is an action with a
	 * cost -- every distinct stored identifier is decrypted -- and it replaces
	 * what the operator is working from. Both are reasons to make it a posted,
	 * nonce-checked intent rather than a link anything could prefetch.
	 *
	 * @var string
	 */
	public const RESCAN_ACTION = 'ffc_rescan_identities';

	/**
	 * Nonce action for taking the worklist again.
	 *
	 * @var string
	 */
	public const RESCAN_NONCE = 'ffc_rescan_identities';

	/**
	 * Nonce action for the merge form.
	 *
	 * ONE NONCE FOR THE FORM, NOT ONE PER PAIR, BECAUSE THE FORM IS THE UNIT.
	 *
	 * The other verbs key their nonce per finding, so a nonce lifted from one
	 * row cannot confirm another. A merge is confirmed pair by pair with a
	 * checkbox, in a single submission, so the thing being authorised is the
	 * whole ticked set — and every pair in it is re-read and re-judged on the
	 * server anyway.
	 *
	 * @since 6.28.3
	 */
	public const MERGE_NONCE = 'ffc_merge_identity_pair';

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
		add_action( 'admin_post_' . self::SPLIT_ACTION, array( $this, 'handle_split' ) );
		add_action( 'admin_post_' . self::MERGE_ACTION, array( $this, 'handle_merge' ) );
		add_action( 'admin_post_' . self::RESCAN_ACTION, array( $this, 'handle_rescan' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Load the account-search dialog, on this screen and nowhere else.
	 *
	 * @since 6.28.4
	 * @param string $hook The screen's hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_script(
			'ffc-identity-search',
			FFC_PLUGIN_URL . 'assets/js/ffc-identity-search.js',
			array( 'jquery' ),
			FFC_VERSION,
			true
		);

		wp_localize_script(
			'ffc-identity-search',
			'ffcIdentitySearch',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => IdentitySearchAjaxEndpoint::AJAX_ACTION,
				'nonce'   => wp_create_nonce( IdentitySearchAjaxEndpoint::AJAX_ACTION ),
				// ONLY THE STRINGS THAT CARRY A RUNTIME VALUE.
				//
				// Every fixed sentence in the dialog is printed by the view,
				// escaped there and visible to the translation guards as an
				// ordinary source string. What is left here is the handful
				// that interpolate a count, a name or an account number.
				'strings' => array(
					/* translators: 1: how many records move. 2: the identifier's hash prefix. 3: RF or CPF. */
					'subtitle'  => __( 'Moves the %1$s records carrying %2$s · %3$s', 'ffcertificate' ),
					'searching' => __( 'Searching…', 'ffcertificate' ),
					/* translators: %s: how many accounts matched. */
					'found'     => __( '%s accounts found', 'ffcertificate' ),
					'noResults' => __( 'No account matches that.', 'ffcertificate' ),
					/* translators: 1: the account's display name. 2: the account number. */
					'chosen'    => __( 'Destination: %1$s (#%2$s)', 'ffcertificate' ),
					/* translators: %s: the account number. */
					'move'      => __( 'Move to #%s', 'ffcertificate' ),
					'failed'    => __( 'The search could not be completed.', 'ffcertificate' ),
				),
			)
		);

		// A SECOND SCRIPT AND NOT A SECOND CONCERN IN THE FIRST.
		//
		// Both ask the server before the operator commits, but one chooses a
		// destination and the other judges a typed number, and the file named
		// `search` doing the second would be a name that stops describing its
		// contents. All of its fixed prose is `data-` attributes in the view,
		// so it localises no strings at all.
		wp_enqueue_script(
			'ffc-identity-preflight',
			FFC_PLUGIN_URL . 'assets/js/ffc-identity-preflight.js',
			array( 'jquery' ),
			FFC_VERSION,
			true
		);

		wp_localize_script(
			'ffc-identity-preflight',
			'ffcIdentityPreflight',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => IdentityPreflightAjaxEndpoint::AJAX_ACTION,
				'nonce'   => wp_create_nonce( IdentityPreflightAjaxEndpoint::AJAX_ACTION ),
			)
		);
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
		$held = $this->worklists()->get( get_current_user_id(), self::LIMIT );

		// Read from the SAME structure the items came from: what the list
		// does not carry is whether it is empty because the data is fine, and
		// whether it is complete at all.
		$this->coverage  = $held[ IdentityWorklist::COVERAGE ];
		$this->truncated = $held[ IdentityWorklist::TRUNCATED ];
		$this->taken_at  = $held[ IdentityWorklist::TAKEN_AT ];

		return $held[ IdentityWorklist::ITEMS ];
	}

	/**
	 * Which checks reached their cap on the scan behind the current list.
	 *
	 * Empty is the only state in which a count printed beside this queue is a
	 * count. `IdentityAuditExportSource` has said this about its own cap since
	 * 6.27.0, one row over, by emitting a note row; this screen said nothing,
	 * so a check holding more findings than `LIMIT` looked exactly like one
	 * that had returned everything.
	 *
	 * @since 6.28.4
	 * @return array<int, string>
	 */
	public function truncated(): array {
		return $this->truncated;
	}

	/**
	 * When the list being worked was taken, as a unix timestamp.
	 *
	 * @since 6.28.4
	 * @return int
	 */
	public function taken_at(): int {
		return $this->taken_at;
	}

	/**
	 * Checks capped on the scan behind the current list.
	 *
	 * @since 6.28.4
	 * @var array<int, string>
	 */
	private array $truncated = array();

	/**
	 * When the current list was taken.
	 *
	 * @since 6.28.4
	 * @var int
	 */
	private int $taken_at = 0;

	/**
	 * The held worklist, as a seam a test can replace.
	 *
	 * @since 6.28.4
	 * @return IdentityWorklist
	 */
	protected function worklists(): IdentityWorklist {
		// Built around THIS screen's tiering, which is built around its
		// `conflicts()` seam: one chain, so a test driving the query drives
		// everything the screen reads.
		return new IdentityWorklist( $this->queues() );
	}

	/**
	 * Take the worklist again, on the operator's say-so.
	 *
	 * @since 6.28.4
	 * @return void
	 */
	public function handle_rescan(): void {
		if ( ! Capabilities::current_user_can_admin_or( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::RESCAN_NONCE );

		$this->worklists()->take( get_current_user_id(), self::LIMIT );

		$this->report( array(), __( 'The queue was read again.', 'ffcertificate' ) );
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

		// SCOPED WHEN THE SCREEN SAYS WHOSE, AND ONLY THEN.
		//
		// On the shared tier one identifier sits on two logins and one of them
		// typed it wrong, so the correction has to name which. Everywhere else
		// the finding IS the hash and an absent scope means every row carrying
		// it -- which is why this defaults to zero rather than to the current
		// user or to anything derived. The nonce is per finding, not per
		// account, so the account is re-checked by the service: a scope naming
		// an account that holds none of these rows is refused there.
		$result = $this->repairs()->repair(
			$subject,
			RequestInput::get_post_string( 'ffc_rf', '' ),
			get_current_user_id(),
			self::posted_field(),
			absint( RequestInput::get_post_string( 'ffc_account_scope', '0' ) )
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

		// LAND ON THE NEXT FINDING, NOT BACK AT THE TOP.
		//
		// The finding just resolved is the one the worklist drops, so a
		// cursor still naming it resolves to the top of its tier -- correct,
		// and useless: an operator working 96 failures would be sent back to
		// the first one after each. The form knows, at render time, which
		// finding follows, so it posts that key and the redirect carries it.
		// Both are untrusted and neither can do harm: an unknown key lands on
		// the top, which is where this would have landed anyway.
		$args = array( 'page' => self::MENU_SLUG );
		$next = RequestInput::get_post_string( 'ffc_next', '' );
		$tier = RequestInput::get_post_string( 'ffc_tier', '' );

		if ( '' !== $next && in_array( $tier, IdentityQueuePanels::ORDER, true ) ) {
			$args[ IdentityQueuePanels::ARG_AT ] = array( $tier => $next );
		}

		wp_safe_redirect(
			add_query_arg( $args, admin_url( 'edit.php?post_type=ffc_form' ) )
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
	 * Give one identifier's records an account of their own.
	 *
	 * The address is asked for rather than derived, and that is decision 1 of
	 * #1386: WordPress requires it to be unique and every production finding
	 * reports the two identifiers sharing the address the existing account
	 * already uses, so there is no address to inherit.
	 *
	 * @since 6.28.3
	 * @return void
	 */
	public function handle_split(): void {
		if ( ! Capabilities::current_user_can_admin_or( self::SPLIT_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		$subject = RequestInput::get_post_string( 'ffc_subject', '' );

		check_admin_referer( self::SPLIT_NONCE . $subject );

		$result = $this->separations()->split(
			$subject,
			RequestInput::get_post_string( 'ffc_email', '' ),
			get_current_user_id(),
			self::posted_field()
		);

		$this->report(
			$result,
			__( 'Split. A new account was created and those records, the identity index and the account\'s certificate access were updated together.', 'ffcertificate' )
		);
	}

	/**
	 * Consolidate every account pair the operator ticked.
	 *
	 * PAIR BY PAIR, AND THE UNTICKED ONES ARE NOT TOUCHED.
	 *
	 * The screen names each pair -- the people and the document -- and the
	 * operator confirms which ones are one person (#1386, decision 3). A merge
	 * is the one verb no other verb can undo, so it is never applied to a list
	 * wholesale: what is written is exactly what was ticked.
	 *
	 * Each outcome is reported, including the refusals, because a partial
	 * result silently presented as a whole one is how an operator comes to
	 * believe a queue is empty.
	 *
	 * @since 6.28.3
	 * @return void
	 */
	public function handle_merge(): void {
		if ( ! Capabilities::current_user_can_admin_or( self::MERGE_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::MERGE_NONCE );

		$merges   = $this->mergers();
		$actor    = get_current_user_id();
		$done     = 0;
		$refusals = array();

		// THE RAW CONTAINER, BECAUSE THIS PAYLOAD IS ONE GROUP PER PAIR.
		//
		// `get_post_array()` runs `sanitize_text_field()` over every element,
		// which returns '' for an array -- so each pair's group arrives as an
		// empty string, `is_array()` refuses it, and the screen reports that
		// nothing was confirmed. Indistinguishable from an operator who ticked
		// nothing, which is how it reached production.
		//
		// Sanitising is not skipped, it MOVES: `absint()` below is the right
		// function for each of the three ids, and `confirm` is read as a
		// presence rather than a value. That is the contract
		// `get_post_raw_array()` states -- the container is centralised, never
		// the sanitising.
		foreach ( RequestInput::get_post_raw_array( 'ffc_pair' ) as $pair ) {
			if ( ! is_array( $pair ) || empty( $pair['confirm'] ) ) {
				continue;
			}

			$keep = isset( $pair['keep'] ) ? absint( $pair['keep'] ) : 0;
			$a    = isset( $pair['a'] ) ? absint( $pair['a'] ) : 0;
			$b    = isset( $pair['b'] ) ? absint( $pair['b'] ) : 0;

			// The survivor must be one of the two the screen offered. A value
			// from anywhere else would merge an account the operator never saw.
			if ( $keep !== $a && $keep !== $b ) {
				$refusals[] = __( 'A pair named an account that was not one of its two.', 'ffcertificate' );
				continue;
			}

			$result = $merges->merge( $keep, $keep === $a ? $b : $a, $actor );

			if ( $result instanceof WP_Error ) {
				$refusals[] = $result->get_error_message();
				continue;
			}

			++$done;
		}

		$this->report_merges( $done, $refusals );
	}

	/**
	 * Say what happened to every pair, refusals included.
	 *
	 * @since 6.28.3
	 * @param int                $done     How many merged.
	 * @param array<int, string> $refusals Why the others did not.
	 * @return void
	 */
	private function report_merges( int $done, array $refusals ): void {
		if ( 0 === $done && array() === $refusals ) {
			$this->report(
				new WP_Error( 'ffc_identity_merge_none', __( 'No pair was confirmed, so nothing was merged.', 'ffcertificate' ) ),
				''
			);

			return;
		}

		$merged = sprintf(
			/* translators: %s: how many account pairs were merged. */
			_n( '%s pair merged.', '%s pairs merged.', $done, 'ffcertificate' ),
			number_format_i18n( $done )
		);

		if ( array() === $refusals ) {
			$this->report(
				array(),
				$merged . ' ' . __( 'The emptied logins were left in place — removing them is yours to do in Users.', 'ffcertificate' )
			);

			return;
		}

		$this->report(
			new WP_Error( 'ffc_identity_merge_partial', $merged . ' ' . implode( ' ', $refusals ) ),
			''
		);
	}

	/**
	 * The merge, as a seam a test can replace.
	 *
	 * @since 6.28.3
	 * @return IdentityMerge
	 */
	protected function mergers(): IdentityMerge {
		return new IdentityMerge();
	}

	/**
	 * The split, as a seam a test can replace.
	 *
	 * @since 6.28.3
	 * @return IdentitySplit
	 */
	protected function separations(): IdentitySplit {
		return new IdentitySplit();
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
		// A WRITE THAT WORKED TAKES ITS FINDING OUT OF THE HELD LIST.
		//
		// One place rather than one per handler: every verb funnels through
		// here, and the rule is the same for all of them -- the finding that
		// was resolved stops being in the queue, and NOTHING ELSE MOVES. The
		// alternative, dropping the list so the next load re-scans, is the
		// per-resolution scan `IdentityWorklist` exists to avoid, and it
		// renumbers every other position at the same time.
		//
		// The key is posted, so it is untrusted -- and it cannot be abused
		// into anything: `resolved()` only ever removes an entry from the
		// caller's OWN held list, and a re-scan brings back anything dropped
		// that was not really resolved.
		if ( ! $result instanceof WP_Error ) {
			$key = RequestInput::get_post_string( 'ffc_key', '' );

			if ( '' !== $key ) {
				$this->worklists()->resolved( get_current_user_id(), $key );
			}
		}

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
	 * Where each panel's cursor is, as the request asks for it.
	 *
	 * A GET READ WITHOUT A NONCE, AND THAT IS RIGHT.
	 *
	 * This moves a cursor and writes nothing. A nonce here would make every
	 * `next` link a one-shot that breaks on the back button, for an argument
	 * whose worst case is showing the operator a finding they can already
	 * see. `IdentityQueuePanels` resolves an unknown key to the top of its
	 * tier, so neither a typo nor a hand-edited URL can name anything else.
	 *
	 * @since 6.28.4
	 * @return array<string, string>
	 */
	private static function cursors(): array {
		$out = array();

		$raw = RequestInput::get_get_raw_array( IdentityQueuePanels::ARG_AT );

		foreach ( IdentityQueuePanels::ORDER as $tier ) {
			if ( isset( $raw[ $tier ] ) && is_scalar( $raw[ $tier ] ) ) {
				$out[ $tier ] = sanitize_text_field( (string) $raw[ $tier ] );
			}
		}

		return $out;
	}

	/**
	 * Which tiers were asked to show their whole list rather than one finding.
	 *
	 * @since 6.28.4
	 * @return array<int, string>
	 */
	private static function listed(): array {
		$raw = RequestInput::get_get_string( IdentityQueuePanels::ARG_LIST, '' );

		if ( '' === $raw ) {
			return array();
		}

		// An allowlist rather than a filter: only a tier this screen draws can
		// be listed, so nothing the request names reaches the markup.
		return array_values( array_intersect( IdentityQueuePanels::ORDER, explode( ',', $raw ) ) );
	}

	/**
	 * The record-name reader, as a seam a test can stand in for.
	 *
	 * @since 6.28.4
	 * @return IdentityRecordNames
	 */
	protected function namer(): IdentityRecordNames {
		return new IdentityRecordNames();
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

		// `queue()` FIRST: the three readings below are properties of the list
		// it just resolved, and asking for them before it would answer about
		// no list at all.
		$ffc_identity_findings  = $this->queue();
		$ffc_identity_coverage  = $this->coverage();
		$ffc_identity_capped    = $this->truncated();
		$ffc_identity_taken_at  = $this->taken_at();
		$ffc_identity_may_split = Capabilities::current_user_can_admin_or( self::SPLIT_CAPABILITY );
		$ffc_identity_may_merge = Capabilities::current_user_can_admin_or( self::MERGE_CAPABILITY );
		$ffc_identity_panels    = IdentityQueuePanels::build(
			$ffc_identity_findings,
			self::cursors(),
			self::listed()
		);

		// WHO A FINDING IS ABOUT, READ ON DEMAND AND ONLY FOR WHAT IS DRAWN.
		//
		// A closure rather than a precomputed map: the stepper draws ONE
		// finding per tier unless the operator asked for a list, so resolving
		// names for the whole queue would read for a hundred findings to show
		// one. It costs two indexed reads per finding drawn.
		$ffc_identity_names = function ( $hash, $field = 'rf' ) {
			return $this->namer()->for_hash( (string) $hash, (string) $field );
		};

		require __DIR__ . '/views/identity-resolution-page.php';
	}
}
