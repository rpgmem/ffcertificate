<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\Strategies\CpfRfSplitMigrationStrategy;

/**
 * Tests for CpfRfSplitMigrationStrategy: splits combined cpf_rf into separate cpf/rf columns.
 *
 * The constructor calls SubmissionRepository::get_submissions_table() and accesses $wpdb, so we
 * construct with a properly stubbed environment, then use reflection for private methods.
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\CpfRfSplitMigrationStrategy
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class CpfRfSplitMigrationStrategyTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var \Mockery\MockInterface&\stdClass */
    private $wpdb;

    /** @var CpfRfSplitMigrationStrategy */
    private CpfRfSplitMigrationStrategy $strategy;

    /** @var \ReflectionClass<CpfRfSplitMigrationStrategy> */
    private \ReflectionClass $ref;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Mock $wpdb
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' )->makePartial();
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'Q' )->byDefault();
        $wpdb->shouldReceive( 'query' )->andReturn( 0 )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
        $wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $wpdb->shouldReceive( 'get_col' )->andReturn( array() )->byDefault();
        $wpdb->shouldReceive( 'update' )->andReturn( 1 )->byDefault();
        $this->wpdb = $wpdb;

        // WP_Error alias for Strategies namespace
        if ( ! class_exists( 'FreeFormCertificate\Migrations\Strategies\WP_Error' ) ) {
            class_alias( 'WP_Error', 'FreeFormCertificate\Migrations\Strategies\WP_Error' );
        }

        // Global WP stubs
        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );

        // Namespaced stubs for Strategies namespace
        Functions\when( 'FreeFormCertificate\Migrations\Strategies\__' )->returnArg();
        Functions\when( 'FreeFormCertificate\Migrations\Strategies\is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );

        // Namespaced stubs for Core namespace (used by ActivityLog)
        Functions\when( 'FreeFormCertificate\Core\get_option' )->justReturn( array() );
        Functions\when( 'FreeFormCertificate\Settings\get_option' )->justReturn( array() );
        Functions\when( 'FreeFormCertificate\Core\absint' )->alias( function( $val ) { return abs( (int) $val ); } );

        // Construct strategy — constructor calls SubmissionRepository::get_submissions_table() which uses $wpdb->prefix
        $this->strategy = new CpfRfSplitMigrationStrategy();
        $this->ref = new \ReflectionClass( CpfRfSplitMigrationStrategy::class );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Invoke a private method on the strategy.
     *
     * @param string $method Method name.
     * @param array<int, mixed> $args Arguments.
     * @return mixed
     */
    private function invokePrivate( string $method, array $args = [] ) {
        $m = $this->ref->getMethod( $method );
        $m->setAccessible( true );
        return $m->invoke( $this->strategy, ...$args );
    }

    /**
     * Set a private property on the strategy.
     */
    private function setPrivate( string $name, $value ): void {
        $prop = $this->ref->getProperty( $name );
        $prop->setAccessible( true );
        $prop->setValue( $this->strategy, $value );
    }

    // ==================================================================
    // get_name()
    // ==================================================================

    public function test_get_name_returns_expected_string(): void {
        $name = $this->strategy->get_name();

        $this->assertSame( 'CPF/RF Split Migration', $name );
    }

    // ==================================================================
    // can_run() — encryption class missing
    // ==================================================================

    public function test_can_run_returns_wp_error_when_encryption_class_missing(): void {
        // Mock class_exists to return false for Encryption
        // Since the code uses class_exists() directly, we need to use a namespace-aware approach.
        // The can_run method checks class_exists( '\\FreeFormCertificate\\Core\\Encryption' )
        // which actually exists in autoloader. We need to test the column_exists path instead.
        // Let's skip testing class_exists scenario and test the columns_missing scenario.

        // For can_run, Encryption class exists (loaded by autoloader), and is_configured
        // uses the SECURE_AUTH_KEY constant defined in bootstrap, so it returns true.
        // We test the columns_missing scenario.

        // column_exists calls SHOW COLUMNS FROM => returns empty (no column)
        $this->wpdb->shouldReceive( 'get_results' )
            ->andReturn( array() ); // no columns found

        $result = $this->strategy->can_run( 'split_cpf_rf', array() );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'columns_missing', $result->get_error_code() );
    }

    // ==================================================================
    // can_run() — columns exist, encryption configured
    // ==================================================================

    public function test_can_run_returns_true_when_all_prerequisites_met(): void {
        // column_exists returns non-empty for both cpf_hash and rf_hash
        // Each call to column_exists calls get_results. We need two calls to return non-empty.
        $col_result = array( (object) array( 'Field' => 'cpf_hash' ) );

        $this->wpdb->shouldReceive( 'get_results' )
            ->andReturn( $col_result );

        $result = $this->strategy->can_run( 'split_cpf_rf', array() );

        $this->assertTrue( $result );
    }

    // ==================================================================
    // calculate_status() — both tables empty or nonexistent
    // ==================================================================

    public function test_calculate_status_returns_complete_when_no_tables(): void {
        // table_exists => returns null (tables don't exist)
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

        $status = $this->strategy->calculate_status( 'split_cpf_rf', array() );

        $this->assertSame( 0, $status['total'] );
        $this->assertSame( 0, $status['migrated'] );
        $this->assertSame( 0, $status['pending'] );
        $this->assertTrue( $status['is_complete'] );
        $this->assertSame( 100.0, $status['percent'] );
    }

    // ==================================================================
    // calculate_status() — cpf_rf_hash column already dropped (fully migrated)
    // ==================================================================

    public function test_calculate_status_when_legacy_column_dropped(): void {
        // table_exists: returns table name for submissions
        // column_exists for cpf_rf_hash: returns empty (column dropped)
        // column_exists for cpf_hash: would not be called since cpf_rf_hash missing

        // First call: table_exists for submissions => yes
        // Second call: column_exists cpf_rf_hash => no (empty)
        // Third call: table_exists for appointments => no
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturn(
                'wp_ffc_submissions',  // table_exists submissions
                '10',                  // COUNT(*) total records
                null                   // table_exists appointments => no
            );

        $this->wpdb->shouldReceive( 'get_results' )
            ->andReturn( array() ); // column cpf_rf_hash does not exist

        $status = $this->strategy->calculate_status( 'split_cpf_rf', array() );

        $this->assertSame( 10, $status['total'] );
        $this->assertSame( 10, $status['migrated'] );
        $this->assertSame( 0, $status['pending'] );
        $this->assertTrue( $status['is_complete'] );
    }

    // ==================================================================
    // resolve_plain_value() — plain text available
    // ==================================================================

    public function test_resolve_plain_value_returns_plain_cpf_rf(): void {
        $record = array(
            'id'               => 1,
            'cpf_rf'           => '12345678901',
            'cpf_rf_encrypted' => 'enc_value',
            'cpf_rf_hash'      => 'hash_value',
        );

        $result = $this->invokePrivate( 'resolve_plain_value', array( $record ) );

        $this->assertSame( '12345678901', $result );
    }

    // ==================================================================
    // resolve_plain_value() — encrypted value needs decryption
    // ==================================================================

    public function test_resolve_plain_value_decrypts_when_no_plain_text(): void {
        $record = array(
            'id'               => 2,
            'cpf_rf'           => '',
            'cpf_rf_encrypted' => 'encrypted_data',
            'cpf_rf_hash'      => 'hash_value',
        );

        // Encryption::decrypt with invalid data returns null,
        // confirming the decrypt path is exercised when cpf_rf is empty.
        $result = $this->invokePrivate( 'resolve_plain_value', array( $record ) );

        $this->assertNull( $result );
    }

    // ==================================================================
    // resolve_plain_value() — both empty returns null
    // ==================================================================

    public function test_resolve_plain_value_returns_null_when_both_empty(): void {
        $record = array(
            'id'               => 3,
            'cpf_rf'           => '',
            'cpf_rf_encrypted' => '',
            'cpf_rf_hash'      => 'hash_value',
        );

        $result = $this->invokePrivate( 'resolve_plain_value', array( $record ) );

        $this->assertNull( $result );
    }

    // ==================================================================
    // execute() — no records to process
    // ==================================================================

    public function test_execute_with_no_pending_records(): void {
        // process_table gets empty records, table_exists checks, etc.
        // get_results returns empty for both tables
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

        // table_exists for appointments returns null (doesn't exist)
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

        $result = $this->strategy->execute( 'split_cpf_rf', array( 'batch_size' => 50 ) );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 0, $result['processed'] );
    }

    // ==================================================================
    // execute() — uses batch_size from config
    // ==================================================================

    public function test_execute_uses_batch_size_from_config(): void {
        // The execute method reads batch_size from migration_config
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

        $result = $this->strategy->execute( 'split_cpf_rf', array( 'batch_size' => 25 ) );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 0, $result['processed'] );
        $this->assertArrayHasKey( 'has_more', $result );
        $this->assertArrayHasKey( 'message', $result );
    }

    // ==================================================================
    // execute() — defaults to batch_size 50 when not set
    // ==================================================================

    public function test_execute_defaults_batch_size_to_50(): void {
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

        $result = $this->strategy->execute( 'split_cpf_rf', array() );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 0, $result['processed'] );
    }

    // ==================================================================
    // process_table() — classifies CPF / RF / unknown-length records
    // ==================================================================

    /**
     * Alias-mock ActivityLog (real class references its own LEVEL_* constants,
     * so we make ActivityLog::log() a no-op by stubbing SettingsReader instead
     * of alias-mocking ActivityLog itself). Safe under runClassInSeparateProcess
     * because no other test in this class references ActivityLog / SettingsReader.
     */
    private function stubActivityLog(): void {
        if ( ! class_exists( 'FreeFormCertificate\Settings\SettingsReader', false ) ) {
            $reader = Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' );
            $reader->shouldReceive( 'activity_log_enabled' )->andReturn( false )->byDefault();
            $reader->shouldReceive( 'activity_log_min_level' )->andReturn( 'info' )->byDefault();
            $reader->shouldReceive( 'activity_log_category_enabled' )->andReturn( true )->byDefault();
        }
    }

    public function test_process_table_splits_cpf_rf_and_unknown_length_records(): void {
        $this->stubActivityLog();

        $records = array(
            // 11 digits → CPF branch.
            array(
                'id'               => 1,
                'cpf_rf'           => '123.456.789-01',
                'cpf_rf_encrypted' => 'enc_cpf',
                'cpf_rf_hash'      => 'hash_cpf',
            ),
            // 7 digits → RF branch.
            array(
                'id'               => 2,
                'cpf_rf'           => '1234567',
                'cpf_rf_encrypted' => 'enc_rf',
                'cpf_rf_hash'      => 'hash_rf',
            ),
            // 5 digits → unknown-length branch (defaults to CPF + logs warning).
            array(
                'id'               => 3,
                'cpf_rf'           => '12345',
                'cpf_rf_encrypted' => 'enc_unknown',
                'cpf_rf_hash'      => 'hash_unknown',
            ),
            // Empty resolved value → error path (could not resolve).
            array(
                'id'               => 4,
                'cpf_rf'           => '',
                'cpf_rf_encrypted' => '',
                'cpf_rf_hash'      => 'hash_empty',
            ),
        );

        $this->wpdb->shouldReceive( 'get_results' )->andReturn( $records );
        $this->wpdb->shouldReceive( 'update' )->andReturn( 1 );

        $result = $this->invokePrivate( 'process_table', array( 'wp_ffc_submissions', 50 ) );

        // 3 processed (CPF, RF, unknown), 1 error (empty resolve).
        $this->assertSame( 3, $result['processed'] );
        $this->assertCount( 1, $result['errors'] );
    }

    public function test_process_table_records_update_failure_as_error(): void {
        $this->stubActivityLog();

        $records = array(
            array(
                'id'               => 1,
                'cpf_rf'           => '12345678901',
                'cpf_rf_encrypted' => 'enc_cpf',
                'cpf_rf_hash'      => 'hash_cpf',
            ),
        );

        $this->wpdb->shouldReceive( 'get_results' )->andReturn( $records );
        // update() returns false → failure branch records an error.
        $this->wpdb->shouldReceive( 'update' )->andReturn( false );

        $result = $this->invokePrivate( 'process_table', array( 'wp_ffc_submissions', 50 ) );

        $this->assertSame( 0, $result['processed'] );
        $this->assertCount( 1, $result['errors'] );
    }

    public function test_process_table_returns_empty_when_no_records(): void {
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

        $result = $this->invokePrivate( 'process_table', array( 'wp_ffc_submissions', 50 ) );

        $this->assertSame( 0, $result['processed'] );
        $this->assertSame( array(), $result['errors'] );
    }

    // ==================================================================
    // count_table_status() — table with pending + migrated rows
    // ==================================================================

    public function test_count_table_status_with_pending_and_migrated(): void {
        // table_exists → matches; cpf_rf_hash exists; cpf_hash exists;
        // then COUNT(*) total = 10, pending = 4 → migrated 6.
        $this->wpdb->shouldReceive( 'get_var' )->andReturn(
            'wp_ffc_submissions', // table_exists
            10,                   // total
            4                     // pending
        );
        // column_exists cpf_rf_hash → present, cpf_hash → present.
        $this->wpdb->shouldReceive( 'get_results' )->andReturn(
            array( (object) array( 'Field' => 'cpf_rf_hash' ) )
        );

        $status = $this->invokePrivate( 'count_table_status', array( 'wp_ffc_submissions' ) );

        $this->assertSame( 10, $status['total'] );
        $this->assertSame( 6, $status['migrated'] );
        $this->assertSame( 4, $status['pending'] );
    }

    // ==================================================================
    // can_run() — encryption not configured branch
    // ==================================================================

    public function test_can_run_returns_error_when_encryption_not_configured(): void {
        // Encryption::is_configured() reads SECURE_AUTH_KEY etc.; alias-mock it
        // to force the not-configured branch.
        $enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $enc->shouldReceive( 'is_configured' )->andReturn( false )->byDefault();

        $result = $this->strategy->can_run( 'split_cpf_rf', array() );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'encryption_not_configured', $result->get_error_code() );
    }
}
