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
        $this->assertCount( 9, $areas );
    }
}
