<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationFormRenderer;

/**
 * @covers \FreeFormCertificate\Reregistration\ReregistrationFormRenderer
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ReregistrationFormRendererTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'checked' )->justReturn( '' );
        Functions\when( 'wp_date' )->alias( function ( $format, $ts ) { return date( $format, $ts ); } );
        Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
        Functions\when( 'wp_nonce_field' )->justReturn( '' );

        $user_mock = Mockery::mock( 'WP_User' );
        $user_mock->user_email      = 'test@example.com';
        $user_mock->display_name    = 'Test User';
        $user_mock->user_registered = '2025-01-01 00:00:00';
        Functions\when( 'get_userdata' )->justReturn( $user_mock );

        if ( ! defined( 'ARRAY_A' ) ) {
            define( 'ARRAY_A', 'ARRAY_A' );
        }
        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }

        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return func_get_arg(0); } )->byDefault();
        $wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @var array<object>|null Field definitions the CustomFieldRepository mock
     *                         returns. Reset before each `mockRepositories` call.
     */
    private static $mockFields = null;

    /**
     * Build a minimal field stdClass with renderer-safe defaults.
     *
     * @param array<string, mixed> $overrides
     */
    private function makeField( array $overrides ): object {
        return (object) array_merge( array(
            'id'                 => 0,
            'field_key'          => '',
            'field_label'        => '',
            'field_type'         => 'text',
            'field_group'        => 'personal',
            'field_source'       => 'standard',
            'field_profile_key'  => null,
            'field_mask'         => null,
            'is_sensitive'       => 0,
            'field_options'      => null,
            'validation_rules'   => null,
            'sort_order'         => 0,
            'is_required'        => 0,
            'is_active'          => 1,
        ), $overrides );
    }

    /**
     * Register alias mocks for the renderer's static repository dependencies.
     *
     * @param array<object> $fields Fields to expose through
     *                              CustomFieldRepository::get_by_audience_with_parents.
     */
    private function mockRepositories( array $fields = array() ): void {
        self::$mockFields = $fields;

        $reregRepoMock = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' );
        $reregRepoMock->shouldReceive( 'get_audience_ids' )->andReturn( empty( $fields ) ? array() : array( 1 ) );

        $customFieldRepoMock = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\CustomFieldRepository' );
        $customFieldRepoMock->shouldReceive( 'get_by_audience_with_parents' )->andReturn( $fields );
        $customFieldRepoMock->shouldReceive( 'get_user_data' )->andReturn( array() );

        $fieldOptionsMock = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationFieldOptions' );
        $fieldOptionsMock->shouldIgnoreMissing( array() );

        $seederMock = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' );
        $seederMock->shouldReceive( 'get_group_labels' )->andReturn( array(
            'personal' => 'Personal Data',
            'contact'  => 'Contact Information',
        ) );
    }

    // ==================================================================
    // render() — basic rendering
    // ==================================================================

    public function test_render_returns_form_html(): void {
        $this->mockRepositories();

        $rereg = (object) array(
            'id'       => 1,
            'title'    => 'Recadastramento 2025',
            'end_date' => '2025-12-31 23:59:59',
        );
        $submission = (object) array( 'data' => null );

        $html = ReregistrationFormRenderer::render( $rereg, $submission, 10 );

        $this->assertStringContainsString( 'ffc-rereg-form-container', $html );
        $this->assertStringContainsString( 'Recadastramento 2025', $html );
        $this->assertStringContainsString( 'ffc-rereg-form', $html );
    }

    // ==================================================================
    // render() — with saved draft data
    // ==================================================================

    public function test_render_populates_from_saved_draft(): void {
        $this->mockRepositories( array(
            $this->makeField( array(
                'id'          => 1,
                'field_key'   => 'display_name',
                'field_label' => 'Name',
                'field_type'  => 'text',
                'field_group' => 'personal',
                'is_required' => 1,
            ) ),
            $this->makeField( array(
                'id'          => 2,
                'field_key'   => 'phone',
                'field_label' => 'Home Phone',
                'field_type'  => 'text',
                'field_group' => 'contact',
            ) ),
        ) );

        $rereg = (object) array(
            'id'       => 2,
            'title'    => 'Test Draft',
            'end_date' => '2025-06-30 23:59:59',
        );
        $submission = (object) array(
            'data' => json_encode( array(
                'fields' => array(
                    'display_name' => 'Maria Silva',
                    'phone'        => '11999999999',
                ),
            ) ),
        );

        $html = ReregistrationFormRenderer::render( $rereg, $submission, 10 );

        $this->assertStringContainsString( 'Maria Silva', $html );
        $this->assertStringContainsString( '11999999999', $html );
    }

    // ==================================================================
    // render() — deadline is displayed
    // ==================================================================

    public function test_render_shows_deadline(): void {
        $this->mockRepositories();

        $rereg = (object) array(
            'id'       => 3,
            'title'    => 'Deadline Test',
            'end_date' => '2025-09-15 18:00:00',
        );
        $submission = (object) array( 'data' => null );

        $html = ReregistrationFormRenderer::render( $rereg, $submission, 5 );

        $this->assertStringContainsString( 'Deadline', $html );
        $this->assertStringContainsString( '2025-09-15', $html );
    }
}
