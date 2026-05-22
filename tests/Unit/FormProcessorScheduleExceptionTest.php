<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\FormProcessor;
use FreeFormCertificate\Submissions\SubmissionHandler;

/**
 * Tests for `FormProcessor::persist_schedule_exception()` — the Sprint 6
 * private helper that writes the override TIME columns + emits the two
 * audit log rows for a verified schedule-exception consumption.
 *
 * Lives in its own file so the `@runTestsInSeparateProcesses` annotation
 * (required because the alias mocks for ActivityLog / Utils are
 * single-shot per PHP process) doesn't slow down the 70+ existing
 * FormProcessorTest cases that don't need isolation. Mirrors the
 * established pattern in ActivityLogAjaxEndpointTest / CacheActionsAjaxEndpointTest.
 *
 * @covers \FreeFormCertificate\Frontend\FormProcessor::persist_schedule_exception
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class FormProcessorScheduleExceptionTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var FormProcessor */
    private $processor;

    /** @var Mockery\MockInterface */
    private $repo;

    /** @var array<int, array<string, mixed>> */
    private array $audit_calls = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();

        // Spy repository.
        $this->repo = Mockery::mock( '\FreeFormCertificate\Repositories\SubmissionRepository' );
        $this->repo->shouldReceive( 'update' )->byDefault();

        // Handler returns the spy.
        $handler = Mockery::mock( SubmissionHandler::class );
        $handler->shouldReceive( 'get_repository' )->andReturn( $this->repo )->byDefault();

        $this->processor = new FormProcessor( $handler );

        // Alias ActivityLog so we can spy on log() and read constants
        // without booting the real wpdb-backed class.
        $this->audit_calls = array();
        $audit_ref         = &$this->audit_calls;
        $log_class         = Mockery::mock( 'alias:\FreeFormCertificate\Core\ActivityLog' );
        $log_class->shouldReceive( 'log' )
            ->andReturnUsing( static function ( $action, $level, $context, $user_id, $submission_id ) use ( &$audit_ref ) {
                $audit_ref[] = array(
                    'action'        => $action,
                    'level'         => $level,
                    'context'       => $context,
                    'user_id'       => $user_id,
                    'submission_id' => $submission_id,
                );
                return true;
            } );

        // Alias Utils so get_user_ip is deterministic.
        $utils = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $utils->shouldReceive( 'get_user_ip' )->andReturn( '203.0.113.5' )->byDefault();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function invoke_persist( int $submission_id, int $form_id, array $payload, string $cpf ): void {
        $ref = new \ReflectionMethod( FormProcessor::class, 'persist_schedule_exception' );
        $ref->setAccessible( true );
        $ref->invokeArgs( $this->processor, array( $submission_id, $form_id, $payload, $cpf ) );
    }

    private function full_payload( array $overrides = array() ): array {
        return array_merge(
            array(
                'v'                   => 1,
                'form_id'             => 42,
                'start'               => '08:00',
                'end'                 => '17:30',
                'operator_cpf_hash'   => str_repeat( 'a', 64 ),
                'operator_cpf_masked' => '123.***.***-45',
                'exp'                 => time() + 600,
                'jti'                 => 'x',
            ),
            $overrides
        );
    }

    // ==================================================================
    // DB persistence
    // ==================================================================

    public function test_persist_writes_both_override_columns_when_payload_carries_both(): void {
        $this->repo->shouldReceive( 'update' )
            ->once()
            ->with(
                555,
                array(
                    'schedule_start_override' => '08:00',
                    'schedule_end_override'   => '17:30',
                )
            );

        $this->invoke_persist( 555, 42, $this->full_payload(), '12345678900' );
    }

    public function test_persist_writes_only_present_override_when_one_end_is_null(): void {
        $this->repo->shouldReceive( 'update' )
            ->once()
            ->with( 555, array( 'schedule_end_override' => '17:30' ) );

        $this->invoke_persist(
            555,
            42,
            $this->full_payload( array( 'start' => null ) ),
            '12345678900'
        );
    }

    public function test_persist_skips_update_when_both_ends_are_null(): void {
        $this->repo->shouldReceive( 'update' )->never();

        $this->invoke_persist(
            555,
            42,
            $this->full_payload(
                array(
                    'start' => null,
                    'end'   => null,
                )
            ),
            '12345678900'
        );
    }

    // ==================================================================
    // Audit emit
    // ==================================================================

    public function test_persist_emits_two_audit_rows_with_correct_context(): void {
        $this->invoke_persist( 555, 42, $this->full_payload(), '12345678900' );

        $this->assertCount( 2, $this->audit_calls );

        $override_row = $this->audit_calls[0];
        $this->assertSame( 'schedule_override_created', $override_row['action'] );
        $this->assertSame( 'info', $override_row['level'] );
        $this->assertSame( 42, $override_row['context']['form_id'] );
        $this->assertSame( 555, $override_row['context']['submission_id'] );
        $this->assertSame( hash( 'sha256', '12345678900' ), $override_row['context']['participant_cpf_hash'] );
        $this->assertSame( str_repeat( 'a', 64 ), $override_row['context']['operator_cpf_hash'] );
        $this->assertSame( '123.***.***-45', $override_row['context']['operator_cpf_masked'] );
        $this->assertSame( '08:00', $override_row['context']['schedule_start_after'] );
        $this->assertSame( '17:30', $override_row['context']['schedule_end_after'] );
        $this->assertIsInt( $override_row['context']['ts'], 'ts is Category A unix UTC int' );
        $this->assertSame( 555, $override_row['submission_id'] );

        $bypass_row = $this->audit_calls[1];
        $this->assertSame( 'operator_ip_bypass', $bypass_row['action'] );
        $this->assertSame( '203.0.113.5', $bypass_row['context']['bypassed_ip'] );
        $this->assertSame( str_repeat( 'a', 64 ), $bypass_row['context']['operator_cpf_hash'] );
        $this->assertSame( '123.***.***-45', $bypass_row['context']['operator_cpf_masked'] );
        $this->assertIsInt( $bypass_row['context']['ts'] );
    }

    public function test_persist_emits_audit_rows_even_when_no_db_update_happens(): void {
        // Both overrides null → no DB write (covered above), but the
        // audit rows STILL fire so an admin reviewing the log sees the
        // operator consumed an exception even if the override values
        // collapsed to baseline mid-flight. In practice the action's
        // `no_change` rejection in Sprint 4 prevents reaching this
        // branch, but the persist helper is robust to it.
        $this->repo->shouldReceive( 'update' )->never();

        $this->invoke_persist(
            555,
            42,
            $this->full_payload(
                array(
                    'start' => null,
                    'end'   => null,
                )
            ),
            '12345678900'
        );

        $this->assertCount( 2, $this->audit_calls );
        $this->assertNull( $this->audit_calls[0]['context']['schedule_start_after'] );
        $this->assertNull( $this->audit_calls[0]['context']['schedule_end_after'] );
    }

    public function test_persist_omits_participant_cpf_hash_when_cpf_blank(): void {
        // Forms where CPF mode is "none" send empty plaintext upstream;
        // the audit row should reflect the absence without crashing on
        // hash('sha256', '').
        $this->invoke_persist( 555, 42, $this->full_payload(), '' );

        $this->assertSame( '', $this->audit_calls[0]['context']['participant_cpf_hash'] );
    }
}
