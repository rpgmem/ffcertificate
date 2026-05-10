<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Debug;

/**
 * Tests for Debug: area enable/disable, conditional logging,
 * data formatting, and convenience method delegation.
 */
class DebugTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array Captured error_log calls */
    private $logged = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $logged = &$this->logged;
        Functions\when( 'error_log' )->alias( function ( $msg ) use ( &$logged ) {
            $logged[] = $msg;
            return true;
        } );
    }

    protected function tearDown(): void {
        $this->logged = array();
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Helper: mock get_option to enable a specific debug area.
     */
    private function enable_area( string $area ): void {
        Functions\when( 'get_option' )->justReturn( array( $area => 1 ) );
    }

    /**
     * Helper: mock get_option to disable all debug areas.
     */
    private function disable_all(): void {
        Functions\when( 'get_option' )->justReturn( array() );
    }

    // ==================================================================
    // is_enabled()
    // ==================================================================

    public function test_enabled_when_setting_is_1(): void {
        $this->enable_area( Debug::AREA_PDF_GENERATOR );
        $this->assertTrue( Debug::is_enabled( Debug::AREA_PDF_GENERATOR ) );
    }

    public function test_disabled_when_setting_missing(): void {
        $this->disable_all();
        $this->assertFalse( Debug::is_enabled( Debug::AREA_PDF_GENERATOR ) );
    }

    public function test_disabled_when_setting_is_0(): void {
        Functions\when( 'get_option' )->justReturn( array( Debug::AREA_PDF_GENERATOR => 0 ) );
        $this->assertFalse( Debug::is_enabled( Debug::AREA_PDF_GENERATOR ) );
    }

    public function test_different_areas_independent(): void {
        Functions\when( 'get_option' )->justReturn( array(
            Debug::AREA_EMAIL_HANDLER => 1,
            Debug::AREA_REST_API      => 0,
        ) );
        $this->assertTrue( Debug::is_enabled( Debug::AREA_EMAIL_HANDLER ) );
        $this->assertFalse( Debug::is_enabled( Debug::AREA_REST_API ) );
    }

    // ==================================================================
    // log() — conditional logging
    // ==================================================================

    public function test_log_writes_when_enabled(): void {
        $this->enable_area( Debug::AREA_PDF_GENERATOR );
        Debug::log( Debug::AREA_PDF_GENERATOR, 'Test message' );
        $this->assertCount( 1, $this->logged );
        $this->assertStringContainsString( '[FFC Debug] Test message', $this->logged[0] );
    }

    public function test_log_skips_when_disabled(): void {
        $this->disable_all();
        Debug::log( Debug::AREA_PDF_GENERATOR, 'Should not appear' );
        $this->assertCount( 0, $this->logged );
    }

    // ==================================================================
    // log() — data formatting
    // ==================================================================

    public function test_log_with_null_data_no_data_suffix(): void {
        $this->enable_area( Debug::AREA_ENCRYPTION );
        Debug::log( Debug::AREA_ENCRYPTION, 'No data' );
        $this->assertStringNotContainsString( '| Data:', $this->logged[0] );
    }

    public function test_log_with_string_data(): void {
        $this->enable_area( Debug::AREA_ENCRYPTION );
        Debug::log( Debug::AREA_ENCRYPTION, 'Msg', 'extra info' );
        $this->assertStringContainsString( '| Data: extra info', $this->logged[0] );
    }

    public function test_log_with_array_data(): void {
        $this->enable_area( Debug::AREA_ENCRYPTION );
        Debug::log( Debug::AREA_ENCRYPTION, 'Msg', array( 'key' => 'val' ) );
        $this->assertStringContainsString( '| Data:', $this->logged[0] );
        $this->assertStringContainsString( 'key', $this->logged[0] );
        $this->assertStringContainsString( 'val', $this->logged[0] );
    }

    public function test_log_with_integer_data(): void {
        $this->enable_area( Debug::AREA_GEOFENCE );
        Debug::log( Debug::AREA_GEOFENCE, 'Count', 42 );
        $this->assertStringContainsString( '| Data: 42', $this->logged[0] );
    }

    // ==================================================================
    // Convenience methods — delegation
    // ==================================================================

    public function test_log_pdf_delegates(): void {
        $this->enable_area( Debug::AREA_PDF_GENERATOR );
        Debug::log_pdf( 'PDF test' );
        $this->assertCount( 1, $this->logged );
        $this->assertStringContainsString( 'PDF test', $this->logged[0] );
    }

    public function test_log_email_delegates(): void {
        $this->enable_area( Debug::AREA_EMAIL_HANDLER );
        Debug::log_email( 'Email test' );
        $this->assertCount( 1, $this->logged );
    }

    public function test_log_form_delegates(): void {
        $this->enable_area( Debug::AREA_FORM_PROCESSOR );
        Debug::log_form( 'Form test' );
        $this->assertCount( 1, $this->logged );
    }

    public function test_log_rest_api_delegates(): void {
        $this->enable_area( Debug::AREA_REST_API );
        Debug::log_rest_api( 'API test' );
        $this->assertCount( 1, $this->logged );
    }

    public function test_log_migrations_delegates(): void {
        $this->enable_area( Debug::AREA_MIGRATIONS );
        Debug::log_migrations( 'Migration test' );
        $this->assertCount( 1, $this->logged );
    }

    public function test_log_activity_log_delegates(): void {
        $this->enable_area( Debug::AREA_ACTIVITY_LOG );
        Debug::log_activity_log( 'Activity test' );
        $this->assertCount( 1, $this->logged );
    }

    // ==================================================================
    // Constants — verify all areas defined
    // ==================================================================

    public function test_all_area_constants_defined(): void {
        $ref = new \ReflectionClass( Debug::class );
        $constants = $ref->getConstants();
        $areas = array_filter( $constants, function ( $k ) {
            return str_starts_with( $k, 'AREA_' );
        }, ARRAY_FILTER_USE_KEY );
        // 9 pre-6.2.0 areas + 5 added in 6.2.0 (FRONTEND, ADMIN,
        // SELF_SCHEDULING, AUDIENCE, QRCODE) when the legacy
        // `Utils::debug_log()` callsites were migrated to the per-area
        // `Debug` system.
        $this->assertCount( 14, $areas );
    }

    // ==================================================================
    // PII / credential redaction in array payloads
    // ==================================================================

    public function test_log_masks_email_value(): void {
        $this->enable_area( Debug::AREA_EMAIL_HANDLER );
        Debug::log( Debug::AREA_EMAIL_HANDLER, 'Sent', array( 'email' => 'someone@example.com' ) );
        $this->assertStringNotContainsString( 'someone@example.com', $this->logged[0] );
        // Length hint preserved so the field shape is still debuggable.
        $this->assertStringContainsString( 'len:19', $this->logged[0] );
    }

    public function test_log_masks_cpf_and_cpf_rf(): void {
        $this->enable_area( Debug::AREA_FORM_PROCESSOR );
        Debug::log( Debug::AREA_FORM_PROCESSOR, 'Doc', array(
            'cpf'    => '12345678901',
            'cpf_rf' => '7654321',
        ) );
        $this->assertStringNotContainsString( '12345678901', $this->logged[0] );
        $this->assertStringNotContainsString( '7654321', $this->logged[0] );
    }

    public function test_log_masks_auth_code_and_magic_token(): void {
        $this->enable_area( Debug::AREA_PDF_GENERATOR );
        Debug::log( Debug::AREA_PDF_GENERATOR, 'Generated', array(
            'auth_code'   => 'ABCDEF123456',
            'magic_token' => 'tok_supersecret_value_xyz',
        ) );
        $this->assertStringNotContainsString( 'ABCDEF123456', $this->logged[0] );
        $this->assertStringNotContainsString( 'tok_supersecret_value_xyz', $this->logged[0] );
    }

    public function test_log_strips_magic_token_from_url(): void {
        $this->enable_area( Debug::AREA_PDF_GENERATOR );
        Debug::log( Debug::AREA_PDF_GENERATOR, 'Built URL', array(
            'target_url' => 'https://example.com/valid?magic_token=abc123xyz&foo=bar',
        ) );
        $this->assertStringNotContainsString( 'abc123xyz', $this->logged[0] );
        $this->assertStringContainsString( '[redacted]', $this->logged[0] );
        $this->assertStringContainsString( 'foo=bar', $this->logged[0] );
    }

    public function test_log_preserves_non_sensitive_fields(): void {
        $this->enable_area( Debug::AREA_ADMIN );
        Debug::log( Debug::AREA_ADMIN, 'Op', array(
            'submission_id' => 42,
            'form_id'       => 7,
            'context'       => 'submission',
        ) );
        $this->assertStringContainsString( 'submission_id', $this->logged[0] );
        $this->assertStringContainsString( '42', $this->logged[0] );
        $this->assertStringContainsString( 'form_id', $this->logged[0] );
        $this->assertStringContainsString( 'submission', $this->logged[0] );
    }

    public function test_log_redacts_recursively(): void {
        $this->enable_area( Debug::AREA_REST_API );
        Debug::log( Debug::AREA_REST_API, 'Nested', array(
            'request' => array(
                'meta'  => array( 'email' => 'leak@example.com' ),
                'count' => 3,
            ),
        ) );
        $this->assertStringNotContainsString( 'leak@example.com', $this->logged[0] );
        $this->assertStringContainsString( 'count', $this->logged[0] );
        $this->assertStringContainsString( '3', $this->logged[0] );
    }

    public function test_log_short_value_fully_masked(): void {
        $this->enable_area( Debug::AREA_FORM_PROCESSOR );
        Debug::log( Debug::AREA_FORM_PROCESSOR, 'Short', array( 'token' => 'abc' ) );
        $this->assertStringNotContainsString( '=> abc', $this->logged[0] );
    }

    public function test_log_keys_are_case_insensitive(): void {
        $this->enable_area( Debug::AREA_FORM_PROCESSOR );
        Debug::log( Debug::AREA_FORM_PROCESSOR, 'Mixed', array( 'Email' => 'mixed@example.com' ) );
        $this->assertStringNotContainsString( 'mixed@example.com', $this->logged[0] );
    }

    public function test_log_empty_string_preserved_not_masked(): void {
        $this->enable_area( Debug::AREA_FORM_PROCESSOR );
        Debug::log( Debug::AREA_FORM_PROCESSOR, 'Empty', array( 'email' => '' ) );
        // Empty string should remain empty, not get a length hint.
        $this->assertStringNotContainsString( 'len:0', $this->logged[0] );
    }
}
