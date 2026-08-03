<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Migrations\Strategies\KeyRotationMigrationStrategy;

/**
 * Tests for KeyRotationMigrationStrategy (S7b, #857): re-encrypts submissions +
 * appointments PII under the active key and rebuilds search hashes, keyed on the
 * key fingerprint.
 *
 * The wpdb mock inspects the (naively interpolated) query text so a single mock
 * can answer SHOW TABLES / SHOW COLUMNS / COUNT / SELECT / MAX deterministically
 * from in-memory seed rows. Options are backed by an in-memory store so cursor /
 * fingerprint / completion state transitions are observable.
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\KeyRotationMigrationStrategy
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class KeyRotationMigrationStrategyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const SUBMISSIONS  = 'wp_ffc_submissions';
	private const APPOINTMENTS = 'wp_ffc_self_scheduling_appointments';

	/** @var \Mockery\MockInterface&\stdClass */
	private $wpdb;

	/** @var KeyRotationMigrationStrategy */
	private KeyRotationMigrationStrategy $strategy;

	/** @var array<string, mixed> In-memory option store. */
	private array $options = array();

	/** @var array<string, array<int, array<string, mixed>>> Seed rows per table. */
	private array $rows = array();

	/** @var array<int, string> Columns to report as absent. */
	private array $missing_columns = array();

	/** @var array<int, array<string, mixed>> Captured wpdb->update() calls. */
	private array $captured_updates = array();

	/** @var int|false Return value of wpdb->update(). */
	private $update_return = 1;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->rows = array(
			self::SUBMISSIONS  => array(),
			self::APPOINTMENTS => array(),
		);

		global $wpdb;
		$wpdb              = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix      = 'wp_';
		$wpdb->last_error  = '';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v )->byDefault();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( array( $this, 'fake_prepare' ) )->byDefault();
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing( array( $this, 'fake_get_var' ) )->byDefault();
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( array( $this, 'fake_get_results' ) )->byDefault();
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where, $format = null, $where_format = null ) {
				$this->captured_updates[] = array(
					'table' => $table,
					'data'  => $data,
					'where' => $where,
				);
				return $this->update_return;
			}
		)->byDefault();
		$this->wpdb = $wpdb;

		// WP_Error alias for the Strategies namespace.
		if ( ! class_exists( 'FreeFormCertificate\Migrations\Strategies\WP_Error' ) ) {
			class_alias( 'WP_Error', 'FreeFormCertificate\Migrations\Strategies\WP_Error' );
		}

		Functions\when( '__' )->returnArg();
		Functions\when( 'FreeFormCertificate\Migrations\Strategies\__' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $t ) => $t instanceof \WP_Error );

		// Option store — global and Strategies-namespaced fallbacks.
		$get = function ( $key, $default = false ) {
			return $this->options[ $key ] ?? $default;
		};
		$set = function ( $key, $value, $autoload = null ) {
			$this->options[ $key ] = $value;
			return true;
		};
		Functions\when( 'get_option' )->alias( $get );
		Functions\when( 'update_option' )->alias( $set );
		Functions\when( 'FreeFormCertificate\Migrations\Strategies\get_option' )->alias( $get );
		Functions\when( 'FreeFormCertificate\Migrations\Strategies\update_option' )->alias( $set );

		// ActivityLog::log() short-circuits when the log is disabled.
		if ( ! class_exists( 'FreeFormCertificate\Settings\SettingsReader', false ) ) {
			$reader = Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' );
			$reader->shouldReceive( 'activity_log_enabled' )->andReturn( false )->byDefault();
			$reader->shouldReceive( 'activity_log_min_level' )->andReturn( 'info' )->byDefault();
			$reader->shouldReceive( 'activity_log_category_enabled' )->andReturn( true )->byDefault();
		}

		$this->strategy = new KeyRotationMigrationStrategy();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Query-inspecting wpdb doubles
	// ------------------------------------------------------------------

	/**
	 * Naive placeholder interpolation so the mock can branch on query text.
	 *
	 * @param string $query Query with %i/%s/%d placeholders.
	 * @param mixed  ...$args Bound values in order.
	 * @return string
	 */
	public function fake_prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[sdi]/', (string) $arg, $query, 1 ) ?? $query;
		}
		return $query;
	}

	/**
	 * Detect which known table a query targets.
	 *
	 * @param string $query Interpolated query.
	 * @return string
	 */
	private function detect_table( string $query ): string {
		if ( false !== strpos( $query, self::APPOINTMENTS ) ) {
			return self::APPOINTMENTS;
		}
		if ( false !== strpos( $query, self::SUBMISSIONS ) ) {
			return self::SUBMISSIONS;
		}
		return '';
	}

	/**
	 * @param string $query Interpolated query.
	 * @return mixed
	 */
	public function fake_get_var( string $query ) {
		if ( false !== strpos( $query, 'SHOW TABLES' ) ) {
			// table_exists() compares the result === table name.
			return $this->detect_table( $query );
		}

		$table = $this->detect_table( $query );
		$rows  = $this->rows[ $table ] ?? array();

		if ( false !== strpos( $query, 'MAX(id)' ) ) {
			$max = 0;
			foreach ( $rows as $r ) {
				$max = max( $max, (int) $r['id'] );
			}
			return $max;
		}

		if ( false !== strpos( $query, 'COUNT(*)' ) ) {
			if ( preg_match( '/id <= (\d+)/', $query, $m ) ) {
				$cursor = (int) $m[1];
				return count( array_filter( $rows, static fn( $r ) => (int) $r['id'] <= $cursor ) );
			}
			return count( $rows );
		}

		return null;
	}

	/**
	 * @param string $query Interpolated query.
	 * @return array<int, mixed>
	 */
	public function fake_get_results( string $query ): array {
		if ( false !== strpos( $query, 'SHOW COLUMNS' ) ) {
			if ( preg_match( '/LIKE\s+(\S+)/', $query, $m ) ) {
				$col = $m[1];
				if ( in_array( $col, $this->missing_columns, true ) ) {
					return array();
				}
			}
			return array( (object) array( 'Field' => 'x' ) );
		}

		if ( false !== strpos( $query, 'SELECT id' ) ) {
			$table  = $this->detect_table( $query );
			$cursor = preg_match( '/id > (\d+)/', $query, $m ) ? (int) $m[1] : 0;
			$out    = array_values( array_filter( $this->rows[ $table ] ?? array(), static fn( $r ) => (int) $r['id'] > $cursor ) );
			if ( preg_match( '/LIMIT (\d+)/', $query, $lm ) ) {
				$out = array_slice( $out, 0, (int) $lm[1] );
			}
			return $out;
		}

		return array();
	}

	// ------------------------------------------------------------------
	// get_name() + can_run()
	// ------------------------------------------------------------------

	public function test_get_name(): void {
		$this->assertSame( 'Encryption Key Rotation', $this->strategy->get_name() );
	}

	public function test_can_run_requires_both_decoupling_constants(): void {
		// The unit env defines only the WordPress salts (neither FFC_ENCRYPTION_KEY
		// nor FFC_HASH_SALT), so the site is not decoupled and the rotation must
		// refuse to run with a clear instruction naming BOTH constants.
		$result = $this->strategy->can_run( 'key_rotation', array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'encryption_not_decoupled', $result->get_error_code() );
		$this->assertStringContainsString( 'FFC_ENCRYPTION_KEY', $result->get_error_message() );
		$this->assertStringContainsString( 'FFC_HASH_SALT', $result->get_error_message() );
	}

	// ------------------------------------------------------------------
	// execute() — the core re-encrypt + rehash pass
	// ------------------------------------------------------------------

	public function test_execute_reencrypts_and_rehashes_then_completes(): void {
		$this->rows[ self::SUBMISSIONS ] = array(
			array(
				'id'                => 1,
				'email_encrypted'   => Encryption::encrypt( 'a@b.com' ),
				'cpf_encrypted'     => Encryption::encrypt( '11111111111' ),
				'rf_encrypted'      => '',
				'user_ip_encrypted' => Encryption::encrypt( '10.0.0.1' ),
				'data_encrypted'    => '',
			),
			array(
				'id'                => 2,
				'email_encrypted'   => Encryption::encrypt( 'c@d.com' ),
				'cpf_encrypted'     => '',
				'rf_encrypted'      => Encryption::encrypt( '7654321' ),
				'user_ip_encrypted' => '',
				'data_encrypted'    => '',
			),
		);

		$result = $this->strategy->execute( 'key_rotation', array( 'batch_size' => 100 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['processed'] );
		$this->assertFalse( $result['has_more'] );
		$this->assertCount( 2, $this->captured_updates );

		// Row 1: email + cpf re-encrypted under the active key and rehashed.
		$row1 = $this->update_for_id( 1 );
		$this->assertSame( 'a@b.com', Encryption::decrypt( (string) $row1['email_encrypted'] ) );
		$this->assertSame( Encryption::hash( 'a@b.com' ), $row1['email_hash'] );
		$this->assertSame( '11111111111', Encryption::decrypt( (string) $row1['cpf_encrypted'] ) );
		$this->assertSame( Encryption::hash( '11111111111' ), $row1['cpf_hash'] );
		$this->assertSame( '10.0.0.1', Encryption::decrypt( (string) $row1['user_ip_encrypted'] ) );
		// user_ip has no searchable hash column.
		$this->assertArrayNotHasKey( 'user_ip_hash', $row1 );

		// Row 2: rf branch.
		$row2 = $this->update_for_id( 2 );
		$this->assertSame( '7654321', Encryption::decrypt( (string) $row2['rf_encrypted'] ) );
		$this->assertSame( Encryption::hash( '7654321' ), $row2['rf_hash'] );

		// Completion latched under the active fingerprint.
		$state = $this->options['ffc_key_rotation_state'];
		$this->assertTrue( $state['completed'] );
		$this->assertSame( Encryption::key_fingerprint(), $state['fingerprint'] );
	}

	public function test_execute_short_circuits_when_already_completed(): void {
		$this->options['ffc_key_rotation_state'] = array(
			'fingerprint' => Encryption::key_fingerprint(),
			'cursor'      => array(),
			'completed'   => true,
		);

		$result = $this->strategy->execute( 'key_rotation', array() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['processed'] );
		$this->assertFalse( $result['has_more'] );
		$this->assertCount( 0, $this->captured_updates );
	}

	public function test_execute_rearms_when_key_fingerprint_changed(): void {
		// Previously "completed" under a stale key. A new active key must re-arm
		// the rotation instead of reporting done.
		$this->options['ffc_key_rotation_state'] = array(
			'fingerprint' => 'staleprint00',
			'cursor'      => array( self::SUBMISSIONS => 999 ),
			'completed'   => true,
		);
		$this->rows[ self::SUBMISSIONS ] = array(
			array(
				'id'                => 1,
				'email_encrypted'   => Encryption::encrypt( 'x@y.com' ),
				'cpf_encrypted'     => '',
				'rf_encrypted'      => '',
				'user_ip_encrypted' => '',
				'data_encrypted'    => '',
			),
		);

		$result = $this->strategy->execute( 'key_rotation', array( 'batch_size' => 100 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['processed'] );
		$state = $this->options['ffc_key_rotation_state'];
		$this->assertSame( Encryption::key_fingerprint(), $state['fingerprint'] );
	}

	public function test_execute_reports_has_more_across_batches(): void {
		$this->rows[ self::SUBMISSIONS ] = array(
			array( 'id' => 1, 'email_encrypted' => Encryption::encrypt( 'a@b.com' ) ),
			array( 'id' => 2, 'email_encrypted' => Encryption::encrypt( 'c@d.com' ) ),
		);

		// One row per batch: first batch leaves one pending.
		$result = $this->strategy->execute( 'key_rotation', array( 'batch_size' => 1 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['processed'] );
		$this->assertTrue( $result['has_more'] );
		$this->assertFalse( $this->options['ffc_key_rotation_state']['completed'] );
	}

	public function test_execute_records_update_failure_as_error(): void {
		$this->update_return                = false;
		$this->rows[ self::SUBMISSIONS ] = array(
			array( 'id' => 1, 'email_encrypted' => Encryption::encrypt( 'a@b.com' ) ),
		);

		$result = $this->strategy->execute( 'key_rotation', array( 'batch_size' => 100 ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['processed'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_execute_reports_error_and_never_overwrites_unreadable_row(): void {
		// A row whose only PII column cannot be decrypted (garbage / lost key) is
		// reported as an error and left UNTOUCHED — never nulled.
		$this->rows[ self::SUBMISSIONS ] = array(
			array(
				'id'                => 1,
				'email_encrypted'   => 'not-a-valid-ciphertext',
				'cpf_encrypted'     => '',
				'rf_encrypted'      => '',
				'user_ip_encrypted' => '',
				'data_encrypted'    => '',
			),
		);

		$result = $this->strategy->execute( 'key_rotation', array( 'batch_size' => 100 ) );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertSame( 0, $result['processed'] );
		$this->assertCount( 0, $this->captured_updates );
	}

	// ------------------------------------------------------------------
	// calculate_status()
	// ------------------------------------------------------------------

	public function test_calculate_status_reports_all_pending_when_fingerprint_stale(): void {
		$this->options['ffc_key_rotation_state'] = array(
			'fingerprint' => 'staleprint00',
			'cursor'      => array(),
			'completed'   => true,
		);
		$this->rows[ self::SUBMISSIONS ] = array(
			array( 'id' => 1, 'email_encrypted' => Encryption::encrypt( 'a@b.com' ) ),
			array( 'id' => 2, 'email_encrypted' => Encryption::encrypt( 'c@d.com' ) ),
		);

		$status = $this->strategy->calculate_status( 'key_rotation', array() );

		$this->assertSame( 2, $status['total'] );
		$this->assertSame( 0, $status['migrated'] );
		$this->assertSame( 2, $status['pending'] );
		$this->assertFalse( $status['is_complete'] );
	}

	public function test_calculate_status_complete_when_fingerprint_matches_and_completed(): void {
		$this->options['ffc_key_rotation_state'] = array(
			'fingerprint' => Encryption::key_fingerprint(),
			'cursor'      => array(),
			'completed'   => true,
		);
		$this->rows[ self::SUBMISSIONS ] = array(
			array( 'id' => 1, 'email_encrypted' => Encryption::encrypt( 'a@b.com' ) ),
		);

		$status = $this->strategy->calculate_status( 'key_rotation', array() );

		$this->assertSame( 1, $status['total'] );
		$this->assertSame( 1, $status['migrated'] );
		$this->assertSame( 0, $status['pending'] );
		$this->assertTrue( $status['is_complete'] );
		$this->assertSame( 100.0, $status['percent'] );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * The captured update data for a given row id.
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>
	 */
	private function update_for_id( int $id ): array {
		foreach ( $this->captured_updates as $u ) {
			if ( (int) ( $u['where']['id'] ?? 0 ) === $id ) {
				return $u['data'];
			}
		}
		$this->fail( "No update captured for id {$id}" );
	}
}
