<?php
/**
 * Audience Admin Presence
 *
 * The Presence devices screen (#2743): the phones participants activated to
 * show their presence code, what needs an administrator, and the actions that
 * answer it.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Admin\AdminUI;
use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Presence devices: the stat cards, "Needs attention", the device list and a
 * participant's history.
 *
 * Every action is a POST to the screen itself, checked against the nonce and
 * {@see self::CAP}, and answered by a redirect that names the result, so a
 * reload never repeats it.
 *
 * @phpstan-import-type DeviceRow from AudiencePresenceReader
 * @phpstan-import-type RequestRow from AudiencePresenceReader
 * @phpstan-import-type SignalGroup from AudiencePresenceReader
 */
class AudienceAdminPresence {

	/**
	 * What every view and action of the screen needs.
	 */
	public const CAP = 'ffc_manage_audiences_presence';

	/**
	 * The screen's slug, after the menu prefix.
	 */
	public const SLUG_SUFFIX = '-presence';

	/**
	 * The nonce action every form carries.
	 */
	public const NONCE = 'ffc_presence_admin';

	/**
	 * Devices per page in the list.
	 */
	public const PER_PAGE = 50;

	/**
	 * The window "changes" counts over, in days.
	 */
	public const CHANGE_DAYS = 30;

	/**
	 * The most accounts a user search narrows the list to.
	 */
	public const SEARCH_LIMIT = 200;

	/**
	 * Menu slug prefix.
	 *
	 * @var string
	 */
	private string $menu_slug;

	/**
	 * Constructor.
	 *
	 * @param string $menu_slug Menu slug prefix.
	 */
	public function __construct( string $menu_slug ) {
		$this->menu_slug = $menu_slug;
	}

	/**
	 * The screen's address.
	 *
	 * @param array<string, string|int> $args Extra query arguments.
	 * @return string
	 */
	public function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $this->menu_slug . self::SLUG_SUFFIX ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Run a posted action, or turn a result code into a notice.
	 *
	 * Called on every `ffc-scheduling*` screen by the coordinator, so it acts
	 * only on its own.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( RequestInput::get_get_string( 'page' ) !== $this->menu_slug . self::SLUG_SUFFIX ) {
			return;
		}
		if ( ! Capabilities::current_user_can_admin_or( self::CAP ) ) {
			return;
		}

		$message = RequestInput::get_get_string( 'message' );
		if ( '' !== $message ) {
			$this->notice( $message );
		}

		if ( 'presence_admin' !== RequestInput::get_post_string( 'ffc_action' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( RequestInput::get_post_string( 'ffc_presence_nonce' ), self::NONCE ) ) {
			wp_die( esc_html__( 'This page has expired. Reload it and try again.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		$result = $this->run(
			RequestInput::get_post_string( 'op' ),
			RequestInput::get_post_string( 'target' ),
			get_current_user_id(),
			time()
		);

		$args = array( 'message' => $result );
		$user = RequestInput::get_post_int( 'return_user' );
		if ( $user > 0 ) {
			$args['user'] = $user;
		}
		wp_safe_redirect( $this->url( $args ) );
		exit;
	}

	/**
	 * Carry out one action.
	 *
	 * @param string $op     `deactivate`, `approve`, `refuse` or `dismiss`.
	 * @param string $target Device row id, request id or signals hash.
	 * @param int    $by     The administrator.
	 * @param int    $now    Unix time.
	 * @return string The result code the redirect names.
	 */
	public function run( string $op, string $target, int $by, int $now ): string {
		switch ( $op ) {
			case 'deactivate':
				return $this->deactivate( absint( $target ), $by, $now );
			case 'approve':
			case 'refuse':
				return $this->decide( absint( $target ), 'approve' === $op, $by, $now );
			case 'dismiss':
				return $this->dismiss( $target, $by, $now );
		}
		return 'unknown';
	}

	/**
	 * Deactivate a device: its key is revoked, so the app asks to activate
	 * again and terminals refuse its codes from their next sync.
	 *
	 * @param int $id  Device row id.
	 * @param int $by  The administrator.
	 * @param int $now Unix time.
	 * @return string
	 */
	private function deactivate( int $id, int $by, int $now ): string {
		$device = AudiencePresenceReader::by_id( $id );
		if ( null === $device || null !== $device['revoked_at'] ) {
			return 'stale';
		}
		if ( ! AudiencePresenceWriter::revoke( $id, AudiencePresenceWriter::REVOKED_ADMIN, $by, $now ) ) {
			return 'failed';
		}
		ActivityLog::log(
			'presence_device_revoked',
			ActivityLog::LEVEL_INFO,
			array(
				'target_user_id' => $device['user_id'],
				'device_id'      => $device['device_id'],
				'key_id'         => $device['key_id'],
				'reason'         => AudiencePresenceWriter::REVOKED_ADMIN,
			),
			$by
		);
		return 'deactivated';
	}

	/**
	 * Answer a request.
	 *
	 * Allowing a reserved phone releases it from every other account it is
	 * reserved to; allowing a change beyond the limit lets exactly one more
	 * activation through. Either way the participant activates the phone
	 * again in the app.
	 *
	 * @param int  $id      Request id.
	 * @param bool $approve Whether to allow it.
	 * @param int  $by      The administrator.
	 * @param int  $now     Unix time.
	 * @return string
	 */
	private function decide( int $id, bool $approve, int $by, int $now ): string {
		$request = AudiencePresenceReader::request( $id );
		if ( null === $request || AudiencePresenceWriter::REQUEST_PENDING !== $request['status'] ) {
			return 'stale';
		}
		$status = $approve ? AudiencePresenceWriter::REQUEST_APPROVED : AudiencePresenceWriter::REQUEST_REFUSED;
		if ( ! AudiencePresenceWriter::decide_request( $id, $status, $by, $now ) ) {
			return 'stale';
		}

		$context = array(
			'target_user_id' => $request['user_id'],
			'device_id'      => $request['device_id'],
			'reason'         => $request['reason'],
		);
		if ( ! $approve ) {
			ActivityLog::log( 'presence_request_refused', ActivityLog::LEVEL_INFO, $context, $by );
			return 'refused';
		}

		if ( AudiencePresenceEnrolment::REASON_RESERVED === $request['reason'] ) {
			$context['released'] = AudiencePresenceWriter::release_device( $request['device_id'], $request['user_id'], $by, $now );
			ActivityLog::log( 'presence_device_released', ActivityLog::LEVEL_INFO, $context, $by );
			return 'released';
		}
		ActivityLog::log( 'presence_request_approved', ActivityLog::LEVEL_INFO, $context, $by );
		return 'approved';
	}

	/**
	 * Dismiss a shared-signals alert.
	 *
	 * @param string $hash The signals hash the alert is about.
	 * @param int    $by   The administrator.
	 * @param int    $now  Unix time.
	 * @return string
	 */
	private function dismiss( string $hash, int $by, int $now ): string {
		$hash = (string) preg_replace( '/[^a-f0-9]/', '', strtolower( $hash ) );
		if ( '' === $hash ) {
			return 'stale';
		}
		$count = AudiencePresenceWriter::dismiss_signals( $hash, $now );
		if ( $count <= 0 ) {
			return 'stale';
		}
		ActivityLog::log(
			'presence_signals_dismissed',
			ActivityLog::LEVEL_INFO,
			array(
				'signals_hash' => substr( $hash, 0, 16 ),
				'devices'      => $count,
			),
			$by
		);
		return 'dismissed';
	}

	/**
	 * Queue the notice a result code stands for.
	 *
	 * @param string $code Result code.
	 * @return void
	 */
	private function notice( string $code ): void {
		$messages = array(
			'deactivated' => array( 'success', __( 'Device deactivated. Its codes stop working at each terminal\'s next sync, and the app asks to activate it again.', 'ffcertificate' ) ),
			'released'    => array( 'success', __( 'Phone released. The participant can now activate it in the app.', 'ffcertificate' ) ),
			'approved'    => array( 'success', __( 'One more device change allowed. The participant can now activate the phone in the app.', 'ffcertificate' ) ),
			'refused'     => array( 'success', __( 'Request refused. The participant keeps the device they had.', 'ffcertificate' ) ),
			'dismissed'   => array( 'success', __( 'Alert dismissed. It comes back if another phone reports the same signals.', 'ffcertificate' ) ),
			'stale'       => array( 'warning', __( 'Nothing changed: someone already answered this, or the device is no longer active.', 'ffcertificate' ) ),
			'failed'      => array( 'error', __( 'The change could not be saved. Try again.', 'ffcertificate' ) ),
		);
		if ( isset( $messages[ $code ] ) ) {
			add_settings_error( 'ffc_presence', 'ffc_presence_' . $code, $messages[ $code ][1], $messages[ $code ][0] );
		}
	}

	/**
	 * Draw the screen.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! Capabilities::current_user_can_admin_or( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage presence devices.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		$now  = time();
		$user = RequestInput::get_get_int( 'user' );

		echo '<div class="wrap ffc-admin-page ffc-page-scheduling-presence ffc-boxed">';
		echo '<h1>' . esc_html__( 'Presence devices', 'ffcertificate' ) . '</h1>';
		\FreeFormCertificate\Core\AdminNotice::settings_errors( 'ffc_presence' );

		if ( $user > 0 ) {
			$this->render_history( $user, $now );
		} else {
			$this->render_overview( $now );
		}

		echo '</div>';
	}

	/**
	 * The stat cards, "Needs attention" and the device list, one above the
	 * other as the approved mockup draws them (#2782).
	 *
	 * @param int $now Unix time.
	 * @return void
	 */
	private function render_overview( int $now ): void {
		$requests = AudiencePresenceReader::pending_requests();
		$groups   = AudiencePresenceReader::signal_groups();
		$active   = AudiencePresenceReader::count_active();
		$changes  = AudiencePresenceReader::count_changes_since( $now - self::CHANGE_DAYS * DAY_IN_SECONDS );

		echo '<div class="ffc-stats">';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_stat_card() escapes every value.
		echo AdminUI::get_stat_card(
			array(
				'label' => __( 'Active devices', 'ffcertificate' ),
				'value' => $active,
				'icon'  => 'phone',
			)
		);
		echo AdminUI::get_stat_card(
			array(
				'label' => __( 'Release requests', 'ffcertificate' ),
				'value' => count( $requests ),
				'icon'  => 'unlock',
			)
		);
		echo AdminUI::get_stat_card(
			array(
				'label' => __( 'Same-signal alerts', 'ffcertificate' ),
				'value' => count( $groups ),
				'icon'  => 'warning',
			)
		);
		echo AdminUI::get_stat_card(
			array(
				/* translators: %d: number of days */
				'label' => sprintf( __( 'Changes in the last %d days', 'ffcertificate' ), self::CHANGE_DAYS ),
				'value' => $changes,
				'icon'  => 'sync',
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		$this->render_attention( $requests, $groups, $now );
		$this->render_list( $now );
	}

	/**
	 * The active devices, one page of them, narrowed by the user search.
	 *
	 * @param int $now Unix time.
	 * @return void
	 */
	private function render_list( int $now ): void {
		$search   = trim( RequestInput::get_get_string( 's' ) );
		$user_ids = '' === $search ? null : self::matching_users( $search );
		$total    = AudiencePresenceReader::count_active( $user_ids );
		$paged    = max( 1, RequestInput::get_get_int( 'paged' ) );
		$devices  = AudiencePresenceReader::active_devices( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE, $user_ids );

		echo '<div class="card ffc-presence-devices">';
		echo '<h2 class="ffc-icon-phone">' . esc_html__( 'Devices', 'ffcertificate' ) . '</h2>';

		if ( '' === $search && array() === $devices ) {
			echo '<p>' . esc_html__( 'No participant has activated a phone yet. A device appears here once someone activates it on the app\'s Presence screen.', 'ffcertificate' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<form method="get" class="ffc-list-filters ffc-presence-search" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( $this->menu_slug . self::SLUG_SUFFIX ) . '">';
		echo '<label for="ffc-presence-search">' . esc_html__( 'Search user', 'ffcertificate' ) . '</label>';
		echo '<input type="search" id="ffc-presence-search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Name, username or e-mail', 'ffcertificate' ) . '">';
		AdminUI::render_button(
			array(
				'label'    => __( 'Search', 'ffcertificate' ),
				'category' => 'neutral',
				'icon'     => 'search',
				'type'     => 'submit',
			)
		);
		echo '</form>';

		if ( array() === $devices ) {
			/* translators: %s: search term */
			echo '<p>' . esc_html( sprintf( __( 'No active device belongs to a user matching "%s".', 'ffcertificate' ), $search ) ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<div class="ffc-presence-scroll"><table class="widefat striped ffc-presence-table"><thead><tr>';
		foreach ( array(
			__( 'User', 'ffcertificate' ),
			__( 'Device', 'ffcertificate' ),
			__( 'Active since', 'ffcertificate' ),
			__( 'Offline until', 'ffcertificate' ),
			/* translators: %d: number of days */
			sprintf( __( 'Changes (%d days)', 'ffcertificate' ), self::CHANGE_DAYS ),
			__( 'Actions', 'ffcertificate' ),
		) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $devices as $device ) {
			$family = AudiencePresenceAgent::label( $device['platform'], $device['browser'] );
			echo '<tr>';
			echo '<td>' . esc_html( self::user_label( $device['user_id'] ) ) . '</td>';
			echo '<td>' . ( '' !== $family ? esc_html( $family ) . ' ' : '' ) . '<code>' . esc_html( self::device_label( $device['device_id'] ) ) . '</code></td>';
			echo '<td>' . esc_html( DateFormatter::format_date( $device['activated_at'] ) ) . '</td>';
			echo '<td>' . esc_html( self::offline_until( $device, $now ) ) . '</td>';
			echo '<td>' . esc_html(
				sprintf(
					/* translators: 1: changes made, 2: changes allowed */
					__( '%1$d of %2$d', 'ffcertificate' ),
					AudiencePresenceReader::changes_since( $device['user_id'], $now - self::CHANGE_DAYS * DAY_IN_SECONDS ),
					AudiencePresenceEnrolment::MAX_CHANGES
				)
			) . '</td>';
			echo '<td><div class="ffc-presence-actions">';
			AdminUI::render_button(
				array(
					'label'    => __( 'History', 'ffcertificate' ),
					'category' => 'neutral',
					'icon'     => 'history',
					'href'     => $this->url( array( 'user' => $device['user_id'] ) ),
				)
			);
			$this->action_form(
				'deactivate',
				(string) $device['id'],
				__( 'Deactivate', 'ffcertificate' ),
				'danger',
				'cross',
				__( 'Deactivate this device? Its presence codes stop working, and the participant has to activate a phone again in the app.', 'ffcertificate' )
			);
			echo '</div></td></tr>';
		}
		echo '</tbody></table></div>';

		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			$base  = '' === $search ? $this->url() : $this->url( array( 's' => $search ) );
			$links = paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%', $base ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				)
			);
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( (string) $links ) . '</div></div>';
		}
		echo '<p class="description ffc-presence-footnote">' . esc_html__( 'Deactivating revokes the device\'s key: terminals refuse its code from their next sync. Every release, refusal and deactivation is written to the activity log.', 'ffcertificate' ) . '</p>';
		echo '</div>';
	}

	/**
	 * The accounts a search term names, by name, username or e-mail.
	 *
	 * Capped, so a one-letter search cannot turn into an IN list of every
	 * account; a term that broad narrows nothing anyway.
	 *
	 * @param string $search Search term.
	 * @return list<int>
	 */
	private static function matching_users( string $search ): array {
		$ids = get_users(
			array(
				'search'         => '*' . $search . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				'fields'         => 'ID',
				'number'         => self::SEARCH_LIMIT,
			)
		);
		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * What an administrator has to answer, above the list, and nothing at
	 * all when nothing waits.
	 *
	 * @param array $requests Pending requests.
	 * @param array $groups   Shared-signals groups.
	 * @param int   $now      Unix time.
	 * @phpstan-param list<RequestRow>  $requests
	 * @phpstan-param list<SignalGroup> $groups
	 * @return void
	 */
	private function render_attention( array $requests, array $groups, int $now ): void {
		if ( array() === $requests && array() === $groups ) {
			return;
		}

		echo '<div class="card ffc-presence-attention">';
		echo '<h2 class="ffc-icon-warning">' . esc_html__( 'Needs attention', 'ffcertificate' ) . '</h2>';
		echo '<ul class="ffc-presence-attention-list">';
		foreach ( $requests as $request ) {
			$name     = self::user_label( $request['user_id'] );
			$reserved = AudiencePresenceEnrolment::REASON_RESERVED === $request['reason'];
			if ( $reserved ) {
				$title       = __( 'Phone of another account', 'ffcertificate' );
				$reservation = AudiencePresenceReader::reservation( $request['device_id'], $request['user_id'], $now );
				if ( null === $reservation ) {
					/* translators: %s: participant */
					$text = sprintf( __( '%s tried to activate presence on a phone reserved to another account.', 'ffcertificate' ), $name );
				} elseif ( null === $reservation['until'] ) {
					/* translators: 1: participant, 2: the account that uses the phone */
					$text = sprintf( __( '%1$s tried to activate presence on a phone %2$s still uses.', 'ffcertificate' ), $name, self::user_label( $reservation['user_id'] ) );
				} else {
					/* translators: 1: participant, 2: the account the phone is reserved to, 3: date */
					$text = sprintf( __( '%1$s tried to activate presence on a phone reserved to %2$s until %3$s.', 'ffcertificate' ), $name, self::user_label( $reservation['user_id'] ), DateFormatter::format_date( $reservation['until'] ) );
				}
			} else {
				$title = __( 'Change limit', 'ffcertificate' );
				/* translators: 1: participant, 2: the change asked for (one more than the limit), 3: number of days */
				$text = sprintf( __( '%1$s asked for device change number %2$d in %3$d days.', 'ffcertificate' ), $name, AudiencePresenceEnrolment::MAX_CHANGES + 1, self::CHANGE_DAYS );
			}
			echo '<li>';
			echo '<p class="ffc-presence-attention-kind">' . esc_html( $title ) . '</p>';
			echo '<p>' . esc_html( $text ) . ' <span class="ffc-presence-attention-when">· ' . esc_html( DateFormatter::format_datetime( $request['created_at'] ) ) . '</span></p>';
			echo '<div class="ffc-presence-actions">';
			$this->action_form(
				'approve',
				(string) $request['id'],
				/* translators: %s: participant */
				$reserved ? sprintf( __( 'Release to %s', 'ffcertificate' ), $name ) : __( 'Release one change', 'ffcertificate' ),
				'caution',
				'unlock',
				$reserved
					? __( 'Release this phone from the other account? That account can no longer show codes from it.', 'ffcertificate' )
					: ''
			);
			$this->action_form( 'refuse', (string) $request['id'], __( 'Refuse', 'ffcertificate' ), 'neutral', 'cross', '' );
			echo '</div></li>';
		}
		foreach ( $groups as $group ) {
			$names = array_map( array( self::class, 'user_label' ), $group['user_ids'] );
			echo '<li>';
			echo '<p class="ffc-presence-attention-kind">' . esc_html__( 'Alert · same signals', 'ffcertificate' ) . '</p>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: list of participants */
					__( 'The devices of %s report the same signals. It may be the same phone, or two identical school phones.', 'ffcertificate' ),
					implode( ', ', $names )
				)
			) . '</p>';
			echo '<div class="ffc-presence-actions">';
			foreach ( $group['user_ids'] as $user_id ) {
				AdminUI::render_button(
					array(
						/* translators: %s: participant */
						'label'    => sprintf( __( 'History of %s', 'ffcertificate' ), self::user_label( $user_id ) ),
						'category' => 'neutral',
						'icon'     => 'history',
						'href'     => $this->url( array( 'user' => $user_id ) ),
					)
				);
			}
			$this->action_form( 'dismiss', $group['signals_hash'], __( 'Dismiss', 'ffcertificate' ), 'neutral', 'cross', '' );
			echo '</div></li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Every device a participant activated, the replaced and revoked ones
	 * included.
	 *
	 * @param int $user_id Participant.
	 * @param int $now     Unix time.
	 * @return void
	 */
	private function render_history( int $user_id, int $now ): void {
		AdminUI::render_button(
			array(
				'label'    => __( 'Back to presence devices', 'ffcertificate' ),
				'category' => 'neutral',
				'icon'     => 'chevron-left',
				'href'     => $this->url(),
			)
		);

		$devices = self::latest_per_device( AudiencePresenceReader::for_user( $user_id ) );
		echo '<div class="card ffc-presence-history">';
		/* translators: %s: participant */
		echo '<h2 class="ffc-icon-history">' . esc_html( sprintf( __( 'Device history: %s', 'ffcertificate' ), self::user_label( $user_id ) ) ) . '</h2>';
		if ( array() === $devices ) {
			echo '<p>' . esc_html__( 'This participant has never activated a phone.', 'ffcertificate' ) . '</p></div>';
			return;
		}
		$changes = AudiencePresenceReader::changes_since( $user_id, $now - self::CHANGE_DAYS * DAY_IN_SECONDS );
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: number of changes, 2: number of days */
				_n( '%1$d device change in the last %2$d days.', '%1$d device changes in the last %2$d days.', $changes, 'ffcertificate' ),
				$changes,
				self::CHANGE_DAYS
			)
		) . '</p>';

		echo '<table class="widefat striped ffc-presence-table"><thead><tr>';
		foreach ( array(
			__( 'Device', 'ffcertificate' ),
			__( 'Activated', 'ffcertificate' ),
			__( 'Status', 'ffcertificate' ),
			__( 'Works offline until', 'ffcertificate' ),
			__( 'Actions', 'ffcertificate' ),
		) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $devices as $device ) {
			$active = null === $device['revoked_at'];
			echo '<tr>';
			echo '<td><code>' . esc_html( self::device_label( $device['device_id'] ) ) . '</code></td>';
			echo '<td>' . esc_html( DateFormatter::format_datetime( $device['activated_at'] ) ) . '</td>';
			echo '<td>' . esc_html( self::status_label( $device ) ) . '</td>';
			echo '<td>' . esc_html( $active ? self::offline_until( $device, $now ) : '—' ) . '</td>';
			echo '<td><div class="ffc-presence-actions">';
			if ( $active ) {
				$this->action_form(
					'deactivate',
					(string) $device['id'],
					__( 'Deactivate', 'ffcertificate' ),
					'danger',
					'cross',
					__( 'Deactivate this device? Its presence codes stop working, and the participant has to activate a phone again in the app.', 'ffcertificate' ),
					$user_id
				);
			}
			echo '</div></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * One action, as a form of its own.
	 *
	 * @param string $op          Action.
	 * @param string $target      What it acts on.
	 * @param string $label       Button label.
	 * @param string $category    Button category.
	 * @param string $icon        Button icon.
	 * @param string $confirm     A question to confirm first, or '' for none.
	 * @param int    $return_user The history to come back to, or 0 for the overview.
	 * @return void
	 */
	private function action_form( string $op, string $target, string $label, string $category, string $icon, string $confirm, int $return_user = 0 ): void {
		echo '<form method="post" class="ffc-presence-action" action="' . esc_url( $this->url() ) . '">';
		wp_nonce_field( self::NONCE, 'ffc_presence_nonce' );
		echo '<input type="hidden" name="ffc_action" value="presence_admin">';
		echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
		echo '<input type="hidden" name="target" value="' . esc_attr( $target ) . '">';
		if ( $return_user > 0 ) {
			echo '<input type="hidden" name="return_user" value="' . esc_attr( (string) $return_user ) . '">';
		}
		$attrs = array();
		if ( '' !== $confirm ) {
			$attrs['data-confirm'] = $confirm;
		}
		AdminUI::render_button(
			array(
				'label'    => $label,
				'category' => $category,
				'icon'     => $icon,
				'type'     => 'submit',
				'attrs'    => $attrs,
			)
		);
		echo '</form>';
	}

	/**
	 * How the screen names a participant: the display name, or the account
	 * number when the account is gone.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	public static function user_label( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( $user instanceof \WP_User && '' !== trim( (string) $user->display_name ) ) {
			return (string) $user->display_name;
		}
		/* translators: %d: user ID */
		return sprintf( __( 'Account #%d', 'ffcertificate' ), $user_id );
	}

	/**
	 * A short name for a device: the last characters of the id the app keeps.
	 *
	 * @param string $device_id Device id.
	 * @return string
	 */
	public static function device_label( string $device_id ): string {
		return '…' . substr( $device_id, -8 );
	}

	/**
	 * Until when a device can show a valid code without reaching the site:
	 * its last attestation's expiry.
	 *
	 * @param array $device Device row.
	 * @param int   $now    Unix time.
	 * @phpstan-param DeviceRow $device
	 * @return string
	 */
	public static function offline_until( array $device, int $now ): string {
		$until = ( $device['attested_at'] ?? $device['activated_at'] ) + AudiencePresenceAttestation::TTL;
		if ( $until < $now ) {
			/* translators: %s: date */
			return sprintf( __( 'Expired on %s; renews when the app goes online', 'ffcertificate' ), DateFormatter::format_date( $until ) );
		}
		return DateFormatter::format_date( $until );
	}

	/**
	 * One row per phone: its latest activation.
	 *
	 * Activating the same phone again makes a new key and a new row; the old
	 * rows stay, because terminals refuse a retired key through the
	 * revocation list, but the history names each phone once.
	 *
	 * @param array $devices Device rows, newest activation first.
	 * @phpstan-param list<DeviceRow> $devices
	 * @return array
	 * @phpstan-return list<DeviceRow>
	 */
	public static function latest_per_device( array $devices ): array {
		$latest = array();
		foreach ( $devices as $device ) {
			if ( ! isset( $latest[ $device['device_id'] ] ) ) {
				$latest[ $device['device_id'] ] = $device;
			}
		}
		return array_values( $latest );
	}

	/**
	 * A device's status in words.
	 *
	 * @param array $device Device row.
	 * @phpstan-param DeviceRow $device
	 * @return string
	 */
	public static function status_label( array $device ): string {
		if ( null === $device['revoked_at'] ) {
			return __( 'Active', 'ffcertificate' );
		}
		$when = DateFormatter::format_datetime( $device['revoked_at'] );
		return AudiencePresenceWriter::REVOKED_ADMIN === $device['revoked_reason']
			/* translators: %s: date and time */
			? sprintf( __( 'Deactivated by an administrator on %s', 'ffcertificate' ), $when )
			/* translators: %s: date and time */
			: sprintf( __( 'Replaced by another phone on %s', 'ffcertificate' ), $when );
	}
}
