<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\MigrationRegistry;

/**
 * Tests for MigrationRegistry: centralized registry for all available migrations.
 *
 * @covers \FreeFormCertificate\Migrations\MigrationRegistry
 */
class MigrationRegistryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Global WP stubs
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->alias( function() { $args = func_get_args(); return $args[1] ?? null; } );

		// Namespaced stubs: FreeFormCertificate\Migrations\*
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->alias( function() { $args = func_get_args(); return $args[1] ?? null; } );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// Constructor / register_migrations
	// ==================================================================

	public function test_constructor_registers_default_migrations(): void {
		$registry = new MigrationRegistry();

		$all = $registry->get_all_migrations();

		$this->assertIsArray( $all );
		$this->assertNotEmpty( $all );
	}

	// ==================================================================
	// get_all_migrations
	// ==================================================================

	public function test_get_all_migrations_returns_default_migrations(): void {
		$registry = new MigrationRegistry();

		$all = $registry->get_all_migrations();

		$this->assertIsArray( $all );
		// Sixteen once the five one-shot cards of #2115 left in 6.45.0 (#2552);
		// twenty-one before that. Nineteen since the recruitment PCD-per-notice
		// move joined (#2245),
		// eighteen once the reregistration draft encryption did (#2242),
		// seventeen once the refused-address cleanup did (#2109), sixteen
		// once the rate-limit log address encryption did (#2109).
		// Fourteen once the activity-log IP encryption joined (#1574),
		// thirteen once the name-parts backfill did (#1552), twelve once the
		// birth-date backfill did (#1538), both in 6.33.0. Eleven
		// from 6.30.0, when the display-name backfill joined the card list
		// (#1480); it was ten from 6.28.2, when the certificate-capability
		// backfill did (#1345). The count is asserted as HISTORY in the comment
		// and as a live number here on purpose -- every key below is named too,
		// so a card arriving without a test failing is what this guards against;
		// bump the number and name the new key together.
		$this->assertCount( 16, $all );
		// #2502: the answers under the folded per-audience copies of the
		// reregistration fields move onto the inherited field.
		$this->assertArrayHasKey( 'custom_field_answers', $all );
		$this->assertArrayHasKey( 'rate_limit_keyed_identifiers', $all );
		$this->assertArrayHasKey( 'recruitment_pcd_per_notice', $all );
		$this->assertArrayHasKey( 'key_rotation', $all );
		// The rotation's second pass, over the areas the first never covered
		// (#1236). It is a SEPARATE migration, not new targets on `key_rotation`,
		// because that one latches a `completed` boolean and short-circuits the
		// status before looking at any table -- adding targets there would report
		// "complete" without ever touching them.
		$this->assertArrayHasKey( 'key_rotation_remaining', $all );
		// The card that rewrites stored identifiers into their canonical form
		// (#1313). Separate from the e-mail rehash card that preceded it (#2115):
		// that one latched complete, and it repaired a SALT rather than the
		// string the salt was applied to.
		$this->assertArrayHasKey( 'identity_normalization', $all );
		// The repair for accounts that own a certificate and hold no capability
		// to read it -- the consequence of adoption linking a row without
		// granting what reads it (#1345).
		$this->assertArrayHasKey( 'certificate_capability_backfill', $all );
		// The repair for accounts a candidacy promotion created with no name at
		// all: it passed an empty answers array, so WordPress stored the login
		// as the display name (#1480).
		$this->assertArrayHasKey( 'display_name_backfill', $all );
		// The copy of birth dates already given through a reregistration onto
		// the canonical profile field (#1538).
		$this->assertArrayHasKey( 'birth_date_backfill', $all );
		// The split of full names stored whole in `first_name` (#1552).
		$this->assertArrayHasKey( 'name_parts_backfill', $all );
		// The encryption of sensitive answers drafts stored in clear (#2242).
		$this->assertArrayHasKey( 'rereg_draft_encryption', $all );
		// The encryption of client IPs older rows still hold in clear (#1574).
		$this->assertArrayHasKey( 'activity_log_encrypt_ip', $all );
		// The deletion of the debug rows that logged visitors' positions (#2040).
		$this->assertArrayHasKey( 'activity_log_location_cleanup', $all );
		// The plaintext addresses of the rate-limit log (#2109).
		$this->assertArrayHasKey( 'rate_limit_logs_encrypt_ip', $all );
		// The plaintext address geofence refusals copied into their context (#2109).
		$this->assertArrayHasKey( 'activity_log_access_denied_ip', $all );
		$this->assertArrayHasKey( 'identity_index_backfill', $all );
	}

	public function test_identity_index_backfill_migration_has_expected_keys(): void {
		$registry  = new MigrationRegistry();
		$migration = $registry->get_all_migrations()['identity_index_backfill'];

		foreach ( array( 'name', 'description', 'icon', 'batch_size', 'order' ) as $key ) {
			$this->assertArrayHasKey( $key, $migration, "Missing expected key: {$key}" );
		}

		$this->assertSame( 50, $migration['batch_size'] );

		// It must be ordered AFTER the canonicalisation card it refuses to run
		// before, so the Migrations tab lists them in the order they can
		// actually be run. The gate itself is in the strategy's `can_run()`;
		// this only stops the screen from reading as though the order were
		// free.
		$this->assertGreaterThan(
			$registry->get_all_migrations()['identity_normalization']['order'],
			$migration['order'],
			'The backfill is listed before the card it depends on, so an operator meets them in an order neither can satisfy.'
		);
	}

	// ==================================================================
	// get_migration
	// ==================================================================

	public function test_get_migration_returns_correct_config_for_key_rotation(): void {
		$registry  = new MigrationRegistry();
		$migration = $registry->get_migration( 'key_rotation' );

		$this->assertIsArray( $migration );
		$this->assertSame( 'Encryption Key Rotation', $migration['name'] );
		$this->assertSame( 100, $migration['batch_size'] );
		$this->assertSame( 4, $migration['order'] );
	}

	public function test_get_migration_returns_null_for_nonexistent_key(): void {
		$registry = new MigrationRegistry();

		$this->assertNull( $registry->get_migration( 'nonexistent_migration' ) );
	}

	// ==================================================================
	// exists
	// ==================================================================

	public function test_exists_returns_true_for_key_rotation(): void {
		$registry = new MigrationRegistry();

		$this->assertTrue( $registry->exists( 'key_rotation' ) );
	}

	public function test_exists_returns_false_for_nonexistent_key(): void {
		$registry = new MigrationRegistry();

		$this->assertFalse( $registry->exists( 'nonexistent_migration' ) );
	}

	// ==================================================================
	// is_available
	// ==================================================================

	public function test_is_available_delegates_to_exists_and_returns_true(): void {
		$registry = new MigrationRegistry();

		// is_available should return true for an existing migration, same as exists
		$this->assertSame(
			$registry->exists( 'key_rotation' ),
			$registry->is_available( 'key_rotation' )
		);
		$this->assertTrue( $registry->is_available( 'key_rotation' ) );
	}

	public function test_is_available_delegates_to_exists_and_returns_false(): void {
		$registry = new MigrationRegistry();

		// is_available should return false for a nonexistent migration, same as exists
		$this->assertSame(
			$registry->exists( 'nonexistent_migration' ),
			$registry->is_available( 'nonexistent_migration' )
		);
		$this->assertFalse( $registry->is_available( 'nonexistent_migration' ) );
	}

	// ==================================================================
	// apply_filters hook
	// ==================================================================

	public function test_apply_filters_is_called_during_registration(): void {
		// Reset Brain\Monkey so we can set up expectations
		Monkey\tearDown();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();

		// Expect apply_filters to be called with the registry filter hook
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'ffcertificate_migrations_registry', Mockery::type( 'array' ) )
			->andReturnUsing( function( $hook, $migrations ) {
				return $migrations;
			} );

		$registry = new MigrationRegistry();

		// Verify the registry still works correctly after filter pass-through
		$this->assertArrayHasKey( 'key_rotation', $registry->get_all_migrations() );
	}

	public function test_apply_filters_allows_adding_custom_migrations(): void {
		// Reset Brain\Monkey so we can set up expectations
		Monkey\tearDown();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();

		// Simulate a plugin adding a custom migration via the filter
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'ffcertificate_migrations_registry', Mockery::type( 'array' ) )
			->andReturnUsing( function( $hook, $migrations ) {
				$migrations['custom_migration'] = array(
					'name'            => 'Custom Migration',
					'description'     => 'A custom migration added by a plugin',
					'icon'            => 'ffc-icon-custom',
					'batch_size'      => 25,
					'order'           => 2,
				);
				return $migrations;
			} );

		$registry = new MigrationRegistry();
		$all      = $registry->get_all_migrations();

		// Both the default and custom migration should be present
		$this->assertArrayHasKey( 'custom_migration', $all );
		$this->assertSame( 'Custom Migration', $all['custom_migration']['name'] );
	}

	/**
	 * No card carries a removal release once the #2115 cycle closed: the five
	 * one-shot cards it deprecated left in 6.45.0 (#2552). A card deprecated
	 * later names its release here, beside the `@removal` marker.
	 */
	public function test_no_card_names_a_removal_release(): void {
		$registry = new MigrationRegistry();
		$removals = array();
		foreach ( $registry->get_all_migrations() as $key => $migration ) {
			if ( isset( $migration['removal'] ) ) {
				$removals[ $key ] = $migration['removal'];
			}
		}

		$this->assertSame( array(), $removals );
	}
}
