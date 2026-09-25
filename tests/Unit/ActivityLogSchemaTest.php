<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\ActivityLog;

/**
 * The activity log's CREATE must declare every column its insert can write (#1444).
 *
 * WHAT WAS WRONG
 *
 * `flush_buffer()` wrote `context_encrypted` and `submission_id`, and **no
 * `CREATE TABLE` or `ALTER` anywhere in the tree declared either** — since the
 * gates that read them arrived in 6.6.4. Every consumer treats both as
 * optional, so nothing ever complained:
 *
 * - `flush_buffer()` gates each write on `in_array( <col>, $columns, true )`,
 *   so the ciphertext was silently dropped while `log()` had already NULLed the
 *   plaintext on a successful encrypt — an entry with sensitive context stored
 *   with neither.
 * - `ActivityLogClearPlaintextMigrationStrategy` short-circuits on the missing
 *   column to `percent => 100, is_complete => true`, so the card claimed the
 *   dual-storage leak was closed on an install where the encryption it clears
 *   after had never run once.
 * - `ActivityLogQuery::get_submission_logs()` — the LGPD per-submission audit
 *   trail — returns `array()`, so `VerificationResponseRenderer` silently
 *   omitted the recorded-schedule block from the PUBLIC verification page of
 *   any certificate whose window an operator had overridden.
 *
 * WHY NO GUARD SAW IT
 *
 * `SchemaAgreementTest` compares a `CREATE TABLE` against an
 * `add_columns_if_missing()` call, and across files where two classes declare
 * one table. Here there is no second declaration to disagree with — both
 * columns were named only by their READERS. `ActivatorSchemaGuardTest` works
 * per table, not per column.
 *
 * WHAT THIS PINS, AND WHAT IT DELIBERATELY DOES NOT
 *
 * One file's two halves, exactly: every column the insert can write is
 * declared by the CREATE beside it. That is cheap and total because both sides
 * live in one method each, delimited by reflection rather than by a parse.
 *
 * It is NOT the general guard — *every column an insert or update names must be
 * declared somewhere in the tree* — which is tracked apart (#1447). That one
 * needs to resolve which TABLE a write targets, and the measurement that says
 * so is worth keeping: a first pass resolved the table in only 18 of 77 write
 * sites, and a union across all tables would have caught `context_encrypted`
 * and NOT `submission_id`, because some other table declares that name. Half a
 * detector freezes a baseline that is believed.
 *
 * @coversNothing
 */
class ActivityLogSchemaTest extends TestCase {

	/**
	 * Every column `flush_buffer()` can put into the insert.
	 *
	 * A FROZEN REGISTER, which is the strongest self-check available here
	 * (CLAUDE.md, "A self-check is worth what it is tight to"): a scan that
	 * loses a shape fails, and a NEW column in the insert fails too and asks
	 * to be declared in the CREATE — which is the direction this exists for.
	 *
	 * @var array<int, string>
	 */
	private const WRITTEN = array(
		'action',
		'context',
		'context_encrypted',
		'created_at',
		'level',
		'submission_id',
		'user_id',
		'user_ip',
	);

	/**
	 * The release whose schema lacked both columns.
	 *
	 * `maybe_create_table()` runs `dbDelta` only when the stored option differs
	 * from `DB_VERSION`, and nothing else performs an activation on an in-place
	 * update (the #1311 lesson) — so a shape change that leaves this constant
	 * alone reaches a fresh install and no upgraded one.
	 */
	private const VERSION_WITHOUT_THE_COLUMNS = '2.0.0';

	/**
	 * The source lines of one method of `ActivityLog`, by reflection.
	 *
	 * Reflection rather than a brace-matching parse: the line numbers are the
	 * authority on where a method body is, and a regex over the whole file
	 * bleeds into the neighbouring method — which is exactly how a first
	 * prototype of this measurement missed both columns, since they are
	 * written by a conditional assignment rather than an array literal.
	 */
	private function body_of( string $method ): string {
		$ref   = new \ReflectionMethod( ActivityLog::class, $method );
		$file  = (string) $ref->getFileName();
		$lines = explode( "\n", (string) file_get_contents( $file ) );
		$start = (int) $ref->getStartLine() - 1;
		$len   = (int) $ref->getEndLine() - $start;

		return implode( "\n", array_slice( $lines, $start, $len ) );
	}

	/**
	 * Columns the insert can carry: array-literal keys AND the conditional
	 * assignments, which are the shape that hid this defect.
	 *
	 * @return array<int, string>
	 */
	private function written_columns(): array {
		$body = $this->body_of( 'flush_buffer' );
		$out  = array();

		if ( preg_match_all( "/'([a-z_][a-z0-9_]*)'\s*=>/", $body, $literal ) ) {
			$out = array_merge( $out, $literal[1] );
		}

		if ( preg_match_all( "/\\\$row_data\[\s*'([a-z_][a-z0-9_]*)'\s*\]\s*=/", $body, $assigned ) ) {
			$out = array_merge( $out, $assigned[1] );
		}

		$out = array_values( array_unique( $out ) );
		sort( $out );

		return $out;
	}

	/**
	 * Columns the `CREATE TABLE` declares, keys and the closing paren aside.
	 *
	 * @return array<int, string>
	 */
	private function declared_columns(): array {
		$body = $this->body_of( 'create_table' );

		$open = strpos( $body, 'CREATE TABLE' );
		$this->assertNotFalse( $open, 'create_table() no longer carries a CREATE TABLE literal — the scan reached nothing.' );

		$out = array();
		foreach ( explode( "\n", substr( $body, (int) $open ) ) as $line ) {
			$line = trim( $line );

			// The alternation needs the word boundary: without it `CREATE` matches
			// the prefix of `created_at` and drops a real column. The token-boundary
			// trap CLAUDE.md records for the CSS anchor scan, in a new costume.
			if ( '' === $line || preg_match( '/^(?:CREATE\b|PRIMARY\s+KEY\b|UNIQUE\s+(?:KEY|INDEX)\b|KEY\b|INDEX\b|CONSTRAINT\b|FOREIGN\s+KEY\b|\))/i', $line ) ) {
				continue;
			}

			if ( preg_match( '/^`?([a-z_][a-z0-9_]*)`?\s+[a-z]/i', $line, $m ) ) {
				$out[] = strtolower( $m[1] );
			}
		}

		$out = array_values( array_unique( $out ) );
		sort( $out );

		return $out;
	}

	// ==================================================================

	/**
	 * The register still describes what the insert writes.
	 */
	public function test_the_written_columns_are_the_frozen_set(): void {
		$this->assertSame(
			self::WRITTEN,
			$this->written_columns(),
			'The columns `flush_buffer()` can write are not the frozen set. Either a shape'
			. ' this scan reads was lost, or a new column joined the insert — in which case'
			. ' declare it in create_table(), bump DB_VERSION, and add it here.'
		);
	}

	/**
	 * The scan reached past the write list.
	 *
	 * `id` is declared and never written — it is AUTO_INCREMENT — so its
	 * presence proves the CREATE parse read the statement rather than
	 * echoing the register above.
	 */
	public function test_the_create_scan_reaches_a_column_the_insert_never_writes(): void {
		$declared = $this->declared_columns();

		$this->assertContains( 'id', $declared, 'The CREATE scan did not find `id`, so it is not reading the statement.' );
		$this->assertNotContains( 'id', self::WRITTEN, 'AUTO_INCREMENT `id` must not be written by the insert.' );
	}

	/**
	 * THE DEFECT: a column written and declared by nobody.
	 */
	public function test_every_written_column_is_declared_by_the_create(): void {
		$declared = $this->declared_columns();
		$missing  = array_values( array_diff( $this->written_columns(), $declared ) );

		$this->assertSame(
			array(),
			$missing,
			'These columns are written by flush_buffer() and declared by no CREATE TABLE: '
			. implode( ', ', $missing )
			. '. The write is gated on the column existing, so nothing fails — the value is'
			. ' silently dropped, and for `context` the plaintext has already been NULLed.'
		);
	}

	/**
	 * Declaring a column reaches an existing install only through DB_VERSION.
	 */
	public function test_the_schema_version_moved_past_the_release_without_those_columns(): void {
		$ref = new \ReflectionClass( ActivityLog::class );
		$const = $ref->getConstant( 'DB_VERSION' );

		$this->assertIsString( $const, 'DB_VERSION is gone or is no longer a string.' );
		$this->assertTrue(
			version_compare( $const, self::VERSION_WITHOUT_THE_COLUMNS, '>' ),
			sprintf(
				'DB_VERSION is %s, which is not past %s — the release whose CREATE lacked'
				. ' context_encrypted and submission_id. maybe_create_table() only runs dbDelta'
				. ' when the stored option differs, so an install that will never be activated'
				. ' again would never get the columns.',
				$const,
				self::VERSION_WITHOUT_THE_COLUMNS
			)
		);
	}
}
