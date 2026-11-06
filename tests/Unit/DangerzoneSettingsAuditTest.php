<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\DangerzoneSettingsAudit;
use FreeFormCertificate\Admin\SettingsAjaxEndpoint;
use FreeFormCertificate\Core\ActivityLogItems;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use PHPUnit\Framework\TestCase;

/**
 * Changes to the settings that delete or suppress data are audited (#2340).
 *
 * @covers \FreeFormCertificate\Admin\DangerzoneSettingsAudit
 */
class DangerzoneSettingsAuditTest extends TestCase {

	/** @var array<string, mixed> The stored `ffc_settings`. */
	private array $settings = array();

	/** @var array<int, array<string, mixed>> Rows the flush inserted. */
	private array $inserted = array();

	/** @var mixed */
	private $wpdb_backup;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();
		$this->settings = array();
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => 'ffc_settings' === $key ? $this->settings : $default
		);
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		// The entries are flushed at once, so they reach the table here.
		$this->inserted    = array();
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$inserted          = &$this->inserted;
		$GLOBALS['wpdb']   = new class( $inserted ) {
			/** @var string */
			public $prefix = 'wp_';
			/** @var array<int, array<string, mixed>> */
			private $rows;
			public function __construct( array &$rows ) {
				$this->rows = &$rows;
			}
			public function prepare( $sql ) {
				return (string) $sql;
			}
			public function get_col() {
				return array( 'id', 'action', 'level', 'context', 'context_encrypted', 'user_id', 'user_ip_encrypted', 'submission_id', 'created_at' );
			}
			public function insert( $table, $data ) {
				$this->rows[] = $data;
				return 1;
			}
		};
	}

	/**
	 * Setting, from and to of each row the flush wrote.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function changes(): array {
		return array_map(
			static fn( array $row ): array => json_decode( (string) $row['context'], true ),
			$this->inserted
		);
	}

	protected function tearDown(): void {
		// The flush memoised this double's columns; later tests must not see them.
		\FreeFormCertificate\Core\ActivityLog::clear_column_cache();
		$GLOBALS['wpdb'] = $this->wpdb_backup;
		ActivityLogBuffer::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_switching_the_log_off_is_recorded_while_it_is_still_on(): void {
		$written = DangerzoneSettingsAudit::record(
			array( 'enable_activity_log' => 1 ),
			array( 'enable_activity_log' => 0 )
		);

		$this->assertSame( 1, $written );
		$this->assertCount( 1, $this->inserted, 'Flushed at once, before the log goes off.' );
		$this->assertSame( DangerzoneSettingsAudit::ACTION, $this->inserted[0]['action'] );
		$this->assertSame( 'warning', $this->inserted[0]['level'] );
		$this->assertSame( 7, $this->inserted[0]['user_id'] );
		$this->assertSame(
			array(
				'setting' => 'enable_activity_log',
				'from'    => 1,
				'to'      => 0,
			),
			$this->changes()[0]
		);
		$this->assertSame( array(), ActivityLogBuffer::entries(), 'Nothing is left for the shutdown flush to drop.' );
	}

	public function test_a_missing_key_reads_as_its_default_so_a_first_save_records_only_real_changes(): void {
		$written = DangerzoneSettingsAudit::record(
			array(),
			array(
				'enable_activity_log'         => 1,
				'activity_log_retention_days' => 90,
				'activity_log_max_records'    => 100000,
				'activity_log_item_pii_reveal' => 1,
				'cleanup_days'                => 30,
			)
		);

		$this->assertSame( 1, $written );
		$this->assertSame( 'cleanup_days', $this->changes()[0]['setting'] );
		$this->assertSame( 365, $this->changes()[0]['from'] );
	}

	public function test_an_audit_item_switched_off_is_recorded_and_a_secondary_one_is_not(): void {
		$written = DangerzoneSettingsAudit::record(
			array(),
			array(
				'activity_log_item_pii_reveal'       => 0,
				'activity_log_item_cert_submissions' => 1,
			)
		);

		$this->assertSame( 1, $written );
		$this->assertSame( 'activity_log_item_pii_reveal', $this->changes()[0]['setting'] );
	}

	public function test_equal_values_of_different_types_are_no_change(): void {
		$this->assertSame(
			0,
			DangerzoneSettingsAudit::record(
				array( 'cleanup_enabled' => true, 'cleanup_days' => '365' ),
				array( 'cleanup_enabled' => 1, 'cleanup_days' => 365 )
			)
		);
		$this->assertSame( array(), $this->inserted );
	}

	public function test_nothing_is_recorded_while_the_log_is_off(): void {
		$this->settings = array( 'enable_activity_log' => 0 );

		$this->assertSame( 0, DangerzoneSettingsAudit::record( array(), array( 'cleanup_enabled' => 1 ) ) );
	}

	public function test_it_covers_exactly_the_danger_zone_keys(): void {
		$this->assertEqualsCanonicalizing( SettingsAjaxEndpoint::dangerzone_settings(), array_keys( DangerzoneSettingsAudit::defaults() ) );
	}

	public function test_its_defaults_agree_with_the_declared_ones(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$settings = ( new \ReflectionClass( \FreeFormCertificate\Admin\Settings::class ) )->newInstanceWithoutConstructor();
		$declared = $settings->get_default_settings();

		foreach ( DangerzoneSettingsAudit::defaults() as $key => $default ) {
			if ( 0 === strpos( $key, ActivityLogItems::SETTING_PREFIX ) ) {
				$this->assertSame( ActivityLogItems::default_for( substr( $key, strlen( ActivityLogItems::SETTING_PREFIX ) ) ) ? 1 : 0, $default, $key );
				continue;
			}
			$this->assertArrayHasKey( $key, $declared, $key . ' has no declared default.' );
			$this->assertSame( (int) $declared[ $key ], $default, $key );
		}
	}
}
