<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\RecordAutosave;
use FreeFormCertificate\Core\SourceRegistry;
use FreeFormCertificate\Reregistration\ReregistrationAdmin;
use FreeFormCertificate\Reregistration\ReregistrationAjaxHandler;
use FreeFormCertificate\Reregistration\ReregistrationExportSource;
use FreeFormCertificate\Reregistration\ReregistrationFrontend;
use FreeFormCertificate\Reregistration\ReregistrationImportAjaxHandler;
use FreeFormCertificate\Reregistration\ReregistrationLoader;
use FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReregistrationLoader — the single bootstrap entry point for the
 * Reregistration module (#563 B3). Pins that init() wires the admin screens
 * (when is_admin()), the frontend, and the standard-fields seeder, each once.
 *
 * The real classes run; `add_action` records what each registers, so "the
 * loader wired X" is read off the hooks X's own `init()` / `register()` adds.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationLoader
 */
class ReregistrationLoaderTest extends TestCase {

	/**
	 * Every add_action call, in order.
	 *
	 * @var list<array{hook: string, callback: string, priority: int, args: int}>
	 */
	private array $actions = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->actions = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority = 10, $args = 1 ): bool {
				$this->actions[] = array(
					'hook'     => (string) $hook,
					'callback' => self::describe( $callback ),
					'priority' => (int) $priority,
					'args'     => (int) $args,
				);
				return true;
			}
		);

		// Both registries are static and outlive the test.
		RecordAutosave::reset();
		SourceRegistry::reset();
	}

	protected function tearDown(): void {
		RecordAutosave::reset();
		SourceRegistry::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_init_wires_admin_frontend_and_seeder(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		( new ReregistrationLoader() )->init();

		// Admin screens: ReregistrationAdmin::init() ran once.
		$this->assertSame( array( ReregistrationAdmin::class . '::add_menu@30' ), $this->registered( 'admin_menu' ) );
		$this->assertSame( array( ReregistrationAdmin::class . '::enqueue_assets@10' ), $this->registered( 'admin_enqueue_scripts' ) );
		$this->assertSame( array( ReregistrationAjaxHandler::class . '::ajax_generate_record@10' ), $this->registered( 'wp_ajax_ffc_generate_record' ) );
		$this->assertSame( array( ReregistrationImportAjaxHandler::class . '::ajax_start@10' ), $this->registered( 'wp_ajax_ffc_rereg_import_start' ) );
		$this->assertSame( array( 'reregistration' ), RecordAutosave::entities(), 'The campaign edit screen registers its record autosave.' );

		// The batched-export source registers (lazily) with the shared registry
		// when wiring the admin screens (#772).
		$this->assertTrue( SourceRegistry::has( ReregistrationExportSource::TYPE ) );
		$this->assertInstanceOf( ReregistrationExportSource::class, SourceRegistry::get( ReregistrationExportSource::TYPE ) );

		$this->assert_frontend_and_seeder_wired_once();
	}

	public function test_init_skips_admin_on_frontend(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		( new ReregistrationLoader() )->init();

		// ReregistrationAdmin must NOT be initialised on a frontend request.
		$admin_hooks = array_filter(
			$this->actions,
			static function ( array $action ): bool {
				return 0 === strpos( $action['callback'], ReregistrationAdmin::class . '::' )
					|| 0 === strpos( $action['callback'], ReregistrationAjaxHandler::class . '::' )
					|| 0 === strpos( $action['callback'], ReregistrationImportAjaxHandler::class . '::' );
			}
		);
		$this->assertSame( array(), array_values( $admin_hooks ), 'No admin-screen hook on a frontend request.' );
		$this->assertSame( array(), RecordAutosave::entities(), 'No record autosave on a frontend request.' );
		$this->assertFalse( SourceRegistry::has( ReregistrationExportSource::TYPE ), 'No export source on a frontend request.' );

		$this->assert_frontend_and_seeder_wired_once();
	}

	/**
	 * The frontend's AJAX and the seeder's hooks are each registered once.
	 *
	 * @return void
	 */
	private function assert_frontend_and_seeder_wired_once(): void {
		$this->assertSame( array( ReregistrationFrontend::class . '::ajax_get_form@10' ), $this->registered( 'wp_ajax_ffc_get_reregistration_form' ) );
		$this->assertSame( array( ReregistrationFrontend::class . '::ajax_submit@10' ), $this->registered( 'wp_ajax_ffc_submit_reregistration' ) );
		$this->assertSame( array( ReregistrationFrontend::class . '::ajax_save_draft@10' ), $this->registered( 'wp_ajax_ffc_save_reregistration_draft' ) );
		$this->assertSame( array( ReregistrationFrontend::class . '::ajax_import_previous@10' ), $this->registered( 'wp_ajax_ffc_import_previous_reregistration' ) );

		$seeder = array_values(
			array_filter(
				$this->actions,
				static function ( array $action ): bool {
					return 0 === strpos( $action['callback'], ReregistrationStandardFieldsSeeder::class . '::' );
				}
			)
		);
		$this->assertSame(
			array(
				array(
					'hook'     => 'ffc_audience_created',
					'callback' => ReregistrationStandardFieldsSeeder::class . '::on_audience_created',
					'priority' => 10,
					'args'     => 2,
				),
				array(
					'hook'     => 'admin_init',
					'callback' => ReregistrationStandardFieldsSeeder::class . '::maybe_relabel_union',
					'priority' => 10,
					'args'     => 1,
				),
			),
			$seeder,
			'The standard-fields seeder registers once.'
		);
	}

	/**
	 * Callbacks registered on one hook, as `Class::method@priority`.
	 *
	 * @param string $hook Hook name.
	 * @return list<string>
	 */
	private function registered( string $hook ): array {
		$out = array();
		foreach ( $this->actions as $action ) {
			if ( $hook === $action['hook'] ) {
				$out[] = $action['callback'] . '@' . $action['priority'];
			}
		}
		return $out;
	}

	/**
	 * A callback as `Class::method`.
	 *
	 * @param mixed $callback Callback given to add_action.
	 * @return string
	 */
	private static function describe( $callback ): string {
		if ( is_array( $callback ) ) {
			$owner = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return $owner . '::' . $callback[1];
		}
		return is_string( $callback ) ? $callback : 'closure';
	}
}
