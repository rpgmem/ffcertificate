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
 * Tests for FormProcessor::check_restrictions() and calculate_quiz_score().
 *
 * Uses reflection to test private methods directly since they contain
 * critical security logic (allowlist, denylist, ticket, password).
 */
class FormProcessorRestrictionsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private FormProcessor $processor;
    private \ReflectionMethod $check_restrictions;
    private \ReflectionMethod $calculate_quiz_score;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $handler = Mockery::mock( SubmissionHandler::class );
        $email_handler = Mockery::mock( 'EmailHandler' );

        $this->processor = new FormProcessor( $handler, $email_handler );

        // Make private methods accessible via reflection.
        $ref = new \ReflectionClass( FormProcessor::class );

        $this->check_restrictions = $ref->getMethod( 'check_restrictions' );
        $this->check_restrictions->setAccessible( true );

        $this->calculate_quiz_score = $ref->getMethod( 'calculate_quiz_score' );
        $this->calculate_quiz_score->setAccessible( true );

        Functions\when( '__' )->returnArg();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // No restrictions
    // ------------------------------------------------------------------

    public function test_no_restrictions_allows_access(): void {
        $config = array(); // no restrictions key

        $result = $this->check_restrictions->invoke( $this->processor, $config, '12345678901', '', 1 );

        $this->assertTrue( $result['allowed'] );
        $this->assertFalse( $result['is_ticket'] );
    }

    // ------------------------------------------------------------------
    // Password restriction
    // ------------------------------------------------------------------

    public function test_password_required_when_empty(): void {
        $config = array(
            'restrictions'    => array( 'password' => '1' ),
            'validation_code' => 'secret123',
        );

        // Simulate empty password in POST
        $_POST['ffc_password'] = '';
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();

        $result = $this->check_restrictions->invoke( $this->processor, $config, '12345678901', '', 1 );

        $this->assertFalse( $result['allowed'] );
        $this->assertStringContainsString( 'Password is required', $result['message'] );

        unset( $_POST['ffc_password'] );
    }

    public function test_password_incorrect_blocks(): void {
        $config = array(
            'restrictions'    => array( 'password' => '1' ),
            'validation_code' => 'secret123',
        );

        $_POST['ffc_password'] = 'wrong';
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();

        $result = $this->check_restrictions->invoke( $this->processor, $config, '12345678901', '', 1 );

        $this->assertFalse( $result['allowed'] );
        $this->assertStringContainsString( 'Incorrect password', $result['message'] );

        unset( $_POST['ffc_password'] );
    }

    public function test_password_correct_allows(): void {
        $config = array(
            'restrictions'    => array( 'password' => '1' ),
            'validation_code' => 'secret123',
        );

        $_POST['ffc_password'] = 'secret123';
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();

        $result = $this->check_restrictions->invoke( $this->processor, $config, '12345678901', '', 1 );

        $this->assertTrue( $result['allowed'] );

        unset( $_POST['ffc_password'] );
    }

    // ------------------------------------------------------------------
    // Denylist restriction
    // ------------------------------------------------------------------

    public function test_denylist_blocks_matching_cpf(): void {
        $config = array(
            'restrictions'      => array( 'denylist' => '1' ),
            'denied_users_list' => "123.456.789-01\n987.654.321-00",
        );

        $result = $this->check_restrictions->invoke( $this->processor, $config, '12345678901', '', 1 );

        $this->assertFalse( $result['allowed'] );
        $this->assertStringContainsString( 'blocked', $result['message'] );
    }

    public function test_denylist_allows_non_matching_cpf(): void {
        $config = array(
            'restrictions'      => array( 'denylist' => '1' ),
            'denied_users_list' => "111.111.111-11",
        );

        $result = $this->check_restrictions->invoke( $this->processor, $config, '12345678901', '', 1 );

        $this->assertTrue( $result['allowed'] );
    }

    // ------------------------------------------------------------------
    // Allowlist restriction
    // ------------------------------------------------------------------

    public function test_allowlist_blocks_non_matching_cpf(): void {
        $config = array(
            'restrictions'       => array( 'allowlist' => '1' ),
            'allowed_users_list' => "111.111.111-11\n222.222.222-22",
        );

        $result = $this->check_restrictions->invoke( $this->processor, $config, '12345678901', '', 1 );

        $this->assertFalse( $result['allowed'] );
        $this->assertStringContainsString( 'not authorized', $result['message'] );
    }

    public function test_allowlist_allows_matching_cpf(): void {
        $config = array(
            'restrictions'       => array( 'allowlist' => '1' ),
            'allowed_users_list' => "123.456.789-01\n222.222.222-22",
        );

        $result = $this->check_restrictions->invoke( $this->processor, $config, '12345678901', '', 1 );

        $this->assertTrue( $result['allowed'] );
    }

    // ------------------------------------------------------------------
    // Ticket restriction
    // ------------------------------------------------------------------

    public function test_ticket_required_when_empty(): void {
        $config = array(
            'restrictions'         => array( 'ticket' => '1' ),
            'generated_codes_list' => "ABC-DEF-123\nGHI-JKL-456",
        );

        $result = $this->check_restrictions->invoke( $this->processor, $config, '12345678901', '', 1 );

        $this->assertFalse( $result['allowed'] );
        $this->assertStringContainsString( 'Ticket code is required', $result['message'] );
    }

    public function test_ticket_invalid_blocks(): void {
        $config = array(
            'restrictions'         => array( 'ticket' => '1' ),
            'generated_codes_list' => "ABC-DEF-123\nGHI-JKL-456",
        );

        $result = $this->check_restrictions->invoke( $this->processor, $config, '12345678901', 'ZZZ-ZZZ-999', 1 );

        $this->assertFalse( $result['allowed'] );
        $this->assertStringContainsString( 'Invalid or already used ticket', $result['message'] );
    }

    public function test_ticket_valid_allows_and_is_consumed(): void {
        $config = array(
            'restrictions'         => array( 'ticket' => '1' ),
            'generated_codes_list' => "ABC-DEF-123\nGHI-JKL-456",
        );

        // Stub update_post_meta for ticket consumption
        Functions\when( 'update_post_meta' )->justReturn( true );

        $result = $this->check_restrictions->invoke( $this->processor, $config, '12345678901', 'abc-def-123', 1 );

        $this->assertTrue( $result['allowed'] );
        $this->assertTrue( $result['is_ticket'] );
    }

    public function test_ticket_case_insensitive(): void {
        $config = array(
            'restrictions'         => array( 'ticket' => '1' ),
            'generated_codes_list' => "abc-def-123",
        );

        Functions\when( 'update_post_meta' )->justReturn( true );

        $result = $this->check_restrictions->invoke( $this->processor, $config, '12345678901', 'ABC-DEF-123', 1 );

        $this->assertTrue( $result['allowed'] );
    }

    // ------------------------------------------------------------------
    // Combined restrictions (priority order: password → denylist → allowlist → ticket)
    // ------------------------------------------------------------------

    public function test_denylist_has_priority_over_allowlist(): void {
        $config = array(
            'restrictions'       => array( 'denylist' => '1', 'allowlist' => '1' ),
            'denied_users_list'  => "123.456.789-01",
            'allowed_users_list' => "123.456.789-01",  // same CPF in both
        );

        $result = $this->check_restrictions->invoke( $this->processor, $config, '12345678901', '', 1 );

        // Denylist should block even though CPF is in allowlist
        $this->assertFalse( $result['allowed'] );
        $this->assertStringContainsString( 'blocked', $result['message'] );
    }

    // ------------------------------------------------------------------
    // calculate_quiz_score()
    // ------------------------------------------------------------------

    public function test_quiz_score_calculates_correctly(): void {
        $fields = array(
            array(
                'type'    => 'radio',
                'name'    => 'q1',
                'options' => 'A, B, C',
                'points'  => '10, 0, 5',
            ),
            array(
                'type'    => 'select',
                'name'    => 'q2',
                'options' => 'X, Y',
                'points'  => '0, 20',
            ),
            array(
                'type'    => 'text',
                'name'    => 'name',
                // no points — should be ignored
            ),
        );

        $submission = array( 'q1' => 'A', 'q2' => 'Y', 'name' => 'João' );

        $result = $this->calculate_quiz_score->invoke( $this->processor, $fields, $submission );

        $this->assertSame( 30, $result['score'] );       // 10 + 20
        $this->assertSame( 30, $result['max_score'] );   // max(10,0,5) + max(0,20) = 10 + 20
        $this->assertSame( 100, $result['percent'] );
    }

    public function test_quiz_score_zero_when_wrong_answers(): void {
        $fields = array(
            array(
                'type'    => 'radio',
                'name'    => 'q1',
                'options' => 'A, B',
                'points'  => '10, 0',
            ),
        );

        $submission = array( 'q1' => 'B' );

        $result = $this->calculate_quiz_score->invoke( $this->processor, $fields, $submission );

        $this->assertSame( 0, $result['score'] );
        $this->assertSame( 10, $result['max_score'] );
        $this->assertSame( 0, $result['percent'] );
    }

    public function test_quiz_score_handles_no_quiz_fields(): void {
        $fields = array(
            array( 'type' => 'text', 'name' => 'name' ),
            array( 'type' => 'email', 'name' => 'email' ),
        );

        $result = $this->calculate_quiz_score->invoke( $this->processor, $fields, array( 'name' => 'A' ) );

        $this->assertSame( 0, $result['score'] );
        $this->assertSame( 0, $result['max_score'] );
        $this->assertSame( 0, $result['percent'] );
    }

    public function test_quiz_score_unanswered_question(): void {
        $fields = array(
            array(
                'type'    => 'radio',
                'name'    => 'q1',
                'options' => 'A, B',
                'points'  => '10, 5',
            ),
        );

        // User didn't answer q1
        $result = $this->calculate_quiz_score->invoke( $this->processor, $fields, array() );

        $this->assertSame( 0, $result['score'] );
        $this->assertSame( 10, $result['max_score'] );
    }
}
