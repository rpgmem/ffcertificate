<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceCsvImporter;

/**
 * Tests for AudienceCsvImporter: CSV validation, sample generation, and import logic.
 */
class AudienceCsvImporterTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var string */
    private string $temp_dir;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->temp_dir = sys_get_temp_dir() . '/ffc_csv_test_' . uniqid();
        mkdir( $this->temp_dir, 0777, true );

        // Mock common WordPress functions
        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'sanitize_email' )->alias( function ( $email ) {
            return trim( strtolower( $email ) );
        } );
        Functions\when( 'sanitize_text_field' )->alias( 'trim' );
        Functions\when( 'sanitize_hex_color' )->alias( function ( $color ) {
            return preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ? $color : '';
        } );
        Functions\when( 'is_email' )->alias( function ( $email ) {
            return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
        } );
        Functions\when( 'absint' )->alias( function ( $val ) {
            return abs( intval( $val ) );
        } );
        Functions\when( 'sanitize_user' )->alias( function ( $user ) {
            return preg_replace( '/[^a-z0-9_.-]/i', '', $user );
        } );
    }

    protected function tearDown(): void {
        // Clean up temp files
        $files = glob( $this->temp_dir . '/*' );
        if ( $files ) {
            foreach ( $files as $file ) {
                unlink( $file );
            }
        }
        if ( is_dir( $this->temp_dir ) ) {
            rmdir( $this->temp_dir );
        }

        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helper: create a temp CSV file
    // ------------------------------------------------------------------

    private function create_csv( string $content ): string {
        $path = $this->temp_dir . '/test_' . uniqid() . '.csv';
        file_put_contents( $path, $content );
        return $path;
    }

    // ==================================================================
    // get_sample_csv()
    // ==================================================================

    public function test_get_sample_csv_members_has_email_column(): void {
        $csv = AudienceCsvImporter::get_sample_csv( 'members' );
        $lines = explode( "\n", trim( $csv ) );
        $header = $lines[0];

        $this->assertStringContainsString( 'email', $header );
        $this->assertStringContainsString( 'name', $header );
        $this->assertGreaterThan( 1, count( $lines ), 'Sample should have header + data rows' );
    }

    public function test_get_sample_csv_audiences_has_name_column(): void {
        $csv = AudienceCsvImporter::get_sample_csv( 'audiences' );
        $lines = explode( "\n", trim( $csv ) );
        $header = $lines[0];

        $this->assertStringContainsString( 'name', $header );
        $this->assertStringContainsString( 'color', $header );
        $this->assertStringContainsString( 'parent', $header );
    }

    public function test_get_sample_csv_audiences_has_parent_child_structure(): void {
        $csv = AudienceCsvImporter::get_sample_csv( 'audiences' );

        $this->assertStringContainsString( 'Group A', $csv );
        $this->assertStringContainsString( 'Subgroup A1', $csv );
        $this->assertStringContainsString( '#3788d8', $csv );
    }

    public function test_get_sample_csv_members_contains_example_emails(): void {
        $csv = AudienceCsvImporter::get_sample_csv( 'members' );

        $this->assertStringContainsString( 'john@example.com', $csv );
        $this->assertStringContainsString( 'jane@example.com', $csv );
    }

    // ==================================================================
    // validate_csv()
    // ==================================================================

    public function test_validate_csv_nonexistent_file(): void {
        $result = AudienceCsvImporter::validate_csv( '/nonexistent/file.csv', 'members' );

        $this->assertFalse( $result['valid'] );
        $this->assertNotEmpty( $result['errors'] );
    }

    public function test_validate_csv_empty_file(): void {
        $path = $this->create_csv( '' );
        $result = AudienceCsvImporter::validate_csv( $path, 'members' );

        $this->assertFalse( $result['valid'] );
        $this->assertNotEmpty( $result['errors'] );
    }

    public function test_validate_csv_members_missing_email_column(): void {
        $path = $this->create_csv( "name,audience_id\nJohn,1\n" );
        $result = AudienceCsvImporter::validate_csv( $path, 'members' );

        $this->assertFalse( $result['valid'] );
        $this->assertStringContainsString( 'email', $result['errors'][0] );
    }

    public function test_validate_csv_members_valid(): void {
        $path = $this->create_csv( "email,name,audience_id\ntest@example.com,Test User,1\ntest2@example.com,Test User 2,2\n" );
        $result = AudienceCsvImporter::validate_csv( $path, 'members' );

        $this->assertTrue( $result['valid'] );
        $this->assertSame( 2, $result['rows'] );
        $this->assertEmpty( $result['errors'] );
    }

    public function test_validate_csv_audiences_missing_name_column(): void {
        $path = $this->create_csv( "color,parent\n#ff0000,\n" );
        $result = AudienceCsvImporter::validate_csv( $path, 'audiences' );

        $this->assertFalse( $result['valid'] );
        $this->assertStringContainsString( 'name', $result['errors'][0] );
    }

    public function test_validate_csv_audiences_valid(): void {
        $path = $this->create_csv( "name,color,parent\nGroup A,#3788d8,\nSub A1,#ff0000,Group A\n" );
        $result = AudienceCsvImporter::validate_csv( $path, 'audiences' );

        $this->assertTrue( $result['valid'] );
        $this->assertSame( 2, $result['rows'] );
    }

    public function test_validate_csv_skips_empty_rows(): void {
        $path = $this->create_csv( "email,name\ntest@example.com,Test\n\n\ntest2@example.com,Test2\n" );
        $result = AudienceCsvImporter::validate_csv( $path, 'members' );

        $this->assertTrue( $result['valid'] );
        $this->assertSame( 2, $result['rows'], 'Empty rows should not be counted' );
    }

    public function test_validate_csv_normalizes_header_case(): void {
        $path = $this->create_csv( "EMAIL,Name,AUDIENCE_ID\ntest@example.com,Test,1\n" );
        $result = AudienceCsvImporter::validate_csv( $path, 'members' );

        $this->assertTrue( $result['valid'], 'Header normalization should make EMAIL match email' );
    }

    // ==================================================================
    // import_members()
    // ==================================================================

    public function test_import_members_nonexistent_file(): void {
        $result = AudienceCsvImporter::import_members( '/nonexistent/file.csv' );

        $this->assertFalse( $result['success'] );
        $this->assertNotEmpty( $result['errors'] );
    }

    public function test_import_members_empty_file(): void {
        $path = $this->create_csv( '' );
        $result = AudienceCsvImporter::import_members( $path );

        $this->assertFalse( $result['success'] );
    }

    public function test_import_members_missing_email_column(): void {
        $path = $this->create_csv( "name,audience_id\nJohn,1\n" );
        $result = AudienceCsvImporter::import_members( $path );

        $this->assertFalse( $result['success'] );
        $this->assertStringContainsString( 'email', $result['errors'][0] );
    }

    public function test_import_members_no_audience_specified(): void {
        $path = $this->create_csv( "email,name\ntest@example.com,Test\n" );
        $result = AudienceCsvImporter::import_members( $path, 0 );

        $this->assertFalse( $result['success'] );
        $this->assertStringContainsString( 'audience', strtolower( $result['errors'][0] ) );
    }

    public function test_import_members_invalid_email_skipped(): void {
        $path = $this->create_csv( "email,name\nnot-an-email,Test\n" );

        $mock_user = new \WP_User( 1 );
        $mock_user->display_name = 'Test';
        $mock_user->user_email = 'test@example.com';

        Functions\when( 'get_user_by' )->justReturn( null );

        $result = AudienceCsvImporter::import_members( $path, 5 );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 0, $result['imported'] );
        $this->assertSame( 1, $result['skipped'] );
        $this->assertStringContainsString( 'Row 2', $result['errors'][0] );
    }

    public function test_import_members_user_not_found_without_create(): void {
        $path = $this->create_csv( "email,name\ntest@example.com,Test User\n" );

        Functions\when( 'get_user_by' )->justReturn( false );

        $result = AudienceCsvImporter::import_members( $path, 5, false );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 0, $result['imported'] );
        $this->assertSame( 1, $result['skipped'] );
        $this->assertStringContainsString( 'not found', $result['errors'][0] );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_import_members_successful_with_existing_user(): void {
        $path = $this->create_csv( "email,name\ntest@example.com,Test User\n" );

        $mock_user = new \WP_User( 42 );
        $mock_user->user_email = 'test@example.com';
        $mock_user->display_name = 'Test User';

        Functions\when( 'get_user_by' )->justReturn( $mock_user );

        // Mock AudienceRepository::add_member()
        $repo_mock = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo_mock->shouldReceive( 'add_member' )
                  ->with( 5, 42 )
                  ->once()
                  ->andReturn( true );

        $result = AudienceCsvImporter::import_members( $path, 5 );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 1, $result['imported'] );
        $this->assertSame( 0, $result['skipped'] );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_import_members_already_member_counted_as_skipped(): void {
        $path = $this->create_csv( "email,name\ntest@example.com,Test\n" );

        $mock_user = new \WP_User( 42 );
        $mock_user->user_email = 'test@example.com';
        Functions\when( 'get_user_by' )->justReturn( $mock_user );

        $repo_mock = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo_mock->shouldReceive( 'add_member' )
                  ->with( 5, 42 )
                  ->once()
                  ->andReturn( false );

        $result = AudienceCsvImporter::import_members( $path, 5 );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 0, $result['imported'] );
        $this->assertSame( 1, $result['skipped'] );
    }

    // ==================================================================
    // import_audiences()
    // ==================================================================

    public function test_import_audiences_nonexistent_file(): void {
        $result = AudienceCsvImporter::import_audiences( '/nonexistent/file.csv' );

        $this->assertFalse( $result['success'] );
        $this->assertNotEmpty( $result['errors'] );
    }

    public function test_import_audiences_missing_name_column(): void {
        $path = $this->create_csv( "color,parent\n#ff0000,\n" );
        $result = AudienceCsvImporter::import_audiences( $path );

        $this->assertFalse( $result['success'] );
        $this->assertStringContainsString( 'name', $result['errors'][0] );
    }

    public function test_import_audiences_empty_name_skipped(): void {
        $path = $this->create_csv( "name,color,parent\n,#ff0000,\n" );

        // Mock wpdb for get_audience_id_by_name
        $this->mock_wpdb();

        $result = AudienceCsvImporter::import_audiences( $path );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 0, $result['imported'] );
        $this->assertSame( 1, $result['skipped'] );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_import_audiences_creates_parents_first(): void {
        $path = $this->create_csv( "name,color,parent\nChild A1,#ff0000,Parent A\nParent A,#3788d8,\n" );

        $wpdb = $this->mock_wpdb();
        // get_audience_id_by_name returns 0 for all (nothing exists yet)
        $wpdb->shouldReceive( 'get_var' )->andReturn( null );

        $repo_mock = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo_mock->shouldReceive( 'get_table_name' )->andReturn( 'wp_ffc_audiences' );

        // Parent A should be created first (no parent_name)
        $repo_mock->shouldReceive( 'create' )
                  ->with( Mockery::on( function ( $data ) {
                      return $data['name'] === 'Parent A' && $data['parent_id'] === null;
                  } ) )
                  ->once()
                  ->andReturn( 10 );

        // Then Child A1 should try but fail because parent lookup returns 0
        // (since get_var returns null for get_audience_id_by_name)
        $result = AudienceCsvImporter::import_audiences( $path );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 1, $result['imported'] ); // Only Parent A created
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_import_audiences_skips_existing(): void {
        $path = $this->create_csv( "name,color\nExisting Group,#ff0000\n" );

        $wpdb = $this->mock_wpdb();
        // get_audience_id_by_name returns existing ID
        $wpdb->shouldReceive( 'get_var' )->andReturn( '5' );

        $repo_mock = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo_mock->shouldReceive( 'get_table_name' )->andReturn( 'wp_ffc_audiences' );
        $repo_mock->shouldReceive( 'create' )->never();

        $result = AudienceCsvImporter::import_audiences( $path );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 0, $result['imported'] );
        $this->assertSame( 1, $result['skipped'] );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_import_audiences_default_color_when_invalid(): void {
        $path = $this->create_csv( "name,color\nTest Group,not-a-color\n" );

        $wpdb = $this->mock_wpdb();
        $wpdb->shouldReceive( 'get_var' )->andReturn( null );

        $repo_mock = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo_mock->shouldReceive( 'get_table_name' )->andReturn( 'wp_ffc_audiences' );

        // Should use default color when sanitize_hex_color returns empty
        $repo_mock->shouldReceive( 'create' )
                  ->with( Mockery::on( function ( $data ) {
                      return $data['color'] === '#3788d8';
                  } ) )
                  ->once()
                  ->andReturn( 1 );

        $result = AudienceCsvImporter::import_audiences( $path );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 1, $result['imported'] );
    }

    // ------------------------------------------------------------------
    // Helper: mock global $wpdb
    // ------------------------------------------------------------------

    private function mock_wpdb(): \Mockery\MockInterface {
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () {
            return func_get_args()[0]; // Return the query string
        } );
        return $wpdb;
    }
}
