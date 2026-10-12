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
 * Tests for the AudienceCsvImporter paths AudienceCsvImporterTest leaves
 * open: resolving the audience per row (by id or by name), a user the
 * creator refuses, and the depth-ordered audience import — children resolved
 * on a later pass, an existing child skipped, a parent that never appears,
 * and a write that fails.
 *
 * The reader/writer and the user creator are aliased, so each test runs in
 * its own process.
 *
 * @covers \FreeFormCertificate\Audience\AudienceCsvImporter
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceCsvImporterEdgesTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private string $temp_dir;

	/** @var array<string, int> Audience name => id, as the audiences table holds them. */
	private array $audiences = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->temp_dir = sys_get_temp_dir() . '/ffc_csv_edges_' . uniqid();
		mkdir( $this->temp_dir, 0777, true );

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_email' )->alias( static fn ( $email ) => trim( strtolower( (string) $email ) ) );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'is_email' )->alias( static fn ( $email ) => (bool) filter_var( $email, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'absint' )->alias( static fn ( $v ) => abs( (int) $v ) );
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ) => $thing instanceof \WP_Error );

		// get_audience_id_by_name() reads the table through $wpdb; answer from the map.
		$this->audiences = array();
		$audiences       = &$this->audiences;
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( $sql, $table, $name ) => $name );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( $name ) use ( &$audiences ) {
				return isset( $audiences[ $name ] ) ? (string) $audiences[ $name ] : null;
			}
		);

		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_table_name' )->andReturn( 'wp_ffc_audiences' );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->temp_dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->temp_dir );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function csv( string $content ): string {
		$path = $this->temp_dir . '/' . uniqid() . '.csv';
		file_put_contents( $path, $content );
		return $path;
	}

	// ==================================================================
	// import_members() — the audience per row
	// ==================================================================

	public function test_members_take_the_audience_from_the_id_column_and_skip_blank_rows(): void {
		$path = $this->csv( "email,audience_id\n,\na@example.com,7\nb@example.com,0\n" );
		Mockery::mock( 'alias:FreeFormCertificate\UserDashboard\UserCreator' )
			->shouldReceive( 'resolve_existing_user' )->andReturn( 31 );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' )
			->shouldReceive( 'add_member' )->once()->with( 7, 31 )->andReturn( true );

		$result = AudienceCsvImporter::import_members( $path );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( array( 'Row 4: Could not determine audience.' ), $result['errors'], 'The blank row 2 is neither imported nor reported.' );
	}

	public function test_members_resolve_the_audience_by_name(): void {
		$this->audiences = array( 'Teachers' => 12 );
		$path            = $this->csv( "email,audience\na@example.com,Teachers\nb@example.com,Nobody\n" );
		Mockery::mock( 'alias:FreeFormCertificate\UserDashboard\UserCreator' )
			->shouldReceive( 'resolve_existing_user' )->andReturn( 31 );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' )
			->shouldReceive( 'add_member' )->once()->with( 12, 31 )->andReturn( true );

		$result = AudienceCsvImporter::import_members( $path );

		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( array( 'Row 3: Could not determine audience.' ), $result['errors'] );
	}

	public function test_members_report_a_user_the_creator_refuses(): void {
		$path    = $this->csv( "email,name\nnew@example.com,New\n" );
		$creator = Mockery::mock( 'alias:FreeFormCertificate\UserDashboard\UserCreator' );
		$creator->shouldReceive( 'resolve_existing_user' )->andReturn( 0 );
		$creator->shouldReceive( 'get_or_create_user_dual' )->andReturn( new \WP_Error( 'existing_login', 'Login taken.' ) );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' )->shouldNotReceive( 'add_member' );

		$result = AudienceCsvImporter::import_members( $path, 5, true );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( array( 'Row 2: Could not create user: Login taken.' ), $result['errors'] );
	}

	// ==================================================================
	// import_audiences()
	// ==================================================================

	public function test_audiences_from_an_empty_file_are_refused(): void {
		$result = AudienceCsvImporter::import_audiences( $this->csv( '' ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'Empty file or invalid CSV format.' ), $result['errors'] );
	}

	public function test_audiences_are_created_level_by_level(): void {
		$this->audiences = array( 'Existing Child' => 3 );
		$path            = $this->csv(
			"name,color,parent_name\n"
			. "Grandchild,#111111,Child\n"
			. ",,\n"
			. "Child,#222222,Root\n"
			. "Root,#333333,\n"
			. "Existing Child,#444444,Root\n"
			. "Broken Child,#555555,Root\n"
			. "Orphan,#666666,Missing Parent\n"
		);
		$audiences = &$this->audiences;
		$created   = array();
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' )
			->shouldReceive( 'create' )->andReturnUsing(
				static function ( $data ) use ( &$audiences, &$created ) {
					if ( 'Broken Child' === $data['name'] ) {
						return false;
					}
					$id                         = 100 + count( $created );
					$created[]                  = array( $data['name'], $data['parent_id'] );
					$audiences[ $data['name'] ] = $id;
					return $id;
				}
			);

		$result = AudienceCsvImporter::import_audiences( $path );

		$this->assertTrue( $result['success'] );
		$this->assertSame(
			array( array( 'Root', null ), array( 'Child', 100 ), array( 'Grandchild', 101 ) ),
			$created,
			'Roots first, then each level once its parent exists.'
		);
		$this->assertSame( 3, $result['imported'] );
		$this->assertSame( 3, $result['skipped'], 'The existing child, the failed write and the orphan.' );
		$this->assertSame(
			array(
				'Row 7: Could not create audience.',
				'Row 8: Parent audience "Missing Parent" not found.',
			),
			$result['errors']
		);
	}

	public function test_a_root_audience_whose_write_fails_is_reported(): void {
		$path = $this->csv( "name\nLonely\n" );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' )
			->shouldReceive( 'create' )->once()->andReturn( 0 );

		$result = AudienceCsvImporter::import_audiences( $path );

		$this->assertSame( array( 'Row 2: Could not create audience.' ), $result['errors'] );
		$this->assertSame( 1, $result['skipped'] );
	}
}
