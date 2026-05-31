<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\MaintenanceToolInterface;
use FreeFormCertificate\Maintenance\MaintenanceToolRegistry;
use FreeFormCertificate\Migrations\ObsoleteShortcodeCleaner;

/**
 * Tests for the pluggable maintenance-tool framework.
 *
 * @covers \FreeFormCertificate\Maintenance\MaintenanceToolRegistry
 * @covers \FreeFormCertificate\Migrations\ObsoleteShortcodeCleaner
 */
class MaintenanceToolRegistryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function fake_tool( string $id ): MaintenanceToolInterface {
		return new class( $id ) implements MaintenanceToolInterface {
			/** @var string */
			private $id;
			public function __construct( string $id ) {
				$this->id = $id;
			}
			public function get_id(): string {
				return $this->id;
			}
			public function get_title(): string {
				return 'Title ' . $this->id;
			}
			public function get_description(): string {
				return 'Desc ' . $this->id;
			}
			public function is_actionable(): bool {
				return false;
			}
			public function get_default_options(): array {
				return array();
			}
			public function run( array $options ): array {
				return array( 'ran' => $this->id );
			}
		};
	}

	public function test_register_and_get(): void {
		$registry = new MaintenanceToolRegistry();
		$tool     = $this->fake_tool( 'alpha' );
		$registry->register( $tool );

		$this->assertTrue( $registry->has( 'alpha' ) );
		$this->assertSame( $tool, $registry->get( 'alpha' ) );
	}

	public function test_get_unknown_returns_null(): void {
		$registry = new MaintenanceToolRegistry();
		$this->assertNull( $registry->get( 'missing' ) );
		$this->assertFalse( $registry->has( 'missing' ) );
	}

	public function test_all_preserves_insertion_order(): void {
		$registry = new MaintenanceToolRegistry();
		$registry->register( $this->fake_tool( 'first' ) );
		$registry->register( $this->fake_tool( 'second' ) );

		$ids = array_map( static fn ( MaintenanceToolInterface $t ): string => $t->get_id(), $registry->all() );
		$this->assertSame( array( 'first', 'second' ), $ids );
	}

	public function test_re_registering_same_id_replaces_but_keeps_position(): void {
		$registry = new MaintenanceToolRegistry();
		$registry->register( $this->fake_tool( 'a' ) );
		$registry->register( $this->fake_tool( 'b' ) );
		$replacement = $this->fake_tool( 'a' );
		$registry->register( $replacement );

		$this->assertSame( $replacement, $registry->get( 'a' ) );
		$ids = array_map( static fn ( MaintenanceToolInterface $t ): string => $t->get_id(), $registry->all() );
		$this->assertSame( array( 'a', 'b' ), $ids );
	}

	public function test_create_default_registers_obsolete_shortcode_cleaner(): void {
		$registry = MaintenanceToolRegistry::create_default();
		$tool     = $registry->get( 'obsolete_shortcode' );

		$this->assertInstanceOf( ObsoleteShortcodeCleaner::class, $tool );
		$this->assertInstanceOf( MaintenanceToolInterface::class, $tool );
	}

	public function test_cleaner_advertises_actionable_metadata(): void {
		$cleaner = new ObsoleteShortcodeCleaner();
		$this->assertSame( 'obsolete_shortcode', $cleaner->get_id() );
		$this->assertTrue( $cleaner->is_actionable() );
		$this->assertNotSame( '', $cleaner->get_title() );
		$this->assertNotSame( '', $cleaner->get_description() );
		$this->assertSame(
			array(
				'days'    => ObsoleteShortcodeCleaner::DEFAULT_DAYS,
				'dry_run' => true,
			),
			$cleaner->get_default_options()
		);
	}
}
