<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\Migrations\Strategies\IdentityNormalizationMigrationStrategy;

/**
 * The card that brings stored identifiers into their canonical form (#1313).
 *
 * THE REAL `Encryption` IS USED, NOT AN ALIAS
 *
 * The strategy reads `Encryption::V2_PREFIX`, and a Mockery alias does not
 * declare class constants. With the real class the fixtures' ciphertext is
 * genuine and the decrypt → normalise → re-encrypt round trip is verifiable
 * rather than staged — which matters here more than usual, because the whole
 * claim of the card is about what a row holds after it runs.
 *
 * ONE TABLE PER TEST, AND THE DOUBLE MODELS THAT
 *
 * `prepare()` interpolates, so the statements are readable, but the row store
 * is a single list: every test drives one target. That is as expressive as a
 * keyed store would be and cannot silently answer for the wrong table.
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\IdentityNormalizationMigrationStrategy
 */
class IdentityNormalizationMigrationStrategyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array<string, string|null>> */
	private array $rows = array();

	/** @var array<int, array<string, mixed>> */
	private array $writes = array();

	/** @var array<string, mixed> */
	private array $options = array();

	/** Whether the next $wpdb->update() reports a duplicate-key refusal. */
	private bool $refuse_write = false;

	private IdentityNormalizationMigrationStrategy $strategy;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Migrations\Strategies\IdentityNormalizationMigrationStrategy' );
		class_exists( '\FreeFormCertificate\Core\SensitiveFieldRegistry' );

		$this->rows         = array();
		$this->writes       = array();
		$this->options      = array();
		$this->refuse_write = false;

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

		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$sql = (string) $sql;

				// Only the appointments table exists in this harness, so every
				// other target counts zero and the dispatch falls to the one
				// under test.
				if ( str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
					return str_contains( $sql, $this->table() ) ? $this->table() : null;
				}
				if ( str_contains( $sql, 'COUNT(DISTINCT user_id)' ) ) {
					return 0;
				}
				if ( str_contains( $sql, 'COALESCE(MAX(id)' ) ) {
					return (string) $this->max_id();
				}
				if ( str_contains( $sql, 'COUNT(*)' ) ) {
					return (string) count( $this->pending_ids( $sql ) );
				}
				return 0;
			}
		)->byDefault();

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$out = array();
				foreach ( $this->rows as $row ) {
					if ( 1 === preg_match( '/id > (\d+)/', (string) $sql, $m ) && (int) $row['id'] <= (int) $m[1] ) {
						continue;
					}
					$out[] = $row;
				}
				return $out;
			}
		)->byDefault();

		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				unset( $table );
				if ( $this->refuse_write ) {
					return false;
				}
				$this->writes[] = array(
					'id'   => (int) $where['id'],
					'data' => $data,
				);
				foreach ( $this->rows as $index => $row ) {
					if ( (int) $row['id'] === (int) $where['id'] ) {
						$this->rows[ $index ] = array_merge( $row, $data );
					}
				}
				return 1;
			}
		)->byDefault();

		Functions\when( '__' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $t ) => $t instanceof \WP_Error );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
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
		Functions\when( 'get_user_meta' )->justReturn( '' );

		if ( ! class_exists( 'FreeFormCertificate\Migrations\Strategies\WP_Error' ) ) {
			class_alias( 'WP_Error', 'FreeFormCertificate\Migrations\Strategies\WP_Error' );
		}

		$this->strategy = new class() extends IdentityNormalizationMigrationStrategy {
			/**
			 * Passes the gate without defining process-wide constants, which
			 * would change `Encryption` for every test that runs afterwards.
			 *
			 * @return bool
			 */
			protected function encryption_available(): bool {
				return true;
			}
		};
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function table(): string {
		return 'wp_ffc_self_scheduling_appointments';
	}

	private function max_id(): int {
		$max = 0;
		foreach ( $this->rows as $row ) {
			$max = max( $max, (int) $row['id'] );
		}
		return $max;
	}

	/**
	 * Ids the interpolated COUNT would see, honouring the cursor clause.
	 *
	 * @param string $sql Interpolated statement.
	 * @return array<int, int>
	 */
	private function pending_ids( string $sql ): array {
		$ids = array();
		foreach ( $this->rows as $row ) {
			$has = false;
			foreach ( array( 'email_encrypted', 'cpf_encrypted', 'rf_encrypted' ) as $column ) {
				if ( null !== ( $row[ $column ] ?? null ) ) {
					$has = true;
					break;
				}
			}
			if ( ! $has ) {
				continue;
			}
			if ( 1 === preg_match( '/id <= (\d+)/', $sql, $m ) && (int) $row['id'] > (int) $m[1] ) {
				continue;
			}
			$ids[] = (int) $row['id'];
		}
		return $ids;
	}

	/**
	 * Seed one appointment row with genuine ciphertext.
	 *
	 * @param int                   $id     Row id.
	 * @param array<string, string> $plain  Field key => plaintext.
	 * @param array<string, string> $hashes Hash column => stored hash (optional).
	 * @return void
	 */
	private function seed_row( int $id, array $plain, array $hashes = array() ): void {
		$row = array(
			'id'              => (string) $id,
			'email_encrypted' => null,
			'email_hash'      => null,
			'cpf_encrypted'   => null,
			'cpf_hash'        => null,
			'rf_encrypted'    => null,
			'rf_hash'         => null,
		);

		foreach ( $plain as $field => $value ) {
			$cipher = Encryption::encrypt( $value );
			$this->assertIsString( $cipher, 'The fixture needs real ciphertext.' );
			$row[ $field . '_encrypted' ] = $cipher;
			$row[ $field . '_hash' ]      = Encryption::hash( $value );
		}

		$this->rows[] = array_merge( $row, $hashes );
	}

	// ==================================================================

	/**
	 * The live defect: an e-mail booked with capitals is canonicalised.
	 *
	 * `SelfSchedulingAppointmentAjaxHandler` sanitises but does not lowercase,
	 * so the stored hash was of the mixed-case string and no other module's
	 * lookup could find it.
	 */
	public function test_a_mixed_case_email_is_rewritten_and_rehashed(): void {
		$this->seed_row( 10, array( 'email' => 'Joao@Escola.gov.br' ) );

		$this->strategy->execute( '', array() );

		$row = $this->rows[0];

		$this->assertSame( 'joao@escola.gov.br', Encryption::decrypt( (string) $row['email_encrypted'] ) );
		$this->assertSame(
			Encryption::hash( 'joao@escola.gov.br' ),
			$row['email_hash'],
			'The stored hash must be of the canonical form, or the lookup that now canonicalises finds nothing.'
		);
	}

	/**
	 * Both document fields, because RF is not a special case of CPF.
	 *
	 * They share a normalizer and the card derives its columns from the
	 * registry, so covering one exercises the other's code path -- which is
	 * exactly the reasoning that lets a field be silently uncovered. RF has its
	 * own columns, its own `UNIQUE KEY uq_rf_hash` on the candidate table and
	 * its own entry in the profile map, and none of those is reached by a CPF
	 * fixture. The provider costs one line and removes the inference.
	 *
	 * @dataProvider document_fields
	 *
	 * @param string $field  Registry field key.
	 * @param string $masked The value as an input mask produces it.
	 * @param string $bare   Its canonical form.
	 */
	public function test_a_masked_document_is_rewritten_to_digits( string $field, string $masked, string $bare ): void {
		$this->seed_row( 10, array( $field => $masked ) );

		$this->strategy->execute( '', array() );

		$this->assertSame( $bare, Encryption::decrypt( (string) $this->rows[0][ $field . '_encrypted' ] ) );
		$this->assertSame( Encryption::hash( $bare ), $this->rows[0][ $field . '_hash' ] );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function document_fields(): array {
		return array(
			'cpf' => array( 'cpf', '123.456.789-09', '12345678909' ),
			'rf'  => array( 'rf', '765.432-1', '7654321' ),
		);
	}

	/**
	 * A row already canonical is read and left alone — no write at all.
	 *
	 * This is the property the card's completion rests on. `encrypt()` uses a
	 * random IV, so a card that compared ciphertexts would rewrite every row on
	 * every run and never reach 0 pending; the comparison is on the plaintext
	 * and on the hash, both deterministic.
	 */
	public function test_an_already_canonical_row_is_not_rewritten(): void {
		$this->seed_row( 10, array( 'email' => 'joao@escola.gov.br', 'cpf' => '12345678909', 'rf' => '7654321' ) );

		$this->strategy->execute( '', array() );

		$this->assertSame(
			array(),
			$this->writes,
			'A canonical row was rewritten — the card would never converge, because the ciphertext differs on every pass.'
		);
	}

	/**
	 * The hash is repaired even when the plaintext was already canonical.
	 *
	 * The plan stopped at the plaintext. That is enough for every write path in
	 * the tree, because `encrypt_fields()` hashes the string it encrypts — but
	 * it is a property of today's code, not of rows written years ago, and this
	 * card exists precisely because a value can carry a rule nobody recorded.
	 */
	public function test_a_stale_hash_is_repaired_without_touching_the_ciphertext(): void {
		$this->seed_row( 10, array( 'email' => 'joao@escola.gov.br' ), array( 'email_hash' => 'STALE' ) );
		$before = $this->rows[0]['email_encrypted'];

		$this->strategy->execute( '', array() );

		$this->assertSame( Encryption::hash( 'joao@escola.gov.br' ), $this->rows[0]['email_hash'] );
		$this->assertSame(
			$before,
			$this->rows[0]['email_encrypted'],
			'The ciphertext was already canonical; rewriting it would churn the row for nothing.'
		);
		$this->assertCount( 1, $this->writes );
		$this->assertSame( array( 'email_hash' ), array_keys( $this->writes[0]['data'] ) );
	}

	/**
	 * A value carrying no identifier is reported, never rewritten.
	 *
	 * `...---` normalises to the empty string. Storing that would give every
	 * such row one shared searchable hash, which is the opposite of an
	 * identifier — so the row is left exactly as it is and a human is told.
	 */
	public function test_a_value_that_normalises_to_nothing_is_reported_not_written(): void {
		$this->seed_row( 10, array( 'cpf' => '...---' ) );

		$result = $this->strategy->execute( '', array() );

		$this->assertSame( array(), $this->writes );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * A refused write is reported per row and the batch carries on.
	 *
	 * `ffc_recruitment_candidate` carries `UNIQUE KEY uq_cpf_hash`, so two
	 * candidates whose CPFs differ only in punctuation collapse onto one hash
	 * the moment both are canonicalised and the second write is rejected. That
	 * is two rows for one person, which the punctuation had been hiding — and
	 * #1313 wants the count of exactly those before anyone designs a merge
	 * policy, so the card reports and continues rather than aborting.
	 */
	public function test_a_refused_write_is_reported_and_the_batch_continues(): void {
		$this->seed_row( 10, array( 'cpf' => '123.456.789-09' ) );
		$this->seed_row( 20, array( 'cpf' => '987.654.321-00' ) );
		$this->refuse_write = true;

		$result = $this->strategy->execute( '', array() );

		$this->assertCount( 2, $result['errors'], 'Both refusals must be reported; one abort would hide the second.' );
		$this->assertSame( 0, $result['processed'] );
	}

	/**
	 * A changed canonical-form rule re-arms the card.
	 *
	 * "Complete" means "every row matched the rules in force when it ran", so a
	 * rule change invalidates that sentence. Without the fingerprint the card
	 * would stay latched complete over rows nobody had re-examined.
	 */
	public function test_a_stale_rule_fingerprint_reports_everything_pending(): void {
		$this->seed_row( 10, array( 'email' => 'joao@escola.gov.br' ) );

		$this->options['ffc_identity_normalization_state'] = array(
			'fingerprint' => 'a-rule-set-that-is-no-longer-in-force',
			'cursors'     => array( 'appointments' => 999 ),
			'completed'   => true,
		);

		$status = $this->strategy->calculate_status( '', array() );

		$this->assertSame( 0, $status['migrated'] );
		$this->assertFalse( $status['is_complete'] );
	}

	/**
	 * The fingerprint is of the RULES, and it is stable across calls.
	 */
	public function test_the_rule_fingerprint_is_stable(): void {
		$this->assertSame(
			SensitiveFieldRegistry::normalizer_fingerprint(),
			SensitiveFieldRegistry::normalizer_fingerprint()
		);
		$this->assertNotSame( '', SensitiveFieldRegistry::normalizer_fingerprint() );
	}
}
