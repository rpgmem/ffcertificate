<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin;
use FreeFormCertificate\Admin\AdminLoader;
use FreeFormCertificate\Core\RecordAutosave;
use FreeFormCertificate\Core\SourceRegistry;

/**
 * Tests for AdminLoader — the single bootstrap entry point for the Admin
 * module (#563 B3 coupling reduction). Pins that init() constructs the
 * stateful trio (CsvExporter / Admin / AdminAjax) and wires every admin-only
 * endpoint exactly once, so a future refactor can't silently drop one when the
 * orchestrator stops newing them up directly.
 *
 * The real classes run: their constructors and `init()` methods register
 * hooks, which the `add_action` / `add_filter` stubs record, so "the loader
 * wires X" is asserted as "X's hook is registered once". Nothing in the
 * wiring path reaches the database; the `$wpdb` double carries no
 * expectation, so a query would fail the test.
 *
 * @covers \FreeFormCertificate\Admin\AdminLoader
 */
class AdminLoaderTest extends TestCase {

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

		// The registry is static and outlives a test; the trio's export
		// source is asserted on, so start empty.
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
		Functions\when( 'is_admin' )->justReturn( true );
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
	 * @param string $kind     action or filter.
	 * @param string $hook     Hook name.
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
	 * One registration per class the loader wires: kind, hook, callback.
	 *
	 * Statics name the class's `init()` registration; instances the trio's
	 * constructors and the two held pages. CsvExporter registers no hook --
	 * its wiring is the export source asserted on separately.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	private static function expected_wiring(): array {
		return array(
			'Admin'                                 => array( 'action', 'admin_menu', Admin\Admin::class . '->register_admin_menu' ),
			'AdminAjax'                             => array( 'action', 'wp_ajax_ffc_search_user', Admin\AdminAjax::class . '->search_user' ),
			'AdminUserColumns'                      => array( 'filter', 'manage_users_columns', Admin\AdminUserColumns::class . '::add_custom_columns' ),
			'AdminUserCapabilities'                 => array( 'action', 'wp_ajax_ffc_toggle_user_role', Admin\AdminUserCapabilities::class . '::ajax_toggle_user_role' ),
			'RoleCapabilityEditor'                  => array( 'action', 'wp_ajax_ffc_set_role_cap', Admin\RoleCapabilityEditor::class . '::ajax_set_role_cap' ),
			'AdminMenuVisibility'                   => array( 'action', 'admin_init', Admin\AdminMenuVisibility::class . '::block_url_access' ),
			'AdminMenuIcons'                        => array( 'action', 'admin_enqueue_scripts', Admin\AdminMenuIcons::class . '::print_css' ),
			'DeviceThresholdUpgradeNotice'          => array( 'action', 'admin_notices', Admin\DeviceThresholdUpgradeNotice::class . '::maybe_render' ),
			'CaptchaModeNotice'                     => array( 'action', 'admin_notices', Admin\CaptchaModeNotice::class . '::maybe_render' ),
			'EncryptionKeyHealthNotice'             => array( 'action', 'admin_notices', Admin\EncryptionKeyHealthNotice::class . '::maybe_render' ),
			'HtmlRefsNotice'                        => array( 'action', 'admin_notices', Admin\HtmlRefsNotice::class . '::maybe_render' ),
			'SettingsAjaxEndpoint'                  => array( 'action', 'wp_ajax_' . Admin\SettingsAjaxEndpoint::AJAX_ACTION, Admin\SettingsAjaxEndpoint::class . '::handle' ),
			'QrDesignPreviewAjaxEndpoint'           => array( 'action', 'wp_ajax_' . Admin\QrDesignPreviewAjaxEndpoint::AJAX_ACTION, Admin\QrDesignPreviewAjaxEndpoint::class . '::handle' ),
			'FormMetaAjaxEndpoint'                  => array( 'action', 'wp_ajax_' . Admin\FormMetaAjaxEndpoint::AJAX_ACTION, Admin\FormMetaAjaxEndpoint::class . '::handle' ),
			'FormPublicCsvActions'                  => array( 'action', 'wp_ajax_' . Admin\FormPublicCsvActions::AJAX_ACTION, Admin\FormPublicCsvActions::class . '::handle' ),
			// The record autosave endpoint lives in Core (#1757).
			'RecordAutosave'                        => array( 'action', 'wp_ajax_' . RecordAutosave::AJAX_ACTION, RecordAutosave::class . '::handle' ),
			'LocationsAjaxEndpoint'                 => array( 'action', 'wp_ajax_' . Admin\LocationsAjaxEndpoint::ACTION_SAVE, Admin\LocationsAjaxEndpoint::class . '::handle_save' ),
			'CacheActionsAjaxEndpoint'              => array( 'action', 'wp_ajax_' . Admin\CacheActionsAjaxEndpoint::ACTION_CLEAR, Admin\CacheActionsAjaxEndpoint::class . '::handle_clear' ),
			'FormFeaturesAjaxEndpoint'              => array( 'action', 'wp_ajax_' . Admin\FormFeaturesAjaxEndpoint::AJAX_ACTION, Admin\FormFeaturesAjaxEndpoint::class . '::handle' ),
			'MigrationActionsAjaxEndpoint'          => array( 'action', 'wp_ajax_' . Admin\MigrationActionsAjaxEndpoint::AJAX_ACTION, Admin\MigrationActionsAjaxEndpoint::class . '::handle' ),
			'ActivityLogAjaxEndpoint'               => array( 'action', 'wp_ajax_' . Admin\ActivityLogAjaxEndpoint::AJAX_ACTION, Admin\ActivityLogAjaxEndpoint::class . '::handle' ),
			'SubmissionsBulkActionsAjaxEndpoint'    => array( 'action', 'wp_ajax_' . Admin\SubmissionsBulkActionsAjaxEndpoint::AJAX_ACTION, Admin\SubmissionsBulkActionsAjaxEndpoint::class . '::handle' ),
			'FormListColumns'                       => array( 'filter', 'manage_ffc_form_posts_columns', Admin\FormListColumns::class . '::add_columns' ),
			'AdminUserCustomFields'                 => array( 'action', 'show_user_profile', Admin\AdminUserCustomFields::class . '::render_section' ),
			'IdentitySearchAjaxEndpoint'            => array( 'action', 'wp_ajax_' . Admin\IdentitySearchAjaxEndpoint::AJAX_ACTION, Admin\IdentitySearchAjaxEndpoint::class . '::handle' ),
			'IdentityCorrectionPreviewAjaxEndpoint' => array( 'action', 'wp_ajax_' . Admin\IdentityCorrectionPreviewAjaxEndpoint::AJAX_ACTION, Admin\IdentityCorrectionPreviewAjaxEndpoint::class . '::handle' ),
			'IdentityMergePreviewAjaxEndpoint'      => array( 'action', 'wp_ajax_' . Admin\IdentityMergePreviewAjaxEndpoint::AJAX_ACTION, Admin\IdentityMergePreviewAjaxEndpoint::class . '::handle' ),
			'IdentityResolutionPage'                => array( 'action', 'admin_menu', Admin\IdentityResolutionPage::class . '->register_menu' ),
			'QuizGradingHandler'                    => array( 'action', 'admin_post_' . Admin\QuizGradingHandler::ACTION, Admin\QuizGradingHandler::class . '->handle' ),
		);
	}

	public function test_init_wires_every_admin_module_class(): void {
		$handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );

		( new AdminLoader( $handler ) )->init();

		foreach ( self::expected_wiring() as $name => $w ) {
			$this->assertSame(
				1,
				$this->registered[ implode( '|', $w ) ] ?? 0,
				$name . ' must be wired exactly once.'
			);
		}

		// CsvExporter is constructed and handed to Admin, whose constructor
		// registers the submissions export source through it (#772).
		$this->assertTrue( SourceRegistry::has( Admin\SubmissionsExportSource::TYPE ) );
	}

	/**
	 * This loader must NOT compose the expired-tickets cron (#1234).
	 *
	 * It did compose, and that is why it never ran: `AdminLoader` is only built
	 * inside `if ( is_admin() )`, and `wp-cron.php` defines `DOING_CRON` and
	 * never `WP_ADMIN` -- so `is_admin()` is false in every context that
	 * EXECUTES the hook. The registration now lives in
	 * `Loader::define_admin_hooks()`, which despite its name runs on every
	 * request, and that is where the module-gate tests live.
	 *
	 * THE OLD TEST PROVED THE RIGHT THING IN THE WRONG PLACE
	 *
	 * It charged that `AdminLoader::init()` called
	 * `ExpiredTicketsCleanup::init()`, which was true and useless: it pinned a
	 * wiring that never fired. A green expectation does not say the hook is ever
	 * called -- only that the path the test stages registers it.
	 *
	 * The real classes run here, so the check is on the hook itself: nothing
	 * the loader wires registers any callback on the cron hook.
	 */
	public function test_init_does_not_wire_the_expired_tickets_cron(): void {
		$handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );

		( new AdminLoader( $handler ) )->init();

		// Self-check: the recorder saw the wiring (a registration the loader
		// is known to make), so an empty match below is an absence and not a
		// collapsed recording.
		$this->assertSame( 1, $this->registered[ implode( '|', self::expected_wiring()['Admin'] ) ] ?? 0 );

		$prefix  = 'action|' . Admin\ExpiredTicketsCleanup::CRON_HOOK . '|';
		$on_cron = array_filter(
			array_keys( $this->registered ),
			static fn( string $key ): bool => 0 === strpos( $key, $prefix )
		);
		$this->assertSame( array(), array_values( $on_cron ) );
	}
}
