<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminAjax;

/**
 * Tests for AdminAjax: constructor hook registration, generate_tickets,
 * and search_user AJAX handlers.
 *
 * @covers \FreeFormCertificate\Admin\AdminAjax
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AdminAjaxTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface Alias mock for Utils */
    private $utils_mock;
    private $caps_mock;

    /** @var Mockery\MockInterface Overload mock for WP_User_Query */
    private $user_query_mock;

    /** @var Mockery\MockInterface */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Common WP function stubs
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $v ) {
            return abs( (int) $v );
        } );
        Functions\when( 'add_action' )->justReturn( true );

        // Stub wp_send_json_error to throw so we can detect "die" behavior
        Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) {
            $message = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : 'wp_send_json_error';
            throw new \RuntimeException( 'wp_send_json_error: ' . $message );
        } );

        // Stub wp_send_json_success to throw so we can capture success responses
        Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) {
            throw new AdminAjaxSuccessException( $data );
        } );

        // Default stubs for nonce/permission (pass by default)
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'current_user_can' )->justReturn( true );

        // Utils alias mock — required by check_ajax_permission for 'manage_options'
        $this->utils_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $this->caps_mock  = Mockery::mock( 'alias:\FreeFormCertificate\Core\Capabilities' );
        $ri_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\RequestInput' );
        $this->caps_mock->shouldReceive( 'current_user_can_manage' )->andReturn( true )->byDefault();
        $this->caps_mock->shouldReceive( 'current_user_can_admin_or' )->andReturn( true )->byDefault();
        $this->utils_mock->shouldReceive( 'debug_log' )->byDefault();
        $ri_mock->shouldReceive( 'get_post_string' )->andReturnUsing( function ( $key, $default = '' ) {
            return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? $_POST[ $key ] : $default;
        } )->byDefault();
        $ri_mock->shouldReceive( 'get_post_int' )->andReturnUsing( function ( $key, $default = 0 ) {
            return isset( $_POST[ $key ] ) ? (int) $_POST[ $key ] : $default;
        } )->byDefault();

        // Encryption alias mock — needed by search_user_by_cpf and the
        // appointment reveal path (decrypt_appointment passes the row through).
        $encryption_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Encryption' );
        $encryption_mock->shouldReceive( 'hash' )->andReturn( 'hashed_value' )->byDefault();
        $encryption_mock->shouldReceive( 'decrypt_appointment' )->andReturnUsing(
            function ( $row ) {
                return $row;
            }
        )->byDefault();

        // WP_User_Query overload mock
        $this->user_query_mock = Mockery::mock( 'overload:\WP_User_Query' );
        $this->user_query_mock->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();

        // wp_rand stub — returns varying values for code generation
        Functions\when( 'wp_rand' )->alias( function ( $min, $max ) {
            return mt_rand( $min, $max );
        } );

        // Default stubs
        Functions\when( 'get_post_meta' )->justReturn( array() );
        Functions\when( 'get_userdata' )->justReturn( false );
        Functions\when( 'get_avatar_url' )->justReturn( 'https://example.com/avatar.jpg' );
        // reveal_pii passes get_current_user_id() to ActivityLog::log — the arg
        // is evaluated even when the audit no-ops, so it must be defined.
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        // Global wpdb for search_user_by_cpf
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' )->makePartial();
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $this->wpdb = $wpdb;
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' )->byDefault();
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
    }

    protected function tearDown(): void {
        unset(
            $_POST['nonce'],
            $_POST['quantity'],
            $_POST['form_id'],
            $_POST['search']
        );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Helper: set up POST data for a valid generate_tickets request.
     */
    private function setup_valid_generate_tickets( int $quantity = 3, int $form_id = 10 ): void {
        $_POST['nonce'] = 'valid_nonce';
        $_POST['quantity'] = (string) $quantity;
        $_POST['form_id'] = (string) $form_id;
    }

    /**
     * Helper: set up POST data for a valid search_user request.
     */
    private function setup_valid_search_user( string $term = 'john' ): void {
        $_POST['nonce'] = 'valid_nonce';
        $_POST['search'] = $term;
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_registers_ajax_hooks(): void {
        $registered = array();
        Functions\when( 'add_action' )->alias( function ( $hook, $callback ) use ( &$registered ) {
            $registered[] = $hook;
        } );

        $ajax = new AdminAjax();

        $this->assertContains( 'wp_ajax_ffc_generate_tickets', $registered );
        $this->assertContains( 'wp_ajax_ffc_search_user', $registered );
    }

    // ==================================================================
    // generate_tickets()
    // ==================================================================

    public function test_generate_tickets_sends_error_without_nonce(): void {
        // No $_POST['nonce'] — verify_ajax_nonce should fire wp_send_json_error
        $ajax = new AdminAjax();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/wp_send_json_error/' );

        $ajax->generate_tickets();
    }

    public function test_generate_tickets_sends_error_without_permission(): void {
        $_POST['nonce'] = 'valid_nonce';

        // Permission denied — neither the certificates manage cap (#739 §4.3)
        // nor manage_options.
        $this->caps_mock->shouldReceive( 'current_user_can_admin_or' )->with( 'ffc_manage_certificates' )->andReturn( false );
        Functions\when( 'current_user_can' )->justReturn( false );

        $ajax = new AdminAjax();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/Permission denied/' );

        $ajax->generate_tickets();
    }

    public function test_generate_tickets_sends_error_for_invalid_quantity(): void {
        $this->setup_valid_generate_tickets( 0, 10 );

        $ajax = new AdminAjax();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/Quantity must be between/' );

        $ajax->generate_tickets();
    }

    public function test_generate_tickets_sends_error_for_quantity_over_1000(): void {
        $this->setup_valid_generate_tickets( 1001, 10 );

        $ajax = new AdminAjax();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/Quantity must be between/' );

        $ajax->generate_tickets();
    }

    public function test_generate_tickets_sends_error_for_missing_form_id(): void {
        $_POST['nonce'] = 'valid_nonce';
        $_POST['quantity'] = '5';
        $_POST['form_id'] = '0';

        $ajax = new AdminAjax();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/Invalid form ID/' );

        $ajax->generate_tickets();
    }

    public function test_generate_tickets_returns_correct_quantity(): void {
        $this->setup_valid_generate_tickets( 3, 10 );

        // No existing codes
        Functions\when( 'get_post_meta' )->justReturn( array() );

        $ajax = new AdminAjax();

        try {
            $ajax->generate_tickets();
            $this->fail( 'Expected AdminAjaxSuccessException to be thrown' );
        } catch ( AdminAjaxSuccessException $e ) {
            $data = $e->getData();
            $this->assertArrayHasKey( 'codes', $data );
            $this->assertArrayHasKey( 'quantity', $data );
            $this->assertSame( 3, $data['quantity'] );

            // codes is a newline-separated string
            $codes = array_filter( explode( "\n", $data['codes'] ) );
            $this->assertCount( 3, $codes );
        }
    }

    public function test_generate_tickets_codes_format(): void {
        $this->setup_valid_generate_tickets( 1, 10 );

        // Use varied wp_rand to produce different characters
        $rand_counter = 0;
        Functions\when( 'wp_rand' )->alias( function ( $min, $max ) use ( &$rand_counter ) {
            $rand_counter++;
            return $rand_counter % ( $max - $min + 1 );
        } );

        Functions\when( 'get_post_meta' )->justReturn( array() );

        $ajax = new AdminAjax();

        try {
            $ajax->generate_tickets();
            $this->fail( 'Expected AdminAjaxSuccessException to be thrown' );
        } catch ( AdminAjaxSuccessException $e ) {
            $data = $e->getData();
            $codes = array_filter( explode( "\n", $data['codes'] ) );
            $code = $codes[0];

            // Format: ABC-DEF-123 (3 uppercase letters, dash, 3 uppercase letters, dash, 3 digits)
            $this->assertMatchesRegularExpression( '/^[A-Z]{3}-[A-Z]{3}-[0-9]{3}$/', $code );
        }
    }

    // ==================================================================
    // search_user()
    // ==================================================================

    public function test_search_user_sends_error_for_short_term(): void {
        $this->setup_valid_search_user( 'a' ); // Only 1 character

        $ajax = new AdminAjax();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/at least 2 characters/' );

        $ajax->search_user();
    }

    public function test_search_user_finds_by_numeric_id(): void {
        $this->setup_valid_search_user( '42' );

        $user = new \WP_User( 42 );
        $user->display_name = 'Found User';
        $user->user_email = 'found@example.com';

        Functions\when( 'get_userdata' )->alias( function ( $id ) use ( $user ) {
            if ( $id === 42 ) {
                return $user;
            }
            return false;
        } );

        $this->user_query_mock->shouldReceive( 'get_results' )->andReturn( array() );

        $ajax = new AdminAjax();

        try {
            $ajax->search_user();
            $this->fail( 'Expected AdminAjaxSuccessException to be thrown' );
        } catch ( AdminAjaxSuccessException $e ) {
            $data = $e->getData();
            $this->assertArrayHasKey( 'users', $data );
            $this->assertCount( 1, $data['users'] );
            $this->assertSame( 42, $data['users'][0]['id'] );
            $this->assertSame( 'Found User', $data['users'][0]['display_name'] );
        }
    }

    public function test_search_user_finds_by_name_email(): void {
        $this->setup_valid_search_user( 'jane' );

        $user = new \WP_User( 55 );
        $user->ID = 55;
        $user->display_name = 'Jane Doe';
        $user->user_email = 'jane@example.com';

        $this->user_query_mock->shouldReceive( 'get_results' )->andReturn( array( $user ) );

        $ajax = new AdminAjax();

        try {
            $ajax->search_user();
            $this->fail( 'Expected AdminAjaxSuccessException to be thrown' );
        } catch ( AdminAjaxSuccessException $e ) {
            $data = $e->getData();
            $this->assertArrayHasKey( 'users', $data );
            $this->assertCount( 1, $data['users'] );
            $this->assertSame( 55, $data['users'][0]['id'] );
            $this->assertSame( 'Jane Doe', $data['users'][0]['display_name'] );
            $this->assertSame( 'jane@example.com', $data['users'][0]['email'] );
        }
    }

    public function test_search_user_returns_error_when_none_found(): void {
        $this->setup_valid_search_user( 'nonexistent' );

        // get_userdata returns false (not numeric search anyway)
        Functions\when( 'get_userdata' )->justReturn( false );
        // WP_User_Query returns empty
        $this->user_query_mock->shouldReceive( 'get_results' )->andReturn( array() );
        // CPF search also returns nothing (search term is not numeric enough)

        $ajax = new AdminAjax();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/No users found/' );

        $ajax->search_user();
    }

    public function test_search_user_falls_back_to_cpf_search(): void {
        // CPF with enough digits for the fallback search
        $this->setup_valid_search_user( '12345678901' );

        // get_userdata returns false (numeric but no WP user with that ID)
        Functions\when( 'get_userdata' )->justReturn( false );

        // WP_User_Query returns empty
        $this->user_query_mock->shouldReceive( 'get_results' )->andReturn( array() );

        // CPF search: wpdb returns a user_id, then get_userdata returns a user
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( '77' );

        $cpf_user = new \WP_User( 77 );
        $cpf_user->ID = 77;
        $cpf_user->display_name = 'CPF User';
        $cpf_user->user_email = 'cpf@example.com';

        Functions\when( 'get_userdata' )->alias( function ( $id ) use ( $cpf_user ) {
            if ( $id === 77 ) {
                return $cpf_user;
            }
            return false;
        } );

        $ajax = new AdminAjax();

        try {
            $ajax->search_user();
            $this->fail( 'Expected AdminAjaxSuccessException to be thrown' );
        } catch ( AdminAjaxSuccessException $e ) {
            $data = $e->getData();
            $this->assertArrayHasKey( 'users', $data );
            $this->assertCount( 1, $data['users'] );
            $this->assertSame( 77, $data['users'][0]['id'] );
            $this->assertSame( 'CPF User', $data['users'][0]['display_name'] );
        }
    }

    // ==================================================================
    // reveal_pii (#739 §3.3)
    // ==================================================================

    /**
     * Alias-mock Core\PiiAccessPolicy with its TIER_* constants mapped so the
     * handler's production references resolve.
     *
     * @return \Mockery\MockInterface
     */
    private function mock_pii_policy() {
        Mockery::getConfiguration()->setConstantsMap(
            array(
                'FreeFormCertificate\Core\PiiAccessPolicy' => array(
                    'TIER_MASKED'   => 'masked',
                    'TIER_REVEAL'   => 'reveal',
                    'TIER_UNMASKED' => 'unmasked',
                ),
            )
        );
        return Mockery::mock( 'alias:FreeFormCertificate\Core\PiiAccessPolicy' );
    }

    /**
     * Overload-mock SubmissionHandler so get_submission returns a fixed row.
     *
     * @param array<string,mixed> $row Row fields.
     * @return void
     */
    private function mock_submission( array $row ): void {
        $handler = Mockery::mock( 'overload:\FreeFormCertificate\Submissions\SubmissionHandler' );
        $handler->shouldReceive( 'get_submission' )->andReturn( $row );
    }

    public function test_reveal_pii_rejects_unsupported_field(): void {
        $_POST['nonce']         = 'n';
        $_POST['submission_id'] = '5';
        $_POST['field']         = 'ssn';

        $ajax = new AdminAjax();
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/Unsupported field/' );
        $ajax->reveal_pii();
    }

    public function test_reveal_pii_denied_for_masked_tier(): void {
        $_POST['nonce']         = 'n';
        $_POST['submission_id'] = '5';
        $_POST['field']         = 'cpf';

        $this->mock_submission(
            array(
                'cpf'     => '12345678901',
                'user_id' => 9,
            )
        );
        $policy = $this->mock_pii_policy();
        $policy->shouldReceive( 'resolve' )->andReturn( 'masked' );

        $ajax = new AdminAjax();
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/permission to reveal/' );
        $ajax->reveal_pii();
    }

    public function test_reveal_pii_cpf_success_formats_and_audits(): void {
        $_POST['nonce']         = 'n';
        $_POST['submission_id'] = '5';
        $_POST['field']         = 'cpf';

        $this->mock_submission(
            array(
                'cpf'     => '12345678901',
                'user_id' => 9,
            )
        );

        $policy = $this->mock_pii_policy();
        $policy->shouldReceive( 'resolve' )->andReturn( 'reveal' );

        $df = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
        $df->shouldReceive( 'format_cpf' )->with( '12345678901' )->andReturn( '123.456.789-01' );

        // The reveal tier calls the real ActivityLog::log; keep the audit log
        // disabled so it early-returns without a DB write (its LEVEL_INFO
        // constant is genuinely defined this way, avoiding the alias-mock
        // constants-map fragility).
        Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' )
            ->shouldReceive( 'activity_log_enabled' )->andReturn( false );

        $ajax = new AdminAjax();
        try {
            $ajax->reveal_pii();
            $this->fail( 'Expected AdminAjaxSuccessException' );
        } catch ( AdminAjaxSuccessException $e ) {
            $data = $e->getData();
            $this->assertSame(
                array(
                    'field' => 'cpf',
                    'value' => '123.456.789-01',
                ),
                $data
            );
        }
    }

    public function test_reveal_pii_unmasked_tier_returns_value(): void {
        $_POST['nonce']         = 'n';
        $_POST['submission_id'] = '5';
        $_POST['field']         = 'cpf';

        $this->mock_submission(
            array(
                'cpf'     => '12345678901',
                'user_id' => 9,
            )
        );

        $policy = $this->mock_pii_policy();
        $policy->shouldReceive( 'resolve' )->andReturn( 'unmasked' );

        $df = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
        $df->shouldReceive( 'format_cpf' )->andReturn( '123.456.789-01' );

        // Unmasked tier never reaches the audit branch, so ActivityLog is not
        // touched at all.
        $ajax = new AdminAjax();
        $this->expectException( AdminAjaxSuccessException::class );
        $ajax->reveal_pii();
    }

    /**
     * Overload-mock AppointmentRepository so findById returns a fixed row.
     *
     * @param array<string,mixed> $row Row fields.
     * @return void
     */
    private function mock_appointment( array $row ): void {
        $repo = Mockery::mock( 'overload:\FreeFormCertificate\Repositories\AppointmentRepository' );
        $repo->shouldReceive( 'findById' )->andReturn( $row );
    }

    public function test_reveal_pii_appointment_cpf_reveal_tier_returns_value(): void {
        $_POST['nonce']         = 'n';
        $_POST['type']          = 'appointment';
        $_POST['submission_id'] = '7';
        $_POST['field']         = 'cpf';

        $this->mock_appointment(
            array(
                'cpf'     => '12345678901',
                'user_id' => 3,
            )
        );

        $policy = $this->mock_pii_policy();
        $policy->shouldReceive( 'resolve' )->andReturn( 'reveal' );

        $df = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
        $df->shouldReceive( 'format_cpf' )->with( '12345678901' )->andReturn( '123.456.789-01' );

        // Reveal tier audits via the real ActivityLog; keep the log disabled so
        // it early-returns without a DB write.
        Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' )
            ->shouldReceive( 'activity_log_enabled' )->andReturn( false );

        $ajax = new AdminAjax();
        try {
            $ajax->reveal_pii();
            $this->fail( 'Expected AdminAjaxSuccessException' );
        } catch ( AdminAjaxSuccessException $e ) {
            $this->assertSame(
                array(
                    'field' => 'cpf',
                    'value' => '123.456.789-01',
                ),
                $e->getData()
            );
        }
    }

    public function test_reveal_pii_appointment_masked_tier_denied(): void {
        $_POST['nonce']         = 'n';
        $_POST['type']          = 'appointment';
        $_POST['submission_id'] = '7';
        $_POST['field']         = 'email';

        $this->mock_appointment(
            array(
                'email'   => 'x@y.com',
                'user_id' => 3,
            )
        );

        $policy = $this->mock_pii_policy();
        $policy->shouldReceive( 'resolve' )->andReturn( 'masked' );

        $ajax = new AdminAjax();
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/permission to reveal/' );
        $ajax->reveal_pii();
    }
}

/**
 * Custom exception to capture wp_send_json_success data in tests.
 */
class AdminAjaxSuccessException extends \RuntimeException {

    /** @var mixed */
    private $data;

    /**
     * @param mixed $data The success response data.
     */
    public function __construct( $data = null ) {
        $this->data = $data;
        parent::__construct( 'wp_send_json_success' );
    }

    /**
     * @return mixed
     */
    public function getData() {
        return $this->data;
    }
}
