<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormListColumns;

/**
 * Tests for FormListColumns: init() hook registration, the conditional
 * enqueue, and the batch submission-count loader, which runs the real
 * SubmissionRepository table lookup against a $wpdb double (#2091).
 *
 * @covers \FreeFormCertificate\Admin\FormListColumns
 * @preserveGlobalState disabled
 */
class FormListColumnsHooksTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var mixed $wpdb as it was before the test. */
	private $original_wpdb;

	/** @var bool Whether $wpdb was set before the test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
		$this->original_wpdb = $this->had_wpdb ? $GLOBALS['wpdb'] : null;
		// Shared scripts are enqueued through AssetRegistry, which registers
		// them first; false is WordPress's answer for a handle already
		// registered, so nothing is localized twice (#1931).
		Functions\when( 'wp_register_script' )->justReturn( false );

		class_exists( '\\FreeFormCertificate\\Admin\\FormListColumns' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();

		// Reset the static cache.
		$ref  = new \ReflectionClass( FormListColumns::class );
		$prop = $ref->getProperty( 'submission_counts_cache' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		// The count cache is static: leave none behind for the next class.
		$ref  = new \ReflectionClass( FormListColumns::class );
		$prop = $ref->getProperty( 'submission_counts_cache' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// init()
	// ------------------------------------------------------------------

	public function test_init_registers_all_hooks(): void {
		Filters\expectAdded( 'manage_ffc_form_posts_columns' )->once();
		Actions\expectAdded( 'manage_ffc_form_posts_custom_column' )->once();
		Filters\expectAdded( 'manage_edit-ffc_form_sortable_columns' )->once();
		Actions\expectAdded( 'pre_get_posts' )->once();
		Actions\expectAdded( 'admin_enqueue_scripts' )->once();
		Filters\expectAdded( 'wp_list_table_class_name' )->once()->with( array( FormListColumns::class, 'list_table_class' ), 10, 2 );

		FormListColumns::init();
	}

	// ------------------------------------------------------------------
	// enqueue_features_script()
	// ------------------------------------------------------------------

	public function test_enqueue_features_script_returns_early_off_edit_php(): void {
		$enqueued = array();
		Functions\when( 'wp_enqueue_script' )->alias( function ( $h ) use ( &$enqueued ) {
			$enqueued[] = $h;
		} );

		FormListColumns::enqueue_features_script( 'post.php' );

		$this->assertSame( array(), $enqueued );
	}

	public function test_enqueue_features_script_returns_early_for_other_post_type(): void {
		$enqueued = array();
		Functions\when( 'wp_enqueue_script' )->alias( function ( $h ) use ( &$enqueued ) {
			$enqueued[] = $h;
		} );
		Functions\when( 'get_current_screen' )->justReturn(
			(object) array( 'post_type' => 'post' )
		);

		FormListColumns::enqueue_features_script( 'edit.php' );

		$this->assertSame( array(), $enqueued );
	}

	public function test_enqueue_features_script_returns_early_with_no_screen(): void {
		$enqueued = array();
		Functions\when( 'wp_enqueue_script' )->alias( function ( $h ) use ( &$enqueued ) {
			$enqueued[] = $h;
		} );
		Functions\when( 'get_current_screen' )->justReturn( null );

		FormListColumns::enqueue_features_script( 'edit.php' );

		$this->assertSame( array(), $enqueued );
	}

	public function test_enqueue_features_script_loads_on_ffc_form_list_screen(): void {
		$enqueued = array();
		Functions\when( 'get_current_screen' )->justReturn(
			(object) array( 'post_type' => 'ffc_form' )
		);
		Functions\when( 'wp_enqueue_script' )->alias( function ( $h ) use ( &$enqueued ) {
			$enqueued[] = $h;
		} );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		// false: ffc-core is already registered, as the admin hook leaves it,
		// so its configuration is not localized here (#1931).
		Functions\when( 'wp_register_script' )->justReturn( false );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );

		FormListColumns::enqueue_features_script( 'edit.php' );

		$this->assertContains( 'ffc-core', $enqueued );
		$this->assertContains( 'ffc-form-list-features', $enqueued );
		$this->assertContains( 'ffc-copy-shortcode', $enqueued );
	}

	// ------------------------------------------------------------------
	// get_submission_count() => load_submission_counts() (DB batch path)
	// ------------------------------------------------------------------

	/**
	 * A $wpdb double for the count query: records the table it was asked
	 * for and answers get_results with the given rows.
	 *
	 * @param mixed                $rows   What get_results returns.
	 * @param array<int, string>   $tables Receives each table the query names.
	 * @return \Mockery\MockInterface
	 */
	private function counts_wpdb( $rows, array &$tables ) {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) use ( &$tables ) {
				$this->assertStringContainsString( 'SELECT form_id, COUNT(*) AS cnt FROM %i', $sql );
				$tables[] = $args[0];
				return $sql;
			}
		);
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );
		$GLOBALS['wpdb'] = $wpdb;
		return $wpdb;
	}

	public function test_submission_count_batch_loads_from_db(): void {
		Functions\when( 'number_format_i18n' )->alias( function ( $n ) {
			return (string) $n;
		} );
		Functions\when( 'admin_url' )->alias( function ( $p ) {
			return 'https://example.test/wp-admin/' . $p;
		} );

		$tables = array();
		$this->counts_wpdb(
			array(
				array( 'form_id' => 42, 'cnt' => 3 ),
				array( 'form_id' => 7, 'cnt' => 11 ),
			),
			$tables
		);

		// First render triggers the single batch query.
		ob_start();
		FormListColumns::render_column( 'ffc_submissions', 42 );
		$out42 = ob_get_clean();

		// Second render hits the cache (get_results already used ->once()).
		ob_start();
		FormListColumns::render_column( 'ffc_submissions', 7 );
		$out7 = ob_get_clean();

		$this->assertStringContainsString( '<strong>3</strong>', $out42 );
		$this->assertStringContainsString( '<strong>11</strong>', $out7 );
		// The real repository names the submissions table under the prefix.
		$this->assertSame( array( 'wp_ffc_submissions' ), $tables );
	}

	public function test_submission_count_handles_empty_db_result(): void {
		Functions\when( 'number_format_i18n' )->alias( function ( $n ) {
			return (string) $n;
		} );

		$tables = array();
		$this->counts_wpdb( null, $tables );

		ob_start();
		FormListColumns::render_column( 'ffc_submissions', 42 );
		$out = ob_get_clean();

		// No rows => count 0 => empty marker.
		$this->assertStringContainsString( '&mdash;', $out );
		$this->assertSame( array( 'wp_ffc_submissions' ), $tables );
	}
}
