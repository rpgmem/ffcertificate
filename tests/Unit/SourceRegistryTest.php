<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\SourceRegistry;
use FreeFormCertificate\Core\BatchedExportSourceInterface;

/**
 * Tests for the SourceRegistry: register / has / get / types / reset, the
 * lazy-factory contract, and the guard that a factory returning a non-source
 * yields null. (Issue #772.)
 *
 * @covers \FreeFormCertificate\Core\SourceRegistry
 */
class SourceRegistryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		class_exists( '\\FreeFormCertificate\Core\SourceRegistry' );
		SourceRegistry::reset();
	}

	protected function tearDown(): void {
		SourceRegistry::reset();
		parent::tearDown();
	}

	/** A minimal source stub (only type() is exercised here). */
	private function stub_source( string $type ): BatchedExportSourceInterface {
		return new class( $type ) implements BatchedExportSourceInterface {
			private string $t;
			public function __construct( string $t ) {
				$this->t = $t;
			}
			public function type(): string {
				return $this->t; }
			public function authorize_start(): void {}
			public function authorize_batch( array $job ): void {}
			public function authorize_download( array $job ): void {}
			public function job_owner_fields(): array {
				return array(); }
			public function sanitize_filters(): array {
				return array(); }
			public function count( array $filters ): int {
				return 0; }
			public function build_context( array $filters ): array {
				return array(); }
			public function header( array $filters, array $context ): array {
				return array(); }
			public function filename( array $filters, array $context ): string {
				return 'x.csv'; }
			public function fetch_page( array $filters, array $context, int $cursor, int $size ): array {
				return array(); }
			public function cursor_of( array $row ): int {
				return 0; }
			public function format_row( array $row, array $context ): array {
				return array(); }
			public function extra_start_response( string $job_id, array $job ): array {
				return array(); }
			public function on_complete( string $job_id, array $job ): void {}
			public function on_before_download( array $job ): void {}
		};
	}

	public function test_unknown_type_is_absent(): void {
		$this->assertFalse( SourceRegistry::has( 'nope' ) );
		$this->assertNull( SourceRegistry::get( 'nope' ) );
		$this->assertSame( array(), SourceRegistry::types() );
	}

	public function test_register_then_get_builds_via_factory(): void {
		$built = 0;
		SourceRegistry::register(
			'demo',
			function () use ( &$built ) {
				++$built;
				return $this->stub_source( 'demo' );
			}
		);

		// Registration is lazy — the factory hasn't run yet.
		$this->assertSame( 0, $built );
		$this->assertTrue( SourceRegistry::has( 'demo' ) );
		$this->assertContains( 'demo', SourceRegistry::types() );

		$source = SourceRegistry::get( 'demo' );
		$this->assertInstanceOf( BatchedExportSourceInterface::class, $source );
		$this->assertSame( 'demo', $source->type() );
		$this->assertSame( 1, $built, 'factory invoked once on get()' );
	}

	public function test_get_returns_null_when_factory_yields_non_source(): void {
		SourceRegistry::register( 'bad', static fn() => new \stdClass() );
		$this->assertTrue( SourceRegistry::has( 'bad' ) );
		$this->assertNull( SourceRegistry::get( 'bad' ) );
	}

	public function test_register_replaces_existing_factory(): void {
		SourceRegistry::register( 'dup', fn() => $this->stub_source( 'first' ) );
		SourceRegistry::register( 'dup', fn() => $this->stub_source( 'second' ) );

		$source = SourceRegistry::get( 'dup' );
		$this->assertNotNull( $source );
		$this->assertSame( 'second', $source->type() );
		$this->assertSame( array( 'dup' ), SourceRegistry::types() );
	}

	public function test_reset_clears_all(): void {
		SourceRegistry::register( 'a', fn() => $this->stub_source( 'a' ) );
		SourceRegistry::register( 'b', fn() => $this->stub_source( 'b' ) );
		$this->assertCount( 2, SourceRegistry::types() );

		SourceRegistry::reset();
		$this->assertSame( array(), SourceRegistry::types() );
		$this->assertFalse( SourceRegistry::has( 'a' ) );
	}
}
