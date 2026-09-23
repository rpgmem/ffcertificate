<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\IdentityResolutionPage;
use FreeFormCertificate\UserDashboard\CapabilityCatalog;
use FreeFormCertificate\UserDashboard\CapabilityManager;
use FreeFormCertificate\UserDashboard\CapabilityMigrator;

/**
 * Splitting and merging get capabilities of their own (#1397).
 *
 * WHAT MAKES THIS DANGEROUS IS NOT THE GATE, IT IS THE GRANT.
 *
 * Adding a capability to an action that had none REVOKES it from everybody,
 * silently: the screen simply stops offering the button, and nobody is told
 * that what worked yesterday no longer does. The one-shot migration is the
 * whole safety of this change, so it is what most of this drives.
 *
 * @covers \FreeFormCertificate\UserDashboard\CapabilityMigrator
 */
class IdentityVerbCapabilityTest extends TestCase {

	/**
	 * Preload for pcov attribution.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\UserDashboard\CapabilityMigrator' );

		// `CapabilityCatalog::groups()` is translated metadata, so reading it
		// at all needs the i18n functions -- the same stub its own test uses.
		Functions\when( '__' )->returnArg();

		// CHOSEN, NOT INHERITED. `LabelSorter::locale()` guards on
		// `function_exists( 'get_locale' )`, which the catalogue reaches when
		// it sorts -- so whether it takes the WordPress branch or its `en_US`
		// fallback depends on whether ANY earlier test in the process stubbed
		// it. Under `--filter` on this file alone nothing had, and the error
		// then names a file this test never touches (#1053).
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
	}

	/**
	 * Tear down Brain\Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * THE MIGRATION SEEDS BOTH VERBS ONTO WHOEVER WORKS THE QUEUE TODAY.
	 *
	 * Without it, every operator holding `ffc_manage_identities` loses split
	 * and merge on upgrade. The split is for handing the queue out WITHOUT
	 * them from here on, never for taking them from somebody who had them.
	 */
	public function test_the_grant_map_seeds_both_verbs_from_the_queue_capability(): void {
		$this->assertSame(
			array(
				'ffc_manage_identities' => array(
					'ffc_split_identities',
					'ffc_merge_identities',
				),
			),
			CapabilityMigrator::identity_verbs_cap_grant_map()
		);
	}

	/**
	 * Both slugs are real capabilities, not strings the page invented.
	 *
	 * `CapabilityCatalogTest` already holds that the catalogue and the manager
	 * agree as a set; what this adds is that the two the PAGE gates on are in
	 * that set at all — a gate naming a capability nobody registers refuses
	 * everybody except an administrator, and looks like a permissions problem.
	 */
	public function test_the_page_gates_on_capabilities_that_exist(): void {
		$registered = CapabilityManager::get_all_capabilities();

		$this->assertContains( IdentityResolutionPage::SPLIT_CAPABILITY, $registered );
		$this->assertContains( IdentityResolutionPage::MERGE_CAPABILITY, $registered );
		$this->assertContains( IdentityResolutionPage::CAPABILITY, $registered );
	}

	/**
	 * THE THREE ARE DISTINCT, WHICH IS THE POINT OF THE CHANGE.
	 *
	 * A copy-paste that left two of them equal would pass every other test
	 * here and quietly merge the two grants back into one.
	 */
	public function test_the_three_capabilities_are_distinct(): void {
		$caps = array(
			IdentityResolutionPage::CAPABILITY,
			IdentityResolutionPage::SPLIT_CAPABILITY,
			IdentityResolutionPage::MERGE_CAPABILITY,
		);

		$this->assertCount( 3, array_unique( $caps ) );
	}

	/**
	 * A CAPABILITY SLUG AND A HOOK NAME MUST NOT BE THE SAME STRING.
	 *
	 * They live in different namespaces and would not collide at runtime, so
	 * nothing breaks — but a grep for `ffc_merge_identities` would return the
	 * capability and the `admin_post` action together, and a reader has no way
	 * to tell which a given line means. The action was renamed rather than the
	 * capability: the capability follows the grammar, and the action was
	 * already the odd one out among four singular siblings.
	 */
	public function test_no_action_or_nonce_reuses_a_capability_slug(): void {
		$caps = CapabilityManager::get_all_capabilities();

		foreach ( array(
			'REPAIR_ACTION'      => IdentityResolutionPage::REPAIR_ACTION,
			'CONSOLIDATE_ACTION' => IdentityResolutionPage::CONSOLIDATE_ACTION,
			'RELINK_ACTION'      => IdentityResolutionPage::RELINK_ACTION,
			'SPLIT_ACTION'       => IdentityResolutionPage::SPLIT_ACTION,
			'MERGE_ACTION'       => IdentityResolutionPage::MERGE_ACTION,
			'RESCAN_ACTION'      => IdentityResolutionPage::RESCAN_ACTION,
			'MERGE_NONCE'        => IdentityResolutionPage::MERGE_NONCE,
			'RESCAN_NONCE'       => IdentityResolutionPage::RESCAN_NONCE,
		) as $name => $value ) {
			$this->assertNotContains(
				$value,
				$caps,
				sprintf( '`%s` is also a capability slug, so the two cannot be told apart by reading.', $name )
			);
		}
	}

	/**
	 * The catalogue no longer tells an administrator that the queue capability
	 * covers splitting and merging, because it stopped being true.
	 *
	 * A description that states a conclusion is the kind of stale text
	 * `CLAUDE.md` calls a lie rather than a drift: somebody granting one
	 * capability would believe they had granted three.
	 */
	public function test_the_queue_capability_no_longer_claims_the_two_verbs(): void {
		$described = '';

		foreach ( CapabilityCatalog::groups() as $group ) {
			foreach ( (array) ( $group['caps'] ?? array() ) as $slug => $meta ) {
				if ( IdentityResolutionPage::CAPABILITY === $slug ) {
					$described = (string) ( $meta['description'] ?? '' );
				}
			}
		}

		$this->assertNotSame( '', $described, 'The queue capability must still be described.' );
		$this->assertStringNotContainsStringIgnoringCase( 'split an account', $described );
		$this->assertStringNotContainsStringIgnoringCase( 'merge two accounts', $described );
	}
}
