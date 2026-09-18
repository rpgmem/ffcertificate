<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\DataSanitizer;

/**
 * One idiom per identifier, and a register for every site that keeps its own (#1314).
 *
 * WHAT WAS WRONG
 *
 * Two rules about the same two identifiers were written at 21 sites, and the
 * failure mode was proportional to how many spellings existed. The CPF/RF
 * classification -- *seven digits is an RF, everything else is a CPF* -- had
 * nine copies; they all agreed, so nothing was broken, but the rule could only
 * be changed in nine places at once. The e-mail canonical form had three
 * spellings and two of them were wrong: `sanitize_email()` strips invalid
 * characters and does NOT lowercase, so eight sites produced a different value
 * from the one the write path stored.
 *
 * One of those eight was not merely inconsistent. `RateLimitRepository` hashed
 * the address exactly as typed and compared it against a hash written from the
 * canonical form, so an address carrying a capital letter matched nothing and
 * the per-address submission limit counted zero prior submissions for the very
 * person it exists to stop.
 *
 * WHAT THIS PINS
 *
 * That neither rule can be written a second time. Both directions are
 * registers with a reason per entry, and both are ratchets: a new site fails,
 * and a registered site that stopped matching fails too, so an entry cannot
 * outlive the code it describes.
 *
 * WHAT IT DELIBERATELY DOES NOT PIN
 *
 * That a site calls the right one. A file can call `classify_cpf_rf()` and use
 * the answer backwards, and this scan reads presence, never meaning -- the
 * behavioural half at the bottom is what fixes the rule's content, and
 * `IdentityHashBoundaryTest` is what proves the hash boundary is correct.
 *
 * @covers \FreeFormCertificate\Core\DataSanitizer
 */
class IdentifierIdiomTest extends TestCase {

	/**
	 * The one file allowed to decide what seven digits means, plus the sites
	 * that legitimately test the same length for a different job.
	 *
	 * Validation is not classification: these four reject anything that is
	 * neither 7 nor 11 rather than route it, which is the layer that makes the
	 * classifier's fallback unreachable on the public paths.
	 *
	 * @var array<string, string>
	 */
	private const LENGTH_RULE_ALLOWED = array(
		'includes/core/class-ffc-data-sanitizer.php'                                   => 'Declares the rule. Every other site asks it.',
		'includes/core/class-ffc-document-formatter.php'                               => 'Validates and formats a value whose kind is already known, and its own auto-detection carries a third kind (12 digits = auth code) plus an unknown-length branch that returns the input untouched -- a display decision, not a storage one.',
		'includes/self-scheduling/class-ffc-self-scheduling-appointment-validator.php' => 'Entry validation: rejects any length that is neither 7 nor 11.',
		'includes/frontend/submission/class-ffc-field-sanitizer.php'                   => 'Entry validation on the public certificate form.',
		'includes/api/class-ffc-form-rest-controller.php'                              => 'Entry validation on the REST submission route.',
		'includes/api/class-ffc-operator-certificates-rest-controller.php'             => 'Entry validation on the operator REST route.',
	);

	/**
	 * Sites that lowercase and trim something that is not an e-mail address.
	 *
	 * @var array<string, string>
	 */
	private const CASE_FOLD_ALLOWED = array(
		'includes/core/class-ffc-data-sanitizer.php'                                => 'Declares the canonical form. Every other site asks it.',
		'includes/reregistration/class-ffc-reregistration-import-staging-service.php' => '`fold()` case-folds a CSV header cell or a field name, never an address.',
		'includes/recruitment/class-ffc-csv-parser.php'                             => 'Case-folds a CSV header name for column matching.',
		'includes/user-dashboard/class-ffc-user-creator.php'                        => 'Builds a login from the local part of an address; it is a username, never a lookup key.',
	);

	/**
	 * Sites that strip a value to digits without asking for the canonical form.
	 *
	 * @var array<string, string>
	 */
	private const DIGIT_STRIP_ALLOWED = array(
		'includes/core/class-ffc-data-sanitizer.php' => 'Declares the canonical form. Every other site asks it.',
	);

	/**
	 * Sites that call `sanitize_email()` without canonicalising the result.
	 *
	 * @var array<string, string>
	 */
	private const BARE_SANITIZE_EMAIL_ALLOWED = array(
		'includes/admin/class-ffc-settings-save-handler.php' => 'The SMTP sender address is a setting, not an identifier: it is never hashed and nothing is looked up by it.',
	);

	/**
	 * Every PHP file under `includes/`, repository-relative.
	 *
	 * @return list<string>
	 */
	private function source_files(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				$files[] = str_replace( $root . '/', '', $file->getPathname() );
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * A file's code with every comment blanked and line numbers preserved.
	 *
	 * Read through `token_get_all()` rather than as text, for the reason
	 * `DeprecationDueTest` reads tokens: prose that MENTIONS an idiom is not an
	 * occurrence of it, and this file's own docblocks quote both idioms
	 * verbatim. A text scan reports them and the register grows to describe the
	 * documentation rather than the code.
	 *
	 * @param string $relative Repository-relative path.
	 * @return list<string> One entry per source line, 0-indexed.
	 */
	private function code_lines( string $relative ): array {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative );
		$code   = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( ! is_array( $token ) ) {
				$code .= $token;
				continue;
			}

			if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
				// Keep the newlines so a reported line number is the real one.
				$code .= str_repeat( "\n", substr_count( $token[1], "\n" ) );
				continue;
			}

			$code .= $token[1];
		}

		return explode( "\n", $code );
	}

	/**
	 * Files whose code matches a pattern, with the offending lines.
	 *
	 * @param string $pattern PCRE.
	 * @return array<string, list<string>>
	 */
	private function files_matching( string $pattern ): array {
		$hits = array();

		foreach ( $this->source_files() as $relative ) {
			foreach ( $this->code_lines( $relative ) as $number => $line ) {
				if ( 1 === preg_match( $pattern, $line ) ) {
					$hits[ $relative ][] = ( $number + 1 ) . ': ' . trim( $line );
				}
			}
		}

		return $hits;
	}

	/**
	 * Both directions of one register, so an entry cannot outlive its code.
	 *
	 * @param array<string, list<string>> $hits    Measured.
	 * @param array<string, string>       $allowed Register.
	 * @param string                      $noun    What the pattern finds.
	 * @return void
	 */
	private function assert_register_holds( array $hits, array $allowed, string $noun ): void {
		foreach ( $hits as $relative => $lines ) {
			$this->assertArrayHasKey(
				$relative,
				$allowed,
				"{$relative} writes its own {$noun}:\n  " . implode( "\n  ", $lines )
					. "\nAsk the one function for it, or register the file here with the reason it cannot."
			);
		}

		foreach ( array_keys( $allowed ) as $relative ) {
			$this->assertArrayHasKey(
				$relative,
				$hits,
				"{$relative} is registered as keeping its own {$noun} and no longer has one — drop the entry to lock the win in."
			);
		}
	}

	/**
	 * What seven digits means is decided in one place.
	 */
	public function test_no_file_classifies_a_cpf_or_rf_by_its_own_length_test(): void {
		$hits = $this->files_matching( '/strlen\s*\([^)]*\)\s*===?\s*7|7\s*===?\s*strlen\s*\(/' );

		$this->assertNotSame(
			array(),
			$hits,
			'No seven-digit length test was found anywhere, including in the file that declares the rule — the scan matched nothing and would pass on an empty tree.'
		);

		$this->assert_register_holds( $hits, self::LENGTH_RULE_ALLOWED, 'seven-digit length test' );
	}

	/**
	 * The canonical form of an address is decided in one place.
	 */
	public function test_no_file_case_folds_an_address_with_its_own_chain(): void {
		$hits = $this->files_matching( '/strtolower\(\s*(trim|sanitize_email)\(/' );

		$this->assertNotSame(
			array(),
			$hits,
			'No hand-rolled case fold was found anywhere, including in the file that declares the canonical form — the scan matched nothing and would pass on an empty tree.'
		);

		$this->assert_register_holds( $hits, self::CASE_FOLD_ALLOWED, 'hand-rolled case fold' );
	}

	/**
	 * An address that reaches a hash or a lookup is canonicalised first.
	 *
	 * `sanitize_email()` judges validity and leaves case alone, so it is the
	 * right call and never the whole one: the eight sites that stopped there
	 * produced a value the write path had already lowercased.
	 */
	public function test_no_file_takes_a_sanitized_address_without_canonicalising_it(): void {
		$hits = array();

		foreach ( $this->source_files() as $relative ) {
			foreach ( $this->code_lines( $relative ) as $number => $line ) {
				if ( 1 !== preg_match( '/sanitize_email\s*\(/', $line ) ) {
					continue;
				}

				if ( 1 === preg_match( '/normalize_email\s*\(/', $line ) ) {
					continue;
				}

				$hits[ $relative ][] = ( $number + 1 ) . ': ' . trim( $line );
			}
		}

		$this->assertNotSame(
			array(),
			$hits,
			'No uncanonicalised sanitize_email() was found anywhere — the scan matched nothing and would pass on an empty tree.'
		);

		$this->assert_register_holds( $hits, self::BARE_SANITIZE_EMAIL_ALLOWED, 'uncanonicalised sanitize_email()' );
	}

	/**
	 * A CPF/RF is stripped to digits in one place.
	 *
	 * This is the third spelling of the same defect class and the most numerous
	 * -- `normalize_cpf_rf()` has existed since 6.6.1 and 19 sites reproduced
	 * its body instead of calling it, which is precisely the exposure #1314
	 * names: one function means CPF only ever diverged where the function was
	 * not called at all.
	 */
	public function test_no_file_strips_a_value_to_digits_with_its_own_expression(): void {
		$hits = $this->files_matching( "/preg_replace\\(\\s*'\\/(\\\\D|\\[\\^0-9\\])\\+?\\/'/" );

		$this->assertNotSame(
			array(),
			$hits,
			'No digit strip was found anywhere, including in the file that declares it — the scan matched nothing and would pass on an empty tree.'
		);

		$this->assert_register_holds( $hits, self::DIGIT_STRIP_ALLOWED, 'hand-rolled digit strip' );
	}

	/**
	 * The rule itself, stated once so the register above has something to mean.
	 *
	 * @dataProvider identifier_provider
	 * @param string $value    What a caller holds.
	 * @param string $expected The kind it routes to.
	 */
	public function test_the_classifier_states_the_rule( string $value, string $expected ): void {
		$this->assertSame( $expected, DataSanitizer::classify_cpf_rf( $value ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function identifier_provider(): array {
		return array(
			'seven digits is an RF'                 => array( '1234567', 'rf' ),
			'a masked RF is still an RF'            => array( '123.456-7', 'rf' ),
			'eleven digits is a CPF'                => array( '12345678909', 'cpf' ),
			'a masked CPF is still a CPF'           => array( '123.456.789-09', 'cpf' ),
			'an unknown length routes as CPF'       => array( '123456789', 'cpf' ),
			'an empty value routes as CPF'          => array( '', 'cpf' ),
			'letters around seven digits are ignored' => array( 'RF 123 456 7', 'rf' ),
		);
	}

	/**
	 * The certificate and appointment paths cannot disagree about a value.
	 *
	 * Both collect ONE combined `cpf_rf` field and split it into the separate
	 * `cpf` / `rf` columns, and before #1314 each decided the split for itself.
	 * That they now ask the same function is what the register above enforces;
	 * this states the property that follows from it.
	 */
	public function test_the_same_value_routes_to_the_same_kind_however_it_is_spelled(): void {
		$as_typed = ' 123.456-7 ';
		$as_stored = DataSanitizer::normalize_cpf_rf( $as_typed );

		$this->assertSame(
			DataSanitizer::classify_cpf_rf( $as_typed ),
			DataSanitizer::classify_cpf_rf( $as_stored ),
			'A masked value and its digits-only form route to different columns, so a reader cannot find what the writer stored.'
		);
	}
}
