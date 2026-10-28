<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Services\UserService;
use FreeFormCertificate\UserDashboard\CapabilityManager;

/**
 * Tests for UserService: full profile retrieval, capabilities,
 * user statistics, personal data export, and FFC data presence check.
 *
 * Runs the real UserManager, UserProfileRepository, CapabilityManager,
 * Encryption and BirthDate over a `$wpdb` double: the `ffc_user_profiles`
 * row is what `get_row()` answers, `SHOW TABLES LIKE` answers from the
 * tables a test declares present, and each `COUNT(*)` answers from a count
 * per table. Every `prepare()` is recorded, so a test can assert which user
 * a query was asked about.
 *
 * @covers \FreeFormCertificate\Services\UserService
 */
class UserServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/**
	 * Every prepared statement, in order: [ sql, args ].
	 *
	 * @var list<array{0: string, 1: array<int, mixed>}>
	 */
	private array $prepared = [];

	/**
	 * Tables that `SHOW TABLES LIKE` reports as present.
	 *
	 * @var list<string>
	 */
	private array $tables = [];

	/**
	 * What a `COUNT(*)` answers, by table.
	 *
	 * @var array<string, string>
	 */
	private array $counts = [];

	/** The stored `ffc_user_profiles` row, or null for none. */
	private ?array $profile_row = null;

	/**
	 * User meta by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta = [];

	/**
	 * Arguments of each get_user_meta() call.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $meta_reads = [];

	/**
	 * The `$wpdb` global before the test, restored in tearDown().
	 *
	 * @var mixed
	 */
	private $previous_wpdb;

	/** Whether `$wpdb` was set before the test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->prepared    = [];
		$this->tables      = [];
		$this->counts      = [];
		$this->profile_row = null;
		$this->meta        = [];
		$this->meta_reads  = [];

		$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;

		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$this->prepared[] = array( (string) $sql, array_values( $args ) );
				return 'Q' . ( count( $this->prepared ) - 1 );
			}
		);
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $query ) {
				[ $sql, $args ] = $this->statement( $query );
				if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) ) {
					return in_array( $args[0], $this->tables, true ) ? $args[0] : null;
				}
				if ( false !== strpos( $sql, 'COUNT(*)' ) ) {
					return $this->counts[ $args[0] ?? '' ] ?? null;
				}
				return null;
			}
		);
		$this->wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $query ) {
				[ , $args ] = $this->statement( $query );
				return 'wp_ffc_user_profiles' === ( $args[0] ?? null ) ? $this->profile_row : null;
			}
		);
		$GLOBALS['wpdb'] = $this->wpdb;

		// Default WP stubs
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );
		Functions\when( 'sanitize_key' )->alias(
			static fn( $key ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) )
		);
		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id, $key = '', $single = false ) {
				$this->meta_reads[] = array( $user_id, $key, $single );
				return $this->meta[ $key ] ?? '';
			}
		);
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The prepared statement behind a token the double handed out.
	 *
	 * @param mixed $query Token returned by prepare().
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function statement( $query ): array {
		$index = (int) substr( (string) $query, 1 );
		return $this->prepared[ $index ] ?? array( (string) $query, array() );
	}

	/**
	 * The user ids the profile table was read for.
	 *
	 * @return list<mixed>
	 */
	private function profile_reads(): array {
		$ids = array();
		foreach ( $this->prepared as [ $sql, $args ] ) {
			if ( 'wp_ffc_user_profiles' === ( $args[0] ?? null ) && false !== strpos( $sql, 'WHERE user_id = %d' ) ) {
				$ids[] = $args[1];
			}
		}
		return $ids;
	}

	/**
	 * Declare a stored profile row for the user.
	 *
	 * @param array<string, mixed> $columns Columns that differ from an empty row.
	 */
	private function storeProfile( int $user_id, array $columns ): void {
		$this->tables[]    = 'wp_ffc_user_profiles';
		$this->profile_row = array_merge(
			array(
				'user_id'      => (string) $user_id,
				'display_name' => null,
				'phone'        => null,
				'department'   => null,
				'organization' => null,
				'notes'        => null,
				'preferences'  => null,
				'created_at'   => '2024-01-01 00:00:00',
				'updated_at'   => '2024-01-01 00:00:00',
			),
			$columns
		);
	}

	private function makeWpUser(array $overrides = []): \WP_User {
		$user = new \WP_User();
		$user->display_name = $overrides['display_name'] ?? 'Test User';
		$user->user_email = $overrides['user_email'] ?? 'test@example.com';
		$user->user_registered = $overrides['user_registered'] ?? '2024-01-01 00:00:00';
		$user->roles = $overrides['roles'] ?? ['ffc_end_user'];
		return $user;
	}

	// ==================================================================
	// get_full_profile — user not found
	// ==================================================================

	public function test_get_full_profile_returns_null_when_user_not_found(): void {
		$result = UserService::get_full_profile(999);

		$this->assertNull($result);
	}

	// ==================================================================
	// get_full_profile — basic profile (no FFC profile row)
	// ==================================================================

	public function test_get_full_profile_returns_core_data(): void {
		$user = $this->makeWpUser([
			'display_name' => 'John Doe',
			'user_email' => 'john@example.com',
			'user_registered' => '2024-01-15 10:30:00',
			'roles' => ['ffc_end_user'],
		]);
		Functions\when('get_userdata')->justReturn($user);

		$result = UserService::get_full_profile(42);

		$this->assertIsArray($result);
		$this->assertSame(42, $result['user_id']);
		$this->assertSame('John Doe', $result['display_name']);
		$this->assertSame('john@example.com', $result['email']);
		$this->assertSame('2024-01-15 10:30:00', $result['member_since']);
		$this->assertSame(['ffc_end_user'], $result['roles']);
		$this->assertArrayHasKey('capabilities', $result);
	}

	// ==================================================================
	// get_full_profile — with FFC profile data from the profile table
	// ==================================================================

	public function test_get_full_profile_merges_ffc_profile_data(): void {
		$user = $this->makeWpUser(['display_name' => 'Maria Silva']);
		Functions\when('get_userdata')->justReturn($user);

		$this->storeProfile(42, [
			'phone' => '+55 11 99999-0000',
			'department' => 'Engineering',
			'organization' => 'Acme Corp',
			'notes' => 'VIP user',
		]);

		$result = UserService::get_full_profile(42);

		$this->assertSame([42], array_values(array_unique($this->profile_reads())), 'The profile row is read for the requested user only.');
		$this->assertSame('+55 11 99999-0000', $result['phone']);
		$this->assertSame('Engineering', $result['department']);
		$this->assertSame('Acme Corp', $result['organization']);
		$this->assertSame('VIP user', $result['notes']);
	}

	/**
	 * The canonical birth date (#1538) reaches the profile in ISO form, and a
	 * stored value that is not a date reaches it as '' rather than as garbage.
	 */
	public function test_get_full_profile_carries_the_canonical_birth_date(): void {
		Functions\when('get_userdata')->justReturn($this->makeWpUser());

		$this->meta['ffc_user_birth_date'] = (string) Encryption::encrypt('20/05/1990');
		$this->assertSame('1990-05-20', UserService::get_full_profile(42)['birth_date']);

		$this->meta['ffc_user_birth_date'] = (string) Encryption::encrypt('not a date');
		$this->assertSame('', UserService::get_full_profile(42)['birth_date']);

		$this->assertSame(
			[[42, 'ffc_user_birth_date', true], [42, 'ffc_user_birth_date', true]],
			$this->meta_reads,
			'The birth date is read from the requested user\'s meta, once per profile.'
		);
	}

	public function test_get_full_profile_defaults_missing_ffc_fields_to_empty_string(): void {
		$user = $this->makeWpUser();
		Functions\when('get_userdata')->justReturn($user);

		$this->storeProfile(10, []); // no phone, department, etc.

		$result = UserService::get_full_profile(10);

		$this->assertSame([10], array_values(array_unique($this->profile_reads())));
		$this->assertSame('', $result['phone']);
		$this->assertSame('', $result['department']);
		$this->assertSame('', $result['organization']);
		$this->assertSame('', $result['notes']);
	}

	public function test_get_full_profile_overrides_display_name_when_ffc_profile_sets_one(): void {
		$user = $this->makeWpUser(['display_name' => 'John from WP']);
		Functions\when('get_userdata')->justReturn($user);

		$this->storeProfile(42, ['display_name' => 'John from FFC']);

		$result = UserService::get_full_profile(42);

		$this->assertSame('John from FFC', $result['display_name']);
	}

	public function test_get_full_profile_keeps_wp_display_name_when_ffc_value_empty(): void {
		$user = $this->makeWpUser(['display_name' => 'John from WP']);
		Functions\when('get_userdata')->justReturn($user);

		$this->storeProfile(42, ['display_name' => '']);

		$result = UserService::get_full_profile(42);

		$this->assertSame('John from WP', $result['display_name']);
	}

	public function test_get_full_profile_surfaces_preferences_blob_when_present(): void {
		$user = $this->makeWpUser();
		Functions\when('get_userdata')->justReturn($user);

		$this->storeProfile(42, ['preferences' => '{"theme":"dark"}']);

		$result = UserService::get_full_profile(42);

		$this->assertSame('{"theme":"dark"}', $result['preferences']);
	}

	/**
	 * UserManager::get_profile() always carries the `preferences` key — null
	 * when the profile stores none — so the profile passes that null through
	 * rather than inventing a value.
	 */
	public function test_get_full_profile_carries_null_preferences_when_none_stored(): void {
		$user = $this->makeWpUser();
		Functions\when('get_userdata')->justReturn($user);

		$this->storeProfile(42, []);

		$result = UserService::get_full_profile(42);

		$this->assertArrayHasKey('preferences', $result);
		$this->assertNull($result['preferences']);
	}

	// ==================================================================
	// get_user_capabilities
	// ==================================================================

	public function test_get_user_capabilities_returns_cap_status_map(): void {
		$all     = CapabilityManager::get_all_capabilities();
		$granted = [$all[0], $all[1]];

		Functions\when('user_can')->alias(function ($uid, $cap) use ($granted) {
			return 42 === $uid && in_array($cap, $granted, true);
		});

		$result = UserService::get_user_capabilities(42);

		$this->assertCount(count(array_unique($all)), $result);
		$this->assertTrue($result[$all[0]]);
		$this->assertTrue($result[$all[1]]);
		$this->assertFalse($result[$all[2]]);
	}

	public function test_get_user_capabilities_reports_every_cap_false_when_user_holds_none(): void {
		// Default: user_can returns false (from setUp)
		$result = UserService::get_user_capabilities(42);

		$this->assertSame(
			array_fill_keys(CapabilityManager::get_all_capabilities(), false),
			$result
		);
	}

	// ==================================================================
	// get_user_statistics
	// ==================================================================

	public function test_get_user_statistics_returns_certificate_count(): void {
		$this->counts['wp_ffc_submissions'] = '5';

		$result = UserService::get_user_statistics(42);

		$this->assertSame(5, $result['certificates']);
	}

	public function test_get_user_statistics_counts_appointments_when_table_exists(): void {
		$this->counts['wp_ffc_submissions']                  = '0';
		$this->tables[]                                      = 'wp_ffc_self_scheduling_appointments';
		$this->counts['wp_ffc_self_scheduling_appointments'] = '3';

		$result = UserService::get_user_statistics(42);

		$this->assertSame(3, $result['appointments']);
	}

	public function test_get_user_statistics_counts_audience_groups_when_table_exists(): void {
		$this->counts['wp_ffc_submissions']      = '0';
		$this->tables[]                          = 'wp_ffc_audience_members';
		$this->counts['wp_ffc_audience_members'] = '5';

		$result = UserService::get_user_statistics(42);

		$this->assertSame(5, $result['audience_groups']);
	}

	public function test_get_user_statistics_returns_all_zeros_when_no_data(): void {
		$this->counts['wp_ffc_submissions'] = '0';

		$result = UserService::get_user_statistics(42);

		$this->assertSame(0, $result['certificates']);
		$this->assertSame(0, $result['appointments']);
		$this->assertSame(0, $result['audience_groups']);
	}

	// ==================================================================
	// user_has_ffc_data
	// ==================================================================

	public function test_user_has_ffc_data_returns_false_when_all_stats_zero(): void {
		$this->counts['wp_ffc_submissions'] = '0';

		$this->assertFalse(UserService::user_has_ffc_data(42));
	}

	public function test_user_has_ffc_data_returns_true_when_certificates_exist(): void {
		$this->counts['wp_ffc_submissions'] = '3';

		$this->assertTrue(UserService::user_has_ffc_data(42));
	}

	public function test_user_has_ffc_data_returns_true_when_appointments_exist(): void {
		$this->counts['wp_ffc_submissions']                  = '0';
		$this->tables[]                                      = 'wp_ffc_self_scheduling_appointments';
		$this->counts['wp_ffc_self_scheduling_appointments'] = '1';

		$this->assertTrue(UserService::user_has_ffc_data(42));
	}

	// ==================================================================
	// export_personal_data
	// ==================================================================

	public function test_export_personal_data_includes_profile_when_user_exists(): void {
		$user = $this->makeWpUser(['display_name' => 'Export User']);
		Functions\when('get_userdata')->justReturn($user);

		$this->counts['wp_ffc_submissions'] = '0';

		$result = UserService::export_personal_data(42);

		$this->assertArrayHasKey('profile', $result);
		$this->assertSame(42, $result['profile']['user_id']);
		$this->assertArrayHasKey('statistics', $result);
	}

	public function test_export_personal_data_omits_profile_when_user_not_found(): void {
		$this->counts['wp_ffc_submissions'] = '0';

		$result = UserService::export_personal_data(999);

		$this->assertArrayNotHasKey('profile', $result);
		$this->assertArrayHasKey('statistics', $result);
	}

	public function test_export_personal_data_always_includes_statistics(): void {
		$this->counts['wp_ffc_submissions'] = '0';

		$result = UserService::export_personal_data(1);

		$this->assertArrayHasKey('statistics', $result);
		$this->assertIsArray($result['statistics']);
	}
}
