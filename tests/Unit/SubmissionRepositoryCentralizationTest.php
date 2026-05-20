<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Repositories\SubmissionRepository;

/**
 * Tests for the three SubmissionRepository methods added in the
 * issue #340 cleanup — `findMagicTokenById()`, `countByFormAndCpfHash()`,
 * and `clearQrCodeCache()`. Each centralizes a call site that used to
 * touch `wp_ffc_submissions` via raw wpdb.
 *
 * @covers \FreeFormCertificate\Repositories\SubmissionRepository
 */
class SubmissionRepositoryCentralizationTest extends TestCase {

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

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( 'wp_cache_flush_group' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing( fn ( $sql ) => $sql )
			->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function repo(): SubmissionRepository {
		return new SubmissionRepository();
	}

	// ------------------------------------------------------------------
	// findMagicTokenById()
	// ------------------------------------------------------------------

	public function test_find_magic_token_returns_string_when_present(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( 'tok-abc123' );

		$this->assertSame( 'tok-abc123', $this->repo()->findMagicTokenById( 5 ) );
	}

	public function test_find_magic_token_returns_null_when_row_missing(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );

		$this->assertNull( $this->repo()->findMagicTokenById( 5 ) );
	}

	public function test_find_magic_token_returns_null_when_token_empty_string(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '' );

		$this->assertNull( $this->repo()->findMagicTokenById( 5 ) );
	}

	public function test_find_magic_token_short_circuits_on_non_positive_id(): void {
		$this->wpdb->shouldNotReceive( 'get_var' );

		$this->assertNull( $this->repo()->findMagicTokenById( 0 ) );
		$this->assertNull( $this->repo()->findMagicTokenById( -1 ) );
	}

	// ------------------------------------------------------------------
	// countByFormAndCpfHash()
	// ------------------------------------------------------------------

	public function test_count_by_form_and_cpf_hash_returns_int(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '3' );

		$this->assertSame( 3, $this->repo()->countByFormAndCpfHash( 7, 'abc' ) );
	}

	public function test_count_by_form_and_cpf_hash_returns_zero_on_null(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );

		$this->assertSame( 0, $this->repo()->countByFormAndCpfHash( 7, 'abc' ) );
	}

	public function test_count_by_form_and_cpf_hash_short_circuits_on_invalid_inputs(): void {
		$this->wpdb->shouldNotReceive( 'get_var' );

		$this->assertSame( 0, $this->repo()->countByFormAndCpfHash( 0, 'abc' ) );
		$this->assertSame( 0, $this->repo()->countByFormAndCpfHash( 7, '' ) );
	}

	// ------------------------------------------------------------------
	// clearQrCodeCache()
	// ------------------------------------------------------------------

	public function test_clear_qr_code_cache_returns_affected_rows(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 12 );

		$this->assertSame( 12, $this->repo()->clearQrCodeCache() );
	}

	public function test_clear_qr_code_cache_returns_zero_when_prepare_fails(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( null );
		$this->wpdb->shouldNotReceive( 'query' );

		$this->assertSame( 0, $this->repo()->clearQrCodeCache() );
	}

	public function test_clear_qr_code_cache_returns_zero_when_wpdb_query_fails(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( false );

		$this->assertSame( 0, $this->repo()->clearQrCodeCache() );
	}

	// ------------------------------------------------------------------
	// sql_user_certificate_count_subquery() — issue #343 group C
	// ------------------------------------------------------------------

	public function test_sql_user_certificate_count_subquery_returns_self_contained_select(): void {
		$sql = $this->repo()->sql_user_certificate_count_subquery();

		$this->assertStringStartsWith( '(SELECT ', $sql );
		$this->assertStringEndsWith( ')', $sql );
		$this->assertStringContainsString( 'wp_ffc_submissions', $sql );
		$this->assertStringContainsString( 'GROUP BY user_id', $sql );
		$this->assertStringContainsString( "status != 'trash'", $sql );
	}
}
