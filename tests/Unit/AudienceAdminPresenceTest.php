<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceAdminPresence;
use FreeFormCertificate\Audience\AudiencePresenceAttestation;
use FreeFormCertificate\Audience\AudiencePresenceEnrolment;
use FreeFormCertificate\Audience\AudiencePresenceReader;
use FreeFormCertificate\Audience\AudiencePresenceWriter;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use FreeFormCertificate\Tests\Support\FakeCredentials;
use FreeFormCertificate\Tests\Support\FakePresenceTables;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The Presence devices screen (#2743): what it counts, what it asks an
 * administrator to answer, and what each answer changes, against tables that
 * keep what they are given.
 *
 * @covers \FreeFormCertificate\Audience\AudienceAdminPresence
 * @covers \FreeFormCertificate\Audience\AudiencePresenceReader
 * @covers \FreeFormCertificate\Audience\AudiencePresenceWriter
 */
class AudienceAdminPresenceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private int $now;

	private const DAY = 86400;

	private const ADMIN = 1;

	private FakePresenceTables $tables;

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var mixed */
	private $wpdb_backup;

	private bool $had_wpdb = false;

	private AudienceAdminPresence $screen;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->now     = time();
		$this->tables  = new FakePresenceTables();
		$this->options = array(
			'ffc_settings' => array_merge(
				ActivityLogBuffer::ENABLED_SETTINGS,
				array(
					'date_format' => 'Y-m-d',
					'time_format' => 'H:i',
				)
			),
		);

		ActivityLogBuffer::stub_functions();
		ActivityLogBuffer::reset();

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === (int) $n ? $single : $plural );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
		Functions\when( 'esc_attr' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( FakeCredentials::WP_SALT );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $ts ) => gmdate( $format, $ts ) );
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.org/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url = '' ) {
				if ( is_string( $args ) ) {
					return $url . '&' . $args;
				}
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_nonce_field' )->alias(
			static function () {
				echo '<input type="hidden" name="ffc_presence_nonce" value="nonce">';
			}
		);
		Functions\when( 'paginate_links' )->justReturn( '<a class="page-numbers">2</a>' );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) {
				if ( ! in_array( (int) $id, array( 7, 8, 9 ), true ) ) {
					return false;
				}
				$user               = new \WP_User( (int) $id );
				$user->display_name = array( 7 => 'Maria Silva', 8 => 'João Lima', 9 => 'Ana Costa' )[ (int) $id ];
				return $user;
			}
		);

		$this->had_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		if ( $this->had_wpdb ) {
			$this->wpdb_backup = $GLOBALS['wpdb'];
		}
		$GLOBALS['wpdb'] = $this->tables;

		$this->screen = new AudienceAdminPresence( 'ffc-scheduling' );
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$_GET  = array();
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---- Actions ----------------------------------------------------------

	public function test_deactivate_revokes_the_key_so_terminals_refuse_it_and_logs_who(): void {
		$id = $this->device( 7, 'phone-0000000000000001', 'kid-a', $this->now - self::DAY );

		$this->assertSame( 'deactivated', $this->screen->run( 'deactivate', (string) $id, self::ADMIN, $this->now ) );

		$row = AudiencePresenceReader::by_id( $id );
		$this->assertSame( $this->now, $row['revoked_at'] );
		$this->assertSame( AudiencePresenceWriter::REVOKED_ADMIN, $row['revoked_reason'] );
		$this->assertSame( self::ADMIN, $row['revoked_by'] );
		$this->assertContains( 'kid-a', AudiencePresenceReader::revoked_key_ids_since( $this->now - AudiencePresenceAttestation::TTL ) );

		$log = ActivityLogBuffer::contexts();
		$this->assertSame( 'presence_device_revoked', $log[0]['action'] );
		$this->assertSame( 7, $log[0]['context']['target_user_id'] );
		$this->assertSame( 'admin', $log[0]['context']['reason'] );

		$this->assertSame( 'stale', $this->screen->run( 'deactivate', (string) $id, self::ADMIN, $this->now ), 'a second click changes nothing' );
		$this->assertSame( 'stale', $this->screen->run( 'deactivate', '999', self::ADMIN, $this->now ) );
	}

	public function test_releasing_a_reserved_phone_frees_it_for_the_account_that_asked(): void {
		$this->device( 8, 'shared-phone-00000001', 'kid-b', $this->now - self::DAY );
		$request = AudiencePresenceWriter::request( 7, 'shared-phone-00000001', AudiencePresenceEnrolment::REASON_RESERVED, $this->now - 60 );
		$this->assertSame( 8, AudiencePresenceReader::reserving_user( 'shared-phone-00000001', 7, $this->now ) );

		$this->assertSame( 'released', $this->screen->run( 'approve', (string) $request, self::ADMIN, $this->now ) );

		$this->assertNull( AudiencePresenceReader::reserving_user( 'shared-phone-00000001', 7, $this->now ) );
		$this->assertSame( AudiencePresenceWriter::REQUEST_APPROVED, AudiencePresenceReader::request( $request )['status'] );
		$this->assertSame( self::ADMIN, AudiencePresenceReader::request( $request )['decided_by'] );
		$this->assertSame( array( 'presence_device_released' ), array_column( ActivityLogBuffer::contexts(), 'action' ) );
		$this->assertSame( 1, ActivityLogBuffer::contexts()[0]['context']['released'] );
		$this->assertSame( 'stale', $this->screen->run( 'refuse', (string) $request, self::ADMIN, $this->now ), 'an answered request takes no second answer' );
	}

	public function test_allowing_one_more_change_approves_the_request_the_enrolment_uses(): void {
		$request = AudiencePresenceWriter::request( 7, 'fifth-phone-000000001', AudiencePresenceEnrolment::REASON_CHANGE_LIMIT, $this->now - 60 );

		$this->assertSame( 'approved', $this->screen->run( 'approve', (string) $request, self::ADMIN, $this->now ) );

		$this->assertSame( $request, AudiencePresenceReader::request_id( 7, 'fifth-phone-000000001', AudiencePresenceEnrolment::REASON_CHANGE_LIMIT, AudiencePresenceWriter::REQUEST_APPROVED ) );
		$this->assertSame( array( 'presence_request_approved' ), array_column( ActivityLogBuffer::contexts(), 'action' ) );
	}

	public function test_refusing_a_request_keeps_the_device_reserved_and_logs_it(): void {
		$this->device( 8, 'shared-phone-00000001', 'kid-b', $this->now - self::DAY );
		$request = AudiencePresenceWriter::request( 7, 'shared-phone-00000001', AudiencePresenceEnrolment::REASON_RESERVED, $this->now - 60 );

		$this->assertSame( 'refused', $this->screen->run( 'refuse', (string) $request, self::ADMIN, $this->now ) );

		$this->assertSame( 8, AudiencePresenceReader::reserving_user( 'shared-phone-00000001', 7, $this->now ) );
		$this->assertSame( AudiencePresenceWriter::REQUEST_REFUSED, AudiencePresenceReader::request( $request )['status'] );
		$this->assertSame( array( 'presence_request_refused' ), array_column( ActivityLogBuffer::contexts(), 'action' ) );
		$this->assertSame( array(), AudiencePresenceReader::pending_requests() );
	}

	public function test_a_decision_on_a_request_that_does_not_exist_or_an_unknown_action_changes_nothing(): void {
		$this->assertSame( 'stale', $this->screen->run( 'approve', '999', self::ADMIN, $this->now ) );
		$this->assertSame( 'unknown', $this->screen->run( 'delete-everything', '1', self::ADMIN, $this->now ) );
		$this->assertFalse( AudiencePresenceWriter::decide_request( 1, 'used', self::ADMIN, $this->now ), 'only approved or refused' );
		$this->assertSame( array(), ActivityLogBuffer::contexts() );
	}

	public function test_signals_shared_by_two_accounts_form_an_alert_until_dismissed(): void {
		$hash = str_repeat( 'ab', 32 );
		$this->device( 7, 'school-phone-0000001', 'kid-a', $this->now - 2 * self::DAY, $hash );
		$this->device( 8, 'school-phone-0000002', 'kid-b', $this->now - self::DAY, $hash );
		$this->device( 9, 'own-phone-0000000003', 'kid-c', $this->now - self::DAY, str_repeat( 'cd', 32 ) );

		$groups = AudiencePresenceReader::signal_groups();
		$this->assertCount( 1, $groups );
		$this->assertSame( $hash, $groups[0]['signals_hash'] );
		$this->assertSame( array( 7, 8 ), $groups[0]['user_ids'] );

		$this->assertSame( 'dismissed', $this->screen->run( 'dismiss', $hash, self::ADMIN, $this->now ) );
		$this->assertSame( array(), AudiencePresenceReader::signal_groups() );
		$this->assertSame( 2, ActivityLogBuffer::contexts()[0]['context']['devices'] );
		$this->assertSame( 'stale', $this->screen->run( 'dismiss', $hash, self::ADMIN, $this->now ) );
		$this->assertSame( 'stale', $this->screen->run( 'dismiss', '<script>', self::ADMIN, $this->now ) );

		// A new phone with the same signals raises it again.
		$this->device( 9, 'school-phone-0000003', 'kid-d', $this->now, $hash );
		$this->assertCount( 1, AudiencePresenceReader::signal_groups() );
	}

	// ---- What the screen shows ---------------------------------------------

	public function test_the_overview_counts_and_lists_and_asks_what_needs_attention(): void {
		$this->grant( true );
		$hash = str_repeat( 'ab', 32 );
		AudiencePresenceWriter::activate( 7, 'school-phone-0000001', 'kid-a', 'public', $hash, true, $this->now - 2 * self::DAY, 'Android', 'Chrome' );
		$this->device( 8, 'school-phone-0000002', 'kid-b', $this->now - self::DAY, $hash );
		AudiencePresenceWriter::request( 9, 'school-phone-0000001', AudiencePresenceEnrolment::REASON_RESERVED, $this->now - 60 );
		AudiencePresenceWriter::request( 9, 'fifth-phone-000000001', AudiencePresenceEnrolment::REASON_CHANGE_LIMIT, $this->now - 30 );

		$html = $this->render();

		$this->assertStringContainsString( 'ffc-page-scheduling-presence', $html );
		$this->assertMatchesRegularExpression( '/Active devices.*?2/s', $html );
		$this->assertStringContainsString( 'Release requests', $html );
		$this->assertStringContainsString( 'Same-signal alerts', $html );
		$this->assertStringContainsString( 'Maria Silva', $html );
		$this->assertStringContainsString( 'Android · Chrome <code>…-0000001</code>', $html, 'the phone is named by its families, with its id beside them' );
		$this->assertStringContainsString( '<code>…-0000002</code>', $html, 'a phone without known families keeps the id alone' );
		$this->assertStringContainsString( '1 of 3', $html, 'changes read against the limit' );
		$this->assertStringContainsString( gmdate( 'Y-m-d', $this->now - 2 * self::DAY + AudiencePresenceAttestation::TTL ), $html, 'works offline until the attestation expires' );
		$this->assertLessThan( strpos( $html, 'ffc-presence-devices' ), strpos( $html, 'ffc-presence-attention' ), '"Needs attention" comes above the list' );
		$this->assertStringContainsString( 'Phone of another account', $html );
		$this->assertStringContainsString( 'Ana Costa tried to activate presence on a phone Maria Silva still uses.', $html );
		$this->assertStringContainsString( 'Release to Ana Costa', $html );
		$this->assertStringContainsString( 'Change limit', $html );
		$this->assertStringContainsString( 'Ana Costa asked for device change number 4 in 30 days.', $html );
		$this->assertStringContainsString( 'Alert · same signals', $html );
		$this->assertStringContainsString( 'The devices of Maria Silva, João Lima report the same signals.', $html );
		$this->assertStringContainsString( 'value="dismiss"', $html );
		$this->assertStringContainsString( 'value="' . $hash . '"', $html );
		$this->assertStringContainsString( 'data-confirm=', $html, 'deactivating asks first' );
		$this->assertStringContainsString( 'terminals refuse its code from their next sync', $html );
		$this->assertStringContainsString( 'name="s"', $html, 'the list can be searched' );
		$this->assertSame( 3, substr_count( $html, 'value="deactivate"' ) + substr_count( $html, 'value="approve"' ) - 1, 'one deactivate per device and one release per request' );
	}

	public function test_a_reserved_phone_names_its_account_and_when_the_reservation_ends(): void {
		$this->grant( true );
		$old = $this->device( 7, 'school-phone-0000001', 'kid-a', $this->now - 10 * self::DAY );
		AudiencePresenceWriter::revoke( $old, AudiencePresenceWriter::REVOKED_REPLACED, null, $this->now - 5 * self::DAY );
		AudiencePresenceWriter::request( 9, 'school-phone-0000001', AudiencePresenceEnrolment::REASON_RESERVED, $this->now - 60 );

		$reservation = AudiencePresenceReader::reservation( 'school-phone-0000001', 9, $this->now );
		$this->assertSame( array( 'user_id' => 7, 'until' => $this->now - 5 * self::DAY + AudiencePresenceEnrolment::RESERVATION ), $reservation );
		$this->assertSame( 7, AudiencePresenceReader::reserving_user( 'school-phone-0000001', 9, $this->now ) );
		$this->assertNull( AudiencePresenceReader::reservation( 'school-phone-0000001', 7, $this->now ), 'never reserved against its own account' );

		$html = $this->render();
		$this->assertStringContainsString(
			'Ana Costa tried to activate presence on a phone reserved to Maria Silva until ' . gmdate( 'Y-m-d', $this->now - 5 * self::DAY + AudiencePresenceEnrolment::RESERVATION ) . '.',
			$html
		);
	}

	public function test_the_user_search_narrows_the_list_and_says_when_nothing_matches(): void {
		$this->grant( true );
		$this->device( 7, 'phone-maria-00000001', 'kid-a', $this->now - self::DAY );
		$this->device( 8, 'phone-joao-000000002', 'kid-b', $this->now - self::DAY );
		Functions\when( 'get_users' )->alias(
			static function ( array $args ) {
				return '*joão*' === $args['search'] ? array( '8' ) : array();
			}
		);

		$_GET['s'] = 'joão';
		$html      = $this->render();
		$this->assertStringContainsString( '…00000002', $html );
		$this->assertStringNotContainsString( '…00000001', $html );
		$this->assertStringContainsString( 'value="joão"', $html );
		$this->assertSame( array( 8 ), array_column( AudiencePresenceReader::active_devices( 10, 0, array( 8 ) ), 'user_id' ) );
		$this->assertSame( 1, AudiencePresenceReader::count_active( array( 8, 9 ) ) );

		$_GET['s'] = 'nobody';
		$html      = $this->render();
		$this->assertStringContainsString( 'No active device belongs to a user matching &quot;nobody&quot;.', $html );
		$this->assertStringNotContainsString( '<table', $html );
		$this->assertSame( array(), AudiencePresenceReader::active_devices( 10, 0, array() ) );
		$this->assertSame( 0, AudiencePresenceReader::count_active( array() ) );
	}

	public function test_an_empty_install_says_so_instead_of_drawing_an_empty_table(): void {
		$this->grant( true );

		$html = $this->render();

		$this->assertStringContainsString( 'No participant has activated a phone yet.', $html );
		$this->assertStringNotContainsString( 'Needs attention', $html, 'nothing waits, so nothing asks' );
		$this->assertStringNotContainsString( '<table', $html );
	}

	public function test_a_participants_history_shows_every_device_and_why_it_stopped(): void {
		$this->grant( true );
		$old = $this->device( 7, 'old-phone-0000000001', 'kid-old', $this->now - 20 * self::DAY );
		AudiencePresenceWriter::revoke( $old, AudiencePresenceWriter::REVOKED_REPLACED, null, $this->now - 10 * self::DAY );
		$gone = $this->device( 7, 'lost-phone-000000002', 'kid-lost', $this->now - 10 * self::DAY, null, true );
		AudiencePresenceWriter::revoke( $gone, AudiencePresenceWriter::REVOKED_ADMIN, self::ADMIN, $this->now - 5 * self::DAY );
		$this->device( 7, 'new-phone-0000000003', 'kid-new', $this->now - 5 * self::DAY, null, true );
		$_GET['user'] = '7';

		$html = $this->render();

		$this->assertStringContainsString( 'Device history: Maria Silva', $html );
		$this->assertStringContainsString( '2 device changes in the last 30 days.', $html );
		$this->assertStringContainsString( 'Replaced by another phone on', $html );
		$this->assertStringContainsString( 'Deactivated by an administrator on', $html );
		$this->assertSame( 1, substr_count( $html, 'value="deactivate"' ), 'only the active device can be deactivated' );
		$this->assertStringContainsString( 'name="return_user" value="7"', $html );
	}

	public function test_the_history_names_each_phone_once_with_its_latest_activation(): void {
		$this->grant( true );
		$first = $this->device( 7, 'same-phone-000000001', 'kid-1', $this->now - 3 * self::DAY );
		AudiencePresenceWriter::revoke( $first, AudiencePresenceWriter::REVOKED_REPLACED, null, $this->now - 2 * self::DAY );
		$second = $this->device( 7, 'same-phone-000000001', 'kid-2', $this->now - 2 * self::DAY );
		AudiencePresenceWriter::revoke( $second, AudiencePresenceWriter::REVOKED_REPLACED, null, $this->now - self::DAY );
		$this->device( 7, 'same-phone-000000001', 'kid-3', $this->now - self::DAY );
		$_GET['user'] = '7';

		$html = $this->render();

		$this->assertSame( 1, substr_count( $html, '<td><code>' ), 'three keys on one phone are one row' );
		$this->assertStringNotContainsString( 'Replaced by another phone', $html );
		$this->assertSame( 1, substr_count( $html, 'value="deactivate"' ), 'the row is the active key' );
	}

	public function test_a_participant_without_devices_has_an_empty_history(): void {
		$this->grant( true );
		$_GET['user'] = '42';

		$html = $this->render();

		$this->assertStringContainsString( 'Device history: Account #42', $html );
		$this->assertStringContainsString( 'never activated a phone', $html );
	}

	public function test_the_screen_is_closed_without_the_capability(): void {
		$this->grant( false );
		Functions\expect( 'wp_die' )->once()->andThrow( new \RuntimeException( 'denied' ) );

		$this->expectExceptionMessage( 'denied' );
		$this->render();
	}

	public function test_an_expired_attestation_reads_as_renewing_when_online(): void {
		$device = array(
			'activated_at' => $this->now - 30 * self::DAY,
			'attested_at'  => null,
		);
		$this->assertSame( 'Expired on ' . gmdate( 'Y-m-d', $this->now - 23 * self::DAY ) . '; renews when the app goes online', AudienceAdminPresence::offline_until( $device, $this->now ) );
		$device['attested_at'] = $this->now - self::DAY;
		$this->assertSame( gmdate( 'Y-m-d', $this->now + 6 * self::DAY ), AudienceAdminPresence::offline_until( $device, $this->now ) );
	}

	public function test_a_renewal_moves_the_offline_until_date(): void {
		$id = $this->device( 7, 'phone-0000000000000001', 'kid-a', $this->now - 6 * self::DAY );
		$this->assertTrue( AudiencePresenceWriter::attested( $id, $this->now ) );
		$this->assertSame( $this->now, AudiencePresenceReader::by_id( $id )['attested_at'] );
		$this->assertFalse( AudiencePresenceWriter::attested( 999, $this->now ) );
	}

	// ---- Request handling -------------------------------------------------

	public function test_a_posted_action_needs_the_capability_and_the_nonce(): void {
		$this->grant( false );
		$_GET  = array( 'page' => 'ffc-scheduling-presence' );
		$_POST = array( 'ffc_action' => 'presence_admin' );
		Functions\expect( 'wp_verify_nonce' )->never();
		$this->screen->handle_actions();

		$this->grant( true );
		Monkey\tearDown();
		Monkey\setUp();
		$this->setUp();
		$this->grant( true );
		$_GET  = array( 'page' => 'ffc-scheduling-presence' );
		$_POST = array(
			'ffc_action'         => 'presence_admin',
			'ffc_presence_nonce' => 'stale',
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\expect( 'wp_die' )->once()->andThrow( new \RuntimeException( 'expired' ) );
		$this->expectExceptionMessage( 'expired' );
		$this->screen->handle_actions();
	}

	public function test_a_valid_action_redirects_with_its_result(): void {
		$this->grant( true );
		$id    = $this->device( 7, 'phone-0000000000000001', 'kid-a', $this->now - self::DAY );
		$_GET  = array( 'page' => 'ffc-scheduling-presence' );
		$_POST = array(
			'ffc_action'         => 'presence_admin',
			'ffc_presence_nonce' => 'good',
			'op'                 => 'deactivate',
			'target'             => (string) $id,
			'return_user'        => '7',
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( self::ADMIN );
		$redirect = null;
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url ) use ( &$redirect ) {
				$redirect = $url;
				throw new \RuntimeException( 'redirected' );
			}
		);

		try {
			$this->screen->handle_actions();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}
		$this->assertStringContainsString( 'message=deactivated', (string) $redirect );
		$this->assertStringContainsString( 'user=7', (string) $redirect );
		$this->assertNotNull( AudiencePresenceReader::by_id( $id )['revoked_at'] );
	}

	public function test_a_result_code_becomes_a_notice_and_other_screens_are_left_alone(): void {
		$this->grant( true );
		$notices = array();
		Functions\when( 'add_settings_error' )->alias(
			static function ( $setting, $code, $message, $type ) use ( &$notices ) {
				$notices[] = array( $code, $type );
			}
		);
		$_GET = array(
			'page'    => 'ffc-scheduling-bookings',
			'message' => 'deactivated',
		);
		$this->screen->handle_actions();
		$this->assertSame( array(), $notices );

		foreach ( array( 'deactivated', 'released', 'approved', 'refused', 'dismissed', 'stale', 'failed', 'nonsense' ) as $code ) {
			$_GET = array(
				'page'    => 'ffc-scheduling-presence',
				'message' => $code,
			);
			$this->screen->handle_actions();
		}
		$this->assertCount( 7, $notices );
		$this->assertSame( array( 'ffc_presence_stale', 'warning' ), $notices[5] );
		$this->assertSame( array( 'ffc_presence_failed', 'error' ), $notices[6] );
	}

	public function test_names_and_labels(): void {
		$this->assertSame( '…12345678', AudienceAdminPresence::device_label( 'abcdefgh12345678' ) );
		$this->assertSame( 'Account #42', AudienceAdminPresence::user_label( 42 ) );
		$this->assertSame( 'Active', AudienceAdminPresence::status_label( array( 'revoked_at' => null, 'revoked_reason' => null ) ) );
		$this->assertStringContainsString( 'https://example.org/wp-admin/admin.php?page=ffc-scheduling-presence', $this->screen->url() );
	}

	// ---- Helpers ----------------------------------------------------------

	/**
	 * Insert an active device row.
	 *
	 * @param int         $user      User.
	 * @param string      $device    Device id.
	 * @param string      $key_id    Key id.
	 * @param int         $activated When it was activated.
	 * @param string|null $signals   Signals hash.
	 * @param bool        $change    Whether it counted as a change.
	 * @return int Row id.
	 */
	private function device( int $user, string $device, string $key_id, int $activated, ?string $signals = null, bool $change = false ): int {
		return AudiencePresenceWriter::activate( $user, $device, $key_id, 'public', $signals, $change, $activated );
	}

	/**
	 * Whether the current user holds the screen's capability.
	 *
	 * @param bool $can Whether they do.
	 */
	private function grant( bool $can ): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => $can && AudienceAdminPresence::CAP === $cap );
		Functions\when( 'get_settings_errors' )->justReturn( array() );
	}

	/**
	 * The screen's markup.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		try {
			$this->screen->render_page();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}
}
