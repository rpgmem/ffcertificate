<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Tests\Support\HookWiring;

/**
 * The certificate-capability grant is announced AND listened to, from the
 * right place.
 *
 * WHY THIS GUARD EXISTS
 *
 * `link_orphaned_records_dual()` claims a person's unlinked submissions, and
 * until #1345 it granted nothing for them -- so an account created by a path
 * that grants no capability (a promoted candidacy and a reregistration import
 * are both declared no-ops) held certificates the dashboard refused to show,
 * client-side and with a 403. Measured on production: 1,478 accounts.
 *
 * The backfill that repairs them cannot call `CapabilityManager` directly:
 * it lives in `Migrations`, and `UserDashboard > Migrations` already exists,
 * so naming it would make the two modules mutually dependent. It announces
 * instead -- and a hook is invisible to `ModuleBoundaryTest` by construction,
 * so nothing else would notice either half going missing. That is the "built
 * but never wired" class, one hook wide, and here it would show up as a
 * migration card counting accounts that nothing ever repairs.
 *
 * @coversNothing
 */
final class CertificateCapabilityGrantWiringTest extends TestCase {

	/**
	 * The action both halves have to agree on.
	 *
	 * @var string
	 */
	private const HOOK = 'ffc_grant_certificate_capabilities';

	/**
	 * Self-check: an empty scan must fail rather than read as clean (the
	 * #1071 / #1094 rule).
	 */
	public function test_the_scan_reads_the_source(): void {
		$sources = HookWiring::sources();

		$this->assertNotEmpty( $sources, 'The scan found no PHP under includes/.' );
		$this->assertGreaterThan( 100, count( $sources ), 'The scan reached only part of the tree.' );
	}

	public function test_the_grant_is_announced(): void {
		$this->assertNotEmpty(
			HookWiring::files_calling( self::HOOK, 'do_action' ),
			'Nothing fires ' . self::HOOK . ', so the backfill repairs nobody.'
		);
	}

	public function test_the_announcement_is_listened_to(): void {
		$this->assertNotEmpty(
			HookWiring::files_calling( self::HOOK, 'add_action' ),
			'Nothing listens to ' . self::HOOK . ', so the card counts accounts that are never repaired.'
		);
	}

	/**
	 * The producer stays out of `UserDashboard` and the listener out of
	 * `Migrations` -- either would be the direct call the action exists to
	 * avoid, and would close the cycle `ModuleBoundaryTest` reported.
	 */
	public function test_neither_half_reaches_across_the_cycle(): void {
		$producers = HookWiring::files_calling( self::HOOK, 'do_action' );
		$listeners = HookWiring::files_calling( self::HOOK, 'add_action' );

		$this->assertNotEmpty( $producers, 'Nothing announces; the assertions below would prove nothing.' );
		$this->assertNotEmpty( $listeners, 'Nothing listens; the assertions below would prove nothing.' );

		foreach ( $listeners as $path ) {
			$this->assertStringNotContainsString(
				'migrations/',
				$path,
				'A listener inside the migrations module is the direct call this hook exists to avoid.'
			);
		}
	}

	/**
	 * The listener is registered by the orchestrator, so no module toggle can
	 * skip it. The Migrations tab is not gated on one, so a card whose
	 * listener a toggle could remove would count accounts forever.
	 */
	public function test_the_listener_is_registered_by_the_orchestrator(): void {
		$listeners = HookWiring::files_calling( self::HOOK, 'add_action' );

		$this->assertContains(
			'includes/class-ffc-loader.php',
			$listeners,
			'The orchestrator is where a listener no toggle can skip belongs.'
		);
	}
}
