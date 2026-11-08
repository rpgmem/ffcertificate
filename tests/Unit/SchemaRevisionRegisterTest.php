<?php
/**
 * A schema change ships with a revision bump (#2430).
 *
 * WHAT WAS WRONG
 *
 * Each module heals its schema on a request, behind a gate. Where the gate was
 * `FFC_VERSION`, a table, column or index merged mid-batch never ran on the
 * testes site, because `develop` keeps the released version for the whole
 * batch: the post-deploy smoke found `ffc_short_url_retired` missing (#2337),
 * and audience and self-scheduling had hit the same gap before. #2435 gave
 * every module a `SCHEMA_REVISION`. A revision only helps if the PR that
 * changes the schema bumps it, and nothing checked that; the smoke noticed a
 * missing bump only after the merge, and only for a table that went missing.
 *
 * WHAT THIS CHECKS
 *
 * A register freezes, per class that declares `SCHEMA_REVISION_OPTION`, its
 * revision and a fingerprint of the schema its file declares
 * ({@see SchemaRevisions::schema_literals()}). A change to that schema with
 * the same revision fails here, before the merge. A change with a new revision
 * fails too, until the register is regenerated, which is the explicit step the
 * other ratchets ask for:
 *
 *   FFC_UPDATE_SCHEMA_REVISION_REGISTER=1 vendor/bin/phpunit --filter SchemaRevisionRegister
 *
 * Every other file under `includes/` that declares schema belongs to one of
 * those classes (`OWNERS`) or says why it is outside the rule (`EXEMPT`).
 *
 * WHAT IT CANNOT SEE
 *
 * A column assembled from variables, and whether a bump reaches the heal that
 * needs it: the revision gates one chain, and a step left outside that chain
 * would not run however the number moves. It reads text; the fresh-install
 * job and the post-deploy smoke cross into a database.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\SchemaRevisions;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class SchemaRevisionRegisterTest extends TestCase {

	private const REGISTER = 'tests/fixtures/schema-revision-register.php';

	/**
	 * Files whose schema runs behind another class's revision.
	 *
	 * @var array<string, string> File => owning class's file.
	 */
	private const OWNERS = array(
		// The user foreign keys run from Activator::maybe_add_foreign_keys(),
		// behind the core revision; their set is the CONSTRAINTS map.
		'includes/migrations/class-ffc-migration-foreign-keys.php' => 'includes/class-ffc-activator.php',
		// The creation-time conversion runs from ActivityLog::create_table(),
		// behind the activity log's revision (#2340).
		'includes/core/class-ffc-activity-log-instants.php'        => 'includes/core/class-ffc-activity-log.php',
	);

	/**
	 * Files that declare schema outside any revision, with the reason.
	 *
	 * @var array<string, string>
	 */
	private const EXEMPT = array(
		'includes/core/class-ffc-database-helper-trait.php'        => 'The schema helpers themselves: they declare no column of their own.',
		'includes/migrations/class-ffc-migration-dynamic-rereg-fields.php' => 'A one-shot migration of two tables their activators also declare; SchemaAgreementTest keeps the declarations equal, and the activators carry the revision.',
		'includes/generators/class-ffc-qrcode-generator.php'       => 'Adds its cache column to the submissions table on first use, not behind a gate.',
		'includes/submissions/class-ffc-submission-lifecycle-service.php' => 'Resets AUTO_INCREMENT after a bulk delete; data maintenance, not a schema declaration.',
	);

	/**
	 * Calls that create or change schema.
	 */
	private const SCHEMA_CALLS = array( 'dbDelta', 'add_column_if_missing', 'add_columns_if_missing', 'add_index_if_missing', 'add_indexes_if_missing', 'migrate_datetime_column_to_unix' );

	/**
	 * The register as the code reads now.
	 *
	 * @return array<string, array{class: string, revision: string, fingerprint: string}>
	 */
	private static function current(): array {
		$by_owner = array();
		foreach ( SchemaRevisions::classes() as $file => $class ) {
			$by_owner[ $file ] = array( $file );
		}
		foreach ( self::OWNERS as $file => $owner ) {
			$by_owner[ $owner ][] = $file;
		}

		$out = array();
		foreach ( SchemaRevisions::classes() as $file => $class ) {
			$extra = array();
			if ( 'includes/class-ffc-activator.php' === $file ) {
				$extra[] = (string) json_encode( ( new \ReflectionClassConstant( \FreeFormCertificate\Migrations\MigrationForeignKeys::class, 'CONSTRAINTS' ) )->getValue() );
			}
			$out[ $file ] = array(
				'class'       => $class,
				'revision'    => (string) constant( $class . '::SCHEMA_REVISION' ),
				'fingerprint' => SchemaRevisions::fingerprint( $by_owner[ $file ], $extra ),
			);
		}
		ksort( $out );
		return $out;
	}

	/**
	 * The frozen register.
	 *
	 * @return array<string, array{class: string, revision: string, fingerprint: string}>
	 */
	private static function register(): array {
		$path = dirname( __DIR__, 2 ) . '/' . self::REGISTER;
		/** @var array<string, array{class: string, revision: string, fingerprint: string}> $register */
		$register = is_file( $path ) ? require $path : array();
		return $register;
	}

	/**
	 * Files that declare schema: a `CREATE TABLE` or `ALTER TABLE` literal, or
	 * a schema call.
	 *
	 * @return array<int, string>
	 */
	private static function schema_files(): array {
		$out = array();
		foreach ( SchemaRevisions::files() as $file ) {
			$tokens = token_get_all( (string) file_get_contents( SchemaRevisions::root() . '/' . $file ) );
			$count  = count( $tokens );
			for ( $i = 0; $i < $count; $i++ ) {
				$token = $tokens[ $i ];
				if ( ! is_array( $token ) ) {
					continue;
				}
				$is_create = in_array( $token[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) && 1 === preg_match( '/\b(?:CREATE|ALTER)\s+TABLE\b/i', $token[1] );
				$is_call   = T_STRING === $token[0] && in_array( $token[1], self::SCHEMA_CALLS, true ) && self::is_call( $tokens, $i );
				if ( $is_create || $is_call ) {
					$out[] = $file;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Whether the name at $i is called, not defined.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $i      Position.
	 * @return bool
	 */
	private static function is_call( array $tokens, int $i ): bool {
		$next = $i + 1;
		while ( isset( $tokens[ $next ] ) && is_array( $tokens[ $next ] ) && T_WHITESPACE === $tokens[ $next ][0] ) {
			++$next;
		}
		$previous = $i - 1;
		while ( $previous >= 0 && is_array( $tokens[ $previous ] ) && T_WHITESPACE === $tokens[ $previous ][0] ) {
			--$previous;
		}
		return isset( $tokens[ $next ] ) && '(' === $tokens[ $next ]
			&& ! ( $previous >= 0 && is_array( $tokens[ $previous ] ) && T_FUNCTION === $tokens[ $previous ][0] );
	}

	public function test_a_schema_change_bumps_its_revision(): void {
		$current = self::current();

		if ( getenv( 'FFC_UPDATE_SCHEMA_REVISION_REGISTER' ) ) {
			$body = "<?php\n/**\n * Schema revision register (#2430) — generated.\n"
				. " * Regenerate: FFC_UPDATE_SCHEMA_REVISION_REGISTER=1 vendor/bin/phpunit --filter SchemaRevisionRegister\n"
				. " * Each entry is a class that gates its schema on SCHEMA_REVISION, with the\n"
				. " * revision and a fingerprint of the schema it declares. A schema change with\n"
				. " * the same revision fails; regenerate only after bumping it.\n */\n\nreturn array(\n";
			foreach ( $current as $file => $entry ) {
				$body .= "\t'" . $file . "' => array(\n"
					. "\t\t'class'       => '" . addslashes( $entry['class'] ) . "',\n"
					. "\t\t'revision'    => '" . addslashes( $entry['revision'] ) . "',\n"
					. "\t\t'fingerprint' => '" . $entry['fingerprint'] . "',\n"
					. "\t),\n";
			}
			$body .= ");\n";
			file_put_contents( dirname( __DIR__, 2 ) . '/' . self::REGISTER, $body );
		}

		$register = self::register();
		$problems = array();

		foreach ( $current as $file => $entry ) {
			if ( ! isset( $register[ $file ] ) ) {
				$problems[] = sprintf( '%s declares SCHEMA_REVISION_OPTION and is not in the register: regenerate it.', $file );
				continue;
			}
			$frozen = $register[ $file ];
			if ( $frozen['fingerprint'] !== $entry['fingerprint'] && $frozen['revision'] === $entry['revision'] ) {
				$problems[] = sprintf( '%s changed its schema and kept SCHEMA_REVISION at %s: bump it, so an install on the same FFC_VERSION runs the change, then regenerate the register.', $entry['class'], $entry['revision'] );
			} elseif ( $frozen['fingerprint'] !== $entry['fingerprint'] || $frozen['revision'] !== $entry['revision'] ) {
				$problems[] = sprintf( '%s moved (revision %s → %s): regenerate the register to lock it in.', $entry['class'], $frozen['revision'], $entry['revision'] );
			}
		}
		foreach ( array_diff_key( $register, $current ) as $file => $entry ) {
			$problems[] = sprintf( '%s is in the register and no longer declares SCHEMA_REVISION_OPTION: regenerate it.', $file );
		}

		$this->assertSame( array(), $problems, implode( "\n", $problems ) . "\n\nFFC_UPDATE_SCHEMA_REVISION_REGISTER=1 vendor/bin/phpunit --filter SchemaRevisionRegister" );
	}

	public function test_every_file_that_declares_schema_has_a_revision_or_a_reason(): void {
		$covered = array_merge( array_keys( SchemaRevisions::classes() ), array_keys( self::OWNERS ), array_keys( self::EXEMPT ) );
		$loose   = array_values( array_diff( self::schema_files(), $covered ) );

		$this->assertSame(
			array(),
			$loose,
			"These files declare schema and no class with SCHEMA_REVISION covers them. Give the class one, or name its owner in OWNERS, or the reason in EXEMPT:\n" . implode( "\n", $loose )
		);
	}

	public function test_an_owner_or_an_exemption_that_names_nothing_fails(): void {
		$schema = self::schema_files();
		$stale  = array();
		foreach ( array_merge( array_keys( self::OWNERS ), array_keys( self::EXEMPT ) ) as $file ) {
			if ( ! in_array( $file, $schema, true ) ) {
				$stale[] = $file;
			}
		}
		foreach ( self::OWNERS as $owner ) {
			if ( ! isset( SchemaRevisions::classes()[ $owner ] ) ) {
				$stale[] = $owner . ' (named as an owner, has no SCHEMA_REVISION)';
			}
		}

		$this->assertSame( array(), $stale, 'Stale OWNERS / EXEMPT entries: ' . implode( ', ', $stale ) );
	}

	public function test_every_revision_option_is_declared_in_the_uninstall_manifest(): void {
		require_once dirname( __DIR__, 2 ) . '/.github/scripts/ffc-uninstall-manifest.php';
		$manifest = ffc_manifest_options( dirname( __DIR__, 2 ) . '/uninstall.php' );
		$this->assertNotSame( array(), $manifest, 'The uninstall manifest read no option: the check did not run.' );

		$missing = array_values( array_diff( array_keys( SchemaRevisions::current_options() ), $manifest ) );
		$this->assertSame( array(), $missing, 'Revision options uninstall.php does not delete: ' . implode( ', ', $missing ) );
	}

	/**
	 * Self-check: the token scan finds every class a second reading finds, and
	 * each of them declares schema the fingerprint can read. Without it a scan
	 * that lost a class, or read nothing in one, would pass as clean.
	 */
	public function test_the_scan_reaches_every_revision_class(): void {
		$recount = array();
		foreach ( glob( SchemaRevisions::root() . '/includes/{,*/,*/*/}*.php', GLOB_BRACE ) ?: array() as $path ) {
			$code = (string) php_strip_whitespace( $path );
			if ( 1 === preg_match( '/\bconst\s+SCHEMA_REVISION_OPTION\b/', $code ) ) {
				$recount[] = substr( $path, strlen( SchemaRevisions::root() ) + 1 );
			}
		}
		sort( $recount );

		$this->assertSame( $recount, array_keys( SchemaRevisions::classes() ), 'The token scan and the recount disagree about which classes carry a revision.' );
		$this->assertContains( 'includes/class-ffc-activator.php', $recount, 'The core activator, the class every install runs, was not found.' );

		foreach ( SchemaRevisions::classes() as $file => $class ) {
			$this->assertNotSame( array(), SchemaRevisions::schema_literals( $file ), $class . ' carries a revision and the fingerprint read no schema in it.' );
		}
	}
}
