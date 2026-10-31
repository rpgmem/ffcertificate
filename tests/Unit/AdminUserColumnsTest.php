<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminUserColumns;

/**
 * Tests for AdminUserColumns: custom user list columns for certificates,
 * appointments, and user actions (login-as-user link).
 *
 * Covers hook registration, column insertion, rendering (zero/non-zero counts,
 * unknown columns), style enqueueing, and batch-loading cache behaviour.
 *
 * Utils and AssetHelper are the real classes. The three sort tests declare a
 * minimal WP_User_Query, which would outlive them, so they run in their own
 * process (#2127).
 *
 * @covers \FreeFormCertificate\Admin\AdminUserColumns
 */
class AdminUserColumnsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Reset static caches via Reflection
		$this->resetStaticCaches();

		// Mock $wpdb
		global $wpdb;
		$this->wpdb_backup = $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () {
			return func_get_args()[0];
		} )->byDefault();

		// Common WP stubs
		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( function ( $single, $plural, $count ) {
			return $count === 1 ? $single : $plural;
		} );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce_123' );
		Functions\when( 'add_query_arg' )->alias( function ( $args, $url ) {
			return $url . '?' . http_build_query( $args );
		} );
		Functions\when( 'home_url' )->alias( function ( $path = '' ) {
			return 'https://example.com' . $path;
		} );
		Functions\when( 'get_option' )->justReturn( array() );
		/*
		 * The login-as control is gated on `ffc_view_as_user` (or admin) since
		 * 6.32.0. Default to allowed here so the column tests keep measuring the
		 * markup they were written for; the refusal has its own test below.
		 */
		Functions\when( 'current_user_can' )->justReturn( true );
		// The profile section prints its own labels; `esc_html_e` echoes.
		Functions\when( 'esc_html_e' )->alias(
			static function ( $text ) {
				echo $text;
			}
		);
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_backup;
		$this->resetStaticCaches();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Reset all static caches in AdminUserColumns via Reflection.
	 */
	private function resetStaticCaches(): void {
		$ref = new \ReflectionClass( AdminUserColumns::class );
		foreach ( array( 'appointments_table_exists', 'dashboard_url_cache', 'certificate_counts_cache', 'appointment_counts_cache', 'recruitment_table_exists', 'recruitment_counts_cache' ) as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( null, null );
		}
	}

	// ==================================================================
	// init()
	// ==================================================================

	public function test_init_registers_hooks(): void {
		Functions\expect( 'add_filter' )
			->once()
			->with( 'manage_users_columns', array( AdminUserColumns::class, 'add_custom_columns' ) );

		Functions\expect( 'add_filter' )
			->once()
			->with( 'manage_users_custom_column', array( AdminUserColumns::class, 'render_custom_column' ), 10, 3 );

		Functions\expect( 'add_filter' )
			->once()
			->with( 'manage_users_sortable_columns', array( AdminUserColumns::class, 'register_sortable_columns' ) );

		Functions\expect( 'add_action' )
			->once()
			->with( 'pre_user_query', array( AdminUserColumns::class, 'apply_sort_to_user_query' ) );

		Functions\expect( 'add_action' )
			->once()
			->with( 'admin_enqueue_scripts', array( AdminUserColumns::class, 'enqueue_styles' ) );

		AdminUserColumns::init();
	}

	// ==================================================================
	// add_custom_columns()
	// ==================================================================

	public function test_add_custom_columns_inserts_after_posts(): void {
		$columns = array(
			'cb'       => '<input type="checkbox" />',
			'username' => 'Username',
			'email'    => 'Email',
			'role'     => 'Role',
			'posts'    => 'Posts',
		);

		$result = AdminUserColumns::add_custom_columns( $columns );

		$keys = array_keys( $result );
		$posts_index = array_search( 'posts', $keys, true );
		$this->assertSame( 'ffc_certificates', $keys[ $posts_index + 1 ] );
		$this->assertSame( 'ffc_appointments', $keys[ $posts_index + 2 ] );
		$this->assertSame( 'ffc_notices', $keys[ $posts_index + 3 ] );
		$this->assertSame( 'ffc_user_actions', $keys[ $posts_index + 4 ] );
	}

	public function test_add_custom_columns_preserves_existing(): void {
		$columns = array(
			'cb'       => '<input type="checkbox" />',
			'username' => 'Username',
			'posts'    => 'Posts',
			'custom'   => 'Custom Column',
		);

		$result = AdminUserColumns::add_custom_columns( $columns );

		// All original columns must still be present
		$this->assertArrayHasKey( 'cb', $result );
		$this->assertArrayHasKey( 'username', $result );
		$this->assertArrayHasKey( 'posts', $result );
		$this->assertArrayHasKey( 'custom', $result );

		// New columns added
		$this->assertArrayHasKey( 'ffc_certificates', $result );
		$this->assertArrayHasKey( 'ffc_appointments', $result );
		$this->assertArrayHasKey( 'ffc_notices', $result );
		$this->assertArrayHasKey( 'ffc_user_actions', $result );

		// Total count: 4 original + 4 new
		$this->assertCount( 8, $result );
	}

	// ==================================================================
	// render_custom_column() — certificates
	// ==================================================================

	public function test_render_certificates_column_zero_count(): void {
		// Batch load returns no results
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( array() );

		$output = AdminUserColumns::render_custom_column( '', 'ffc_certificates', 42 );

		$this->assertStringContainsString( 'ffc-empty-value', $output );
		$this->assertStringContainsString( '—', $output );
	}

	public function test_render_certificates_column_with_count(): void {
		// Batch load returns count for user 42
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( array(
				array( 'user_id' => '42', 'cnt' => '5' ),
				array( 'user_id' => '99', 'cnt' => '2' ),
			) );

		$output = AdminUserColumns::render_custom_column( '', 'ffc_certificates', 42 );

		$this->assertStringContainsString( '<strong>5</strong>', $output );
		$this->assertStringContainsString( 'certificates', $output );
	}

	// ==================================================================
	// render_custom_column() — appointments
	// ==================================================================

	public function test_render_appointments_column_zero_count(): void {
		// Certificate batch load (will be triggered first if cache is null)
		$this->wpdb->shouldReceive( 'get_results' )
			->andReturn( array() )
			->byDefault();

		// table_exists check — return null (table does not exist)
		$this->wpdb->shouldReceive( 'get_var' )
			->andReturn( null )
			->byDefault();

		$output = AdminUserColumns::render_custom_column( '', 'ffc_appointments', 42 );

		$this->assertStringContainsString( 'ffc-empty-value', $output );
		$this->assertStringContainsString( '—', $output );
	}

	public function test_render_appointments_column_with_count(): void {
		$table = 'wp_ffc_self_scheduling_appointments';

		// table_exists returns the table name (table exists)
		$this->wpdb->shouldReceive( 'get_var' )
			->andReturn( $table )
			->byDefault();

		// Batch appointment counts — AppointmentRepository::countAllByUserGrouped
		// aliases the COUNT() column as `c` after the issue #340 centralization.
		$this->wpdb->shouldReceive( 'get_results' )
			->andReturn( array(
				array( 'user_id' => '42', 'c' => '3' ),
			) );

		$output = AdminUserColumns::render_custom_column( '', 'ffc_appointments', 42 );

		$this->assertStringContainsString( '<strong>3</strong>', $output );
		$this->assertStringContainsString( 'appointments', $output );
	}

	// ==================================================================
	// render_custom_column() — user actions
	// ==================================================================

	public function test_render_user_actions_column(): void {
		$output = AdminUserColumns::render_custom_column( '', 'ffc_user_actions', 42 );

		$this->assertStringContainsString( 'ffc-view-as-user', $output );
		$this->assertStringContainsString( 'button', $output );
		$this->assertStringContainsString( 'Login as User', $output );
		$this->assertStringContainsString( 'ffc_view_as_user=42', $output );
		$this->assertStringContainsString( 'test_nonce_123', $output );
	}

	/**
	 * Nothing is rendered for a viewer who cannot use it.
	 *
	 * The column used to print the button for anybody who can reach
	 * `users.php`, while `DashboardViewMode` refuses the link on click unless
	 * the viewer holds `ffc_view_as_user` or `manage_options`. A control that
	 * cannot work is a defect, not a hint, so the gate is now the same on both
	 * ends of the link.
	 */
	public function test_login_as_is_absent_without_the_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$output = AdminUserColumns::render_custom_column( '', 'ffc_user_actions', 42 );

		$this->assertSame( '', $output );
	}

	// ==================================================================
	// render_profile_login_as() — the same control on user-edit.php
	// ==================================================================

	public function test_profile_login_as_renders_the_same_control(): void {
		ob_start();
		AdminUserColumns::render_profile_login_as( new \WP_User( 42 ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ffc-view-as-user', $html );
		$this->assertStringContainsString( 'ffc_view_as_user=42', $html );
		// Same nonce name as the column's, because one builder makes both.
		$this->assertStringContainsString( 'test_nonce_123', $html );
	}

	public function test_profile_login_as_renders_nothing_without_the_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		ob_start();
		AdminUserColumns::render_profile_login_as( new \WP_User( 42 ) );

		$this->assertSame( '', (string) ob_get_clean() );
	}

	// ==================================================================
	// render_custom_column() — unknown column
	// ==================================================================

	public function test_render_unknown_column_returns_original(): void {
		$output = AdminUserColumns::render_custom_column( 'original_output', 'unknown_col', 42 );

		$this->assertSame( 'original_output', $output );
	}

	// ==================================================================
	// render_custom_column() — recruitment notices
	// ==================================================================

	public function test_render_notices_column_zero_count(): void {
		// table_exists check: classification table missing → zero
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();

		$output = AdminUserColumns::render_custom_column( '', 'ffc_notices', 42 );

		$this->assertStringContainsString( 'ffc-empty-value', $output );
	}

	public function test_render_notices_column_with_count(): void {
		// table_exists returns table name — table present
		$this->wpdb->shouldReceive( 'get_var' )
			->andReturn( 'wp_ffc_recruitment_classification' )
			->byDefault();

		// COUNT() alias is `c` in the new repository method (issue #340).
		$this->wpdb->shouldReceive( 'get_results' )
			->andReturn( array(
				array( 'user_id' => '42', 'c' => '4' ),
			) );

		$output = AdminUserColumns::render_custom_column( '', 'ffc_notices', 42 );

		$this->assertStringContainsString( '<strong>4</strong>', $output );
		$this->assertStringContainsString( 'notices', $output );
	}

	// ==================================================================
	// register_sortable_columns()
	// ==================================================================

	public function test_register_sortable_columns_adds_name_and_value_columns(): void {
		$sortable = array( 'username' => 'login', 'email' => 'email' );
		$result   = AdminUserColumns::register_sortable_columns( $sortable );

		$this->assertSame( 'display_name', $result['name'] );
		$this->assertSame( 'ffc_certificates', $result['ffc_certificates'] );
		$this->assertSame( 'ffc_appointments', $result['ffc_appointments'] );
		$this->assertSame( 'ffc_notices', $result['ffc_notices'] );
		// Pre-existing entries preserved.
		$this->assertSame( 'login', $result['username'] );
		$this->assertSame( 'email', $result['email'] );
	}

	// ==================================================================
	// apply_sort_to_user_query()
	// ==================================================================

	/**
	 * Declares core's user query class inside the calling test's own process.
	 *
	 * Called from a test, never at file scope: PHPUnit loads every test file
	 * in its parent process to build the suite, so a file-scope declaration
	 * gave the parent a `WP_User_Query` with no constructor arguments and no
	 * `get_total()`, which broke any test there that counts users (#2104).
	 * Each test that calls it runs in its own process, so each gets a fresh one.
	 */
	private function declare_user_query(): void {
		if ( ! class_exists( '\WP_User_Query', false ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a WordPress core class inside this test's own process.
			eval( 'class WP_User_Query { public $query_from = ""; public $query_where = ""; public $query_orderby = ""; public $query_vars = array(); }' );
		}
	}

	/**
	 * Its own process: it declares a minimal WP_User_Query.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_apply_sort_to_user_query_skips_unknown_orderby(): void {
		global $wpdb;
		$wpdb->users = 'wp_users';

		$this->declare_user_query();
		$query                = new \WP_User_Query();
		$query->query_vars    = array( 'orderby' => 'login', 'order' => 'ASC' );
		$query->query_from    = 'FROM wp_users';
		$query->query_orderby = 'ORDER BY user_login ASC';

		AdminUserColumns::apply_sort_to_user_query( $query );

		// Untouched: query_from + query_orderby unchanged for non-FFC orderby.
		$this->assertSame( 'FROM wp_users', $query->query_from );
		$this->assertSame( 'ORDER BY user_login ASC', $query->query_orderby );
	}

	/**
	 * Its own process: it declares a minimal WP_User_Query.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_apply_sort_to_user_query_mutates_query_when_sorting_by_certificates(): void {
		global $wpdb;
		$wpdb->users = 'wp_users';

		$this->declare_user_query();
		$query                = new \WP_User_Query();
		$query->query_vars    = array( 'orderby' => 'ffc_certificates', 'order' => 'DESC' );
		$query->query_from    = 'FROM wp_users';
		$query->query_orderby = '';

		AdminUserColumns::apply_sort_to_user_query( $query );

		$this->assertStringContainsString( 'LEFT JOIN', $query->query_from );
		$this->assertStringContainsString( 'ffc_cert_counts', $query->query_from );
		$this->assertStringContainsString( 'ORDER BY COALESCE', $query->query_orderby );
		$this->assertStringContainsString( 'DESC', $query->query_orderby );
	}

	/**
	 * Its own process: it declares a minimal WP_User_Query.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_apply_sort_to_user_query_mutates_query_when_sorting_by_notices(): void {
		global $wpdb;
		$wpdb->users = 'wp_users';

		// table_exists returns the table name → exists
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_recruitment_classification' )->byDefault();

		$this->declare_user_query();
		$query                = new \WP_User_Query();
		$query->query_vars    = array( 'orderby' => 'ffc_notices', 'order' => 'ASC' );
		$query->query_from    = 'FROM wp_users';
		$query->query_orderby = '';

		AdminUserColumns::apply_sort_to_user_query( $query );

		$this->assertStringContainsString( 'ffc_notice_counts', $query->query_from );
		$this->assertStringContainsString( 'COUNT(DISTINCT cl.notice_id)', $query->query_from );
		$this->assertStringContainsString( 'ASC', $query->query_orderby );
	}

	// ==================================================================
	// enqueue_styles()
	// ==================================================================

	public function test_enqueue_styles_on_users_page(): void {
		$enqueued = array();
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( ...$args ) use ( &$enqueued ) {
				$enqueued[ $args[0] ] = $args;
			}
		);

		AdminUserColumns::enqueue_styles( 'users.php' );

		// users.php is not an is_ffc_page(), so the palette is enqueued here
		// (#1126 B) and ffc-admin.css, which paints with var(--ffc-*), declares
		// it as a dependency.
		$this->assertSame( array( 'ffc-common', 'ffc-admin' ), array_keys( $enqueued ) );
		$this->assertStringContainsString( 'ffc-admin.min.css', $enqueued['ffc-admin'][1] );
		$this->assertSame( array( 'ffc-common' ), $enqueued['ffc-admin'][2] );
		$this->assertSame( FFC_VERSION, $enqueued['ffc-admin'][3] );
	}

	public function test_enqueue_styles_skips_other_pages(): void {
		Functions\expect( 'wp_enqueue_style' )->never();

		AdminUserColumns::enqueue_styles( 'edit.php' );
	}

	// ==================================================================
	// Batch loading — counts loaded once
	// ==================================================================

	public function test_batch_loads_counts_once(): void {
		// Certificate batch: get_results called exactly once
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( array(
				array( 'user_id' => '1', 'cnt' => '10' ),
				array( 'user_id' => '2', 'cnt' => '20' ),
			) );

		// First call triggers batch load
		$output1 = AdminUserColumns::render_custom_column( '', 'ffc_certificates', 1 );
		$this->assertStringContainsString( '<strong>10</strong>', $output1 );

		// Second call uses cache — no additional DB query
		$output2 = AdminUserColumns::render_custom_column( '', 'ffc_certificates', 2 );
		$this->assertStringContainsString( '<strong>20</strong>', $output2 );

		// Third call for non-existent user returns zero (empty value)
		$output3 = AdminUserColumns::render_custom_column( '', 'ffc_certificates', 999 );
		$this->assertStringContainsString( 'ffc-empty-value', $output3 );
	}
}
