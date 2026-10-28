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
 * double that answers the audience list and the per-audience field counts
 * (#2127).
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

	/** @var array<int, array{int, int}> Audience id => [active count, total count]. */
	private array $counts = array();

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
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin.php' );
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		$this->audiences        = array();
		$this->counts           = array();
		$this->audience_queries = array();

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->bound = is_array( $args[0] ?? null ) ? $args[0] : $args;
				if ( str_contains( (string) $sql, 'ORDER BY' ) ) {
					$this->audience_queries[] = $this->bound;
				}
				return (string) $sql;
			}
		);
		// AudienceReader::get_all() lists the audiences.
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( fn() => $this->audiences );
		// CustomFieldReader::count_by_audience() counts one audience's fields.
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$id = (int) $this->bound[1];
				[ $active, $total ] = $this->counts[ $id ] ?? array( 0, 0 );
				return (string) ( str_contains( (string) $sql, 'is_active = 1' ) ? $active : $total );
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

	public function test_render_shows_audiences_with_field_counts(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->audiences = array(
			(object) array( 'id' => '1', 'name' => 'Teachers', 'color' => '#ff0000', 'parent_id' => null ),
			(object) array( 'id' => '2', 'name' => 'Math Teachers', 'color' => '#00ff00', 'parent_id' => '1' ),
		);
		$this->counts    = array( 1 => array( 3, 5 ) );

		ob_start();
		ReregistrationCustomFieldsPage::render();
		$output = ob_get_clean();

		$this->assertListedActiveAudiences();
		$this->assertStringContainsString( 'Teachers', $output );
		$this->assertStringContainsString( 'Math Teachers', $output );
		$this->assertStringContainsString( '3', $output ); // active count
		$this->assertStringContainsString( '5', $output ); // total count
		$this->assertStringContainsString( 'Edit Fields', $output );
	}
}
