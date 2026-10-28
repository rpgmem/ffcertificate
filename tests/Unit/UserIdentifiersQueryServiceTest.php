<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Services\UserIdentifiersQueryService;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Focused tests for `UserIdentifiersQueryService` — the cross-table
 * aggregator for user-level CPF / RF / email identifiers introduced
 * in issue #343 group A. Existing UserManagerTest coverage already
 * exercises every public method transitively (UserManager delegates
 * to this service post-#343 group A); these cases pin the public
 * service contract directly for greppability + as a smoke check.
 *
 * Encryption and DocumentFormatter are the real classes: the rows carry
 * ciphertexts the real cipher wrote, and the masks are the real ones
 * (#2117).
 *
 * @covers \FreeFormCertificate\Services\UserIdentifiersQueryService
 */
class UserIdentifiersQueryServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/**
	 * A CPF in the `xxx.xxx.xxx-xx` mask a visitor types.
	 *
	 * @param string $cpf Eleven digits.
	 */
	private static function masked( string $cpf ): string {
		return substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9 );
	}

	/**
	 * An RF in the `xxx.xxx-x` mask.
	 *
	 * @param string $rf Seven digits.
	 */
	private static function masked_rf( string $rf ): string {
		return substr( $rf, 0, 3 ) . '.' . substr( $rf, 3, 3 ) . '-' . substr( $rf, 6 );
	}

	/**
	 * The partial mask the query service shows: `xxx.***.***-xx`.
	 *
	 * @param string $cpf Eleven digits.
	 */
	private static function partial( string $cpf ): string {
		return substr( $cpf, 0, 3 ) . '.***.***-' . substr( $cpf, 9 );
	}

	/**
	 * The partial RF mask: `xxx.***-x`.
	 *
	 * @param string $rf Seven digits.
	 */
	private static function partial_rf( string $rf ): string {
		return substr( $rf, 0, 3 ) . '.***-' . substr( $rf, 6 );
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		Functions\when( 'is_email' )->alias( function ( $v ) {
			return is_string( $v ) && false !== strpos( $v, '@' );
		} );

		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql ) => $sql )->byDefault();
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

	public function test_get_cpfs_masked_for_user_decrypts_and_masks(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array( array( 'cpf_encrypted' => Encryption::encrypt( Identifiers::CPF_A ), 'rf_encrypted' => null ) )
		);

		$out = UserIdentifiersQueryService::get_cpfs_masked_for_user( 42 );

		$this->assertSame( array( self::partial( Identifiers::CPF_A ) ), $out );
	}

	public function test_get_cpfs_masked_for_user_returns_empty_when_no_rows(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$this->assertSame( array(), UserIdentifiersQueryService::get_cpfs_masked_for_user( 42 ) );
	}

	public function test_get_cpfs_formatted_for_user_formats_by_type_and_dedupes(): void {
		$r1 = Encryption::encrypt( Identifiers::RF_A );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				// Both columns: the CPF wins, as in the masked list.
				array( 'cpf_encrypted' => Encryption::encrypt( Identifiers::CPF_A ), 'rf_encrypted' => $r1 ),
				// The same CPF stored with punctuation: one entry.
				array( 'cpf_encrypted' => Encryption::encrypt( self::masked( Identifiers::CPF_A ) ), 'rf_encrypted' => null ),
				array( 'cpf_encrypted' => null, 'rf_encrypted' => $r1 ),
				array( 'cpf_encrypted' => null, 'rf_encrypted' => $r1 ),
			)
		);

		$out = UserIdentifiersQueryService::get_cpfs_formatted_for_user( 42 );

		// Formatted in full, never masked.
		$this->assertSame( array( self::masked( Identifiers::CPF_A ), self::masked_rf( Identifiers::RF_A ) ), $out );
	}

	public function test_get_cpfs_formatted_for_user_skips_a_row_that_does_not_decrypt(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				// Not a ciphertext: decrypts to nothing.
				array( 'cpf_encrypted' => 'not-a-ciphertext', 'rf_encrypted' => null ),
				array( 'cpf_encrypted' => Encryption::encrypt( '' ), 'rf_encrypted' => null ),
				array( 'cpf_encrypted' => null, 'rf_encrypted' => Encryption::encrypt( Identifiers::RF_A ) ),
			)
		);

		$this->assertSame( array( self::masked_rf( Identifiers::RF_A ) ), UserIdentifiersQueryService::get_cpfs_formatted_for_user( 42 ) );
	}

	public function test_get_typed_identifiers_separates_cpfs_and_rfs(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array( 'cpf_encrypted' => Encryption::encrypt( Identifiers::CPF_A ), 'rf_encrypted' => null ),
				array( 'cpf_encrypted' => null, 'rf_encrypted' => Encryption::encrypt( Identifiers::RF_A ) ),
			)
		);

		$out = UserIdentifiersQueryService::get_typed_identifiers_for_user( 42 );

		$this->assertSame( array( self::partial( Identifiers::CPF_A ) ), $out['cpfs'] );
		$this->assertSame( array( self::partial_rf( Identifiers::RF_A ) ), $out['rfs'] );
	}

	public function test_get_emails_for_user_falls_back_to_wp_account_when_no_encrypted_rows(): void {
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array() );

		$user              = new \stdClass();
		$user->user_email  = 'wp@example.com';
		Functions\when( 'get_user_by' )->justReturn( $user );

		$this->assertSame( array( 'wp@example.com' ), UserIdentifiersQueryService::get_emails_for_user( 42 ) );
	}

	public function test_get_emails_for_user_decrypts_and_dedups_against_wp_account(): void {
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn(
			array( Encryption::encrypt( 'alice@example.com' ), Encryption::encrypt( 'alice@example.com' ) )
		);

		$user              = new \stdClass();
		$user->user_email  = 'alice@example.com';
		Functions\when( 'get_user_by' )->justReturn( $user );

		$out = UserIdentifiersQueryService::get_emails_for_user( 42 );

		// Two decrypted hits + one wp account email — but array_unique
		// dedups them to one.
		$this->assertSame( array( 'alice@example.com' ), $out );
	}

	/**
	 * #1060 — `get_col()` is mixed. A non-string used to be stringified and
	 * handed to the decrypter as if it were a ciphertext; it is now dropped,
	 * which is also what the SQL's `IS NOT NULL` intends.
	 *
	 * `get_emails_for_user()` swallows every exception raised inside its
	 * loop, so the output alone cannot tell the two behaviours apart. The
	 * real cipher can: every non-empty string it fails to decrypt raises its
	 * private failure counter, so an integer stringified to `'12345'` would
	 * move that counter, while a dropped one leaves it where it was. A NULL
	 * stringified to '' is skipped by the cipher without counting, so the
	 * integer is the value that proves the drop.
	 */
	public function test_get_emails_for_user_drops_non_string_column_values(): void {
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );
		$cipher = Encryption::encrypt( 'alice@example.com' );

		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( $cipher, null, 12345 ) );

		$user             = new \stdClass();
		$user->user_email = 'alice@example.com';
		Functions\when( 'get_user_by' )->justReturn( $user );

		$failures = new \ReflectionProperty( Encryption::class, 'decrypt_failure_count' );
		$failures->setAccessible( true );
		$before = $failures->getValue();

		$out = UserIdentifiersQueryService::get_emails_for_user( 42 );

		$this->assertSame( $before, $failures->getValue(), 'No non-string value reached the decrypter.' );
		$this->assertSame( array( 'alice@example.com' ), $out );
	}
}
