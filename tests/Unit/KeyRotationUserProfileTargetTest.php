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
use FreeFormCertificate\UserDashboard\UserProfileFieldMap;

/**
 * The key rotation's third target: the sensitive profile usermeta (#1236).
 *
 * WHY THIS EXISTS
 *
 * `ffc_user_cpf`, `ffc_user_rf` and `ffc_user_rg` hold PII encrypted under the
 * key DERIVED from the WordPress salts. While they stay that way, rotating
 * `SECURE_AUTH_KEY` / `LOGGED_IN_KEY` / `NONCE_KEY` -- something several hosts
 * offer in one click -- makes those values unreadable forever, because the key
 * IS derived from them. This is not a performance item.
 *
 * THE SEPARATE FILE IS DELIBERATE
 *
 * `KeyRotationRemainingMigrationStrategyTest` builds a `$wpdb` double oriented
 * around TABLE ROWS, which is the shape of the other two targets. This target
 * has no table of its own: it pages by `user_id` with `get_col()` and reads and
 * writes values through the WordPress meta API. Fitting both shapes into one
 * harness would make both less readable.
 *
 * The REAL `Encryption` is used, not an alias mock -- the strategy reads
 * `Encryption::V2_PREFIX`, and a Mockery alias does not declare class
 * constants. With the real class the fixtures' ciphertext is genuine and the
 * round trip is verifiable.
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\KeyRotationRemainingMigrationStrategy
 */
class KeyRotationUserProfileTargetTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array<string, string>> */
	private array $meta = array();

	/**
	 * The ffc_user_profiles rows, keyed by user id.
	 *
	 * The target grew a second store in #1313: the ciphertext stays in the
	 * usermeta, the rebuilt HASH goes to an indexed column. A rotation that
	 * rewrote only the first would leave every identifier lookup silently
	 * finding nobody, so the harness has to see both.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $profiles = array();

	/** @var array<string, mixed> */
	private array $options = array();

	private KeyRotationRemainingMigrationStrategy $strategy;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Migrations\Strategies\KeyRotationRemainingMigrationStrategy' );
		class_exists( '\FreeFormCertificate\UserDashboard\UserProfileFieldMap' );

		$this->meta     = array();
		$this->options  = array();
		$this->profiles = array();

		global $wpdb;
		$wpdb           = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix   = 'wp_';
		$wpdb->usermeta = 'wp_usermeta';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v )->byDefault();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$a ): string {
				$values = ( 1 === count( $a ) && is_array( $a[0] ) ) ? $a[0] : $a;
				foreach ( $values as $v ) {
					$sql = preg_replace( '/%[ids]/', (string) $v, (string) $sql, 1 );
				}
				return (string) $sql;
			}
		)->byDefault();

		// Neither of the OTHER two targets has a table in this harness, so both
		// count zero and the dispatch falls to this one. That is what allows
		// exercising one target in isolation without staging the others.
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$sql = (string) $sql;
				if ( str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
					return null;
				}
				if ( str_contains( $sql, 'COUNT(DISTINCT user_id)' ) ) {
					return count( $this->pending_users( $sql ) );
				}
				// UserProfileRepository::existsForUserId().
				if ( str_contains( $sql, 'ffc_user_profiles' ) ) {
					$user_id = $this->user_id_of( $sql );
					return isset( $this->profiles[ $user_id ] ) ? '1' : null;
				}
				return 0;
			}
		)->byDefault();

		// UserProfileRepository, reading and writing the identity index. The
		// double's `prepare()` interpolates, so the user id is readable off the
		// statement -- which is what lets this model a TABLE rather than the
		// single row a template-returning `prepare()` would force.
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				return $this->profiles[ $this->user_id_of( (string) $sql ) ] ?? null;
			}
		)->byDefault();
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) {
				unset( $table );
				$this->profiles[ (int) $data['user_id'] ] = $data;
				return 1;
			}
		)->byDefault();
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				unset( $table );
				$user_id                    = (int) $where['user_id'];
				$this->profiles[ $user_id ] = array_merge( $this->profiles[ $user_id ] ?? array(), $data );
				return 1;
			}
		)->byDefault();

		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $sql ) {
				return array_map( 'strval', $this->pending_users( (string) $sql ) );
			}
		)->byDefault();

		if ( ! class_exists( 'FreeFormCertificate\Migrations\Strategies\WP_Error' ) ) {
			class_alias( 'WP_Error', 'FreeFormCertificate\Migrations\Strategies\WP_Error' );
		}

		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );

		Functions\when( '__' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $t ) => $t instanceof \WP_Error );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );

		// The GLOBALS only: stubbing the namespaced version CREATES it through
		// Patchwork for the rest of the process, and every later test reaching
		// that code resolves a function with no expectation. CLAUDE.md records
		// the case, which already broke a neighbour in this very family.
		Functions\when( 'get_option' )->alias(
			function ( $key, $default_value = false ) {
				return $this->options[ $key ] ?? $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id, $key, $single = false ) {
				return $this->meta[ (int) $user_id ][ $key ] ?? '';
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( $user_id, $key, $value ) {
				$this->meta[ (int) $user_id ][ $key ] = (string) $value;
				return true;
			}
		);

		$this->strategy = new class() extends KeyRotationRemainingMigrationStrategy {
			/**
			 * Passes the decoupling gate without defining a process constant --
			 * a constant would hold for every later test.
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
	 * The `user_id` an interpolated single-row statement asks for.
	 *
	 * @param string $sql SQL already interpolated by the double's `prepare()`.
	 * @return int
	 */
	private function user_id_of( string $sql ): int {
		return 1 === preg_match( '/user_id = (\d+)/', $sql, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * The `user_id`s the captured SQL would select, read from the in-memory
	 * store. It honours the cursor (`user_id > N`) and the slice (`user_id <= N`).
	 *
	 * @param string $sql SQL already interpolated by the double's `prepare()`.
	 * @return list<int>
	 */
	private function pending_users( string $sql ): array {
		$ids = array();

		foreach ( $this->meta as $user_id => $rows ) {
			$has = false;
			foreach ( $rows as $key => $value ) {
				if ( str_contains( $sql, $key ) && 0 === strpos( $value, Encryption::V2_PREFIX ) ) {
					$has = true;
					break;
				}
			}
			if ( ! $has ) {
				continue;
			}

			if ( 1 === preg_match( '/user_id > (\d+)/', $sql, $m ) && $user_id <= (int) $m[1] ) {
				continue;
			}
			if ( 1 === preg_match( '/user_id <= (\d+)/', $sql, $m ) && $user_id > (int) $m[1] ) {
				continue;
			}

			$ids[] = (int) $user_id;
		}

		sort( $ids );

		return $ids;
	}

	// ==================================================================
	// The agreement guard
	// ==================================================================

	/**
	 * The migration's map knows EVERY sensitive usermeta field in
	 * `UserProfileFieldMap` -- no more, no fewer.
	 *
	 * It is the assertion that justifies the literals in the production code.
	 * Importing the map there would create the `Migrations > UserDashboard` edge,
	 * which is not in `ModuleBoundaryTest`'s baseline; a test lives outside the
	 * graph and can charge the agreement without coupling the modules.
	 *
	 * Without this, a fourth sensitive field added to the map would fall outside
	 * the rotation in silence -- and silence here costs unreadable PII.
	 */
	public function test_the_migration_knows_every_sensitive_usermeta_field(): void {
		$expected = array();

		foreach ( UserProfileFieldMap::sensitive_field_keys() as $field ) {
			$spec = UserProfileFieldMap::get( $field );
			if ( null === $spec || UserProfileFieldMap::STORAGE_USERMETA !== ( $spec['storage'] ?? null ) ) {
				continue;
			}
			$meta_key              = (string) $spec['meta_key'];
			$expected[ $meta_key ] = UserProfileFieldMap::hash_column( $field );
		}

		$this->assertNotSame( array(), $expected, 'Read no sensitive field from the map — the check did not run.' );

		$ref = new \ReflectionMethod( KeyRotationRemainingMigrationStrategy::class, 'profile_meta_map' );
		$ref->setAccessible( true );
		$actual = $ref->invoke( $this->strategy );

		ksort( $expected );
		ksort( $actual );

		$this->assertSame( $expected, $actual, 'The migration map diverged from UserProfileFieldMap — a sensitive field would go unrotated.' );
	}

	/**
	 * The `ffc_user_profiles` columns are NOT part of the target.
	 *
	 * #1236 groups "ffc_user_profiles / wp_usermeta" on one line. Measured, that
	 * table's six columns are plain text, and re-encrypting what was never
	 * encrypted would corrupt it. This assertion freezes the measurement.
	 */
	public function test_the_profile_table_columns_are_not_sensitive(): void {
		// The identity-index columns #1313 added are deliberately absent from
		// this list: they are not FIELDS of the map at all, they are where a
		// usermeta field's hash is written. A hash is not an envelope, so
		// re-encrypting it would be exactly the corruption this test freezes.
		$table_fields = array( 'display_name', 'phone', 'department', 'organization', 'notes', 'preferences' );

		foreach ( $table_fields as $field ) {
			$spec = UserProfileFieldMap::get( $field );

			$this->assertNotNull( $spec, sprintf( 'The field %s vanished from the map — re-measure before trusting this assertion.', $field ) );
			$this->assertSame( UserProfileFieldMap::STORAGE_PROFILE_TABLE, $spec['storage'] );
			$this->assertEmpty(
				$spec['sensitive'] ?? false,
				sprintf( 'The field %s became sensitive: the rotation migration must start covering the profile table.', $field )
			);
		}
	}

	// ==================================================================
	// The batch
	// ==================================================================

	/**
	 * Seeds a user's meta with genuine ciphertext.
	 *
	 * @param int                   $user_id The user.
	 * @param array<string, string> $plain   Meta key => plaintext value.
	 * @return void
	 */
	private function seed_user( int $user_id, array $plain ): void {
		foreach ( $plain as $key => $value ) {
			$cipher = Encryption::encrypt( $value );
			$this->assertIsString( $cipher, 'The fixture needs real ciphertext.' );
			$this->meta[ $user_id ][ $key ] = $cipher;
		}
	}

	/**
	 * A user with three metas has all THREE re-encrypted in the same batch.
	 *
	 * It is the property that holds up paging by user. If the page were of meta
	 * rows and the cursor advanced by `user_id`, a user split across two batches
	 * would lose the metas left behind -- the cursor would already have passed
	 * them, and nobody would come back.
	 */
	public function test_every_meta_of_a_user_is_rewritten_in_one_batch(): void {
		$this->seed_user( 10, array( 'ffc_user_cpf' => '11122233344', 'ffc_user_rf' => '7654321', 'ffc_user_rg' => '12345678' ) );
		$before = $this->meta[10];

		$this->strategy->execute( '', array() );

		foreach ( array( 'ffc_user_cpf', 'ffc_user_rf', 'ffc_user_rg' ) as $key ) {
			$this->assertNotSame( $before[ $key ], $this->meta[10][ $key ], sprintf( '%s was not re-encrypted.', $key ) );
			$this->assertSame(
				Encryption::decrypt( $before[ $key ] ),
				Encryption::decrypt( $this->meta[10][ $key ] ),
				sprintf( '%s changed its VALUE, not just its ciphertext — the migration corrupted the data.', $key )
			);
		}
	}

	/**
	 * The searchable fields get their paired hash; the non-searchable one does not.
	 *
	 * The hash is the half that fixes a LIVE defect rather than merely preventing
	 * a future one: `Encryption::hash()` reads `FFC_HASH_SALT` as soon as it
	 * exists, so every hash written before the decoupling is unreachable by any
	 * search made afterwards.
	 */
	public function test_hashes_are_rebuilt_only_for_the_searchable_fields(): void {
		$this->seed_user( 10, array( 'ffc_user_cpf' => '11122233344', 'ffc_user_rf' => '7654321', 'ffc_user_rg' => '12345678' ) );

		$this->strategy->execute( '', array() );

		$this->assertSame( Encryption::hash( '11122233344' ), $this->profiles[10]['cpf_hash'] ?? null );
		$this->assertSame( Encryption::hash( '7654321' ), $this->profiles[10]['rf_hash'] ?? null );
		$this->assertArrayNotHasKey(
			'rg_hash',
			$this->profiles[10],
			'RG is not hash-searchable in the map; writing one creates a column nothing reads.'
		);
		$this->assertArrayNotHasKey(
			'ffc_user_cpf_hash',
			$this->meta[10],
			'The hash must not be written to the meta as well — two stores answering one question is what #1313 removed.'
		);
	}

	/**
	 * The user had no profile row, and the rotation still leaves the index
	 * answering for them.
	 *
	 * The index has to exist for whoever carries the identifier, not for
	 * whoever also happened to fill in a display name -- otherwise a rotation
	 * is the moment a subset of users silently stops being findable, and the
	 * subset is not one anybody can state.
	 */
	public function test_a_user_without_a_profile_row_gets_one(): void {
		$this->seed_user( 10, array( 'ffc_user_cpf' => '11122233344' ) );
		$this->assertArrayNotHasKey( 10, $this->profiles );

		$this->strategy->execute( '', array() );

		$this->assertSame( Encryption::hash( '11122233344' ), $this->profiles[10]['cpf_hash'] ?? null );
		$this->assertSame( 10, (int) $this->profiles[10]['user_id'] );
	}

	/**
	 * A hash already under the current salt costs no write.
	 *
	 * The same property the other two targets hold. Without it every rotation
	 * rewrites every row of the index, which on a large install is the
	 * difference between a migration that finishes and one that times out on
	 * work it did not need to do.
	 */
	public function test_an_index_already_current_is_not_rewritten(): void {
		$this->seed_user( 10, array( 'ffc_user_cpf' => '11122233344' ) );
		$this->profiles[10] = array(
			'user_id'  => 10,
			'cpf_hash' => Encryption::hash( '11122233344' ),
		);

		global $wpdb;
		$wpdb->shouldReceive( 'update' )->never();
		$wpdb->shouldReceive( 'insert' )->never();

		$this->strategy->execute( '', array() );

		$this->assertSame( Encryption::hash( '11122233344' ), $this->profiles[10]['cpf_hash'] );
	}

	/**
	 * The cursor advances, so the next batch does not reprocess what already passed.
	 *
	 * Without this the migration never finishes: the pending predicate is
	 * `meta_value LIKE '%v2:%'`, and a RE-ENCRYPTED value still matches it. It is
	 * the cursor -- not the predicate -- that makes the work progress.
	 */
	public function test_the_cursor_advances_so_a_second_batch_moves_on(): void {
		$this->seed_user( 10, array( 'ffc_user_cpf' => '11122233344' ) );
		$this->seed_user( 20, array( 'ffc_user_cpf' => '55566677788' ) );

		$this->strategy->execute( '', array() );

		$state = $this->options['ffc_key_rotation_remaining_state'] ?? array();
		$this->assertIsArray( $state );

		$json = wp_json_encode( $state );
		$this->assertIsString( $json );
		$this->assertStringContainsString( '20', $json, 'The cursor did not reach the last user in the batch.' );
	}

	/**
	 * A value outside the `v2:` scheme is left untouched.
	 *
	 * A plaintext meta -- or one under a scheme this migration does not know --
	 * is not its to rewrite. Re-encrypting plaintext would make it unreadable to
	 * `UserProfileService` itself.
	 *
	 * WHAT THIS ASSERTION DOES NOT PIN, measured by mutation: replacing the
	 * prefix check with a plain `'' === $stored` keeps all 6 tests green. That is
	 * because `Encryption::decrypt()` already returns null for what it cannot
	 * decrypt, and the `continue` that follows protects the value either way. The
	 * observable behaviour is the same; what changes is the COST.
	 *
	 * That is why the check stays: without it, every plaintext meta enters
	 * `decrypt()` -- opening the envelope, comparing the HMAC, calling
	 * `openssl_decrypt` -- in a loop that walks every user on the site.
	 *
	 * Until #1234 there was a second, larger reason: each failure wrote a row
	 * into `ffc_activity_log`, uncapped. The cap now exists (five per request),
	 * so what remains is the cost of the decryption itself.
	 *
	 * Pinning that cost would mean alias-mocking `ActivityLog`, a real and
	 * already-loaded class -- fragile and order-dependent, which is what
	 * CLAUDE.md says to avoid. The reason is written in the production method
	 * instead.
	 */
	public function test_a_value_outside_the_v2_scheme_is_left_alone(): void {
		$this->meta[10] = array( 'ffc_user_cpf' => 'plain-text' );

		$this->strategy->execute( '', array() );

		$this->assertSame( 'plain-text', $this->meta[10]['ffc_user_cpf'] );
		$this->assertArrayNotHasKey( 'ffc_user_cpf_hash', $this->meta[10] );
	}
}
