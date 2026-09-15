<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Migrations\Strategies\KeyRotationRemainingMigrationStrategy;

/**
 * The migration that finishes the key rotation over the areas the first one
 * never covered (#1236).
 *
 * The REAL `Encryption` is used, not an alias mock: the strategy reads
 * `Encryption::V2_PREFIX`, and a Mockery alias does not declare class constants
 * (the trap CLAUDE.md records). With the real class the fixtures' ciphertext is
 * genuine and the round trip is genuinely verifiable.
 *
 * The decoupling gate is crossed by a SUBCLASS overriding `is_decoupled()`,
 * rather than by defining `FFC_ENCRYPTION_KEY`: a constant belongs to the whole
 * process and would change `Encryption`'s behaviour for every test running after
 * this one, alphabetically -- exactly the kind of order dependence CLAUDE.md
 * says to avoid.
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\KeyRotationRemainingMigrationStrategy
 */
class KeyRotationRemainingMigrationStrategyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const CANDIDATES = 'wp_ffc_recruitment_candidate';
	private const REREG      = 'wp_ffc_reregistration_submissions';

	/** @var array<string, array<int, array<string, mixed>>> */
	private array $rows = array();

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, array<string, mixed>> */
	private array $updates = array();

	private KeyRotationRemainingMigrationStrategy $strategy;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Migrations\Strategies\KeyRotationRemainingMigrationStrategy' );

		$this->rows    = array(
			self::CANDIDATES => array(),
			self::REREG      => array(),
		);
		$this->options = array();
		$this->updates = array();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v )->byDefault();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( array( $this, 'fake_prepare' ) )->byDefault();
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing( array( $this, 'fake_get_var' ) )->byDefault();
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( array( $this, 'fake_get_results' ) )->byDefault();
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array(
					'table' => $table,
					'data'  => $data,
					'id'    => (int) $where['id'],
				);

				foreach ( $this->rows[ $table ] as $i => $row ) {
					if ( (int) $row['id'] === (int) $where['id'] ) {
						$this->rows[ $table ][ $i ] = array_merge( $row, $data );
					}
				}

				return 1;
			}
		)->byDefault();

		if ( ! class_exists( 'FreeFormCertificate\Migrations\Strategies\WP_Error' ) ) {
			class_alias( 'WP_Error', 'FreeFormCertificate\Migrations\Strategies\WP_Error' );
		}

		Functions\when( '__' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $t ) => $t instanceof \WP_Error );

		$get = function ( $key, $default_value = false ) {
			return $this->options[ $key ] ?? $default_value;
		};
		$set = function ( $key, $value, $autoload = null ) {
			$this->options[ $key ] = $value;
			return true;
		};
		// The GLOBALS only, deliberately. An unqualified call inside a namespace
		// falls back to the global one when no namespaced version exists -- and
		// stubbing the namespaced one CREATES it through Patchwork, for the rest
		// of the process. Every later test reaching that code then resolves the
		// namespaced version, which has no expectation, and fails with "is not
		// defined nor mocked". That is how this file broke
		// `RewriteHtmlImageRefsMigrationStrategyTest`, which stubs only the global.
		Functions\when( 'get_option' )->alias( $get );
		Functions\when( 'update_option' )->alias( $set );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );

		$this->strategy = new class() extends KeyRotationRemainingMigrationStrategy {
			/**
			 * Crosses the gate without defining a process constant.
			 *
			 * @return bool
			 */
			protected function is_decoupled(): bool {
				return true;
			}
		};
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A naive `prepare()`: it interpolates so the double can read the intent.
	 *
	 * @param string $sql  SQL with placeholders.
	 * @param mixed  ...$a The values.
	 * @return string
	 */
	public function fake_prepare( $sql, ...$a ): string {
		$values = ( 1 === count( $a ) && is_array( $a[0] ) ) ? $a[0] : $a;

		foreach ( $values as $v ) {
			$sql = preg_replace( '/%[ids]/', is_int( $v ) ? (string) $v : (string) $v, (string) $sql, 1 );
		}

		return (string) $sql;
	}

	/**
	 * @param string $sql The interpolated SQL.
	 * @return mixed
	 */
	public function fake_get_var( $sql ) {
		$sql = (string) $sql;

		if ( str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
			foreach ( array_keys( $this->rows ) as $table ) {
				if ( str_contains( $sql, $table ) ) {
					return $table;
				}
			}
			return null;
		}

		$table = $this->table_in( $sql );
		if ( '' === $table ) {
			return 0;
		}

		if ( str_contains( $sql, 'MAX(id)' ) ) {
			$ids = array_map( static fn( $r ) => (int) $r['id'], $this->rows[ $table ] );
			return $ids ? max( $ids ) : 0;
		}

		// COUNT(*) — with or without the cursor's slice.
		$matching = $this->matching_rows( $table, $sql );

		return count( $matching );
	}

	/**
	 * @param string $sql The interpolated SQL.
	 * @return array<int, array<string, mixed>>
	 */
	public function fake_get_results( $sql ) {
		$table = $this->table_in( (string) $sql );
		if ( '' === $table ) {
			return array();
		}

		return array_values( $this->matching_rows( $table, (string) $sql ) );
	}

	/**
	 * Linhas do alvo que satisfazem os recortes presentes no SQL.
	 *
	 * @param string $table Tabela.
	 * @param string $sql   The interpolated SQL.
	 * @return array<int, array<string, mixed>>
	 */
	private function matching_rows( string $table, string $sql ): array {
		$rows = $this->rows[ $table ];

		if ( str_contains( $sql, 'data LIKE' ) ) {
			$rows = array_filter(
				$rows,
				static fn( $r ) => is_string( $r['data'] ?? null ) && str_contains( (string) $r['data'], Encryption::V2_PREFIX )
			);
		}

		if ( preg_match( '/id <= (\d+)/', $sql, $m ) ) {
			$rows = array_filter( $rows, static fn( $r ) => (int) $r['id'] <= (int) $m[1] );
		}

		if ( preg_match( '/id > (\d+)/', $sql, $m ) ) {
			$rows = array_filter( $rows, static fn( $r ) => (int) $r['id'] > (int) $m[1] );
		}

		return $rows;
	}

	/**
	 * @param string $sql SQL.
	 * @return string
	 */
	private function table_in( string $sql ): string {
		foreach ( array_keys( $this->rows ) as $table ) {
			if ( str_contains( $sql, $table ) ) {
				return $table;
			}
		}

		return '';
	}

	// ------------------------------------------------------------------
	// The gate
	// ------------------------------------------------------------------

	public function test_can_run_refuses_while_the_site_is_not_decoupled(): void {
		// This one uses the REAL strategy, without the subclass: the test
		// environment defines neither constant, which is the state to refuse.
		$real   = new KeyRotationRemainingMigrationStrategy();
		$result = $real->can_run( 'key_rotation_remaining', array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'FFC_ENCRYPTION_KEY', $result->get_error_message() );
		$this->assertStringContainsString( 'FFC_HASH_SALT', $result->get_error_message() );
	}

	public function test_execute_refuses_instead_of_rewriting_under_the_wrong_key(): void {
		$real = new KeyRotationRemainingMigrationStrategy();

		$this->rows[ self::CANDIDATES ][] = $this->candidate( 1, '11111111111' );

		$result = $real->execute( 'key_rotation_remaining', array() );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array(), $this->updates, 'Nothing may be written when the gate refuses.' );
	}

	// ------------------------------------------------------------------
	// Recruitment: re-encrypt and rebuild the hash
	// ------------------------------------------------------------------

	public function test_recruitment_row_is_reencrypted_and_its_hash_rebuilt(): void {
		$cpf = '11111111111';

		$row               = $this->candidate( 1, $cpf );
		$row['cpf_hash']   = 'hash-under-the-old-salt';
		$this->rows[ self::CANDIDATES ][] = $row;

		$this->strategy->execute( 'key_rotation_remaining', array() );

		$this->assertCount( 1, $this->updates );
		$written = $this->updates[0]['data'];

		$this->assertArrayHasKey( 'cpf_hash', $written, 'O hash obsoleto tinha de ser reescrito.' );
		$this->assertSame( Encryption::hash( $cpf ), $written['cpf_hash'] );

		$this->assertArrayHasKey( 'cpf_encrypted', $written );
		$this->assertSame(
			$cpf,
			Encryption::decrypt( (string) $written['cpf_encrypted'] ),
			'The rewritten ciphertext must still decrypt to the same value.'
		);
	}

	public function test_a_hash_already_current_is_not_rewritten(): void {
		$cpf = '22222222222';

		$row                              = $this->candidate( 1, $cpf );
		$row['cpf_hash']                  = (string) Encryption::hash( $cpf );
		$this->rows[ self::CANDIDATES ][] = $row;

		$this->strategy->execute( 'key_rotation_remaining', array() );

		$written = $this->updates[0]['data'];

		$this->assertArrayNotHasKey(
			'cpf_hash',
			$written,
			'A hash already under the current salt must not cost a write.'
		);
		$this->assertArrayHasKey( 'cpf_encrypted', $written );
	}

	/**
	 * A UNIQUE collision is reported with the database's message, not as a
	 * generic failure.
	 *
	 * WHY THIS CASE EXISTS
	 *
	 * `cpf_hash` and `rf_hash` are UNIQUE over the hash VALUE, not over the
	 * person. Under different salts the same person produces different values, so
	 * two rows for them pass the constraint — and that is exactly what part 2 of
	 * #1236 describes: after the decoupling the search stopped finding the old
	 * candidate and the importer's dedup created a new row.
	 *
	 * Where that pair exists, rebuilding the old row's hash produces the value
	 * the new one already has, and the UPDATE hits the constraint. The method's
	 * docblock claimed the opposite; this assertion is what keeps it honest.
	 *
	 * WHAT IT PROVES, AND WHAT IT DOES NOT
	 *
	 * It proves the failure is reported with the database's message — which names
	 * the duplicated key and value, and is what separates "reconcile the
	 * duplicates by hand" from "try again" — and that the loop survives it.
	 *
	 * It does not prove the collision happens: that is the server enforcing the
	 * UNIQUE, and no `$wpdb` double reproduces it. What exists here is the
	 * SIMULATED failure, which is the only side of this case the code controls.
	 */
	public function test_a_unique_collision_is_reported_with_the_database_message(): void {
		global $wpdb;

		$this->rows[ self::CANDIDATES ][] = array_merge(
			$this->candidate( 1, '11111111111' ),
			array( 'cpf_hash' => 'hash-under-the-old-salt' )
		);

		$wpdb->last_error = "Duplicate entry 'abc123' for key 'cpf_hash'";
		$wpdb->shouldReceive( 'update' )->andReturn( false );

		$result = $this->strategy->execute( 'key_rotation_remaining', array() );

		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( "Duplicate entry 'abc123' for key 'cpf_hash'", $result['errors'][0] );
		$this->assertStringContainsString( '1', $result['errors'][0], 'The candidate id must be in the message.' );
	}

	/**
	 * With no database message, the old wording still holds.
	 *
	 * `last_error` may come back empty — a connection failure, a driver that does
	 * not fill it. Interpolating empty would produce a sentence ending in a colon
	 * and nothing, which is worse than the short message.
	 */
	public function test_a_write_failure_without_a_database_message_still_reports_the_candidate(): void {
		global $wpdb;

		$this->rows[ self::CANDIDATES ][] = array_merge(
			$this->candidate( 7, '11111111111' ),
			array( 'cpf_hash' => 'hash-under-the-old-salt' )
		);

		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'update' )->andReturn( false );

		$result = $this->strategy->execute( 'key_rotation_remaining', array() );

		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( '7', $result['errors'][0] );

		// The short form ends in a full stop. Interpolating an empty detail would
		// produce a sentence ending in `: ` and nothing -- which is what this
		// assertion refuses. MEASURED: the first version looked for `': .'`, which
		// the mutation never produces, so it passed green measuring nothing.
		$this->assertStringEndsWith( '.', $result['errors'][0] );
	}

	// ------------------------------------------------------------------
	// Reregistration: the dispatch is by value, not by configuration
	// ------------------------------------------------------------------

	public function test_only_values_carrying_the_ciphertext_prefix_are_touched(): void {
		$secret = 'participant-cpf';

		$body = array(
			'fields' => array(
				'cpf'  => (string) Encryption::encrypt( $secret ),
				'nome' => 'Plaintext that was never encrypted',
			),
		);

		$this->rows[ self::REREG ][] = array(
			'id'   => 1,
			'data' => (string) json_encode( $body ),
		);

		$result = $this->strategy->execute( 'key_rotation_remaining', array() );

		$this->assertCount( 1, $this->updates );
		$saved = json_decode( (string) $this->updates[0]['data']['data'], true );

		$this->assertSame(
			'Plaintext that was never encrypted',
			$saved['fields']['nome'],
			'A plaintext value must not be encrypted by the migration.'
		);

		// The assertion that really charges the dispatch by prefix. Without it,
		// the plaintext ends up in `decrypt()`, which returns null, and the value
		// survives intact -- so the assertion above passes even with the dispatch
		// broken. What does NOT survive is the conclusion: every plaintext field
		// becomes an error, and `mark_completed()` requires an empty list, so the
		// migration would never reach 100%. Measured by mutation: removing the
		// prefix check, this is the assertion that
		// reprova, e só ela.
		$this->assertSame(
			array(),
			$result['errors'],
			'Plaintext is not a decryption failure: reporting it as an error would stop the migration completing.'
		);
		$this->assertTrue( $result['has_more'] === false || 0 === $result['pending'] );
		$this->assertStringStartsWith( Encryption::V2_PREFIX, $saved['fields']['cpf'] );
		$this->assertSame( $secret, Encryption::decrypt( $saved['fields']['cpf'] ) );
	}

	public function test_a_body_without_ciphertext_is_never_written(): void {
		$this->rows[ self::REREG ][] = array(
			'id'   => 1,
			'data' => (string) json_encode( array( 'fields' => array( 'nome' => 'Fulano' ) ) ),
		);

		$this->strategy->execute( 'key_rotation_remaining', array() );

		$this->assertSame( array(), $this->updates );
	}

	// ------------------------------------------------------------------
	// State: fingerprint and completion
	// ------------------------------------------------------------------

	public function test_a_changed_key_rearms_instead_of_reporting_complete(): void {
		$this->rows[ self::CANDIDATES ][] = $this->candidate( 1, '33333333333' );

		$this->strategy->execute( 'key_rotation_remaining', array() );
		$completed = $this->strategy->calculate_status( 'key_rotation_remaining', array() );
		$this->assertTrue( $completed['is_complete'] );

		// The key changes: what was already rewritten is legacy again.
		$state                 = $this->options['ffc_key_rotation_remaining_state'];
		$state['fingerprint']  = 'fingerprint-of-another-key';
		$this->options['ffc_key_rotation_remaining_state'] = $state;

		$after = $this->strategy->calculate_status( 'key_rotation_remaining', array() );

		$this->assertFalse(
			$after['is_complete'],
			'A different key must re-arm; reporting "complete" would leave data under the old key.'
		);
		$this->assertSame( 1, $after['pending'] );
	}

	public function test_an_empty_install_reports_complete_without_writing(): void {
		$status = $this->strategy->calculate_status( 'key_rotation_remaining', array() );

		$this->assertTrue( $status['is_complete'] );
		$this->assertSame( 0, $status['total'] );
		$this->assertSame( array(), $this->updates );
	}

	public function test_name_is_the_one_the_registry_advertises(): void {
		$this->assertSame( 'Encryption Key Rotation — Remaining Areas', $this->strategy->get_name() );
	}

	/**
	 * A candidate row with a genuinely encrypted CPF.
	 *
	 * @param int    $id  The id.
	 * @param string $cpf The CPF in plaintext.
	 * @return array<string, mixed>
	 */
	private function candidate( int $id, string $cpf ): array {
		return array(
			'id'              => $id,
			'cpf_encrypted'   => (string) Encryption::encrypt( $cpf ),
			'cpf_hash'        => 'old-hash',
			'rf_encrypted'    => null,
			'rf_hash'         => null,
			'email_encrypted' => null,
			'email_hash'      => null,
		);
	}
}
