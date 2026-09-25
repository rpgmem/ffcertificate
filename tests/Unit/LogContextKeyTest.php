<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\SensitiveFieldRegistry;

/**
 * A log context key that names an identifier must hold one (#1448).
 *
 * WHAT WAS WRONG
 *
 * `IdentityAdoption` logged `'cpf' => substr( $cpf_hash, 0, 12 )` -- a truncated
 * hash under a key naming the document -- and its own comment said so:
 * *"Prefixes, never the hashes and never the values"*. The comment was right and
 * the key was not.
 *
 * `SensitiveFieldRegistry::contains_sensitive()` matches on the **key name
 * alone**: `walk_for_sensitive()` tests `isset( $sensitive[ $key ] )` and never
 * looks at the value. So that context classified as sensitive, and
 * `ActivityLog::log()` encrypted it.
 *
 * WHY IT COST NOTHING UNTIL IT DID
 *
 * Until #1444 the `context_encrypted` column did not exist, so the ciphertext
 * was discarded and the misnomer was free. Declaring the column turned the
 * branch on: every adoption began encrypting two hash prefixes and a boolean,
 * while the Activity Log screen -- which prints the context as raw JSON, with no
 * label map -- showed a key naming an identifier the row does not carry.
 *
 * WHAT THIS PINS
 *
 * The rule, not the rename: a literal context key that the registry treats as
 * sensitive must be there because the value really is that identifier. One
 * register entry, with its reason.
 *
 * WHAT IT DELIBERATELY DOES NOT SEE
 *
 * The **dynamic** half of the sensitive set. `dynamic_sensitive_keys()` reads
 * `ffc_custom_fields.is_sensitive`, so it lives in the database and no static
 * scan can know it -- an administrator can make `matricula` sensitive and this
 * guard will not notice a context keyed on it. Only `universal_sensitive_keys()`
 * is read here, and it is read from the registry rather than copied, because a
 * copied list goes stale the day a field is added to `FIELDS`.
 *
 * Nor a context that is not a literal array: 24 of the 89 call sites hand over a
 * variable, and those are counted but not inspected. They are not registered
 * one by one, because unlike a schema write the risk here is a misnamed key
 * rather than a missing column -- a variable context is built somewhere this
 * scan would have to follow, and the honest statement is that it does not.
 *
 * @coversNothing
 */
class LogContextKeyTest extends TestCase {

	/**
	 * Context keys that name a sensitive identifier ON PURPOSE.
	 *
	 * A ratchet both ways: an entry that stops matching fails, so it cannot
	 * outlive the code it describes.
	 *
	 * @var array<string, string> "<relative path>:<key>" => reason.
	 */
	private const CARRIES_THE_VALUE = array(
		'includes/privacy/class-ffc-privacy-erasers.php:email' =>
			'The subject\'s real address in the `privacy_data_erased` record, which is the LGPD proof of erasure. It IS the identifier, so classifying the context as sensitive -- and encrypting it -- is the correct outcome rather than an accident of naming.',
	);

	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Every `ActivityLog::log()` call under `includes/`, with its arguments.
	 *
	 * THE RECEIVER IS A SINGLE TOKEN WHEN IT IS QUALIFIED, and that is the
	 * #1284 trap: `\FreeFormCertificate\Core\ActivityLog` tokenizes as one
	 * `T_NAME_FULLY_QUALIFIED`, so comparing the token text to `ActivityLog`
	 * matched nothing. Measured: the first version of this scan saw 59 of the 89
	 * calls and reported ZERO sensitive keys, having missed the one legitimate
	 * occurrence -- a clean result produced by a broken reader.
	 *
	 * @param string $path File path.
	 * @return list<array{line: int, args: list<string>}>
	 */
	private function log_calls( string $path ): array {
		$tokens = token_get_all( (string) file_get_contents( $path ) );
		$out    = array();
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) || T_STRING !== $token[0] || 'log' !== $token[1] ) {
				continue;
			}

			$k = $this->back_over_space( $tokens, $i - 1 );

			if ( ! ( is_array( $tokens[ $k ] ) && T_DOUBLE_COLON === $tokens[ $k ][0] ) ) {
				continue;
			}

			$k        = $this->back_over_space( $tokens, $k - 1 );
			$receiver = is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : (string) $tokens[ $k ];
			$short    = (string) substr( (string) strrchr( '\\' . $receiver, '\\' ), 1 );

			if ( ! in_array( $receiver, array( 'self', 'static' ), true ) && 'ActivityLog' !== $short ) {
				continue;
			}

			$j = $i + 1;

			while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				++$j;
			}

			if ( '(' !== $tokens[ $j ] ) {
				continue;
			}

			$out[] = array(
				'line' => (int) $token[2],
				'args' => $this->top_level_args( $tokens, $j ),
			);
		}

		return $out;
	}

	/**
	 * @param array<int, mixed> $tokens Token list.
	 */
	private function back_over_space( array $tokens, int $i ): int {
		while ( $i > 0 && is_array( $tokens[ $i ] ) && T_WHITESPACE === $tokens[ $i ][0] ) {
			--$i;
		}

		return $i;
	}

	/**
	 * @param array<int, mixed> $tokens Token list.
	 * @return list<string>
	 */
	private function top_level_args( array $tokens, int $open ): array {
		$depth   = 0;
		$args    = array();
		$current = '';
		$count   = count( $tokens );

		for ( $k = $open; $k < $count; $k++ ) {
			$text = is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ];

			if ( '(' === $text || '[' === $text ) {
				++$depth;
				if ( 1 === $depth ) {
					continue;
				}
			} elseif ( ')' === $text || ']' === $text ) {
				--$depth;
				if ( 0 === $depth ) {
					$args[] = $current;
					break;
				}
			} elseif ( ',' === $text && 1 === $depth ) {
				$args[]  = $current;
				$current = '';
				continue;
			}

			$current .= $text;
		}

		return array_map( 'trim', $args );
	}

	/**
	 * @return array{total: int, literal: int, variable: int, hits: array<string, string>}
	 */
	private function scan(): array {
		$sensitive = SensitiveFieldRegistry::universal_sensitive_keys();
		$out       = array(
			'total'    => 0,
			'literal'  => 0,
			'variable' => 0,
			'hits'     => array(),
		);

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root() . '/includes', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$path = $file->getPathname();

			if ( substr( $path, -4 ) !== '.php' ) {
				continue;
			}

			$relative = substr( $path, strlen( $this->root() ) + 1 );

			foreach ( $this->log_calls( $path ) as $call ) {
				++$out['total'];

				$context = $call['args'][2] ?? '';

				if ( '' === $context ) {
					continue;
				}

				if ( 0 !== strpos( $context, 'array(' ) && 0 !== strpos( $context, '[' ) ) {
					++$out['variable'];
					continue;
				}

				++$out['literal'];

				preg_match_all( "/'([a-z_][a-z0-9_]*)'\s*=>/", $context, $keys );

				foreach ( $keys[1] as $key ) {
					if ( isset( $sensitive[ $key ] ) ) {
						$out['hits'][ $relative . ':' . $key ] = $relative . ':' . $call['line'];
					}
				}
			}
		}

		return $out;
	}

	// ==================================================================

	/**
	 * The rule: a sensitive-named key is registered or it is a defect.
	 */
	public function test_a_sensitive_context_key_is_registered_or_holds_the_value(): void {
		$scan       = $this->scan();
		$unexpected = array_diff_key( $scan['hits'], self::CARRIES_THE_VALUE );

		$this->assertSame(
			array(),
			$unexpected,
			"These log contexts name a key SensitiveFieldRegistry treats as sensitive:\n"
			. implode( "\n", array_map(
				static fn( string $k, string $v ): string => "  {$k}  at {$v}",
				array_keys( $unexpected ),
				array_values( $unexpected )
			) )
			. "\nThe match is on the KEY NAME alone, so the whole context is encrypted"
			. ' whether or not the value is that identifier. If it is, register it with'
			. ' the reason; if it is not, rename the key to say what it holds.'
		);
	}

	/**
	 * Every register entry still describes a real call.
	 */
	public function test_every_register_entry_still_matches_a_context(): void {
		$scan = $this->scan();

		foreach ( array_keys( self::CARRIES_THE_VALUE ) as $entry ) {
			$this->assertArrayHasKey(
				$entry,
				$scan['hits'],
				sprintf(
					'`%s` is excused here and no longer matches a log context. Drop the entry --'
					. ' an exception that describes no code makes this guard narrower and says so'
					. ' to nobody.',
					$entry
				)
			);
		}
	}

	/**
	 * The adoption context no longer names an identifier it does not carry.
	 *
	 * The canary for #1448 itself: the two keys carry a 12-character hash
	 * prefix, so the context must not classify as sensitive at all.
	 */
	public function test_the_adoption_context_does_not_classify_as_sensitive(): void {
		$scan = $this->scan();

		foreach ( array_keys( $scan['hits'] ) as $entry ) {
			$this->assertStringNotContainsString(
				'class-ffc-identity-adoption.php',
				$entry,
				'The adoption context names a sensitive key again. Its CPF and RF values are'
				. ' 12-character hash prefixes, so encrypting the row buys nothing and the key'
				. ' names an identifier the row does not carry.'
			);
		}

		$source = (string) file_get_contents( $this->root() . '/includes/maintenance/class-ffc-identity-adoption.php' );

		$this->assertStringContainsString( "'cpf_prefix'", $source, 'The renamed key is gone, so this canary is measuring nothing.' );
		$this->assertStringContainsString( "'rf_prefix'", $source, 'The renamed key is gone, so this canary is measuring nothing.' );
	}

	/**
	 * The scan read every call site, by an independent count.
	 *
	 * Comments stripped, then a regex: a docblock naming the method is prose,
	 * not a call, and a raw grep over the tree reports 91 against the walk's 89
	 * for exactly that reason. The same read-tokens-never-lines rule the
	 * suppression and comment-language guards follow.
	 */
	public function test_the_scan_reached_every_log_call(): void {
		$scan    = $this->scan();
		$recount = 0;
		$files   = 0;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root() . '/includes', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$path = $file->getPathname();

			if ( substr( $path, -4 ) !== '.php' ) {
				continue;
			}

			++$files;
			$code = '';

			foreach ( token_get_all( (string) file_get_contents( $path ) ) as $token ) {
				if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}

				$code .= is_array( $token ) ? $token[1] : $token;
			}

			$recount += preg_match_all( '/(?:ActivityLog|self|static)::log\s*\(/', $code );
		}

		$this->assertGreaterThan( 0, $files, 'The walk found no PHP file at all.' );
		$this->assertSame(
			$recount,
			$scan['total'],
			'The token walk and an independent comment-stripped recount disagree about how'
			. ' many log calls exist. One of the two stopped seeing part of the tree.'
		);
	}

	/**
	 * The sensitive set is read from the registry and is not empty.
	 */
	public function test_the_sensitive_set_comes_from_the_registry(): void {
		$keys = SensitiveFieldRegistry::universal_sensitive_keys();

		$this->assertNotEmpty( $keys, 'The registry answered no sensitive keys, so every assertion above is vacuous.' );
		$this->assertArrayHasKey( 'cpf', $keys, 'The registry no longer treats `cpf` as sensitive, which this guard is built on.' );
		$this->assertArrayHasKey( 'rf', $keys, 'The registry no longer treats `rf` as sensitive.' );
	}
}
