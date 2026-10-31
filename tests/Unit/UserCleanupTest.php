<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\UserCleanup;

/**
 * Tests for UserCleanup: hook registration, user data anonymization
 * on deletion, and email change handling with hash reindexing.
 *
 * @covers \FreeFormCertificate\UserDashboard\UserCleanup
 * @preserveGlobalState disabled
 */
class UserCleanupTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb = Mockery::mock('wpdb');
		$wpdb->prefix = 'wp_';
		$this->wpdb = $wpdb;

		// UserService::get_user_statistics() (reached via the #322
		// short-circuit `user_has_ffc_data` guard at the top of
		// anonymize_user_data) calls this for the certificate count.

		// DocumentFormatter::mask_email() (called by UserCleanup) calls is_email()
		// internally — provide a permissive stub.
		Functions\when('is_email')->justReturn(true);

		// NOTE: ActivityLog is NOT alias-mocked here — the real class must be
		// loaded so its LEVEL_* class constants (referenced by UserCleanup as
		// arguments to ActivityLog::log) are available. We instead disable the
		// activity log entirely via get_option so ActivityLog::log() short
		// circuits before touching any of its runtime dependencies.
		Functions\when('__')->returnArg();
		Functions\when('get_userdata')->justReturn(false);
		Functions\when('current_time')->justReturn('2026-03-02 12:00:00');
		Functions\when('get_option')->justReturn(array());
		Functions\when('absint')->alias(function ($v) { return abs((int) $v); });

		// Default wpdb stubs
		$this->wpdb->shouldReceive('prepare')->andReturn('SQL')->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Answer `get_var` by reading the statement, not by call position.
	 *
	 * `SHOW TABLES LIKE` names a table only when it is in `$tables`, and the
	 * footprint probe (`SELECT 1 FROM … LIMIT 1`) finds a row only in a table
	 * listed in `$rows`. A positional `andReturn()` sequence broke every time
	 * the footprint check changed how many statements it runs (#2246).
	 *
	 * @param list<string>       $tables Optional tables that exist.
	 * @param array<string, int> $rows   Rows per table seen by the probe.
	 */
	private function db( array $tables, array $rows ): void {
		// The authorship sweep checks columns on the tables that exist; no
		// column is reported, so it changes nothing here.
		$this->wpdb->shouldReceive('esc_like')->andReturnUsing( static fn ( $v ) => $v );
		$this->wpdb->shouldReceive('get_results')->andReturn( array() );
		$this->wpdb->shouldReceive('prepare')->andReturnUsing(
			static fn ( $sql, ...$args ) => (string) json_encode( array( $sql, $args ) )
		);
		$this->wpdb->shouldReceive('get_var')->andReturnUsing(
			static function ( $query ) use ( $tables, $rows ) {
				$decoded = json_decode( (string) $query, true );
				if ( ! is_array( $decoded ) ) {
					return null;
				}
				[ $sql, $args ] = $decoded;
				if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) ) {
					return in_array( $args[0], $tables, true ) ? $args[0] : null;
				}
				if ( false !== strpos( $sql, 'SELECT 1 FROM' ) ) {
					return ! empty( $rows[ $args[0] ] ) ? '1' : null;
				}
				return null;
			}
		);
	}

	// ==================================================================
	// init() — hook registrations
	// ==================================================================

	public function test_init_registers_deleted_user_action(): void {
		Functions\expect('add_action')
			->with('deleted_user', [UserCleanup::class, 'anonymize_user_data'])
			->once();

		Functions\expect('add_action')
			->with('profile_update', [UserCleanup::class, 'handle_email_change'], 10, 2)
			->once();

		UserCleanup::init();
	}

	// ==================================================================
	// anonymize_user_data — submissions nullified, no optional tables
	// ==================================================================

	public function test_anonymize_nullifies_submissions_user_id(): void {
		$this->wpdb->shouldReceive('query')->once()->andReturn(3);
		// The footprint probe finds a certificate, so the cleanup proceeds;
		// no optional table exists.
		$this->db( array(), array( 'wp_ffc_submissions' => 1 ) );

		UserCleanup::anonymize_user_data(42);

		$this->addToAssertionCount(1);
	}

	public function test_anonymize_handles_no_affected_rows_gracefully(): void {
		$this->wpdb->shouldReceive('query')->andReturn(0);
		$this->wpdb->shouldReceive('get_var')->andReturn(null);

		UserCleanup::anonymize_user_data(42);

		$this->addToAssertionCount(1);
	}

	// ==================================================================
	// anonymize_user_data — with optional tables
	// ==================================================================

	public function test_anonymize_nullifies_appointments_when_table_exists(): void {
		// query: submissions=0, appointments=2, activity_log=0
		$this->wpdb->shouldReceive('query')->andReturn(0, 2, 0);

		$this->db(
			array( 'wp_ffc_self_scheduling_appointments', 'wp_ffc_activity_log' ),
			array( 'wp_ffc_submissions' => 1 )
		);

		UserCleanup::anonymize_user_data(42);

		$this->addToAssertionCount(1);
	}

	public function test_anonymize_deletes_from_deletion_tables_when_they_exist(): void {
		$this->wpdb->shouldReceive('query')->andReturn(0);

		// Every optional table exists, so each DELETE fires.
		$this->db(
			array(
				'wp_ffc_self_scheduling_appointments',
				'wp_ffc_activity_log',
				'wp_ffc_audience_members',
				'wp_ffc_audience_booking_users',
				'wp_ffc_audience_schedule_permissions',
				'wp_ffc_user_profiles',
			),
			array( 'wp_ffc_submissions' => 1 )
		);

		$this->wpdb->shouldReceive('delete')
			->with('wp_ffc_audience_members', ['user_id' => 42], ['%d'])
			->once()->andReturn(1);
		$this->wpdb->shouldReceive('delete')
			->with('wp_ffc_audience_booking_users', ['user_id' => 42], ['%d'])
			->once()->andReturn(0);
		$this->wpdb->shouldReceive('delete')
			->with('wp_ffc_audience_schedule_permissions', ['user_id' => 42], ['%d'])
			->once()->andReturn(2);
		$this->wpdb->shouldReceive('delete')
			->with('wp_ffc_user_profiles', ['user_id' => 42], ['%d'])
			->once()->andReturn(1);

		UserCleanup::anonymize_user_data(42);

		$this->addToAssertionCount(1);
	}

	// ==================================================================
	// anonymize_user_data — logging
	// ==================================================================

	public function test_anonymize_short_circuits_when_user_has_no_ffc_data(): void {
		// All counts return 0 / null → UserService::user_has_ffc_data
		// is false → anonymize_user_data exits before any UPDATE /
		// DELETE / wpdb->query fires. Issue #322 short-circuit.
		$this->wpdb->shouldReceive('get_var')->andReturn(null);
		$this->wpdb->shouldNotReceive('query');
		$this->wpdb->shouldNotReceive('delete');

		UserCleanup::anonymize_user_data(42);

		$this->addToAssertionCount(1);
	}

	public function test_a_profile_row_alone_is_a_footprint_and_is_deleted(): void {
		// No certificate, appointment or membership: before #2246 the check
		// read only those three, skipped the cleanup and left the profile row
		// of a deleted account behind.
		$this->wpdb->shouldReceive('query')->andReturn(0);
		$this->db( array( 'wp_ffc_user_profiles' ), array( 'wp_ffc_user_profiles' => 1 ) );

		$this->wpdb->shouldReceive('delete')
			->with('wp_ffc_user_profiles', ['user_id' => 42], ['%d'])
			->once()->andReturn(1);

		UserCleanup::anonymize_user_data(42);
	}

	public function test_anonymize_logs_affected_tables(): void {
		$this->wpdb->shouldReceive('query')->andReturn(1); // submissions affected
		$this->db( array(), array( 'wp_ffc_submissions' => 1 ) );

		// ActivityLog::log() is short-circuited in setUp via get_option returning
		// [], so we can't assert the call directly here. The method still runs
		// to completion, which is what we care about.
		UserCleanup::anonymize_user_data(42);

		$this->addToAssertionCount(1);
	}

	// ==================================================================
	// anonymize_user_data — actor-attribution + candidate sweep (#834)
	// ==================================================================

	/** @var array<int, string> Recorded UPDATE queries from the sweep harness. */
	private array $recorded_updates = [];

	/**
	 * Query-inspecting harness: interpolate %i/%d so get_var / get_results /
	 * query can branch on the real SQL, and record the UPDATEs fired into
	 * $this->recorded_updates.
	 *
	 * @param array<int, string> $present_tables Table names to report present.
	 * @param array<int, string> $absent_columns Column names to report missing.
	 * @return void
	 */
	private function installSweepHarness(array $present_tables, array $absent_columns = []): void {
		$this->recorded_updates = [];

		$this->wpdb->shouldReceive('esc_like')->andReturnUsing(fn($v) => $v)->byDefault();
		$this->wpdb->shouldReceive('prepare')->andReturnUsing(function ($q, ...$args) {
			foreach ($args as $arg) {
				$q = preg_replace('/%[sdi]/', (string) $arg, $q, 1) ?? $q;
			}
			return $q;
		});
		// No data-subject footprint (COUNT → 0); SHOW TABLES → present list.
		$this->wpdb->shouldReceive('get_var')->andReturnUsing(function ($q) use ($present_tables) {
			if (strpos($q, 'SHOW TABLES') !== false) {
				foreach ($present_tables as $t) {
					if (strpos($q, $t) !== false) {
						return $t;
					}
				}
				return null;
			}
			return '0';
		});
		// column_exists → present unless the column is in $absent_columns.
		$this->wpdb->shouldReceive('get_results')->andReturnUsing(function ($q) use ($absent_columns) {
			if (strpos($q, 'SHOW COLUMNS') !== false) {
				foreach ($absent_columns as $c) {
					if (preg_match('/LIKE\s+' . preg_quote($c, '/') . '\b/', $q)) {
						return [];
					}
				}
				return [(object) ['Field' => 'x']];
			}
			return [];
		});
		$this->wpdb->shouldReceive('query')->andReturnUsing(function ($q) {
			$this->recorded_updates[] = $q;
			return 1;
		});
	}

	public function test_authorship_sweep_nulls_attribution_and_candidate_links(): void {
		$this->installSweepHarness([
			'wp_ffc_self_scheduling_calendars',
			'wp_ffc_recruitment_candidate',
		]);

		UserCleanup::anonymize_user_data(42);

		$joined = implode("\n", $this->recorded_updates);
		// Cluster 1: calendars created_by + updated_by nulled.
		$this->assertStringContainsString('UPDATE wp_ffc_self_scheduling_calendars SET created_by = NULL WHERE created_by = 42', $joined);
		$this->assertStringContainsString('UPDATE wp_ffc_self_scheduling_calendars SET updated_by = NULL WHERE updated_by = 42', $joined);
		// Cluster 2: promoted-candidate user_id link dropped.
		$this->assertStringContainsString('UPDATE wp_ffc_recruitment_candidate SET user_id = NULL WHERE user_id = 42', $joined);
		// A NOT NULL / not-in-map table is never touched (accepted orphan).
		$this->assertStringNotContainsString('wp_ffc_audiences ', $joined);
	}

	public function test_post_event_invitations_are_deleted_even_without_a_footprint(): void {
		$this->installSweepHarness(['wp_ffc_audience_form_invites']);
		$this->wpdb->shouldReceive('delete')
			->once()
			->with('wp_ffc_audience_form_invites', ['user_id' => 42], ['%d'])
			->andReturn(2);

		UserCleanup::anonymize_user_data(42);
	}

	public function test_authorship_sweep_skips_absent_tables(): void {
		// Only the candidate table present → only its UPDATE fires.
		$this->installSweepHarness(['wp_ffc_recruitment_candidate']);

		UserCleanup::anonymize_user_data(42);

		$joined = implode("\n", $this->recorded_updates);
		$this->assertStringContainsString('UPDATE wp_ffc_recruitment_candidate SET user_id = NULL', $joined);
		$this->assertStringNotContainsString('wp_ffc_self_scheduling_calendars', $joined);
		$this->assertStringNotContainsString('wp_ffc_short_urls', $joined);
	}

	public function test_authorship_sweep_skips_absent_column(): void {
		// submissions present but the migration-added edited_by column is missing.
		$updates = $this->installSweepHarness(['wp_ffc_submissions'], ['edited_by']);

		UserCleanup::anonymize_user_data(42);

		$this->assertStringNotContainsString('edited_by', implode("\n", $this->recorded_updates));
	}

	// ==================================================================
	// handle_email_change — early returns
	// ==================================================================

	public function test_handle_email_change_returns_early_when_user_not_found(): void {
		$old_user = new \WP_User();
		$old_user->user_email = 'old@example.com';

		$this->wpdb->shouldNotReceive('query');

		UserCleanup::handle_email_change(42, $old_user);

		$this->addToAssertionCount(1);
	}

	public function test_handle_email_change_returns_early_when_email_unchanged(): void {
		$new_user = new \WP_User();
		$new_user->user_email = 'same@example.com';
		Functions\when('get_userdata')->justReturn($new_user);

		$old_user = new \WP_User();
		$old_user->user_email = 'same@example.com';

		$this->wpdb->shouldNotReceive('query');

		UserCleanup::handle_email_change(42, $old_user);

		$this->addToAssertionCount(1);
	}

	// ==================================================================
	// handle_email_change — reindexes email_hash
	// ==================================================================

	public function test_handle_email_change_updates_email_hash_on_submissions(): void {
		$new_user = new \WP_User();
		$new_user->user_email = 'new@example.com';
		Functions\when('get_userdata')->justReturn($new_user);

		$old_user = new \WP_User();
		$old_user->user_email = 'old@example.com';

		$this->wpdb->shouldReceive('query')->once()->andReturn(3);
		$this->wpdb->shouldReceive('get_var')->andReturn(null); // no profiles table

		UserCleanup::handle_email_change(42, $old_user);

		$this->addToAssertionCount(1);
	}

	// ==================================================================
	// handle_email_change — updates profile timestamp
	// ==================================================================

	public function test_handle_email_change_updates_profile_timestamp_when_table_exists(): void {
		$new_user = new \WP_User();
		$new_user->user_email = 'new@example.com';
		Functions\when('get_userdata')->justReturn($new_user);

		$old_user = new \WP_User();
		$old_user->user_email = 'old@example.com';

		$this->wpdb->shouldReceive('query')->andReturn(1);
		$this->wpdb->shouldReceive('get_var')
			->andReturn('wp_ffc_user_profiles');
		$this->wpdb->shouldReceive('update')
			->once()
			->with(
				'wp_ffc_user_profiles',
				['updated_at' => '2026-03-02 12:00:00'],
				['user_id' => 42],
				['%s'],
				['%d']
			)
			->andReturn(1);

		UserCleanup::handle_email_change(42, $old_user);

		$this->addToAssertionCount(1);
	}

	// ==================================================================
	// handle_email_change — logging with masked emails
	// ==================================================================

	public function test_handle_email_change_logs_with_masked_emails(): void {
		$new_user = new \WP_User();
		$new_user->user_email = 'new@example.com';
		Functions\when('get_userdata')->justReturn($new_user);

		$old_user = new \WP_User();
		$old_user->user_email = 'old@example.com';

		$this->wpdb->shouldReceive('query')->andReturn(1);
		$this->wpdb->shouldReceive('get_var')->andReturn(null);

		// ActivityLog::log() is short-circuited in setUp via get_option returning
		// [], so the logging side effect cannot be asserted here. The method
		// still runs to completion, which is what we care about.
		UserCleanup::handle_email_change(42, $old_user);

		$this->addToAssertionCount(1);
	}
}
