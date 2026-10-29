<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\OptOut;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Everyone receives date messages until they say otherwise (#1538).
 *
 * Every test runs in the shared process over a `$wpdb` double.
 *
 * @covers \FreeFormCertificate\DateMessages\OptOut
 */
class DateMessagesOptOutTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** Whether `$wpdb` was set before the test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_only_an_explicit_false_is_an_opt_out(): void {
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )->andReturn(
			array(
				array( 'user_id' => '1', 'preferences' => '{"notify_date_messages":false}' ),
				array( 'user_id' => '2', 'preferences' => '{"notify_date_messages":true}' ),
				array( 'user_id' => '3', 'preferences' => '{"notify_new_certificate":true}' ),
				array( 'user_id' => '4', 'preferences' => null ),
				array( 'user_id' => '5', 'preferences' => 'not json' ),
				array( 'user_id' => '6', 'preferences' => '{"notify_date_messages":0}' ),
			)
		);

		$this->assertSame( array( 1 => true ), OptOut::among( array( 1, 2, 3, 4, 5, 6 ) ) );
	}

	public function test_no_ids_means_no_query(): void {
		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->shouldReceive( 'get_results' )->never();

		$this->assertSame( array(), OptOut::among( array( 0, -3 ) ) );
	}

	/**
	 * Runs the real UserManager, UserProfileService and UserProfileRepository
	 * over the `$wpdb` double: the stored row carries other preferences, and
	 * what reaches `update()` is the stored set with only this key changed —
	 * which is what "sends only its own key" means once the merge is real.
	 */
	public function test_set_sends_only_its_own_key_and_leaves_the_merge_to_update_profile(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );

		$prepared = array();
		$updates  = array();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) use ( &$prepared ) {
				$prepared[] = array( (string) $sql, $args );
				return (string) ( count( $prepared ) - 1 );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $query ) use ( &$prepared ) {
				[ $sql, $args ] = $prepared[ (int) $query ];
				if ( 'SHOW TABLES LIKE %s' === $sql ) {
					return $args[0];
				}
				$this->assertSame( 'SELECT id FROM %i WHERE user_id = %d', $sql, 'Unexpected get_var() query.' );
				$this->assertSame( array( 'wp_ffc_user_profiles', 9 ), $args );
				return '3';
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $query ) use ( &$prepared ) {
				[ $sql, $args ] = $prepared[ (int) $query ];
				$this->assertSame( 'SELECT * FROM %i WHERE user_id = %d', $sql, 'Unexpected get_row() query.' );
				$this->assertSame( array( 'wp_ffc_user_profiles', 9 ), $args );
				return array(
					'id'           => '3',
					'user_id'      => '9',
					'display_name' => 'X',
					'phone'        => null,
					'department'   => null,
					'organization' => null,
					'notes'        => null,
					'preferences'  => '{"notify_appointment_reminder":false,"notify_date_announcements":true,"notify_date_messages":true}',
					'created_at'   => null,
					'updated_at'   => null,
				);
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			static function ( $table, $data, $where ) use ( &$updates ) {
				$updates[] = array( $table, $data, $where );
				return 1;
			}
		);

		$this->assertTrue( OptOut::set( 9, false ) );

		$this->assertCount( 1, $updates, 'One profile write.' );
		[ $table, $data, $where ] = $updates[0];
		$this->assertSame( 'wp_ffc_user_profiles', $table );
		$this->assertSame( array( 'user_id' => 9 ), $where );
		$this->assertSame( array( 'preferences' ), array_keys( $data ), 'Only the preferences column is written.' );
		$written = json_decode( $data['preferences'], true );
		ksort( $written );
		$this->assertSame(
			array(
				'notify_appointment_reminder' => false,
				'notify_date_announcements'   => true,
				'notify_date_messages'        => false,
			),
			$written,
			'Only the date-messages key changes; every other stored choice is kept.'
		);
	}
}
