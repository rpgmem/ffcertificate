<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Services\UserIdentifiersQueryService;

/**
 * Focused tests for `UserIdentifiersQueryService` — the cross-table
 * aggregator for user-level CPF / RF / email identifiers introduced
 * in issue #343 group A. Existing UserManagerTest coverage already
 * exercises every public method transitively (UserManager delegates
 * to this service post-#343 group A); these cases pin the public
 * service contract directly for greppability + as a smoke check.
 *
 * @covers \FreeFormCertificate\Services\UserIdentifiersQueryService
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UserIdentifiersQueryServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		Functions\when( 'is_email' )->alias( function ( $v ) {
			return is_string( $v ) && false !== strpos( $v, '@' );
		} );

		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql ) => $sql )->byDefault();

		// Default Utils stub: the service calls get_submissions_table().
		$utilsMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' );
		$utilsMock->shouldReceive( 'get_submissions_table' )->andReturn( 'wp_ffc_submissions' )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_cpfs_masked_for_user_decrypts_and_masks(): void {
		$encMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
		$encMock->shouldReceive( 'decrypt' )->andReturn( '12345678901' );

		$fmtMock = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
		$fmtMock->shouldReceive( 'mask_cpf' )->andReturn( '123.***.***-01' );

		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array( array( 'cpf_encrypted' => 'cipher', 'rf_encrypted' => null ) )
		);

		$out = UserIdentifiersQueryService::get_cpfs_masked_for_user( 42 );

		$this->assertSame( array( '123.***.***-01' ), $out );
	}

	public function test_get_cpfs_masked_for_user_returns_empty_when_no_rows(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$this->assertSame( array(), UserIdentifiersQueryService::get_cpfs_masked_for_user( 42 ) );
	}

	public function test_get_typed_identifiers_separates_cpfs_and_rfs(): void {
		$encMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
		$encMock->shouldReceive( 'decrypt' )->andReturnUsing( fn ( $v ) => 'plain-' . $v );

		$fmtMock = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
		$fmtMock->shouldReceive( 'mask_cpf' )->andReturnUsing( fn ( $v ) => 'mask:' . $v );

		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array( 'cpf_encrypted' => 'c1',  'rf_encrypted' => null ),
				array( 'cpf_encrypted' => null,  'rf_encrypted' => 'r1' ),
			)
		);

		$out = UserIdentifiersQueryService::get_typed_identifiers_for_user( 42 );

		$this->assertSame( array( 'mask:plain-c1' ), $out['cpfs'] );
		$this->assertSame( array( 'mask:plain-r1' ), $out['rfs'] );
	}

	public function test_get_emails_for_user_falls_back_to_wp_account_when_no_encrypted_rows(): void {
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array() );

		$user              = new \stdClass();
		$user->user_email  = 'wp@example.com';
		Functions\when( 'get_user_by' )->justReturn( $user );

		$this->assertSame( array( 'wp@example.com' ), UserIdentifiersQueryService::get_emails_for_user( 42 ) );
	}

	public function test_get_emails_for_user_decrypts_and_dedups_against_wp_account(): void {
		$encMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
		$encMock->shouldReceive( 'decrypt' )->andReturn( 'alice@example.com' );

		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( 'cipher-1', 'cipher-2' ) );

		$user              = new \stdClass();
		$user->user_email  = 'alice@example.com';
		Functions\when( 'get_user_by' )->justReturn( $user );

		$out = UserIdentifiersQueryService::get_emails_for_user( 42 );

		// Two decrypted hits + one wp account email — but array_unique
		// dedups them to one.
		$this->assertSame( array( 'alice@example.com' ), $out );
	}
}
