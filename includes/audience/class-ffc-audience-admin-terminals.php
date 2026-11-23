<?php
/**
 * Audience Admin Terminals
 *
 * The Terminals screen (#2748): the check-in terminals, how they are doing,
 * and the actions that change or end one.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Admin\AdminUI;
use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Terminals: the stat cards, the list and the event editor.
 *
 * An operator sees the terminals they paired; an administrator sees every
 * one, as in the app. Every action is a POST to the screen itself, checked
 * against the nonce and {@see AudienceTerminalPairing::CAP}, and answered by
 * a redirect that names the result, so a reload never repeats it.
 *
 * @phpstan-import-type TerminalRow from AudienceTerminalReader
 */
class AudienceAdminTerminals {

	/**
	 * The screen's slug, after the menu prefix.
	 */
	public const SLUG_SUFFIX = '-terminals';

	/**
	 * The nonce action every form carries.
	 */
	public const NONCE = 'ffc_terminals_admin';

	/**
	 * A terminal heard from within this many seconds is online. It asks for
	 * its schedule every 30 seconds while it has a connection.
	 */
	public const ONLINE_SECONDS = 120;

	/**
	 * How long an ended terminal stays on the list, in days.
	 */
	public const KEEP_DAYS = 7;

	public const STATE_ONLINE  = 'online';
	public const STATE_OFFLINE = 'offline';
	public const STATE_EXPIRED = 'expired';
	public const STATE_REVOKED = 'revoked';

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
	 * Run a posted action, or turn a result code into a notice. Called on
	 * every `ffc-scheduling*` screen by the coordinator, so it acts only on
	 * its own.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( RequestInput::get_get_string( 'page' ) !== $this->menu_slug . self::SLUG_SUFFIX ) {
			return;
		}
		if ( ! Capabilities::current_user_can_admin_or( AudienceTerminalPairing::CAP ) ) {
			return;
		}

		$message = RequestInput::get_get_string( 'message' );
		if ( '' !== $message ) {
			$this->notice( $message );
		}

		if ( 'terminals_admin' !== RequestInput::get_post_string( 'ffc_action' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( RequestInput::get_post_string( 'ffc_terminals_nonce' ), self::NONCE ) ) {
			wp_die( esc_html__( 'This page has expired. Reload it and try again.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; sanitize_ids() casts every entry to a positive int.
		$bookings = isset( $_POST['bookings'] ) && is_array( $_POST['bookings'] ) ? AudienceTerminalRestController::sanitize_ids( wp_unslash( $_POST['bookings'] ) ) : array();
		$result   = $this->run(
			RequestInput::get_post_string( 'op' ),
			RequestInput::get_post_int( 'terminal' ),
			$bookings,
			get_current_user_id(),
			current_user_can( 'manage_options' ),
			time()
		);

		$args = array( 'message' => $result['code'] );
		if ( '' !== $result['error'] ) {
			$args['error'] = $result['error'];
		}
		if ( 'events_failed' === $result['code'] ) {
			$args['edit'] = RequestInput::get_post_int( 'terminal' );
		}
		wp_safe_redirect( $this->url( $args ) );
		exit;
	}

	/**
	 * Carry out one action.
	 *
	 * @param string $op       `events` or `end`.
	 * @param int    $id       Terminal id.
	 * @param int[]  $bookings The events, for `events`.
	 * @param int    $by       Who acts.
	 * @param bool   $any      Whether they may act on a terminal someone else paired.
	 * @param int    $now      Unix time.
	 * @phpstan-param list<int> $bookings
	 * @return array{code: string, error: string} The result code the redirect names, and the refusal's own code.
	 */
	public function run( string $op, int $id, array $bookings, int $by, bool $any, int $now ): array {
		if ( 'end' === $op ) {
			$result = AudienceTerminalPairing::revoke( $id, $by, $any, $now );
			return is_wp_error( $result )
				? array(
					'code'  => 'stale',
					'error' => '',
				)
				: array(
					'code'  => 'ended',
					'error' => '',
				);
		}
		if ( 'events' === $op ) {
			$result = AudienceTerminalPairing::update_bookings( $id, $bookings, $by, $any, $now );
			return is_wp_error( $result )
				? array(
					'code'  => 'events_failed',
					'error' => (string) $result->get_error_code(),
				)
				: array(
					'code'  => 'events_saved',
					'error' => '',
				);
		}
		return array(
			'code'  => 'unknown',
			'error' => '',
		);
	}

	/**
	 * Queue the notice a result code stands for.
	 *
	 * @param string $code Result code.
	 * @return void
	 */
	private function notice( string $code ): void {
		$errors   = self::error_messages();
		$error    = RequestInput::get_get_string( 'error' );
		$messages = array(
			'ended'         => array( 'success', __( 'Terminal ended. It stops checking people in at once.', 'ffcertificate' ) ),
			'events_saved'  => array( 'success', __( 'Events saved. The terminal picks them up within a minute.', 'ffcertificate' ) ),
			'events_failed' => array( 'error', $errors[ $error ] ?? __( 'The events could not be saved. Try again.', 'ffcertificate' ) ),
			'stale'         => array( 'warning', __( 'Nothing changed: this terminal had already ended.', 'ffcertificate' ) ),
		);
		if ( isset( $messages[ $code ] ) ) {
			add_settings_error( 'ffc_terminals', 'ffc_terminals_' . $code, $messages[ $code ][1], $messages[ $code ][0] );
		}
	}

	/**
	 * What a refused event change says, by the refusal's code. The overlap
	 * refusal names both events, which a redirect cannot carry, so it says
	 * what to do instead.
	 *
	 * @return array<string, string>
	 */
	private static function error_messages(): array {
		return array(
			'ffc_terminal_no_bookings'   => __( 'Choose at least one event for the terminal.', 'ffcertificate' ),
			/* translators: %d: maximum number of events */
			'ffc_terminal_too_many'      => sprintf( __( 'A terminal holds at most %d events.', 'ffcertificate' ), AudienceTerminalPairing::MAX_BOOKINGS ),
			/* translators: %d: number of days */
			'ffc_terminal_booking'       => sprintf( __( 'Every event must be active and end within the next %d days.', 'ffcertificate' ), AudienceTerminalPairing::DAYS_AHEAD ),
			'ffc_terminal_overlap'       => __( 'Two of these events have check-in windows that cross. A terminal checks in one event at a time: put them on different terminals.', 'ffcertificate' ),
			'ffc_terminal_booking_limit' => __( 'One of these events already has as many terminals as it may. End one before adding it here.', 'ffcertificate' ),
			'ffc_terminal_missing'       => __( 'There is no such terminal.', 'ffcertificate' ),
		);
	}

	/**
	 * Draw the screen.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! Capabilities::current_user_can_admin_or( AudienceTerminalPairing::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage check-in terminals.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		$now       = time();
		$all       = current_user_can( 'manage_options' );
		$terminals = self::visible( AudienceTerminalReader::recent_terminals( $now - self::KEEP_DAYS * DAY_IN_SECONDS ), $all ? null : get_current_user_id() );
		$edit      = RequestInput::get_get_int( 'edit' );

		echo '<div class="wrap ffc-admin-page ffc-page-scheduling-terminals ffc-boxed">';
		echo '<h1>' . esc_html__( 'Terminals', 'ffcertificate' ) . '</h1>';
		\FreeFormCertificate\Core\AdminNotice::settings_errors( 'ffc_terminals' );

		$editing = null;
		foreach ( $terminals as $terminal ) {
			if ( $terminal['id'] === $edit && in_array( self::state( $terminal, $now ), array( self::STATE_ONLINE, self::STATE_OFFLINE ), true ) ) {
				$editing = $terminal;
			}
		}

		if ( null !== $editing ) {
			$this->render_editor( $editing, $now );
		} else {
			$this->render_stats( $terminals, $now );
			$this->render_list( $terminals, $all, $now );
		}
		echo '</div>';
	}

	/**
	 * The terminals one person may see: all of them, or those they paired.
	 *
	 * @param array    $terminals Terminal rows.
	 * @param int|null $user_id   Who is looking, or null for an administrator.
	 * @phpstan-param list<TerminalRow> $terminals
	 * @return list<TerminalRow>
	 */
	public static function visible( array $terminals, ?int $user_id ): array {
		if ( null === $user_id ) {
			return $terminals;
		}
		return array_values( array_filter( $terminals, static fn( array $t ): bool => $t['paired_by'] === $user_id ) );
	}

	/**
	 * A terminal's state.
	 *
	 * @param array $terminal Terminal row.
	 * @param int   $now      Unix time.
	 * @phpstan-param TerminalRow $terminal
	 * @return string One of the `STATE_*` constants.
	 */
	public static function state( array $terminal, int $now ): string {
		if ( null !== $terminal['revoked_at'] ) {
			return self::STATE_REVOKED;
		}
		if ( $terminal['expires_at'] <= $now ) {
			return self::STATE_EXPIRED;
		}
		return null !== $terminal['last_seen_at'] && $now - $terminal['last_seen_at'] <= self::ONLINE_SECONDS ? self::STATE_ONLINE : self::STATE_OFFLINE;
	}

	/**
	 * A state in words.
	 *
	 * @param string $state One of the `STATE_*` constants.
	 * @return string
	 */
	public static function state_label( string $state ): string {
		$labels = array(
			self::STATE_ONLINE  => __( 'Online', 'ffcertificate' ),
			self::STATE_OFFLINE => __( 'Offline', 'ffcertificate' ),
			self::STATE_EXPIRED => __( 'Ended automatically', 'ffcertificate' ),
			self::STATE_REVOKED => __( 'Ended by hand', 'ffcertificate' ),
		);
		return $labels[ $state ] ?? $state;
	}

	/**
	 * The stat cards.
	 *
	 * @param array $terminals Terminal rows.
	 * @param int   $now       Unix time.
	 * @phpstan-param list<TerminalRow> $terminals
	 * @return void
	 */
	private function render_stats( array $terminals, int $now ): void {
		$counts = array(
			self::STATE_ONLINE  => 0,
			self::STATE_OFFLINE => 0,
		);
		$queued = 0;
		foreach ( $terminals as $terminal ) {
			$state = self::state( $terminal, $now );
			if ( isset( $counts[ $state ] ) ) {
				++$counts[ $state ];
				$queued += $terminal['queued'];
			}
		}
		$today = (string) wp_date( 'Y-m-d', $now );

		echo '<div class="ffc-stats">';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_stat_card() escapes every value.
		echo AdminUI::get_stat_card(
			array(
				'label' => __( 'Online', 'ffcertificate' ),
				'value' => $counts[ self::STATE_ONLINE ],
				'icon'  => 'monitor',
			)
		);
		echo AdminUI::get_stat_card(
			array(
				'label' => __( 'Offline', 'ffcertificate' ),
				'value' => $counts[ self::STATE_OFFLINE ],
				'icon'  => 'cloud',
			)
		);
		echo AdminUI::get_stat_card(
			array(
				'label' => __( 'Readings today', 'ffcertificate' ),
				'value' => AudienceAttendanceReader::count_by_method_on( AudienceAttendanceWriter::METHOD_TERMINAL, $today ),
				'icon'  => 'user-check',
			)
		);
		echo AdminUI::get_stat_card(
			array(
				'label' => __( 'Readings waiting to be sent', 'ffcertificate' ),
				'value' => $queued,
				'icon'  => 'sync',
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	/**
	 * The terminals table.
	 *
	 * @param array $terminals Terminal rows.
	 * @param bool  $all       Whether the list holds every terminal.
	 * @param int   $now       Unix time.
	 * @phpstan-param list<TerminalRow> $terminals
	 * @return void
	 */
	private function render_list( array $terminals, bool $all, int $now ): void {
		echo '<div class="card ffc-terminals-list">';
		echo '<h2 class="ffc-icon-monitor">' . esc_html__( 'Terminals', 'ffcertificate' ) . '</h2>';
		echo '<p class="description">' . esc_html(
			$all
				/* translators: %d: number of days */
				? sprintf( __( 'Every active terminal on the site, and those that ended in the last %d days. Pair a terminal in the app, under More → Pair terminal.', 'ffcertificate' ), self::KEEP_DAYS )
				/* translators: %d: number of days */
				: sprintf( __( 'The active terminals you paired, and those that ended in the last %d days. Pair a terminal in the app, under More → Pair terminal.', 'ffcertificate' ), self::KEEP_DAYS )
		) . '</p>';
		if ( array() === $terminals ) {
			echo '<p>' . esc_html__( 'No terminal is paired.', 'ffcertificate' ) . '</p></div>';
			return;
		}

		echo '<div class="ffc-terminals-scroll"><table class="widefat striped ffc-terminals-table"><thead><tr>';
		foreach ( array(
			__( 'Name', 'ffcertificate' ),
			__( 'Events', 'ffcertificate' ),
			__( 'Current event', 'ffcertificate' ),
			__( 'Paired by', 'ffcertificate' ),
			__( 'Last seen', 'ffcertificate' ),
			__( 'Readings', 'ffcertificate' ),
			__( 'State', 'ffcertificate' ),
			__( 'Actions', 'ffcertificate' ),
		) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $terminals as $terminal ) {
			$state  = self::state( $terminal, $now );
			$active = in_array( $state, array( self::STATE_ONLINE, self::STATE_OFFLINE ), true );
			echo '<tr>';
			/* translators: %d: terminal number */
			echo '<td>' . esc_html( '' !== $terminal['label'] ? $terminal['label'] : sprintf( __( 'Terminal %d', 'ffcertificate' ), $terminal['id'] ) ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', array_map( array( self::class, 'event_title' ), $terminal['bookings'] ) ) ) . '</td>';
			echo '<td>' . esc_html( $active ? self::current_event( $terminal, $now ) : '—' ) . '</td>';
			echo '<td>' . esc_html( null === $terminal['paired_by'] ? '—' : AudienceAdminPresence::user_label( $terminal['paired_by'] ) ) . '</td>';
			echo '<td>' . esc_html( null === $terminal['last_seen_at'] ? __( 'Not used yet', 'ffcertificate' ) : DateFormatter::format_datetime( $terminal['last_seen_at'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) $terminal['readings'] );
			if ( $active && $terminal['queued'] > 0 ) {
				/* translators: %d: number of readings waiting to be sent */
				echo '<br><span class="description">' . esc_html( sprintf( _n( '%d waiting to be sent', '%d waiting to be sent', $terminal['queued'], 'ffcertificate' ), $terminal['queued'] ) ) . '</span>';
			}
			echo '</td>';
			echo '<td><span class="ffc-terminal-state ffc-terminal-state-' . esc_attr( $state ) . '">' . esc_html( self::state_label( $state ) ) . '</span></td>';
			echo '<td><div class="ffc-terminals-actions">';
			if ( $active ) {
				AdminUI::render_button(
					array(
						'label'    => __( 'Edit events', 'ffcertificate' ),
						'category' => 'neutral',
						'icon'     => 'edit',
						'href'     => $this->url( array( 'edit' => $terminal['id'] ) ),
					)
				);
				$this->action_form( 'end', $terminal['id'], __( 'End', 'ffcertificate' ), 'danger', 'cross', __( 'End this terminal? It stops checking people in at once, and pairing it again needs a new code.', 'ffcertificate' ) );
			}
			echo '</div></td></tr>';
		}
		echo '</tbody></table></div></div>';
	}

	/**
	 * The event editor for one terminal.
	 *
	 * @param array $terminal Terminal row.
	 * @param int   $now      Unix time.
	 * @phpstan-param TerminalRow $terminal
	 * @return void
	 */
	private function render_editor( array $terminal, int $now ): void {
		AdminUI::render_button(
			array(
				'label'    => __( 'Back to terminals', 'ffcertificate' ),
				'category' => 'neutral',
				'icon'     => 'chevron-left',
				'href'     => $this->url(),
			)
		);
		$events = AudienceTerminalRestController::offered_bookings( $now );

		echo '<div class="card ffc-terminals-editor">';
		/* translators: %s: terminal name */
		echo '<h2 class="ffc-icon-edit">' . esc_html( sprintf( __( 'Events of %s', 'ffcertificate' ), '' !== $terminal['label'] ? $terminal['label'] : sprintf( __( 'Terminal %d', 'ffcertificate' ), $terminal['id'] ) ) ) . '</h2>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: maximum number of events, 2: number of days */
				__( 'Up to %1$d events, each ending within the next %2$d days, and no two whose check-in windows cross. The terminal picks the change up within a minute. An event you remove keeps the readings already taken for it.', 'ffcertificate' ),
				AudienceTerminalPairing::MAX_BOOKINGS,
				AudienceTerminalPairing::DAYS_AHEAD
			)
		) . '</p>';
		echo '<form method="post" action="' . esc_url( $this->url() ) . '">';
		wp_nonce_field( self::NONCE, 'ffc_terminals_nonce' );
		echo '<input type="hidden" name="ffc_action" value="terminals_admin">';
		echo '<input type="hidden" name="op" value="events">';
		echo '<input type="hidden" name="terminal" value="' . esc_attr( (string) $terminal['id'] ) . '">';
		echo '<fieldset class="ffc-terminals-events"><legend class="screen-reader-text">' . esc_html__( 'Events this terminal checks in', 'ffcertificate' ) . '</legend>';
		if ( array() === $events ) {
			echo '<p>' . esc_html__( 'No event in the next 7 days can take a terminal.', 'ffcertificate' ) . '</p>';
		}
		foreach ( $events as $event ) {
			$held = in_array( $event['id'], $terminal['bookings'], true );
			echo '<label class="ffc-terminals-event"><input type="checkbox" name="bookings[]" value="' . esc_attr( (string) $event['id'] ) . '"' . checked( $held, true, false ) . disabled( $event['full'] && ! $held, true, false ) . '> ';
			echo '<span><strong>' . esc_html( $event['description'] ) . '</strong><br><span class="description">' . esc_html(
				implode(
					' · ',
					array_filter(
						array(
							$event['when'],
							$event['place'],
							/* translators: %d: number of terminals already paired for the event */
							$event['full'] && ! $held ? __( 'Terminal limit reached', 'ffcertificate' ) : sprintf( __( 'Terminals: %d', 'ffcertificate' ), $event['terminals'] ),
						)
					)
				)
			) . '</span></span></label>';
		}
		echo '</fieldset>';
		AdminUI::render_button(
			array(
				'label'    => __( 'Save events', 'ffcertificate' ),
				'category' => 'primary',
				'icon'     => 'save',
				'type'     => 'submit',
			)
		);
		echo '</form></div>';
	}

	/**
	 * What an event is called.
	 *
	 * @param int $booking_id Booking.
	 * @return string
	 */
	public static function event_title( int $booking_id ): string {
		$booking = AudienceBookingReader::get_by_id( $booking_id );
		$title   = null === $booking ? '' : trim( (string) ( $booking->description ?? '' ) );
		/* translators: %d: event id */
		return '' !== $title ? $title : sprintf( __( 'Event #%d', 'ffcertificate' ), $booking_id );
	}

	/**
	 * The event a terminal checks in now: the one whose window holds the
	 * moment, preferring a window an organiser opened.
	 *
	 * @param array $terminal Terminal row.
	 * @param int   $now      Unix time.
	 * @phpstan-param TerminalRow $terminal
	 * @return string
	 */
	public static function current_event( array $terminal, int $now ): string {
		$current = null;
		foreach ( AudienceTerminalSchedule::plan( $terminal, $now ) as $entry ) {
			if ( $entry['opens'] <= $now && $now < $entry['closes'] && ( null === $current || ( $entry['open'] && ! $current['open'] ) ) ) {
				$current = $entry;
			}
		}
		return null === $current ? __( 'None now', 'ffcertificate' ) : $current['title'];
	}

	/**
	 * One action as a form of its own.
	 *
	 * @param string $op       Operation.
	 * @param int    $terminal Terminal id.
	 * @param string $label    Button label.
	 * @param string $category Button category.
	 * @param string $icon     Button icon.
	 * @param string $confirm  A question to confirm first, or '' for none.
	 * @return void
	 */
	private function action_form( string $op, int $terminal, string $label, string $category, string $icon, string $confirm ): void {
		echo '<form method="post" class="ffc-terminals-action" action="' . esc_url( $this->url() ) . '">';
		wp_nonce_field( self::NONCE, 'ffc_terminals_nonce' );
		echo '<input type="hidden" name="ffc_action" value="terminals_admin">';
		echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
		echo '<input type="hidden" name="terminal" value="' . esc_attr( (string) $terminal ) . '">';
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
}
