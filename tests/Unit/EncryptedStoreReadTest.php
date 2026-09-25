<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Ciphertext may not be read and used as though it were plaintext (#1446).
 *
 * THE DEFECT
 *
 * `CsvDownloadValidator`'s `owner` gate read `ffc_user_cpf` and handed it to
 * `normalize_cpf_rf()`. That meta is always a `v2:` envelope, so the gate
 * compared digits scraped out of base64 against an 11-digit CPF and could never
 * match: every visitor was told their CPF did not match the author's (#1443).
 *
 * Three identity guards exist and none looks for this shape.
 * `IdentityConvergenceGuardTest` watches who HASHES an identifier outside the
 * registry; `IdentifierIdiomTest` watches who CLASSIFIES, case-folds or
 * digit-strips with its own expression; `IdentityHashBoundaryTest` proves the
 * hash boundary is correct. This site did none of those — it read ciphertext and
 * treated it as a value — so it appeared in no allowlist, because it never
 * needed an exception.
 *
 * WHAT THIS ENFORCES, AND WHAT IT REFUSES TO PRETEND
 *
 * Two rules, both per STATEMENT, both blocking at zero:
 *
 *  - **A.** A read of a sensitive `ffc_user_*` meta must decrypt or hash in its
 *    own statement. This is the shape that shipped, and it needs no dataflow:
 *    #1443's `$author_cpf = (string) get_user_meta( …, 'ffc_user_cpf', true );`
 *    carries no `decrypt(` and is flagged where it stands.
 *  - **B.** A read of a `*_encrypted` key may not sit DIRECTLY inside a call
 *    that treats its argument as plaintext.
 *
 * THE DATAFLOW VERSION WAS BUILT FIVE TIMES AND IS NOT SHIPPED
 *
 * The general rule — *ciphertext must not REACH a plaintext operation* — needs
 * to follow a value across statements, and five attempts each failed for a
 * different reason: direct nesting only; an assignment reader that broke on the
 * first token; following a variable NAME rather than its value, which reported
 * every decrypted-then-displayed field as a defect; a whitespace-only skip that
 * a comment stopped; and a 60-token lookback that a 30-line comment block
 * overruns, each `//` line being its own token.
 *
 * **Every one of those five reported zero findings on a clean tree**, which is
 * indistinguishable from working. A guard like that, merged, is a gate everybody
 * trusts and that detects nothing — the failure mode #1428 spent an arc
 * removing. So the narrow rules ship and the dataflow verdict is recorded in
 * #1446 rather than approximated here.
 *
 * WHY THE CANARIES ARE NOT OPTIONAL
 *
 * Both rules find NOTHING on the current tree: rule A's population is zero
 * because #1443's fix removed the only sensitive-meta read in the plugin, and
 * rule B's 78 reads are all decrypt arguments or presence tests. A scan with no
 * findings must prove it can produce one, so each rule is run against synthetic
 * source carrying the defect — the shape `DeprecationDueTest` uses, and the only
 * honest self-check when the live population is empty.
 *
 * The scanner lives here rather than in `tests/Support/`: one guard reads it, and
 * extracting it on spec is the bad-façade trap. A second consumer is the trigger.
 *
 * @coversNothing
 */
class EncryptedStoreReadTest extends TestCase {

	/**
	 * Metas holding a `v2:` envelope, per `UserProfileFieldMap`'s sensitive flags.
	 *
	 * @var array<int, string>
	 */
	private const SENSITIVE_META = array( 'ffc_user_cpf', 'ffc_user_rf', 'ffc_user_rg' );

	/**
	 * Calls that treat their argument as a readable value.
	 *
	 * `hash_identifier` is here deliberately: hashing ciphertext produces a hash
	 * of base64, which matches nothing and fails silently — the same class of
	 * defect as comparing it, not a safe use.
	 */
	private const PLAINTEXT_SINKS = '/(?:normalize_cpf_rf|classify_cpf_rf|hash_identifier|esc_html|esc_attr|esc_textarea|sanitize_text_field|sanitize_email|format_cpf|format_rf)$/';

	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * The WHOLE statement a token sits in, both directions.
	 *
	 * Forward only is not enough and the negative canary is what said so: in
	 * `Encryption::decrypt( (string) get_user_meta( …, 'ffc_user_cpf', true ) )`
	 * the decrypt sits BEFORE the read, so a forward-only reader calls the
	 * correct shape a defect.
	 *
	 * There is NO TOKEN CAP on the backward walk, deliberately. A capped
	 * lookback is what the abandoned dataflow version tripped on: a 30-line
	 * comment block is 30 tokens, so a 60-token budget never reached the
	 * statement boundary. Walking to the boundary itself always terminates,
	 * because a function body opens with one.
	 *
	 * @param array<int, mixed> $tokens Token list.
	 */
	private function statement_from( array $tokens, int $i ): string {
		$count = count( $tokens );
		$start = $i;

		for ( $m = $i; $m >= 0; $m-- ) {
			$piece = is_array( $tokens[ $m ] ) ? $tokens[ $m ][1] : $tokens[ $m ];

			if ( ';' === $piece || '{' === $piece || '}' === $piece ) {
				break;
			}

			$start = $m;
		}

		$text = '';

		for ( $m = $start; $m < $count; $m++ ) {
			$piece = is_array( $tokens[ $m ] ) ? $tokens[ $m ][1] : $tokens[ $m ];

			if ( ';' === $piece ) {
				break;
			}

			// COMMENTS ARE NOT CODE, and this is where that cost the most.
			// #1443's fix carries a 30-line comment explaining the hash
			// comparison, and it NAMES `hash_identifier()` in prose. With
			// comments included, re-introducing the defect left this guard GREEN:
			// the rule saw the word in the text and declared the read safe. The
			// canary below now carries a comment mentioning a sink for exactly
			// that reason -- a fixture that sketches the hostile case instead of
			// reproducing it is a fixture that passes while the tree is broken.
			if ( is_array( $tokens[ $m ] ) && in_array( $tokens[ $m ][0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			$text .= $piece;
		}

		return $text;
	}

	/**
	 * Rule A: sensitive-meta reads whose own statement neither decrypts nor hashes.
	 *
	 * @return array{reads: int, bad: list<string>}
	 */
	private function undecrypted_meta_reads( string $source, string $label ): array {
		$tokens = token_get_all( $source );
		$count  = count( $tokens );
		$out    = array(
			'reads' => 0,
			'bad'   => array(),
		);

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) || T_STRING !== $token[0] || 'get_user_meta' !== $token[1] ) {
				continue;
			}

			$statement = $this->statement_from( $tokens, $i );
			$named     = null;

			foreach ( self::SENSITIVE_META as $meta ) {
				if ( false !== strpos( $statement, "'" . $meta . "'" ) ) {
					$named = $meta;
				}
			}

			if ( null === $named ) {
				continue;
			}

			++$out['reads'];

			if ( ! preg_match( '/decrypt\s*\(|hash_identifier\s*\(/', $statement ) ) {
				$out['bad'][] = $label . ':' . $token[2] . ' reads ' . $named;
			}
		}

		return $out;
	}

	/**
	 * Rule B: a `*_encrypted` read sitting directly inside a plaintext sink.
	 *
	 * @return array{reads: int, bad: list<string>}
	 */
	private function ciphertext_in_a_sink( string $source, string $label ): array {
		$tokens = token_get_all( $source );
		$count  = count( $tokens );
		$out    = array(
			'reads' => 0,
			'bad'   => array(),
		);

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
				continue;
			}

			$key = trim( $token[1], "'\"" );

			if ( ! preg_match( '/_encrypted$/', $key ) ) {
				continue;
			}

			$before = $i - 1;

			while ( $before >= 0 && is_array( $tokens[ $before ] ) && T_WHITESPACE === $tokens[ $before ][0] ) {
				--$before;
			}

			if ( '[' !== $tokens[ $before ] ) {
				continue;
			}

			// An assignment TARGET is a write, not a read: `$a['x_encrypted'] = …`.
			$after = $i + 1;

			while ( $after < $count && ']' !== $tokens[ $after ] ) {
				++$after;
			}

			++$after;

			while ( $after < $count && is_array( $tokens[ $after ] ) && T_WHITESPACE === $tokens[ $after ][0] ) {
				++$after;
			}

			if ( '=' === ( $tokens[ $after ] ?? null ) ) {
				continue;
			}

			++$out['reads'];

			// Start before the read's OWN `[`: the first bracket walking back is
			// part of `$row['x_encrypted']`, not a call enclosing it, and the
			// positive canary is what caught that.
			$sink = $this->enclosing_sink( $tokens, $before - 1 );

			if ( null !== $sink ) {
				$out['bad'][] = $label . ':' . $token[2] . ' ' . $key . ' -> ' . $sink;
			}
		}

		return $out;
	}

	/**
	 * The name of the call that directly encloses a token, when it is a sink.
	 *
	 * @param array<int, mixed> $tokens Token list.
	 */
	private function enclosing_sink( array $tokens, int $i ): ?string {
		$depth = 0;

		for ( $m = $i; $m >= 0; $m-- ) {
			$piece = is_array( $tokens[ $m ] ) ? $tokens[ $m ][1] : $tokens[ $m ];

			if ( ')' === $piece || ']' === $piece ) {
				++$depth;
				continue;
			}

			if ( '(' === $piece || '[' === $piece ) {
				if ( 0 === $depth ) {
					$head = $m - 1;

					while ( $head >= 0 && is_array( $tokens[ $head ] ) && T_WHITESPACE === $tokens[ $head ][0] ) {
						--$head;
					}

					$name = is_array( $tokens[ $head ] ) ? $tokens[ $head ][1] : (string) ( $tokens[ $head ] ?? '' );

					// THE FIRST ENCLOSING CALL DECIDES, and walking past it is the
					// bug the negative canary caught: in
					// `esc_html( Encryption::decrypt( $row['email_encrypted'] ) )`
					// the call that directly encloses the read is `decrypt`, and
					// continuing outward finds `esc_html` and calls the correct
					// shape a defect. "Directly inside" means the innermost one.
					return preg_match( self::PLAINTEXT_SINKS, $name ) ? $name : null;
				}

				--$depth;
				continue;
			}

			if ( ';' === $piece && 0 === $depth ) {
				break;
			}
		}

		return null;
	}

	/**
	 * @return list<array{path: string, label: string}>
	 */
	private function sources(): array {
		$out = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root() . '/includes', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$path = $file->getPathname();

			if ( substr( $path, -4 ) !== '.php' ) {
				continue;
			}

			$out[] = array(
				'path'  => $path,
				'label' => substr( $path, strlen( $this->root() ) + 1 ),
			);
		}

		return $out;
	}

	// ==================================================================

	/**
	 * RULE A, blocking at zero.
	 */
	public function test_no_sensitive_meta_is_read_without_decrypting_it(): void {
		$bad = array();

		foreach ( $this->sources() as $source ) {
			$result = $this->undecrypted_meta_reads( (string) file_get_contents( $source['path'] ), $source['label'] );
			$bad    = array_merge( $bad, $result['bad'] );
		}

		$this->assertSame(
			array(),
			$bad,
			"These statements read a `v2:` envelope and neither decrypt nor hash it:\n  "
			. implode( "\n  ", $bad )
			. "\nThe value is base64, so whatever is done with it next -- comparing,"
			. ' normalising, displaying -- fails silently rather than loudly. Decrypt it,'
			. ' or compare the stored hash instead, which needs no plaintext at all.'
		);
	}

	/**
	 * RULE B, blocking at zero.
	 */
	public function test_no_ciphertext_is_handed_straight_to_a_plaintext_call(): void {
		$bad = array();

		foreach ( $this->sources() as $source ) {
			$result = $this->ciphertext_in_a_sink( (string) file_get_contents( $source['path'] ), $source['label'] );
			$bad    = array_merge( $bad, $result['bad'] );
		}

		$this->assertSame(
			array(),
			$bad,
			"These reads hand ciphertext directly to a call that treats it as a value:\n  "
			. implode( "\n  ", $bad )
		);
	}

	/**
	 * RULE A's canary: the scan produces a finding when one exists.
	 *
	 * Synthetic source carrying #1443 verbatim, INCLUDING the comment block
	 * before it — a 30-line `//` run is what overran the dataflow version's
	 * lookback, each line being its own token, so the fixture keeps it.
	 */
	public function test_rule_a_reports_the_defect_that_shipped(): void {
		$synthetic = <<<'PHP'
<?php
class Probe {
	public function gate( int $author_id, string $digits ): bool {
		// A comment standing between the statements exactly as the shipped
		// code had them, because a token-counting lookback overruns on a run
		// of these -- AND naming a sink in prose, which is what made an
		// earlier version of this guard pass over the real defect: the fix
		// that replaced this code explains itself with the words
		// `hash_identifier()` and `hash_equals`, and a statement reader that
		// keeps comments reads them as the read being safe.
		$author_cpf = (string) get_user_meta( $author_id, 'ffc_user_cpf', true );
		$author_dig = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $author_cpf );

		return $author_dig === $digits;
	}
}
PHP;

		$result = $this->undecrypted_meta_reads( $synthetic, 'synthetic' );

		$this->assertSame( 1, $result['reads'], 'The scan did not even see the sensitive-meta read.' );
		$this->assertCount( 1, $result['bad'], 'The scan saw the read and did not report it as undecrypted.' );
		$this->assertStringContainsString( 'ffc_user_cpf', $result['bad'][0] );
	}

	/**
	 * RULE A's negative canary: a decrypted read is not a finding.
	 */
	public function test_rule_a_accepts_a_read_that_decrypts(): void {
		$synthetic = <<<'PHP'
<?php
$plain = \FreeFormCertificate\Core\Encryption::decrypt( (string) get_user_meta( $id, 'ffc_user_cpf', true ) );
PHP;

		$result = $this->undecrypted_meta_reads( $synthetic, 'synthetic' );

		$this->assertSame( 1, $result['reads'], 'The scan did not see the read.' );
		$this->assertSame( array(), $result['bad'], 'A read that decrypts in its own statement must not be a finding.' );
	}

	/**
	 * RULE B's canary, both ways.
	 */
	public function test_rule_b_reports_ciphertext_in_a_sink_and_accepts_a_decrypt(): void {
		$bad_source = <<<'PHP'
<?php
$out = esc_html( $row['email_encrypted'] );
PHP;

		$good_source = <<<'PHP'
<?php
$out = esc_html( \FreeFormCertificate\Core\Encryption::decrypt( $row['email_encrypted'] ) );
$row['email_encrypted'] = \FreeFormCertificate\Core\Encryption::encrypt( $plain );
PHP;

		$bad = $this->ciphertext_in_a_sink( $bad_source, 'synthetic' );
		$this->assertSame( 1, $bad['reads'] );
		$this->assertCount( 1, $bad['bad'], 'Ciphertext handed straight to esc_html() must be a finding.' );

		$good = $this->ciphertext_in_a_sink( $good_source, 'synthetic' );
		$this->assertSame( 1, $good['reads'], 'The write target must not count as a read, and the decrypted one must.' );
		$this->assertSame( array(), $good['bad'], 'A decrypt between the read and the sink is the correct shape.' );
	}

	/**
	 * Both scans looked at the tree, and rule B has a real population.
	 *
	 * Rule A finds no reads at all today -- #1443's fix removed the only one --
	 * so its self-check is the canary above rather than a count. Rule B has 78
	 * reads, and an independent comment-stripped recount is what says the walk
	 * saw them; a bare floor would decay as the population grows.
	 */
	public function test_the_scans_read_the_tree(): void {
		$reads   = 0;
		$files   = 0;
		$recount = 0;

		foreach ( $this->sources() as $source ) {
			++$files;
			$text    = (string) file_get_contents( $source['path'] );
			$reads  += $this->ciphertext_in_a_sink( $text, $source['label'] )['reads'];
			$code    = '';

			foreach ( token_get_all( $text ) as $token ) {
				if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}

				$code .= is_array( $token ) ? $token[1] : $token;
			}

			// Reads only: a `[ 'x_encrypted' ]` not followed by `=`.
			$recount += preg_match_all( '/\[\s*[\'"][a-z_]+_encrypted[\'"]\s*\](?!\s*=[^=>])/', $code );
		}

		$this->assertGreaterThan( 0, $files, 'The walk found no PHP file at all.' );
		$this->assertSame(
			$recount,
			$reads,
			'The token walk and an independent comment-stripped recount disagree about how'
			. ' many ciphertext reads exist. One of the two stopped seeing part of the tree.'
		);
	}
}
