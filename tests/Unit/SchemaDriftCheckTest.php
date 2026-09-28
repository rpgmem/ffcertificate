<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/.github/scripts/ffc-create-statements.php';
require_once dirname( __DIR__, 2 ) . '/.github/scripts/ffc-schema-drift.php';

/**
 * The post-deploy smoke's column check, driven without a server (#1458).
 *
 * WHY THE COMPARISON IS PURE AND THIS TEST EXISTS AT ALL
 *
 * The check runs on the testes host, over SSH, after a deploy -- so nothing in
 * CI can execute it end to end, and a bug in it would surface as an alarm that
 * is silently wrong rather than as a red build. Splitting the `SHOW COLUMNS`
 * read from the comparison is what makes the part with judgement in it reachable
 * from fixtures, the same reason `CsvStreamer` is orchestration over an
 * injectable download.
 *
 * What stays unreachable is honest to state: that `SHOW COLUMNS FROM %i`
 * answers, and that the host's schema is what the deploy left. Only the deploy
 * can say those.
 */
final class SchemaDriftCheckTest extends TestCase {

	/**
	 * `ffc_activity_log` as production actually held it, before #1458.
	 *
	 * THIS IS THE DEFECT THE CHECK EXISTS FOR, so it is the first thing tested.
	 * Taken from the issue's own `SHOW COLUMNS`: `action_type` is `NOT NULL`
	 * with no default and declared by nothing, `action_details` and `user_agent`
	 * are nullable and declared by nothing, and `submission_id` is `NOT NULL`
	 * against a `DEFAULT NULL` in the statement -- which is a TYPE-level
	 * disagreement, not a presence one, so this check must NOT report it (the
	 * dbDelta idempotence gate owns that direction).
	 *
	 * @return array<string, array{null: string, default: string|null, extra: string}>
	 */
	private static function activity_log_as_production_held_it(): array {
		return array(
			'id'                 => array( 'null' => 'NO', 'default' => null, 'extra' => 'auto_increment' ),
			'submission_id'      => array( 'null' => 'NO', 'default' => null, 'extra' => '' ),
			'action_type'        => array( 'null' => 'NO', 'default' => null, 'extra' => '' ),
			'action_details'     => array( 'null' => 'YES', 'default' => null, 'extra' => '' ),
			'user_id'            => array( 'null' => 'YES', 'default' => null, 'extra' => '' ),
			'user_ip'            => array( 'null' => 'YES', 'default' => null, 'extra' => '' ),
			'user_agent'         => array( 'null' => 'YES', 'default' => null, 'extra' => '' ),
			'created_at'         => array( 'null' => 'NO', 'default' => 'current_timestamp()', 'extra' => '' ),
			'action'             => array( 'null' => 'NO', 'default' => null, 'extra' => '' ),
			'level'              => array( 'null' => 'NO', 'default' => null, 'extra' => '' ),
			'context'            => array( 'null' => 'YES', 'default' => null, 'extra' => '' ),
			'context_encrypted'  => array( 'null' => 'YES', 'default' => null, 'extra' => '' ),
		);
	}

	public function test_it_reproduces_1458_on_the_schema_that_produced_it(): void {
		$declared = array( 'id', 'submission_id', 'user_id', 'user_ip', 'created_at', 'action', 'level', 'context', 'context_encrypted' );

		$drift = ffc_schema_drift( $declared, self::activity_log_as_production_held_it() );

		$this->assertSame(
			array( 'action_type' ),
			$drift['hazard'],
			'`action_type` is NOT NULL with no default and declared by nothing — the strict-mode failure #1458 found.'
		);
		$this->assertSame(
			array( 'action_details', 'user_agent' ),
			$drift['inert'],
			'Nullable and declared by nothing: real debt, and not worth reddening a deploy for.'
		);
		$this->assertSame(
			array(),
			$drift['missing'],
			'Every declared column was present on that install; reporting one would be a false alarm.'
		);
	}

	public function test_submission_id_is_not_reported_although_its_type_disagrees(): void {
		$drift = ffc_schema_drift( array( 'id', 'submission_id' ), self::activity_log_as_production_held_it() );

		$this->assertNotContains( 'submission_id', $drift['hazard'] );
		$this->assertNotContains( 'submission_id', $drift['inert'] );
		$this->assertNotContains( 'submission_id', $drift['missing'] );
	}

	public function test_a_declared_column_the_server_lacks_is_reported(): void {
		$drift = ffc_schema_drift(
			array( 'id', 'context_encrypted' ),
			array( 'id' => array( 'null' => 'NO', 'default' => null, 'extra' => 'auto_increment' ) )
		);

		$this->assertSame( array( 'context_encrypted' ), $drift['missing'] );
	}

	/**
	 * The two shapes where the SERVER supplies the value, so an omitting insert
	 * is fine and calling them hazards would be a false alarm on every install.
	 *
	 * @dataProvider server_supplied_columns
	 *
	 * @param array{null: string, default: string|null, extra: string} $column The live column.
	 */
	public function test_a_column_the_server_fills_is_never_a_hazard( array $column ): void {
		$this->assertFalse( ffc_schema_column_is_strict_hazard( $column ) );
	}

	/**
	 * @return array<string, array{0: array{null: string, default: string|null, extra: string}}>
	 */
	public static function server_supplied_columns(): array {
		return array(
			'auto_increment'                 => array( array( 'null' => 'NO', 'default' => null, 'extra' => 'auto_increment' ) ),
			'AUTO_INCREMENT, spelled loudly' => array( array( 'null' => 'NO', 'default' => null, 'extra' => 'AUTO_INCREMENT' ) ),
			'CURRENT_TIMESTAMP default'      => array( array( 'null' => 'NO', 'default' => 'CURRENT_TIMESTAMP', 'extra' => '' ) ),
			'current_timestamp() as MariaDB' => array( array( 'null' => 'NO', 'default' => 'current_timestamp()', 'extra' => '' ) ),
			'an ordinary default'            => array( array( 'null' => 'NO', 'default' => '0', 'extra' => '' ) ),
			'nullable'                       => array( array( 'null' => 'YES', 'default' => null, 'extra' => '' ) ),
		);
	}

	public function test_not_null_with_no_default_is_the_hazard(): void {
		$this->assertTrue(
			ffc_schema_column_is_strict_hazard( array( 'null' => 'NO', 'default' => null, 'extra' => '' ) )
		);
		$this->assertTrue(
			ffc_schema_column_is_strict_hazard( array( 'null' => 'NO', 'default' => '', 'extra' => '' ) ),
			"An empty-string default is how some servers report `no default`, and it is the value #1458's column silently took."
		);
	}

	public function test_the_comparison_ignores_column_name_case(): void {
		$drift = ffc_schema_drift(
			array( 'Created_At' ),
			array( 'created_at' => array( 'null' => 'NO', 'default' => 'current_timestamp()', 'extra' => '' ) )
		);

		$this->assertSame( array(), $drift['missing'] );
		$this->assertSame( array(), $drift['inert'] );
	}

	/**
	 * The reader, against the real tree.
	 *
	 * NAMED RATHER THAN COUNTED where a count would go stale with the next
	 * table, and counted where the count IS the self-check: a reader that
	 * returned one table would satisfy any floor worth writing.
	 */
	public function test_the_declared_reader_reads_the_whole_tree(): void {
		$declared = ffc_schema_declared_columns( dirname( __DIR__, 2 ) . '/includes' );

		$this->assertSame(
			array(),
			$declared['errors'],
			"The reader reports a fault on the real tree, so the smoke's check 4 would fail every deploy: " . implode( '; ', $declared['errors'] )
		);

		// AN INDEPENDENT RECOUNT, BY A DIFFERENT READER, because a floor over a
		// growing population is a floor that loosens on its own: `> 25` was
		// tight at 28 tables and is slack at 40, and nobody has to touch it for
		// that to happen. `uninstall.php` is obliged to know every table (it
		// drops them, and the fresh-install job enforces the manifest BOTH
		// ways), so every table a `CREATE` declares must appear there -- and
		// both sides move when a table is added, which is what a floor cannot
		// do. It is also the same list the smoke's check 3 reads, so the two
		// cannot disagree about the footprint.
		require_once dirname( __DIR__, 2 ) . '/.github/scripts/ffc-uninstall-manifest.php';

		$manifest = ffc_manifest_tables( dirname( __DIR__, 2 ) . '/uninstall.php' );

		$this->assertNotEmpty( $manifest, 'The manifest read nothing, so the comparison below proves nothing.' );

		$this->assertSame(
			array(),
			array_values( array_diff( array_keys( $declared['tables'] ), $manifest ) ),
			'A table has a CREATE TABLE and is not in uninstall.php — either the reader invented it or the manifest is short.'
		);

		$this->assertArrayHasKey( 'ffc_activity_log', $declared['tables'], 'The table #1458 was found on must be readable.' );
		$this->assertArrayHasKey( 'ffc_submissions', $declared['tables'] );

		// Two tables are declared twice, so the reader must UNION rather than
		// take the last writer. `ffc_custom_fields` is one of them.
		$this->assertContains( 'field_key', $declared['tables']['ffc_custom_fields'] );

		// `submission_id` and `context_encrypted` are what #1444 added to the
		// statement; naming them pins the reader to the shape that defect had.
		$this->assertContains( 'submission_id', $declared['tables']['ffc_activity_log'] );
		$this->assertContains( 'context_encrypted', $declared['tables']['ffc_activity_log'] );

		// And the three #1458 dropped must NOT be declared, or the check would
		// report them as missing on any install that still carries them.
		foreach ( array( 'action_type', 'action_details', 'user_agent' ) as $dropped ) {
			$this->assertNotContains(
				$dropped,
				$declared['tables']['ffc_activity_log'],
				"`{$dropped}` was dropped in 6.29.1 and must stay undeclared."
			);
		}
	}

	public function test_a_missing_includes_directory_is_a_fault_and_not_an_empty_clean_read(): void {
		$declared = ffc_schema_declared_columns( '/nonexistent/includes' );

		$this->assertSame( array(), $declared['tables'] );
		$this->assertNotEmpty( $declared['errors'], 'Reading nothing must be a fault, never a clean result.' );
	}

	/**
	 * Every fault path of the reader, driven from a throwaway tree.
	 *
	 * THESE HAVE NO COVERAGE FROM THE REAL TREE BY CONSTRUCTION, which is the
	 * reason they are here: on `develop` every statement parses, every table
	 * resolves and the two nets agree, so all three branches are dead code as
	 * far as the tree is concerned -- and a self-check nothing exercises is one
	 * that can rot without anybody noticing. Found by mutation: blanking the
	 * unparsed-body branch left the suite green.
	 *
	 * @var string|null
	 */
	private ?string $fixture_dir = null;

	protected function tearDown(): void {
		if ( null !== $this->fixture_dir && is_dir( $this->fixture_dir ) ) {
			foreach ( (array) glob( $this->fixture_dir . '/*' ) as $file ) {
				unlink( (string) $file );
			}
			rmdir( $this->fixture_dir );
		}

		$this->fixture_dir = null;

		parent::tearDown();
	}

	/**
	 * A throwaway `includes/` holding one PHP file.
	 *
	 * @param string $source The file's contents.
	 * @return string Absolute directory path.
	 */
	private function fixture_tree( string $source ): string {
		$dir = sys_get_temp_dir() . '/ffc-drift-' . bin2hex( random_bytes( 6 ) );
		mkdir( $dir );
		file_put_contents( $dir . '/class-fixture.php', $source );

		$this->fixture_dir = $dir;

		return $dir;
	}

	public function test_a_statement_whose_body_cannot_be_read_is_a_fault(): void {
		// No `{$charset_collate}` tail, which is what the column reader anchors
		// on -- the #1241 shape, where nine statements yielded no body at all.
		$declared = ffc_schema_declared_columns(
			$this->fixture_tree(
				'<?php
class Fixture {
	public static function create() {
		global $wpdb;
		$table_name = $wpdb->prefix . \'ffc_fixture\';
		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			PRIMARY KEY  (id)
		)";
		return $sql;
	}
}'
			)
		);

		$this->assertNotEmpty( $declared['errors'] );
		$this->assertStringContainsString( 'read no columns', $declared['errors'][0] );
		$this->assertStringContainsString(
			'would read as undeclared',
			$declared['errors'][0],
			'The message has to say WHY it is fatal: an unparsed body makes every live column of that table look like drift.'
		);
		$this->assertSame( array(), $declared['tables'], 'A table whose columns could not be read must not be offered for comparison.' );
	}

	public function test_a_statement_whose_table_cannot_be_resolved_is_a_fault(): void {
		$declared = ffc_schema_declared_columns(
			$this->fixture_tree(
				'<?php
class Fixture {
	public static function create() {
		$sql = "CREATE TABLE {$mystery} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			PRIMARY KEY  (id)
		) {$charset_collate}";
		return $sql;
	}
}'
			)
		);

		$this->assertNotEmpty( $declared['errors'] );
		$this->assertStringContainsString( 'could not resolve the table name', $declared['errors'][0] );
	}

	public function test_a_statement_the_extraction_cannot_see_is_a_fault(): void {
		// Single-quoted: the wide net counts it, the real extraction only reads
		// double-quoted literals. That divergence is the whole reason the parser
		// offers two counts.
		$declared = ffc_schema_declared_columns(
			$this->fixture_tree(
				'<?php
class Fixture {
	public static function create() {
		$sql = \'CREATE TABLE wp_ffc_fixture ( id bigint(20) )\';
		return $sql;
	}
}'
			)
		);

		$this->assertNotEmpty( $declared['errors'] );
		$this->assertStringContainsString(
			'shape the extraction cannot see',
			implode( ' | ', $declared['errors'] )
		);
	}

	public function test_an_empty_tree_is_a_fault_rather_than_a_clean_read(): void {
		$declared = ffc_schema_declared_columns( $this->fixture_tree( '<?php // nothing here.' ) );

		$this->assertSame( array(), $declared['tables'] );
		$this->assertSame( array( 'no CREATE TABLE statement was found under includes/' ), $declared['errors'] );
	}
}
