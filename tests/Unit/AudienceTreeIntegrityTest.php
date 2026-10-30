<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceReader;
use FreeFormCertificate\Audience\AudienceWriter;

/**
 * The audience tree keeps three levels and no cycles on every write (#2243).
 *
 * The tree used throughout: 1 → 2 → 4, 1 → 3; 5 alone; 6 → 7. Rows 8 and 9
 * point at each other, the shape a cycle stored before the writer refused
 * one has. AudienceReader is the real one, over a `$wpdb` double that answers
 * a row by id and the children of a parent.
 *
 * @covers \FreeFormCertificate\Audience\AudienceReader
 * @covers \FreeFormCertificate\Audience\AudienceWriter
 */
class AudienceTreeIntegrityTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $wpdb;

	/** @var array<int, object> Audience rows by id. */
	private array $rows = array();

	/** @var array<int, mixed> Arguments of the last prepare(). */
	private array $bound = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		foreach ( array( 1 => null, 2 => 1, 3 => 1, 4 => 2, 5 => null, 6 => null, 7 => 6, 8 => 9, 9 => 8 ) as $id => $parent ) {
			$this->rows[ $id ] = (object) array(
				'id'        => (string) $id,
				'name'      => 'G' . $id,
				'parent_id' => null === $parent ? null : (string) $parent,
			);
		}

		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->bound = is_array( $args[0] ?? null ) ? $args[0] : $args;
				return $sql;
			}
		);
		$this->wpdb->shouldReceive( 'get_row' )->andReturnUsing( fn() => $this->rows[ (int) $this->bound[1] ] ?? null );
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function () {
				$parent = (int) $this->bound[1];
				return array_values( array_filter( $this->rows, static fn( $row ) => (int) $row->parent_id === $parent ) );
			}
		);

		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->wpdb;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_top_level_audience_or_a_free_level_is_accepted(): void {
		$this->assertNull( AudienceReader::parent_refusal( 0, null ) );
		$this->assertNull( AudienceReader::parent_refusal( 0, 2 ) );
		$this->assertNull( AudienceReader::parent_refusal( 6, 5 ) );
	}

	public function test_a_fourth_level_is_refused(): void {
		$this->assertStringContainsString( 'at most 3 levels', (string) AudienceReader::parent_refusal( 0, 4 ) );
	}

	public function test_a_subtree_that_would_reach_a_fourth_level_is_refused(): void {
		// 6 has a child, so under 2 (level 2) its child would sit on level 4.
		$this->assertStringContainsString( 'at most 3 levels', (string) AudienceReader::parent_refusal( 6, 2 ) );
	}

	public function test_an_audience_cannot_be_its_own_parent_or_sit_under_its_descendant(): void {
		$this->assertStringContainsString( 'under itself', (string) AudienceReader::parent_refusal( 2, 2 ) );
		$this->assertStringContainsString( 'under itself', (string) AudienceReader::parent_refusal( 1, 4 ) );
	}

	public function test_a_missing_parent_is_refused(): void {
		$this->assertStringContainsString( 'does not exist', (string) AudienceReader::parent_refusal( 0, 99 ) );
	}

	public function test_the_walks_stop_on_a_stored_cycle(): void {
		$this->assertSame( array( 8, 9 ), AudienceReader::get_ancestor_ids( 8 ) );
		$this->assertSame( array( 9 ), AudienceReader::get_descendant_ids( 8 ) );
		$this->assertSame( 1, AudienceReader::subtree_height( 8 ) );
		$this->assertCount( 1, AudienceReader::get_ancestors( 8 ) );
	}

	public function test_the_walks_read_the_whole_tree(): void {
		$this->assertSame( array( 4, 2, 1 ), AudienceReader::get_ancestor_ids( 4 ) );
		$this->assertSame( array( 2, 3, 4 ), AudienceReader::get_descendant_ids( 1 ) );
		$this->assertSame( 2, AudienceReader::subtree_height( 1 ) );
		$this->assertSame( 2, AudienceReader::depth( 4 ) );
	}

	public function test_the_writer_refuses_a_move_that_closes_a_cycle(): void {
		$this->wpdb->shouldNotReceive( 'update' );

		$this->assertFalse( AudienceWriter::update( 1, array( 'parent_id' => 4 ) ) );
	}

	public function test_the_writer_refuses_a_new_audience_on_a_fourth_level(): void {
		$this->wpdb->shouldNotReceive( 'insert' );

		$this->assertFalse( AudienceWriter::create( array( 'name' => 'Deep', 'parent_id' => 4, 'allow_self_join' => 0 ) ) );
	}
}
