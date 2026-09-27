<?php
/**
 * The CPF and RF values every test fixture uses.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * One home for the identifiers tests pretend people hold (#1492).
 *
 * WHY THIS EXISTS, AND IT IS NOT TIDINESS
 *
 * Validation already has a single home -- `DocumentFormatter::validate_cpf()`
 * and `validate_rf()` -- which is why a rule change lands in one place.
 * Fixtures had none, so #1489's check-digit rule turned **19 tests red across
 * two files** whose fixtures used `12345678901`: a CPF no person can hold.
 * Nobody had written it intending an invalid value, and nobody could see that
 * it was one. That is the cost a standard removes.
 *
 * THREE PEOPLE, NAMED RATHER THAN NUMBERED
 *
 * A test comparing two accounts needs two identifiers that are valid AND
 * distinct; the largest fixture in the suite needs four values at once. Naming
 * them `A` / `B` / `C` is what makes a diff read as *two different people*
 * instead of two magic numbers, and each person's RF echoes the first six
 * digits of their CPF so a row carrying both reads as one person.
 *
 * THE TWO KINDS OF INVALID ARE NOT INTERCHANGEABLE
 *
 * This is the part a standard gets wrong by offering one of them.
 * `CPF_REPEATED_DIGITS` is refused by `preg_match( '/(\d)\1{10}/' )` **before**
 * any check digit is computed, so a test using it cannot prove the check digit
 * is consulted at all -- it proves the structural guard fires.
 * `CPF_BAD_CHECK_DIGIT` is one digit off a real CPF, which is the shape a typo
 * actually takes and the only value that exercises the digit arithmetic. A rule
 * like #1489's needs the second; a test of the width or repetition guard needs
 * the first.
 *
 * WHAT IS DELIBERATELY NOT COVERED
 *
 * A value of the **wrong width** -- `'12345'`, `'123456789012345'`, a 7-digit
 * RF sitting in a CPF column -- is the test's subject rather than its fixture,
 * and the width says so: nobody writes a five-digit CPF by accident, and
 * `validate_cpf()` refuses it on length before anything else. So the guard
 * checks values that ARE 11 (or 7) digits and leaves the rest alone, which
 * needs no allowlist.
 *
 * EVERY VALUE HERE WAS VERIFIED BY CALLING `DocumentFormatter`, never by
 * transcribing the algorithm. That distinction is not pedantry: a hand-copied
 * check scored `11111111111` as VALID, where the real one refuses it.
 */
final class Identifiers {

	/**
	 * Person A's CPF. Valid.
	 *
	 * @var string
	 */
	public const CPF_A = '51817842080';

	/**
	 * Person A's RF. Valid; echoes the first six digits of `CPF_A`.
	 *
	 * @var string
	 */
	public const RF_A = '5181780';

	/**
	 * Person B's CPF. Valid, and distinct from A's.
	 *
	 * @var string
	 */
	public const CPF_B = '20456942084';

	/**
	 * Person B's RF. Valid; echoes the first six digits of `CPF_B`.
	 *
	 * @var string
	 */
	public const RF_B = '2045699';

	/**
	 * Person C's CPF. Valid, for the fixtures that need a third.
	 *
	 * @var string
	 */
	public const CPF_C = '73102442064';

	/**
	 * Person C's RF. Valid; echoes the first six digits of `CPF_C`.
	 *
	 * @var string
	 */
	public const RF_C = '7310242';

	/**
	 * Eleven identical digits: refused STRUCTURALLY, before any digit is computed.
	 *
	 * Use this to exercise the repetition guard. It cannot show that a check
	 * digit was consulted, because the value never reaches that arithmetic.
	 *
	 * @var string
	 */
	public const CPF_REPEATED_DIGITS = '11111111111';

	/**
	 * `CPF_A` with its last digit changed: the shape a typo takes.
	 *
	 * The only CPF here that exercises the check-digit arithmetic, so it is the
	 * one a rule about check digits must be tested with.
	 *
	 * @var string
	 */
	public const CPF_BAD_CHECK_DIGIT = '51817842081';

	/**
	 * `RF_A` with its last digit changed, so the RF check digit disagrees.
	 *
	 * @var string
	 */
	public const RF_BAD_CHECK_DIGIT = '5181784';

	/**
	 * Every valid CPF, for a guard that needs the set rather than a name.
	 *
	 * @return array<int, string>
	 */
	public static function valid_cpfs(): array {
		return array( self::CPF_A, self::CPF_B, self::CPF_C );
	}

	/**
	 * Every valid RF.
	 *
	 * @return array<int, string>
	 */
	public static function valid_rfs(): array {
		return array( self::RF_A, self::RF_B, self::RF_C );
	}

	/**
	 * Every CPF a fixture may legitimately carry, valid or not.
	 *
	 * @return array<int, string>
	 */
	public static function cpfs(): array {
		return array_merge(
			self::valid_cpfs(),
			array( self::CPF_REPEATED_DIGITS, self::CPF_BAD_CHECK_DIGIT )
		);
	}

	/**
	 * Every RF a fixture may legitimately carry, valid or not.
	 *
	 * @return array<int, string>
	 */
	public static function rfs(): array {
		return array_merge( self::valid_rfs(), array( self::RF_BAD_CHECK_DIGIT ) );
	}
}
