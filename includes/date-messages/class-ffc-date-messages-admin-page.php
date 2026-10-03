<?php
/**
 * Date-messages admin screen.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;

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
	 * Parent menu: the plugin's own.
	 */
	public const PARENT = 'edit.php?post_type=ffc_form';

	/**
	 * Read-only access: rules, history and recipient totals.
	 */
	public const VIEW_CAP = 'ffc_view_date_messages';

	/**
	 * Create and edit rules, send manually, set the send time.
	 */
	public const MANAGE_CAP = 'ffc_manage_date_messages';

	/**
	 * See who receives: names in the recipient preview.
	 */
	public const PII_CAP = 'ffc_view_date_messages_pii';

	/**
	 * `admin_post` actions. Each nonce action equals its `admin_post`
	 * action, keyed per rule where the action names one.
	 */
	public const SAVE_ACTION      = 'ffc_date_messages_save_rule';
	public const DELETE_ACTION    = 'ffc_date_messages_delete_rule';
	public const DUPLICATE_ACTION = 'ffc_date_messages_duplicate_rule';
	public const TOGGLE_ACTION    = 'ffc_date_messages_toggle_rule';
	public const SEND_ACTION      = 'ffc_date_messages_send_now';
	public const SETTINGS_ACTION  = 'ffc_date_messages_settings';

	/**
	 * Tabs, in display order.
	 */
	public const TABS = array( 'rules', 'send', 'history', 'settings' );

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
		add_action( 'admin_post_' . self::TOGGLE_ACTION, array( $this, 'handle_toggle' ) );
		add_action( 'admin_post_' . self::SEND_ACTION, array( $this, 'handle_send_now' ) );
		add_action( 'admin_post_' . self::SETTINGS_ACTION, array( $this, 'handle_settings' ) );
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
	 * Whether the current user may see recipients by name.
	 *
	 * @return bool
	 */
	public static function can_view_pii(): bool {
		return Capabilities::current_user_can_admin_or( self::PII_CAP );
	}

	/**
	 * Register the submenu.
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

		add_submenu_page(
			self::PARENT,
			__( 'Date Messages', 'ffcertificate' ),
			__( 'Date Messages', 'ffcertificate' ),
			$cap,
			self::MENU_SLUG,
			array( $this, 'render_page' )
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

		wp_enqueue_script(
			'ffc-date-messages-admin',
			FFC_PLUGIN_URL . "assets/js/ffc-date-messages-admin{$suffix}.js",
			array( 'jquery', 'ffc-core' ),
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
				'decisions'     => self::decision_labels(),
				'strings'       => array(
					'loading'   => __( 'Working…', 'ffcertificate' ),
					'failed'    => __( 'The request failed. Reload the page and try again.', 'ffcertificate' ),
					'truncated' => __( 'Only the first rows are listed; the totals count everyone.', 'ffcertificate' ),
					'none'      => __( 'Nobody falls on these dates.', 'ffcertificate' ),
				),
			)
		);

		$defaults = MessageBuilder::defaults();
		wp_enqueue_script(
			'ffc-email-restore-default',
			FFC_PLUGIN_URL . "assets/js/ffc-email-restore-default{$suffix}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-email-restore-default',
			'ffcEmailRestoreDefaults',
			array(
				'date_message_body' => array(
					'body'    => $defaults['body'],
					'confirm' => __( 'Replace the current message with the default text? Your changes will be lost.', 'ffcertificate' ),
				),
			)
		);
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

		$outcome    = $this->take_outcome();
		$can_manage = self::can_manage();
		$rules      = RuleReader::all();
		$audiences  = self::audience_options();
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
		}

		$history = array();
		$total   = 0;
		$paged   = max( 1, RequestInput::get_get_int( 'paged', 1 ) );
		if ( 'history' === $tab ) {
			$history = DeliveryLog::recent_runs( self::HISTORY_PER_PAGE, ( $paged - 1 ) * self::HISTORY_PER_PAGE );
			$total   = DeliveryLog::count_runs();
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
	 * Save a rule from the editor.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		$this->guard( self::SAVE_ACTION );

		$posted = RequestInput::get_post_raw_array( 'rule' );
		$id     = is_numeric( $posted['id'] ?? null ) ? max( 0, (int) $posted['id'] ) : 0;
		$data   = self::form_data( $posted );

		// The digest settings are not on this form yet, so an edit keeps the
		// stored ones instead of resetting them.
		$stored = $id > 0 ? RuleReader::get_by_id( $id ) : null;
		if ( $id > 0 && null === $stored ) {
			$this->finish( 'error', __( 'That rule no longer exists.', 'ffcertificate' ) );
		}
		if ( null !== $stored ) {
			$data['digest_enabled']  = $stored->digest_enabled ? '1' : '0';
			$data['digest_mode']     = $stored->digest_mode;
			$data['digest_user_ids'] = $stored->digest_user_ids;
		}
		$data['id'] = $id;

		$rule = Rule::from_array( $data );
		if ( is_wp_error( $rule ) ) {
			$this->finish( 'error', $rule->get_error_message(), array( 'rule' => $id > 0 ? $id : 0 ), $data );
		}

		$saved = RuleWriter::save( $rule );
		if ( false === $saved ) {
			$this->finish( 'error', __( 'The rule could not be saved.', 'ffcertificate' ), array( 'rule' => $id > 0 ? $id : 0 ), $data );
		}

		$this->finish( 'success', __( 'Rule saved.', 'ffcertificate' ), array( 'rule' => $saved ) );
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
	 * Turn a rule on or off.
	 *
	 * @return void
	 */
	public function handle_toggle(): void {
		$id = RequestInput::get_post_int( 'rule_id' );
		$this->guard( self::TOGGLE_ACTION . '_' . $id );

		$rule = $id > 0 ? RuleReader::get_by_id( $id ) : null;
		if ( null === $rule ) {
			$this->finish( 'error', __( 'That rule no longer exists.', 'ffcertificate' ) );
		}

		$data              = $rule->to_columns();
		$data['id']        = $rule->id;
		$data['is_active'] = $rule->is_active ? '0' : '1';

		$toggled = Rule::from_array( $data );
		if ( ! $toggled instanceof Rule || false === RuleWriter::save( $toggled ) ) {
			$this->finish( 'error', __( 'The rule could not be saved.', 'ffcertificate' ) );
		}

		$this->finish( 'success', $toggled->is_active ? __( 'Rule activated.', 'ffcertificate' ) : __( 'Rule deactivated.', 'ffcertificate' ) );
	}

	/**
	 * Send one rule's messages over a range of target dates, now.
	 *
	 * @return void
	 */
	public function handle_send_now(): void {
		$this->guard( self::SEND_ACTION );

		$back = array( 'tab' => 'send' );
		$rule = RuleReader::get_by_id( RequestInput::get_post_int( 'rule_id' ) );
		if ( null === $rule ) {
			$this->finish( 'error', __( 'Choose a rule.', 'ffcertificate' ), $back );
		}

		$from = self::date( RequestInput::get_post_string( 'from' ) );
		$to   = self::date( RequestInput::get_post_string( 'to' ) );
		if ( null === $from || null === $to ) {
			$this->finish( 'error', __( 'Enter both dates.', 'ffcertificate' ), $back );
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
	 * Save the daily send time and move the event to it.
	 *
	 * @return void
	 */
	public function handle_settings(): void {
		$this->guard( self::SETTINGS_ACTION );

		$back = array( 'tab' => 'settings' );
		$time = RequestInput::get_post_string( 'send_time' );
		if ( 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			$this->finish( 'error', __( 'Enter the time as hours and minutes, such as 08:00.', 'ffcertificate' ), $back );
		}

		$settings              = get_option( DateMessagesCron::SETTINGS_OPTION, array() );
		$settings              = is_array( $settings ) ? $settings : array();
		$settings['send_time'] = $time;
		update_option( DateMessagesCron::SETTINGS_OPTION, $settings, false );

		DateMessagesCron::reschedule();

		$this->finish( 'success', __( 'Send time saved and the daily event moved to it.', 'ffcertificate' ), $back );
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
			'name'         => $posted['name'] ?? '',
			'source'       => $posted['source'] ?? BirthdaySource::ID,
			'offset_days'  => $posted['offset_days'] ?? '',
			'audience_id'  => $posted['audience_id'] ?? '',
			'subject'      => $posted['subject'] ?? '',
			'body'         => is_string( $body ) ? wp_kses_post( $body ) : '',
			'send_to_user' => isset( $posted['send_to_user'] ) ? '1' : '0',
			'is_active'    => isset( $posted['is_active'] ) ? '1' : '0',
		);
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
				$options[ (int) $node->id ] = str_repeat( '— ', $depth ) . (string) $node->name;
				if ( isset( $node->children ) && is_array( $node->children ) ) {
					$walk( $node->children, $depth + 1 );
				}
			}
		};
		$walk( \FreeFormCertificate\Audience\AudienceReader::get_hierarchical( 'active' ), 0 );

		return $options;
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
