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
 * AudienceReader, AudienceWriter and UserCreator are the real classes. A
 * `$wpdb` double holds the audiences table and the members table in arrays,
 * answers the name lookup, the by-id and children reads and the membership
 * count by their SQL text, and records every insert; a name in
 * `$failing_names` makes its insert fail. The e-mail lookup and the account
 * creation stop at stubbed `get_user_by()` / `wp_create_user()`. A query
 * nothing here expects fails the test.
 *
 * @covers \FreeFormCertificate\Audience\AudienceCsvImporter
 */
class AudienceCsvImporterEdgesTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private string $temp_dir;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<int, array{id: int, name: string, parent_id: int|null, allow_self_join: int}> The audiences table, by id. */
	private array $audiences = array();

	/** @var list<string> Audience names whose insert fails. */
	private array $failing_names = array();

	/** @var list<array{0: string, 1: int|null}> Audiences inserted, as `[ name, parent_id ]`, in order. */
	private array $created = array();

	/** @var list<array{0: int, 1: int}> Memberships inserted, as `[ audience_id, user_id ]`. */
	private array $memberships = array();

	/** @var list<array<int, mixed>> Every prepare() call, as its argument list. */
	private array $prepared = array();

	/** @var int|null The account `get_user_by( 'email', … )` resolves to, or null for none. */
	private ?int $existing_user = null;

	/** @var list<string> E-mail addresses `wp_create_user()` was asked to create. */
	private array $create_attempts = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->temp_dir = sys_get_temp_dir() . '/ffc_csv_edges_' . uniqid();
		mkdir( $this->temp_dir, 0777, true );

		$this->audiences       = array();
		$this->failing_names   = array();
		$this->created         = array();
		$this->memberships     = array();
		$this->prepared        = array();
		$this->existing_user   = null;
		$this->create_attempts = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_email' )->alias( static fn ( $email ) => trim( strtolower( (string) $email ) ) );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'is_email' )->alias( static fn ( $email ) => (bool) filter_var( $email, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'absint' )->alias( static fn ( $v ) => abs( (int) $v ) );
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_parse_args' )->alias( static fn ( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'get_option' )->alias( static fn ( $name, $default = false ) => 'ffc_settings' === $name ? array() : $default );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'sanitize_user' )->alias( static fn ( $name ) => strtolower( (string) $name ) );
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'generated-password' );
		Functions\when( 'get_user_by' )->alias(
			function ( $field, $value ) {
				return 'email' === $field && null !== $this->existing_user ? (object) array( 'ID' => $this->existing_user ) : false;
			}
		);
		Functions\when( 'wp_create_user' )->alias(
			function ( $username, $password, $email ) {
				$this->create_attempts[] = (string) $email;
				return new \WP_Error( 'existing_login', 'Login taken.' );
			}
		);

		$this->wpdb             = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix     = 'wp_';
		$this->wpdb->insert_id  = 0;
		$this->wpdb->last_error = '';
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( ...$args ) {
				$flat             = 2 === count( $args ) && is_array( $args[1] ) ? array_merge( array( $args[0] ), $args[1] ) : $args;
				$this->prepared[] = $flat;
				return 'Q' . ( count( $this->prepared ) - 1 ) . ':' . $flat[0];
			}
		);
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$bound = $this->boundTo( (string) $sql );
				if ( false !== strpos( (string) $sql, 'SELECT id FROM %i WHERE name = %s' ) && 'wp_ffc_audiences' === $bound[1] ) {
					foreach ( $this->audiences as $row ) {
						if ( $row['name'] === $bound[2] ) {
							return (string) $row['id'];
						}
					}
					return null;
				}
				if ( false !== strpos( (string) $sql, 'WHERE audience_id = %d AND user_id = %d' ) && 'wp_ffc_audience_members' === $bound[1] ) {
					foreach ( $this->memberships as $membership ) {
						if ( array( (int) $bound[2], (int) $bound[3] ) === $membership ) {
							return '1';
						}
					}
					return '0';
				}
				$this->fail( 'Unexpected get_var(): ' . $sql );
			}
		);
		$this->wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				$bound = $this->boundTo( (string) $sql );
				if ( false !== strpos( (string) $sql, 'SELECT * FROM %i WHERE id = %d' ) && 'wp_ffc_audiences' === $bound[1] ) {
					$row = $this->audiences[ (int) $bound[2] ] ?? null;
					return null === $row ? null : (object) $row;
				}
				$this->fail( 'Unexpected get_row(): ' . $sql );
			}
		);
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$bound = $this->boundTo( (string) $sql );
				if ( false !== strpos( (string) $sql, 'parent_id = %d' ) && 'wp_ffc_audiences' === $bound[1] ) {
					$children = array();
					foreach ( $this->audiences as $row ) {
						if ( (int) $bound[2] === $row['parent_id'] ) {
							$children[] = (object) $row;
						}
					}
					return $children;
				}
				$this->fail( 'Unexpected get_results(): ' . $sql );
			}
		);
		$this->wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) {
				if ( 'wp_ffc_audiences' === $table ) {
					if ( in_array( $data['name'], $this->failing_names, true ) ) {
						return false;
					}
					$id                     = 100 + count( $this->created );
					$this->created[]        = array( $data['name'], $data['parent_id'] );
					$this->audiences[ $id ] = array(
						'id'              => $id,
						'name'            => $data['name'],
						'parent_id'       => null === $data['parent_id'] ? null : (int) $data['parent_id'],
						'allow_self_join' => (int) ( $data['allow_self_join'] ?? 0 ),
					);
					$this->wpdb->insert_id  = $id;
					return 1;
				}
				if ( 'wp_ffc_audience_members' === $table ) {
					$this->memberships[]   = array( (int) $data['audience_id'], (int) $data['user_id'] );
					$this->wpdb->insert_id = count( $this->memberships );
					return 1;
				}
				$this->fail( 'Unexpected insert() into ' . $table );
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->wpdb;
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->temp_dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->temp_dir );
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The prepare() argument list behind a prepared query string.
	 *
	 * @return array<int, mixed>
	 */
	private function boundTo( string $sql ): array {
		if ( 1 === preg_match( '/^Q(\d+):/', $sql, $m ) ) {
			return $this->prepared[ (int) $m[1] ];
		}
		return array( $sql );
	}

	/** Store an existing audience under a fixed id. */
	private function existingAudience( int $id, string $name, ?int $parent_id = null ): void {
		$this->audiences[ $id ] = array(
			'id'              => $id,
			'name'            => $name,
			'parent_id'       => $parent_id,
			'allow_self_join' => 0,
		);
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
		$path                = $this->csv( "email,audience_id\n,\na@example.com,7\nb@example.com,0\n" );
		$this->existing_user = 31;

		$result = AudienceCsvImporter::import_members( $path );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( array( 'Row 4: Could not determine audience.' ), $result['errors'], 'The blank row 2 is neither imported nor reported.' );
		$this->assertSame( array( array( 7, 31 ) ), $this->memberships );
	}

	public function test_members_resolve_the_audience_by_name(): void {
		$this->existingAudience( 12, 'Teachers' );
		$path                = $this->csv( "email,audience\na@example.com,Teachers\nb@example.com,Nobody\n" );
		$this->existing_user = 31;

		$result = AudienceCsvImporter::import_members( $path );

		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( array( 'Row 3: Could not determine audience.' ), $result['errors'] );
		$this->assertSame( array( array( 12, 31 ) ), $this->memberships );
	}

	public function test_members_report_a_user_the_creator_refuses(): void {
		$path = $this->csv( "email,name\nnew@example.com,New\n" );

		$result = AudienceCsvImporter::import_members( $path, 5, true );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( array( 'Row 2: Could not create user: Login taken.' ), $result['errors'] );
		$this->assertSame( array( 'new@example.com' ), $this->create_attempts, 'Nobody matched, so the creator was asked to create the account.' );
		$this->assertSame( array(), $this->memberships, 'A refused user is not added to the audience.' );
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
		$this->existingAudience( 3, 'Existing Child' );
		$this->failing_names = array( 'Broken Child' );
		$path                = $this->csv(
			"name,color,parent_name\n"
			. "Grandchild,#111111,Child\n"
			. ",,\n"
			. "Child,#222222,Root\n"
			. "Root,#333333,\n"
			. "Existing Child,#444444,Root\n"
			. "Broken Child,#555555,Root\n"
			. "Orphan,#666666,Missing Parent\n"
		);

		$result = AudienceCsvImporter::import_audiences( $path );

		$this->assertTrue( $result['success'] );
		$this->assertSame(
			array( array( 'Root', null ), array( 'Child', 100 ), array( 'Grandchild', 101 ) ),
			$this->created,
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
		$path                = $this->csv( "name\nLonely\n" );
		$this->failing_names = array( 'Lonely' );

		$result = AudienceCsvImporter::import_audiences( $path );

		$this->assertSame( array( 'Row 2: Could not create audience.' ), $result['errors'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( array(), $this->created );
	}
}
