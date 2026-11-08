<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\UserMessages;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The Messages tab's list (#2503), over an in-memory rules table and log.
 *
 * Rules: 1 an active announcement, 2 a disabled announcement, 3 an active
 * birthday rule. The person, user 5, is Ana.
 *
 * @covers \FreeFormCertificate\DateMessages\UserMessages
 * @covers \FreeFormCertificate\DateMessages\DeliveryLog
 */
class DateMessagesUserMessagesTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const NOW = 1794000000;

	/** @var array<int, array<string, mixed>> Rule rows by id. */
	private array $rules = array();

	/** @var list<array<string, mixed>> Log rows, newest first. */
	private array $log = array();

	/** @var array<string, mixed> Options. */
	private array $options = array();

	/** @var list<int> Ids opted out of announcements. */
	private array $opted_out = array();

	/** @var list<array<int, mixed>> Log queries prepared. */
	private array $log_queries = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options   = array();
		$this->opted_out = array();
		$this->rules     = array(
			1 => $this->rule( 1, 'announcement', 1, 'Reregistration opens', '<p>Hello {{first_name}}, your e-mail is {{email}}.</p>' ),
			2 => $this->rule( 2, 'announcement', 0, 'Old notice', '<p>Gone.</p>' ),
			3 => $this->rule( 3, 'dated', 1, 'Happy birthday, {{first_name}}', '<p>You turn {{age}} on {{date}}. <a href="{{unsubscribe_url}}">u</a></p>' ),
		);
		$this->log = array(
			array( 'rule_id' => '1', 'occurrence_key' => 'once', 'sent_at' => (string) ( self::NOW - 2 * DAY_IN_SECONDS ) ),
			array( 'rule_id' => '2', 'occurrence_key' => 'once', 'sent_at' => (string) ( self::NOW - 10 * DAY_IN_SECONDS ) ),
			array( 'rule_id' => '9', 'occurrence_key' => 'once', 'sent_at' => (string) ( self::NOW - 4 * DAY_IN_SECONDS ) ),
			array( 'rule_id' => '3', 'occurrence_key' => '2026-10-21', 'sent_at' => (string) ( self::NOW - 17 * DAY_IN_SECONDS ) ),
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( (string) $v ) );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'esc_html' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => strip_tags( (string) $v ) );
		Functions\when( 'wp_trim_words' )->alias( static fn( $v ) => (string) $v );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_email' )->alias( static fn( $e ) => false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'get_bloginfo' )->justReturn( 'SME' );
		Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.org' . $p );
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.org/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value = null, $url = null ) {
				return is_array( $key ) ? $value . '?' . http_build_query( $key ) : $url . '?' . $key . '=' . $value;
			}
		);
		Functions\when( 'get_permalink' )->justReturn( 'https://example.org/dashboard' );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'America/Sao_Paulo' ) );
		Functions\when( 'wp_date' )->alias( static fn( $f, $ts ) => gmdate( (string) $f, (int) $ts ) );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'get_userdata' )->alias(
			static fn( $id ) => 5 === (int) $id
				? (object) array(
					'ID'           => 5,
					'user_email'   => 'ana.lima@example.org',
					'display_name' => 'Ana Lima',
					'first_name'   => 'Ana',
					'last_name'    => 'Lima',
					'user_registered' => '2020-01-01 00:00:00',
				)
				: false
		);

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( ...$args ) => $args );
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $q ) {
				$table = is_array( $q ) ? ( $q[1] ?? '' ) : '';
				if ( 'wp_ffc_date_message_log' === $table ) {
					$this->log_queries[] = $q;
					return array_slice( $this->log, 0, (int) $q[4] );
				}
				if ( 'wp_ffc_date_message_rules' === $table ) {
					return array_values( $this->rules );
				}
				if ( 'wp_ffc_user_profiles' === $table || ( is_array( $q ) && false !== strpos( (string) $q[0], 'preferences' ) ) ) {
					// OptOut::among(): the people who turned announcements off.
					return array_map(
						static fn( $id ) => array(
							'user_id'     => $id,
							'preferences' => '{"notify_date_announcements":false}',
						),
						$this->opted_out
					);
				}
				return array();
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturn( null );
		// UserManager::get_profile(): the profile table exists and holds no row.
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing( static fn( $q ) => is_array( $q ) ? ( $q[1] ?? null ) : null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A stored rule row.
	 */
	private function rule( int $id, string $kind, int $active, string $subject, string $body ): array {
		return array(
			'id'              => $id,
			'name'            => 'Rule ' . $id,
			'source'          => 'birthday',
			'offset_days'     => 0,
			'audience_ids'    => '[]',
			'subject'         => $subject,
			'body'            => $body,
			'appearance'      => null,
			'send_to_user'    => 1,
			'digest_enabled'  => 0,
			'digest_mode'     => 'summary',
			'digest_user_ids' => '[]',
			'is_active'       => $active,
			'kind'            => $kind,
			'send_at'         => 0,
			'respect_opt_out' => 1,
		);
	}

	/**
	 * @return list<string>
	 */
	private static function subjects( array $items ): array {
		return array_map( static fn( $m ) => (string) $m['subject'], $items );
	}

	public function test_announcements_inside_the_window_are_current_and_the_rest_earlier(): void {
		$messages = UserMessages::for_user( 5, false, self::NOW );

		$this->assertSame( array( 'Reregistration opens' ), self::subjects( $messages['current'] ) );
		$this->assertSame( array( 'Old notice', 'Happy birthday, Ana' ), self::subjects( $messages['earlier'] ), 'The disabled rule stays; the deleted one (9) and a date message never are current.' );
		$this->assertSame( 'announcement', $messages['current'][0]['kind'] );
		$this->assertSame( 'dated', $messages['earlier'][1]['kind'] );
		$this->assertSame( '3-2026-10-21', $messages['earlier'][1]['key'] );
	}

	public function test_the_window_follows_the_setting_and_ignores_a_value_it_does_not_offer(): void {
		$this->options[ UserMessages::SETTINGS_OPTION ] = array( 'dashboard_notice_days' => '2' );
		$this->assertSame( 2, UserMessages::notice_days() );
		$this->assertSame( array(), UserMessages::for_user( 5, false, self::NOW + 1 )['current'], 'Two days and a second ago is past a two-day window.' );

		$this->options[ UserMessages::SETTINGS_OPTION ] = array( 'dashboard_notice_days' => '3' );
		$this->assertSame( UserMessages::DEFAULT_NOTICE_DAYS, UserMessages::notice_days() );
	}

	public function test_hiding_disabled_rules_drops_what_they_sent(): void {
		$this->assertFalse( UserMessages::hide_disabled_rules(), 'Off until an administrator turns it on.' );

		$this->options[ UserMessages::SETTINGS_OPTION ] = array( 'hide_disabled_rules' => '1' );
		$messages = UserMessages::for_user( 5, false, self::NOW );

		$this->assertSame( array( 'Happy birthday, Ana' ), self::subjects( $messages['earlier'] ) );
	}

	public function test_the_text_is_the_rule_as_it_is_now_with_the_persons_values(): void {
		$this->rules[1]['subject'] = 'Reregistration opens on Monday';
		$messages = UserMessages::for_user( 5, false, self::NOW );

		$current = $messages['current'][0];
		$this->assertSame( 'Reregistration opens on Monday', $current['subject'], 'An edited rule shows its current text.' );
		$this->assertStringContainsString( 'Hello Ana, your e-mail is ana.lima@example.org.', $current['body'] );
		$this->assertSame( self::NOW - 2 * DAY_IN_SECONDS, $current['sent_at'] );
	}

	public function test_the_unsubscribe_link_leads_to_the_tab(): void {
		$birthday = UserMessages::for_user( 5, false, self::NOW )['earlier'][1];

		$this->assertStringContainsString( 'href="https://example.org/dashboard?tab=messages"', $birthday['body'] );
		$this->assertStringNotContainsString( 'ffc_date_messages_unsubscribe', $birthday['body'] );
	}

	public function test_another_reader_sees_the_email_and_the_source_values_masked(): void {
		$messages = UserMessages::for_user( 5, true, self::NOW );

		$this->assertStringNotContainsString( 'ana.lima@example.org', $messages['current'][0]['body'] );
		$this->assertStringContainsString( 'You turn *** on ***.', $messages['earlier'][1]['body'], 'The age and the birthday are the source\'s values.' );
		$this->assertStringContainsString( 'Ana', $messages['current'][0]['body'], 'The name is not masked: the dashboard names the person anyway.' );
	}

	public function test_nobody_sent_anything_reads_only_the_log(): void {
		$this->log = array();

		$this->assertSame( array( 'current' => array(), 'earlier' => array() ), UserMessages::for_user( 5, false, self::NOW ) );
		$this->assertCount( 1, $this->log_queries );
		$this->assertSame( 'user', $this->log_queries[0][3], 'Only deliveries: a failed send leaves no row, a counted decision lives on its own channel.' );
		$this->assertSame( 5, $this->log_queries[0][2] );
		$this->assertSame( UserMessages::MAX_DELIVERIES, $this->log_queries[0][4] );
	}

	public function test_a_missing_account_reads_nothing(): void {
		$this->assertSame( array( 'current' => array(), 'earlier' => array() ), UserMessages::for_user( 6, false, self::NOW ) );
	}

	public function test_notices_are_the_current_ones_at_most_three_unless_the_person_opted_out(): void {
		$current = array_fill( 0, 5, array( 'subject' => 'x' ) );
		$this->assertCount( UserMessages::MAX_NOTICES, UserMessages::notices( $current, 5 ) );
		$this->assertSame( array(), UserMessages::notices( array(), 5 ) );

		$this->opted_out = array( 5 );
		$this->assertSame( array(), UserMessages::notices( $current, 5 ), 'The tab keeps them; the top of the dashboard does not.' );
	}
}
