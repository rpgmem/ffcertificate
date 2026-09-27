<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\IdentityAgreement;

/**
 * The rule that decides whether two sets of identifiers describe one person.
 *
 * IT HAD NO TEST OF ITS OWN, AND THAT IS HOW #1477 SHIPPED.
 *
 * Every case reached it through a verb -- merge, relink, the search's
 * per-candidate verdict -- so what those verbs asserted was the refusal they
 * composed, never the rule's own reading. A refusal that says the wrong thing
 * about a correct decision is invisible from there: the code is right, the
 * write is right, and only the sentence is wrong.
 *
 * `between()` takes two arrays and touches no database, so these are the
 * cheapest tests in the suite and the ones that pin the distinction the verbs
 * now depend on.
 *
 * @covers \FreeFormCertificate\Maintenance\IdentityAgreement
 */
class IdentityAgreementTest extends TestCase {

	/**
	 * Equal on both sides, one value each: the only shape that matches.
	 */
	public function test_one_value_each_and_equal_is_a_match(): void {
		$out = IdentityAgreement::between(
			array( 'rf' => array( 'rfShared' ) ),
			array( 'rf' => array( 'rfShared' ) )
		);

		$this->assertSame( array( 'rf' ), $out['matches'] );
		$this->assertSame( array(), $out['conflicts'] );
		$this->assertSame( array(), $out['reasons'], 'Nothing refused, so nothing has a reason.' );
	}

	/**
	 * A slot the target does not hold is a gap it gains, and only when the
	 * moving side names one value -- two would not say which it gains.
	 */
	public function test_a_single_value_against_an_empty_slot_is_a_gap(): void {
		$out = IdentityAgreement::between(
			array( 'cpf' => array( 'cpfOnly' ) ),
			array()
		);

		$this->assertSame( array( 'cpf' => 'cpfOnly' ), $out['gaps'] );
		$this->assertSame( array(), $out['conflicts'] );
	}

	/**
	 * THE CASE THE OLD SENTENCE DESCRIBED CORRECTLY, so it has to keep its
	 * reason: two values, no overlap, is a genuine disagreement and the fix is
	 * to correct whichever side is wrong.
	 */
	public function test_two_sides_sharing_nothing_is_a_disagreement(): void {
		$out = IdentityAgreement::between(
			array( 'rf' => array( 'rfMine' ) ),
			array( 'rf' => array( 'rfTheirs' ) )
		);

		$this->assertSame( array( 'rf' ), $out['conflicts'] );
		$this->assertSame(
			array( 'rf' => IdentityAgreement::REASON_DISAGREEMENT ),
			$out['reasons']
		);
	}

	/**
	 * THE CASE IT DESCRIBED WRONGLY, AND THE COMMON ONE IN PRODUCTION (#1477).
	 *
	 * The two sides agree about `rfShared` -- it is the value that paired them
	 * in the queue -- and the moving side carries one more. Still refused, and
	 * correctly: the target would inherit a number nobody has explained. But
	 * it is not a disagreement, and an operator told it was goes looking for a
	 * difference between two identical values.
	 */
	public function test_an_extra_value_beside_a_shared_one_is_ambiguous_not_a_disagreement(): void {
		$out = IdentityAgreement::between(
			array( 'rf' => array( 'rfShared', 'rfExtra' ) ),
			array( 'rf' => array( 'rfShared' ) )
		);

		$this->assertSame( array( 'rf' ), $out['conflicts'], 'It still refuses.' );
		$this->assertSame(
			array( 'rf' => IdentityAgreement::REASON_AMBIGUOUS ),
			$out['reasons']
		);
		$this->assertSame( array(), $out['matches'] );
	}

	/**
	 * Two values against an empty slot: nothing to disagree with, so the
	 * refusal is the moving side's own ambiguity.
	 */
	public function test_two_values_against_an_empty_slot_is_ambiguous(): void {
		$out = IdentityAgreement::between(
			array( 'cpf' => array( 'cpfA', 'cpfB' ) ),
			array()
		);

		$this->assertSame( array( 'cpf' ), $out['conflicts'] );
		$this->assertSame(
			array( 'cpf' => IdentityAgreement::REASON_AMBIGUOUS ),
			$out['reasons']
		);
		$this->assertSame( array(), $out['gaps'], 'An ambiguous set fills no slot.' );
	}

	/**
	 * A target holding BOTH of the moving side's values is still refused --
	 * two accounts with the same pair of numbers is not one unambiguous
	 * person -- and it is ambiguity rather than disagreement, since every
	 * value is shared.
	 */
	public function test_a_target_holding_both_values_is_still_ambiguous(): void {
		$out = IdentityAgreement::between(
			array( 'rf' => array( 'rfOne', 'rfTwo' ) ),
			array( 'rf' => array( 'rfOne', 'rfTwo' ) )
		);

		$this->assertSame( array( 'rf' ), $out['conflicts'] );
		$this->assertSame(
			array( 'rf' => IdentityAgreement::REASON_AMBIGUOUS ),
			$out['reasons']
		);
	}

	/**
	 * THE ASYMMETRY IS THE RULE WORKING, and it is what makes the same pair
	 * merge one way and not the other. Reversing the arguments of the case
	 * above turns a refusal into a match, because the side that would GAIN
	 * something unexplained is the one that matters.
	 */
	public function test_the_rule_is_asymmetric_by_design(): void {
		$multi  = array( 'rf' => array( 'rfShared', 'rfExtra' ) );
		$single = array( 'rf' => array( 'rfShared' ) );

		$this->assertSame(
			array( 'rf' ),
			IdentityAgreement::between( $multi, $single )['conflicts'],
			'Absorbing the multi-valued side hands the other an unexplained number.'
		);
		$this->assertSame(
			array( 'rf' ),
			IdentityAgreement::between( $single, $multi )['matches'],
			'The other direction gives the target nothing it did not already hold.'
		);
	}

	/**
	 * Both identifiers can refuse at once for different reasons, and the
	 * refusal has to be able to say both -- one composed sentence per cause,
	 * not the first cause found.
	 */
	public function test_the_two_identifiers_carry_their_own_reasons(): void {
		$out = IdentityAgreement::between(
			array(
				'rf'  => array( 'rfShared', 'rfExtra' ),
				'cpf' => array( 'cpfMine' ),
			),
			array(
				'rf'  => array( 'rfShared' ),
				'cpf' => array( 'cpfTheirs' ),
			)
		);

		$this->assertSame( array( 'rf', 'cpf' ), $out['conflicts'] );
		$this->assertSame(
			array(
				'rf'  => IdentityAgreement::REASON_AMBIGUOUS,
				'cpf' => IdentityAgreement::REASON_DISAGREEMENT,
			),
			$out['reasons']
		);
	}

	/**
	 * Absence on both sides says nothing about whether these are one person,
	 * so it is none of the three outcomes.
	 */
	public function test_an_identifier_neither_side_carries_is_not_an_outcome(): void {
		$out = IdentityAgreement::between( array(), array( 'rf' => array( 'rfTheirs' ) ) );

		$this->assertSame( array(), $out['matches'] );
		$this->assertSame( array(), $out['gaps'] );
		$this->assertSame( array(), $out['conflicts'] );
	}

	/**
	 * THE TWO REASONS MUST NOT BE THE SAME STRING, or every caller's split
	 * collapses into one branch while still reading as two.
	 */
	public function test_the_two_reasons_are_distinguishable(): void {
		$this->assertNotSame(
			IdentityAgreement::REASON_DISAGREEMENT,
			IdentityAgreement::REASON_AMBIGUOUS
		);
	}
}
