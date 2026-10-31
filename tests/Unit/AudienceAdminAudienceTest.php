<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAccountEmail;
use FreeFormCertificate\Audience\AudienceAdminAudience;
use FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder;

/**
 * The audience admin screen: the list, the three-tab editor and the actions
 * it handles.
 *
 * AudienceReader, AudienceWriter, the reregistration fields seeder and
 * reader, PiiAccessPolicy and the account-email renderer are the real
 * classes. The `$wpdb` double below holds the audiences, their members and
 * the reregistration fields in arrays a test sets, answers each query by its
 * SQL text and the values bound to it, records every write, and fails the
 * test on a query it does not know.
 *
 * @covers \FreeFormCertificate\Audience\AudienceAdminAudience
 * @covers \FreeFormCertificate\Audience\AudienceAdminAudienceRenderer
 */
class AudienceAdminAudienceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const AUDIENCES = 'wp_ffc_audiences';
	private const MEMBERS   = 'wp_ffc_audience_members';
	private const FIELDS    = 'wp_ffc_custom_fields';

	/** @var mixed The stored self-join limit, null for none (#1916). */
	private $self_join_limit = null;

	/** @var bool What user_can() answers for the audiences PII cap (#1824). */
	private $can_reveal = false;

	/** @var array<int, object> Audience rows by id, as the table holds them. */
	private array $audiences = array();

	/** @var array<int, list<int>> Member user ids by audience id. */
	private array $members = array();

	/** @var list<int> Audiences a booking names (#2243); empty means none is booked. */
	private array $booked_audiences = array();

	/** @var list<int> Member user ids whose WordPress account is gone. */
	private array $deleted_users = array();

	/** @var string|null What the lock check's submissions read answers (#2242). */
	private ?string $answered = null;

	/** @var list<array<int, mixed>> Every prepare() call, as its argument list. */
	private array $prepared = array();

	/** @var list<string> The SQL of every read, in order. */
	private array $ran = array();

	/** @var list<array<int, mixed>> Every write, as [operation, table, data, where] or ['query', bound...]. */
	private array $writes = array();

	/** @var int The id the next insert reports. */
	private int $next_insert_id = 1;

	/** @var list<array{0: int, 1: string}> Every user_can() call, as [user id, capability]. */
	private array $user_can_calls = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> $_GET as an earlier test left it. */
	private array $get_backup = array();

	/** @var array<string, mixed> $_POST as an earlier test left it. */
	private array $post_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->get_backup  = $_GET ?? array();
		$this->post_backup = $_POST ?? array();
		// handle_actions() acts only on its own screen (#1771).
		$_GET  = array( 'page' => 'ffc-scheduling-audiences' );
		$_POST = array();

		// pcov does not record lines for files first autoloaded mid-test-method,
		// so the extracted renderer's coverage would attribute to nothing.
		// Preload the class here so pcov attributes its lines to this test.
		class_exists( '\\FreeFormCertificate\\Audience\\AudienceAdminAudienceRenderer' );

		// The account-email renderer keeps the reveal nonce for the request;
		// an earlier test in this process may have filled it.
		$nonce = new \ReflectionProperty( AudienceAccountEmail::class, 'nonce' );
		$nonce->setAccessible( true );
		$nonce->setValue( null, null );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );
		// The reregistration field types are sorted by locale (LabelSorter).
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
			return array_merge( $defaults, (array) $args );
		} );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		// The self-join description names the configured limit (#1916); every
		// other option, the cache versions included, reads its default.
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => ( 'ffc_aud_max_self_join_groups' === $key && null !== $this->self_join_limit ) ? $this->self_join_limit : $default
		);
		// A member count keeps the members whose account still exists (#1795).
		Functions\when( 'get_users' )->alias(
			fn( $args ) => array_values( array_diff( array_map( 'intval', (array) ( $args['include'] ?? array() ) ), $this->deleted_users ) )
		);
		// add_member() grants the audience capabilities to an account it finds.
		Functions\when( 'get_userdata' )->justReturn( false );
		// The editor renders its Members tab (#1761).
		Functions\when( 'wp_nonce_url' )->justReturn( '/remove' );
		Functions\when( 'get_user_by' )->alias(
			static fn( $field, $id ) => (object) array( 'ID' => $id, 'display_name' => 'User' . $id, 'user_email' => $id . '@e.com' )
		);

		// The account email is masked (#1824). Whether a row gets a Reveal
		// button is the real policy's answer: the current user (1) is never the
		// member, is no site administrator, and holds the audiences PII cap
		// when $this->can_reveal says so.
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'reveal-nonce' );
		Functions\when( 'user_can' )->alias(
			function ( $user_id, $cap ) {
				$this->user_can_calls[] = array( (int) $user_id, (string) $cap );
				return 'manage_options' !== $cap && $this->can_reveal;
			}
		);

		$this->installDatabase();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$_GET  = $this->get_backup;
		$_POST = $this->post_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// The `$wpdb` double
	// ==================================================================

	/**
	 * Put the `$wpdb` double in place.
	 */
	private function installDatabase(): void {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( ...$args ) {
				$flat             = 2 === count( $args ) && is_array( $args[1] ) ? array_merge( array( $args[0] ), $args[1] ) : $args;
				$this->prepared[] = $flat;
				return 'Q' . ( count( $this->prepared ) - 1 ) . ':' . $flat[0];
			}
		);
		foreach ( array( 'get_row', 'get_results', 'get_col', 'get_var' ) as $method ) {
			$wpdb->shouldReceive( $method )->andReturnUsing(
				// Nobody answered a custom field here (#2242): the editor's lock
				// check finds no submission carrying its key.
				fn( $sql ) => 'get_var' === $method && false !== strpos( (string) $sql, 'SELECT 1 FROM %i WHERE data LIKE %s' ) ? $this->answered : $this->answer( $method, (string) $sql )
			);
		}
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $text ) => $text );
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( $wpdb ) {
				$this->writes[]  = array( 'insert', $table, $data );
				$wpdb->insert_id = $this->next_insert_id++;
				return 1;
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->writes[] = array( 'update', $table, $data, $where );
				return 1;
			}
		);
		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) {
				$this->writes[] = array( 'delete', $table, $where );
				return 1;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) {
				$this->writes[] = array_merge( array( 'query' ), $this->boundTo( (string) $sql ) );
				return 1;
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
	}

	/**
	 * The prepare() argument list behind a prepared query string.
	 *
	 * @return array<int, mixed>
	 */
	private function boundTo( string $sql ): array {
		if ( preg_match( '/^Q(\d+):/', $sql, $match ) ) {
			return $this->prepared[ (int) $match[1] ] ?? array();
		}
		return array( $sql );
	}

	/**
	 * Answer one read from the arrays the test set.
	 *
	 * @return mixed
	 */
	private function answer( string $method, string $sql ) {
		$bound       = $this->boundTo( $sql );
		$text        = (string) $bound[0];
		$table       = (string) ( $bound[1] ?? '' );
		$args        = array_slice( $bound, 2 );
		$this->ran[] = $text;

		if ( self::AUDIENCES === $table && 0 === strpos( $text, 'SELECT * FROM %i' ) && in_array( $method, array( 'get_row', 'get_results' ), true ) ) {
			$rows = $this->select( $this->audiences, $text, $args );
			return 'get_row' === $method ? ( $rows[0] ?? null ) : $rows;
		}
		if ( 'get_col' === $method && self::MEMBERS === $table && 0 === strpos( $text, 'SELECT DISTINCT user_id FROM %i WHERE audience_id IN (' ) ) {
			$users = array();
			foreach ( $args as $audience_id ) {
				$users = array_merge( $users, $this->members[ (int) $audience_id ] ?? array() );
			}
			return array_map( 'strval', array_values( array_unique( $users ) ) );
		}
		if ( 'get_var' === $method && self::MEMBERS === $table && 'SELECT COUNT(*) FROM %i WHERE audience_id = %d AND user_id = %d' === $text ) {
			return in_array( (int) $args[1], $this->members[ (int) $args[0] ] ?? array(), true ) ? '1' : '0';
		}
		if ( 'get_var' === $method && 'wp_ffc_audience_booking_audiences' === $table && 0 === strpos( $text, 'SELECT COUNT(DISTINCT booking_id) FROM %i WHERE audience_id IN (' ) ) {
			return (string) count( array_intersect( array_map( 'intval', $args ), $this->booked_audiences ) );
		}
		if ( 'get_col' === $method && self::FIELDS === $table && 'SELECT field_key FROM %i WHERE audience_id = %d' === $text ) {
			// Every standard field is already stored, so the seeder inserts none.
			return array_column( ReregistrationStandardFieldsSeeder::get_standard_fields_definition(), 'field_key' );
		}
		if ( 'get_results' === $method && self::FIELDS === $table && false !== strpos( $text, 'ORDER BY sort_order ASC, id ASC' ) ) {
			// The fields are listed without one being drawn: the rows are
			// render_custom_field_row()'s, tested on their own below.
			return array();
		}
		$this->fail( "Unexpected {$method}(): {$text} with " . var_export( $bound, true ) );
	}

	/**
	 * The rows matching a `SELECT * FROM %i [WHERE …] [ORDER BY name …]`.
	 *
	 * @param array<int, object>  $rows Stored rows.
	 * @param string              $sql  The query, placeholders unreplaced.
	 * @param array<int, mixed>   $args The values bound after the table.
	 * @return list<object>
	 */
	private function select( array $rows, string $sql, array $args ): array {
		preg_match_all( '/\b(\w+) = %[sd]/', $sql, $columns );
		$out = array();
		foreach ( $rows as $row ) {
			if ( false !== strpos( $sql, 'parent_id IS NULL' ) && ! empty( $row->parent_id ) ) {
				continue;
			}
			foreach ( $columns[1] as $i => $column ) {
				if ( (string) ( $row->$column ?? '' ) !== (string) $args[ $i ] ) {
					continue 2;
				}
			}
			$out[] = clone $row;
		}
		if ( false !== strpos( $sql, 'ORDER BY name' ) ) {
			usort( $out, static fn( $a, $b ) => strcmp( (string) $a->name, (string) $b->name ) );
		}
		return $out;
	}

	/**
	 * Store an audience row.
	 *
	 * @param array<string, mixed> $fields Columns that differ from a plain active top-level audience.
	 */
	private function audience( int $id, array $fields = array() ): void {
		$row = array_merge(
			array(
				'id'                 => (string) $id,
				'name'               => 'Audience ' . $id,
				'color'              => '#3788d8',
				'parent_id'          => null,
				'status'             => 'active',
				'allow_self_join'    => '0',
				'members_only'       => '0',
				'require_membership' => '0',
			),
			$fields
		);
		if ( null !== $row['parent_id'] ) {
			$row['parent_id'] = (string) $row['parent_id'];
		}
		$this->audiences[ $id ] = (object) $row;
	}

	/**
	 * How many reads ran a query containing $needle.
	 */
	private function ranWith( string $needle ): int {
		return count( array_filter( $this->ran, static fn( string $sql ): bool => false !== strpos( $sql, $needle ) ) );
	}

	/**
	 * The writes of one operation on one table, in order.
	 *
	 * @return list<array<int, mixed>>
	 */
	private function writesOf( string $operation, string $table ): array {
		return array_values(
			array_filter( $this->writes, static fn( array $w ): bool => $operation === $w[0] && $table === $w[1] )
		);
	}

	/**
	 * The self-join cascades the writer ran, as [value, child ids…].
	 *
	 * @return list<array<int, mixed>>
	 */
	private function cascades(): array {
		$out = array();
		foreach ( $this->writes as $write ) {
			if ( 'query' === $write[0] && false !== strpos( (string) $write[1], 'UPDATE %i SET allow_self_join = %d' ) ) {
				$out[] = array_slice( $write, 3 );
			}
		}
		return $out;
	}

	/**
	 * Run handle_actions() up to the redirect it ends in.
	 *
	 * @return string The redirect target.
	 */
	private function handleUntilRedirect(): string {
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_safe_redirect' )->alias( static function ( $u ) { throw new \RuntimeException( 'redirect:' . $u ); } );
		try {
			( new AudienceAdminAudience( 'ffc-scheduling' ) )->handle_actions();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		$this->fail( 'handle_actions() did not redirect.' );
	}

	// ==================================================================
	// Constructor
	// ==================================================================

	public function test_constructor_creates_instance(): void {
		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$this->assertInstanceOf( AudienceAdminAudience::class, $page );
	}

	// ==================================================================
	// handle_actions() — no permission
	// ==================================================================

	public function test_handle_actions_returns_early_without_permission(): void {
		// #1030: this asserted nothing, and with no action queued the method
		// would have done nothing whether or not the guard existed. Queue the
		// page's real save action so the capability is the only thing that can
		// stop it, then assert the nonce — which every acting branch verifies
		// immediately after matching the action — was never checked.
		Functions\when( 'current_user_can' )->justReturn( false );
		$_POST = array(
			'ffc_action'   => 'save_audience',
			'ffc_audience_nonce' => 'n',
		);

		Functions\expect( 'wp_verify_nonce' )->never();

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$page->handle_actions();
	}

	// ==================================================================
	// handle_actions() — with message
	// ==================================================================

	public function test_handle_actions_shows_feedback_message(): void {
		$_GET['message'] = 'created';
		$_GET['page'] = 'ffc-scheduling-audiences';

		// #1030: the name claims a message is shown and nothing checked it —
		// a handler that rendered no feedback at all would have passed.
		$notices = array();
		Functions\when( 'add_settings_error' )->alias(
			static function ( $setting, $code, $message ) use ( &$notices ) {
				$notices[] = $message;
				return true;
			}
		);

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$page->handle_actions();

		$this->assertNotEmpty( $notices, 'handle_actions() surfaced no feedback message' );
	}

	// ==================================================================
	// render_page() — default list
	// ==================================================================

	public function test_render_page_renders_list_by_default(): void {
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_url' )->justReturn( '/' );
		Functions\when( 'wp_trim_words' )->returnArg();

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wrap', $output );
		// The list keeps the plain list-table screen (#1629).
		$this->assertStringNotContainsString( 'ffc-boxed', $output );
	}

	// ==================================================================
	// render_list() — hierarchical rows (recursive)
	// ==================================================================

	public function test_render_list_renders_hierarchical_rows(): void {
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_url' )->justReturn( '/' );

		$this->audience( 1, array( 'name' => 'Parent Aud', 'color' => '#ff0000', 'status' => 'active' ) );
		$this->audience( 2, array( 'name' => 'Child Aud', 'color' => '#00ff00', 'status' => 'inactive', 'parent_id' => 1 ) );
		// Four direct members, ten counting the child's.
		$this->members = array(
			1 => array( 1, 2, 3, 4 ),
			2 => array( 5, 6, 7, 8, 9, 10 ),
		);

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Parent Aud', $output );
		$this->assertStringContainsString( 'Child Aud', $output );
		// Parent has children: total (10) shown alongside direct (4).
		$this->assertStringContainsString( '(10)', $output );
		// Active parent shows Deactivate; inactive child shows Delete.
		$this->assertStringContainsString( 'Deactivate', $output );
		$this->assertStringContainsString( 'Delete', $output );

		// Once a booking names the child, its row offers no Delete (#2243).
		$this->booked_audiences = array( 2 );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'Has bookings, kept', $output );
		$this->assertStringNotContainsString( 'permanently delete this audience', $output );
	}

	/**
	 * Row actions by tier (#1769): Edit / Deactivate need
	 * `ffc_manage_audiences`, Delete needs `ffc_delete_audiences`.
	 *
	 * @return array<string, array{0: list<string>, 1: bool, 2: bool}>
	 */
	public function row_action_tiers(): array {
		return array(
			'view-only tier'       => array( array( 'ffc_view_audiences' ), false, false ),
			'manage tier'          => array( array( 'ffc_view_audiences', 'ffc_manage_audiences' ), true, false ),
			'manage + delete tier' => array( array( 'ffc_view_audiences', 'ffc_manage_audiences', 'ffc_delete_audiences' ), true, true ),
		);
	}

	/**
	 * Grant exactly the given capabilities.
	 *
	 * @param list<string> $caps Capabilities held.
	 */
	private function grant( array $caps ): void {
		Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => in_array( $cap, $caps, true ) );
	}

	/**
	 * @dataProvider row_action_tiers
	 *
	 * @param list<string> $caps       Capabilities held.
	 * @param bool         $can_manage Whether Edit / Deactivate render.
	 * @param bool         $can_delete Whether Delete renders.
	 */
	public function test_row_actions_render_only_for_the_cap_their_handler_checks( array $caps, bool $can_manage, bool $can_delete ): void {
		$this->grant( $caps );
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_url' )->justReturn( '/' );
		$this->audience( 1, array( 'name' => 'Parent Aud', 'color' => '#ff0000', 'status' => 'active' ) );
		$this->audience( 2, array( 'name' => 'Child Aud', 'color' => '#00ff00', 'status' => 'inactive', 'parent_id' => 1 ) );
		$this->members = array(
			1 => array( 7 ),
			2 => array( 7 ),
		);

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		// Members stays for every tier: its screen opens on the menu's own
		// `ffc_view_audiences`.
		$this->assertSame( 2, substr_count( $output, 'Members</a>' ) );
		$this->assertSame( $can_manage ? 2 : 0, substr_count( $output, 'Edit</a>' ) );
		$this->assertSame( $can_manage, str_contains( $output, 'Deactivate' ) );
		$this->assertSame( $can_delete, str_contains( $output, 'Delete' ) );
	}

	// ==================================================================
	// render_form()
	// ==================================================================

	public function test_render_form_edit_renders_custom_fields_section(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '5';
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'current_user_can' )->justReturn( true ); // view + manage caps.

		// A parent with no children keeps the single switch (#1896).
		$this->audience( 5, array( 'name' => 'Grp', 'color' => '#123456' ) );
		$this->members = array( 5 => array( 7, 8 ) ); // The Members tab label (#1761).

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ffc-add-custom-field', $output );
		// A boxed screen of vertical tabs (#1761): the audience, its members
		// and its fields, each panel titled with its icon. The Members tab
		// keeps its two cards.
		$this->assertMatchesRegularExpression( '/<div class="wrap ffc-admin-page ffc-page-scheduling-audiences ffc-boxed">/', $output );
		preg_match_all( '/<section id="ffc-tabpanel-([a-z-]+)"/', $output, $panels );
		$this->assertSame( array( 'audience', 'members', 'fields' ), $panels[1] );
		$this->assertStringContainsString( '<h2 class="ffc-vtabs__panel-title"><span class="ffc-vtabs__icon ffc-icon-users" aria-hidden="true"></span>Audience</h2>', $output );
		$this->assertStringContainsString( '<h2 class="ffc-vtabs__panel-title"><span class="ffc-vtabs__icon ffc-icon-user-check" aria-hidden="true"></span>Members (2)</h2>', $output );
		$this->assertStringContainsString( '<span class="ffc-vtabs__label">Members (2)</span>', $output, 'the tab carries the count the list shows' );
		$this->assertStringContainsString( '<h2 class="ffc-vtabs__panel-title"><span class="ffc-vtabs__icon ffc-icon-clipboard" aria-hidden="true"></span>Reregistration fields</h2>', $output );
		$this->assertStringContainsString( '<section id="ffc-tabpanel-audience" class="ffc-vtabs__panel is-active"', $output, 'the edit URL opens Audience' );
		$this->assertSame( 2, substr_count( $output, '<div class="card">' ) );
		$this->assertSame( substr_count( $output, '<section' ), substr_count( $output, '</section>' ), 'every panel is closed' );
		$this->assertSame( substr_count( $output, '<div' ), substr_count( $output, '</div>' ), 'every box is closed' );
		// The seeder found every standard field stored and wrote nothing.
		$this->assertSame( array(), $this->writes );
	}

	public function test_custom_fields_section_read_only_without_manage(): void {
		// view cap present, manage cap absent -> read-only render path.
		Functions\when( 'current_user_can' )->alias(
			static fn( $cap ) => 'ffc_view_custom_fields' === $cap
		);
		$this->audience( 5 );

		ob_start();
		\FreeFormCertificate\Audience\AudienceAdminAudienceRenderer::render_custom_fields_section( 5 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Read-only', $output );
	}

	public function test_render_custom_field_row_custom_select_field(): void {
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );

		$field = (object) array(
			'id'                => 12,
			'field_label'       => 'My Select',
			'field_type'        => 'select',
			'field_key'         => 'my_select',
			'field_group'       => 'extra',
			'field_profile_key' => '',
			'field_mask'        => '',
			'field_source'      => 'custom',
			'is_sensitive'      => 0,
			'is_required'       => 1,
			'is_active'         => 1,
			'field_options'     => json_encode( array( 'choices' => array( 'A', 'B' ), 'help_text' => 'pick one' ) ),
			'validation_rules'  => json_encode( array( 'format' => 'email' ) ),
		);

		ob_start();
		\FreeFormCertificate\Audience\AudienceAdminAudienceRenderer::render_custom_field_row( $field, array( 'text', 'select', 'dependent_select' ), array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'My Select', $output );
		$this->assertStringContainsString( 'A', $output );
		// Custom field is deletable.
		$this->assertStringContainsString( 'ffc-field-delete', $output );
	}

	public function test_an_answered_custom_field_is_drawn_locked_and_not_deletable(): void {
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		$this->answered = '1';

		$field = (object) array(
			'id'                => 31,
			'field_label'       => 'Answered',
			'field_type'        => 'text',
			'field_key'         => 'answered_text',
			'field_group'       => '',
			'field_profile_key' => '',
			'field_mask'        => '',
			'field_source'      => 'custom',
			'is_sensitive'      => 0,
			'is_required'       => 0,
			'is_active'         => 1,
			'field_options'     => null,
			'validation_rules'  => null,
		);

		ob_start();
		\FreeFormCertificate\Audience\AudienceAdminAudienceRenderer::render_custom_field_row( $field, array( 'text' ), array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ffc-field-answer-lock', $output, 'The lock is explained (#2242).' );
		$this->assertMatchesRegularExpression( '/<select class="ffc-field-type" disabled>/', $output );
		$this->assertMatchesRegularExpression( '/class="ffc-field-key"[^>]*disabled/', $output );
		$this->assertMatchesRegularExpression( '/class="ffc-field-profile-key"[^>]*disabled/', $output );
		$this->assertStringNotContainsString( 'ffc-field-delete', $output, 'An answered field is deactivated, never deleted.' );
		$this->assertDoesNotMatchRegularExpression( '/<input type="text" class="ffc-field-label[^"]*"[^>]*disabled/', $output, 'The label stays editable.' );
	}

	public function test_render_custom_field_row_standard_locked_field(): void {
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );

		$field = (object) array(
			'id'                => 3,
			'field_label'       => 'CPF',
			'field_type'        => 'text',
			'field_key'         => 'cpf',
			'field_group'       => '',
			'field_profile_key' => 'cpf',
			'field_mask'        => 'cpf',
			'field_source'      => 'standard',
			'is_sensitive'      => 1,
			'is_required'       => 1,
			'is_active'         => 1,
			'field_options'     => null,
			'validation_rules'  => null,
		);

		ob_start();
		\FreeFormCertificate\Audience\AudienceAdminAudienceRenderer::render_custom_field_row( $field, array( 'text' ), array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'CPF', $output );
		$this->assertStringContainsString( 'disabled', $output );
		// Standard field has no delete button.
		$this->assertStringNotContainsString( 'ffc-field-delete', $output );
	}

	public function test_render_form_edit_with_breadcrumb_and_custom_fields(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '5';
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );

		// The tree-access pair is read on the top-level audience (#1918).
		$this->audience( 1, array( 'name' => 'Top', 'color' => '#aaa', 'members_only' => '1', 'require_membership' => '1' ) );
		$this->audience( 5, array( 'name' => 'Sub Group', 'color' => '#123456', 'parent_id' => 1, 'allow_self_join' => '1' ) );
		$this->members = array( 5 => array( 7, 8 ) ); // The Members tab label (#1761).
		// render_custom_fields_section: no view/manage cap -> early return.
		Functions\when( 'current_user_can' )->justReturn( false );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Sub Group', $output );
		$this->assertStringContainsString( 'ffc-breadcrumb', $output );
		$this->assertStringContainsString( 'Top', $output );
		$this->assertStringContainsString( 'Inherited from the top-level audience: visible to members only, and members keep at least one group.', $output );
		$this->assertStringNotContainsString( 'name="audience_members_only"', $output, 'a child shows the state read-only' );
		$this->assertStringNotContainsString( 'name="audience_tree_access"', $output );
	}

	public function test_render_form_missing_audience_dies(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '99';
		Functions\when( 'wp_die' )->alias( static function ( $m ) { throw new \RuntimeException( (string) $m ); } );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Audience not found' );
		// render_page() echoes the admin page wrapper before it detects the
		// missing record and calls wp_die(); capture and discard that output
		// so the markup doesn't leak into PHPUnit's stdout (the assertion is
		// on the wp_die exception, not the buffer).
		ob_start();
		try {
			$page->render_page();
		} finally {
			ob_end_clean();
		}
	}

	// ==================================================================
	// The Members tab (#1761)
	// ==================================================================

	public function test_render_members_lists_users(): void {
		$_GET['action'] = 'members';
		$_GET['id']     = '5';
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_nonce_url' )->justReturn( '/' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'get_user_by' )->alias(
			static fn( $field, $id ) => (object) array( 'ID' => $id, 'display_name' => 'User' . $id, 'user_email' => $id . '@e.com' )
		);

		// A parent with no children keeps the single switch (#1896).
		$this->audience( 5, array( 'name' => 'Grp' ) );
		$this->members = array( 5 => array( 7, 8 ) );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'User7', $output );
		// The email is masked (#1824).
		$this->assertStringContainsString( '8***@e.com', $output );
		$this->assertStringNotContainsString( '8@e.com', $output );
		$this->assertStringContainsString( 'ffc-boxed', $output );
		$this->assertStringContainsString( '<h2 class="ffc-icon-plus">', $output );
		$this->assertStringContainsString( '<h2 class="ffc-icon-users">', $output );
		// The old members URL opens the editor on its Members tab.
		$this->assertStringContainsString( '<section id="ffc-tabpanel-members" class="ffc-vtabs__panel is-active"', $output );
		$this->assertStringContainsString( 'id="ffc-tabnav-members" class="ffc-vtabs__tab is-active" role="tab" aria-controls="ffc-tabpanel-members" aria-selected="true"', $output );
		$this->assertStringContainsString( 'name="audience_name"', $output, 'the Audience tab is there too' );
	}

	public function test_members_tab_and_heading_count_only_the_rows_the_table_renders(): void {
		// User 9 was deleted but its member row survived: the table skips it,
		// and the tab label and the card heading both read the count the
		// audiences list shows, which skips it too (#1795).
		$_GET['action'] = 'members';
		$_GET['id']     = '5';
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_nonce_url' )->justReturn( '/' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'get_user_by' )->alias(
			static fn( $field, $id ) => 9 === $id ? false : (object) array( 'ID' => $id, 'display_name' => 'User' . $id, 'user_email' => $id . '@e.com' )
		);

		// A parent with no children keeps the single switch (#1896).
		$this->audience( 5, array( 'name' => 'Grp' ) );
		$this->members       = array( 5 => array( 7, 8, 9 ) );
		$this->deleted_users = array( 9 );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Members (2)', $output, 'the tab label' );
		$this->assertStringContainsString( 'Current Members (2)', $output, 'the card heading' );
		$this->assertStringNotContainsString( '(3)', $output, 'the orphan row is counted nowhere' );
		$this->assertSame( 2, substr_count( $output, '@e.com' ), 'the table renders the two users that exist' );
	}

	/**
	 * Which tab the editor opens: `?tab=` when it names a tab, the Members
	 * tab after a member is added or removed, else Audience.
	 *
	 * @return array<string, array{0: array<string, string>, 1: array<string, string>, 2: string}>
	 */
	public function opening_tabs(): array {
		return array(
			'plain edit URL'          => array( array( 'action' => 'edit' ), array(), 'audience' ),
			'?tab= names a tab'       => array( array( 'action' => 'edit', 'tab' => 'fields' ), array(), 'fields' ),
			'?tab= names no tab'      => array( array( 'action' => 'edit', 'tab' => 'nope' ), array(), 'audience' ),
			'members URL'             => array( array( 'action' => 'members' ), array(), 'members' ),
			'members URL, ?tab='      => array( array( 'action' => 'members', 'tab' => 'audience' ), array(), 'audience' ),
			'member removed redirect' => array( array( 'action' => 'members', 'message' => 'member_removed' ), array(), 'members' ),
			'add-members POST'        => array( array( 'action' => 'edit' ), array( 'ffc_action' => 'add_members' ), 'members' ),
			'save POST'               => array( array( 'action' => 'edit' ), array( 'ffc_action' => 'save_audience' ), 'audience' ),
		);
	}

	/**
	 * @dataProvider opening_tabs
	 *
	 * @param array<string, string> $get    Query arguments.
	 * @param array<string, string> $post   Posted fields.
	 * @param string                $opened Tab expected open.
	 */
	public function test_editor_opens_the_tab_the_request_names( array $get, array $post, string $opened ): void {
		$_GET  = array_merge( array( 'page' => 'ffc-scheduling-audiences', 'id' => '5' ), $get );
		$_POST = $post;
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		// A parent with no children keeps the single switch (#1896).
		$this->audience( 5, array( 'name' => 'Grp' ) );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		preg_match_all( '/<section id="ffc-tabpanel-([a-z-]+)" class="ffc-vtabs__panel is-active"/', $output, $open );
		$this->assertSame( array( $opened ), $open[1] );
		preg_match_all( '/aria-selected="true"/', $output, $selected );
		$this->assertCount( 1, $selected[0], 'one tab selected' );
	}

	public function test_fields_tab_follows_the_custom_fields_caps(): void {
		// View-only on audiences and nothing on custom fields: no fields tab,
		// as the section did not render before the tabs.
		$this->grant( array( 'ffc_view_audiences' ) );
		$_GET['action'] = 'edit';
		$_GET['id']     = '5';
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		// A parent with no children keeps the single switch (#1896).
		$this->audience( 5, array( 'name' => 'Grp' ) );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		preg_match_all( '/<section id="ffc-tabpanel-([a-z-]+)"/', $output, $panels );
		$this->assertSame( array( 'audience', 'members' ), $panels[1] );
		$this->assertStringNotContainsString( 'ffc-tab-fields', $output );
	}

	public function test_new_audience_has_only_the_audience_tab(): void {
		$_GET['action'] = 'new';
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		preg_match_all( '/<section id="ffc-tabpanel-([a-z-]+)"/', $output, $panels );
		$this->assertSame( array( 'audience' ), $panels[1] );
		$this->assertStringContainsString( 'Add New Audience', $output );
		$this->assertSame( 0, $this->ranWith( 'SELECT DISTINCT user_id' ), 'a new audience counts no members' );
	}

	public function test_render_members_missing_audience_dies(): void {
		$_GET['action'] = 'members';
		$_GET['id']     = '99';
		Functions\when( 'wp_die' )->alias( static function ( $m ) { throw new \RuntimeException( (string) $m ); } );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Audience not found' );
		// render_page() echoes the admin page wrapper before it detects the
		// missing record and calls wp_die(); capture and discard that output
		// so the markup doesn't leak into PHPUnit's stdout (the assertion is
		// on the wp_die exception, not the buffer).
		ob_start();
		try {
			$page->render_page();
		} finally {
			ob_end_clean();
		}
	}

	// ==================================================================
	// handle_actions() — save
	// ==================================================================

	public function test_handle_actions_creates_audience_and_redirects(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		$_POST = array(
			'ffc_action'         => 'save_audience',
			'ffc_audience_nonce' => 'n',
			'audience_id'        => '0',
			'audience_name'      => 'Brand New',
			'audience_color'     => '#abcabc',
			'audience_parent'    => '',
			'audience_status'    => 'active',
			'audience_self_join' => '1',
		);
		$this->next_insert_id = 50;

		$this->assertStringContainsString( 'id=50&message=created', $this->handleUntilRedirect() );

		$inserts = $this->writesOf( 'insert', self::AUDIENCES );
		$this->assertCount( 1, $inserts );
		$this->assertSame( 'Brand New', $inserts[0][2]['name'] );
		$this->assertNull( $inserts[0][2]['parent_id'] );
		$this->assertSame( 1, $inserts[0][2]['allow_self_join'] );
		// A new audience has no children yet, so nothing cascades (#1896).
		$this->assertSame( array(), $this->cascades() );
	}

	/**
	 * A new group under an audience is created without the posted switch, so
	 * the writer gives it the audience's choice for its descendants (#1901).
	 */
	public function test_a_new_child_audience_leaves_self_join_to_the_writer(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		// Audience 3 has no group below it yet, so its choice for children
		// reads as off — while the switch is posted on.
		$this->audience( 3, array( 'name' => 'Top', 'allow_self_join' => '1' ) );
		$_POST = array(
			'ffc_action'         => 'save_audience',
			'ffc_audience_nonce' => 'n',
			'audience_id'        => '0',
			'audience_name'      => 'New Child',
			'audience_color'     => '#abcabc',
			'audience_parent'    => '3',
			'audience_status'    => 'active',
			'audience_self_join' => '1',
		);
		$this->next_insert_id = 51;

		$this->assertStringContainsString( 'id=51&message=created', $this->handleUntilRedirect() );

		$inserts = $this->writesOf( 'insert', self::AUDIENCES );
		$this->assertCount( 1, $inserts );
		$this->assertSame( 3, $inserts[0][2]['parent_id'] );
		$this->assertSame( 0, $inserts[0][2]['allow_self_join'], 'the inherited choice, not the posted switch' );
	}

	/**
	 * @return iterable<string, array{0: array<string, string>, 1: int|null, 2: string|null}>
	 */
	public static function self_join_posts(): iterable {
		yield 'switch on, no children' => array( array( 'audience_self_join' => '1' ), 1, null );
		yield 'switch off, no children' => array( array(), 0, null );
		foreach ( array( 'off', 'self', 'all', 'descendants' ) as $scope ) {
			yield "scope {$scope}" => array( array( 'audience_self_join_scope' => $scope ), null, $scope );
		}
		yield 'scope offered, none picked' => array( array( 'audience_self_join_scope' => '' ), null, null );
	}

	/**
	 * The self-join field of a top-level audience (#1901): the switch writes
	 * the audience's own row; the four-option choice goes through the writer,
	 * which sets the audience and every descendant, and an empty choice (the
	 * mixed state left alone) touches neither the row's flag nor any other.
	 *
	 * @dataProvider self_join_posts
	 *
	 * @param array<string, string> $self_join The self-join fields posted.
	 * @param int|null              $own       The own flag in the update, null when absent.
	 * @param string|null           $scope     The choice applied, or null for none.
	 */
	public function test_handle_actions_update_applies_the_self_join_field( array $self_join, ?int $own, ?string $scope ): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'add_settings_error' )->justReturn( true );

		$this->audience( 5, array( 'name' => 'Edited' ) );
		if ( array() !== array_intersect_key( $self_join, array( 'audience_self_join_scope' => true ) ) ) {
			// The choice is offered because the audience has a group below it.
			$this->audience( 6, array( 'parent_id' => 5 ) );
		}
		$_POST = array_merge(
			array(
				'ffc_action'         => 'save_audience',
				'ffc_audience_nonce' => 'n',
				'audience_id'        => '5',
				'audience_name'      => 'Edited',
				'audience_color'     => '#000000',
				'audience_parent'    => '',
				'audience_status'    => 'active',
			),
			$self_join
		);

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$page->handle_actions();

		$updates = $this->writesOf( 'update', self::AUDIENCES );
		$this->assertSame( array( 'id' => 5 ), $updates[0][3] );
		if ( null === $own ) {
			$this->assertArrayNotHasKey( 'allow_self_join', $updates[0][2] );
		} else {
			$this->assertSame( $own, $updates[0][2]['allow_self_join'] );
		}
		if ( null === $scope ) {
			$this->assertCount( 1, $updates, 'no choice is applied' );
			$this->assertSame( array(), $this->cascades(), 'nothing cascades' );
			return;
		}
		$flags = array(
			'off'         => array( 0, 0 ),
			'self'        => array( 1, 0 ),
			'all'         => array( 1, 1 ),
			'descendants' => array( 0, 1 ),
		)[ $scope ];
		$this->assertCount( 2, $updates );
		$this->assertSame( array( 'allow_self_join' => $flags[0] ), $updates[1][2], 'the choice sets the audience' );
		$this->assertSame( array( 'id' => 5 ), $updates[1][3] );
		$this->assertSame( array( array( $flags[1], 6 ) ), $this->cascades(), 'and every group below it, once' );
	}

	/**
	 * @return iterable<string, array{0: array<string, string>, 1: bool, 2: bool}>
	 */
	public static function tree_access_posts(): iterable {
		yield 'both on'               => array( array( 'audience_members_only' => '1', 'audience_require_membership' => '1' ), true, true );
		yield 'members-only alone'    => array( array( 'audience_members_only' => '1' ), true, false );
		yield 'both off (unchecked)'  => array( array(), false, false );
	}

	/**
	 * The Save button writes the tree-access pair of a top-level audience
	 * through the writer, which stores keep-at-least-one off without
	 * members-only (#1918).
	 *
	 * @dataProvider tree_access_posts
	 *
	 * @param array<string, string> $posted       The toggles posted.
	 * @param bool                  $members_only Expected first flag.
	 * @param bool                  $keep         Expected second flag.
	 */
	public function test_handle_actions_update_saves_the_tree_access_pair( array $posted, bool $members_only, bool $keep ): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'add_settings_error' )->justReturn( true );

		$this->audience( 5, array( 'name' => 'Top' ) );
		$_POST = array_merge(
			array(
				'ffc_action'           => 'save_audience',
				'ffc_audience_nonce'   => 'n',
				'audience_id'          => '5',
				'audience_name'        => 'Top',
				'audience_color'       => '#000000',
				'audience_parent'      => '',
				'audience_status'      => 'active',
				'audience_tree_access' => '1',
			),
			$posted
		);

		( new AudienceAdminAudience( 'ffc-scheduling' ) )->handle_actions();

		$updates = $this->writesOf( 'update', self::AUDIENCES );
		$this->assertCount( 2, $updates, 'the save, then the pair' );
		$this->assertSame(
			array(
				'members_only'       => $members_only ? 1 : 0,
				'require_membership' => $keep ? 1 : 0,
			),
			$updates[1][2]
		);
		$this->assertSame( array( 'id' => 5 ), $updates[1][3] );
	}

	public function test_handle_actions_update_of_a_child_leaves_the_tree_access_alone(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'add_settings_error' )->justReturn( true );

		$this->audience( 3, array( 'name' => 'Top' ) );
		$this->audience( 5, array( 'name' => 'Child', 'parent_id' => 3 ) );
		$_POST = array(
			'ffc_action'            => 'save_audience',
			'ffc_audience_nonce'    => 'n',
			'audience_id'           => '5',
			'audience_name'         => 'Child',
			'audience_color'        => '#000000',
			'audience_parent'       => '3',
			'audience_status'       => 'active',
			'audience_tree_access'  => '1',
			'audience_members_only' => '1',
		);

		( new AudienceAdminAudience( 'ffc-scheduling' ) )->handle_actions();

		$updates = $this->writesOf( 'update', self::AUDIENCES );
		$this->assertCount( 1, $updates, 'only the save' );
		$this->assertArrayNotHasKey( 'members_only', $updates[0][2] );
	}

	/**
	 * A top-level audience draws both toggles with record autosave, the
	 * second disabled while the first is off (#1918).
	 *
	 * @dataProvider tree_access_render_states
	 *
	 * @param string $members_only Stored first flag.
	 * @param string $keep         Stored second flag.
	 */
	public function test_a_top_level_audience_renders_the_tree_access_toggles( string $members_only, string $keep ): void {
		$this->grant( array( 'ffc_view_audiences', 'ffc_manage_audiences' ) );
		$_GET['action'] = 'edit';
		$_GET['id']     = '5';
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );

		$this->audience( 5, array( 'name' => 'Top', 'color' => '#123456', 'members_only' => $members_only, 'require_membership' => $keep ) );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		$on = '1' === $members_only;
		$this->assertStringContainsString( '<input type="hidden" name="audience_tree_access" value="1">', $output );
		$this->assertMatchesRegularExpression( '/name="audience_members_only" value="1"' . ( $on ? ' checked' : '' ) . ' data-ffc-autosave-record-key="members_only" data-ffc-autosave-record="audience:5"/', $output );
		$this->assertMatchesRegularExpression( '/name="audience_require_membership" value="1"' . ( $on && '1' === $keep ? ' checked' : '' ) . ( $on ? '' : ' disabled' ) . ' data-ffc-autosave-record-key="require_membership"/', $output );
	}

	/**
	 * @return iterable<string, array{0: string, 1: string}>
	 */
	public static function tree_access_render_states(): iterable {
		yield 'both off'                  => array( '0', '0' );
		yield 'members-only alone'        => array( '1', '0' );
		yield 'both on'                   => array( '1', '1' );
		yield 'stale keep, members-only off' => array( '0', '1' );
	}

	public function test_handle_actions_update_with_parent_skips_cascade(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'add_settings_error' )->justReturn( true );

		$this->audience( 3, array( 'name' => 'Top' ) );
		$this->audience( 5, array( 'name' => 'Child', 'parent_id' => 3 ) );
		// A group below the edited one, which a cascade would reach.
		$this->audience( 6, array( 'name' => 'Grandchild', 'parent_id' => 5 ) );
		$_POST = array(
			'ffc_action'         => 'save_audience',
			'ffc_audience_nonce' => 'n',
			'audience_id'        => '5',
			'audience_name'      => 'Child',
			'audience_color'     => '#000000',
			'audience_parent'    => '3',
			'audience_status'    => 'active',
		);

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$page->handle_actions();

		$updates = $this->writesOf( 'update', self::AUDIENCES );
		$this->assertCount( 1, $updates );
		$this->assertSame( 3, $updates[0][2]['parent_id'] );
		$this->assertSame( array( 'id' => 5 ), $updates[0][3] );
		$this->assertSame( array(), $this->cascades() );
	}

	public function test_handle_actions_save_aborts_on_bad_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$_POST = array( 'ffc_action' => 'save_audience', 'ffc_audience_nonce' => 'bad' );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$page->handle_actions();

		$this->assertSame( array(), $this->writes, 'nothing is created or updated' );
	}

	// ==================================================================
	// handle_actions() — add members
	// ==================================================================

	public function test_handle_actions_adds_members(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$notices = array();
		Functions\when( 'add_settings_error' )->alias(
			static function ( $setting, $code, $message ) use ( &$notices ) {
				$notices[] = $message;
				return true;
			}
		);

		$_POST = array(
			'ffc_action'            => 'add_members',
			'ffc_add_members_nonce' => 'n',
			'audience_id'           => '5',
			'user_ids'              => '7,8,9',
		);

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$page->handle_actions();

		$this->assertSame(
			array(
				array( 'audience_id' => 5, 'user_id' => 7 ),
				array( 'audience_id' => 5, 'user_id' => 8 ),
				array( 'audience_id' => 5, 'user_id' => 9 ),
			),
			array_column( $this->writesOf( 'insert', self::MEMBERS ), 2 )
		);
		$this->assertSame( array( '3 member(s) added successfully.' ), $notices );
	}

	public function test_handle_actions_add_members_bad_nonce_aborts(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$_POST = array( 'ffc_action' => 'add_members', 'ffc_add_members_nonce' => 'bad' );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$page->handle_actions();

		$this->assertSame( array(), $this->writes, 'no member is added' );
	}

	// ==================================================================
	// handle_actions() — remove member
	// ==================================================================

	public function test_handle_actions_removes_member_and_redirects(): void {
		$_GET = array( 'page' => 'ffc-scheduling-audiences', 'remove_user' => '7', 'id' => '5', '_wpnonce' => 'n' );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		$this->assertStringContainsString( 'message=member_removed', $this->handleUntilRedirect() );
		$this->assertSame(
			array( array( 'delete', self::MEMBERS, array( 'audience_id' => 5, 'user_id' => 7 ) ) ),
			$this->writes
		);
	}

	public function test_handle_actions_ignores_a_remove_member_request_on_another_screen(): void {
		// The coordinator runs this handler on every ffc-scheduling* screen;
		// `remove_user` was the one action it performed without checking
		// which screen it was on (#1771).
		$_GET = array( 'page' => 'ffc-scheduling-calendars', 'remove_user' => '7', 'id' => '5', '_wpnonce' => 'n' );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		( new AudienceAdminAudience( 'ffc-scheduling' ) )->handle_actions();

		$this->assertSame( array(), $this->writes, 'no member is removed' );
	}

	// ==================================================================
	// handle_actions() — deactivate / delete
	// ==================================================================

	public function test_handle_actions_deactivates_audience(): void {
		$_GET = array(
			'action'   => 'deactivate',
			'id'       => '5',
			'page'     => 'ffc-scheduling-audiences',
			'_wpnonce' => 'n',
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$this->audience( 5 );

		$this->assertStringContainsString( 'message=deactivated', $this->handleUntilRedirect() );
		$this->assertSame(
			array( array( 'update', self::AUDIENCES, array( 'status' => 'inactive' ), array( 'id' => 5 ) ) ),
			$this->writes
		);
	}

	public function test_handle_actions_deletes_inactive_audience(): void {
		$_GET = array(
			'action'   => 'delete',
			'id'       => '5',
			'page'     => 'ffc-scheduling-audiences',
			'_wpnonce' => 'n',
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$this->audience( 5, array( 'status' => 'inactive' ) );

		$this->assertStringContainsString( 'message=deleted', $this->handleUntilRedirect() );
		$this->assertSame(
			array(
				array( 'delete', self::MEMBERS, array( 'audience_id' => 5 ) ),
				array( 'delete', self::AUDIENCES, array( 'id' => 5 ) ),
			),
			$this->writes
		);
	}

	/**
	 * Deleting an audience takes its groups and memberships, so the rosters
	 * of its bookings would shrink after the fact: one that bookings name, or
	 * whose group they name, stays inactive (#2243).
	 */
	public function test_an_audience_whose_tree_is_booked_is_not_deleted(): void {
		$_GET = array(
			'action'   => 'delete',
			'id'       => '5',
			'page'     => 'ffc-scheduling-audiences',
			'_wpnonce' => 'n',
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$this->audience( 5, array( 'status' => 'inactive' ) );
		$this->audience( 6, array( 'status' => 'inactive', 'parent_id' => '5' ) );
		$this->booked_audiences = array( 6 );

		$this->assertStringContainsString( 'message=has_bookings&bookings=1', $this->handleUntilRedirect() );
		$this->assertSame( array(), $this->writes );
	}

	public function test_handle_actions_delete_denied_without_cap(): void {
		$_GET = array(
			'action' => 'delete',
			'id'     => '5',
			'page'   => 'ffc-scheduling-audiences',
		);
		Functions\when( 'current_user_can' )->alias(
			static fn( $cap ) => 'ffc_manage_audiences' === $cap
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'wp_die' )->alias( static function ( $m ) { throw new \RuntimeException( (string) $m ); } );
		$this->audience( 5, array( 'status' => 'inactive' ) );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		try {
			$page->handle_actions();
			$this->fail( 'handle_actions() did not refuse.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'permission to delete', $e->getMessage() );
		}
		$this->assertSame( array(), $this->writes, 'nothing is deleted' );
	}

	/**
	 * Edit-screen tiers (#1777): view-only renders read-only, manage edits.
	 *
	 * @return array<string, array{0: list<string>, 1: bool}>
	 */
	public function edit_screen_tiers(): array {
		return array(
			'view-only tier' => array( array( 'ffc_view_audiences' ), false ),
			'manage tier'    => array( array( 'ffc_view_audiences', 'ffc_manage_audiences' ), true ),
		);
	}


	/**
	 * @dataProvider edit_screen_tiers
	 *
	 * @param list<string> $caps       Capabilities held.
	 * @param bool         $can_manage Whether the screen is editable.
	 */
	public function test_edit_form_renders_read_only_without_manage( array $caps, bool $can_manage ): void {
		$this->grant( $caps );
		$_GET['action'] = 'edit';
		$_GET['id']     = '5';
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );

		// A parent with no children keeps the single switch (#1896).
		$this->audience( 5, array( 'name' => 'Grp', 'color' => '#123456', 'allow_self_join' => '1' ) );
		$this->members = array( 5 => array( 7, 8 ) ); // The Members tab label (#1761).

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="Grp"', $output, 'every tier sees the record' );
		$this->assertSame( ! $can_manage, str_contains( $output, 'Read-only — you can view this page' ) );
		$this->assertSame( $can_manage ? 0 : 1, substr_count( $output, '<fieldset disabled class="ffc-readonly-lock">' ) );
		$this->assertSame( $can_manage ? 0 : 1, substr_count( $output, '</fieldset>' ) );
		$this->assertSame( $can_manage, str_contains( $output, 'name="submit" value="Update Audience">Update Audience</button>' ) );
		if ( ! $can_manage ) {
			$open  = (int) strpos( $output, '<fieldset disabled' );
			$close = (int) strpos( $output, '</fieldset>' );
			foreach ( array( 'name="audience_name"', 'name="audience_color"', 'name="audience_parent"', 'name="audience_status"', 'name="audience_self_join"' ) as $field ) {
				$at = (int) strpos( $output, $field );
				$this->assertTrue( $at > $open && $at < $close, $field . ' sits inside the lock' );
			}
		}
		$this->assertSame( substr_count( $output, '<div' ), substr_count( $output, '</div>' ), 'every box is closed' );
	}

	/**
	 * The switch's description states the configured self-join limit, not
	 * the number that used to be hard-coded (#1916).
	 */
	public function test_the_self_join_switch_names_the_configured_limit(): void {
		$this->self_join_limit = '7';
		$this->grant( array( 'ffc_view_audiences', 'ffc_manage_audiences' ) );
		$_GET['action'] = 'edit';
		$_GET['id']     = '5';
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );

		// No group below it, so the single switch renders.
		$this->audience( 5, array( 'name' => 'Grp', 'color' => '#123456', 'allow_self_join' => '1' ) );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Users can join up to 7 groups through self-join.', $output );
		$this->assertStringNotContainsString( 'up to 2', $output );
	}

	/**
	 * @return iterable<string, array{0: string, 1: string|null, 2: string|null}>
	 */
	public static function self_join_render_states(): iterable {
		yield 'no children: one switch' => array( '1', null, null );
		yield 'off everywhere' => array( '0', '0', 'off' );
		yield 'this audience only' => array( '1', '0', 'self' );
		yield 'audience and descendants' => array( '1', '1', 'all' );
		yield 'descendants only' => array( '0', '1', 'descendants' );
		yield 'descendants disagree, audience on' => array( '1', 'mixed', null );
		yield 'descendants disagree, audience off' => array( '0', 'mixed', null );
	}

	/**
	 * A top-level audience with children shows one four-option choice
	 * (#1901), read back from its own row and its descendants'; while they
	 * disagree no option is checked and a notice states the real state.
	 *
	 * @dataProvider self_join_render_states
	 *
	 * @param string      $own     The audience's own flag.
	 * @param string|null $state   What the descendants' rows add up to.
	 * @param string|null $checked The option checked, null for none.
	 */
	public function test_a_parent_renders_one_self_join_choice( string $own, ?string $state, ?string $checked ): void {
		$this->grant( array( 'ffc_view_audiences', 'ffc_manage_audiences' ) );
		$_GET['action'] = 'edit';
		$_GET['id']     = '5';
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );

		$this->audience( 5, array( 'name' => 'Grp', 'color' => '#123456', 'allow_self_join' => $own ) );
		if ( 'mixed' === $state ) {
			// Seven groups below it, three of which allow self-join: two
			// children and their grandchildren.
			$this->audience( 10, array( 'parent_id' => 5, 'allow_self_join' => '1' ) );
			$this->audience( 11, array( 'parent_id' => 5, 'allow_self_join' => '0' ) );
			$this->audience( 12, array( 'parent_id' => 10, 'allow_self_join' => '1' ) );
			$this->audience( 13, array( 'parent_id' => 10, 'allow_self_join' => '1' ) );
			$this->audience( 14, array( 'parent_id' => 11, 'allow_self_join' => '0' ) );
			$this->audience( 15, array( 'parent_id' => 11, 'allow_self_join' => '0' ) );
			$this->audience( 16, array( 'parent_id' => 11, 'allow_self_join' => '0' ) );
		} elseif ( null !== $state ) {
			// A child and a grandchild that agree.
			$this->audience( 10, array( 'parent_id' => 5, 'allow_self_join' => $state ) );
			$this->audience( 12, array( 'parent_id' => 10, 'allow_self_join' => $state ) );
		}

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		if ( null === $state ) {
			$this->assertMatchesRegularExpression( '/<input type="checkbox" id="audience_self_join" name="audience_self_join" value="1" checked[^>]*data-ffc-autosave-record-key="allow_self_join"/', $output );
			$this->assertStringNotContainsString( 'audience_self_join_scope', $output );
			return;
		}

		$this->assertStringNotContainsString( 'name="audience_self_join"', $output, 'the switch gives way to the choice' );
		$form_end = (int) strpos( $output, '</form>' );
		$this->assertLessThan( $form_end, (int) strpos( $output, 'name="audience_self_join_scope"' ), 'the Save button posts the choice too' );
		$this->assertStringContainsString( '<input type="hidden" name="audience_self_join_scope" value="">', $output, 'an unpicked choice still posts, empty' );
		foreach ( array( 'off', 'self', 'all', 'descendants' ) as $option ) {
			$this->assertMatchesRegularExpression(
				'/name="audience_self_join_scope" value="' . $option . '"' . ( $option === $checked ? ' checked' : '' ) . ' data-ffc-autosave-record-key="self_join_scope" data-ffc-autosave-record="audience:5"/',
				$output
			);
		}
		$this->assertSame( null === $checked ? 0 : 1, substr_count( $output, '" checked data-ffc-autosave-record-key="self_join_scope"' ) );

		if ( null === $checked ) {
			$this->assertStringContainsString( 'ffc-self-join-mixed', $output );
			$this->assertStringContainsString( '1' === $own ? 'This audience allows self-join; 3 of 7' : 'This audience does not allow self-join; 3 of 7', $output );
		} else {
			$this->assertStringNotContainsString( 'ffc-self-join-mixed', $output );
		}
	}

	/**
	 * @dataProvider edit_screen_tiers
	 *
	 * @param list<string> $caps       Capabilities held.
	 * @param bool         $can_manage Whether members can be added and removed.
	 */
	public function test_members_screen_offers_no_writes_without_manage( array $caps, bool $can_manage ): void {
		$this->grant( $caps );
		$_GET['action'] = 'members';
		$_GET['id']     = '5';
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_nonce_url' )->justReturn( '/remove' );
		Functions\when( 'get_user_by' )->alias(
			static fn( $field, $id ) => (object) array( 'ID' => $id, 'display_name' => 'User' . $id, 'user_email' => $id . '@e.com' )
		);

		// A parent with no children keeps the single switch (#1896).
		$this->audience( 5, array( 'name' => 'Grp' ) );
		$this->members = array( 5 => array( 7, 8 ) );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		// Every tier sees the members, with the email masked (#1824).
		$this->assertStringContainsString( 'User7', $output );
		$this->assertStringContainsString( '8***@e.com', $output );
		$this->assertStringNotContainsString( '8@e.com', $output );

		$this->assertSame( ! $can_manage, str_contains( $output, 'Read-only — you can view this page' ) );
		$this->assertSame( $can_manage, str_contains( $output, '<h2 class="ffc-icon-plus">' ), 'the Add Members box' );
		$this->assertSame( $can_manage, str_contains( $output, 'id="user_search"' ) );
		$this->assertSame( $can_manage, str_contains( $output, 'name="add_members" value="Add Selected Members">Add Selected Members</button>' ) );
		$this->assertSame( $can_manage ? 2 : 0, substr_count( $output, 'Remove this member?' ) );
		$this->assertSame( $can_manage, str_contains( $output, 'column-actions' ) );
		$this->assertSame( substr_count( $output, '<div' ), substr_count( $output, '</div>' ), 'every box is closed' );
	}

	/**
	 * @return array<string, array{0: bool}>
	 */
	public static function reveal_tiers(): array {
		return array(
			'may reveal (reveal tier)' => array( true ),
			'masked tier'              => array( false ),
		);
	}

	/**
	 * The members table masks each account email for every tier and draws
	 * the audited Reveal button only for a viewer the policy lets reveal,
	 * asking with the audiences PII cap (#1824).
	 *
	 * @dataProvider reveal_tiers
	 *
	 * @param bool $can_reveal Whether the viewer holds the audiences PII cap.
	 */
	public function test_members_table_masks_the_account_email_at_every_tier( bool $can_reveal ): void {
		$this->can_reveal = $can_reveal;
		$_GET['action'] = 'members';
		$_GET['id']     = '5';
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );

		// A parent with no children keeps the single switch (#1896).
		$this->audience( 5, array( 'name' => 'Grp' ) );
		$this->members = array( 5 => array( 7, 8 ) );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		foreach ( array( 7, 8 ) as $member ) {
			$this->assertStringNotContainsString( $member . '@e.com', $output, 'no tier receives the clear address' );
			$this->assertStringContainsString( '<span class="ffc-pii-value" data-field="email">' . $member . '***@e.com</span>', $output );
			$this->assertSame(
				$can_reveal,
				str_contains( $output, 'data-type="audience_account" data-field="email" data-submission-id="' . $member . '" data-nonce="reveal-nonce"' )
			);
		}
		$this->assertSame( $can_reveal ? 2 : 0, substr_count( $output, 'ffc-reveal-pii"' ) );
		// One "Reveal this page" over the table, for the reveal tier (#1863).
		$this->assertSame( $can_reveal ? 1 : 0, substr_count( $output, 'ffc-reveal-pii-page' ) );
		// The policy asked the audiences PII cap of the current user: once
		// per member row and once for the page.
		$asked = array_filter( $this->user_can_calls, static fn( array $call ): bool => 'ffc_view_audiences_pii' === $call[1] );
		$this->assertCount( 3, $asked );
		$this->assertSame( array( 1 ), array_values( array_unique( array_column( $asked, 0 ) ) ) );
	}
}
