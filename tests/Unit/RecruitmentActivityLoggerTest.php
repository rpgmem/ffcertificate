<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentActivityLogger;
use FreeFormCertificate\Core\ActivityLog;

/**
 * Tests for RecruitmentActivityLogger — verifies the action vocabulary,
 * payload shape per event, and that sensitive-payload protection
 * (inherited from the core ActivityLog) is wired correctly. We don't
 * exercise the encrypt path itself (covered by SensitiveFieldRegistryTest);
 * we verify the log call lands with the expected action code + context.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentActivityLogger
 */
class RecruitmentActivityLoggerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Several methods this class reaches are deprecated (#2089) and emit
		// the notice; stub it so each test chooses the branch rather than
		// inheriting it from an earlier class.
		\Brain\Monkey\Functions\when( '_deprecated_function' )->justReturn( null );

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		// Enable the activity log so ActivityLog::log() doesn't short-circuit.
		Functions\when( 'get_option' )->justReturn( array( 'enable_activity_log' => 1 ) );
		Functions\when( 'absint' )->alias( 'intval' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_current_user_id' )->justReturn( 99 );
		Functions\when( 'current_time' )->justReturn( '2026-05-01 10:00:00' );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		// Encryption module is class-detected via class_exists; ensure it's
		// reported as "not configured" so the log path stays in plaintext
		// (we test the action+payload, not the encryption itself).
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql ) => $sql )->byDefault();
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array() )->byDefault();
	}

	protected function tearDown(): void {
		// Reset the private static buffer so each test starts clean.
		$ref = new \ReflectionProperty( ActivityLog::class, 'write_buffer' );
		$ref->setAccessible( true );
		$ref->setValue( null, array() );

		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Read the private static write_buffer via reflection.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function buffer(): array {
		$ref = new \ReflectionProperty( ActivityLog::class, 'write_buffer' );
		$ref->setAccessible( true );
		$value = $ref->getValue();
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Capture the most recent buffered log entry.
	 *
	 * @return array<string, mixed>|null
	 */
	private function last_buffered_entry(): ?array {
		$buffer = $this->buffer();
		if ( empty( $buffer ) ) {
			return null;
		}
		return end( $buffer );
	}

	public function test_csv_imported_logs_with_expected_payload(): void {
		RecruitmentActivityLogger::csv_imported( 5, 'preview', 42 );

		$entry = $this->last_buffered_entry();
		$this->assertNotNull( $entry );
		$this->assertSame( 'recruitment_csv_imported', $entry['action'] );
		// Context is JSON-encoded in the log row when not encrypted.
		$context = json_decode( (string) $entry['context'], true );
		$this->assertSame( 5, $context['notice_id'] );
		$this->assertSame( 'preview', $context['list_type'] );
		$this->assertSame( 42, $context['inserted_count'] );
	}

	public function test_csv_import_failed_uses_warning_level(): void {
		RecruitmentActivityLogger::csv_import_failed( 5, 'definitive', 7 );

		$entry = $this->last_buffered_entry();
		$this->assertSame( 'recruitment_csv_import_failed', $entry['action'] );
		$this->assertSame( ActivityLog::LEVEL_WARNING, $entry['level'] );
	}

	public function test_notice_status_changed_omits_reason_when_null(): void {
		RecruitmentActivityLogger::notice_status_changed( 5, 'draft', 'preliminary' );

		$context = json_decode( (string) $this->last_buffered_entry()['context'], true );
		$this->assertSame( 'draft', $context['from'] );
		$this->assertSame( 'preliminary', $context['to'] );
		$this->assertArrayNotHasKey( 'reason', $context );
	}

	public function test_notice_status_changed_includes_reason_when_provided(): void {
		RecruitmentActivityLogger::notice_status_changed( 5, 'closed', 'definitive', 'Vacancy reopened' );

		$context = json_decode( (string) $this->last_buffered_entry()['context'], true );
		$this->assertSame( 'Vacancy reopened', $context['reason'] );
	}

	public function test_notice_promoted_records_mode_and_copy_count(): void {
		RecruitmentActivityLogger::notice_promoted( 5, 'snapshot', 17 );

		$context = json_decode( (string) $this->last_buffered_entry()['context'], true );
		$this->assertSame( 5, $context['notice_id'] );
		$this->assertSame( 'snapshot', $context['mode'] );
		$this->assertSame( 17, $context['copied'] );
	}

	public function test_classification_status_changed_records_transition(): void {
		RecruitmentActivityLogger::classification_status_changed( 10, 'called', 'hired' );

		$entry = $this->last_buffered_entry();
		$this->assertSame( 'recruitment_classification_status_changed', $entry['action'] );
		$context = json_decode( (string) $entry['context'], true );
		$this->assertSame( 10, $context['classification_id'] );
		$this->assertSame( 'called', $context['from'] );
		$this->assertSame( 'hired', $context['to'] );
	}

	public function test_call_created_includes_out_of_order_flag(): void {
		RecruitmentActivityLogger::call_created( 7, 10, true );

		$context = json_decode( (string) $this->last_buffered_entry()['context'], true );
		$this->assertSame( 1, $context['out_of_order'] );
	}

	public function test_bulk_call_created_aggregates_into_single_event(): void {
		RecruitmentActivityLogger::bulk_call_created(
			array( 1, 2, 3 ),
			array( 100, 101, 102 ),
			'2026-06-01',
			'08:00'
		);

		$entry = $this->last_buffered_entry();
		$this->assertSame( 'recruitment_bulk_call_created', $entry['action'] );
		$context = json_decode( (string) $entry['context'], true );
		$this->assertSame( array( 1, 2, 3 ), $context['classification_ids'] );
		$this->assertSame( array( 100, 101, 102 ), $context['call_ids'] );
		$this->assertSame( 3, $context['count'] );
	}

	public function test_call_cancelled_records_reason(): void {
		RecruitmentActivityLogger::call_cancelled( 7, 10, 'Admin reverted' );

		$context = json_decode( (string) $this->last_buffered_entry()['context'], true );
		$this->assertSame( 'Admin reverted', $context['reason'] );
	}

	public function test_candidate_promoted_uses_user_id_as_actor(): void {
		RecruitmentActivityLogger::candidate_promoted( 50, 200 );

		$entry = $this->last_buffered_entry();
		$this->assertSame( 'recruitment_candidate_promoted', $entry['action'] );
		$this->assertSame( 200, $entry['user_id'] );
		$context = json_decode( (string) $entry['context'], true );
		$this->assertSame( 50, $context['candidate_id'] );
		$this->assertSame( 200, $context['user_id'] );
	}

	public function test_candidate_deleted_uses_warning_level(): void {
		RecruitmentActivityLogger::candidate_deleted( 50, 'cleanup' );

		$entry = $this->last_buffered_entry();
		$this->assertSame( ActivityLog::LEVEL_WARNING, $entry['level'] );
		$context = json_decode( (string) $entry['context'], true );
		$this->assertSame( 'cleanup', $context['reason'] );
	}

	public function test_classification_deleted_records_id(): void {
		RecruitmentActivityLogger::classification_deleted( 10 );

		$entry = $this->last_buffered_entry();
		$this->assertSame( 'recruitment_classification_deleted', $entry['action'] );
		$this->assertSame( ActivityLog::LEVEL_WARNING, $entry['level'] );
		$context = json_decode( (string) $entry['context'], true );
		$this->assertSame( 10, $context['classification_id'] );
		$this->assertArrayNotHasKey( 'reason', $context );
	}

	public function test_adjutancy_deleted_records_id(): void {
		RecruitmentActivityLogger::adjutancy_deleted( 2 );

		$entry = $this->last_buffered_entry();
		$this->assertSame( 'recruitment_adjutancy_deleted', $entry['action'] );
		$this->assertSame( ActivityLog::LEVEL_WARNING, $entry['level'] );
		$context = json_decode( (string) $entry['context'], true );
		$this->assertSame( 2, $context['adjutancy_id'] );
	}

	// ------------------------------------------------------------------
	// pii_revealed() — issue #330
	// ------------------------------------------------------------------

	public function test_pii_revealed_logs_every_reveal_whatever_the_stored_setting(): void {
		// An install that switched the old toggle off still has the key in
		// its option; it no longer silences anything (#2245).
		Functions\when( 'get_option' )->alias(
			static fn ( $key ) => 'ffc_recruitment_settings' === $key
				? array( 'audit_pii_reveals' => false )
				: array( 'enable_activity_log' => 1 )
		);

		$first  = RecruitmentActivityLogger::pii_revealed( 12, 'cpf' );
		$second = RecruitmentActivityLogger::pii_revealed( 12, 'cpf' );

		$this->assertTrue( $first );
		$this->assertTrue( $second, 'A repeated reveal is a second reveal, not a duplicate.' );
		$this->assertCount( 2, $this->buffer() );
		$this->assertSame( 'recruitment_pii_revealed', $this->last_buffered_entry()['action'] );
	}

	public function test_pii_revealed_logs_across_fields_and_candidates(): void {
		Functions\when( 'get_option' )->justReturn( array( 'enable_activity_log' => 1 ) );

		RecruitmentActivityLogger::pii_revealed( 12, 'cpf' );
		RecruitmentActivityLogger::pii_revealed( 12, 'rf' );
		RecruitmentActivityLogger::pii_revealed( 13, 'cpf' );

		$this->assertCount( 3, $this->buffer() );
	}

	// ------------------------------------------------------------------
	// candidate_fields_edited() + classification_adjutancy_changed() — #331
	// ------------------------------------------------------------------

	public function test_candidate_fields_edited_logs_diff(): void {
		$wrote = RecruitmentActivityLogger::candidate_fields_edited(
			42,
			array(
				'name'  => array( 'old' => 'Alice', 'new' => 'Alicia' ),
				'notes' => array( 'old' => '', 'new' => 'some note' ),
			)
		);

		$this->assertTrue( $wrote );
		$entry = $this->last_buffered_entry();
		$this->assertSame( 'recruitment_candidate_fields_edited', $entry['action'] );
		$context = json_decode( (string) $entry['context'], true );
		$this->assertSame( 42, $context['candidate_id'] );
		$this->assertArrayHasKey( 'changes', $context );
		$this->assertSame( 'Alicia', $context['changes']['name']['new'] );
	}

	public function test_candidate_fields_edited_auto_encrypts_when_diff_carries_sensitive_keys(): void {
		// `phone` is a registered sensitive field — when it appears in the
		// audit payload (even nested under `changes`), the ActivityLog
		// auto-encrypt path drops the plaintext `context` column and only
		// writes `context_encrypted`. Verify the protection actually fires.
		RecruitmentActivityLogger::candidate_fields_edited(
			42,
			array( 'phone' => array( 'old' => '', 'new' => '11999999999' ) )
		);

		$entry = $this->last_buffered_entry();
		$this->assertSame( 'recruitment_candidate_fields_edited', $entry['action'] );
		$this->assertNull( $entry['context'], 'plaintext context must be dropped' );
		$this->assertNotNull( $entry['context_encrypted'], 'context_encrypted must be populated' );
	}

	public function test_candidate_fields_edited_short_circuits_on_empty_diff(): void {
		$wrote = RecruitmentActivityLogger::candidate_fields_edited( 42, array() );

		$this->assertFalse( $wrote );
		$this->assertSame( array(), $this->buffer() );
	}

	public function test_classification_adjutancy_changed_logs_from_to(): void {
		RecruitmentActivityLogger::classification_adjutancy_changed( 7, 3, 5 );

		$entry = $this->last_buffered_entry();
		$this->assertSame( 'recruitment_classification_adjutancy_changed', $entry['action'] );
		$context = json_decode( (string) $entry['context'], true );
		$this->assertSame( 7, $context['classification_id'] );
		$this->assertSame( 3, $context['from'] );
		$this->assertSame( 5, $context['to'] );
	}
}
