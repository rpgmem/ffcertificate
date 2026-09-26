<?php
/**
 * A cursor key has to survive a URL.
 *
 * @package FreeFormCertificate
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Admin\IdentityQueuePanels;
use FreeFormCertificate\Maintenance\IdentityQueue;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `IdentityQueue::key_of()` travels in the query string, so what it may
 * contain is bounded by the URL and not by our taste.
 *
 * It was `tier|column|subject`. `add_query_arg()` does not encode values --
 * `build_query()` calls `_http_build_query( …, false )` -- so the `|` reached
 * the URL literally, where RFC 3986 does not allow it and a WAF reads it as a
 * command-injection signature. On the production host every navigation link
 * and the post-correction redirect 403'd before reaching PHP, which is why
 * `Reload the list` was the only way to advance although the stepper had been
 * carrying the next key all along.
 *
 * Two controlled requests settled it: the same cursor with dots loads, while
 * `?ffc_teste=a|b` -- a parameter the plugin never reads -- 403s on its own.
 * Opposite in every variable but the pipe, opposite in outcome.
 *
 * @covers \FreeFormCertificate\Maintenance\IdentityQueue
 */
class IdentityQueueKeyTest extends TestCase {

	/**
	 * RFC 3986 section 2.3: what may sit in a URL with no encoding at all.
	 *
	 * Deliberately NOT a check that the separator is a dot. That would
	 * restate the implementation, and would pass a future edit that swapped
	 * one unusable character for another.
	 */
	private const UNRESERVED = '/^[A-Za-z0-9._~-]+$/';

	/**
	 * `..` is unreserved twice over and still refused.
	 *
	 * The path-traversal signature (CRS 930100) matches it inside a query
	 * value too, so a separator that assembles it trades one blocked
	 * character for another. The `isolated` tier carries an EMPTY identifier
	 * column, so a dot separator would produce exactly that -- which is why
	 * the separator is a hyphen although the production probe used a dot.
	 */
	private const TRAVERSAL = '..';

	/**
	 * The real identifier columns a finding names.
	 *
	 * The empty string is one of them and is not padding: the `isolated` tier
	 * names no column, so it is the case that turns a separator into a
	 * doubled one. Leaving it out is how a guard passes over the only input
	 * that could fail it.
	 *
	 * @var array<int, string>
	 */
	private const COLUMNS = array( 'cpf_hash', 'rf_hash', 'email_hash', '' );

	/**
	 * Both shapes `subject` takes: a hash, and a user id.
	 *
	 * @var array<int, string>
	 */
	private const SUBJECTS = array(
		'19ee833a5e9e15118f58afdd9c9a3f9ec6b4514ba4a1a214187b2f942e8ccd82',
		'438',
	);

	/**
	 * Call the private assembler.
	 *
	 * @param array<string, mixed> $row A finding.
	 * @return string
	 */
	private function key_of( array $row ): string {
		$method = new ReflectionMethod( IdentityQueue::class, 'key_of' );
		$method->setAccessible( true );

		return (string) $method->invoke( null, $row );
	}

	/**
	 * Every key the screen can build is carryable by a URL unencoded.
	 */
	public function test_a_cursor_key_carries_only_url_safe_characters(): void {
		$seen = array();

		foreach ( IdentityQueuePanels::ORDER as $tier ) {
			$seen[] = $tier;

			foreach ( self::COLUMNS as $column ) {
				foreach ( self::SUBJECTS as $subject ) {
					$key = $this->key_of(
						array(
							IdentityQueue::COLUMN_TIER => $tier,
							'identifier_column'        => $column,
							'subject'                  => $subject,
						)
					);

					$this->assertMatchesRegularExpression(
						self::UNRESERVED,
						$key,
						"A cursor key carries a character a URL cannot hold unencoded, so every navigation link built from it"
							. " is refused before reaching PHP: {$key}"
					);

					$this->assertStringNotContainsString(
						self::TRAVERSAL,
						$key,
						"A cursor key assembles the path-traversal signature, which a WAF refuses although both characters"
							. " are unreserved: {$key}"
					);
				}
			}
		}

		// The self-check is an exact comparison against a non-empty register,
		// not a floor: a tier leaving `ORDER` has to be noticed here too,
		// because a tier this never built a key for is a tier this never
		// checked.
		$this->assertSame(
			array( 'mechanical', 'isolated', 'decision', 'shared', 'mailbox' ),
			$seen,
			'The tier list no longer matches what this guard walks, so some panel goes unchecked.'
		);
	}

	/**
	 * The assertion can fail -- proven against the separator that shipped.
	 *
	 * Without this the test above passes for any key at all if the regex is
	 * ever loosened, and a guard that cannot fail is not a guard.
	 */
	public function test_the_rule_rejects_the_separator_that_was_refused_in_production(): void {
		$this->assertDoesNotMatchRegularExpression(
			self::UNRESERVED,
			'decision|cpf_hash|438',
			'The URL-safety rule accepts a raw pipe, so it would have approved the key that 403d on every navigation link.'
		);
	}
}
