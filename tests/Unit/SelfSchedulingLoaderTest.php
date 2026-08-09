<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\SelfSchedulingLoader;

/**
 * Tests for SelfSchedulingLoader — the single bootstrap entry point for the
 * Self-Scheduling module (#563 B3). Pins that init() constructs the module's
 * runtime classes, gating the admin trio behind is_admin().
 *
 * @covers \FreeFormCertificate\SelfScheduling\SelfSchedulingLoader
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SelfSchedulingLoaderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Every class init() instantiates (overloaded so construction is a no-op).
	 *
	 * @var list<string>
	 */
	private const CLASSES = array(
		'SelfSchedulingAdmin',
		'SelfSchedulingEditor',
		'SelfSchedulingCPT',
		'AppointmentHandler',
		'AppointmentAjaxHandler',
		'AppointmentEmailHandler',
		'AppointmentReceiptHandler',
		'AppointmentCancellationHandler',
		'SelfSchedulingShortcode',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message, $args = array() ) {
				$ffc_type = isset( $args['type'] ) ? $args['type'] : 'info';
				$ffc_cls  = 'notice notice-' . $ffc_type;
				if ( ! empty( $args['dismissible'] ) ) { $ffc_cls .= ' is-dismissible'; }
				if ( ! empty( $args['additional_classes'] ) ) { $ffc_cls .= ' ' . implode( ' ', $args['additional_classes'] ); }
				$ffc_wrap = ! array_key_exists( 'paragraph_wrap', $args ) || $args['paragraph_wrap'];
				echo '<div class="' . $ffc_cls . '">' . ( $ffc_wrap ? '<p>' . $message . '</p>' : $message ) . '</div>';
			}
		);
		class_exists( '\\FreeFormCertificate\\SelfScheduling\\SelfSchedulingLoader' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function overload_all(): void {
		foreach ( self::CLASSES as $cls ) {
			Mockery::mock( 'overload:FreeFormCertificate\SelfScheduling\\' . $cls );
		}
	}

	public function test_init_wires_full_module_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->overload_all();

		( new SelfSchedulingLoader() )->init();

		// The appointments batched-export source registers (lazily) with the
		// shared registry when wiring the admin trio (#772).
		$this->assertTrue(
			\FreeFormCertificate\Core\SourceRegistry::has(
				\FreeFormCertificate\SelfScheduling\AppointmentExportSource::TYPE
			)
		);
	}

	public function test_init_skips_admin_trio_on_frontend(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$this->overload_all();

		( new SelfSchedulingLoader() )->init();

		$this->assertTrue( true );
	}

	public function test_registered_export_source_factory_constructs_the_source(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->overload_all();
		// The factory news both repositories; overload them so construction is a
		// no-op. AppointmentExportSource stays real so ::TYPE resolves and the
		// returned instance type-checks.
		Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' );
		Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );

		( new SelfSchedulingLoader() )->init();

		$source = \FreeFormCertificate\Core\SourceRegistry::get(
			\FreeFormCertificate\SelfScheduling\AppointmentExportSource::TYPE
		);

		$this->assertInstanceOf(
			\FreeFormCertificate\SelfScheduling\AppointmentExportSource::class,
			$source
		);
	}
}
