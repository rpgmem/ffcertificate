<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\IdentityAgreement;
use FreeFormCertificate\Maintenance\IdentityConflictQuery;

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

	// ──────────────────────────────────────────────────────────────────.
	// with_unusable() — an agreed value that is not a value (#1491).
	//
	// No database: the method takes verdicts rather than fetching them, which
	// is the whole reason it is pure. What it decides is tested here; that the
	// verbs ASK is pinned in their own tests.
	// ──────────────────────────────────────────────────────────────────.

	/**
	 * A shared value that fails its check digit stops being a match.
	 *
	 * It becomes a conflict, because it is not weak evidence that the accounts
	 * are one person -- it is evidence that one of them holds a wrong number.
	 */
	public function test_an_invalid_shared_value_moves_from_matches_to_conflicts(): void {
		$out = IdentityAgreement::with_unusable(
			array(
				'matches'   => array( 'rf' ),
				'gaps'      => array(),
				'conflicts' => array(),
				'reasons'   => array(),
			),
			array( 'rf' => array( 'hash-rf' ) ),
			array( 'rf' => array( 'hash-rf' => IdentityConflictQuery::VERDICT_INVALID ) )
		);

		$this->assertSame( array(), $out['matches'], 'An unusable value is not agreement.' );
		$this->assertSame( array( 'rf' ), $out['conflicts'] );
		$this->assertSame( IdentityAgreement::REASON_UNUSABLE, $out['reasons']['rf'] );
	}

	/**
	 * LEAVING `matches` IS THE HALF THAT MATTERS, not adding to `conflicts`.
	 *
	 * `IdentityMerge` reads `matches` as *these accounts agree about something*
	 * and refuses when it is empty. A field left in both lists would let a
	 * caller that consults `matches` first proceed on the very value that
	 * blocks it, so this asserts the removal on its own.
	 */
	public function test_a_valid_shared_value_stays_a_match(): void {
		$out = IdentityAgreement::with_unusable(
			array(
				'matches'   => array( 'rf', 'cpf' ),
				'gaps'      => array(),
				'conflicts' => array(),
				'reasons'   => array(),
			),
			array(
				'rf'  => array( 'hash-rf' ),
				'cpf' => array( 'hash-cpf' ),
			),
			array(
				'rf'  => array( 'hash-rf' => IdentityConflictQuery::VERDICT_VALID ),
				'cpf' => array( 'hash-cpf' => IdentityConflictQuery::VERDICT_INVALID ),
			)
		);

		$this->assertSame( array( 'rf' ), $out['matches'], 'The valid field survives; only the invalid one leaves.' );
		$this->assertSame( array( 'cpf' ), $out['conflicts'] );
	}

	/**
	 * A VALUE NOBODY COULD READ IS NOT A VALUE THAT IS WRONG.
	 *
	 * The #1071 / #1094 rule, and the one
	 * `IdentityConflictQuery::check_digit_failures()` already states for its
	 * own scan. Refusing on `unreadable` would make a mismatched encryption key
	 * look like a data defect and block every merge on an install whose key does
	 * not match its rows -- which is the ordinary state of a staging copy.
	 */
	public function test_an_unreadable_value_does_not_refuse(): void {
		foreach ( array( IdentityConflictQuery::VERDICT_UNREADABLE, IdentityConflictQuery::VERDICT_ABSENT, '' ) as $verdict ) {
			$out = IdentityAgreement::with_unusable(
				array(
					'matches'   => array( 'rf' ),
					'gaps'      => array(),
					'conflicts' => array(),
					'reasons'   => array(),
				),
				array( 'rf' => array( 'hash-rf' ) ),
				array( 'rf' => array( 'hash-rf' => $verdict ) )
			);

			$this->assertSame( array( 'rf' ), $out['matches'], sprintf( 'Verdict "%s" must not refuse.', $verdict ) );
			$this->assertSame( array(), $out['conflicts'] );
		}
	}

	/**
	 * An existing conflict is preserved rather than replaced.
	 *
	 * The two kinds coexist: one side may disagree about the CPF while the RF
	 * they do share is unusable, and an operator needs to be told both.
	 */
	public function test_it_adds_to_conflicts_rather_than_replacing_them(): void {
		$out = IdentityAgreement::with_unusable(
			array(
				'matches'   => array( 'rf' ),
				'gaps'      => array(),
				'conflicts' => array( 'cpf' ),
				'reasons'   => array( 'cpf' => IdentityAgreement::REASON_DISAGREEMENT ),
			),
			array( 'rf' => array( 'hash-rf' ) ),
			array( 'rf' => array( 'hash-rf' => IdentityConflictQuery::VERDICT_INVALID ) )
		);

		$this->assertSame( array( 'cpf', 'rf' ), $out['conflicts'] );
		$this->assertSame( IdentityAgreement::REASON_DISAGREEMENT, $out['reasons']['cpf'] );
		$this->assertSame( IdentityAgreement::REASON_UNUSABLE, $out['reasons']['rf'] );
	}

	/**
	 * No verdict for a hash leaves the match alone, and `gaps` never moves.
	 *
	 * A verdict map that lost an entry is a scan that did not answer, not a
	 * value that failed -- the same reading as `unreadable`. And a GAP is a
	 * field the target does not hold at all, so there is no shared value to
	 * judge.
	 */
	public function test_a_missing_verdict_and_a_gap_are_left_alone(): void {
		$out = IdentityAgreement::with_unusable(
			array(
				'matches'   => array( 'rf' ),
				'gaps'      => array( 'cpf' => 'hash-cpf' ),
				'conflicts' => array(),
				'reasons'   => array(),
			),
			array( 'rf' => array( 'hash-rf' ) ),
			array()
		);

		$this->assertSame( array( 'rf' ), $out['matches'] );
		$this->assertSame( array( 'cpf' => 'hash-cpf' ), $out['gaps'] );
		$this->assertSame( array(), $out['conflicts'] );
	}
}
