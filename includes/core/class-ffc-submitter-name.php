<?php
/**
 * Which key of a submission's answers holds the person's name.
 *
 * @package FreeFormCertificate\Core
 * @since   6.30.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one list of keys a name can arrive under (#1480).
 *
 * A submission's answers are a per-form map, so nothing in the schema says
 * which key is the person's name -- there is no name COLUMN on
 * `ffc_submissions` at all. Every consumer therefore tries a list of likely
 * keys, and that list had been written at five sites.
 *
 * IT HAD ALREADY DIVERGED, AND THE DIVERGENCE WAS VISIBLE TO USERS.
 *
 * Three sites carried six keys including `participante`; the two in
 * `UserCreator` carried five and left it out. One of those two is
 * `sync_user_metadata()`, which sets `display_name` and `first_name` on the
 * account it just created. So for a form whose name field is keyed
 * `participante`, the admin edit screen showed the name, the field sanitizer
 * treated it as a name, and `UserManager` read it -- while the account created
 * from that very submission got no display name and no first name, and its
 * username fell back to the address.
 *
 * Nothing could have caught it: each list is internally correct, and no gate
 * read two of them together. The consolidation IS the fix, and the union is the
 * only choice that regresses nobody -- dropping `participante` would have
 * broken the three sites that honour it.
 *
 * The order is the order of preference, and it is load-bearing: a form may
 * carry more than one of these keys, and the first non-empty one wins. It is
 * the order the three six-key sites already used.
 */
class SubmitterName {

	/**
	 * The keys a name can arrive under, in order of preference.
	 *
	 * @var array<int, string>
	 */
	public const CANDIDATE_KEYS = array(
		'nome_completo',
		'nome',
		'name',
		'full_name',
		'ffc_nome',
		'participante',
	);

	/**
	 * The name in one submission's answers, or an empty string.
	 *
	 * IT TAKES ANY MAP, for the reason `IdentityAgreement::collect()` does: the
	 * answers arrive decoded from a `longtext` column and reach this as a plain
	 * array, so requiring `array<string, mixed>` would claim a key type nothing
	 * proves. This asks for the keys it knows and ignores everything else.
	 *
	 * A value that is not a string is skipped rather than cast. A checkbox
	 * group under a key named `nome` would arrive as an array, and
	 * `(string) array` is `'Array'` -- a name no person has, written to a
	 * profile where somebody would have had to notice it.
	 *
	 * @param array<mixed, mixed> $answers The submission's decoded answers.
	 * @return string The name, trimmed, or '' when no candidate key holds one.
	 */
	public static function from( array $answers ): string {
		foreach ( self::CANDIDATE_KEYS as $key ) {
			$value = $answers[ $key ] ?? '';

			if ( ! is_string( $value ) ) {
				continue;
			}

			$value = trim( $value );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}
}
