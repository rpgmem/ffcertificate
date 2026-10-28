<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\SourceRegistry;
use FreeFormCertificate\SelfScheduling\AppointmentAjaxHandler;
use FreeFormCertificate\SelfScheduling\AppointmentCancellationHandler;
use FreeFormCertificate\SelfScheduling\AppointmentEmailHandler;
use FreeFormCertificate\SelfScheduling\AppointmentExportSource;
use FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler;
use FreeFormCertificate\SelfScheduling\SelfSchedulingAdmin;
use FreeFormCertificate\SelfScheduling\SelfSchedulingCPT;
use FreeFormCertificate\SelfScheduling\SelfSchedulingEditor;
use FreeFormCertificate\SelfScheduling\SelfSchedulingLoader;
use FreeFormCertificate\SelfScheduling\SelfSchedulingShortcode;
use FreeFormCertificate\SelfScheduling\WaitlistPromoter;

/**
 * Tests for SelfSchedulingLoader — the single bootstrap entry point for the
 * Self-Scheduling module (#563 B3). Pins that init() wires the module's
 * runtime classes, gating the admin pair behind is_admin().
 *
 * The real classes are constructed: their constructors register hooks, which
 * the `add_action` / `add_filter` / `add_shortcode` stubs record, so "the
 * loader wires X" is asserted as "X's hook is registered once". The
 * repositories the appointment handler, shortcode and waitlist promoter
 * compose read only `$wpdb->prefix` while constructing; the `$wpdb` double
 * carries no expectation, so any query the wiring ran would fail the test.
 *
 * @covers \FreeFormCertificate\SelfScheduling\SelfSchedulingLoader
 */
class SelfSchedulingLoaderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Every registration seen, as "<kind>|<hook>|<callback>" => count.
	 *
	 * @var array<string, int>
	 */
	private array $registered = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		if ( $this->had_wpdb ) {
			$this->wpdb_backup = $GLOBALS['wpdb'];
		}
		$wpdb            = Mockery::mock( 'wpdb' );
		$wpdb->prefix    = 'wp_';
		$GLOBALS['wpdb'] = $wpdb;

		// The registry is static and outlives a test; the frontend test
		// asserts the appointments source is absent, so start empty.
		SourceRegistry::reset();

		$this->registered = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ): bool {
				return $this->record( 'action', (string) $hook, $callback );
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback ): bool {
				return $this->record( 'filter', (string) $hook, $callback );
			}
		);
		Functions\when( 'add_shortcode' )->alias(
			function ( $tag, $callback ): bool {
				return $this->record( 'shortcode', (string) $tag, $callback );
			}
		);
	}

	protected function tearDown(): void {
		SourceRegistry::reset();
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Record one registration.
	 *
	 * @param string $kind     action, filter or shortcode.
	 * @param string $hook     Hook name or shortcode tag.
	 * @param mixed  $callback The callback as registered.
	 * @return bool What WordPress returns.
	 */
	private function record( string $kind, string $hook, $callback ): bool {
		$key                      = $kind . '|' . $hook . '|' . self::callback_name( $callback );
		$this->registered[ $key ] = ( $this->registered[ $key ] ?? 0 ) + 1;
		return true;
	}

	/**
	 * A callback as text: "Class->method" for an instance, "Class::method" for a static.
	 *
	 * @param mixed $callback Callback.
	 * @return string
	 */
	private static function callback_name( $callback ): string {
		if ( is_array( $callback ) && 2 === count( $callback ) ) {
			$target = $callback[0];
			return is_object( $target )
				? get_class( $target ) . '->' . $callback[1]
				: $target . '::' . $callback[1];
		}
		if ( $callback instanceof \Closure ) {
			return 'Closure';
		}
		return is_string( $callback ) ? $callback : gettype( $callback );
	}

	/**
	 * How many times a callback was registered on a hook.
	 *
	 * @param string $kind     action, filter or shortcode.
	 * @param string $hook     Hook name or shortcode tag.
	 * @param string $class    Class owning the callback.
	 * @param string $method   Instance method.
	 * @return int
	 */
	private function count_of( string $kind, string $hook, string $class, string $method ): int {
		return $this->registered[ $kind . '|' . $hook . '|' . $class . '->' . $method ] ?? 0;
	}

	/**
	 * The admin-only registrations: one per class the is_admin() branch builds.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	private static function admin_wiring(): array {
		return array(
			'SelfSchedulingAdmin'  => array( 'action', 'admin_menu', SelfSchedulingAdmin::class, 'add_submenu_pages' ),
			'SelfSchedulingEditor' => array( 'action', 'add_meta_boxes', SelfSchedulingEditor::class, 'add_custom_metaboxes' ),
		);
	}

	/**
	 * The registrations wired on every request, one per class.
	 *
	 * AppointmentHandler registers no hook of its own; it is wired when the
	 * AJAX and cancellation handlers, which take it by type, register theirs.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	private static function common_wiring(): array {
		return array(
			'SelfSchedulingCPT'              => array( 'action', 'init', SelfSchedulingCPT::class, 'register_calendar_cpt' ),
			'AppointmentAjaxHandler'         => array( 'action', 'wp_ajax_ffc_book_appointment', AppointmentAjaxHandler::class, 'ajax_book_appointment' ),
			'AppointmentEmailHandler'        => array( 'action', 'ffcertificate_self_scheduling_appointment_created_email', AppointmentEmailHandler::class, 'send_booking_confirmation' ),
			'AppointmentReceiptHandler'      => array( 'action', 'template_redirect', AppointmentReceiptHandler::class, 'handle_receipt_request' ),
			'AppointmentCancellationHandler' => array( 'action', 'template_redirect', AppointmentCancellationHandler::class, 'handle_cancellation_request' ),
			'SelfSchedulingShortcode'        => array( 'shortcode', 'ffc_self_scheduling', SelfSchedulingShortcode::class, 'render_calendar' ),
			'WaitlistPromoter'               => array( 'action', 'ffcertificate_appointment_cancelled', WaitlistPromoter::class, 'on_appointment_cancelled' ),
		);
	}

	public function test_init_wires_full_module_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		( new SelfSchedulingLoader() )->init();

		foreach ( array_merge( self::admin_wiring(), self::common_wiring() ) as $name => $w ) {
			$this->assertSame( 1, $this->count_of( ...$w ), $name . ' must be wired exactly once in admin.' );
		}

		// The appointments batched-export source registers (lazily) with the
		// shared registry when wiring the admin pair (#772).
		$this->assertTrue( SourceRegistry::has( AppointmentExportSource::TYPE ) );
	}

	public function test_init_skips_admin_trio_on_frontend(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		( new SelfSchedulingLoader() )->init();

		foreach ( self::admin_wiring() as $name => $w ) {
			$this->assertSame( 0, $this->count_of( ...$w ), $name . ' must not be wired on the frontend.' );
		}
		foreach ( self::common_wiring() as $name => $w ) {
			$this->assertSame( 1, $this->count_of( ...$w ), $name . ' must be wired exactly once on the frontend.' );
		}

		// Wiring the admin pair is what registers the appointments export
		// source, so on the frontend it must not be there.
		$this->assertFalse( SourceRegistry::has( AppointmentExportSource::TYPE ) );
	}

	public function test_registered_export_source_factory_constructs_the_source(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		( new SelfSchedulingLoader() )->init();

		// The factory news both real repositories over the `$wpdb` double;
		// constructing them reads only the prefix.
		$source = SourceRegistry::get( AppointmentExportSource::TYPE );

		$this->assertInstanceOf( AppointmentExportSource::class, $source );
	}
}
