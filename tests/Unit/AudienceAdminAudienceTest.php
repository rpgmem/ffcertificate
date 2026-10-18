<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAdminAudience;

/**
 * @covers \FreeFormCertificate\Audience\AudienceAdminAudience
 * @covers \FreeFormCertificate\Audience\AudienceAdminAudienceRenderer
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceAdminAudienceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface Alias mock of Core\PiiAccessPolicy. */
	private $policy;

	/** @var mixed The stored self-join limit, null for none (#1916). */
	private $self_join_limit = null;

	/** @var bool What the policy answers for a listed account (#1824). */
	private $can_reveal = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// handle_actions() acts only on its own screen (#1771).
		$_GET['page'] = 'ffc-scheduling-audiences';

		// pcov does not record lines for files first autoloaded mid-test-method,
		// so the extracted renderer's coverage would attribute to nothing.
		// Preload the class here so pcov attributes its lines to this test.
		class_exists( '\\FreeFormCertificate\\Audience\\AudienceAdminAudienceRenderer' );

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
		Functions\when( 'FreeFormCertificate\Core\sanitize_text_field' )->returnArg();
		Functions\when( 'FreeFormCertificate\Core\wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
			return array_merge( $defaults, (array) $args );
		} );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		// The self-join description names the configured limit (#1916); every
		// other option reads its default.
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => ( 'ffc_aud_max_self_join_groups' === $key && null !== $this->self_join_limit ) ? $this->self_join_limit : $default
		);
		// The editor renders its Members tab (#1761).
		Functions\when( 'wp_nonce_url' )->justReturn( '/remove' );
		Functions\when( 'get_user_by' )->alias(
			static fn( $field, $id ) => (object) array( 'ID' => $id, 'display_name' => 'User' . $id, 'user_email' => $id . '@e.com' )
		);

		// The account email is masked (#1824). Whether a row gets a Reveal
		// button is the policy's answer, set per test through
		// $this->can_reveal; the tiers themselves are AudienceAccountEmailTest's.
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'reveal-nonce' );
		$this->policy = Mockery::mock( 'alias:FreeFormCertificate\Core\PiiAccessPolicy' );
		$this->policy->shouldReceive( 'can_reveal' )->andReturnUsing( fn() => $this->can_reveal )->byDefault();
		// The members table's "Reveal this page" asks the same question (#1863).
		$this->policy->shouldReceive( 'can_reveal_any' )->andReturnUsing( fn() => $this->can_reveal )->byDefault();

		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return func_get_arg(0); } )->byDefault();
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
		$wpdb->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
		$wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
	}

	protected function tearDown(): void {
		unset( $_GET['action'], $_GET['id'], $_GET['message'], $_GET['page'] );
		Monkey\tearDown();
		parent::tearDown();
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

		$child  = (object) array(
			'id'       => 2,
			'name'     => 'Child Aud',
			'color'    => '#00ff00',
			'status'   => 'inactive',
			'children' => array(),
		);
		$parent = (object) array(
			'id'       => 1,
			'name'     => 'Parent Aud',
			'color'    => '#ff0000',
			'status'   => 'active',
			'children' => array( $child ),
		);

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$repo_w = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldReceive( 'get_hierarchical' )->andReturn( array( $parent ) );
		$repo->shouldReceive( 'get_member_count' )->andReturnUsing(
			static fn( $id, $recursive = false ) => $recursive ? 10 : 4
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
		$child  = (object) array( 'id' => 2, 'name' => 'Child Aud', 'color' => '#00ff00', 'status' => 'inactive', 'children' => array() );
		$parent = (object) array( 'id' => 1, 'name' => 'Parent Aud', 'color' => '#ff0000', 'status' => 'active', 'children' => array( $child ) );
		$repo   = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldReceive( 'get_hierarchical' )->andReturn( array( $parent ) );
		$repo->shouldReceive( 'get_member_count' )->andReturn( 1 );

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

		$audience = (object) array(
			'id'              => 5,
			'name'            => 'Grp',
			'color'           => '#123456',
			'status'          => 'active',
			'parent_id'       => null,
			'allow_self_join' => 0,
		);

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		// A parent with no children keeps the single switch (#1896).
		$repo->shouldReceive( 'descendants_self_join_state' )->andReturn( null )->byDefault();
		$repo_w = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldReceive( 'get_member_count' )->andReturn( 2 ); // The Members tab label (#1761).
		$repo->shouldReceive( 'get_possible_parents' )->andReturn( array() )->byDefault();
		$repo->shouldReceive( 'get_members' )->andReturn( array( 7, 8 ) )->byDefault();
		$repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $audience );
		$repo->shouldReceive( 'get_possible_parents' )->andReturn( array() );
		$repo->shouldReceive( 'get_children' )->with( 5 )->andReturn( array() );

		// Real CustomFieldRepository (for FIELD_TYPES const) returns no fields
		// via the mocked $wpdb (get_results -> []).
		$seeder = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' );
		$seeder->shouldReceive( 'seed_for_audience' )->andReturn( 0 );
		$seeder->shouldReceive( 'get_group_labels' )->andReturn( array() );

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
		unset( $_GET['action'], $_GET['id'] );
	}

	public function test_custom_fields_section_read_only_without_manage(): void {
		// view cap present, manage cap absent -> read-only render path.
		Functions\when( 'current_user_can' )->alias(
			static fn( $cap ) => 'ffc_view_custom_fields' === $cap
		);

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$repo_w = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldReceive( 'get_children' )->with( 5 )->andReturn( array() );

		$seeder = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' );
		$seeder->shouldReceive( 'seed_for_audience' )->andReturn( 0 );
		$seeder->shouldReceive( 'get_group_labels' )->andReturn( array() );

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

		$audience = (object) array(
			'id'              => 5,
			'name'            => 'Sub Group',
			'color'           => '#123456',
			'status'          => 'active',
			'parent_id'       => 1,
			'allow_self_join' => 1,
		);

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$repo_w = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldReceive( 'get_member_count' )->andReturn( 2 ); // The Members tab label (#1761).
		$repo->shouldReceive( 'get_possible_parents' )->andReturn( array() )->byDefault();
		$repo->shouldReceive( 'get_members' )->andReturn( array( 7, 8 ) )->byDefault();
		$repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $audience );
		$repo->shouldReceive( 'get_possible_parents' )->with( 5 )->andReturn(
			array( (object) array( 'id' => 1, 'name' => 'Top', 'depth' => 0 ) )
		);
		$repo->shouldReceive( 'get_ancestors' )->with( 5 )->andReturn(
			array( (object) array( 'id' => 1, 'name' => 'Top', 'color' => '#aaa' ) )
		);
		// The tree-access pair is read on the top-level audience (#1918).
		$repo->shouldReceive( 'get_ancestor_ids' )->with( 5 )->andReturn( array( 5, 1 ) );
		$repo->shouldReceive( 'get_by_id' )->with( 1 )->andReturn(
			(object) array( 'id' => 1, 'name' => 'Top', 'parent_id' => null, 'members_only' => '1', 'require_membership' => '1' )
		);
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
		unset( $_GET['action'], $_GET['id'] );
	}

	public function test_render_form_missing_audience_dies(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '99';
		Functions\when( 'wp_die' )->alias( static function ( $m ) { throw new \RuntimeException( (string) $m ); } );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$repo_w = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldReceive( 'get_by_id' )->with( 99 )->andReturn( null );

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
			unset( $_GET['action'], $_GET['id'] );
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
		$seeder = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' );
		$seeder->shouldReceive( 'seed_for_audience' )->andReturn( 0 );
		$seeder->shouldReceive( 'get_group_labels' )->andReturn( array() );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		// A parent with no children keeps the single switch (#1896).
		$repo->shouldReceive( 'descendants_self_join_state' )->andReturn( null )->byDefault();
		$repo->shouldReceive( 'get_children' )->andReturn( array() );
		$repo_w = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldReceive( 'get_member_count' )->andReturn( 2 ); // The Members tab label (#1761).
		$repo->shouldReceive( 'get_possible_parents' )->andReturn( array() )->byDefault();
		$repo->shouldReceive( 'get_members' )->andReturn( array( 7, 8 ) )->byDefault();
		$repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( (object) array( 'id' => 5, 'name' => 'Grp' ) );
		$repo->shouldReceive( 'get_members' )->with( 5 )->andReturn( array( 7, 8 ) );

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
		unset( $_GET['action'], $_GET['id'] );
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
		$seeder = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' );
		$seeder->shouldReceive( 'seed_for_audience' )->andReturn( 0 );
		$seeder->shouldReceive( 'get_group_labels' )->andReturn( array() );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		// A parent with no children keeps the single switch (#1896).
		$repo->shouldReceive( 'descendants_self_join_state' )->andReturn( null )->byDefault();
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldReceive( 'get_children' )->andReturn( array() );
		$repo->shouldReceive( 'get_possible_parents' )->andReturn( array() )->byDefault();
		$repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( (object) array( 'id' => 5, 'name' => 'Grp' ) );
		$repo->shouldReceive( 'get_members' )->with( 5 )->andReturn( array( 7, 8, 9 ) );
		$repo->shouldReceive( 'get_member_count' )->with( 5 )->andReturn( 2 );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Members (2)', $output, 'the tab label' );
		$this->assertStringContainsString( 'Current Members (2)', $output, 'the card heading' );
		$this->assertStringNotContainsString( '(3)', $output, 'the orphan row is counted nowhere' );
		$this->assertSame( 2, substr_count( $output, '@e.com' ), 'the table renders the two users that exist' );
		unset( $_GET['action'], $_GET['id'] );
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
		$seeder = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' );
		$seeder->shouldReceive( 'seed_for_audience' )->andReturn( 0 );
		$seeder->shouldReceive( 'get_group_labels' )->andReturn( array() );
		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		// A parent with no children keeps the single switch (#1896).
		$repo->shouldReceive( 'descendants_self_join_state' )->andReturn( null )->byDefault();
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldReceive( 'get_by_id' )->andReturn( (object) array( 'id' => 5, 'name' => 'Grp', 'status' => 'active', 'parent_id' => null ) );
		$repo->shouldReceive( 'get_possible_parents' )->andReturn( array() );
		$repo->shouldReceive( 'get_children' )->andReturn( array() );
		$repo->shouldReceive( 'get_members' )->andReturn( array() );
		$repo->shouldReceive( 'get_member_count' )->andReturn( 0 );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();
		$_POST  = array();

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
		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		// A parent with no children keeps the single switch (#1896).
		$repo->shouldReceive( 'descendants_self_join_state' )->andReturn( null )->byDefault();
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldReceive( 'get_by_id' )->andReturn( (object) array( 'id' => 5, 'name' => 'Grp', 'status' => 'active', 'parent_id' => null ) );
		$repo->shouldReceive( 'get_possible_parents' )->andReturn( array() );
		$repo->shouldReceive( 'get_members' )->andReturn( array() );
		$repo->shouldReceive( 'get_member_count' )->andReturn( 0 );

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
		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldReceive( 'get_possible_parents' )->andReturn( array() );
		$repo->shouldNotReceive( 'get_member_count' );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		preg_match_all( '/<section id="ffc-tabpanel-([a-z-]+)"/', $output, $panels );
		$this->assertSame( array( 'audience' ), $panels[1] );
		$this->assertStringContainsString( 'Add New Audience', $output );
	}

	public function test_render_members_missing_audience_dies(): void {
		$_GET['action'] = 'members';
		$_GET['id']     = '99';
		Functions\when( 'wp_die' )->alias( static function ( $m ) { throw new \RuntimeException( (string) $m ); } );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$repo_w = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldReceive( 'get_by_id' )->with( 99 )->andReturn( null );

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
			unset( $_GET['action'], $_GET['id'] );
		}
	}

	// ==================================================================
	// handle_actions() — save
	// ==================================================================

	public function test_handle_actions_creates_audience_and_redirects(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_safe_redirect' )->alias( static function ( $u ) { throw new \RuntimeException( 'redirect:' . $u ); } );

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

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$repo_w = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo_w->shouldReceive( 'create' )->once()->with(
			Mockery::on(
				static fn( $d ) => 'Brand New' === $d['name'] && null === $d['parent_id'] && 1 === $d['allow_self_join']
			)
		)->andReturn( 50 );
		// A new audience has no children yet, so nothing cascades (#1896).
		$repo_w->shouldNotReceive( 'cascade_self_join' );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'message=created' );
		$page->handle_actions();
	}

	/**
	 * A new group under an audience is created without the posted switch, so
	 * the writer gives it the audience's choice for its descendants (#1901).
	 */
	public function test_a_new_child_audience_leaves_self_join_to_the_writer(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_safe_redirect' )->alias( static function ( $u ) { throw new \RuntimeException( 'redirect:' . $u ); } );

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

		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$repo_w = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo_w->shouldReceive( 'create' )->once()->with(
			Mockery::on( static fn( $d ) => 3 === $d['parent_id'] && ! array_key_exists( 'allow_self_join', $d ) )
		)->andReturn( 51 );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'message=created' );
		$page->handle_actions();
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

		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$repo_w = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo_w->shouldReceive( 'update' )->once()->with(
			5,
			Mockery::on( static fn( $d ) => null === $own ? ! array_key_exists( 'allow_self_join', $d ) : $own === $d['allow_self_join'] )
		)->andReturn( true );
		$repo_w->shouldNotReceive( 'cascade_self_join' );
		if ( null === $scope ) {
			$repo_w->shouldNotReceive( 'apply_self_join_scope' );
		} else {
			$repo_w->shouldReceive( 'apply_self_join_scope' )->once()->with( 5, $scope )->andReturn( true );
		}

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$page->handle_actions();
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

		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$repo_w = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo_w->shouldReceive( 'update' )->once()->andReturn( true );
		$repo_w->shouldReceive( 'set_tree_access' )->once()->with( 5, $members_only, $keep )->andReturn( true );

		( new AudienceAdminAudience( 'ffc-scheduling' ) )->handle_actions();
	}

	public function test_handle_actions_update_of_a_child_leaves_the_tree_access_alone(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'add_settings_error' )->justReturn( true );

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

		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$repo_w = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo_w->shouldReceive( 'update' )->once()->andReturn( true );
		$repo_w->shouldNotReceive( 'set_tree_access' );

		( new AudienceAdminAudience( 'ffc-scheduling' ) )->handle_actions();
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

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldReceive( 'get_member_count' )->andReturn( 0 );
		$repo->shouldReceive( 'get_possible_parents' )->andReturn( array() );
		$repo->shouldReceive( 'get_members' )->andReturn( array() );
		$repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn(
			(object) array( 'id' => 5, 'name' => 'Top', 'color' => '#123456', 'status' => 'active', 'parent_id' => null, 'allow_self_join' => '0', 'members_only' => $members_only, 'require_membership' => $keep )
		);
		$repo->shouldReceive( 'descendants_self_join_state' )->andReturn( null );

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

		$_POST = array(
			'ffc_action'         => 'save_audience',
			'ffc_audience_nonce' => 'n',
			'audience_id'        => '5',
			'audience_name'      => 'Child',
			'audience_color'     => '#000000',
			'audience_parent'    => '3',
			'audience_status'    => 'active',
		);

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$repo_w = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo_w->shouldReceive( 'update' )->once()->with( 5, Mockery::on( static fn( $d ) => 3 === $d['parent_id'] ) )->andReturn( true );
		$repo->shouldNotReceive( 'cascade_self_join' );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$page->handle_actions();
		$this->assertTrue( true );
	}

	public function test_handle_actions_save_aborts_on_bad_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$_POST = array( 'ffc_action' => 'save_audience', 'ffc_audience_nonce' => 'bad' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$repo_w = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldNotReceive( 'create' );
		$repo->shouldNotReceive( 'update' );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$page->handle_actions();
		$this->assertTrue( true );
	}

	// ==================================================================
	// handle_actions() — add members
	// ==================================================================

	public function test_handle_actions_adds_members(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'add_settings_error' )->justReturn( true );

		$_POST = array(
			'ffc_action'            => 'add_members',
			'ffc_add_members_nonce' => 'n',
			'audience_id'           => '5',
			'user_ids'              => '7,8,9',
		);

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$repo_w = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo_w->shouldReceive( 'bulk_add_members' )->once()->with( 5, array( 7, 8, 9 ) )->andReturn( 3 );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$page->handle_actions();
		$this->assertTrue( true );
	}

	public function test_handle_actions_add_members_bad_nonce_aborts(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$_POST = array( 'ffc_action' => 'add_members', 'ffc_add_members_nonce' => 'bad' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$repo_w = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldNotReceive( 'bulk_add_members' );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$page->handle_actions();
		$this->assertTrue( true );
	}

	// ==================================================================
	// handle_actions() — remove member
	// ==================================================================

	public function test_handle_actions_removes_member_and_redirects(): void {
		$_GET = array( 'page' => 'ffc-scheduling-audiences', 'remove_user' => '7', 'id' => '5', '_wpnonce' => 'n' );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_safe_redirect' )->alias( static function ( $u ) { throw new \RuntimeException( 'redirect:' . $u ); } );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$repo_w = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo_w->shouldReceive( 'remove_member' )->once()->with( 5, 7 )->andReturn( true );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'message=member_removed' );
		$page->handle_actions();
	}

	public function test_handle_actions_ignores_a_remove_member_request_on_another_screen(): void {
		// The coordinator runs this handler on every ffc-scheduling* screen;
		// `remove_user` was the one action it performed without checking
		// which screen it was on (#1771).
		$_GET = array( 'page' => 'ffc-scheduling-calendars', 'remove_user' => '7', 'id' => '5', '_wpnonce' => 'n' );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$repo_w = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo_w->shouldNotReceive( 'remove_member' );

		( new AudienceAdminAudience( 'ffc-scheduling' ) )->handle_actions();
		$this->addToAssertionCount( 1 );
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
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_safe_redirect' )->alias( static function ( $u ) { throw new \RuntimeException( 'redirect:' . $u ); } );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$repo_w = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo_w->shouldReceive( 'update' )->once()->with( 5, array( 'status' => 'inactive' ) )->andReturn( true );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'message=deactivated' );
		$page->handle_actions();
	}

	public function test_handle_actions_deletes_inactive_audience(): void {
		$_GET = array(
			'action'   => 'delete',
			'id'       => '5',
			'page'     => 'ffc-scheduling-audiences',
			'_wpnonce' => 'n',
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_safe_redirect' )->alias( static function ( $u ) { throw new \RuntimeException( 'redirect:' . $u ); } );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$repo_w = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( (object) array( 'status' => 'inactive' ) );
		$repo_w->shouldReceive( 'delete' )->once()->with( 5 )->andReturn( true );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'message=deleted' );
		$page->handle_actions();
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
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );

		$page = new AudienceAdminAudience( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'permission to delete' );
		$page->handle_actions();
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

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		// A parent with no children keeps the single switch (#1896).
		$repo->shouldReceive( 'descendants_self_join_state' )->andReturn( null )->byDefault();
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldReceive( 'get_member_count' )->andReturn( 2 ); // The Members tab label (#1761).
		$repo->shouldReceive( 'get_possible_parents' )->andReturn( array() )->byDefault();
		$repo->shouldReceive( 'get_members' )->andReturn( array( 7, 8 ) )->byDefault();
		$repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn(
			(object) array( 'id' => 5, 'name' => 'Grp', 'color' => '#123456', 'status' => 'active', 'parent_id' => null, 'allow_self_join' => 1 )
		);
		$repo->shouldReceive( 'get_possible_parents' )->andReturn( array() );

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

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldReceive( 'get_member_count' )->andReturn( 0 );
		$repo->shouldReceive( 'get_possible_parents' )->andReturn( array() );
		$repo->shouldReceive( 'get_members' )->andReturn( array() );
		$repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn(
			(object) array( 'id' => 5, 'name' => 'Grp', 'color' => '#123456', 'status' => 'active', 'parent_id' => null, 'allow_self_join' => '1' )
		);
		$repo->shouldReceive( 'descendants_self_join_state' )->with( 5 )->andReturn( null );

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
	 * @param string|null $state   What the reader derives for the descendants.
	 * @param string|null $checked The option checked, null for none.
	 */
	public function test_a_parent_renders_one_self_join_choice( string $own, ?string $state, ?string $checked ): void {
		$this->grant( array( 'ffc_view_audiences', 'ffc_manage_audiences' ) );
		$_GET['action'] = 'edit';
		$_GET['id']     = '5';
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldReceive( 'get_member_count' )->andReturn( 0 );
		$repo->shouldReceive( 'get_possible_parents' )->andReturn( array() );
		$repo->shouldReceive( 'get_members' )->andReturn( array() );
		$repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn(
			(object) array( 'id' => 5, 'name' => 'Grp', 'color' => '#123456', 'status' => 'active', 'parent_id' => null, 'allow_self_join' => $own )
		);
		$repo->shouldReceive( 'descendants_self_join_state' )->once()->with( 5 )->andReturn( $state );
		if ( 'mixed' === $state ) {
			$repo->shouldReceive( 'descendants_self_join_count' )->once()->with( 5 )->andReturn( array( 'allowed' => 3, 'total' => 7 ) );
		} else {
			$repo->shouldNotReceive( 'descendants_self_join_count' );
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

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		// A parent with no children keeps the single switch (#1896).
		$repo->shouldReceive( 'descendants_self_join_state' )->andReturn( null )->byDefault();
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldReceive( 'get_member_count' )->andReturn( 2 ); // The Members tab label (#1761).
		$repo->shouldReceive( 'get_possible_parents' )->andReturn( array() )->byDefault();
		$repo->shouldReceive( 'get_members' )->andReturn( array( 7, 8 ) )->byDefault();
		$repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( (object) array( 'id' => 5, 'name' => 'Grp' ) );
		$repo->shouldReceive( 'get_members' )->with( 5 )->andReturn( array( 7, 8 ) );

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
			'may reveal (unmasked or reveal tier)' => array( true ),
			'masked tier'                          => array( false ),
		);
	}

	/**
	 * The members table masks each account email for every tier and draws
	 * the audited Reveal button only for a viewer the policy lets reveal,
	 * asking with the appointments gate and the member as owner (#1824).
	 *
	 * @dataProvider reveal_tiers
	 *
	 * @param bool $can_reveal What the policy answers.
	 */
	public function test_members_table_masks_the_account_email_at_every_tier( bool $can_reveal ): void {
		$this->can_reveal = $can_reveal;
		$this->policy->shouldReceive( 'can_reveal' )
			->with( 'ffc_view_audiences_pii', Mockery::type( 'int' ) )
			->andReturnUsing( fn() => $this->can_reveal );
		$_GET['action'] = 'members';
		$_GET['id']     = '5';
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		$seeder = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' );
		$seeder->shouldReceive( 'seed_for_audience' )->andReturn( 0 );
		$seeder->shouldReceive( 'get_group_labels' )->andReturn( array() );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		// A parent with no children keeps the single switch (#1896).
		$repo->shouldReceive( 'descendants_self_join_state' )->andReturn( null )->byDefault();
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceWriter' );
		$repo->shouldReceive( 'get_children' )->andReturn( array() );
		$repo->shouldReceive( 'get_member_count' )->andReturn( 2 );
		$repo->shouldReceive( 'get_possible_parents' )->andReturn( array() )->byDefault();
		$repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( (object) array( 'id' => 5, 'name' => 'Grp' ) );
		$repo->shouldReceive( 'get_members' )->andReturn( array( 7, 8 ) );

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
	}
}
