<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Tests\Support\HookWiring;

/**
 * The adoption hook is announced AND listened to, and from the right place.
 *
 * WHY THIS GUARD EXISTS
 *
 * `UserCreator::link_orphaned_records_dual()` adopts unlinked submissions and
 * appointments the moment a person is resolved, and nothing did the same for
 * recruitment candidacies -- while `UserCleanup` happily NULLs `user_id` on
 * that table when an account is deleted. So the plugin could drop the link and
 * never restore it, which made detaching a candidacy permanent loss. Fifteen
 * of the 73 conflicting accounts measured in production touch that table, two
 * of them exclusively (#1345).
 *
 * The fix is an action rather than a direct call, because `UserCreator` is in
 * `UserDashboard` and the candidate writer is in `Recruitment`, which already
 * depends on `UserDashboard` -- calling it would close a cycle between the two
 * modules. A hook is invisible to `ModuleBoundaryTest` by construction, so
 * nothing else would notice either half going missing: this is the "built but
 * never wired" class `AjaxWiringTest` exists for, one hook wide.
 *
 * WHY THE LISTENER MUST NOT MOVE INTO `RecruitmentLoader`
 *
 * That loader is gated on the Modules-tab toggle. An adoption a toggle can
 * skip leaves a candidacy orphaned with nothing to ever claim it, while
 * `UserCleanup` goes on dropping the link either way -- the asymmetry this
 * whole change removes. It is the same reason the recruitment SCHEMA and role
 * registration were relocated to the orchestrator, which that loader's own
 * NOTE records. Tidying the listener "next to the rest of the module" is the
 * plausible regression, so it is asserted rather than left to a comment.
 *
 * It reads the source as TEXT and boots no WordPress: what it proves is that
 * both ends of the wire exist and agree on a name, never that the handler runs
 * -- the limit `AjaxWiringTest` states for itself.
 *
 * @coversNothing
 */
final class IdentityAdoptionWiringTest extends TestCase {

	/**
	 * The action both halves have to agree on.
	 *
	 * @var string
	 */
	private const HOOK = 'ffc_adopt_orphaned_identity_records';

	/**
	 * Every PHP file under `includes/`, keyed by path.
	 *
	 * @return array<string, string>
	 */
	private static function sources(): array {
		return HookWiring::sources();
	}

	/**
	 * Files containing a call of the given kind on this hook.
	 *
	 * The scan itself lives in {@see HookWiring}, shared with the guard over
	 * `ffc_grant_certificate_capabilities`: two guards asking the same
	 * question must not disagree about what a registration looks like.
	 *
	 * @param string $call `do_action` or `add_action`.
	 * @return array<int, string> Paths, relative to the repository root.
	 */
	private static function files_calling( string $call ): array {
		return HookWiring::files_calling( self::HOOK, $call );
	}

	/**
	 * Self-check: an empty scan must fail rather than read as clean.
	 *
	 * The #1071 / #1094 rule. A moved `includes/`, or a reader that stopped
	 * matching, would otherwise make every assertion below vacuously true --
	 * which is exactly how a guard against a missing wire goes missing itself.
	 */
	public function test_the_scan_reads_the_source(): void {
		$sources = self::sources();

		$this->assertNotEmpty( $sources, 'The scan found no PHP under includes/.' );
		$this->assertGreaterThan( 100, count( $sources ), 'The scan reached only part of the tree.' );
	}

	/**
	 * Somebody announces the resolution.
	 */
	public function test_the_resolution_is_announced(): void {
		$this->assertNotEmpty(
			self::files_calling( 'do_action' ),
			'Nothing fires ' . self::HOOK . ', so no module can claim its own unlinked records.'
		);
	}

	/**
	 * …and somebody listens.
	 */
	public function test_the_announcement_is_listened_to(): void {
		$this->assertNotEmpty(
			self::files_calling( 'add_action' ),
			'Nothing listens to ' . self::HOOK . ', so a candidacy stays orphaned while UserCleanup still drops its link.'
		);
	}

	/**
	 * The listener is registered by the orchestrator, never by the module.
	 *
	 * See the class note: `RecruitmentLoader` is gated on the Modules-tab
	 * toggle, and an adoption a toggle can skip is the defect wearing a
	 * smaller size.
	 */
	public function test_the_listener_is_not_gated_on_the_module_toggle(): void {
		$listeners = self::files_calling( 'add_action' );

		$this->assertNotEmpty( $listeners, 'Nothing listens; the assertion below would prove nothing.' );

		foreach ( $listeners as $path ) {
			$this->assertStringNotContainsString(
				'recruitment/',
				$path,
				'A listener inside the recruitment module is skipped whenever the module is toggled off, and nothing ever claims the candidacy afterwards.'
			);
		}
	}

	/**
	 * The documented hook list names it.
	 *
	 * A plugin-internal action nobody can find is one an integrator reaches by
	 * reading the source, which is the surface this page exists to replace.
	 */
	public function test_the_hook_is_documented(): void {
		$page = file_get_contents( dirname( __DIR__, 2 ) . '/includes/settings/views/documentation/developer-hooks-api.php' );

		$this->assertIsString( $page, 'The hooks documentation page did not parse.' );
		$this->assertStringContainsString( self::HOOK, (string) $page );
	}
}
