<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentRestSupport;

/**
 * Tests for the RecruitmentRestSupport trait — permission gates + WP_Error
 * envelope helpers shared by every domain REST controller under
 * ffcertificate/v1/recruitment.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentRestSupport
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentRestSupportTraitTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var object Anonymous host instance that uses the trait. */
    private $host;

    /** @var array<string, bool> Mutable per-test capability map. */
    private array $caps = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Load the trait file so the require_once chains resolve.
        require_once __DIR__ . '/../../includes/recruitment/class-ffc-recruitment-rest-support-trait.php';

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'current_user_can' )->alias( function ( $cap ) {
            return $this->caps[ $cap ] ?? false;
        } );
        Functions\when( 'is_user_logged_in' )->justReturn( false );

        $this->host = new class() {
            use RecruitmentRestSupport;

            public function call_wp_error_from_envelope( array $errors, int $status ): \WP_Error {
                return $this->wp_error_from_envelope( $errors, $status );
            }

            public function call_wp_error_from_envelope_with_blocked( array $envelope, int $status ): \WP_Error {
                return $this->wp_error_from_envelope_with_blocked( $envelope, $status );
            }
        };

        // RecruitmentErrorMessages is used by the WP_Error helpers — alias-mock it.
        $messagesMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentErrorMessages' );
        $messagesMock->shouldReceive( 'translate' )->andReturnUsing( function ( $code ) {
            return 'msg:' . $code;
        } );
        $messagesMock->shouldReceive( 'translate_all' )->andReturnUsing( function ( $codes ) {
            return array_map( fn( $c ) => 'msg:' . $c, $codes );
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Permission gates
    // ------------------------------------------------------------------

    public function test_check_admin_cap_requires_ffc_manage_recruitment(): void {
        $this->assertFalse( $this->host->check_admin_cap() );
        $this->caps['ffc_manage_recruitment'] = true;
        $this->assertTrue( $this->host->check_admin_cap() );
    }

    public function test_check_can_view_recruitment_accepts_either_cap(): void {
        $this->assertFalse( $this->host->check_can_view_recruitment() );

        $this->caps['ffc_view_recruitment'] = true;
        $this->assertTrue( $this->host->check_can_view_recruitment() );

        $this->caps = array( 'ffc_manage_recruitment' => true );
        $this->assertTrue( $this->host->check_can_view_recruitment() );
    }

    public function test_check_can_import_csv_accepts_either_cap(): void {
        $this->assertFalse( $this->host->check_can_import_csv() );

        $this->caps['ffc_import_recruitment'] = true;
        $this->assertTrue( $this->host->check_can_import_csv() );

        $this->caps = array( 'ffc_manage_recruitment' => true );
        $this->assertTrue( $this->host->check_can_import_csv() );
    }

    public function test_check_can_call_candidates_accepts_either_cap(): void {
        $this->assertFalse( $this->host->check_can_call_candidates() );

        $this->caps['ffc_call_recruitment'] = true;
        $this->assertTrue( $this->host->check_can_call_candidates() );

        $this->caps = array( 'ffc_manage_recruitment' => true );
        $this->assertTrue( $this->host->check_can_call_candidates() );
    }

    public function test_check_can_manage_reasons_accepts_either_cap(): void {
        $this->assertFalse( $this->host->check_can_manage_reasons() );

        $this->caps['ffc_manage_recruitment_reasons'] = true;
        $this->assertTrue( $this->host->check_can_manage_reasons() );

        $this->caps = array( 'ffc_manage_recruitment' => true );
        $this->assertTrue( $this->host->check_can_manage_reasons() );
    }

    public function test_check_logged_in_delegates_to_is_user_logged_in(): void {
        $this->assertFalse( $this->host->check_logged_in() );

        Functions\when( 'is_user_logged_in' )->justReturn( true );
        $this->assertTrue( $this->host->check_logged_in() );
    }

    // ------------------------------------------------------------------
    // wp_error_from_envelope() — basic envelope
    // ------------------------------------------------------------------

    public function test_wp_error_from_envelope_uses_first_code_for_top_level_message(): void {
        $err = $this->host->call_wp_error_from_envelope( array( 'recruitment_a', 'recruitment_b' ), 400 );

        $this->assertInstanceOf( \WP_Error::class, $err );
        $this->assertSame( 'recruitment_a', $err->get_error_code() );
        $this->assertSame( 'msg:recruitment_a', $err->get_error_message() );

        $data = $err->get_error_data();
        $this->assertSame( 400, $data['status'] );
        $this->assertSame( array( 'recruitment_a', 'recruitment_b' ), $data['errors'] );
        $this->assertSame( array( 'msg:recruitment_a', 'msg:recruitment_b' ), $data['messages'] );
    }

    public function test_wp_error_from_envelope_defaults_to_recruitment_error_when_empty(): void {
        $err = $this->host->call_wp_error_from_envelope( array(), 500 );

        $this->assertSame( 'recruitment_error', $err->get_error_code() );
        $this->assertSame( 500, $err->get_error_data()['status'] );
    }

    // ------------------------------------------------------------------
    // wp_error_from_envelope_with_blocked() — surfaces reference counts
    // ------------------------------------------------------------------

    public function test_wp_error_from_envelope_with_blocked_includes_blocked_by_map(): void {
        $envelope = array(
            'success'    => false,
            'errors'     => array( 'recruitment_in_use' ),
            'blocked_by' => array( 'classifications' => 7 ),
        );
        $err = $this->host->call_wp_error_from_envelope_with_blocked( $envelope, 409 );

        $data = $err->get_error_data();
        $this->assertSame( 'recruitment_in_use', $err->get_error_code() );
        $this->assertSame( 409, $data['status'] );
        $this->assertSame( array( 'classifications' => 7 ), $data['blocked_by'] );
    }

    public function test_wp_error_from_envelope_with_blocked_omits_blocked_by_when_absent(): void {
        $envelope = array(
            'success' => false,
            'errors'  => array( 'recruitment_x' ),
        );
        $err = $this->host->call_wp_error_from_envelope_with_blocked( $envelope, 400 );

        $this->assertArrayNotHasKey( 'blocked_by', $err->get_error_data() );
    }
}
