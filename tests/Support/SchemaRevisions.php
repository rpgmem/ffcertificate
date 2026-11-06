<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Which classes gate a schema on their own revision, and what that schema
 * reads like (#2430).
 *
 * WHY THIS IS SHARED
 *
 * `SchemaRevisionRegisterTest` needs the list to check that a schema change
 * bumps its class's revision, and `ActivatorSchemaGuardTest` and
 * `LoaderCapabilitiesTest` need it to answer every gate as current on an
 * established install. Those two kept the map by hand, and #2429 had to edit
 * both when a module gained a revision; reading it from the activators means a
 * new one is covered the moment it declares its constants.
 *
 * WHAT A CLASS IS
 *
 * A class under `includes/` that declares `SCHEMA_REVISION_OPTION`. The scan
 * reads tokens, so a constant named in a docblock is not a declaration, and it
 * resolves the namespace and class from the file itself; the values come from
 * the loaded class, never from the text.
 *
 * WHAT THE FINGERPRINT READS
 *
 * The schema a class declares, and nothing else, so an edit to a comment or to
 * logic around it does not demand a bump:
 *
 * - every string literal that reads as DDL: a `CREATE TABLE`, an `ALTER`, a
 *   column type with a modifier, an index or key clause, an index column list;
 * - every literal argument of a schema helper call (`add_column_if_missing()`
 *   and its siblings, `migrate_datetime_column_to_unix()`), so a column's NAME
 *   counts as well as its type.
 *
 * What it cannot see is a value assembled from variables; the schema here is
 * written as literals, which is the shape `ffc-create-statements.php` and
 * `SchemaColumns` already rely on.
 *
 * @package FreeFormCertificate\Tests\Support
 */
final class SchemaRevisions {

	/**
	 * Calls whose literal arguments are schema.
	 */
	private const HELPERS = array(
		'add_column_if_missing',
		'add_columns_if_missing',
		'add_index_if_missing',
		'add_indexes_if_missing',
		'migrate_datetime_column_to_unix',
	);

	/**
	 * A string literal that reads as DDL.
	 */
	private const DDL = '/\b(?:CREATE\s+TABLE|ALTER\s+TABLE|ADD\s+(?:COLUMN|INDEX|KEY|UNIQUE|CONSTRAINT|FOREIGN)|DROP\s+(?:COLUMN|INDEX|KEY|FOREIGN)|MODIFY\s+(?:COLUMN\s+)?\w|CHANGE\s+(?:COLUMN\s+)?\w|PRIMARY\s+KEY|UNIQUE\s+(?:KEY|INDEX)|FOREIGN\s+KEY)\b|\b(?:bigint|int|smallint|tinyint|mediumint|varchar|char|text|mediumtext|longtext|datetime|date|time|decimal|float|double|enum|json)\b\s*(?:\(|unsigned\b|NOT\s+NULL\b|NULL\b|DEFAULT\b|CHARACTER\s+SET\b|COLLATE\b)|^\s*\(\s*\w+(?:\s*,\s*\w+)*\s*\)\s*$/i';

	/**
	 * Repository root.
	 *
	 * @return string
	 */
	public static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Every PHP file under `includes/`, relative to the root, sorted.
	 *
	 * @return array<int, string>
	 */
	public static function files(): array {
		$root = self::root();
		$out  = array();
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$out[] = substr( $file->getPathname(), strlen( $root ) + 1 );
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * The classes that declare `SCHEMA_REVISION_OPTION`, keyed by file.
	 *
	 * @return array<string, class-string> File => class.
	 */
	public static function classes(): array {
		$out = array();
		foreach ( self::files() as $file ) {
			$tokens    = token_get_all( (string) file_get_contents( self::root() . '/' . $file ) );
			$namespace = '';
			$class     = '';
			$declares  = false;
			$count     = count( $tokens );
			for ( $i = 0; $i < $count; $i++ ) {
				if ( ! is_array( $tokens[ $i ] ) ) {
					continue;
				}
				if ( T_NAMESPACE === $tokens[ $i ][0] ) {
					$namespace = self::next_name( $tokens, $i );
				} elseif ( T_CLASS === $tokens[ $i ][0] && '' === $class ) {
					$class = self::next_name( $tokens, $i );
				} elseif ( T_CONST === $tokens[ $i ][0] && 'SCHEMA_REVISION_OPTION' === self::next_name( $tokens, $i ) ) {
					$declares = true;
				}
			}
			if ( $declares && '' !== $class ) {
				/** @var class-string $fqcn */
				$fqcn         = ltrim( $namespace . '\\' . $class, '\\' );
				$out[ $file ] = $fqcn;
			}
		}
		return $out;
	}

	/**
	 * Each class's revision option and the revision an established install holds.
	 *
	 * @return array<string, string> Option => revision.
	 */
	public static function current_options(): array {
		$out = array();
		foreach ( self::classes() as $class ) {
			$out[ (string) constant( $class . '::SCHEMA_REVISION_OPTION' ) ] = (string) constant( $class . '::SCHEMA_REVISION' );
		}
		return $out;
	}

	/**
	 * The schema text one file declares, in file order.
	 *
	 * @param string $file File relative to the root.
	 * @return array<int, string>
	 */
	public static function schema_literals( string $file ): array {
		$tokens = token_get_all( (string) file_get_contents( self::root() . '/' . $file ) );
		$count  = count( $tokens );
		$out    = array();
		$depth  = 0;

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( 0 === $depth && is_array( $token ) && T_STRING === $token[0] && in_array( $token[1], self::HELPERS, true ) ) {
				$j = $i + 1;
				while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					++$j;
				}
				$previous = $i - 1;
				while ( $previous >= 0 && is_array( $tokens[ $previous ] ) && T_WHITESPACE === $tokens[ $previous ][0] ) {
					--$previous;
				}
				$is_definition = $previous >= 0 && is_array( $tokens[ $previous ] ) && T_FUNCTION === $tokens[ $previous ][0];
				if ( '(' === $tokens[ $j ] && ! $is_definition ) {
					$depth = 1;
					$i     = $j;
					continue;
				}
			}

			if ( $depth > 0 ) {
				if ( '(' === $token ) {
					++$depth;
				} elseif ( ')' === $token ) {
					--$depth;
				} elseif ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
					$out[] = self::normalise( $token[1] );
				}
				continue;
			}

			if ( is_array( $token ) && in_array( $token[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) && 1 === preg_match( self::DDL, trim( $token[1], "'\"" ) ) ) {
				$out[] = self::normalise( $token[1] );
			}
		}

		return $out;
	}

	/**
	 * A fingerprint of the schema text the given files declare.
	 *
	 * @param array<int, string> $files Files relative to the root.
	 * @param array<int, string> $extra Further schema text (a constant's value).
	 * @return string
	 */
	public static function fingerprint( array $files, array $extra = array() ): string {
		$parts = $extra;
		foreach ( $files as $file ) {
			$parts[] = '# ' . $file;
			foreach ( self::schema_literals( $file ) as $literal ) {
				$parts[] = $literal;
			}
		}
		return sha1( implode( "\n", $parts ) );
	}

	/**
	 * Collapse whitespace, so re-indenting a statement changes nothing.
	 *
	 * @param string $text Literal.
	 * @return string
	 */
	private static function normalise( string $text ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * The name after a keyword token.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $i      Keyword position.
	 * @return string
	 */
	private static function next_name( array $tokens, int $i ): string {
		$count = count( $tokens );
		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( ! is_array( $tokens[ $j ] ) ) {
				return '';
			}
			if ( in_array( $tokens[ $j ][0], array( T_STRING, T_NAME_QUALIFIED ), true ) ) {
				return $tokens[ $j ][1];
			}
			if ( T_WHITESPACE !== $tokens[ $j ][0] ) {
				return '';
			}
		}
		return '';
	}
}
