<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\PiiExport;

/**
 * The PII export rule (#1863): personal columns in clear only for the
 * module's PII cap, masked otherwise, and a clear export recorded.
 *
 * @covers \FreeFormCertificate\Core\PiiExport
 * @preserveGlobalState disabled
 */
class PiiExportTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var bool Whether $GLOBALS['wpdb'] existed before the test. */
	private bool $had_wpdb = false;

	/** @var mixed The $wpdb the test replaced. */
	private $wpdb_backup = null;

	/** @var array<string, mixed> $_SERVER before the test. */
	private array $server_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_current_user_id' )->justReturn( 4 );
		Functions\when( 'is_email' )->alias( static fn( $v ) => is_string( $v ) && 1 === substr_count( $v, '@' ) );
		// No dynamic sensitive key unless a test says so.
		Functions\when( 'wp_cache_get' )->justReturn( array() );

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$this->server_backup = $_SERVER;
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		// No test here should query: a log entry is only buffered.
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$GLOBALS['wpdb'] = $wpdb;

		$this->reset_activity_log();
	}

	protected function tearDown(): void {
		$this->reset_activity_log();
		$_SERVER = $this->server_backup;
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_allowed_follows_the_module_pii_cap(): void {
		Functions\when( 'user_can' )->alias( static fn( $uid, $cap ) => 'ffc_view_appointments_pii' === $cap );

		$this->assertTrue( PiiExport::allowed( 'ffc_view_appointments_pii' ) );
		$this->assertFalse( PiiExport::allowed( 'ffc_view_certificates_pii' ) );
	}

	public function test_a_site_administrator_exports_in_clear(): void {
		Functions\when( 'user_can' )->alias( static fn( $uid, $cap ) => 'manage_options' === $cap );

		$this->assertTrue( PiiExport::allowed( 'ffc_view_reregistration_pii' ) );
	}

	public function test_is_personal_knows_the_identifiers_and_the_dynamic_sensitive_keys(): void {
		Functions\when( 'wp_cache_get' )->justReturn( array( 'rg_estadual' => true ) );

		foreach ( array( 'cpf', 'rf', 'rg', 'email', 'user_email', 'phone', 'telefone', 'user_ip', 'rg_estadual' ) as $key ) {
			$this->assertTrue( PiiExport::is_personal( $key ), $key );
		}
		foreach ( array( 'name', 'course', 'data', 'status' ) as $key ) {
			$this->assertFalse( PiiExport::is_personal( $key ), $key );
		}
	}

	public function test_cell_masks_a_personal_value_only_when_the_export_is_not_clear(): void {
		$this->assertSame( 'ana@x.com', PiiExport::cell( 'email', 'ana@x.com', true ) );
		$this->assertSame( 'a***@x.com', PiiExport::cell( 'email', 'ana@x.com', false ) );
		$this->assertSame( 'a***@x.com', PiiExport::cell( 'user_email', 'ana@x.com', false ) );
		$this->assertSame( '203.0.***.***', PiiExport::cell( 'user_ip', '203.0.113.7', false ) );
		$this->assertSame( '518.***.***-80', PiiExport::cell( 'cpf', '51817842080', false ) );
		$this->assertSame( 'Math', PiiExport::cell( 'course', 'Math', false ), 'A column that is not personal stays.' );
		$this->assertSame( '', PiiExport::cell( 'cpf', '', false ) );
	}

	/**
	 * The real ActivityLog::log() runs and buffers the entry; the buffer is
	 * what a shutdown flush would write, so the entry is asserted there and
	 * no query reaches the database.
	 */
	public function test_log_records_the_export_and_its_rows(): void {
		// The activity log on, every level and category logged, no debug area
		// on: get_option is stubbed so the branch does not depend on order.
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = false ) => 'ffc_settings' === $key ? array( 'enable_activity_log' => 1 ) : $default
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'current_time' )->justReturn( '2026-10-28 10:00:00' );
		Functions\when( 'add_action' )->justReturn( true );

		PiiExport::log( 'submissions', 7 );

		$buffer = $this->activity_buffer();
		$this->assertCount( 1, $buffer, 'One activity-log entry is buffered.' );
		$entry = $buffer[0];
		$this->assertSame( 'pii_exported', $entry['action'] );
		$this->assertSame( 'info', $entry['level'] );
		$this->assertSame( array( 'export' => 'submissions', 'rows' => 7 ), json_decode( (string) $entry['context'], true ) );
		$this->assertSame( 4, $entry['user_id'], 'The exporting user is recorded.' );
		$this->assertSame( 0, $entry['submission_id'] );
	}

	/**
	 * ActivityLog's private static write buffer.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function activity_buffer(): array {
		$ref = new \ReflectionProperty( ActivityLog::class, 'write_buffer' );
		$ref->setAccessible( true );
		$value = $ref->getValue();
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Empty ActivityLog's static buffer and switch its bulk-operation pause
	 * off, so no entry leaks between tests in the shared process.
	 */
	private function reset_activity_log(): void {
		foreach ( array( 'write_buffer' => array(), 'logging_disabled' => false ) as $name => $value ) {
			$ref = new \ReflectionProperty( ActivityLog::class, $name );
			$ref->setAccessible( true );
			$ref->setValue( null, $value );
		}
	}
}
