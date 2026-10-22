<?php
/**
 * Date-messages admin screen.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

use FreeFormCertificate\UserDashboard\UserManager;

use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Migrations\Strategies\BirthDateBackfillMigrationStrategy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The screen an operator configures date messages on (#1538).
 *
 * Four tabs on one page: the rules (list and editor), a manual send over a
 * range, the history of runs, and the daily send time. Every write is an
 * `admin_post` action carrying a nonce and checked against the manage
 * capability; the page itself only needs the view capability, and renders
 * read-only for an operator who holds nothing more.
 *
 * The recipient preview and the test send are AJAX, in
 * `DateMessagesAjaxEndpoint`, because both act on a form that has not been
 * saved yet.
 */
final class DateMessagesAdminPage {

	/**
	 * Menu slug. The page-scope class is `ffc-page-date-messages`.
	 */
	public const MENU_SLUG = 'ffc-date-messages';

	/**
	 * Read-only access: rules, history and recipient totals.
	 */
	public const VIEW_CAP = 'ffc_view_date_messages';

	/**
	 * Create and edit rules, send manually, set the send time.
	 */
	public const MANAGE_CAP = 'ffc_manage_date_messages';

	/**
	 * See who receives: the recipient preview and the upcoming dates, where
	 * names stay masked until revealed (#1863).
	 */
	public const PII_CAP = 'ffc_view_date_messages_pii';

	/**
	 * The `ffc_reveal_pii` record type the upcoming dates' Reveal buttons
	 * name: a person, by account ID (#1863).
	 */
	public const REVEAL_TYPE = 'date_messages_person';

	/**
	 * Send a rule's test to oneself, without managing the rules (#1860).
	 */
	public const TEST_CAP = 'ffc_test_date_messages';

	/**
	 * Capability to send a rule now or schedule an announcement (#1949). Its own
	 * cap: managing rules does not include it.
	 */
	public const SEND_CAP = 'ffc_send_date_messages';

	/**
	 * `admin_post` actions. Each nonce action equals its `admin_post`
	 * action, keyed per rule where the action names one.
	 */
	public const SAVE_ACTION      = 'ffc_date_messages_save_rule';
	public const DELETE_ACTION    = 'ffc_date_messages_delete_rule';
	public const DUPLICATE_ACTION = 'ffc_date_messages_duplicate_rule';
	public const SEND_ACTION      = 'ffc_date_messages_send_now';

	/**
	 * Tabs, in display order.
	 */
	public const TABS = array( 'rules', 'send', 'history', 'upcoming', 'settings' );

	/**
	 * Runs per history page.
	 */
	public const HISTORY_PER_PAGE = 20;

	/**
	 * Transient prefix carrying one outcome from a write back to the screen,
	 * per user, the way `IdentityResolutionPage` does it: prose in a query
	 * argument is a message anybody could make an administrator read.
	 */
	public const OUTCOME_TRANSIENT = 'ffc_date_messages_outcome_';

	/**
	 * How long an outcome survives -- one redirect, with slack.
	 */
	private const OUTCOME_TTL = 60;

	/**
	 * Register the hooks. Called on admin requests, under the module toggle.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( $this, 'handle_delete' ) );
		add_action( 'admin_post_' . self::DUPLICATE_ACTION, array( $this, 'handle_duplicate' ) );
		add_action( 'admin_post_' . self::SEND_ACTION, array( $this, 'handle_send_now' ) );
	}

	/**
	 * Whether the current user may open the screen.
	 *
	 * @return bool
	 */
	public static function can_view(): bool {
		return Capabilities::current_user_can_admin_or( self::VIEW_CAP ) || self::can_manage();
	}

	/**
	 * Whether the current user may change anything on it.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return Capabilities::current_user_can_admin_or( self::MANAGE_CAP );
	}

	/**
	 * Whether the current user may send a rule's test to their own address.
	 *
	 * The operator tier's action (#1860): `ffc_test_date_messages` allows it
	 * without allowing any change, and managing the rules still allows it.
	 * The message and recipient previews stay with {@see self::can_view()},
	 * as they were: they send and record nothing.
	 *
	 * @return bool
	 */
	public static function can_test(): bool {
		return self::can_manage() || Capabilities::current_user_can_admin_or( self::TEST_CAP );
	}

	/**
	 * Whether the current user may send a rule now (#1949).
	 *
	 * @return bool
	 */
	public static function can_send(): bool {
		return Capabilities::current_user_can_admin_or( self::SEND_CAP );
	}

	/**
	 * Whether the current user may see recipients by name.
	 *
	 * @return bool
	 */
	public static function can_view_pii(): bool {
		return Capabilities::current_user_can_admin_or( self::PII_CAP );
	}

	/**
	 * Register the top-level menu.
	 *
	 * WordPress gates a menu on ONE capability, while the screen opens for
	 * view OR manage (the 3-state model: a manage grant does not also need
	 * the view one). So the menu names whichever of the two the user holds.
	 * Administrators hold both.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$cap = ! current_user_can( self::VIEW_CAP ) && current_user_can( self::MANAGE_CAP ) ? self::MANAGE_CAP : self::VIEW_CAP;

		add_menu_page(
			\FreeFormCertificate\Core\PluginAreas::label( 'date_messages' ),
			\FreeFormCertificate\Core\PluginAreas::label( 'date_messages' ),
			$cap,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'none', // Drawn from the icon registry by AdminMenuIcons (#1640).
			// A top-level menu like the other modules', placed after URL
			// Shortener (26.4) to keep the FFC block contiguous.
			26.5
		);
	}

	/**
	 * Assets, on this screen only.
	 *
	 * @param string $hook Screen hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, self::MENU_SLUG ) ) {
			return;
		}

		$suffix = \FreeFormCertificate\Core\AssetHelper::asset_suffix();

		// The vertical tab layout lives in ffc-admin-settings.css, as it does
		// for Recruitment; its other rules are scoped and stay dormant here.
		\FreeFormCertificate\Core\AssetRegistry::enqueue_styles( 'ffc-admin-settings' );

		// The body appearance card (#1660) picks an image from the Media
		// Library and its colours with the picker the Email Model uses.
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );

		wp_enqueue_script(
			'ffc-date-messages-admin',
			FFC_PLUGIN_URL . "assets/js/ffc-date-messages-admin{$suffix}.js",
			array( 'jquery', 'ffc-core', 'wp-color-picker' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-date-messages-admin',
			'ffcDateMessages',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'previewAction' => DateMessagesAjaxEndpoint::PREVIEW_ACTION,
				'previewNonce'  => wp_create_nonce( DateMessagesAjaxEndpoint::PREVIEW_ACTION ),
				'testAction'    => DateMessagesAjaxEndpoint::TEST_ACTION,
				'testNonce'     => wp_create_nonce( DateMessagesAjaxEndpoint::TEST_ACTION ),
				'messageAction' => DateMessagesAjaxEndpoint::MESSAGE_ACTION,
				'messageNonce'  => wp_create_nonce( DateMessagesAjaxEndpoint::MESSAGE_ACTION ),
				'decimal'       => self::decimal_point(),
				'decisions'     => self::decision_labels(),
				'strings'       => array(
					'loading'   => __( 'Working…', 'ffcertificate' ),
					'failed'    => __( 'The request failed. Reload the page and try again.', 'ffcertificate' ),
					'truncated' => __( 'Only the first rows are listed; the totals count everyone.', 'ffcertificate' ),
					'none'      => __( 'Nobody falls on these dates.', 'ffcertificate' ),
					'forced'    => __( 'Forced past the opt-out (included in the total above)', 'ffcertificate' ),
				),
			)
		);

		// The upcoming dates list names masked, with Reveal (#1863).
		if ( self::can_view_pii() ) {
			\FreeFormCertificate\Core\PiiReveal::enqueue_assets();
		}

		// The rule editor's audience picker is the shared component (#1648).
		\FreeFormCertificate\Audience\AudienceTransferList::enqueue();

		// The rule's toggles and appearance save per record (#1757).
		\FreeFormCertificate\Core\RecordAutosave::enqueue();

		// One default per kind; the editor switches between them with the kind
		// selector (ffc-date-messages-admin.js), so both are supplied.
		$defaults     = MessageBuilder::defaults();
		$announcement = MessageBuilder::announcement_defaults();
		$confirm      = __( 'Replace the current message with the default text? Your changes will be lost.', 'ffcertificate' );
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-email-restore-default' );
		wp_localize_script(
			'ffc-email-restore-default',
			'ffcEmailRestoreDefaults',
			array(
				'date_message_body'              => array(
					'body'    => $defaults['body'],
					'confirm' => $confirm,
				),
				'date_message_announcement_body' => array(
					'body'    => $announcement['body'],
					'confirm' => $confirm,
				),
			)
		);
		wp_localize_script(
			'ffc-date-messages-admin',
			'ffcDateMessageDefaults',
			array(
				Rule::KIND_DATED        => $defaults,
				Rule::KIND_ANNOUNCEMENT => $announcement,
			)
		);
	}

	/**
	 * The site's decimal separator, for the contrast ratio the editor shows.
	 *
	 * @return string
	 */
	private static function decimal_point(): string {
		global $wp_locale;
		$point = isset( $wp_locale->number_format['decimal_point'] ) ? (string) $wp_locale->number_format['decimal_point'] : '.';
		return '' !== $point ? $point : '.';
	}

	/**
	 * Labels for the recipient decisions, shared by the screen and the
	 * preview script so the two never word a decision differently.
	 *
	 * @return array<string, string>
	 */
	public static function decision_labels(): array {
		return array(
			RecipientResolver::WILL_SEND       => __( 'Will receive', 'ffcertificate' ),
			RecipientResolver::ALREADY_SENT    => __( 'Already sent', 'ffcertificate' ),
			RecipientResolver::OPTED_OUT       => __( 'Opted out', 'ffcertificate' ),
			RecipientResolver::NO_EMAIL        => __( 'No valid e-mail', 'ffcertificate' ),
			RecipientResolver::OUT_OF_AUDIENCE => __( 'Outside the audience', 'ffcertificate' ),
		);
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! self::can_view() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		$tab = RequestInput::get_get_key( 'tab', 'rules' );
		$tab = in_array( $tab, self::TABS, true ) ? $tab : 'rules';
		if ( 'upcoming' === $tab && ! self::can_view_pii() ) {
			$tab = 'rules';
		}

		$outcome    = $this->take_outcome();
		$can_manage = self::can_manage();
		$can_test   = self::can_test();
		$can_send   = self::can_send();
		$rules      = RuleReader::all();
		$audiences  = self::audience_options();
		$managers   = array();
		$editing    = null;
		$draft      = array();

		if ( 'rules' === $tab && RequestInput::has_get( 'rule' ) ) {
			$rule_id = RequestInput::get_get_int( 'rule' );
			$editing = $rule_id > 0 ? RuleReader::get_by_id( $rule_id ) : null;
			$draft   = is_array( $outcome['draft'] ?? null ) ? $outcome['draft'] : array();
			if ( $rule_id > 0 && null === $editing ) {
				$outcome = array(
					'type'    => 'error',
					'message' => __( 'That rule no longer exists.', 'ffcertificate' ),
				);
				$rule_id = -1;
			}
			$tab = $rule_id < 0 ? 'rules' : 'edit';
			if ( 'edit' === $tab ) {
				$managers = self::manager_options();
			}
		}

		$history = array();
		$total   = 0;
		$paged   = max( 1, RequestInput::get_get_int( 'paged', 1 ) );
		// Test sends are listed unless the operator hides them.
		$show_tests = 'hide' !== RequestInput::get_get_key( 'tests' );
		if ( 'history' === $tab ) {
			$history = DeliveryLog::recent_runs( self::HISTORY_PER_PAGE, ( $paged - 1 ) * self::HISTORY_PER_PAGE, $show_tests );
			$total   = DeliveryLog::count_runs( $show_tests );
		}

		// Upcoming dates narrow by an active rule, then by one of its audiences
		// (#1648). A rule or audience the filter does not offer -- a stale or
		// hand-written URL -- reads as "all".
		$period             = RequestInput::get_get_key( 'period', 'next30' );
		$upcoming_rule      = RequestInput::get_get_int( 'rule_filter' );
		$upcoming_rule      = array() === self::active_rules( $rules, max( 0, $upcoming_rule ) ) || $upcoming_rule <= 0 ? 0 : $upcoming_rule;
		$upcoming_audiences = 'upcoming' === $tab ? self::upcoming_audience_options( $rules, $upcoming_rule, $audiences ) : array();
		$audience_id        = RequestInput::get_get_int( 'audience' );
		$audience_id        = isset( $upcoming_audiences[ $audience_id ] ) ? $audience_id : 0;
		$upcoming           = 'upcoming' === $tab ? self::upcoming( $period, self::upcoming_scope( $rules, $upcoming_rule, $audience_id ), Runner::today() ) : null;
		if ( null !== $upcoming ) {
			$upcoming['rows'] = self::with_audience_names( $upcoming['rows'], $upcoming_audiences );
		}

		$rule_names = array();
		foreach ( $rules as $rule ) {
			$rule_names[ $rule->id ] = $rule->name;
		}

		$send_time   = DateMessagesCron::send_time();
		$next_run    = wp_next_scheduled( DateMessagesCron::CRON_HOOK );
		$queue_ready = class_exists( \FreeFormCertificate\Integrations\MailQueue::class ) && \FreeFormCertificate\Integrations\MailQueue::is_active();
		$today       = Runner::today();

		include FFC_PLUGIN_DIR . 'templates/admin/date-messages/page.php';
	}

	/**
	 * Warn while the birth-date backfill has accounts left to examine.
	 *
	 * A date given before the profile field existed sits in the
	 * reregistration stores, which nothing here reads: until the migration
	 * copies it, that person is absent from every preview, the upcoming
	 * dates and the sends, and an empty preview reads as "nobody has a
	 * birthday" (#1538). The count is the migration card's own, never a
	 * second one, and the link is offered only to who can run migrations.
	 *
	 * @return void
	 */
	public static function render_backfill_notice(): void {
		$pending = BirthDateBackfillMigrationStrategy::pending_accounts();
		if ( $pending <= 0 ) {
			return;
		}

		$message = esc_html(
			sprintf(
				/* translators: %d: number of accounts */
				_n(
					'The birth-date migration has %d account left to examine. Until it runs, a birth date given before the profile field existed is not on the profile, so that person does not appear in previews or upcoming dates and receives no messages.',
					'The birth-date migration has %d accounts left to examine. Until it runs, a birth date given before the profile field existed is not on the profile, so those people do not appear in previews or upcoming dates and receive no messages.',
					$pending,
					'ffcertificate'
				),
				$pending
			)
		);

		if ( Capabilities::current_user_can_admin_or( 'ffc_manage_settings_dangerzone' ) ) {
			$message .= ' <a href="' . esc_url( admin_url( 'admin.php?page=ffc-settings&tab=migrations' ) ) . '">'
				. esc_html__( 'Run it in Settings → Data Migrations.', 'ffcertificate' ) . '</a>';
		}

		\FreeFormCertificate\Core\AdminNotice::render(
			'warning',
			$message
		);
	}

	/**
	 * Save a rule from the editor.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		$this->guard( self::SAVE_ACTION );

		$posted = RequestInput::get_post_raw_array( 'rule' );
		$id     = is_numeric( $posted['id'] ?? null ) ? max( 0, (int) $posted['id'] ) : 0;
		$data   = self::form_data( $posted );

		if ( $id > 0 && null === RuleReader::get_by_id( $id ) ) {
			$this->finish( 'error', __( 'That rule no longer exists.', 'ffcertificate' ) );
		}
		$data['id'] = $id;

		if ( self::send_at_is_unreadable( is_string( $posted['send_at'] ?? null ) ? $posted['send_at'] : '' ) ) {
			$this->finish( 'error', __( 'Enter the send time as a date and time, or leave it empty.', 'ffcertificate' ), array( 'rule' => $id > 0 ? $id : 0 ), $data );
		}

		$rule = Rule::from_array( $data );
		if ( is_wp_error( $rule ) ) {
			$this->finish( 'error', $rule->get_error_message(), array( 'rule' => $id > 0 ? $id : 0 ), $data );
		}

		$previous = $id > 0 ? RuleReader::get_by_id( $id ) : null;
		$saved    = RuleWriter::save( $rule );
		if ( false === $saved ) {
			$this->finish( 'error', __( 'The rule could not be saved.', 'ffcertificate' ), array( 'rule' => $id > 0 ? $id : 0 ), $data );
		}

		$stored = RuleReader::get_by_id( (int) $saved );
		if ( null !== $stored ) {
			self::sync_schedule( $stored, $previous );
		}

		$this->finish( 'success', __( 'Rule saved.', 'ffcertificate' ), array( 'rule' => $saved ) );
	}

	/**
	 * Keep an announcement's one send event in step with the saved rule (#1949).
	 *
	 * Only a change to the instant, the kind or the activation touches the
	 * event, so saving an unchanged rule never reschedules it. An instant that
	 * has already passed queues nothing: an announcement is not sent by saving
	 * it, only by "Send now", so a re-save cannot send it twice.
	 *
	 * @param Rule      $stored   The rule as stored.
	 * @param Rule|null $previous The rule before the save, null for a new one.
	 * @return void
	 */
	public static function sync_schedule( Rule $stored, ?Rule $previous ): void {
		$changed = null === $previous
			|| $previous->send_at !== $stored->send_at
			|| $previous->kind !== $stored->kind
			|| $previous->is_active !== $stored->is_active;
		if ( ! $changed ) {
			return;
		}

		// Scheduling clears the old event itself, so it is cleared here only
		// when nothing replaces it.
		if ( $stored->is_announcement() && $stored->is_active && $stored->send_at > time() ) {
			Runner::schedule_announcement( $stored, get_current_user_id() );
			return;
		}
		wp_clear_scheduled_hook( Runner::ANNOUNCE_HOOK, array( $stored->id ) );
	}

	/**
	 * Delete a rule.
	 *
	 * @return void
	 */
	public function handle_delete(): void {
		$id = RequestInput::get_post_int( 'rule_id' );
		$this->guard( self::DELETE_ACTION . '_' . $id );

		if ( $id <= 0 || ! RuleWriter::delete( $id ) ) {
			$this->finish( 'error', __( 'The rule could not be deleted.', 'ffcertificate' ) );
		}
		$this->finish( 'success', __( 'Rule deleted. Its history stays.', 'ffcertificate' ) );
	}

	/**
	 * Copy a rule, inactive, and open the copy.
	 *
	 * @return void
	 */
	public function handle_duplicate(): void {
		$id = RequestInput::get_post_int( 'rule_id' );
		$this->guard( self::DUPLICATE_ACTION . '_' . $id );

		$rule = $id > 0 ? RuleReader::get_by_id( $id ) : null;
		if ( null === $rule ) {
			$this->finish( 'error', __( 'That rule no longer exists.', 'ffcertificate' ) );
		}

		$data         = $rule->to_columns();
		$data['name'] = sprintf(
			/* translators: %s: name of the rule being copied */
			__( '%s (copy)', 'ffcertificate' ),
			$rule->name
		);
		$data['is_active'] = '0';

		$copy  = Rule::from_array( $data );
		$saved = $copy instanceof Rule ? RuleWriter::save( $copy ) : false;
		if ( false === $saved ) {
			$this->finish( 'error', __( 'The rule could not be copied.', 'ffcertificate' ) );
		}

		$this->finish( 'success', __( 'Rule copied. The copy starts inactive.', 'ffcertificate' ), array( 'rule' => $saved ) );
	}

	/**
	 * Send one rule's messages over a range of target dates, now.
	 *
	 * @return void
	 */
	public function handle_send_now(): void {
		$this->guard_send( self::SEND_ACTION );

		$back = array( 'tab' => 'send' );
		$rule = RuleReader::get_by_id( RequestInput::get_post_int( 'rule_id' ) );
		if ( null === $rule ) {
			$this->finish( 'error', __( 'Choose a rule.', 'ffcertificate' ), $back );
		}

		$from = self::date( RequestInput::get_post_string( 'from' ) );
		$to   = self::date( RequestInput::get_post_string( 'to' ) );
		// An announcement goes out on one day, today: the dates are not asked (#1949).
		if ( $rule->is_announcement() ) {
			$from = Runner::today();
			$to   = $from;
		}
		if ( null === $from || null === $to ) {
			$this->finish( 'error', __( 'Enter both dates.', 'ffcertificate' ), $back );
		}
		$range_error = self::send_range_error( $rule, $from, $to );
		if ( '' !== $range_error ) {
			$this->finish( 'error', $range_error, $back );
		}

		$run = Runner::start( $rule, $from, $to, 'manual', get_current_user_id() );
		if ( is_wp_error( $run ) ) {
			$this->finish( 'error', $run->get_error_message(), $back );
		}

		$this->finish(
			'success',
			__( 'Sending started. The first batch went out now; the rest continues in the background. Follow it in History.', 'ffcertificate' ),
			array( 'tab' => 'history' )
		);
	}

	/**
	 * A posted rule as `Rule::from_array()` input. The body keeps its HTML,
	 * filtered to what a post may contain; everything else is plain text,
	 * which `from_array()` sanitises itself.
	 *
	 * @param array<mixed> $posted The `rule` array from the form.
	 * @return array<string, mixed>
	 */
	public static function form_data( array $posted ): array {
		$body = $posted['body'] ?? '';

		return array(
			'name'            => $posted['name'] ?? '',
			'source'          => $posted['source'] ?? BirthdaySource::ID,
			'offset_days'     => $posted['offset_days'] ?? '',
			'audience_ids'    => is_array( $posted['audience_ids'] ?? null ) ? $posted['audience_ids'] : array(),
			'subject'         => $posted['subject'] ?? '',
			'body'            => is_string( $body ) ? wp_kses_post( $body ) : '',
			'send_to_user'    => isset( $posted['send_to_user'] ) ? '1' : '0',
			'kind'            => $posted['kind'] ?? Rule::KIND_DATED,
			'send_at'         => self::send_at_from_input( is_string( $posted['send_at'] ?? null ) ? $posted['send_at'] : '' ),
			// Unticked means respect: the checkbox is the exception, not the default.
			'respect_opt_out' => isset( $posted['force_opt_out'] ) ? '0' : '1',
			'is_active'       => isset( $posted['is_active'] ) ? '1' : '0',
			'digest_enabled'  => isset( $posted['digest_enabled'] ) ? '1' : '0',
			'digest_mode'     => $posted['digest_mode'] ?? 'summary',
			'digest_user_ids' => is_array( $posted['digest_user_ids'] ?? null ) ? $posted['digest_user_ids'] : array(),
			'appearance'      => is_array( $posted['appearance'] ?? null ) ? $posted['appearance'] : array(),
		);
	}

	/**
	 * Why a manual send's range is refused for this rule, or '' when it is fine.
	 *
	 * An announcement is delivered once per day it is sent, so a range that
	 * spans more than one day would send it to everyone once for each day.
	 *
	 * @param Rule               $rule The rule.
	 * @param \DateTimeImmutable $from First day.
	 * @param \DateTimeImmutable $to   Last day.
	 * @return string
	 */
	public static function send_range_error( Rule $rule, \DateTimeImmutable $from, \DateTimeImmutable $to ): string {
		if ( $rule->is_announcement() && $from->format( 'Y-m-d' ) !== $to->format( 'Y-m-d' ) ) {
			return __( 'An announcement is sent on one day. Give the same first and last day.', 'ffcertificate' );
		}
		return '';
	}

	/**
	 * Whether a send-time value was typed but cannot be read, which would
	 * otherwise be saved as "not scheduled" without the operator knowing.
	 *
	 * @param string $raw The value as posted.
	 * @return bool
	 */
	public static function send_at_is_unreadable( string $raw ): bool {
		return '' !== trim( $raw ) && 0 === self::send_at_from_input( trim( $raw ) );
	}

	/**
	 * The instant a datetime-local value names, in the site timezone, as a
	 * UNIX time. An empty or unreadable value is 0: sent by hand, not scheduled.
	 *
	 * @param string $value `Y-m-d\TH:i` as the browser posts it.
	 * @return int
	 */
	public static function send_at_from_input( string $value ): int {
		$at = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, wp_timezone() );
		return false === $at ? 0 : $at->getTimestamp();
	}

	/**
	 * A stored instant as a datetime-local value in the site timezone.
	 *
	 * @param int $send_at UNIX time, 0 for none.
	 * @return string Empty for none.
	 */
	public static function send_at_for_input( int $send_at ): string {
		return $send_at > 0 ? (string) wp_date( 'Y-m-d\TH:i', $send_at ) : '';
	}

	/**
	 * Accounts that may receive a digest: administrators and holders of a
	 * date-messages capability. A summary about who received a message is
	 * not for an account that could not open this screen.
	 *
	 * @return array<int, string> User id => "Name <masked email>".
	 */
	public static function manager_options(): array {
		$users = get_users(
			array(
				'capability__in' => array( 'manage_options', self::VIEW_CAP, self::MANAGE_CAP, self::PII_CAP ),
				'orderby'        => 'display_name',
				'number'         => 500,
			)
		);

		$options = array();
		foreach ( $users as $user ) {
			if ( $user instanceof \WP_User ) {
				// Staff addresses are personal data too (#1863): the name tells
				// the accounts apart, the masked address confirms which.
				$options[ $user->ID ] = $user->display_name . ' <' . \FreeFormCertificate\Core\DocumentFormatter::mask_email( (string) $user->user_email ) . '>';
			}
		}
		return $options;
	}

	/**
	 * Audiences as select options (id => indented name), or none when the
	 * module has none.
	 *
	 * @return array<int, string>
	 */
	public static function audience_options(): array {
		if ( ! class_exists( \FreeFormCertificate\Audience\AudienceReader::class ) ) {
			return array();
		}

		$options = array();
		$walk    = static function ( array $nodes, int $depth ) use ( &$walk, &$options ): void {
			foreach ( $nodes as $node ) {
				if ( ! is_object( $node ) || ! isset( $node->id, $node->name ) ) {
					continue;
				}
				// The count is the reach of the audience, sub-audiences included.
				$members                    = count( \FreeFormCertificate\Audience\AudienceReader::get_members( (int) $node->id, true ) );
				$options[ (int) $node->id ] = str_repeat( '— ', $depth ) . (string) $node->name . ' (' . $members . ')';
				if ( isset( $node->children ) && is_array( $node->children ) ) {
					$walk( $node->children, $depth + 1 );
				}
			}
		};
		$walk( \FreeFormCertificate\Audience\AudienceReader::get_hierarchical( 'active' ), 0 );

		return $options;
	}

	/**
	 * Periods the upcoming-dates panel offers: the next 7 or 30 days, or a
	 * whole month (`m1` … `m12`). Keys are never numeric strings, which PHP
	 * would turn into integers.
	 *
	 * @return array<string, string> Key => label.
	 */
	public static function upcoming_periods(): array {
		global $wp_locale;

		$periods = array(
			'next7'  => __( 'Next 7 days', 'ffcertificate' ),
			'next30' => __( 'Next 30 days', 'ffcertificate' ),
		);
		for ( $month = 1; $month <= 12; $month++ ) {
			$periods[ 'm' . $month ] = is_object( $wp_locale ) && method_exists( $wp_locale, 'get_month' ) ? (string) $wp_locale->get_month( $month ) : (string) $month;
		}
		return $periods;
	}

	/**
	 * The audiences the upcoming-dates filter offers (#1648): those of the
	 * chosen active rule, or of every active rule when none is chosen. A rule
	 * that reaches everyone -- the chosen one, or any active one when none is
	 * chosen -- offers every audience, and so does a screen with no active
	 * rule at all, where narrowing to nothing would only hide the filter.
	 *
	 * @param array<int, Rule>   $rules     Every rule.
	 * @param int                $rule_id   Chosen rule, 0 for none.
	 * @param array<int, string> $audiences Every audience, id => indented name.
	 * @return array<int, string> Audience id => name, in the tree's order.
	 */
	public static function upcoming_audience_options( array $rules, int $rule_id, array $audiences ): array {
		$active = self::active_rules( $rules, $rule_id );
		if ( array() === $active ) {
			return $audiences;
		}

		$ids = array();
		foreach ( $active as $rule ) {
			if ( array() === $rule->audience_ids ) {
				return $audiences;
			}
			foreach ( $rule->audience_ids as $id ) {
				$ids[ $id ] = true;
			}
		}
		return array_intersect_key( $audiences, $ids );
	}

	/**
	 * The audiences the upcoming-dates list covers: the one chosen, else those
	 * of the chosen rule; empty -- everyone -- when neither narrows it.
	 *
	 * @param array<int, Rule> $rules       Every rule.
	 * @param int              $rule_id     Chosen rule, 0 for none.
	 * @param int              $audience_id Chosen audience, 0 for none.
	 * @return array<int>
	 */
	public static function upcoming_scope( array $rules, int $rule_id, int $audience_id ): array {
		if ( $audience_id > 0 ) {
			return array( $audience_id );
		}
		if ( $rule_id <= 0 ) {
			return array();
		}
		$chosen = self::active_rules( $rules, $rule_id );
		return array() === $chosen ? array() : $chosen[0]->audience_ids;
	}

	/**
	 * Name, on each upcoming row, the offered audiences the person belongs to
	 * directly, so the list says why someone is in it.
	 *
	 * @param array<int, array<string, mixed>> $rows      Upcoming rows.
	 * @param array<int, string>               $offered   Audience id => indented name.
	 * @return array<int, array<string, mixed>> The rows, each with an `audiences` string.
	 */
	private static function with_audience_names( array $rows, array $offered ): array {
		if ( array() === $offered || ! class_exists( \FreeFormCertificate\Audience\AudienceReader::class ) ) {
			return $rows;
		}
		foreach ( $rows as $i => $row ) {
			$names = array();
			foreach ( \FreeFormCertificate\Audience\AudienceReader::get_user_audiences( (int) ( $row['user_id'] ?? 0 ) ) as $audience ) {
				if ( isset( $offered[ (int) $audience->id ] ) ) {
					$names[] = (string) $audience->name;
				}
			}
			$rows[ $i ]['audiences'] = implode( ', ', $names );
		}
		return $rows;
	}

	/**
	 * The active rules, or only the chosen one when it is active.
	 *
	 * @param array<int, Rule> $rules   Every rule.
	 * @param int              $rule_id Chosen rule, 0 for every active one.
	 * @return list<Rule>
	 */
	private static function active_rules( array $rules, int $rule_id ): array {
		return array_values(
			array_filter(
				$rules,
				static fn( Rule $rule ): bool => $rule->is_active && ( $rule_id <= 0 || $rule->id === $rule_id )
			)
		);
	}

	/**
	 * The people whose date falls in a period, through the same resolver the
	 * send uses: a synthetic rule carries only the audiences, so the panel
	 * filters, and flags opt-outs, exactly as a send would.
	 *
	 * A month already past this year means its next occurrence, next year,
	 * which only matters for 29 February.
	 *
	 * @param string             $period       A key of upcoming_periods().
	 * @param array<int>         $audience_ids Audiences to list, empty for everyone.
	 * @param \DateTimeImmutable $today        Today, site timezone.
	 * @return array{from: \DateTimeImmutable, to: \DateTimeImmutable, rows: array<int, array{user_id: int, name: string, email: string, date: string, decision: string}>, truncated: bool}
	 */
	public static function upcoming( string $period, array $audience_ids, \DateTimeImmutable $today ): array {
		if ( 1 === preg_match( '/^m(1[0-2]|[1-9])$/', $period, $m ) ) {
			$month = (int) $m[1];
			$year  = (int) $today->format( 'Y' ) + ( $month < (int) $today->format( 'n' ) ? 1 : 0 );
			$from  = $today->setDate( $year, $month, 1 );
			$to    = $from->modify( 'last day of this month' );
		} else {
			$from = $today;
			$to   = $today->modify( 'next7' === $period ? '+6 days' : '+29 days' );
		}

		$rule      = Rule::from_array(
			array(
				'name'         => 'upcoming',
				'subject'      => '-',
				'body'         => '-',
				'audience_ids' => $audience_ids,
			)
		);
		$rows      = array();
		$truncated = false;
		if ( $rule instanceof Rule ) {
			$found     = RecipientPreview::collect( $rule, $from, $to, true );
			$truncated = $found['truncated'];
			foreach ( $found['rows'] as $row ) {
				if ( RecipientResolver::OUT_OF_AUDIENCE !== $row['decision'] ) {
					$rows[] = $row;
				}
			}
		}

		return array(
			'from'      => $from,
			'to'        => $to,
			'rows'      => $rows,
			'truncated' => $truncated,
		);
	}

	/**
	 * Whether the upcoming dates can list a person: an account with a birth
	 * date on its profile. The reveal endpoint serves only those, so the
	 * `date_messages_person` type cannot read any account's name by id.
	 *
	 * @param int $user_id Account ID.
	 * @return bool
	 */
	public static function is_listed( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$extended = UserManager::get_extended_profile( $user_id, array( 'birth_date' ) );
		$stored   = $extended['birth_date'] ?? '';
		return is_string( $stored ) && '' !== $stored;
	}

	/**
	 * A `Y-m-d` date in the site timezone, or null.
	 *
	 * @param string $value Raw value.
	 * @return \DateTimeImmutable|null
	 */
	public static function date( string $value ): ?\DateTimeImmutable {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
		return false !== $date && $date->format( 'Y-m-d' ) === $value ? $date : null;
	}

	/**
	 * Refuse a write without the capability or a valid nonce.
	 *
	 * @param string $nonce_action Nonce action.
	 * @return void
	 */
	private function guard( string $nonce_action ): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * The guard for a send: its own capability, not the rules' (#1949).
	 *
	 * @param string $nonce_action Nonce action.
	 * @return void
	 */
	private function guard_send( string $nonce_action ): void {
		if ( ! self::can_send() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Record an outcome for the next screen and go back to it.
	 *
	 * @param string               $type    'success' or 'error'.
	 * @param string               $message Message.
	 * @param array<string, mixed> $args    Query arguments for the screen.
	 * @param array<string, mixed> $draft   Submitted values to put back in the editor.
	 * @return never
	 */
	private function finish( string $type, string $message, array $args = array(), array $draft = array() ): void {
		set_transient(
			self::OUTCOME_TRANSIENT . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
				'draft'   => $draft,
			),
			self::OUTCOME_TTL
		);

		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::MENU_SLUG ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Read and clear the outcome of the last write.
	 *
	 * @return array<string, mixed>
	 */
	private function take_outcome(): array {
		$key     = self::OUTCOME_TRANSIENT . get_current_user_id();
		$outcome = get_transient( $key );
		if ( false === $outcome ) {
			return array();
		}
		delete_transient( $key );
		return is_array( $outcome ) ? $outcome : array();
	}
}
