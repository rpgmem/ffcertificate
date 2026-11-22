<?php
/**
 * TranslationGlossaryTest
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\PoCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * The pt_BR catalogue says each domain term with one word (#2670).
 *
 * Before this guard the catalogue rendered "submission" as both `submissão`
 * and `envio`, "capability" as `capacidade` and `permissão`, "role" as `função`
 * and `papel`, often on the same screen. Each rule names the English term, the word the
 * glossary forbids in its translation, and, where the forbidden word has a
 * second, legitimate sense, the source words that mark that sense: `envio`
 * also means sending an e-mail, so an entry that talks about sending is left
 * alone.
 *
 * It sees words, never meaning: a translation that avoids the forbidden word
 * by saying something wrong passes.
 */
final class TranslationGlossaryTest extends TestCase {

	private const CATALOGUE = __DIR__ . '/../../languages/ffcertificate-pt_BR.po';

	/**
	 * English term => forbidden word in its translation, the glossary word,
	 * and an optional source pattern that marks the forbidden word's other sense.
	 *
	 * @var array<string, array{source: string, forbidden: string, glossary: string, other_sense?: string}>
	 */
	private const RULES = array(
		'submission'       => array(
			'source'      => '/\bsubmissions?\b/i',
			'forbidden'   => '/\benvios?\b/iu',
			'glossary'    => '/\bsubmiss(?:ão|ões)\b/iu',
			'other_sense' => '/\b(?:send|sends|sent|sending|deliver(?:y|ies))\b/i',
		),
		'audience booking' => array(
			'source'    => '/\baudience bookings?\b/i',
			'forbidden' => '/\bagendamentos? de (?:público|audiência)/iu',
			'glossary'  => '/\breservas? de público-alvo\b/iu',
		),
		'signer'           => array(
			'source'    => '/\bsigners?\b/i',
			'forbidden' => '/\bassinantes?\b/iu',
			'glossary'  => '/\bsignatários?\b/iu',
		),
		'role'             => array(
			'source'    => '/\broles?\b/i',
			'forbidden' => '/\bpap(?:el|éis)\b/iu',
			'glossary'  => '/\bfunç(?:ão|ões)\b/iu',
		),
		'capability'       => array(
			'source'    => '/\bcapabilit(?:y|ies)\b/i',
			'forbidden' => '/\bcapacidades?\b/iu',
			'glossary'  => '/\bpermiss(?:ão|ões)\b/iu',
		),
		'short URL'        => array(
			'source'    => '/\bshort (?:url|link)s?\b/i',
			'forbidden' => '/\blinks? curtos?\b/iu',
			'glossary'  => '/\bURLs? curtas?\b/iu',
		),
		'activity log'     => array(
			'source'    => '/\bactivity[- ]log\b/i',
			'forbidden' => '/\blogs? de atividades?\b/iu',
			'glossary'  => '/\bregistro de atividades\b/iu',
		),
		'audience'         => array(
			'source'    => '/\baudiences?\b/i',
			'forbidden' => '/\baudiências?\b/iu',
			'glossary'  => '/\bpúblicos?-alvo\b/iu',
		),
	);

	/**
	 * Every translation of a glossary term uses the glossary word.
	 *
	 * @return void
	 */
	public function test_every_glossary_term_is_translated_with_its_glossary_word(): void {
		$this->assertSame(
			array(),
			self::violations( PoCatalogue::read( self::CATALOGUE ) ),
			'These translations use a word the pt_BR glossary replaced (#2670). Use the glossary word in RULES, with articles and adjectives in agreement.'
		);
	}

	/**
	 * Each rule reaches translations that use its glossary word, so a rule
	 * whose source pattern stopped matching cannot pass as clean.
	 *
	 * @return void
	 */
	public function test_every_rule_reaches_its_population(): void {
		$entries = PoCatalogue::read( self::CATALOGUE );
		foreach ( self::RULES as $term => $rule ) {
			$reached = 0;
			foreach ( $entries as $entry ) {
				if ( preg_match( $rule['source'], self::source( $entry ) ) && preg_match( $rule['glossary'], implode( ' ', $entry['forms'] ) ) ) {
					++$reached;
				}
			}
			$this->assertGreaterThan( 0, $reached, sprintf( 'The "%s" rule reaches no translation that uses its glossary word: its patterns no longer match the catalogue.', $term ) );
		}
	}

	/**
	 * The scan reads every entry of the file, counted again without the parser.
	 *
	 * @return void
	 */
	public function test_the_scan_reads_every_entry(): void {
		$raw = (string) file_get_contents( self::CATALOGUE );
		// Each entry opens with one msgid line; the header is the entry with an empty msgid.
		$recount = preg_match_all( '/^msgid "/m', $raw );
		$this->assertSame( $recount, count( PoCatalogue::read( self::CATALOGUE ) ) + 1 );
	}

	/**
	 * The check reports a forbidden word, and spares it where the source marks its other sense.
	 *
	 * @return void
	 */
	public function test_the_check_reports_a_forbidden_word_and_spares_its_other_sense(): void {
		$entry = static function ( string $msgid, string $msgstr ): array {
			return array(
				'key'          => $msgid,
				'msgctxt'      => null,
				'msgid'        => $msgid,
				'msgid_plural' => null,
				'forms'        => array( $msgstr ),
				'fuzzy'        => false,
			);
		};

		$found = self::violations(
			array(
				$entry( 'Submission saved.', 'Envio salvo.' ),
				$entry( 'The capability list', 'A lista de permissões' ),
				$entry( 'The manual send stamps the submission.', 'O envio manual marca a submissão.' ),
				$entry( 'Untranslated submission', '' ),
			)
		);

		$this->assertSame( array( 'submission: Submission saved. => Envio salvo.' ), $found );
	}

	/**
	 * Entries whose translation uses a forbidden word.
	 *
	 * @param array<int, array{key: string, msgctxt: string|null, msgid: string, msgid_plural: string|null, forms: array<int, string>, fuzzy: bool}> $entries Catalogue entries.
	 * @return array<int, string>
	 */
	private static function violations( array $entries ): array {
		$found = array();
		foreach ( $entries as $entry ) {
			$source      = self::source( $entry );
			$translation = implode( ' ', $entry['forms'] );
			if ( '' === trim( $translation ) ) {
				continue;
			}
			foreach ( self::RULES as $term => $rule ) {
				if ( ! preg_match( $rule['source'], $source ) || ! preg_match( $rule['forbidden'], $translation ) ) {
					continue;
				}
				if ( isset( $rule['other_sense'] ) && preg_match( $rule['other_sense'], $source ) ) {
					continue;
				}
				$found[] = $term . ': ' . mb_substr( $entry['msgid'], 0, 80 ) . ' => ' . mb_substr( $translation, 0, 80 );
			}
		}
		return $found;
	}

	/**
	 * The English text of an entry, plural included.
	 *
	 * @param array{msgid: string, msgid_plural: string|null} $entry Catalogue entry.
	 * @return string
	 */
	private static function source( array $entry ): string {
		return $entry['msgid'] . ' ' . (string) $entry['msgid_plural'];
	}
}
