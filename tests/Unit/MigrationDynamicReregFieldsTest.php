<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\MigrationDynamicReregFields;

/**
 * Tests for MigrationDynamicReregFields: status tracking, table upgrades,
 * and standard field seeding.
 *
 * @covers \FreeFormCertificate\Migrations\MigrationDynamicReregFields
 */
class MigrationDynamicReregFieldsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' )->makePartial();
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error  = '';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return func_get_args()[0]; } )->byDefault();
        $wpdb->shouldReceive( 'query' )->andReturn( 0 )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $wpdb->shouldReceive( 'get_col' )->andReturn( array() )->byDefault();
        $wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'utf8mb4_general_ci' )->byDefault();

        $this->wpdb = $wpdb;

        Functions\when( '__' )->returnArg();
        Functions\when( 'FreeFormCertificate\Migrations\__' )->returnArg();
        Functions\when( 'FreeFormCertificate\Migrations\dbDelta' )->justReturn( array() );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // is_completed()
    // ==================================================================

    public function test_is_completed_returns_true_when_option_set(): void {
        Functions\when( 'get_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( true );

        $this->assertTrue( MigrationDynamicReregFields::is_completed() );
    }

    public function test_is_completed_returns_false_when_option_not_set(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );

        $this->assertFalse( MigrationDynamicReregFields::is_completed() );
    }

    // ==================================================================
    // run() — already completed
    // ==================================================================

    public function test_run_returns_early_when_already_completed(): void {
        Functions\when( 'get_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( true );

        $result = MigrationDynamicReregFields::run();

        $this->assertTrue( $result['success'] );
        $this->assertEmpty( $result['details'] );
    }

    // ==================================================================
    // run() — executes migration
    // ==================================================================

    public function test_run_executes_all_steps_on_success(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\update_option' )->justReturn( true );

        // column_exists checks: return true so the upgrade steps report success
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( 'field_group' )->byDefault();
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array( 'Field' => 'field_group' ) ) )->byDefault();

        $result = MigrationDynamicReregFields::run();

        $this->assertTrue( $result['success'] );
        $this->assertArrayHasKey( 'ffc_custom_fields', $result['details'] );
        $this->assertArrayHasKey( 'ffc_reregistration_submissions', $result['details'] );
        $this->assertArrayHasKey( 'seed_standard_fields', $result['details'] );
    }

    // ==================================================================
    // get_status()
    // ==================================================================

    public function test_get_status_returns_completed_and_column_info(): void {
        Functions\when( 'get_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( true );

        $status = MigrationDynamicReregFields::get_status();

        $this->assertTrue( $status['completed'] );
        $this->assertArrayHasKey( 'columns', $status );
        $this->assertArrayHasKey( 'custom_fields.field_group', $status['columns'] );
        $this->assertArrayHasKey( 'custom_fields.is_sensitive', $status['columns'] );
        $this->assertArrayHasKey( 'submissions.auth_code', $status['columns'] );
        $this->assertArrayHasKey( 'submissions.magic_token', $status['columns'] );
    }

    public function test_get_status_not_completed(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );

        $status = MigrationDynamicReregFields::get_status();

        $this->assertFalse( $status['completed'] );
    }

    // ==================================================================
    // Activation-order guard: audiences table missing on a fresh install
    // ==================================================================

    public function test_seed_skips_cleanly_when_audiences_table_missing(): void {
        // get_var() (the table-exists probe) returns null by default in setUp,
        // so `ffc_audiences` reads as absent. The seeder must NOT be reached —
        // otherwise it runs `SELECT id FROM wp_ffc_audiences` and logs a
        // "Table doesn't exist" DB error on a fresh install.
        $ref = new \ReflectionMethod( MigrationDynamicReregFields::class, 'seed_standard_fields_all_audiences' );
        $ref->setAccessible( true );
        $result = $ref->invoke( null );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 0, $result['seeded'] );
    }

    // ==================================================================
    // dbDelta SQL hygiene — no backtick identifiers inside `-- ` comments
    // ==================================================================

    public function test_dbdelta_create_table_sql_has_no_backtick_inline_comments(): void {
        // A standalone `-- ` SQL comment that contains a backtick identifier
        // makes dbDelta's column-diff parser misread it as a real column and
        // emit a broken `ALTER TABLE … ADD COLUMN -- …` (logged DB error on
        // every activation). Guard every dbDelta source against re-introduction.
        $files = array(
            __DIR__ . '/../../includes/migrations/class-ffc-migration-dynamic-rereg-fields.php',
            __DIR__ . '/../../includes/migrations/class-ffc-migration-custom-fields-tables.php',
            __DIR__ . '/../../includes/self-scheduling/class-ffc-self-scheduling-activator.php',
            __DIR__ . '/../../includes/recruitment/class-ffc-recruitment-activator.php',
        );
        foreach ( $files as $file ) {
            $this->assertFileExists( $file );
            $lines = file( $file );
            foreach ( $lines as $i => $line ) {
                $this->assertDoesNotMatchRegularExpression(
                    '/^\s*--\s.*`/',
                    $line,
                    sprintf( '%s line %d: a backtick in a dbDelta `-- ` comment breaks dbDelta parsing', basename( $file ), $i + 1 )
                );
            }
        }
    }
}
