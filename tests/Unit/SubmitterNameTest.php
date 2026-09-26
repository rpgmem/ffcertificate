<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\SubmitterName;

/**
 * Reading a person's name out of one submission's answers (#1480).
 *
 * @covers \FreeFormCertificate\Core\SubmitterName
 */
class SubmitterNameTest extends TestCase {

	/**
	 * The ordinary case, under the key the plugin's own forms use.
	 */
	public function test_it_reads_the_name_under_the_first_candidate_key(): void {
		$this->assertSame(
			'Clarice Fontes Miranda',
			SubmitterName::from( array( 'nome_completo' => 'Clarice Fontes Miranda' ) )
		);
	}

	/**
	 * THE KEY THAT TWO SITES HAD DROPPED, which is the defect this class exists
	 * to have fixed rather than a hypothetical.
	 */
	public function test_it_reads_the_name_under_participante(): void {
		$this->assertSame(
			'Otávio Brandão',
			SubmitterName::from( array( 'participante' => 'Otávio Brandão' ) )
		);
	}

	/**
	 * Order is preference: the earlier key wins when a form carries both.
	 */
	public function test_the_earlier_key_wins(): void {
		$this->assertSame(
			'from nome_completo',
			SubmitterName::from(
				array(
					'participante'  => 'from participante',
					'nome_completo' => 'from nome_completo',
				)
			)
		);
	}

	/**
	 * A WHITESPACE-ONLY VALUE NO LONGER MASKS A REAL NAME UNDER A LATER KEY.
	 *
	 * This is a change from the loop in `UserManager`, which stopped at the
	 * first key holding a non-empty STRING and so ended the search on `'   '`
	 * without taking anything. Stated as a test because it is behaviour, not a
	 * refactor.
	 */
	public function test_a_blank_value_does_not_end_the_search(): void {
		$this->assertSame(
			'Real Name',
			SubmitterName::from(
				array(
					'nome_completo' => '   ',
					'name'          => 'Real Name',
				)
			)
		);
	}

	/**
	 * A NON-STRING IS SKIPPED, NEVER CAST.
	 *
	 * A checkbox group under a key named `nome` arrives as an array, and
	 * `(string) array` is `Array` -- which `sync_user_metadata()` would have
	 * written to a profile as somebody's display name.
	 */
	public function test_it_skips_a_value_that_is_not_a_string(): void {
		$this->assertSame(
			'Otávio Brandão',
			SubmitterName::from(
				array(
					'nome_completo' => array( 'a', 'b' ),
					'nome'          => 'Otávio Brandão',
				)
			)
		);
	}

	/**
	 * The name is trimmed, because it reaches a profile and a screen.
	 */
	public function test_it_trims_the_name(): void {
		$this->assertSame( 'Clarice', SubmitterName::from( array( 'nome' => "  Clarice \n" ) ) );
	}

	/**
	 * No candidate key, or none holding anything, is an empty string rather
	 * than a guess: the caller decides what to do with a submission whose
	 * answers name nobody.
	 */
	public function test_it_returns_an_empty_string_when_no_key_holds_a_name(): void {
		$this->assertSame( '', SubmitterName::from( array() ) );
		$this->assertSame( '', SubmitterName::from( array( 'sindicato' => 'Something' ) ) );
		$this->assertSame( '', SubmitterName::from( array( 'nome_completo' => '', 'nome' => '   ' ) ) );
	}

	/**
	 * It takes a map however it arrived, for the reason its docblock gives: the
	 * answers are decoded from a `longtext` column, so a numeric key is
	 * representable and must not fatal.
	 */
	public function test_it_tolerates_a_map_with_numeric_keys(): void {
		$this->assertSame( 'Clarice', SubmitterName::from( array( 0 => 'ignored', 'nome' => 'Clarice' ) ) );
	}
}
