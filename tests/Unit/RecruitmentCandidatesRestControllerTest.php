<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentCandidatesRestController;

/**
 * Tests for the recruitment candidates REST controller — route registration
 * + the read endpoints. Write endpoints (POST/PATCH/DELETE) pull in
 * Encryption + SensitiveFieldRegistry and are out of scope for the smoke
 * tier; this suite pins the surface contract.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCandidatesRestController
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentCandidatesRestControllerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private RecruitmentCandidatesRestController $controller;

    /** @var \Mockery\MockInterface */
    private $repoMock;

    /** @var array<int, array{namespace: string, route: string, args: array}> */
    private array $registered_routes = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'is_user_logged_in' )->justReturn( false );

        $this->registered_routes = array();
        Functions\when( 'register_rest_route' )->alias(
            function ( $namespace, $route, $args ) {
                $this->registered_routes[] = compact( 'namespace', 'route', 'args' );
                return true;
            }
        );

        $this->repoMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateReader' );
        $this->repoMock->shouldReceive( 'get_by_id' )->andReturn( null )->byDefault();
        $this->repoMock->shouldReceive( 'get_by_cpf_hash' )->andReturn( null )->byDefault();
        $this->repoMock->shouldReceive( 'get_by_rf_hash' )->andReturn( null )->byDefault();

        $errMsgMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentErrorMessages' );
        $errMsgMock->shouldReceive( 'translate' )->andReturnUsing( fn( $c ) => 'msg:' . $c );
        $errMsgMock->shouldReceive( 'translate_all' )->andReturnUsing( fn( $codes ) => array_map( fn( $c ) => 'msg:' . $c, $codes ) );

        $this->controller = new RecruitmentCandidatesRestController();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_request( array $params ): \WP_REST_Request {
        $req = Mockery::mock( 'WP_REST_Request' );
        $req->shouldReceive( 'get_param' )->andReturnUsing( fn( $k ) => $params[ $k ] ?? null );
        $req->shouldReceive( 'get_params' )->andReturn( $params );
        return $req;
    }

    // ------------------------------------------------------------------
    // register_routes()
    // ------------------------------------------------------------------

    public function test_register_routes_registers_candidate_endpoints(): void {
        $this->controller->register_routes();

        $routes = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/recruitment/candidates', $routes );
        $this->assertContains( '/recruitment/candidates/(?P<id>\d+)', $routes );
    }

    public function test_register_routes_includes_reveal_pii_endpoint(): void {
        $this->controller->register_routes();

        $routes = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/recruitment/candidates/(?P<id>\d+)/reveal-pii', $routes );

        // Permission gate must be `check_logged_in` (the policy decides
        // per-request whether the owner-clause applies; the route can't
        // reject upfront on caps alone).
        $reveal_entry = null;
        foreach ( $this->registered_routes as $entry ) {
            if ( '/recruitment/candidates/(?P<id>\d+)/reveal-pii' === $entry['route'] ) {
                $reveal_entry = $entry;
                break;
            }
        }
        $this->assertNotNull( $reveal_entry );
        $perm = $reveal_entry['args']['permission_callback'] ?? null;
        $this->assertIsArray( $perm );
        $this->assertSame( 'check_logged_in', $perm[1] );
    }

    public function test_register_routes_includes_the_me_self_endpoint(): void {
        $this->controller->register_routes();

        $routes = array_column( $this->registered_routes, 'route' );
        // The /me/recruitment route gates on is_user_logged_in instead of admin cap.
        $this->assertContains( '/recruitment/me/recruitment', $routes );
    }

    public function test_me_endpoint_uses_check_logged_in_permission_callback(): void {
        $this->controller->register_routes();

        $me_entry = null;
        foreach ( $this->registered_routes as $entry ) {
            if ( '/recruitment/me/recruitment' === $entry['route'] ) {
                $me_entry = $entry;
                break;
            }
        }
        $this->assertNotNull( $me_entry );

        // Walk the args (it's a single endpoint, not a collection).
        $perm = $me_entry['args']['permission_callback'] ?? null;
        $this->assertSame( $this->controller, $perm[0] );
        $this->assertSame( 'check_logged_in', $perm[1] );
    }

    // ------------------------------------------------------------------
    // get_candidate()
    // ------------------------------------------------------------------

    public function test_get_candidate_returns_404_when_not_found(): void {
        // Default repo returns null.
        $result = $this->controller->get_candidate( $this->make_request( array( 'id' => 999 ) ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_candidate_not_found', $result->get_error_code() );
        $this->assertSame( 404, $result->get_error_data()['status'] );
    }

    // ------------------------------------------------------------------
    // list_candidates()
    // ------------------------------------------------------------------

    public function test_list_candidates_returns_400_when_no_filter_provided(): void {
        $result = $this->controller->list_candidates( $this->make_request( array() ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_candidate_list_requires_filter', $result->get_error_code() );
        $this->assertSame( 400, $result->get_error_data()['status'] );
    }

    /**
     * Alias-mock RecruitmentPiiAccessPolicy with its TIER_* constants
     * mapped so production references resolve. Must run before any other
     * use of the class in the process.
     *
     * @return \Mockery\MockInterface
     */
    private function mock_pii_policy() {
        Mockery::getConfiguration()->setConstantsMap(
            array(
                'FreeFormCertificate\Recruitment\RecruitmentPiiAccessPolicy' => array(
                    'TIER_MASKED'   => 'masked',
                    'TIER_REVEAL'   => 'reveal',
                    'TIER_UNMASKED' => 'unmasked',
                ),
            )
        );
        return Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentPiiAccessPolicy' );
    }

    /**
     * Build a candidate row object in the CandidateRow shape that
     * shape_candidate_admin() consumes.
     *
     * @param array<string,mixed> $overrides Field overrides.
     */
    private function make_candidate_row( array $overrides = array() ): object {
        return (object) array_merge(
            array(
                'id'              => 5,
                'user_id'         => 12,
                'name'            => 'Jane Doe',
                'cpf_encrypted'   => 'ENC_CPF',
                'rf_encrypted'    => 'ENC_RF',
                'email_encrypted' => 'ENC_EMAIL',
                'phone'           => '11999998888',
                'notes'           => 'a note',
                'pcd_hash'        => 'PCD_HASH',
                'created_at'      => '2026-01-01 00:00:00',
                'updated_at'      => '2026-01-02 00:00:00',
            ),
            $overrides
        );
    }

    /** Common stubs for the shape_candidate_admin() decrypt/mask/pcd path. */
    private function stub_shape_helpers(): void {
        $enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $enc->shouldReceive( 'decrypt' )->andReturnUsing(
            function ( $v ) {
                $map = array(
                    'ENC_CPF'   => '12345678901',
                    'ENC_RF'    => '7654321',
                    'ENC_EMAIL' => 'jane@example.com',
                );
                return $map[ $v ] ?? null;
            }
        )->byDefault();
        $enc->shouldReceive( 'hash' )->andReturnUsing( fn( $v ) => 'HASH:' . $v )->byDefault();

        $df = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
        $df->shouldReceive( 'mask_email' )->andReturnUsing( fn( $e ) => 'masked:' . $e )->byDefault();
        $df->shouldReceive( 'format_cpf' )->andReturnUsing( fn( $v ) => 'cpf:' . $v )->byDefault();
        $df->shouldReceive( 'format_rf' )->andReturnUsing( fn( $v ) => 'rf:' . $v )->byDefault();

        $pcd = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentPcdHasher' );
        $pcd->shouldReceive( 'verify' )->andReturn( true )->byDefault();

        return; // collaborators registered as aliases for the duration of the test.
    }

    // ------------------------------------------------------------------
    // get_candidate() — success
    // ------------------------------------------------------------------

    public function test_get_candidate_returns_shaped_admin_payload(): void {
        $this->stub_shape_helpers();
        $row = $this->make_candidate_row();
        $this->repoMock->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $row );

        $response = $this->controller->get_candidate( $this->make_request( array( 'id' => 5 ) ) );

        $this->assertNotInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 5, $data['id'] );
        $this->assertSame( 12, $data['user_id'] );
        $this->assertSame( 'Jane Doe', $data['name'] );
        $this->assertSame( '12345678901', $data['cpf'] );
        $this->assertSame( '7654321', $data['rf'] );
        $this->assertSame( 'jane@example.com', $data['email'] );
        $this->assertSame( 'masked:jane@example.com', $data['email_masked'] );
        $this->assertSame( '11999998888', $data['phone'] );
        $this->assertTrue( $data['is_pcd'] );
    }

    public function test_get_candidate_shapes_null_user_and_empty_encrypted_fields(): void {
        $this->stub_shape_helpers();
        $row = $this->make_candidate_row(
            array(
                'user_id'         => null,
                'cpf_encrypted'   => '',
                'rf_encrypted'    => null,
                'email_encrypted' => '',
            )
        );
        $this->repoMock->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $row );

        $data = $this->controller->get_candidate( $this->make_request( array( 'id' => 5 ) ) )->get_data();

        $this->assertNull( $data['user_id'] );
        $this->assertNull( $data['cpf'] );
        $this->assertNull( $data['rf'] );
        $this->assertNull( $data['email'] );
        $this->assertNull( $data['email_masked'] );
    }

    // ------------------------------------------------------------------
    // list_candidates() — cpf / rf filter branches
    // ------------------------------------------------------------------

    public function test_list_candidates_by_cpf_returns_single_shaped_match(): void {
        $this->stub_shape_helpers();
        $san = Mockery::mock( 'alias:FreeFormCertificate\Core\DataSanitizer' );
        $san->shouldReceive( 'normalize_cpf_rf' )->andReturnUsing( fn( $v ) => preg_replace( '/\D/', '', (string) $v ) );

        $row = $this->make_candidate_row();
        $this->repoMock->shouldReceive( 'get_by_cpf_hash' )->andReturn( $row );

        $response = $this->controller->list_candidates(
            $this->make_request( array( 'cpf' => '123.456.789-01' ) )
        );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertCount( 1, $data );
        $this->assertSame( 5, $data[0]['id'] );
    }

    public function test_list_candidates_by_cpf_returns_empty_array_when_no_match(): void {
        $san = Mockery::mock( 'alias:FreeFormCertificate\Core\DataSanitizer' );
        $san->shouldReceive( 'normalize_cpf_rf' )->andReturnUsing( fn( $v ) => preg_replace( '/\D/', '', (string) $v ) );
        $enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $enc->shouldReceive( 'hash' )->andReturn( 'HASH' );

        $this->repoMock->shouldReceive( 'get_by_cpf_hash' )->andReturn( null );

        $response = $this->controller->list_candidates(
            $this->make_request( array( 'cpf' => '12345678901' ) )
        );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( array(), $response->get_data() );
    }

    public function test_list_candidates_by_rf_returns_single_shaped_match(): void {
        $this->stub_shape_helpers();
        $san = Mockery::mock( 'alias:FreeFormCertificate\Core\DataSanitizer' );
        $san->shouldReceive( 'normalize_cpf_rf' )->andReturnUsing( fn( $v ) => preg_replace( '/\D/', '', (string) $v ) );

        $row = $this->make_candidate_row();
        $this->repoMock->shouldReceive( 'get_by_rf_hash' )->andReturn( $row );

        $response = $this->controller->list_candidates(
            $this->make_request( array( 'rf' => '7654321' ) )
        );

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 1, $response->get_data() );
    }

    public function test_list_candidates_falls_through_to_400_when_cpf_normalizes_empty(): void {
        $san = Mockery::mock( 'alias:FreeFormCertificate\Core\DataSanitizer' );
        $san->shouldReceive( 'normalize_cpf_rf' )->andReturn( '' );

        $result = $this->controller->list_candidates(
            $this->make_request( array( 'cpf' => '---' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_candidate_list_requires_filter', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // update_candidate()
    // ------------------------------------------------------------------

    public function test_update_candidate_returns_400_when_no_writable_fields(): void {
        $result = $this->controller->update_candidate(
            $this->make_request( array( 'id' => 5, 'irrelevant' => 'x' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_candidate_update_no_writable_fields', $result->get_error_code() );
        $this->assertSame( 400, $result->get_error_data()['status'] );
    }

    public function test_update_candidate_returns_409_when_writer_fails(): void {
        $writer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateWriter' );
        $writer->shouldReceive( 'update' )->with( 5, Mockery::type( 'array' ) )->andReturn( false );

        $result = $this->controller->update_candidate(
            $this->make_request( array( 'id' => 5, 'name' => 'New Name' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_candidate_update_failed', $result->get_error_code() );
        $this->assertSame( 409, $result->get_error_data()['status'] );
    }

    public function test_update_candidate_success_returns_reshaped_row(): void {
        $this->stub_shape_helpers();
        $captured = array();
        $writer   = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateWriter' );
        $writer->shouldReceive( 'update' )->andReturnUsing(
            function ( $id, $update ) use ( &$captured ) {
                $captured = $update;
                return true;
            }
        );

        $row = $this->make_candidate_row();
        $this->repoMock->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $row );

        $response = $this->controller->update_candidate(
            $this->make_request( array( 'id' => 5, 'name' => 'New Name', 'phone' => '123', 'notes' => 'n' ) )
        );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 5, $response->get_data()['id'] );
        // Only the writable plain fields make it through (no cpf/rf/email here).
        $this->assertSame( array( 'name' => 'New Name', 'phone' => '123', 'notes' => 'n' ), $captured );
    }

    public function test_update_candidate_encrypts_sensitive_fields(): void {
        $this->stub_shape_helpers();
        $san = Mockery::mock( 'alias:FreeFormCertificate\Core\DataSanitizer' );
        $san->shouldReceive( 'normalize_cpf_rf' )->andReturnUsing( fn( $v ) => preg_replace( '/\D/', '', (string) $v ) );

        Mockery::getConfiguration()->setConstantsMap(
            array(
                'FreeFormCertificate\Core\SensitiveFieldRegistry' => array(
                    'CONTEXT_RECRUITMENT_CANDIDATE' => 'recruitment_candidate',
                ),
            )
        );
        $reg     = Mockery::mock( 'alias:FreeFormCertificate\Core\SensitiveFieldRegistry' );
        $captured = array();
        $reg->shouldReceive( 'encrypt_fields' )->andReturnUsing(
            function ( $ctx, $plaintexts ) use ( &$captured ) {
                $captured = $plaintexts;
                return array( 'cpf_encrypted' => 'E1', 'rf_encrypted' => 'E2', 'email_encrypted' => 'E3' );
            }
        );

        $writer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateWriter' );
        $writer->shouldReceive( 'update' )->andReturn( true );
        $this->repoMock->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $this->make_candidate_row() );

        $response = $this->controller->update_candidate(
            $this->make_request(
                array(
                    'id'    => 5,
                    'cpf'   => '123.456.789-01',
                    'rf'    => '76.543-21',
                    'email' => '  Jane@Example.COM ',
                )
            )
        );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( '12345678901', $captured['cpf'] );
        $this->assertSame( '7654321', $captured['rf'] );
        $this->assertSame( 'jane@example.com', $captured['email'] );
    }

    public function test_update_candidate_success_with_null_row_returns_null_data(): void {
        $writer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateWriter' );
        $writer->shouldReceive( 'update' )->andReturn( true );
        // Default get_by_id mock returns null.
        $response = $this->controller->update_candidate(
            $this->make_request( array( 'id' => 5, 'name' => 'X' ) )
        );

        $this->assertSame( 200, $response->get_status() );
        $this->assertNull( $response->get_data() );
    }

    // ------------------------------------------------------------------
    // delete_candidate()
    // ------------------------------------------------------------------

    public function test_delete_candidate_returns_409_with_blocked_by_on_failure(): void {
        $svc = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentDeleteService' );
        $svc->shouldReceive( 'delete_candidate' )->with( 5 )->andReturn(
            array(
                'success'    => false,
                'errors'     => array( 'recruitment_candidate_has_classifications' ),
                'blocked_by' => array( 'classifications' => 3 ),
            )
        );

        $result = $this->controller->delete_candidate( $this->make_request( array( 'id' => 5 ) ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_candidate_has_classifications', $result->get_error_code() );
        $this->assertSame( 409, $result->get_error_data()['status'] );
        $this->assertSame( array( 'classifications' => 3 ), $result->get_error_data()['blocked_by'] );
    }

    public function test_delete_candidate_returns_200_envelope_on_success(): void {
        $envelope = array( 'success' => true, 'errors' => array() );
        $svc      = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentDeleteService' );
        $svc->shouldReceive( 'delete_candidate' )->with( 5 )->andReturn( $envelope );

        $response = $this->controller->delete_candidate( $this->make_request( array( 'id' => 5 ) ) );

        $this->assertNotInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $envelope, $response->get_data() );
    }

    // ------------------------------------------------------------------
    // reveal_pii()
    // ------------------------------------------------------------------

    public function test_reveal_pii_returns_400_for_unsupported_field(): void {
        $result = $this->controller->reveal_pii(
            $this->make_request( array( 'id' => 5, 'field' => 'salary' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_pii_field_unsupported', $result->get_error_code() );
        $this->assertSame( 400, $result->get_error_data()['status'] );
    }

    public function test_reveal_pii_returns_404_when_candidate_missing(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        // Default get_by_id returns null.
        $result = $this->controller->reveal_pii(
            $this->make_request( array( 'id' => 999, 'field' => 'cpf' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_candidate_not_found', $result->get_error_code() );
        $this->assertSame( 404, $result->get_error_data()['status'] );
    }

    public function test_reveal_pii_returns_403_when_tier_masked(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        $this->repoMock->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $this->make_candidate_row() );

        $policy = $this->mock_pii_policy();
        $policy->shouldReceive( 'resolve' )->andReturn( 'masked' );

        $result = $this->controller->reveal_pii(
            $this->make_request( array( 'id' => 5, 'field' => 'cpf' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_pii_access_denied', $result->get_error_code() );
        $this->assertSame( 403, $result->get_error_data()['status'] );
    }

    public function test_reveal_pii_returns_404_when_encrypted_column_empty(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        $this->repoMock->shouldReceive( 'get_by_id' )->with( 5 )
            ->andReturn( $this->make_candidate_row( array( 'cpf_encrypted' => '' ) ) );

        $policy = $this->mock_pii_policy();
        $policy->shouldReceive( 'resolve' )->andReturn( 'unmasked' );

        $result = $this->controller->reveal_pii(
            $this->make_request( array( 'id' => 5, 'field' => 'cpf' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_pii_value_missing', $result->get_error_code() );
        $this->assertSame( 404, $result->get_error_data()['status'] );
    }

    public function test_reveal_pii_returns_500_when_decrypt_fails(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        $this->repoMock->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $this->make_candidate_row() );

        $policy = $this->mock_pii_policy();
        $policy->shouldReceive( 'resolve' )->andReturn( 'reveal' );

        $enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $enc->shouldReceive( 'decrypt' )->andReturn( null );

        $result = $this->controller->reveal_pii(
            $this->make_request( array( 'id' => 5, 'field' => 'cpf' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_pii_decrypt_failed', $result->get_error_code() );
        $this->assertSame( 500, $result->get_error_data()['status'] );
    }

    public function test_reveal_pii_cpf_success_formats_and_audits(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        $this->repoMock->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $this->make_candidate_row() );

        $policy = $this->mock_pii_policy();
        $policy->shouldReceive( 'resolve' )->andReturn( 'reveal' );
        $policy->shouldReceive( 'should_audit' )->andReturn( true );

        $enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $enc->shouldReceive( 'decrypt' )->andReturn( '12345678901' );

        $df = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
        $df->shouldReceive( 'format_cpf' )->with( '12345678901' )->andReturn( '123.456.789-01' );

        $logger = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentActivityLogger' );
        $logger->shouldReceive( 'pii_revealed' )->once()->with( 5, 'cpf' );

        $response = $this->controller->reveal_pii(
            $this->make_request( array( 'id' => 5, 'field' => 'cpf' ) )
        );

        $this->assertNotInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( array( 'field' => 'cpf', 'value' => '123.456.789-01' ), $response->get_data() );
    }

    public function test_reveal_pii_rf_success_without_audit(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        $this->repoMock->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $this->make_candidate_row() );

        $policy = $this->mock_pii_policy();
        $policy->shouldReceive( 'resolve' )->andReturn( 'reveal' );
        $policy->shouldReceive( 'should_audit' )->andReturn( false );

        $enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $enc->shouldReceive( 'decrypt' )->andReturn( '7654321' );

        $df = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
        $df->shouldReceive( 'format_rf' )->with( '7654321' )->andReturn( '76.543-21' );

        // No logger expectation — should_audit is false.
        $response = $this->controller->reveal_pii(
            $this->make_request( array( 'id' => 5, 'field' => 'rf' ) )
        );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( array( 'field' => 'rf', 'value' => '76.543-21' ), $response->get_data() );
    }

    public function test_reveal_pii_email_returns_plain_value(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        $this->repoMock->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $this->make_candidate_row() );

        $policy = $this->mock_pii_policy();
        $policy->shouldReceive( 'resolve' )->andReturn( 'reveal' );
        $policy->shouldReceive( 'should_audit' )->andReturn( false );

        $enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $enc->shouldReceive( 'decrypt' )->andReturn( 'jane@example.com' );

        $response = $this->controller->reveal_pii(
            $this->make_request( array( 'id' => 5, 'field' => 'email' ) )
        );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( array( 'field' => 'email', 'value' => 'jane@example.com' ), $response->get_data() );
    }

    // ------------------------------------------------------------------
    // get_my_recruitment()
    // ------------------------------------------------------------------

    public function test_get_my_recruitment_returns_empty_when_not_logged_in(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 0 );

        $response = $this->controller->get_my_recruitment();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( array(), $response->get_data() );
    }

    public function test_get_my_recruitment_returns_empty_when_no_candidates(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 12 );
        $this->repoMock->shouldReceive( 'get_by_user_id' )->with( 12 )->andReturn( array() );

        $response = $this->controller->get_my_recruitment();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( array(), $response->get_data() );
    }

    public function test_get_my_recruitment_groups_classifications_by_notice(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 12 );
        $this->repoMock->shouldReceive( 'get_by_user_id' )->with( 12 )
            ->andReturn( array( (object) array( 'id' => 5 ) ) );

        $clsRepo = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationRepository' );
        $clsRepo->shouldReceive( 'get_for_candidate' )->with( 5 )->andReturn(
            array(
                (object) array(
                    'id'        => 100,
                    'notice_id' => 7,
                    'list_type' => 'definitive',
                    'rank'      => 1,
                    'score'     => '9.5',
                    'status'    => 'classified',
                ),
                // A draft-notice classification that must be filtered out.
                (object) array(
                    'id'        => 101,
                    'notice_id' => 8,
                    'list_type' => 'preview',
                    'rank'      => 2,
                    'score'     => '8.0',
                    'status'    => 'classified',
                ),
                // A classification whose notice is missing entirely.
                (object) array(
                    'id'        => 102,
                    'notice_id' => 9,
                    'list_type' => 'preview',
                    'rank'      => 3,
                    'score'     => '7.0',
                    'status'    => 'classified',
                ),
            )
        );

        $noticeReader = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeReader' );
        $noticeReader->shouldReceive( 'get_by_id' )->with( 7 )->andReturn(
            (object) array(
                'id'           => 7,
                'code'         => 'N-7',
                'name'         => 'Notice 7',
                'status'       => 'open',
                'was_reopened' => '1',
            )
        );
        $noticeReader->shouldReceive( 'get_by_id' )->with( 8 )->andReturn(
            (object) array( 'id' => 8, 'code' => 'N-8', 'name' => 'Draft', 'status' => 'draft', 'was_reopened' => '0' )
        );
        $noticeReader->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( null );

        $callReader = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCallReader' );
        $callReader->shouldReceive( 'get_history_for_classification' )->with( 100 )->andReturn( array( 'call1' ) );

        $response = $this->controller->get_my_recruitment();

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertCount( 1, $data ); // only notice 7 survived
        $this->assertSame( 7, $data[0]['notice']['id'] );
        $this->assertTrue( $data[0]['notice']['was_reopened'] );
        $this->assertCount( 1, $data[0]['classifications'] );
        $this->assertSame( 100, $data[0]['classifications'][0]['id'] );
        $this->assertSame( array( 'call1' ), $data[0]['classifications'][0]['calls'] );
    }

    // ------------------------------------------------------------------
    // permission callbacks
    // ------------------------------------------------------------------

    public function test_check_admin_cap_reflects_current_user_can(): void {
        Functions\when( 'current_user_can' )->alias( fn( $cap ) => 'ffc_manage_recruitment' === $cap );
        $this->assertTrue( $this->controller->check_admin_cap() );
    }

    public function test_check_admin_cap_denies_without_cap(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        $this->assertFalse( $this->controller->check_admin_cap() );
    }

    public function test_check_can_delete_recruitment_gate(): void {
        Functions\when( 'current_user_can' )->alias( fn( $cap ) => 'ffc_delete_recruitment' === $cap );
        $this->assertTrue( $this->controller->check_can_delete_recruitment() );

        Functions\when( 'current_user_can' )->justReturn( false );
        $this->assertFalse( $this->controller->check_can_delete_recruitment() );
    }

    public function test_check_logged_in_gate(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        $this->assertTrue( $this->controller->check_logged_in() );

        Functions\when( 'is_user_logged_in' )->justReturn( false );
        $this->assertFalse( $this->controller->check_logged_in() );
    }
}
