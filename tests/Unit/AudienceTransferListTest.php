<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceTransferList;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The shared audience picker (#1648).
 *
 * @covers \FreeFormCertificate\Audience\AudienceTransferList
 */
class AudienceTransferListTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<string> Every SQL string the `$wpdb` double ran. */
	private array $ran = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$this->ran         = array();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Installs a `$wpdb` double holding one active audience (id 5) with two
	 * members, so the real AudienceReader answers the picker's two reads.
	 * Any other query fails the test.
	 */
	private function install_wpdb(): void {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				return array(
					'sql'  => $sql,
					'args' => $args,
				);
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $query, $output = OBJECT ) {
				$this->ran[] = $query['sql'];
				if ( str_starts_with( $query['sql'], 'SELECT * FROM %i' ) && 'wp_ffc_audiences' === $query['args'][0] ) {
					$this->assertSame( array( 'wp_ffc_audiences', 'active' ), $query['args'], 'The picker lists active audiences only.' );
					return array(
						(object) array(
							'id'        => '5',
							'name'      => 'A',
							'color'     => '#abc',
							'parent_id' => null,
							'status'    => 'active',
						),
					);
				}
				if ( 'SELECT audience_id, user_id FROM %i' === $query['sql'] && array( 'wp_ffc_audience_members' ) === $query['args'] ) {
					$this->assertSame( ARRAY_A, $output );
					return array(
						array(
							'audience_id' => '5',
							'user_id'     => '10',
						),
						array(
							'audience_id' => '5',
							'user_id'     => '11',
						),
					);
				}
				$this->fail( 'Unexpected query: ' . $query['sql'] );
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	public function test_flatten_keeps_three_levels_in_display_order(): void {
		$grandchild = (object) array( 'id' => '3', 'name' => 'Class 3A', 'color' => '#333', 'children' => array() );
		$child      = (object) array( 'id' => '2', 'name' => 'School', 'color' => '#222', 'children' => array( $grandchild ) );
		$root       = (object) array( 'id' => '1', 'name' => 'Region', 'color' => '#111', 'children' => array( $child ) );
		$other      = (object) array( 'id' => '4', 'name' => 'Other', 'children' => array() );

		$flat = AudienceTransferList::flatten( array( $root, $other, 'not a node' ) );

		$this->assertSame( array( 1, 2, 3, 4 ), array_column( $flat, 'id' ) );
		$this->assertSame( array( 0, 1, 2, 0 ), array_column( $flat, 'depth' ) );
		$this->assertSame( array( 0, 1, 2, 0 ), array_column( $flat, 'parent' ) );
		$this->assertSame( array( array( 2 ), array( 3 ), array(), array() ), array_column( $flat, 'children' ) );
		$this->assertSame( '#ccc', $flat[3]['color'], 'An audience without a colour gets the neutral one.' );
	}

	public function test_member_counts_reach_the_whole_subtree_counting_each_person_once(): void {
		$grandchild = (object) array( 'id' => '3', 'name' => 'Class 3A', 'children' => array() );
		$child      = (object) array( 'id' => '2', 'name' => 'School', 'children' => array( $grandchild ) );
		$root       = (object) array( 'id' => '1', 'name' => 'Region', 'children' => array( $child ) );
		$other      = (object) array( 'id' => '4', 'name' => 'Other', 'children' => array() );

		$flat = AudienceTransferList::with_member_counts(
			AudienceTransferList::flatten( array( $root, $other ) ),
			array(
				1 => array( 10 ),
				2 => array( 10, 20 ),
				3 => array( 20, 30 ),
			)
		);

		$this->assertSame( array( 3, 3, 2, 0 ), array_column( $flat, 'members' ), 'A person in a parent and a child is counted once; an empty audience reads 0.' );
		$this->assertSame( array( 1, 2, 3, 4 ), array_column( $flat, 'id' ), 'The order the picker shows is kept.' );
	}

	public function test_render_prints_the_data_the_script_reads(): void {
		Functions\when( 'esc_attr' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
		Functions\when( 'esc_html_e' )->alias( static fn( $s ) => print $s );
		Functions\when( 'esc_attr_e' )->alias( static fn( $s ) => print $s );
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		// Both members are live accounts.
		Functions\when( 'get_users' )->alias( static fn( $args ) => $args['include'] );
		$this->install_wpdb();

		ob_start();
		AudienceTransferList::render( array( '5', 5, '0' ), 'rule[audience_ids][]', false );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-field-name="rule[audience_ids][]"', $html );
		$this->assertStringContainsString( 'data-selected="[5]"', $html, 'Duplicates and non-ids are dropped.' );
		$this->assertStringContainsString( '&quot;id&quot;:5', $html );
		$this->assertStringContainsString( '&quot;members&quot;:2', $html, 'Each audience carries the people it reaches.' );
		$this->assertStringNotContainsString( 'data-required', $html, 'An optional picker lets the form submit empty.' );
		// The four moves are the family's icon-only buttons (#1904), each named
		// by its label and still carrying the hook the script binds.
		foreach ( array(
			'ffc-transfer-add'        => array( 'chevron-right', 'Add selected' ),
			'ffc-transfer-add-all'    => array( 'chevrons-right', 'Add all' ),
			'ffc-transfer-remove'     => array( 'chevron-left', 'Remove selected' ),
			'ffc-transfer-remove-all' => array( 'chevrons-left', 'Remove all' ),
		) as $hook => $expected ) {
			$this->assertStringContainsString(
				'<button type="button" class="ffc-btn ffc-btn-neutral ffc-btn-icon ffc-icon-' . $expected[0] . ' ' . $hook . '" aria-label="' . $expected[1] . '" title="' . $expected[1] . '"></button>',
				$html
			);
		}
		$this->assertStringNotContainsString( 'class="button', $html );

		ob_start();
		AudienceTransferList::render( array(), 'rereg_audience_ids[]', true );
		$required = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-required="1"', $required );
		// The message the field-message standard shows under an empty picker (#1844).
		$this->assertStringContainsString( 'data-required-message="Select at least one audience."', $required );

		$this->assertSame(
			array( 'SELECT * FROM %i WHERE status = %s ORDER BY name ASC ', 'SELECT audience_id, user_id FROM %i' ),
			array_values( array_unique( $this->ran ) )
		);
		$this->assertCount( 4, $this->ran, 'Each render reads the active tree and the members once.' );
	}

	public function test_enqueue_brings_the_script_and_its_sheet(): void {
		$handles = array();
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( $handle, $src, $deps ) use ( &$handles ) {
				$handles[] = array( 'style', $handle, $deps );
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle, $src, $deps ) use ( &$handles ) {
				$handles[] = array( 'script', $handle, $deps );
			}
		);

		AudienceTransferList::enqueue();

		$this->assertSame(
			array(
				array( 'style', 'ffc-audience-transfer-list', array( 'ffc-common' ) ),
				array( 'script', 'ffc-audience-transfer-list', array( 'jquery', 'ffc-core' ) ),
			),
			$handles
		);
	}
}
