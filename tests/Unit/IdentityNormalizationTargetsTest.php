<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\Migrations\Strategies\IdentityNormalizationMigrationStrategy;
use FreeFormCertificate\UserDashboard\UserProfileFieldMap;

/**
 * What the identity-normalization card must know about (#1313 PR 3).
 *
 * The card walks four stores. Three of them derive their columns from
 * `SensitiveFieldRegistry`, so they cannot fall behind it — a field added to a
 * context is walked the moment it is declared. The fourth cannot: the profile's
 * source of truth is `UserProfileFieldMap`, in the UserDashboard module, and
 * importing it from `Migrations` would create an edge that is not in
 * `ModuleBoundaryTest`'s baseline. So those names are literals in the strategy,
 * exactly as they are in `KeyRotationRemainingMigrationStrategy`, and this file
 * — which lives outside the module graph — is what charges the agreement.
 *
 * The last test is the one that matters most: it is the direction the register
 * cannot see. A new identifier added to a registry context with a hash column
 * and a canonical form must be REPAIRABLE by this card, or the rows already
 * stored keep whatever spelling arrived and nothing says so.
 *
 * @coversNothing
 */
class IdentityNormalizationTargetsTest extends TestCase {

	/**
	 * A private constant of the strategy.
	 *
	 * @param string $name Constant name.
	 * @return mixed
	 */
	private function strategy_const( string $name ) {
		// `ReflectionClassConstant` has no `setAccessible()` — a private
		// constant is readable through it directly, unlike a method.
		return ( new \ReflectionClassConstant( IdentityNormalizationMigrationStrategy::class, $name ) )->getValue();
	}

	/**
	 * The strategy's repairable-field resolution for one context.
	 *
	 * @param string $context Registry context key.
	 * @return array<string, array{encrypted_column: ?string, hash_column: ?string}>
	 */
	private function repairable( string $context ): array {
		$ref = new \ReflectionMethod( IdentityNormalizationMigrationStrategy::class, 'repairable_fields' );
		$ref->setAccessible( true );

		/** @var array<string, array{encrypted_column: ?string, hash_column: ?string}> $fields */
		$fields = $ref->invoke( new IdentityNormalizationMigrationStrategy(), $context );

		return $fields;
	}

	/**
	 * The profile map the card pins must agree with the field map that owns it.
	 */
	public function test_the_pinned_profile_fields_agree_with_the_field_map(): void {
		/** @var array<string, string> $pinned */
		$pinned = $this->strategy_const( 'PROFILE_FIELDS' );

		$this->assertNotSame( array(), $pinned, 'The card pins no profile field — it would walk the profile and correct nothing.' );

		foreach ( $pinned as $field_key => $hash_column ) {
			$this->assertSame(
				UserProfileFieldMap::hash_column( $field_key ),
				$hash_column,
				"The card writes '{$field_key}' to a column the field map does not name — the index it repairs is not the one anything reads."
			);
		}
	}

	/**
	 * Every hashable profile field is covered, not just the ones remembered.
	 */
	public function test_every_hashable_profile_field_is_walked(): void {
		/** @var array<string, string> $pinned */
		$pinned = $this->strategy_const( 'PROFILE_FIELDS' );

		foreach ( UserProfileFieldMap::sensitive_field_keys() as $field_key ) {
			if ( null === UserProfileFieldMap::hash_column( $field_key ) ) {
				continue;
			}

			$this->assertArrayHasKey(
				$field_key,
				$pinned,
				"'{$field_key}' carries a lookup hash on the profile but the card does not walk it, so its stored value keeps whatever spelling arrived."
			);
		}
	}

	/**
	 * The pinned meta prefix is the one the profile actually writes under.
	 *
	 * Read as text rather than by autoloading `UserManager`: a declaration is a
	 * literal in a file, and pulling that class into the process would teach
	 * the rest of the run something it did not ask for.
	 */
	public function test_the_pinned_meta_prefix_matches_the_one_the_profile_writes(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/includes/user-dashboard/class-ffc-user-manager.php'
		);

		$this->assertSame(
			1,
			preg_match( "/const\s+EXTENDED_META_PREFIX\s*=\s*'([^']*)'/", $source, $m ),
			'Could not read EXTENDED_META_PREFIX — the declaration moved or changed shape.'
		);

		$this->assertSame(
			$m[1],
			$this->strategy_const( 'PROFILE_META_PREFIX' ),
			'The card reads a meta key prefix the profile does not write under, so it would find nothing to correct.'
		);
	}

	/**
	 * The three table targets each resolve to a non-empty field set.
	 */
	public function test_each_table_target_has_fields_to_repair(): void {
		foreach ( array(
			SensitiveFieldRegistry::CONTEXT_SUBMISSION,
			SensitiveFieldRegistry::CONTEXT_APPOINTMENT,
			SensitiveFieldRegistry::CONTEXT_RECRUITMENT_CANDIDATE,
		) as $context ) {
			$this->assertNotSame(
				array(),
				$this->repairable( $context ),
				"Context '{$context}' resolves to no repairable field — the card walks its table and corrects nothing."
			);
		}
	}

	/**
	 * Every identifier that can be searched and can be read back is repaired.
	 *
	 * THE DIRECTION A REGISTER CANNOT SEE. A field with a hash column, a
	 * canonical form and a stored ciphertext is one whose rows can be wrong and
	 * can be fixed — so leaving it out of the card is leaving rows unreachable
	 * with nothing reporting it.
	 *
	 * `ticket` is the declared exception and is asserted as such rather than
	 * skipped: it has a canonical form and a hash but NO ciphertext, so there
	 * is no plaintext to repair it from. Should it ever gain one, this test
	 * fails and the card has to decide about it.
	 */
	public function test_every_searchable_identifier_with_a_ciphertext_is_repairable(): void {
		$normalized = SensitiveFieldRegistry::normalized_field_keys();
		$checked    = 0;

		foreach ( array(
			SensitiveFieldRegistry::CONTEXT_SUBMISSION,
			SensitiveFieldRegistry::CONTEXT_APPOINTMENT,
			SensitiveFieldRegistry::CONTEXT_RECRUITMENT_CANDIDATE,
		) as $context ) {
			$repairable = $this->repairable( $context );

			foreach ( SensitiveFieldRegistry::fields_for( $context ) as $field_key => $spec ) {
				if ( null === $spec['hash_column'] || ! in_array( $field_key, $normalized, true ) ) {
					continue;
				}

				++$checked;

				if ( null === $spec['encrypted_column'] ) {
					$this->assertSame(
						'ticket',
						$field_key,
						"'{$field_key}' in '{$context}' is searchable and has a canonical form but stores no ciphertext, so nothing can repair it. Only 'ticket' is known to be in that state."
					);
					continue;
				}

				$this->assertArrayHasKey(
					$field_key,
					$repairable,
					"'{$field_key}' in '{$context}' can be wrong and can be fixed, but the card does not walk it."
				);
			}
		}

		$this->assertGreaterThan(
			0,
			$checked,
			'No searchable identifier was examined — the scan measured nothing and would pass on an empty registry.'
		);
	}
}
