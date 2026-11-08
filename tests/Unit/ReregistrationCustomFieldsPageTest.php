<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationCustomFieldsPage;

/**
 * Tests for ReregistrationCustomFieldsPage.
 *
 * AudienceReader and CustomFieldReader are the real ones, over a `$wpdb`
 * double that answers the audience list, the global field list and what
 * each audience keeps of its own (#2127, #2502).
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationCustomFieldsPage
 */
class ReregistrationCustomFieldsPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var list<object> Audience rows the audience list query answers. */
	private array $audiences = array();

	/** @var list<array<string, string>> Rows of the per-audience count query. */
	private array $counts = array();

	/** @var list<object> Rows of the global field list. */
	private array $global_fields = array();

	/** @var list<array<int, mixed>> Arguments of every audience list prepare(). */
	private array $audience_queries = array();

	/** @var array<int, mixed> Arguments of the last prepare(). */
	private array $bound = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( '_n' )->alias( static fn( $one, $many, $n ) => 1 === $n ? $one : $many );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );

		$this->audiences        = array();
		$this->counts           = array();
		$this->global_fields    = array();
		$this->audience_queries = array();

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->bound = is_array( $args[0] ?? null ) ? $args[0] : $args;
				if ( str_contains( (string) $sql, 'ORDER BY' ) && 'wp_ffc_audiences' === ( $this->bound[0] ?? '' ) ) {
					$this->audience_queries[] = $this->bound;
				}
				return (string) $sql;
			}
		);
		// CustomFieldReader::level_counts(), the global field list, and
		// AudienceReader::get_all() for everything else.
		// ReregistrationStandardFieldsSeeder::seed_global() finds every
		// standard key already in the global list, so it inserts nothing.
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			static fn() => array_column( \FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder::get_standard_fields_definition(), 'field_key' )
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				if ( str_contains( (string) $sql, 'GROUP BY audience_id' ) ) {
					return $this->counts;
				}
				if ( str_contains( (string) $sql, 'WHERE audience_id = %d' ) ) {
					return $this->global_fields;
				}
				return $this->audiences;
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
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
	 * Assert every audience list query asked for active audiences only.
	 */
	private function assertListedActiveAudiences(): void {
		$this->assertNotEmpty( $this->audience_queries, 'The page must list the audiences.' );
		foreach ( $this->audience_queries as $bound ) {
			$this->assertContains( 'active', $bound, 'The audience list must be filtered on the active status.' );
		}
	}

	// ==================================================================
	// render() — permission denied
	// ==================================================================

	public function test_render_dies_without_manage_options(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias( function ( $msg ) {
			throw new \RuntimeException( $msg );
		} );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Permission denied.' );
		ReregistrationCustomFieldsPage::render();
	}

	// ==================================================================
	// render() — empty audiences
	// ==================================================================

	public function test_render_shows_no_audiences_message(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		ob_start();
		ReregistrationCustomFieldsPage::render();
		$output = ob_get_clean();

		$this->assertListedActiveAudiences();
		$this->assertStringContainsString( 'Custom Fields', $output );
		$this->assertStringContainsString( 'No audiences found', $output );
	}

	// ==================================================================
	// render() — with audiences
	// ==================================================================

	public function test_render_lists_where_each_audience_changed_the_global_list(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->audiences = array(
			(object) array( 'id' => '1', 'name' => 'Teachers', 'color' => '#ff0000', 'parent_id' => null ),
			(object) array( 'id' => '2', 'name' => 'Math Teachers', 'color' => '#00ff00', 'parent_id' => '1' ),
		);
		$this->counts    = array(
			array( 'audience_id' => '1', 'changed' => '2', 'hidden' => '1', 'own' => '0' ),
			array( 'audience_id' => '2', 'changed' => '0', 'hidden' => '0', 'own' => '3' ),
		);

		ob_start();
		ReregistrationCustomFieldsPage::render();
		$output = ob_get_clean();

		$this->assertListedActiveAudiences();
		$this->assertStringContainsString( 'Global fields', $output );
		$this->assertStringContainsString( 'data-audience-id="0"', $output, 'The editor saves the global level.' );
		$this->assertStringContainsString( 'Teachers', $output );
		$this->assertStringContainsString( '2 changed · 1 hidden', $output );
		$this->assertStringContainsString( '3 own fields', $output );
		$this->assertStringContainsString( 'tab=fields&id=2', $output, 'Each name opens that audience\'s fields tab.' );
	}
}
