<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\FormProcessor;
use FreeFormCertificate\Frontend\AccessRestrictionChecker;
use FreeFormCertificate\Submissions\SubmissionHandler;

/**
 * Tests for FormProcessor: quiz scoring and restriction checks.
 *
 * Uses Reflection to access private methods for testing pure business logic.
 */
class FormProcessorTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var FormProcessor */
    private $processor;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();

        $mock_handler = Mockery::mock( SubmissionHandler::class );
        $this->processor = new FormProcessor( $mock_handler );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Invoke a private method on FormProcessor.
     */
    private function invoke( string $method, array $args = [] ) {
        $ref = new \ReflectionMethod( FormProcessor::class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( $this->processor, $args );
    }

    // ==================================================================
    // calculate_quiz_score()
    // ==================================================================

    public function test_quiz_score_single_correct_answer(): void {
        $fields = array(
            array( 'type' => 'radio', 'name' => 'q1', 'options' => 'A,B,C', 'points' => '10,5,0' ),
        );
        $data = array( 'q1' => 'A' );

        $result = $this->invoke( 'calculate_quiz_score', array( $fields, $data ) );

        $this->assertSame( 10, $result['score'] );
        $this->assertSame( 10, $result['max_score'] );
        $this->assertSame( 100, $result['percent'] );
    }

    public function test_quiz_score_single_wrong_answer(): void {
        $fields = array(
            array( 'type' => 'radio', 'name' => 'q1', 'options' => 'A,B,C', 'points' => '10,5,0' ),
        );
        $data = array( 'q1' => 'C' );

        $result = $this->invoke( 'calculate_quiz_score', array( $fields, $data ) );

        $this->assertSame( 0, $result['score'] );
        $this->assertSame( 10, $result['max_score'] );
        $this->assertSame( 0, $result['percent'] );
    }

    public function test_quiz_score_multiple_questions(): void {
        $fields = array(
            array( 'type' => 'radio', 'name' => 'q1', 'options' => 'A,B', 'points' => '10,0' ),
            array( 'type' => 'select', 'name' => 'q2', 'options' => 'X,Y,Z', 'points' => '5,10,0' ),
        );
        $data = array( 'q1' => 'A', 'q2' => 'Y' );

        $result = $this->invoke( 'calculate_quiz_score', array( $fields, $data ) );

        $this->assertSame( 20, $result['score'] );
        $this->assertSame( 20, $result['max_score'] );
        $this->assertSame( 100, $result['percent'] );
    }

    public function test_quiz_score_partial_answer(): void {
        $fields = array(
            array( 'type' => 'radio', 'name' => 'q1', 'options' => 'A,B', 'points' => '10,5' ),
            array( 'type' => 'select', 'name' => 'q2', 'options' => 'X,Y', 'points' => '20,10' ),
        );
        $data = array( 'q1' => 'B', 'q2' => 'Y' );

        $result = $this->invoke( 'calculate_quiz_score', array( $fields, $data ) );

        $this->assertSame( 15, $result['score'] );
        $this->assertSame( 30, $result['max_score'] );
        $this->assertSame( 50, $result['percent'] );
    }

    public function test_quiz_score_ignores_text_fields(): void {
        $fields = array(
            array( 'type' => 'text', 'name' => 'name', 'options' => '', 'points' => '' ),
            array( 'type' => 'textarea', 'name' => 'comment', 'options' => '', 'points' => '' ),
            array( 'type' => 'radio', 'name' => 'q1', 'options' => 'A,B', 'points' => '10,0' ),
        );
        $data = array( 'name' => 'John', 'comment' => 'Hello', 'q1' => 'A' );

        $result = $this->invoke( 'calculate_quiz_score', array( $fields, $data ) );

        $this->assertSame( 10, $result['score'] );
        $this->assertSame( 10, $result['max_score'] );
    }

    public function test_quiz_score_ignores_fields_without_points(): void {
        $fields = array(
            array( 'type' => 'radio', 'name' => 'q1', 'options' => 'A,B', 'points' => '' ),
            array( 'type' => 'select', 'name' => 'q2', 'options' => 'X,Y', 'points' => '5,10' ),
        );
        $data = array( 'q1' => 'A', 'q2' => 'Y' );

        $result = $this->invoke( 'calculate_quiz_score', array( $fields, $data ) );

        $this->assertSame( 10, $result['score'] );
        $this->assertSame( 10, $result['max_score'] );
    }

    public function test_quiz_score_unanswered_question(): void {
        $fields = array(
            array( 'type' => 'radio', 'name' => 'q1', 'options' => 'A,B', 'points' => '10,5' ),
        );
        $data = array(); // No answer

        $result = $this->invoke( 'calculate_quiz_score', array( $fields, $data ) );

        $this->assertSame( 0, $result['score'] );
        $this->assertSame( 10, $result['max_score'] );
        $this->assertSame( 0, $result['percent'] );
    }

    public function test_quiz_score_empty_fields_returns_zero(): void {
        $result = $this->invoke( 'calculate_quiz_score', array( array(), array() ) );

        $this->assertSame( 0, $result['score'] );
        $this->assertSame( 0, $result['max_score'] );
        $this->assertSame( 0, $result['percent'] );
    }

    public function test_quiz_score_percent_rounds_correctly(): void {
        $fields = array(
            array( 'type' => 'radio', 'name' => 'q1', 'options' => 'A,B,C', 'points' => '1,0,0' ),
            array( 'type' => 'radio', 'name' => 'q2', 'options' => 'A,B,C', 'points' => '1,0,0' ),
            array( 'type' => 'radio', 'name' => 'q3', 'options' => 'A,B,C', 'points' => '1,0,0' ),
        );
        $data = array( 'q1' => 'A', 'q2' => 'B', 'q3' => 'B' );

        $result = $this->invoke( 'calculate_quiz_score', array( $fields, $data ) );

        // 1/3 = 33.33...% → rounds to 33
        $this->assertSame( 1, $result['score'] );
        $this->assertSame( 3, $result['max_score'] );
        $this->assertSame( 33, $result['percent'] );
    }

    // ==================================================================
    // check_restrictions() — now delegated to AccessRestrictionChecker
    // ==================================================================

    public function test_restrictions_none_active_allows(): void {
        $config = array( 'restrictions' => array() );

        $result = AccessRestrictionChecker::check( $config, '12345678900', '', 1 );

        $this->assertTrue( $result['allowed'] );
        $this->assertSame( '', $result['message'] );
        $this->assertFalse( $result['is_ticket'] );
    }

    public function test_restrictions_password_correct(): void {
        $_POST['ffc_password'] = 'secret123';
        $config = array(
            'restrictions' => array( 'password' => '1' ),
            'validation_code' => 'secret123',
        );

        $result = AccessRestrictionChecker::check( $config, '', '', 1 );

        $this->assertTrue( $result['allowed'] );
        unset( $_POST['ffc_password'] );
    }

    public function test_restrictions_password_incorrect(): void {
        $_POST['ffc_password'] = 'wrong';
        $config = array(
            'restrictions' => array( 'password' => '1' ),
            'validation_code' => 'secret123',
        );

        $result = AccessRestrictionChecker::check( $config, '', '', 1 );

        $this->assertFalse( $result['allowed'] );
        $this->assertStringContainsString( 'Incorrect password', $result['message'] );
        unset( $_POST['ffc_password'] );
    }

    public function test_restrictions_password_empty(): void {
        $config = array(
            'restrictions' => array( 'password' => '1' ),
            'validation_code' => 'secret123',
        );
        // No $_POST['ffc_password']

        $result = AccessRestrictionChecker::check( $config, '', '', 1 );

        $this->assertFalse( $result['allowed'] );
        $this->assertStringContainsString( 'required', $result['message'] );
    }

    public function test_restrictions_denylist_blocks_cpf(): void {
        $config = array(
            'restrictions' => array( 'denylist' => '1' ),
            'denied_users_list' => "123.456.789-00\n987.654.321-00",
        );

        $result = AccessRestrictionChecker::check( $config, '12345678900', '', 1 );

        $this->assertFalse( $result['allowed'] );
        $this->assertStringContainsString( 'blocked', $result['message'] );
    }

    public function test_restrictions_denylist_allows_clean_cpf(): void {
        $config = array(
            'restrictions' => array( 'denylist' => '1' ),
            'denied_users_list' => "111.222.333-44\n555.666.777-88",
        );

        $result = AccessRestrictionChecker::check( $config, '12345678900', '', 1 );

        $this->assertTrue( $result['allowed'] );
    }

    public function test_restrictions_allowlist_allows_listed_cpf(): void {
        $config = array(
            'restrictions' => array( 'allowlist' => '1' ),
            'allowed_users_list' => "123.456.789-00\n987.654.321-00",
        );

        $result = AccessRestrictionChecker::check( $config, '12345678900', '', 1 );

        $this->assertTrue( $result['allowed'] );
    }

    public function test_restrictions_allowlist_blocks_unlisted_cpf(): void {
        $config = array(
            'restrictions' => array( 'allowlist' => '1' ),
            'allowed_users_list' => "111.222.333-44\n555.666.777-88",
        );

        $result = AccessRestrictionChecker::check( $config, '12345678900', '', 1 );

        $this->assertFalse( $result['allowed'] );
        $this->assertStringContainsString( 'not authorized', $result['message'] );
    }

    public function test_restrictions_ticket_valid_consumes(): void {
        Functions\when( 'update_post_meta' )->justReturn( true );

        $config = array(
            'restrictions' => array( 'ticket' => '1' ),
            'generated_codes_list' => "ABC123\nDEF456\nGHI789",
        );

        $result = AccessRestrictionChecker::check( $config, '', 'abc123', 42 );

        $this->assertTrue( $result['allowed'] );
        $this->assertTrue( $result['is_ticket'] );
    }

    public function test_restrictions_ticket_invalid(): void {
        $config = array(
            'restrictions' => array( 'ticket' => '1' ),
            'generated_codes_list' => "ABC123\nDEF456",
        );

        $result = AccessRestrictionChecker::check( $config, '', 'ZZZZZ', 42 );

        $this->assertFalse( $result['allowed'] );
        $this->assertStringContainsString( 'Invalid', $result['message'] );
    }

    public function test_restrictions_ticket_empty(): void {
        $config = array(
            'restrictions' => array( 'ticket' => '1' ),
            'generated_codes_list' => "ABC123",
        );

        $result = AccessRestrictionChecker::check( $config, '', '', 42 );

        $this->assertFalse( $result['allowed'] );
        $this->assertStringContainsString( 'required', $result['message'] );
    }

    public function test_restrictions_denylist_takes_priority_over_allowlist(): void {
        $config = array(
            'restrictions' => array( 'denylist' => '1', 'allowlist' => '1' ),
            'denied_users_list' => "12345678900",
            'allowed_users_list' => "12345678900",
        );

        $result = AccessRestrictionChecker::check( $config, '12345678900', '', 1 );

        // Even though CPF is in allowlist, denylist takes priority
        $this->assertFalse( $result['allowed'] );
        $this->assertStringContainsString( 'blocked', $result['message'] );
    }
}
