<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Tests\Support\PhpSource;

/**
 * The two static halves of #1313's convergence, held shut.
 *
 * WHAT THE ARC FIXED AND WHY THAT IS NOT ENOUGH
 *
 * Identity converged by fixing call sites: the hash boundary (#1315), the
 * indexed columns (#1317), the stored values (#1319), the resolver (#1320),
 * the audience import (#1321), the idioms (#1322) and the last two modules
 * (#1323). Every one of those was a call site that had been free to decide for
 * itself and got it wrong -- and nothing stops the next one being written the
 * same way. `EmailHashRehashMigrationStrategy` exists because this exact class
 * of defect shipped once before, was fixed call site by call site, and
 * recurred as normalisation instead of salt.
 *
 * So these are the shape, not the instances: the hash input is decided in ONE
 * place, and a WordPress user is created in ONE place.
 *
 * THE THIRD GUARD IS NOT HERE, ON PURPOSE
 *
 * "A record carrying a `user_id` and an identifier implies that user's
 * identity index carries it" is a statement about ROWS. No static scan can see
 * it, and a unit test would assert it against the double that supplies the
 * values. It lives in the `fresh-install` job, which has a real database,
 * beside the other checks that plant state and read it back.
 *
 * WHAT THESE CANNOT SEE
 *
 * Presence, never truth -- the rule every guard in this repository states
 * about itself. A site can call `hash_identifier()` with the wrong field key,
 * or `UserCreator` with an identifier it never resolved, and both pass. What
 * they remove is the ability to bypass the boundary at all, which is the half
 * a reviewer cannot hold in their head.
 *
 * @coversNothing
 */
class IdentityConvergenceGuardTest extends TestCase {

	/**
	 * The files allowed to hand a value straight to `Encryption::hash()`.
	 *
	 * The split is between a value ENTERING storage and one being REBUILT from
	 * what is already there. A new value must be canonicalised, which is the
	 * registry's job. A migration re-hashing a decrypted value under the active
	 * salt must NOT canonicalise it: that is the canonicalisation card's
	 * separate job, with its own pending count and its own idempotence
	 * contract, and doing it here would make a rotation quietly perform a
	 * migration the operator did not run.
	 *
	 * @var array<string, string>
	 */
	private const HASH_BOUNDARY_ALLOWED = array(
		'includes/core/class-ffc-sensitive-field-registry.php'                                  => 'The boundary itself: `encrypt_fields()` and `hash_identifier()` are what every other site is required to call.',
		'includes/migrations/strategies/class-ffc-email-hash-rehash-migration-strategy.php'      => 'Rebuilds `email_hash` from the decrypted value under the salt, deliberately without normalising -- its own comment says so, and normalising would silently do the canonicalisation card\'s work.',
		'includes/migrations/strategies/class-ffc-key-rotation-migration-strategy.php'           => 'Re-hashes decrypted values under a newly-defined key. Canonicalising during a rotation would change what the row means, not merely how it is keyed.',
		'includes/migrations/strategies/class-ffc-key-rotation-remaining-migration-strategy.php' => 'The rotation\'s second pass, over the areas the first never covered (#1236). Same reason as its sibling.',
	);

	/**
	 * The one file allowed to create a WordPress user.
	 *
	 * @var array<string, string>
	 */
	private const USER_CREATION_ALLOWED = array(
		'includes/user-dashboard/class-ffc-user-creator.php' => 'The single creation path: it resolves the identifier first, links orphaned records, grants the context capabilities and feeds the identity index. `AudienceCsvImporter` was the last site outside it and joined in #1321.',
	);

	/**
	 * Both directions of a register, so an entry cannot outlive its code.
	 *
	 * @param array<string, list<int>|list<string>> $hits    Measured, keyed by path.
	 * @param array<string, string>                 $allowed Register.
	 * @param string                                $noun    What was found.
	 * @param string                                $remedy  What a new site should do instead.
	 * @return void
	 */
	private function assert_register_holds( array $hits, array $allowed, string $noun, string $remedy ): void {
		foreach ( $hits as $relative => $lines ) {
			$this->assertArrayHasKey(
				$relative,
				$allowed,
				"{$relative} has {$noun} at line(s) " . implode( ', ', array_map( 'strval', $lines ) ) . ".\n{$remedy}"
			);
		}

		foreach ( array_keys( $allowed ) as $relative ) {
			$this->assertArrayHasKey(
				$relative,
				$hits,
				"{$relative} is registered as having {$noun} and no longer does — drop the entry to lock the win in."
			);
		}
	}

	/**
	 * GUARD 1. The string handed to the hash is decided in one place.
	 */
	public function test_no_file_hashes_an_identifier_outside_the_registry(): void {
		$hits = array();

		foreach ( PhpSource::files_under( 'includes' ) as $relative ) {
			$lines = PhpSource::static_calls( $relative, 'Encryption', 'hash' );
			if ( array() !== $lines ) {
				$hits[ $relative ] = $lines;
			}
		}

		$this->assertNotSame(
			array(),
			$hits,
			'No call to Encryption::hash() was found anywhere, including in the registry that owns it — the scan matched nothing and would pass on an empty tree.'
		);

		$this->assert_register_holds(
			$hits,
			self::HASH_BOUNDARY_ALLOWED,
			'a direct call to Encryption::hash()',
			'Call SensitiveFieldRegistry::hash_identifier( $field, $value ) instead: it applies the field\'s canonical form first, which is the whole difference between a lookup that finds the person and one that does not.'
		);
	}

	/**
	 * GUARD 2. A WordPress user is created in one place.
	 */
	public function test_no_file_creates_a_wordpress_user_outside_user_creator(): void {
		$hits = array();

		foreach ( PhpSource::files_under( 'includes' ) as $relative ) {
			$calls = PhpSource::function_calls( $relative, array( 'wp_create_user', 'wp_insert_user' ) );
			if ( array() !== $calls ) {
				$hits[ $relative ] = array_map(
					static fn( array $call ): string => $call['name'] . '() at line ' . $call['line'],
					$calls
				);
			}
		}

		$this->assertNotSame(
			array(),
			$hits,
			'No user creation was found anywhere, including in UserCreator — the scan matched nothing and would pass on an empty tree.'
		);

		$this->assert_register_holds(
			$hits,
			self::USER_CREATION_ALLOWED,
			'a direct user creation',
			'Call UserCreator::get_or_create_user_dual() instead. Creating the user is the easy half; resolving the identifier first, adopting their orphaned records and feeding the identity index is what stops a duplicate account.'
		);
	}

	/**
	 * The scanner tells a call from a mention, and a name from a longer one.
	 *
	 * A CANARY, not a unit test of the helper. Both distinctions are the reason
	 * the guards above read tokens instead of lines, and both have a live
	 * example in this repository: the migration registry's description contains
	 * the text `Encryption::hash()` inside a translated string, and
	 * `wp_create_user_request()` -- WordPress's GDPR request builder, unrelated
	 * to creating a user -- is called by the user-profile REST controller. A
	 * line scan reports the first as a violation and the second as a user
	 * creation; if this ever fails, the guards above have started measuring
	 * prose.
	 */
	public function test_the_scanner_reads_calls_and_not_mentions(): void {
		$this->assertSame(
			array(),
			PhpSource::static_calls( 'includes/migrations/class-ffc-migration-registry.php', 'Encryption', 'hash' ),
			'The registry\'s translated description mentions Encryption::hash() and is being read as a call.'
		);

		$this->assertSame(
			array(),
			PhpSource::function_calls( 'includes/api/class-ffc-user-profile-rest-controller.php', array( 'wp_create_user' ) ),
			'wp_create_user_request() is being read as wp_create_user(), which would make the guard refuse an unrelated GDPR call.'
		);

		$this->assertNotSame(
			array(),
			PhpSource::function_calls( 'includes/api/class-ffc-user-profile-rest-controller.php', array( 'wp_create_user_request' ) ),
			'The canary itself stopped matching: that file no longer calls wp_create_user_request(), so this test proves nothing about the distinction.'
		);
	}
}
