<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\SensitiveFieldRegistry;

/**
 * The identity hash boundary: one canonical form per identifier (#1313).
 *
 * WHAT WAS WRONG
 *
 * `Encryption::hash()` was already one function, salted SHA-256, called from
 * everywhere. What was NOT one thing is the string handed to it, because that
 * was decided by each call site. Five sites normalised a CPF to digits and
 * `UserProfileService` hashed the value exactly as stored -- so a
 * reregistration holding the masked `123.456.789-09` wrote a hash of the
 * punctuation while submissions, appointments and recruitment wrote a hash of
 * the digits. The same person, two values, never matching.
 *
 * A call site that has to remember is a call site that can forget, and the
 * defect class had already shipped once before: `EmailHashRehashMigrationStrategy`
 * exists because two paths hashed an e-mail with no salt at all. That was
 * fixed call site by call site and the shape -- *the input is whoever calls*
 * -- was left standing, so it recurred as normalisation instead of salt.
 *
 * WHAT THIS PINS
 *
 * That the canonical form is a property of the FIELD, not of the caller: the
 * same identifier produces the same hash whichever module writes it, masked or
 * not, and a reader searching for it agrees with the writer that stored it.
 *
 * WHAT IT DELIBERATELY DOES NOT PIN
 *
 * That every call site actually routes through here. Presence is not
 * enforcement -- a new site can still call `Encryption::hash()` directly, and
 * only the static guard of #1313's final PR closes that. This file proves the
 * boundary is CORRECT; the guard proves it is USED.
 *
 * @covers \FreeFormCertificate\Core\SensitiveFieldRegistry
 * @covers \FreeFormCertificate\Core\DataSanitizer
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class IdentityHashBoundaryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Core\SensitiveFieldRegistry' );
		class_exists( '\FreeFormCertificate\Core\DataSanitizer' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The masked and the bare form of one CPF are one value.
	 *
	 * This is the #1313 defect stated as an assertion: the profile stored the
	 * left column, every other module stored the right one.
	 *
	 * @dataProvider masked_identifiers
	 *
	 * @param string $field  Logical field key.
	 * @param string $masked The value as a form or a spreadsheet supplies it.
	 * @param string $bare   The value as the canonical form.
	 */
	public function test_a_masked_identifier_normalizes_to_its_bare_form( string $field, string $masked, string $bare ): void {
		$this->assertSame(
			$bare,
			SensitiveFieldRegistry::normalize( $field, $masked ),
			sprintf( 'The %s field did not reduce %s to its canonical form.', $field, $masked )
		);

		$this->assertSame(
			SensitiveFieldRegistry::normalize( $field, $bare ),
			SensitiveFieldRegistry::normalize( $field, $masked ),
			'Two spellings of one identifier must normalise to one value, or the modules that write them cannot match.'
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function masked_identifiers(): array {
		return array(
			'cpf with the form mask'   => array( 'cpf', '123.456.789-09', '12345678909' ),
			'cpf with spaces'          => array( 'cpf', ' 123 456 789 09 ', '12345678909' ),
			'rf with the form mask'    => array( 'rf', '123.456-7', '1234567' ),
			'ticket lowercased'        => array( 'ticket', '  abc-123 ', 'ABC-123' ),
		);
	}

	/**
	 * Normalising is idempotent, which is what lets the legacy migration
	 * converge.
	 *
	 * The migration of #1313 decides whether a row needs rewriting by asking
	 * `normalize( $plain ) === $plain`. It cannot compare ciphertext, because
	 * `Encryption::encrypt()` uses a random IV and re-encrypting identical
	 * plaintext yields a different string every time -- a ciphertext test
	 * rewrites every row on every run and the migration card never reaches
	 * zero pending. That convergence rests on this property.
	 *
	 * @dataProvider masked_identifiers
	 *
	 * @param string $field  Logical field key.
	 * @param string $masked Unused here; the provider is shared.
	 * @param string $bare   The canonical form.
	 */
	public function test_normalizing_an_already_canonical_value_changes_nothing( string $field, string $masked, string $bare ): void {
		unset( $masked );

		$this->assertSame(
			$bare,
			SensitiveFieldRegistry::normalize( $field, SensitiveFieldRegistry::normalize( $field, $bare ) ),
			'normalize() must be idempotent, or the migration cannot tell a corrected row from an uncorrected one.'
		);
	}

	/**
	 * A field with no declared canonical form is passed through untouched.
	 *
	 * `email` is deliberately in that state right now, and this test is what
	 * stops the flip from happening by accident: lowercasing it changes every
	 * `email_hash` already stored, so the rule and the rehash migration land
	 * together (#1313). Until then, the boundary must not silently start
	 * canonicalising it.
	 */
	public function test_email_is_passed_through_until_its_migration_lands(): void {
		$this->assertSame(
			'Joao@Escola.gov.br',
			SensitiveFieldRegistry::normalize( 'email', 'Joao@Escola.gov.br' ),
			'email declares no canonical form yet; changing that without the rehash migration splits the stored hashes.'
		);
	}

	/**
	 * An unknown field is passed through rather than mangled.
	 */
	public function test_a_field_with_no_rule_is_returned_unchanged(): void {
		$this->assertSame(
			'  Some Free Text  ',
			SensitiveFieldRegistry::normalize( 'notes', '  Some Free Text  ' ),
			'Only identifiers declare a canonical form; everything else is stored as written.'
		);
	}

	/**
	 * A value that carries no identifier hashes to null, not to the hash of
	 * the empty string.
	 *
	 * A CPF field holding only punctuation normalises to '', and hashing that
	 * would make every such row findable by one shared value -- the opposite
	 * of an identifier.
	 */
	public function test_a_value_with_no_identifier_in_it_yields_no_hash(): void {
		$this->assertSame(
			'',
			SensitiveFieldRegistry::normalize( 'cpf', '...---' ),
			'Punctuation alone carries no CPF.'
		);

		$this->assertNull(
			SensitiveFieldRegistry::hash_identifier( 'cpf', '...---' ),
			'An empty canonical value must produce no hash at all.'
		);

		$this->assertNull(
			SensitiveFieldRegistry::hash_identifier( 'cpf', '' ),
			'An empty input must produce no hash at all.'
		);
	}

	/**
	 * Every declared field resolves to a normalizer that exists.
	 *
	 * `SensitiveFieldRegistry::normalize()` deliberately carries no trailing
	 * pass-through: every kind the map declares has an arm, so PHPStan proves
	 * a fall-through unreachable and a dead line cannot sit there rotting.
	 * The cost of that is real and this test is what pays it — a kind added to
	 * the map with no arm would fall off the end of a `: string` method and
	 * raise a TypeError at runtime, on a public form.
	 *
	 * Driving every declared field through the method is what turns that into
	 * a CI failure instead. It asserts nothing about the VALUE, on purpose:
	 * what the canonical form is belongs to the tests above, and what this one
	 * charges is only that a normalizer is reachable at all.
	 */
	public function test_every_declared_field_resolves_to_a_normalizer(): void {
		$fields = SensitiveFieldRegistry::normalized_field_keys();

		$this->assertNotEmpty( $fields, 'The normalizer map is empty — the scan collapsed.' );

		foreach ( $fields as $field_key ) {
			$this->assertIsString(
				SensitiveFieldRegistry::normalize( $field_key, 'ABC-123.456' ),
				sprintf(
					'%s is declared in NORMALIZERS but normalize() has no arm for its kind, so the method falls off its own end.',
					$field_key
				)
			);
		}
	}

	/**
	 * Self-check: every field carrying a hash column declares a canonical
	 * form, or is named here as deliberately having none.
	 *
	 * Without this, adding an identifier to `FIELDS` with a `hash_column` and
	 * no entry in `NORMALIZERS` would store it under whatever spelling arrived
	 * -- which is exactly the state #1313 found, reintroduced silently. An
	 * empty scan fails rather than reads as clean (the #1071 / #1094 rule).
	 */
	public function test_every_hashed_field_has_decided_its_canonical_form(): void {
		$declared = SensitiveFieldRegistry::normalized_field_keys();

		$this->assertNotEmpty( $declared, 'The normalizer map is empty — the scan collapsed.' );

		$hashed = SensitiveFieldRegistry::hashed_field_keys();

		$this->assertNotEmpty( $hashed, 'No hashed field was found in any context — the scan collapsed.' );

		$undecided = array_values( array_diff( $hashed, $declared ) );
		sort( $undecided );

		$this->assertSame(
			array(),
			$undecided,
			'These fields are hashed but declare no canonical form, so whatever spelling reaches them is what gets stored. Add an entry to NORMALIZERS — `null` is a valid answer, but it has to be written down.'
		);
	}
}
