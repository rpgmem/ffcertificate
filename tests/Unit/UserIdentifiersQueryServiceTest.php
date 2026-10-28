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

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

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
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_cpfs_masked_for_user_decrypts_and_masks(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array( array( 'cpf_encrypted' => Encryption::encrypt( '12345678901' ), 'rf_encrypted' => null ) )
		);

		$out = UserIdentifiersQueryService::get_cpfs_masked_for_user( 42 );

		$this->assertSame( array( '123.***.***-01' ), $out );
	}

	public function test_get_cpfs_masked_for_user_returns_empty_when_no_rows(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$this->assertSame( array(), UserIdentifiersQueryService::get_cpfs_masked_for_user( 42 ) );
	}

	public function test_get_cpfs_formatted_for_user_formats_by_type_and_dedupes(): void {
		$r1 = Encryption::encrypt( '7654321' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				// Both columns: the CPF wins, as in the masked list.
				array( 'cpf_encrypted' => Encryption::encrypt( '12345678901' ), 'rf_encrypted' => $r1 ),
				// The same CPF stored with punctuation: one entry.
				array( 'cpf_encrypted' => Encryption::encrypt( '123.456.789-01' ), 'rf_encrypted' => null ),
				array( 'cpf_encrypted' => null, 'rf_encrypted' => $r1 ),
				array( 'cpf_encrypted' => null, 'rf_encrypted' => $r1 ),
			)
		);

		$out = UserIdentifiersQueryService::get_cpfs_formatted_for_user( 42 );

		// Formatted in full, never masked.
		$this->assertSame( array( '123.456.789-01', '765.432-1' ), $out );
	}

	public function test_get_cpfs_formatted_for_user_skips_a_row_that_does_not_decrypt(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				// Not a ciphertext: decrypts to nothing.
				array( 'cpf_encrypted' => 'not-a-ciphertext', 'rf_encrypted' => null ),
				array( 'cpf_encrypted' => Encryption::encrypt( '' ), 'rf_encrypted' => null ),
				array( 'cpf_encrypted' => null, 'rf_encrypted' => Encryption::encrypt( '7654321' ) ),
			)
		);

		$this->assertSame( array( '765.432-1' ), UserIdentifiersQueryService::get_cpfs_formatted_for_user( 42 ) );
	}

	public function test_get_typed_identifiers_separates_cpfs_and_rfs(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array( 'cpf_encrypted' => Encryption::encrypt( '12345678901' ), 'rf_encrypted' => null ),
				array( 'cpf_encrypted' => null, 'rf_encrypted' => Encryption::encrypt( '7654321' ) ),
			)
		);

		$out = UserIdentifiersQueryService::get_typed_identifiers_for_user( 42 );

		$this->assertSame( array( '123.***.***-01' ), $out['cpfs'] );
		$this->assertSame( array( '765.***-1' ), $out['rfs'] );
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
	 * #1060 — `get_col()` is mixed. A NULL used to be stringified to ''
	 * and handed to the decrypter as if it were a ciphertext; it is now
	 * dropped, which is also what the SQL's `IS NOT NULL` intends.
	 *
	 * The assertion is on the arguments the decrypter actually received,
	 * not on a Mockery expectation: `get_emails_for_user()` swallows
	 * every exception raised inside its loop, so an unmatched call there
	 * is silently discarded and an expectation-based test passes against
	 * the old behaviour too.
	 *
	 * The real cipher answers a non-ciphertext with NULL, so only a spy on
	 * the decrypter can tell the two behaviours apart: this test keeps its
	 * alias mock, and its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_emails_for_user_drops_non_string_column_values(): void {
		$seen    = array();
		$encMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
		$encMock->shouldReceive( 'decrypt' )->andReturnUsing(
			function ( $value ) use ( &$seen ) {
				$seen[] = $value;
				return 'alice@example.com';
			}
		);

		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( 'cipher-1', null ) );

		$user             = new \stdClass();
		$user->user_email = 'alice@example.com';
		Functions\when( 'get_user_by' )->justReturn( $user );

		$out = UserIdentifiersQueryService::get_emails_for_user( 42 );

		$this->assertSame( array( 'cipher-1' ), $seen );
		$this->assertSame( array( 'alice@example.com' ), $out );
	}
}
