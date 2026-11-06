<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\ActivityLogItems;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use PHPUnit\Framework\TestCase;

/**
 * The activity log's items, the action-to-item map and the one-shot move
 * from the old categories (#2340).
 *
 * @covers \FreeFormCertificate\Core\ActivityLogItems
 */
class ActivityLogItemsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Every action the log screen has a label for: the plugin's known actions
	 * (`ActivityLogActionLabelsTest` holds that list to the written ones).
	 *
	 * @return array<int, string>
	 */
	private static function known_actions(): array {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/admin/class-ffc-admin-activity-log-page.php' );
		$after  = explode( 'function get_action_label(', $source );
		self::assertCount( 2, $after, 'AdminActivityLogPage no longer declares get_action_label().' );
		$map = explode( '$labels = array(', $after[1] );
		preg_match_all( "/'([a-z0-9_]+)'\s*=>/", explode( "\t\t);", $map[1] )[0], $m );
		self::assertGreaterThan( 50, count( $m[1] ), 'The label map read collapsed.' );

		return $m[1];
	}

	public function test_no_known_action_reaches_the_fallback_unnamed(): void {
		$unplaced = array();
		foreach ( self::known_actions() as $action ) {
			if ( ActivityLogItems::FALLBACK === ActivityLogItems::for_action( $action ) && ! isset( ActivityLogItems::ACTIONS[ $action ] ) ) {
				$unplaced[] = $action;
			}
		}

		$this->assertSame( array(), $unplaced, "These actions land in the fallback item by default; name each in ActivityLogItems::ACTIONS or give it a prefix rule:\n  " . implode( "\n  ", $unplaced ) );
	}

	/**
	 * A toggle with no event behind it does nothing. Every item must receive
	 * at least one known action, or it does not ship (#2340).
	 */
	public function test_every_item_receives_at_least_one_known_action(): void {
		$reached = array();
		foreach ( self::known_actions() as $action ) {
			$reached[ ActivityLogItems::for_action( $action ) ] = true;
		}

		$empty = array_values( array_diff( array_keys( ActivityLogItems::ITEMS ), array_keys( $reached ) ) );
		$this->assertSame( array(), $empty, 'These items receive no known action, so their toggle would do nothing: ' . implode( ', ', $empty ) );
	}

	public function test_the_maps_name_only_items_that_exist(): void {
		foreach ( array_merge( ActivityLogItems::ACTIONS, ActivityLogItems::PREFIXES, array( ActivityLogItems::FALLBACK ) ) as $item ) {
			$this->assertArrayHasKey( $item, ActivityLogItems::ITEMS );
		}
	}

	public function test_every_item_and_section_has_a_label(): void {
		$labels = ActivityLogItems::labels();
		foreach ( ActivityLogItems::ITEMS as $item => $definition ) {
			$this->assertArrayHasKey( $item, $labels['items'] );
			$this->assertArrayHasKey( $definition['section'], $labels['sections'] );
		}
		$this->assertCount( count( ActivityLogItems::ITEMS ), $labels['items'], 'A label names an item that does not exist.' );
	}

	public function test_only_secondary_items_carry_a_legacy_category(): void {
		foreach ( ActivityLogItems::ITEMS as $item => $definition ) {
			$this->assertSame( ActivityLogItems::GROUP_SECONDARY === $definition['group'], isset( $definition['legacy'] ), $item );
		}
	}

	public function test_the_test_support_switches_every_item_on(): void {
		foreach ( array_keys( ActivityLogItems::ITEMS ) as $item ) {
			$this->assertSame( 1, ActivityLogBuffer::ENABLED_SETTINGS[ ActivityLogItems::setting_key( $item ) ] ?? null, $item );
		}
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function resolutions(): array {
		return array(
			'a reveal by its suffix'      => array( 'reregistration_pii_revealed', 'pii_reveal' ),
			'a new reveal type'           => array( 'brand_new_thing_revealed', 'pii_reveal' ),
			'an identity tool by prefix'  => array( 'identity_accounts_merged', 'identity_tools' ),
			'a named action over prefix'  => array( 'appointment_bulk_purged', 'data_deletion' ),
			'an appointment by prefix'    => array( 'appointment_created', 'sched_appointments' ),
			'a recruitment import'        => array( 'recruitment_csv_imported', 'recruit_imports' ),
			'other recruitment by prefix' => array( 'recruitment_call_created', 'recruit_calls' ),
			'a permission change'         => array( 'user_capabilities_changed', 'user_permissions' ),
			'the Danger Zone setting'     => array( 'dangerzone_setting_changed', 'destructive_settings' ),
			'an unknown action'           => array( 'totally_unknown_action', 'system_errors' ),
		);
	}

	/**
	 * @dataProvider resolutions
	 */
	public function test_for_action_resolves_by_name_then_suffix_then_prefix( string $action, string $item ): void {
		$this->assertSame( $item, ActivityLogItems::for_action( $action ) );
	}

	public function test_audit_items_default_on_and_secondary_off(): void {
		$this->assertTrue( ActivityLogItems::default_for( 'pii_reveal' ) );
		$this->assertTrue( ActivityLogItems::is_audit( 'destructive_settings' ) );
		$this->assertFalse( ActivityLogItems::default_for( 'cert_submissions' ) );
		$this->assertFalse( ActivityLogItems::is_audit( 'not_an_item' ) );
	}

	// ------------------------------------------------------------------
	// migrate()
	// ------------------------------------------------------------------

	public function test_a_fresh_install_gets_the_log_on_and_the_secondary_items_off(): void {
		$migrated = ActivityLogItems::migrate( array() );

		$this->assertIsArray( $migrated );
		$this->assertSame( 1, $migrated['enable_activity_log'] );
		$this->assertSame( 0, $migrated['activity_log_item_cert_submissions'] );
		$this->assertArrayNotHasKey( 'activity_log_item_pii_reveal', $migrated, 'Audit items keep their default.' );
		$this->assertSame( ActivityLogItems::VERSION, $migrated[ ActivityLogItems::MIGRATED_KEY ] );
	}

	public function test_a_log_that_was_on_keeps_its_never_saved_categories_on(): void {
		$migrated = ActivityLogItems::migrate( array( 'enable_activity_log' => 1 ) );

		foreach ( ActivityLogItems::keys_of( ActivityLogItems::GROUP_SECONDARY ) as $item ) {
			$this->assertSame( 1, $migrated[ ActivityLogItems::setting_key( $item ) ], $item );
		}
	}

	public function test_a_saved_category_carries_onto_every_item_it_split_into(): void {
		$migrated = ActivityLogItems::migrate(
			array(
				'enable_activity_log'          => 1,
				'activity_log_cat_users'       => 0,
				'activity_log_cat_submissions' => 1,
			)
		);

		$this->assertSame( 0, $migrated['activity_log_item_users_accounts'] );
		$this->assertSame( 0, $migrated['activity_log_item_users_privacy'] );
		$this->assertSame( 1, $migrated['activity_log_item_cert_submissions'] );
		// Reregistration fell back to the submissions category before.
		$this->assertSame( 1, $migrated['activity_log_item_rereg_reviews'] );
		$this->assertArrayNotHasKey( 'activity_log_cat_users', $migrated, 'The category keys are dropped.' );
		$this->assertArrayNotHasKey( 'activity_log_cat_submissions', $migrated );
	}

	public function test_a_log_that_was_off_starts_its_secondary_items_off_and_switches_on(): void {
		$migrated = ActivityLogItems::migrate(
			array(
				'enable_activity_log'          => 0,
				'activity_log_cat_submissions' => 1,
			)
		);

		$this->assertSame( 1, $migrated['enable_activity_log'], 'On on upgrade too.' );
		$this->assertSame( 0, $migrated['activity_log_item_cert_submissions'], 'A log that was off recorded nothing.' );
	}

	public function test_an_item_already_stored_is_left_as_it_is(): void {
		$migrated = ActivityLogItems::migrate(
			array(
				'enable_activity_log'                => 1,
				'activity_log_item_cert_submissions' => 0,
			)
		);

		$this->assertSame( 0, $migrated['activity_log_item_cert_submissions'] );
	}

	public function test_it_runs_once(): void {
		$migrated = ActivityLogItems::migrate( array() );

		$this->assertNull( ActivityLogItems::migrate( $migrated ) );
		$this->assertNull(
			ActivityLogItems::migrate(
				array(
					ActivityLogItems::MIGRATED_KEY => ActivityLogItems::VERSION,
					'enable_activity_log'          => 0,
				)
			),
			'A log switched off after the move stays off.'
		);
	}

	public function test_maybe_migrate_writes_only_when_there_is_something_to_do(): void {
		$stored = array();
		Functions\when( 'get_option' )->alias( static fn() => $stored );
		$writes = array();
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$writes, &$stored ) {
				$writes[] = $key;
				$stored   = $value;
				return true;
			}
		);

		ActivityLogItems::maybe_migrate();
		$this->assertSame( array( 'ffc_settings' ), $writes );

		Functions\when( 'get_option' )->alias( static fn() => $stored );
		ActivityLogItems::maybe_migrate();
		$this->assertSame( array( 'ffc_settings' ), $writes, 'The second run writes nothing.' );
	}
}
