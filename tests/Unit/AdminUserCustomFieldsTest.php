<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminUserCustomFields;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Reregistration\CustomFieldReader;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for AdminUserCustomFields: hook registration, conditional asset loading,
 * save_section nonce/permission checks, and data persistence.
 *
 * The audience reader, the custom-field reader and writer, UserManager with
 * the profile service and repository, Encryption, the request reader and the
 * asset helper are the real classes. The `$wpdb` double below answers the
 * audience, custom-field and profile queries from the arrays a test sets and
 * fails on any other; user meta lives in an array behind get_user_meta() /
 * update_user_meta(), and every meta write is recorded, so a test asserts on
 * what was stored rather than on which method was called.
 *
 * @covers \FreeFormCertificate\Admin\AdminUserCustomFields
 */
class AdminUserCustomFieldsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const AUDIENCES = 'wp_ffc_audiences';
	private const MEMBERS   = 'wp_ffc_audience_members';
	private const FIELDS    = 'wp_ffc_custom_fields';
	private const PROFILES  = 'wp_ffc_user_profiles';

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> `$_POST` before this test. */
	private array $post_backup = array();

	/** @var list<array<int, mixed>> Every prepare() call, as its argument list. */
	private array $prepared = array();

	/**
	 * Audience rows by id.
	 *
	 * @var array<int, object>
	 */
	private array $audiences = array();

	/**
	 * Audience ids each user belongs to directly, by user id.
	 *
	 * @var array<int, list<int>>
	 */
	private array $memberships = array();

	/**
	 * Custom-field rows.
	 *
	 * @var list<object>
	 */
	private array $fields = array();

	/**
	 * User meta: user id => key => value.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $meta = array();

	/**
	 * Every user-meta read, as array( user id, key ).
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $meta_reads = array();

	/**
	 * Every user-meta write, as array( 'update'|'delete', user id, key, value ).
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $meta_writes = array();

	/**
	 * Rows inserted into ffc_user_profiles.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $profile_inserts = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Shared scripts are enqueued through AssetRegistry, which registers
		// them first; false is WordPress's answer for a handle already
		// registered, so nothing is localized twice (#1931).
		Functions\when( 'wp_register_script' )->justReturn( false );

		class_exists('\\FreeFormCertificate\\Admin\\AdminUserCustomFields');

		$this->post_backup = $_POST ?? array();
		$_POST             = array();

		// Common WP function stubs
		Functions\when('__')->returnArg();
		Functions\when('esc_html__')->returnArg();
		Functions\when('esc_html')->returnArg();
		Functions\when('esc_attr')->returnArg();
		Functions\when('wp_kses_post')->returnArg();
		Functions\when('absint')->alias(static fn($v) => abs((int) $v));
		Functions\when('sanitize_text_field')->returnArg();
		Functions\when('wp_unslash')->returnArg();
		Functions\when('sanitize_key')->alias(static fn($key) => preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)));
		Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
		Functions\when('get_option')->alias(static fn($name, $fallback = false) => $fallback);
		Functions\when('get_userdata')->justReturn(false);
		Functions\when('get_current_user_id')->justReturn(1);
		Functions\when('user_can')->justReturn(false);
		Functions\when('set_transient')->justReturn(true);
		Functions\when('wp_cache_get')->justReturn(false);
		Functions\when('wp_cache_set')->justReturn(true);
		Functions\when('wp_cache_delete')->justReturn(true);
		Functions\when('wp_cache_flush')->justReturn(true);

		$this->audiences       = array();
		$this->memberships     = array();
		$this->fields          = array();
		$this->meta            = array();
		$this->meta_reads      = array();
		$this->meta_writes     = array();
		$this->profile_inserts = array();
		$this->prepared        = array();

		Functions\when('get_user_meta')->alias(
			function ($user_id, $key = '', $single = false) {
				$this->meta_reads[] = array( (int) $user_id, $key );
				return $this->meta[ (int) $user_id ][ $key ] ?? '';
			}
		);
		Functions\when('update_user_meta')->alias(
			function ($user_id, $key, $value) {
				$this->meta_writes[]                   = array( 'update', (int) $user_id, $key, $value );
				$this->meta[ (int) $user_id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when('delete_user_meta')->alias(
			function ($user_id, $key) {
				$this->meta_writes[] = array( 'delete', (int) $user_id, $key, null );
				unset( $this->meta[ (int) $user_id ][ $key ] );
				return true;
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$this->wpdb        = $this->database();
		$GLOBALS['wpdb']   = $this->wpdb;
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$_POST = $this->post_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The `$wpdb` double: the audience, custom-field and profile statements
	 * the real classes run here, answered from this test's arrays.
	 *
	 * @return Mockery\MockInterface
	 */
	private function database() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( ...$args ) {
				$flat             = 2 === count( $args ) && is_array( $args[1] ) ? array_merge( array( $args[0] ), $args[1] ) : $args;
				$this->prepared[] = $flat;
				return 'Q' . ( count( $this->prepared ) - 1 ) . ':' . $flat[0];
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$bound = $this->bound( (string) $sql );
				if ( self::AUDIENCES === $bound[0] && false !== strpos( (string) $sql, 'SELECT a.* FROM %i a' ) ) {
					// AudienceReader::get_user_audiences(): the audiences the user belongs to directly.
					$rows = array();
					foreach ( $this->memberships[ (int) $bound[2] ] ?? array() as $id ) {
						$rows[] = clone $this->audiences[ $id ];
					}
					return $rows;
				}
				if ( self::FIELDS === $bound[0] && false !== strpos( (string) $sql, 'WHERE audience_id = %d' ) ) {
					// CustomFieldReader::get_by_audience().
					$active_only = false !== strpos( (string) $sql, 'is_active = 1' );
					$rows        = array();
					foreach ( $this->fields as $field ) {
						if ( (int) $field->audience_id === (int) $bound[1] && ( ! $active_only || 1 === (int) $field->is_active ) ) {
							$rows[] = clone $field;
						}
					}
					return $rows;
				}
				$this->fail( 'Unexpected get_results(): ' . $sql . ' with ' . var_export( $bound, true ) );
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				$bound = $this->bound( (string) $sql );
				if ( self::AUDIENCES === $bound[0] && false !== strpos( (string) $sql, 'WHERE id = %d' ) ) {
					return isset( $this->audiences[ (int) $bound[1] ] ) ? clone $this->audiences[ (int) $bound[1] ] : null;
				}
				$this->fail( 'Unexpected get_row(): ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$bound = $this->bound( (string) $sql );
				if ( false !== strpos( (string) $sql, 'SHOW TABLES LIKE' ) && self::PROFILES === $bound[0] ) {
					// No profile row to read: UserManager falls back to the account.
					return null;
				}
				if ( self::PROFILES === $bound[0] && false !== strpos( (string) $sql, 'SELECT id FROM %i WHERE user_id = %d' ) ) {
					return null;
				}
				$this->fail( 'Unexpected get_var(): ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) {
				if ( self::PROFILES === $table ) {
					$this->profile_inserts[] = $data;
					return 1;
				}
				$this->fail( 'Unexpected insert() into ' . $table );
			}
		);

		return $wpdb;
	}

	/**
	 * The values bound to a prepared statement token.
	 *
	 * @param string $sql Token prepare() returned.
	 * @return array<int, mixed>
	 */
	private function bound( string $sql ): array {
		if ( 1 !== preg_match( '/^Q(\d+):/', $sql, $m ) ) {
			$this->fail( 'A statement that was not prepared: ' . $sql );
		}
		return array_slice( $this->prepared[ (int) $m[1] ], 1 );
	}

	/**
	 * Store an audience.
	 *
	 * @param int      $id     Id.
	 * @param string   $name   Name.
	 * @param int|null $parent Parent audience id.
	 * @return void
	 */
	private function audience( int $id, string $name, ?int $parent = null ): void {
		$this->audiences[ $id ] = (object) array(
			'id'        => (string) $id,
			'name'      => $name,
			'color'     => '#fff',
			'parent_id' => null === $parent ? null : (string) $parent,
			'status'    => 'active',
		);
	}

	/**
	 * Store a custom field of an audience, with the columns a row carries.
	 *
	 * @param int                  $audience_id Audience the field belongs to.
	 * @param array<string, mixed> $columns     Columns that differ from a plain text field.
	 * @return object The row as stored.
	 */
	private function field( int $audience_id, array $columns ): object {
		$row            = (object) array_merge(
			array(
				'field_key'         => 'key_' . $columns['id'],
				'field_label'       => 'Label',
				'field_type'        => 'text',
				'field_options'     => '',
				'validation_rules'  => null,
				'field_mask'        => null,
				'field_profile_key' => null,
				'is_required'       => 0,
				'is_sensitive'      => 0,
				'is_active'         => 1,
				'sort_order'        => 0,
			),
			$columns,
			array( 'audience_id' => $audience_id )
		);
		$this->fields[] = $row;
		return $row;
	}

	/**
	 * Make a user a direct member of audiences.
	 *
	 * @param int $user_id      User.
	 * @param int ...$audiences Audience ids.
	 * @return void
	 */
	private function member( int $user_id, int ...$audiences ): void {
		$this->memberships[ $user_id ] = $audiences;
	}

	/**
	 * Every value written to a user's custom-field snapshot.
	 *
	 * @param int $user_id User.
	 * @return list<mixed>
	 */
	private function snapshots( int $user_id ): array {
		$out = array();
		foreach ( $this->meta_writes as $write ) {
			if ( 'update' === $write[0] && $user_id === $write[1] && CustomFieldReader::USER_META_KEY === $write[2] ) {
				$out[] = $write[3];
			}
		}
		return $out;
	}

	/**
	 * The one snapshot a save wrote for the user.
	 *
	 * @param int $user_id User.
	 * @return array<string, mixed>
	 */
	private function snapshot( int $user_id ): array {
		$written = $this->snapshots( $user_id );
		$this->assertCount( 1, $written, 'The snapshot is written exactly once.' );
		return $written[0];
	}

	/**
	 * Meta writes to the extended profile (`ffc_user_*` keys), by key.
	 *
	 * @param int $user_id User.
	 * @return array<string, mixed>
	 */
	private function profile_writes( int $user_id ): array {
		$out = array();
		foreach ( $this->meta_writes as $write ) {
			if ( $user_id === $write[1] && 0 === strpos( (string) $write[2], 'ffc_user_' ) ) {
				$out[ $write[2] ] = $write[3];
			}
		}
		return $out;
	}

	/**
	 * Store an extended-profile value as UserProfileService writes it:
	 * encrypted when sensitive.
	 *
	 * @param int    $user_id   User.
	 * @param string $key       Profile key.
	 * @param string $value     Clear value.
	 * @param bool   $sensitive Whether it is stored encrypted.
	 * @return void
	 */
	private function profile_value( int $user_id, string $key, string $value, bool $sensitive ): void {
		$this->meta[ $user_id ][ 'ffc_user_' . $key ] = $sensitive ? Encryption::encrypt( $value ) : $value;
	}

	// ==================================================================
	// init()
	// ==================================================================

	public function test_init_registers_show_user_profile_action(): void {
		Actions\expectAdded('show_user_profile')
			->once()
			->with(
				Mockery::type('array'),
				30
			);

		Actions\expectAdded('edit_user_profile')->once();
		Actions\expectAdded('personal_options_update')->once();
		Actions\expectAdded('edit_user_profile_update')->once();
		Actions\expectAdded('admin_enqueue_scripts')->once();

		AdminUserCustomFields::init();
	}

	public function test_init_registers_all_six_hooks(): void {
		$registered_hooks = [];
		Functions\when('add_action')->alias(function ($hook, $callback, $priority = 10) use (&$registered_hooks) {
			$registered_hooks[] = $hook;
		});

		AdminUserCustomFields::init();

		$this->assertContains('show_user_profile', $registered_hooks);
		$this->assertContains('edit_user_profile', $registered_hooks);
		$this->assertContains('personal_options_update', $registered_hooks);
		$this->assertContains('edit_user_profile_update', $registered_hooks);
		$this->assertContains('admin_enqueue_scripts', $registered_hooks);
		// #1120 — the notice that says which required fields kept their value.
		$this->assertContains('admin_notices', $registered_hooks);
		$this->assertCount(6, $registered_hooks);
	}

	// ==================================================================
	// enqueue_assets()
	// ==================================================================

	public function test_enqueue_assets_returns_early_for_non_user_page(): void {
		$style_called = false;
		Functions\when('wp_enqueue_style')->alias(function () use (&$style_called) {
			$style_called = true;
		});

		AdminUserCustomFields::enqueue_assets('edit.php');

		$this->assertFalse($style_called, 'wp_enqueue_style should NOT be called on non-user pages');
	}

	public function test_enqueue_assets_loads_on_user_edit_page(): void {
		$enqueued_styles = [];
		$enqueued_scripts = [];

		Functions\when('wp_enqueue_style')->alias(function ($handle) use (&$enqueued_styles) {
			$enqueued_styles[] = $handle;
		});
		Functions\when('wp_enqueue_script')->alias(function ($handle) use (&$enqueued_scripts) {
			$enqueued_scripts[] = $handle;
		});
		Functions\when('wp_localize_script')->justReturn(true);

		AdminUserCustomFields::enqueue_assets('user-edit.php');

		// The token palette comes first: both sheets paint through it (#1126 B).
		$this->assertSame('ffc-common', $enqueued_styles[0] ?? null);
		$this->assertContains('ffc-working-hours-css', $enqueued_styles);
		$this->assertContains('ffc-custom-fields-admin-css', $enqueued_styles);
		$this->assertContains('ffc-working-hours', $enqueued_scripts);
	}

	public function test_enqueue_assets_loads_on_profile_page(): void {
		$enqueued_styles = [];

		Functions\when('wp_enqueue_style')->alias(function ($handle) use (&$enqueued_styles) {
			$enqueued_styles[] = $handle;
		});
		Functions\when('wp_enqueue_script')->justReturn(true);
		Functions\when('wp_localize_script')->justReturn(true);

		AdminUserCustomFields::enqueue_assets('profile.php');

		$this->assertContains('ffc-working-hours-css', $enqueued_styles);
		$this->assertContains('ffc-custom-fields-admin-css', $enqueued_styles);
	}

	public function test_enqueue_assets_localizes_day_labels(): void {
		$localized_data = null;

		Functions\when('wp_enqueue_style')->justReturn(true);
		Functions\when('wp_enqueue_script')->justReturn(true);
		Functions\when('wp_localize_script')->alias(function ($handle, $var, $data) use (&$localized_data) {
			if ($var === 'ffcWorkingHours') {
				$localized_data = $data;
			}
		});

		AdminUserCustomFields::enqueue_assets('user-edit.php');

		$this->assertNotNull($localized_data);
		$this->assertArrayHasKey('days', $localized_data);
		$this->assertCount(7, $localized_data['days']);
	}

	// ==================================================================
	// save_section() - nonce and permission checks
	// ==================================================================

	public function test_save_section_returns_early_without_nonce(): void {
		// No $_POST nonce set
		Functions\when('wp_verify_nonce')->justReturn(false);
		$this->member(1, 1);
		$this->audience(1, 'Staff');
		$this->field(1, array( 'id' => 10 ));

		AdminUserCustomFields::save_section(1);

		$this->assertSame(array(), $this->meta_writes, 'Nothing was written.');
		$this->assertSame(array(), $this->prepared, 'The fields were never read.');
	}

	public function test_save_section_returns_early_with_invalid_nonce(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'bad_nonce';
		$verified = array();
		Functions\when('wp_verify_nonce')->alias(function ($nonce, $action) use (&$verified) {
			$verified[] = array( $nonce, $action );
			return false;
		});
		$this->member(1, 1);
		$this->audience(1, 'Staff');
		$this->field(1, array( 'id' => 10 ));

		AdminUserCustomFields::save_section(1);

		$this->assertSame(array( array( 'bad_nonce', 'ffc_save_user_custom_fields' ) ), $verified);
		$this->assertSame(array(), $this->meta_writes);
		$this->assertSame(array(), $this->prepared);
	}

	public function test_save_section_returns_early_without_permission(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(false);
		$this->member(1, 1);
		$this->audience(1, 'Staff');
		$this->field(1, array( 'id' => 10 ));

		AdminUserCustomFields::save_section(1);

		$this->assertSame(array(), $this->meta_writes);
		$this->assertSame(array(), $this->prepared);
	}

	public function test_save_section_returns_early_when_no_fields_for_user(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);

		AdminUserCustomFields::save_section(42);

		$this->assertSame(array(), $this->meta_writes);
		$this->assertSame(42, $this->prepared[0][3] ?? null, 'The audiences of the user being saved were read.');
	}

	public function test_save_section_saves_text_field_data(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_10']                    = 'Engineering';

		$this->audience(1, 'Staff');
		$this->member(5, 1);
		$this->field(1, array( 'id' => 10, 'field_type' => 'text', 'field_label' => 'Department' ));

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);

		AdminUserCustomFields::save_section(5);

		$this->assertSame(array( 'field_10' => 'Engineering' ), $this->snapshot(5));
	}

	/**
	 * A profile-mapped field is written to the profile, not the snapshot
	 * (#1538): the reregistration form reads it from there, and the snapshot
	 * copy was one nothing read -- in plaintext for the sensitive fields.
	 */
	public function test_save_section_writes_profile_mapped_fields_to_the_profile(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_10']                    = 'Engineering';
		$_POST['ffc_cf_11']                    = '1990-05-20';
		$_POST['ffc_cf_12']                    = Identifiers::CPF_A;

		$this->audience(1, 'Staff');
		$this->member(5, 1);
		$this->field(1, array( 'id' => 10, 'field_type' => 'text', 'field_label' => 'Department' ));
		$this->field(1, array( 'id' => 11, 'field_type' => 'date', 'field_label' => 'Date of Birth', 'field_profile_key' => 'birth_date', 'is_sensitive' => 0 ));
		$this->field(1, array( 'id' => 12, 'field_type' => 'text', 'field_label' => 'CPF', 'field_profile_key' => 'cpf', 'is_sensitive' => 1 ));

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);

		AdminUserCustomFields::save_section(5);

		$this->assertSame(array( 'field_10' => 'Engineering' ), $this->snapshot(5), 'Only the unmapped field reaches the snapshot.');

		$profile = $this->profile_writes(5);
		$this->assertSame(array( 'ffc_user_birth_date', 'ffc_user_cpf', 'ffc_user_birth_md' ), array_keys($profile));
		$this->assertSame('1990-05-20', Encryption::decrypt((string) $profile['ffc_user_birth_date']));
		$this->assertSame(Identifiers::CPF_A, Encryption::decrypt((string) $profile['ffc_user_cpf']));
		$this->assertStringNotContainsString(Identifiers::CPF_A, (string) $profile['ffc_user_cpf'], 'The CPF is stored encrypted.');
		$this->assertSame('05-20', $profile['ffc_user_birth_md']);
		// The CPF is indexed by its hash on the profile row.
		$this->assertCount(1, $this->profile_inserts);
		$this->assertSame(5, $this->profile_inserts[0]['user_id']);
		$this->assertNotEmpty($this->profile_inserts[0]['cpf_hash']);
	}

	/**
	 * A required profile-mapped field submitted empty keeps its value: the
	 * empty string never reaches the profile, so nothing is erased (#1120).
	 */
	public function test_save_section_keeps_a_required_profile_field_submitted_empty(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_11']                    = '';

		$this->audience(1, 'Staff');
		$this->member(5, 1);
		$this->field(1, array( 'id' => 11, 'field_type' => 'date', 'field_label' => 'Date of Birth', 'field_profile_key' => 'birth_date', 'is_sensitive' => 0, 'is_required' => 1 ));
		$this->profile_value(5, 'birth_date', '1990-05-20', true);

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);

		AdminUserCustomFields::save_section(5);

		$this->assertSame(array(), $this->snapshot(5));
		$this->assertSame(array(), $this->profile_writes(5), 'The profile is not touched.');
		$this->assertSame('1990-05-20', Encryption::decrypt((string) $this->meta[5]['ffc_user_birth_date']));
	}

	public function test_save_section_saves_checkbox_field_as_1_when_checked(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_20']                    = '1';

		$this->audience(1, 'Staff');
		$this->member(7, 1);
		$this->field(1, array( 'id' => 20, 'field_type' => 'checkbox', 'field_label' => 'Active' ));

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);

		AdminUserCustomFields::save_section(7);

		$this->assertSame(array( 'field_20' => 1 ), $this->snapshot(7));
	}

	public function test_save_section_saves_checkbox_field_as_0_when_unchecked(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		// Checkbox not in POST means unchecked

		$this->audience(1, 'Staff');
		$this->member(7, 1);
		$this->field(1, array( 'id' => 20, 'field_type' => 'checkbox', 'field_label' => 'Active' ));

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);

		AdminUserCustomFields::save_section(7);

		$this->assertSame(array( 'field_20' => 0 ), $this->snapshot(7));
	}

	public function test_save_section_saves_textarea_field_with_sanitize_textarea(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_30']                    = "Line 1\nLine 2";

		$this->audience(1, 'Staff');
		$this->member(3, 1);
		$this->field(1, array( 'id' => 30, 'field_type' => 'textarea', 'field_label' => 'Notes' ));

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);
		$textarea_calls = array();
		Functions\when('sanitize_textarea_field')->alias(function ($v) use (&$textarea_calls) {
			$textarea_calls[] = $v;
			return $v;
		});

		AdminUserCustomFields::save_section(3);

		$this->assertSame(array( "Line 1\nLine 2" ), $textarea_calls, 'A textarea keeps its line breaks through its own sanitiser.');
		$this->assertSame(array( 'field_30' => "Line 1\nLine 2" ), $this->snapshot(3));
	}

	/**
	 * Two sibling audiences sharing a parent bring that parent's field twice;
	 * it is saved once.
	 */
	public function test_save_section_saves_a_field_two_audiences_share_once(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_50']                    = 'ABC';

		$this->audience(1, 'Schools');
		$this->audience(2, 'North', 1);
		$this->audience(3, 'South', 1);
		$this->member(1, 2, 3);
		$this->field(1, array( 'id' => 50, 'field_type' => 'text', 'field_label' => 'Code' ));

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);

		AdminUserCustomFields::save_section(1);

		$this->assertSame(array( 'field_50' => 'ABC' ), $this->snapshot(1));
	}

	// ==================================================================
	// render_section() - early return when no audiences
	// ==================================================================

	public function test_render_section_returns_early_when_no_audiences(): void {
		$user = new \WP_User(10);
		$user->ID = 10;

		ob_start();
		AdminUserCustomFields::render_section($user);
		$output = ob_get_clean();

		$this->assertEmpty($output);
		$this->assertSame(10, $this->prepared[0][3] ?? null, 'The audiences of the user shown were read.');
	}

	public function test_render_section_returns_when_audiences_have_no_fields(): void {
		$user     = new \WP_User(10);
		$user->ID = 10;

		$this->audience(1, 'Doctors');
		// No fields -> the audience section is skipped (continue branch).
		$this->member(10, 1);

		Functions\when('esc_html_e')->alias(function ($t) { echo $t; });
		Functions\when('wp_nonce_field')->justReturn('');

		ob_start();
		AdminUserCustomFields::render_section($user);
		$output = ob_get_clean();

		// Heading rendered, but no field section markup.
		$this->assertStringContainsString('FFC Custom Data', $output);
		$this->assertStringNotContainsString('ffc-cf-section-1', $output);
	}

	public function test_render_section_renders_all_field_types(): void {
		$user     = new \WP_User(10);
		$user->ID = 10;

		// Doctors sits under "Parent Aud", whose Shift field it inherits.
		$this->audience(2, 'Parent Aud');
		$this->audience(1, 'Doctors', 2);
		$this->member(10, 1);
		$this->field(1, array(
			'id' => 11, 'field_label' => 'Department', 'field_type' => 'text',
			'is_required' => 1, 'field_options' => '{"help_text":"Pick one"}',
		));
		$this->field(1, array( 'id' => 12, 'field_label' => 'Bio', 'field_type' => 'textarea' ));
		$this->field(2, array( 'id' => 13, 'field_label' => 'Shift', 'field_type' => 'select', 'field_options' => '{"choices":["Morning","Night"]}' ));
		$this->field(1, array( 'id' => 14, 'field_label' => 'Active', 'field_type' => 'checkbox' ));
		$this->field(1, array( 'id' => 15, 'field_label' => 'Age', 'field_type' => 'number' ));
		$this->field(1, array( 'id' => 16, 'field_label' => 'Start', 'field_type' => 'date' ));
		$this->field(1, array( 'id' => 17, 'field_label' => 'Hours', 'field_type' => 'working_hours' ));
		$this->meta[10][ CustomFieldReader::USER_META_KEY ] = array(
			'field_11' => 'Cardiology',
			'field_17' => json_encode([['day' => 1, 'entry1' => '08:00', 'exit2' => '17:00']]),
		);

		Functions\when('esc_html_e')->alias(function ($t) { echo $t; });
		Functions\when('esc_textarea')->returnArg();
		Functions\when('wp_nonce_field')->justReturn('');
		Functions\when('selected')->justReturn('');
		Functions\when('checked')->justReturn('');
		Functions\when('esc_html_e')->alias(static function ($text) { echo $text; });

		ob_start();
		AdminUserCustomFields::render_section($user);
		$output = ob_get_clean();

		$this->assertStringContainsString('FFC Custom Data', $output);
		$this->assertStringContainsString('ffc-cf-section-1', $output);
		// Text input + value.
		$this->assertStringContainsString('ffc_cf_11', $output);
		$this->assertStringContainsString('Cardiology', $output);
		// Required marker.
		$this->assertStringContainsString('required', $output);
		// Help text.
		$this->assertStringContainsString('Pick one', $output);
		// Textarea.
		$this->assertStringContainsString('<textarea', $output);
		// Select with choices.
		$this->assertStringContainsString('<select', $output);
		$this->assertStringContainsString('Morning', $output);
		// Inherited marker (source_audience_id != audience id).
		$this->assertStringContainsString('Inherited from Parent Aud', $output);
		// Checkbox.
		$this->assertStringContainsString('type="checkbox"', $output);
		// Number.
		$this->assertStringContainsString('type="number"', $output);
		// Date.
		$this->assertStringContainsString('type="date"', $output);
		// Working hours table.
		$this->assertStringContainsString('ffc-working-hours', $output);
		$this->assertStringContainsString('ffc-wh-table', $output);
	}

	/**
	 * Two sibling audiences sharing a parent bring that parent's field twice;
	 * the second occurrence is skipped.
	 */
	public function test_render_section_deduplicates_shared_fields(): void {
		$user     = new \WP_User(10);
		$user->ID = 10;

		$this->audience(1, 'Schools');
		$this->audience(2, 'North', 1);
		$this->audience(3, 'South', 1);
		$this->member(10, 2, 3);
		$this->field(1, array( 'id' => 50, 'field_label' => 'Code', 'field_type' => 'text' ));

		Functions\when('esc_html_e')->alias(function ($t) { echo $t; });
		Functions\when('wp_nonce_field')->justReturn('');
		Functions\when('selected')->justReturn('');

		ob_start();
		AdminUserCustomFields::render_section($user);
		$output = ob_get_clean();

		// The input name appears exactly once despite the duplicate field.
		$this->assertSame(1, substr_count($output, 'name="ffc_cf_50"'));
		$this->assertStringContainsString('ffc-cf-section-2', $output);
		$this->assertStringContainsString('ffc-cf-section-3', $output);
	}

	// ==================================================================
	// save_section() - working_hours branch
	// ==================================================================

	public function test_save_section_saves_working_hours_field(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_60']                    = json_encode([
			['day' => 1, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '13:00', 'exit2' => '17:00'],
			// Invalid entry (missing keys) is dropped.
			['day' => 2, 'entry1' => '08:00'],
			'not-an-array',
		]);

		$this->audience(1, 'Staff');
		$this->member(8, 1);
		$this->field(1, array( 'id' => 60, 'field_type' => 'working_hours', 'field_label' => 'Hours' ));

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);

		// Since #1128 the half-filled row is not only dropped, it is reported,
		// so the save writes the incomplete-rows transient on this path.
		$reported = null;
		Functions\when('set_transient')->alias(function ($key, $value) use (&$reported) {
			if (str_starts_with($key, 'ffc_cf_wh_incomplete_')) {
				$reported = $value;
			}
			return true;
		});

		AdminUserCustomFields::save_section(8);

		$decoded = json_decode((string) $this->snapshot(8)['field_60'], true);
		// Only the one fully-formed entry survives sanitization.
		$this->assertIsArray($decoded);
		$this->assertCount(1, $decoded);
		$this->assertSame(1, $decoded[0]['day']);
		$this->assertSame('08:00', $decoded[0]['entry1']);
		$this->assertSame('17:00', $decoded[0]['exit2']);

		// The dropped row is announced, naming the day and what was missing —
		// dropping it in silence is the half of #1128 that was never the fix.
		$this->assertIsArray($reported);
		$this->assertCount(1, $reported);
		$this->assertSame(['exit2'], $reported[0]['missing']);
		$this->assertSame('Hours', $reported[0]['label']);
		$this->assertSame(2, $reported[0]['day']);
	}

	public function test_save_section_working_hours_invalid_json_stores_empty_array(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_61']                    = 'not valid json {{{';

		$this->audience(1, 'Staff');
		$this->member(9, 1);
		$this->field(1, array( 'id' => 61, 'field_type' => 'working_hours', 'field_label' => 'Hours' ));

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);

		AdminUserCustomFields::save_section(9);

		$this->assertSame(array( 'field_61' => '[]' ), $this->snapshot(9));
	}

	// ==================================================================
	// #1120 — a required field arriving empty keeps its stored value
	// ==================================================================

	/**
	 * Store one required text field, post a value for it and run save_section.
	 *
	 * @param string $posted Value the browser sent.
	 * @param string $stored Value already on the profile.
	 * @return array{0: array<string, mixed>, 1: array<int, string>} Saved data, and the labels the notice would name.
	 */
	private function save_required_text( string $posted, string $stored ): array {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_77']                    = $posted;

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);
		Functions\when('get_current_user_id')->justReturn(3);

		$kept = array();
		Functions\when('set_transient')->alias(function ($key, $value) use (&$kept) {
			$kept = $value;
			return true;
		});

		$this->audience(1, 'Staff');
		$this->member(5, 1);
		$this->field(1, array( 'id' => 77, 'field_type' => 'text', 'field_label' => 'Registration number', 'is_required' => 1 ));
		$this->meta[5][ CustomFieldReader::USER_META_KEY ] = array( 'field_77' => $stored );

		AdminUserCustomFields::save_section(5);

		return [$this->snapshot(5), $kept];
	}

	/**
	 * The `required` attribute stops this in the browser, but the browser is
	 * not the guard: a direct POST reaches save_section() all the same, and
	 * before #1120 it overwrote the stored value with the empty string.
	 */
	public function test_save_section_keeps_the_stored_value_when_a_required_field_is_empty(): void {
		[$saved, $kept] = $this->save_required_text('', Identifiers::RF_A);

		$this->assertSame(Identifiers::RF_A, $saved['field_77']);
		$this->assertSame(['Registration number'], $kept, 'The operator has to be told the edit did not take.');
	}

	public function test_save_section_still_writes_a_supplied_value_on_a_required_field(): void {
		[$saved, $kept] = $this->save_required_text(Identifiers::RF_B, Identifiers::RF_A);

		$this->assertSame(Identifiers::RF_B, $saved['field_77']);
		$this->assertSame([], $kept);
	}

	/**
	 * Nothing to preserve means nothing to announce — a required field that
	 * was already empty stays empty, and the notice would be noise.
	 */
	public function test_save_section_does_not_announce_a_required_field_that_was_already_empty(): void {
		[$saved, $kept] = $this->save_required_text('', '');

		$this->assertSame('', $saved['field_77']);
		$this->assertSame([], $kept);
	}

	/**
	 * An optional field keeps the old behaviour: clearing it clears it.
	 */
	public function test_save_section_still_clears_an_optional_field(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_78']                    = '';

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);

		$this->audience(1, 'Staff');
		$this->member(5, 1);
		$this->field(1, array( 'id' => 78, 'field_type' => 'text', 'field_label' => 'Nickname', 'is_required' => 0 ));
		$this->meta[5][ CustomFieldReader::USER_META_KEY ] = array( 'field_78' => 'Bob' );

		AdminUserCustomFields::save_section(5);

		$this->assertSame(array( 'field_78' => '' ), $this->snapshot(5));
	}

	// ==================================================================
	// #1120 — render_field_input() emits `required` from the flag
	// ==================================================================

	/**
	 * Render one field and return its markup.
	 *
	 * @param string $type     Field type.
	 * @param bool   $required Whether the definition marks it required.
	 * @return string
	 */
	private function render_input( string $type, bool $required ): string {
		$field = (object) [
			'id'            => 1,
			'field_type'    => $type,
			'field_label'   => 'Label',
			'is_required'   => $required ? 1 : 0,
			'field_options' => '{"choices":["a","b"]}',
		];

		Functions\when('selected')->justReturn('');
		Functions\when('checked')->justReturn('');
		Functions\when('esc_html_e')->alias(static function ($text) { echo $text; });
		Functions\when('esc_textarea')->returnArg();

		$ref = new \ReflectionMethod(AdminUserCustomFields::class, 'render_field_input');
		$ref->setAccessible(true);

		ob_start();
		$ref->invokeArgs(null, [$field, 'ffc_cf_1', '']);

		return (string) ob_get_clean();
	}

	/**
	 * Until #1120 the flag drew an asterisk and nothing else — no input
	 * emitted the attribute and save_section() never checked it, so the
	 * screen promised something neither end enforced.
	 *
	 * @dataProvider requirable_types
	 * @param string $type Field type that must honour the flag.
	 */
	public function test_render_field_input_emits_required_for_a_required_field( string $type ): void {
		$this->assertStringContainsString('required', $this->render_input($type, true));
	}

	/**
	 * @dataProvider requirable_types
	 * @param string $type Field type that must not invent the attribute.
	 */
	public function test_render_field_input_omits_required_for_an_optional_field( string $type ): void {
		$this->assertStringNotContainsString('required', $this->render_input($type, false));
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function requirable_types(): array {
		return [
			'text'     => ['text'],
			'textarea' => ['textarea'],
			'number'   => ['number'],
			'date'     => ['date'],
			'select'   => ['select'],
		];
	}

	/**
	 * A required checkbox would have to be *ticked* to satisfy the browser,
	 * which is a different promise from "fill this in" and would change what
	 * existing profiles may save. The reregistration renderer draws the
	 * asterisk and skips the attribute for the same reason.
	 */
	public function test_render_field_input_never_marks_a_checkbox_required(): void {
		$html = $this->render_input('checkbox', true);

		$this->assertStringContainsString('type="checkbox"', $html);
		$this->assertStringNotContainsString('required', $html);
	}

	/**
	 * The working-hours value posts through a hidden input, which is barred
	 * from constraint validation outright; the two time inputs inside are
	 * what enforce it, and they predate #1120.
	 */
	public function test_render_field_input_does_not_mark_the_working_hours_carrier_required(): void {
		$html = $this->render_input('working_hours', true);

		$this->assertStringContainsString('type="hidden"', $html);
		$this->assertDoesNotMatchRegularExpression('/<input type="hidden"[^>]*required/', $html);
	}

	// ==================================================================
	// Masks, formats and dependent selects (the reregistration behaviours)
	// ==================================================================

	/**
	 * The mask comes from the field's own `field_mask`, and falls back to
	 * the validation format -- the reregistration form's rule, so a field
	 * masks alike on both screens.
	 */
	public function test_mask_for_prefers_the_field_mask_then_the_format(): void {
		$masked  = (object) [ 'field_mask' => 'cpf', 'validation_rules' => '{"format":"phone"}' ];
		$format  = (object) [ 'field_mask' => null, 'validation_rules' => '{"format":"phone"}' ];
		$neither = (object) [ 'field_mask' => '', 'validation_rules' => null ];

		$this->assertSame( 'cpf', AdminUserCustomFields::mask_for( $masked ) );
		$this->assertSame( 'phone', AdminUserCustomFields::mask_for( $format ) );
		$this->assertSame( '', AdminUserCustomFields::mask_for( $neither ) );
	}

	/**
	 * CPF, RF, RG and the phones carry a `data-mask` the shared script reads,
	 * and every row names its field key and format, which is what the
	 * dual-post toggle and the format check select by.
	 */
	public function test_render_section_emits_the_mask_the_key_and_the_format(): void {
		$user     = new \WP_User(10);
		$user->ID = 10;
		$this->audience(1, 'Staff');
		$this->member(10, 1);
		$this->field(1, array(
			'id' => 21, 'field_key' => 'cpf', 'field_label' => 'CPF', 'field_type' => 'text',
			'field_mask' => 'cpf', 'validation_rules' => '{"format":"cpf"}',
		));
		$this->field(1, array(
			'id' => 22, 'field_key' => 'celular', 'field_label' => 'Mobile', 'field_type' => 'text',
			'field_mask' => null, 'validation_rules' => '{"format":"phone"}',
		));
		Functions\when('esc_html_e')->alias(static function ($t) { echo $t; });
		Functions\when('wp_nonce_field')->justReturn('');

		ob_start();
		AdminUserCustomFields::render_section($user);
		$output = (string) ob_get_clean();

		$this->assertStringContainsString('id="ffc-user-custom-fields"', $output);
		$this->assertMatchesRegularExpression('/<tr data-field-key="cpf"\s+data-format="cpf"/', $output);
		$this->assertMatchesRegularExpression('/name="ffc_cf_21"[^>]*data-mask="cpf"/', $output);
		$this->assertMatchesRegularExpression('/<tr data-field-key="celular"\s+data-format="phone"/', $output);
		$this->assertMatchesRegularExpression('/name="ffc_cf_22"[^>]*data-mask="phone"/', $output);
	}

	/**
	 * "Division / Department" was drawn as a raw text box holding the
	 * stored JSON; it now draws the reregistration form's own cascade.
	 */
	public function test_render_field_input_draws_a_dependent_select_as_the_cascade(): void {
		$field = (object) [
			'id' => 30, 'field_key' => 'divisao_setor', 'field_label' => 'Division / Department',
			'field_type' => 'dependent_select', 'is_required' => 1,
			'field_options' => '{"parent_label":"Division","child_label":"Department","groups":{"DIPED":["Sector A","Sector B"],"DRE":["Sector C"]}}',
		];
		Functions\when('esc_html_e')->alias(static function ($t) { echo $t; });
		Functions\when('selected')->alias(static fn($a, $b, $echo = true) => (string) $a === (string) $b ? ' selected' : '');

		$ref = new \ReflectionMethod(AdminUserCustomFields::class, 'render_field_input');
		$ref->setAccessible(true);
		ob_start();
		$ref->invokeArgs(null, [$field, 'ffc_cf_30', '{"parent":"DIPED","child":"Sector B"}']);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString('class="ffc-dependent-select" data-target="ffc_cf_30"', $html);
		$this->assertStringContainsString('name="ffc_cf_30"', $html);
		$this->assertStringContainsString('Division', $html);
		$this->assertStringContainsString('<option value="Sector B"  selected>', $html);
		$this->assertStringContainsString('ffc-dep-groups', $html);
		$this->assertStringNotContainsString('type="text"', $html);
	}

	/**
	 * The posted pair is decoded, checked against the field's choices and
	 * re-encoded from the two validated strings.
	 */
	public function test_clean_dependent_select_accepts_only_a_listed_pair(): void {
		$field = (object) [
			'id' => 30, 'field_label' => 'Division / Department', 'field_type' => 'dependent_select',
			'is_required' => 0, 'field_options' => '{"groups":{"DIPED":["Sector A"]}}', 'validation_rules' => null,
		];

		$this->assertSame(
			'{"parent":"DIPED","child":"Sector A"}',
			AdminUserCustomFields::clean_dependent_select( $field, ' {"child":"Sector A","parent":"DIPED","extra":"x"} ' )
		);
		$this->assertSame( '', AdminUserCustomFields::clean_dependent_select( $field, '{"parent":"","child":""}' ) );
		$this->assertSame( '', AdminUserCustomFields::clean_dependent_select( $field, 'not json' ) );
		$this->assertNull( AdminUserCustomFields::clean_dependent_select( $field, '{"parent":"DIPED","child":"Elsewhere"}' ) );
		$this->assertNull( AdminUserCustomFields::clean_dependent_select( $field, '{"parent":"Nowhere","child":"Sector A"}' ) );
		$this->assertNull( AdminUserCustomFields::clean_dependent_select( $field, '{"parent":"DIPED","child":""}' ) );
	}

	/**
	 * `divisao_setor` is profile-mapped, so a valid pair goes to the profile
	 * as canonical JSON and never into the snapshot.
	 */
	public function test_save_section_writes_a_dependent_select_to_the_profile(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_30']                    = '{"parent":"DIPED","child":"Sector A"}';

		$this->audience(1, 'Staff');
		$this->member(5, 1);
		$this->field(1, array(
			'id' => 30, 'field_type' => 'dependent_select', 'field_label' => 'Division / Department',
			'field_profile_key' => 'divisao_setor', 'is_sensitive' => 0, 'is_required' => 1,
			'field_options' => '{"groups":{"DIPED":["Sector A"]}}',
		));

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);

		AdminUserCustomFields::save_section(5);

		$this->assertSame(array(), $this->snapshot(5));
		// Not sensitive: stored as it is.
		$this->assertSame(
			array( 'ffc_user_divisao_setor' => '{"parent":"DIPED","child":"Sector A"}' ),
			$this->profile_writes(5)
		);
	}

	/**
	 * A pair outside the choices keeps the stored value and is announced,
	 * instead of being written as posted.
	 */
	public function test_save_section_keeps_the_stored_pair_when_the_posted_one_is_invalid(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_30']                    = '{"parent":"DIPED","child":"<script>"}';

		$this->audience(1, 'Staff');
		$this->member(5, 1);
		$this->field(1, array(
			'id' => 30, 'field_type' => 'dependent_select', 'field_label' => 'Division / Department',
			'is_required' => 0, 'field_options' => '{"groups":{"DIPED":["Sector A"]}}',
		));
		$this->meta[5][ CustomFieldReader::USER_META_KEY ] = array( 'field_30' => '{"parent":"DIPED","child":"Sector A"}' );

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);
		Functions\when('get_current_user_id')->justReturn(1);

		$transients = array();
		Functions\when('set_transient')->alias(function ($key, $value) use (&$transients) {
			$transients[ $key ] = $value;
			return true;
		});

		AdminUserCustomFields::save_section(5);

		$this->assertSame(array( 'field_30' => '{"parent":"DIPED","child":"Sector A"}' ), $this->snapshot(5));
		$this->assertSame( array( 'Division / Department' ), $transients['ffc_cf_required_kept_1'] ?? null );
	}

	/**
	 * The shared behaviours are enqueued with their strings, and the value
	 * that reveals the accumulation fields is the translated "I hold" the
	 * field stores.
	 */
	public function test_enqueue_assets_loads_the_shared_field_behaviours(): void {
		$scripts  = array();
		$localize = array();
		Functions\when('wp_enqueue_style')->justReturn(true);
		Functions\when('wp_enqueue_script')->alias(function ($handle, $src = '', $deps = array()) use (&$scripts) {
			$scripts[ $handle ] = $deps;
		});
		Functions\when('wp_localize_script')->alias(function ($handle, $name, $data) use (&$localize) {
			$localize[ $name ] = $data;
		});

		AdminUserCustomFields::enqueue_assets('user-edit.php');

		// Its dependencies are AssetRegistry's to declare (#1931).
		$this->assertArrayHasKey( 'ffc-field-behaviours', $scripts );
		$this->assertSame( array( 'jquery', 'ffc-field-behaviours' ), $scripts['ffc-admin-user-fields'] ?? null );
		$this->assertSame( 'I hold', $localize['ffcAdminUserFields']['strings']['dualPostShowValue'] ?? null );
	}

	// ==================================================================
	// Profile-mapped values, dependent selects and the post-save notices
	// ==================================================================

	public function test_render_section_shows_a_profile_mapped_field_from_the_profile(): void {
		$user     = new \WP_User(10);
		$user->ID = 10;
		$this->audience(1, 'Staff');
		$this->member(10, 1);
		$this->field(1, array(
			'id' => 23, 'field_key' => 'ramal', 'field_label' => 'Extension', 'field_type' => 'text',
			'field_profile_key' => 'phone_ext', 'is_sensitive' => 1,
		));
		// The snapshot carries a stale copy; the profile is what the screen shows.
		$this->meta[10][ CustomFieldReader::USER_META_KEY ] = array( 'field_23' => 'stale' );
		// Sensitive, so stored encrypted: the read must ask for it as such.
		$this->profile_value(10, 'phone_ext', '4321', true);
		Functions\when('esc_html_e')->alias(static function ($t) { echo $t; });
		Functions\when('wp_nonce_field')->justReturn('');
		// The user's own profile: their sensitive field is theirs to see (#1863).
		Functions\when('get_current_user_id')->justReturn(10);
		Functions\when('user_can')->justReturn(false);

		ob_start();
		AdminUserCustomFields::render_section($user);
		$output = (string) ob_get_clean();

		$this->assertMatchesRegularExpression('/name="ffc_cf_23"[^>]*value="4321"/', $output);
		$this->assertStringNotContainsString('stale', $output);
		$reads = array_filter($this->meta_reads, static fn(array $r): bool => 'ffc_user_phone_ext' === $r[1]);
		$this->assertSame(array( array( 10, 'ffc_user_phone_ext' ) ), array_values($reads), 'The profile is read once, for the user shown.');
	}

	/**
	 * Another user's sensitive field on user-edit.php (#1863).
	 *
	 * @param bool $can_reveal Whether the viewer holds the audience PII cap.
	 * @return string The rendered section.
	 */
	private function render_another_users_sensitive_field( bool $can_reveal ): string {
		$user     = new \WP_User(10);
		$user->ID = 10;
		$this->audience(1, 'Staff');
		$this->member(10, 1);
		$this->field(1, array(
			'id' => 23, 'field_key' => 'rf', 'field_label' => 'RF', 'field_type' => 'text',
			'field_profile_key' => 'rf', 'is_sensitive' => 1, 'is_required' => 1,
		));
		$this->profile_value(10, 'rf', Identifiers::RF_A, true);
		Functions\when('esc_html_e')->alias(static function ($t) { echo $t; });
		Functions\when('wp_nonce_field')->justReturn('');
		Functions\when('wp_create_nonce')->justReturn('reveal-nonce');
		Functions\when('get_current_user_id')->justReturn(3);
		Functions\when('user_can')->alias(static fn($uid, $cap) => $can_reveal && 'ffc_view_audiences_pii' === $cap);

		ob_start();
		AdminUserCustomFields::render_section($user);
		return (string) ob_get_clean();
	}

	public function test_render_section_masks_another_users_sensitive_field_with_a_reveal_button(): void {
		$output = $this->render_another_users_sensitive_field(true);
		$masked = substr(Identifiers::RF_A, 0, 3) . '.***-' . substr(Identifiers::RF_A, -1);

		$this->assertStringNotContainsString(Identifiers::RF_A, $output, 'The clear value never reaches the HTML.');
		$this->assertMatchesRegularExpression('/name="ffc_cf_23"[^>]*value="' . preg_quote($masked, '/') . '"[^>]*data-ffc-pii-field="cf_23"[^>]*data-ffc-pii-editable="1" disabled/', $output);
		$this->assertStringContainsString('class="button button-small ffc-reveal-pii ffc-icon-eye" data-type="user_profile" data-field="cf_23" data-submission-id="10" data-nonce="reveal-nonce"', $output);
	}

	public function test_render_section_masks_another_users_sensitive_field_without_a_button_for_the_masked_tier(): void {
		$output = $this->render_another_users_sensitive_field(false);

		$this->assertStringNotContainsString(Identifiers::RF_A, $output);
		$this->assertStringNotContainsString('ffc-reveal-pii', $output);
		$this->assertStringContainsString('Masked. Revealing it requires the audience PII capability.', $output);
	}

	public function test_save_section_keeps_a_masked_sensitive_field_nobody_revealed(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_10']                    = 'Engineering';
		// ffc_cf_12 and ffc_cf_13 are absent: they stayed disabled, masked.

		$this->audience(1, 'Staff');
		$this->member(5, 1);
		$this->field(1, array( 'id' => 10, 'field_type' => 'text', 'field_label' => 'Department' ));
		$this->field(1, array( 'id' => 12, 'field_type' => 'text', 'field_label' => 'CPF', 'field_profile_key' => 'cpf', 'is_sensitive' => 1 ));
		$this->field(1, array( 'id' => 13, 'field_type' => 'text', 'field_label' => 'RG', 'field_key' => 'rg', 'is_sensitive' => 1 ));
		$this->meta[5][ CustomFieldReader::USER_META_KEY ] = array( 'field_13' => 'MG-1234' );
		$this->profile_value(5, 'cpf', Identifiers::CPF_A, true);

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);
		Functions\when('get_current_user_id')->justReturn(3);
		Functions\when('user_can')->justReturn(true);

		AdminUserCustomFields::save_section(5);

		// What was stored for the snapshot field stays; the posted field joins it.
		$this->assertSame(array( 'field_13' => 'MG-1234', 'field_10' => 'Engineering' ), $this->snapshot(5));
		$this->assertSame(array(), $this->profile_writes(5), 'The masked CPF is not written back.');
		$this->assertSame(array(), $this->profile_inserts);
	}

	public function test_sensitive_values_lists_only_sensitive_free_text_fields(): void {
		$this->audience(1, 'Staff');
		$this->member(5, 1);
		$this->field(1, array( 'id' => 10, 'field_type' => 'text', 'field_label' => 'Department', 'field_key' => 'dept' ));
		$this->field(1, array( 'id' => 12, 'field_type' => 'text', 'field_label' => 'CPF', 'field_profile_key' => 'cpf', 'is_sensitive' => 1 ));
		$this->field(1, array( 'id' => 14, 'field_type' => 'select', 'field_label' => 'Union', 'field_key' => 'sindicato', 'is_sensitive' => 1 ));
		$this->meta[5][ CustomFieldReader::USER_META_KEY ] = array( 'field_10' => 'Eng', 'field_14' => 'X' );
		$this->profile_value(5, 'cpf', Identifiers::CPF_A, true);

		$this->assertSame( array( 12 => Identifiers::CPF_A ), AdminUserCustomFields::sensitive_values(5) );
	}

	public function test_save_section_writes_a_sensitive_dependent_select_to_the_profile_as_sensitive(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_30']                    = '{"parent":"DIPED","child":"Sector A"}';

		$this->audience(1, 'Staff');
		$this->member(5, 1);
		$this->field(1, array(
			'id' => 30, 'field_type' => 'dependent_select', 'field_label' => 'Division / Department',
			'field_profile_key' => 'divisao_setor', 'is_sensitive' => 1, 'is_required' => 0,
			'field_options' => '{"groups":{"DIPED":["Sector A"]}}',
		));

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);

		AdminUserCustomFields::save_section(5);

		$this->assertSame(array(), $this->snapshot(5));
		$profile = $this->profile_writes(5);
		$this->assertSame(array( 'ffc_user_divisao_setor' ), array_keys($profile));
		// Sensitive: stored encrypted.
		$this->assertStringNotContainsString('DIPED', (string) $profile['ffc_user_divisao_setor']);
		$this->assertSame('{"parent":"DIPED","child":"Sector A"}', Encryption::decrypt((string) $profile['ffc_user_divisao_setor']));
	}

	public function test_save_section_stores_an_unmapped_dependent_select_in_the_snapshot(): void {
		$_POST['ffc_user_custom_fields_nonce'] = 'valid_nonce';
		$_POST['ffc_cf_30']                    = '{"parent":"DIPED","child":"Sector A"}';

		$this->audience(1, 'Staff');
		$this->member(5, 1);
		$this->field(1, array(
			'id' => 30, 'field_type' => 'dependent_select', 'field_label' => 'Division / Department',
			'is_required' => 0, 'field_options' => '{"groups":{"DIPED":["Sector A"]}}',
		));

		Functions\when('wp_verify_nonce')->justReturn(true);
		Functions\when('current_user_can')->justReturn(true);

		AdminUserCustomFields::save_section(5);

		$this->assertSame(array( 'field_30' => '{"parent":"DIPED","child":"Sector A"}' ), $this->snapshot(5));
		$this->assertSame(array(), $this->profile_writes(5));
	}

	/**
	 * Run render_save_notice() on a screen with the given transients.
	 *
	 * @param string               $screen_id  Current screen id.
	 * @param array<string, mixed> $transients Key => value.
	 * @return array{0: string, 1: array<int, string>} Output and deleted keys.
	 */
	private function save_notice_on( string $screen_id, array $transients ): array {
		$screen     = new \WP_Screen();
		$screen->id = $screen_id;
		Functions\when('get_current_screen')->justReturn($screen);
		Functions\when('get_current_user_id')->justReturn(3);
		Functions\when('get_transient')->alias(static fn($key) => $transients[ $key ] ?? false);
		// Core\AdminNotice prints through wp_admin_notice() (#1843).
		Functions\when('wp_admin_notice')->alias(
			static function ( $message, $args = array() ) {
				$wrap = ! array_key_exists( 'paragraph_wrap', $args ) || $args['paragraph_wrap'];
				echo '<div class="notice notice-' . $args['type'] . '">' . ( $wrap ? '<p>' . $message . '</p>' : $message ) . '</div>';
			}
		);
		$deleted = array();
		Functions\when('delete_transient')->alias(function ($key) use (&$deleted) {
			$deleted[] = $key;
			return true;
		});

		ob_start();
		AdminUserCustomFields::render_save_notice();
		return array( (string) ob_get_clean(), $deleted );
	}

	public function test_render_save_notice_stays_off_other_screens(): void {
		list( $html, $deleted ) = $this->save_notice_on('dashboard', array( 'ffc_cf_required_kept_3' => array( 'CPF' ) ));

		$this->assertSame('', $html);
		$this->assertSame(array(), $deleted, 'the notice is kept for the profile screen');
	}

	public function test_render_save_notice_names_the_kept_fields_and_the_incomplete_rows(): void {
		$GLOBALS['wp_locale'] = new class() {
			public function get_weekday( $day ) {
				return 'Weekday ' . $day;
			}
		};
		Functions\when('esc_html__')->returnArg();

		list( $html, $deleted ) = $this->save_notice_on(
			'user-edit',
			array(
				'ffc_cf_required_kept_3' => array( 'CPF', 'Date of Birth' ),
				'ffc_cf_wh_incomplete_3' => array(
					'not a row',
					array( 'label' => 'Hours', 'day' => 1, 'missing' => array( 'entry1', 'exit2' ) ),
					array( 'label' => 'Hours', 'day' => 9, 'missing' => 'none' ),
				),
			)
		);
		unset( $GLOBALS['wp_locale'] );

		$this->assertStringContainsString('kept their previous value: CPF, Date of Birth', $html);
		$this->assertStringContainsString('<li>Hours — Weekday 1: missing Entry 1, Exit 2</li><li>Hours — 9: missing </li>', $html);
		$this->assertSame(array( 'ffc_cf_required_kept_3', 'ffc_cf_wh_incomplete_3' ), $deleted);
	}

	public function test_render_save_notice_prints_nothing_for_rows_it_cannot_read(): void {
		list( $html, $deleted ) = $this->save_notice_on(
			'profile',
			array( 'ffc_cf_wh_incomplete_3' => array( 'not a row', 42 ) )
		);

		$this->assertSame('', $html);
		$this->assertSame(array( 'ffc_cf_wh_incomplete_3' ), $deleted, 'the unreadable notice is still consumed');
	}
}
