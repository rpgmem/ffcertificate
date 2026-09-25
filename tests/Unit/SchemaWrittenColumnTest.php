<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Tests\Support\DatabaseWrites;
use FreeFormCertificate\Tests\Support\SchemaColumns;

/**
 * A column an insert or update names must be declared somewhere (#1447).
 *
 * THE DEFECT
 *
 * `ActivityLog::flush_buffer()` wrote `context_encrypted` and `submission_id`,
 * and no `CREATE TABLE`, no `ALTER` and no `add_column_if_missing()` anywhere in
 * the tree declared either — from 6.6.4 until #1444. Every consumer gated its
 * read on the column existing, so nothing failed: the ciphertext was dropped
 * after `log()` had already NULLed the plaintext, a migration card reported 100%
 * complete over a condition it could not evaluate, and the public certificate
 * verification page silently omitted a disclosure.
 *
 * `SchemaAgreementTest` could not see it: it compares a `CREATE TABLE` against
 * an `add_columns_if_missing()` call, and across files where two classes declare
 * one table. Both columns were named only by their READERS, so there was no
 * second declaration to disagree with. `ActivatorSchemaGuardTest` works per
 * table, not per column. The dbDelta idempotence gate replays a statement
 * against the table it created from that statement, and a column absent from
 * both agrees with itself.
 *
 * TWO DIRECTIONS, AND THE SECOND IS DELIBERATELY WEAKER
 *
 * **A, per table:** the strong reading, and the one that catches the defect.
 * 66 of the 76 write sites resolve their table statically.
 *
 * **B, per tree:** for the ten that do not, the column is still checked against
 * *does anything anywhere declare this name*. Weaker on purpose, and not a
 * silent skip — the #1071 / #1094 rule binds a guard as much as a screen: a
 * site this scan cannot resolve is registered with its reason, never dropped.
 *
 * WHY B IS NOT ENOUGH ON ITS OWN, MEASURED
 *
 * A prototype that used only direction B caught `context_encrypted` and **not**
 * `submission_id`, because another table declares that name and a union across
 * tables cannot tell the difference. Half a detector, and a frozen baseline is
 * believed — the #1284 lesson. Per-table resolution is what makes this a
 * detector rather than a coincidence.
 *
 * WHAT IT DELIBERATELY DOES NOT SEE
 *
 * A column key built at runtime (`$update[ $hash_col ] = …`) and a data map
 * arriving as a parameter. Both are registered below rather than reasoned away.
 *
 * @coversNothing
 */
class SchemaWrittenColumnTest extends TestCase {

	/**
	 * Write sites whose TABLE no static reading resolves, with why.
	 *
	 * A register rather than a silence, and a ratchet both ways: a site that
	 * becomes resolvable fails here so the win is locked in, and a new
	 * unresolvable one has to argue for itself.
	 *
	 * @var array<string, string>
	 */
	private const UNRESOLVED_TABLE = array(
		'includes/migrations/strategies/class-ffc-email-hash-rehash-migration-strategy.php' =>
			'foreach ( array( $this->submissions_table, $this->appointments_table ) as $table ) -- the write targets TWO tables in one loop, so there is no single table to compare against.',
		'includes/migrations/strategies/class-ffc-cpf-rf-split-migration-strategy.php' =>
			'$table_name comes from a loop over the two stores the split covers.',
		'includes/maintenance/class-ffc-identity-merge.php' =>
			'foreach ( $plan["stores"] as $table ) -- the set is computed at runtime, and the class comment says re-resolving it here would let the confirmation name a store the loop then skips.',
		'includes/maintenance/class-ffc-identity-repair.php' =>
			'foreach ( $found["rows"] as $table => $ids ) -- the tables are the keys of a runtime finding.',
		'includes/maintenance/class-ffc-identity-relink.php' =>
			'foreach ( $moving["rows"] as $table => $ids ) -- as above.',
		'includes/core/class-ffc-database-helper-trait.php' =>
			'The table is a PARAMETER of the shared helper, and so is the column: every caller passes its own. Neither side is static here by construction.',
	);

	/**
	 * Write sites whose COLUMN SET no static reading resolves, with why.
	 *
	 * @var array<string, string>
	 */
	private const UNRESOLVED_DATA = array(
		'includes/reregistration/class-ffc-custom-field-writer.php' =>
			'insert_row( array $data ) -- the column => value map is a parameter, so the keys belong to the caller.',
		'includes/migrations/strategies/class-ffc-key-rotation-migration-strategy.php' =>
			'$update[ $hash_col ] = $new_hash -- the key is a variable, which no static scan resolves.',
		'includes/migrations/strategies/class-ffc-key-rotation-remaining-migration-strategy.php' =>
			'As above: the hash column is chosen at runtime per area.',
		'includes/migrations/strategies/class-ffc-identity-normalization-migration-strategy.php' =>
			'As above: the column is picked per identifier being canonicalised.',
	);

	/**
	 * Columns written that NO declarer names, with why that is correct.
	 *
	 * All three are legacy columns the CPF/RF split retires. A fresh install has
	 * never had them -- they were dropped from every `CREATE` once production
	 * read 0 pending, which `CLAUDE.md` records as the `cpf_rf_encrypted`
	 * exemplar -- while the migration still NULLs them on an install that does.
	 * The card's own `calculate_status()` reports 100% complete when the column
	 * is gone, so the write is unreachable there rather than broken.
	 *
	 * @var array<string, string>
	 */
	private const LEGACY_WITHOUT_DECLARATION = array(
		'cpf_rf'           => 'Retired by split_cpf_rf; absent from every CREATE by design.',
		'cpf_rf_encrypted' => 'Same column family; the reads were removed once production read 0 pending.',
		'cpf_rf_hash'      => 'Same, and it is the column the migration counts pending rows by.',
	);

	/**
	 * The site this guard exists for, which must resolve at FULL strength.
	 *
	 * A named deep member (CLAUDE.md ranks it last, and it is the right shape
	 * here): if `ActivityLog`'s insert ever stops resolving per table, this
	 * guard silently demotes the one write site whose defect wrote it.
	 */
	private const CANARY_FILE = 'includes/core/class-ffc-activity-log.php';

	/** @var array<string, mixed>|null */
	private static $scan = null;

	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Walk every write site once, classifying each.
	 *
	 * @return array{strong: list<array<string, mixed>>, weak: list<array<string, mixed>>, no_table: list<string>, no_data: list<string>, site_count: int}
	 */
	private function scan(): array {
		if ( null !== self::$scan ) {
			/** @var array{strong: list<array<string, mixed>>, weak: list<array<string, mixed>>, no_table: list<string>, no_data: list<string>, site_count: int} $cached */
			$cached = self::$scan;
			return $cached;
		}

		$includes = $this->root() . '/includes';
		$out      = array(
			'strong'     => array(),
			'weak'       => array(),
			'no_table'   => array(),
			'no_data'    => array(),
			// Sites, not files: the two registers dedupe by file, so a file with
			// two unresolvable sites would make a file count disagree with the
			// recount for a reason that is not a collapsed scan.
			'site_count' => 0,
		);

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $includes, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$path = $file->getPathname();

			if ( substr( $path, -4 ) !== '.php' ) {
				continue;
			}

			$relative = substr( $path, strlen( $this->root() ) + 1 );
			$source   = (string) file_get_contents( $path );
			$tokens   = token_get_all( $source );

			foreach ( DatabaseWrites::calls( $path ) as $call ) {
				$scope   = DatabaseWrites::scope_before( $tokens, $call['index'] );
				$table   = DatabaseWrites::resolve_table( $call['args'][0] ?? '', $scope, $source, $includes );
				$columns = DatabaseWrites::data_columns( $call['args'][1] ?? '', $scope );

				++$out['site_count'];

				if ( null === $columns ) {
					$out['no_data'][] = $relative;
					continue;
				}

				if ( null === $table ) {
					$out['no_table'][] = $relative;
					$out['weak'][]     = array(
						'file'    => $relative,
						'columns' => $columns,
					);
					continue;
				}

				$out['strong'][] = array(
					'file'    => $relative,
					'table'   => $table,
					'columns' => $columns,
				);
			}
		}

		$out['no_table'] = $this->unique_sorted( $out['no_table'] );
		$out['no_data']  = $this->unique_sorted( $out['no_data'] );

		self::$scan = $out;

		return $out;
	}

	/**
	 * @param list<string> $values Values.
	 * @return list<string>
	 */
	private function unique_sorted( array $values ): array {
		$values = array_values( array_unique( $values ) );
		sort( $values );

		return $values;
	}

	// ==================================================================

	/**
	 * THE DEFECT, at full strength: a column no declarer of ITS table names.
	 */
	public function test_every_written_column_is_declared_by_its_own_table(): void {
		$scan      = $this->scan();
		$by_table   = SchemaColumns::declared_by_table( $this->root() . '/includes' );
		$increments = SchemaColumns::declared_incrementally( $this->root() . '/includes' );
		$undeclared = array();

		foreach ( $scan['strong'] as $site ) {
			foreach ( $site['columns'] as $column ) {
				// The incremental declarers are read per FILE rather than per
				// table -- `add_column_if_missing( $table, … )` carries the same
				// variable this scan already resolves for the write, and
				// attributing it would need that resolution a second time. So a
				// column declared incrementally anywhere counts, which is the one
				// place direction A borrows B's reading. Stated rather than
				// hidden: it is why a column moved between two tables' helpers
				// would not be caught here.
				if ( isset( $by_table[ $site['table'] ][ $column ] ) || isset( $increments[ $column ] ) ) {
					continue;
				}

				$undeclared[ $site['table'] . '.' . $column ] = $site['file'];
			}
		}

		$this->assertSame(
			array(),
			$undeclared,
			"These columns are written and declared by nothing:\n"
			. implode( "\n", array_map(
				static fn( string $k, string $v ): string => "  {$k}  <- {$v}",
				array_keys( $undeclared ),
				array_values( $undeclared )
			) )
			. "\nThe write is gated on the column existing at every consumer, so nothing"
			. ' fails -- the value is dropped in silence. Declare it in the CREATE and'
			. ' bump that table\'s DB_VERSION, or through add_column_if_missing().'
		);
	}

	/**
	 * The weaker direction, for the sites whose table is a runtime value.
	 */
	public function test_a_column_written_to_an_unresolved_table_is_declared_somewhere(): void {
		$scan       = $this->scan();
		$anywhere   = SchemaColumns::declared_anywhere( $this->root() . '/includes' );
		$undeclared = array();

		foreach ( $scan['weak'] as $site ) {
			foreach ( $site['columns'] as $column ) {
				if ( isset( $anywhere[ $column ] ) || isset( self::LEGACY_WITHOUT_DECLARATION[ $column ] ) ) {
					continue;
				}

				$undeclared[ $column ] = $site['file'];
			}
		}

		$this->assertSame(
			array(),
			$undeclared,
			'These columns are written to a table this scan cannot resolve, and no'
			. ' declarer anywhere names them: ' . implode( ', ', array_keys( $undeclared ) )
			. '. Either they are declared somewhere this scan cannot read -- teach it --'
			. ' or they are a legacy family being retired, which goes in'
			. ' LEGACY_WITHOUT_DECLARATION with the reason.'
		);
	}

	/**
	 * Every legacy entry still describes a real write.
	 */
	public function test_every_legacy_entry_still_matches_a_write(): void {
		$scan    = $this->scan();
		$written = array();

		foreach ( array_merge( $scan['strong'], $scan['weak'] ) as $site ) {
			foreach ( $site['columns'] as $column ) {
				$written[ $column ] = true;
			}
		}

		foreach ( array_keys( self::LEGACY_WITHOUT_DECLARATION ) as $column ) {
			$this->assertArrayHasKey(
				$column,
				$written,
				sprintf(
					'`%s` is excused here and is written by nothing any more. Drop the entry --'
					. ' an exception that describes no code makes this guard narrower and says so'
					. ' to nobody.',
					$column
				)
			);
		}
	}

	/**
	 * The unresolved registers are exactly the sites that are unresolved.
	 *
	 * THE SELF-CHECK, in the shape CLAUDE.md ranks first: an exact comparison
	 * against a non-empty frozen register. A scan that collapses loses entries
	 * and fails; a site that becomes resolvable fails so the win is locked in;
	 * a new unresolvable site fails and has to write down its reason.
	 */
	public function test_the_unresolved_registers_are_exact(): void {
		$scan = $this->scan();

		$this->assertSame(
			$this->unique_sorted( array_keys( self::UNRESOLVED_TABLE ) ),
			$scan['no_table'],
			'The write sites whose table cannot be resolved are not the registered set.'
		);

		$this->assertSame(
			$this->unique_sorted( array_keys( self::UNRESOLVED_DATA ) ),
			$scan['no_data'],
			'The write sites whose column set cannot be read are not the registered set.'
		);
	}

	/**
	 * The scan resolves the site whose defect this guard was written for.
	 */
	public function test_the_activity_log_insert_resolves_at_full_strength(): void {
		$scan  = $this->scan();
		$found = null;

		foreach ( $scan['strong'] as $site ) {
			if ( self::CANARY_FILE === $site['file'] ) {
				$found = $site;
			}
		}

		$this->assertNotNull(
			$found,
			sprintf( '%s no longer resolves per table, so the write site this guard exists for is only weakly checked.', self::CANARY_FILE )
		);
		$this->assertSame( 'ffc_activity_log', $found['table'], 'The canary resolved to the wrong table.' );
		$this->assertContains( 'context_encrypted', $found['columns'], 'The scan lost the conditional-assignment shape that hid #1444.' );
		$this->assertContains( 'submission_id', $found['columns'], 'The scan lost the conditional-assignment shape that hid #1444.' );
	}

	/**
	 * The scan read the tree, not a fraction of it.
	 *
	 * AN INDEPENDENT RECOUNT, AND THE FIRST VERSION WAS CIRCULAR. It globbed the
	 * files itself — a different traversal, correctly — and then counted the
	 * calls with `DatabaseWrites::calls()`, the very walk under test. Halving
	 * that walk halves both sides and the ratio holds at one, which is the shape
	 * CLAUDE.md names as detecting nothing at all. Measured: the halving left
	 * this test green and only the register comparison caught it.
	 *
	 * So the recount counts call HEADS by a different route: comments stripped,
	 * then a regex, with no bracket matching and no argument splitting. The
	 * comment strip is not optional — `CustomFieldWriter`'s docblock says
	 * *"Wraps `$wpdb->insert()`"*, so a raw regex reports 77 against the walk's
	 * 76 and the disagreement is prose, not a missed call. The same
	 * read-tokens-never-lines rule the suppression and comment-language guards
	 * already follow.
	 */
	public function test_the_scan_reached_every_write_site(): void {
		$scan    = $this->scan();
		$recount = 0;
		$files   = 0;

		foreach ( glob( $this->root() . '/includes/{,*/,*/*/}*.php', GLOB_BRACE ) ?: array() as $path ) {
			++$files;
			$code = '';

			foreach ( token_get_all( (string) file_get_contents( $path ) ) as $token ) {
				if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}

				$code .= is_array( $token ) ? $token[1] : $token;
			}

			$recount += preg_match_all( '/\$wpdb->(?:insert|update|replace)\s*\(/', $code );
		}

		$this->assertGreaterThan( 0, $files, 'The glob found no PHP file, so the recount is not reading the tree.' );
		$this->assertSame(
			$recount,
			$scan['site_count'],
			'The token walk and an independent comment-stripped recount disagree about how'
			. ' many write sites exist. One of the two stopped seeing part of the tree.'
		);
	}
}
