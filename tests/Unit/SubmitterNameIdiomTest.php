<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\SubmitterName;
use FreeFormCertificate\Tests\Support\PhpSource;

/**
 * Which key of a submission's answers holds a name is decided once (#1480).
 *
 * BESIDE `IdentifierIdiomTest` RATHER THAN INSIDE IT, on purpose: that guard's
 * subject is what a CPF, an RF and an address canonically ARE, and a person's
 * name is none of the three. The defect class is the same -- one rule written
 * at N sites, and the copies diverged -- and the scan machinery is shared
 * through `PhpSource` for the reason `CssSelectors` is shared, so nothing is
 * duplicated but the question.
 *
 * WHAT WAS WRONG
 *
 * There is no name column on `ffc_submissions`; the name sits inside the
 * answers under a per-form key. So five sites each carried a list of likely
 * keys, and two of them -- both in `UserCreator` -- carried five where the
 * other three carried six. The missing one was `participante`.
 *
 * One of those two is `sync_user_metadata()`, which writes `display_name` and
 * `first_name`. For a form keyed that way the admin edit screen showed the
 * name, the field sanitizer normalised it as a name, and `UserManager` listed
 * it, while the account created from that submission got neither. Each list was
 * internally correct and no gate read two together.
 *
 * @coversNothing
 */
class SubmitterNameIdiomTest extends TestCase {

	/**
	 * Files allowed to write the key list out, with the reason.
	 *
	 * Empty, and the guard blocks at zero: the list has exactly one home and
	 * there is no case for a second. A `@covers`-style register is kept anyway
	 * so that adding an entry is a decision somebody has to defend here rather
	 * than a line they can slip into a file.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = array();

	/**
	 * No file may spell out its own list of name keys.
	 *
	 * Matched on the two keys that appear in every copy, adjacent -- the pair
	 * is what makes it a list rather than a mention of one key. `code_lines()`
	 * blanks comments first, so the prose in `SubmitterName` describing the
	 * keys is not an occurrence of them.
	 */
	public function test_no_file_writes_its_own_list_of_name_keys(): void {
		$files = PhpSource::files_under( 'includes' );

		$this->assertNotSame( array(), $files, 'Reading no files is a broken scan, never a clean tree.' );

		$hits = PhpSource::lines_matching(
			$files,
			"/'nome_completo'\s*,\s*'nome'/"
		);

		// The declaration itself is an array of strings on one line, so it is
		// the one thing this pattern is expected to find. Finding NOTHING means
		// the scan is broken, not that the tree is clean -- the #1071 rule.
		$declaring = 'includes/core/class-ffc-submitter-name.php';

		$this->assertArrayHasKey(
			$declaring,
			PhpSource::lines_matching( array( $declaring ), "/'nome_completo',/" ),
			'The scan cannot see the declaration it is calibrated against, so a zero elsewhere proves nothing.'
		);

		unset( $hits[ $declaring ] );

		foreach ( $hits as $relative => $lines ) {
			$this->assertArrayHasKey(
				$relative,
				self::ALLOWED,
				"{$relative} writes its own list of name keys:\n  " . implode( "\n  ", $lines )
					. "\nAsk `SubmitterName` for it, or register the file here with the reason it cannot."
			);
		}

		foreach ( array_keys( self::ALLOWED ) as $relative ) {
			$this->assertArrayHasKey(
				$relative,
				$hits,
				"{$relative} is registered as keeping its own list and no longer has one — drop the entry to lock the win in."
			);
		}
	}

	/**
	 * THE KEY THAT WENT MISSING IS IN THE LIST, named rather than counted.
	 *
	 * A count would pass a list that had swapped one key for another, which is
	 * the shape of the defect rather than a different one. `participante` is
	 * named because it is the one that was dropped; the others are named
	 * because a list missing any of them silently stops reading a form that
	 * uses it, and nothing else would report that.
	 */
	public function test_the_union_of_the_five_old_lists_survived(): void {
		foreach ( array( 'nome_completo', 'nome', 'name', 'full_name', 'ffc_nome', 'participante' ) as $key ) {
			$this->assertContains(
				$key,
				SubmitterName::CANDIDATE_KEYS,
				"Dropping {$key} stops reading the name on every form that uses it, and no screen would report the absence."
			);
		}
	}

	/**
	 * The order is preference and it is load-bearing, so it is pinned.
	 *
	 * A form may carry two of these keys; which name reaches a profile depends
	 * on which comes first. Reordering is a behaviour change, not a tidy-up.
	 */
	public function test_the_order_of_preference_is_pinned(): void {
		$this->assertSame(
			array( 'nome_completo', 'nome', 'name', 'full_name', 'ffc_nome', 'participante' ),
			SubmitterName::CANDIDATE_KEYS
		);
	}
}
