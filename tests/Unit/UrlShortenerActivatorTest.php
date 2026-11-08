<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UrlShortener\UrlShortenerActivator;

/**
 * Tests for UrlShortenerActivator: table name generation,
 * idempotent table creation, and migration.
 *
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerActivator
 */
class UrlShortenerActivatorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->last_error = '';

		$this->wpdb = $wpdb;

		// ABSPATH and the upgrade.php create_tables() requires come from the
		// bootstrap (tests/Support/UpgradeStub.php, #1803).

		// This activator's chain became guarded by `FFC_VERSION` (#1231), so it
		// reads and writes a version option. The value returned here is
		// DIFFERENT from `FFC_VERSION` on purpose: these tests exercise the
		// chain's body, which only runs when the guard does not match.
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'update_option' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// get_table_name()
	// ==================================================================

	public function test_get_table_name_uses_prefix(): void {
		$this->assertSame( 'wp_ffc_short_urls', UrlShortenerActivator::get_table_name() );
	}

	public function test_get_table_name_custom_prefix(): void {
		global $wpdb;
		$wpdb->prefix = 'custom_';

		$this->assertSame( 'custom_ffc_short_urls', UrlShortenerActivator::get_table_name() );

		// Restore
		$wpdb->prefix = 'wp_';
	}

	// ==================================================================
	// create_tables()
	// ==================================================================

	public function test_create_tables_skips_if_table_exists(): void {
		$this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET utf8mb4' );
		$this->every_table_exists();

		$delta_called = false;
		Functions\when( 'dbDelta' )->alias( function () use ( &$delta_called ) {
			$delta_called = true;
		} );

		UrlShortenerActivator::create_tables();

		$this->assertFalse( $delta_called, 'dbDelta should not be called when table already exists' );
	}

	public function test_create_tables_calls_db_delta_when_missing(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET utf8mb4' );

		$delta_called = false;
		$delta_sql    = '';
		Functions\when( 'dbDelta' )->alias( function ( $sql ) use ( &$delta_called, &$delta_sql ) {
			$delta_called = true;
			$delta_sql    = $sql;
		} );

		UrlShortenerActivator::create_tables();

		$this->assertTrue( $delta_called );
		$this->assertStringContainsString( 'ffc_short_urls', $delta_sql );
		$this->assertStringNotContainsString( 'ffc_short_url_retired', $delta_sql, 'The last statement is the links table; the retired one runs first.' );
		$this->assertStringContainsString( 'short_code', $delta_sql );
		$this->assertStringContainsString( 'target_url', $delta_sql );
		$this->assertStringContainsString( 'click_count', $delta_sql );
		$this->assertStringContainsString( 'PRIMARY KEY', $delta_sql );
	}

	// ==================================================================
	// maybe_migrate()
	// ==================================================================

	public function test_maybe_migrate_creates_table_if_missing(): void {
		// First call: table_exists returns false
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET utf8mb4' );
		// maybe_migrate() also calls add_column_if_missing() which uses
		// DatabaseHelperTrait::column_exists() → $wpdb->get_results().
		// Return empty array so the column is considered missing and then
		// stub the resulting ALTER TABLE via $wpdb->query.
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$this->wpdb->shouldReceive( 'query' )->andReturn( 1 );

		$delta_called = false;
		Functions\when( 'dbDelta' )->alias( function () use ( &$delta_called ) {
			$delta_called = true;
		} );

		UrlShortenerActivator::maybe_migrate();

		$this->assertTrue( $delta_called );
	}

	public function test_maybe_migrate_skips_if_table_exists(): void {
		$this->every_table_exists();
		$this->established_columns();
		$this->wpdb->shouldReceive( 'query' )->andReturn( 1 );

		$delta_called = false;
		Functions\when( 'dbDelta' )->alias( function () use ( &$delta_called ) {
			$delta_called = true;
		} );

		UrlShortenerActivator::maybe_migrate();

		$this->assertFalse( $delta_called );
	}

	// ==================================================================
	// The retired codes table (#2337)
	// ==================================================================

	public function test_the_retired_table_is_created_on_an_install_that_has_the_links_table(): void {
		// An upgraded install: the links table exists, the retired one does not.
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( self::prepared( ... ) );
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static fn( $table ) => 'wp_ffc_short_urls' === $table ? $table : null
		);
		$this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET utf8mb4' );
		$this->established_columns();

		$statements = array();
		Functions\when( 'dbDelta' )->alias(
			static function ( $sql ) use ( &$statements ) {
				$statements[] = $sql;
			}
		);

		UrlShortenerActivator::maybe_migrate();

		$this->assertCount( 1, $statements );
		$this->assertStringContainsString( 'CREATE TABLE wp_ffc_short_url_retired', $statements[0] );
		$this->assertStringContainsString( 'UNIQUE KEY idx_short_code (short_code)', $statements[0] );
		$this->assertStringContainsString( 'retired_at bigint(20) unsigned NOT NULL', $statements[0] );
	}

	public function test_get_retired_table_name_uses_prefix(): void {
		$this->assertSame( 'wp_ffc_short_url_retired', UrlShortenerActivator::get_retired_table_name() );
	}

	// ==================================================================
	// Case-sensitive codes (#2337)
	// ==================================================================

	public function test_both_tables_declare_a_binary_short_code(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET utf8mb4' );
		$statements = array();
		Functions\when( 'dbDelta' )->alias(
			static function ( $sql ) use ( &$statements ) {
				$statements[] = $sql;
			}
		);

		UrlShortenerActivator::create_tables();

		$this->assertCount( 2, $statements );
		foreach ( $statements as $sql ) {
			$this->assertStringContainsString( 'short_code ' . UrlShortenerActivator::SHORT_CODE_COLUMN, $sql );
		}
	}

	public function test_a_case_insensitive_short_code_is_made_binary(): void {
		$queries = array();
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $q, ...$args ) => $q . ' | ' . implode( ' | ', $args ) );
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static fn( $q ) => 0 === strpos( $q, 'SHOW TABLES' ) ? 'wp_ffc_short_urls' : 'utf8mb4_unicode_520_ci'
		);
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( $q ) use ( &$queries ) {
				$queries[] = $q;
				return 1;
			}
		);

		UrlShortenerActivator::ensure_case_sensitive_codes( 'wp_ffc_short_urls' );

		$this->assertSame( array( 'ALTER TABLE %i MODIFY short_code ' . UrlShortenerActivator::SHORT_CODE_COLUMN . ' | wp_ffc_short_urls' ), $queries );
	}

	public function test_a_binary_short_code_is_left_alone(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $q, ...$args ) => $q . ' | ' . implode( ' | ', $args ) );
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static fn( $q ) => 0 === strpos( $q, 'SHOW TABLES' ) ? 'wp_ffc_short_urls' : 'utf8mb4_bin'
		);
		$this->wpdb->shouldNotReceive( 'query' );

		UrlShortenerActivator::ensure_case_sensitive_codes( 'wp_ffc_short_urls' );
	}

	public function test_a_stored_301_becomes_302_once(): void {
		$options = array(
			'ffc_settings' => array( 'url_shortener_redirect_type' => 301, 'url_shortener_prefix' => 'go' ),
		);
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( &$options ) {
				return $options[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$options ) {
				$options[ $key ] = $value;
				return true;
			}
		);

		UrlShortenerActivator::migrate_permanent_redirects();
		$this->assertSame( 302, $options['ffc_settings']['url_shortener_redirect_type'] );
		$this->assertSame( 'go', $options['ffc_settings']['url_shortener_prefix'], 'Nothing else moves.' );

		// An administrator picks 301 afterwards: the migration has run and leaves it.
		$options['ffc_settings']['url_shortener_redirect_type'] = 301;
		UrlShortenerActivator::migrate_permanent_redirects();
		$this->assertSame( 301, $options['ffc_settings']['url_shortener_redirect_type'] );
	}

	public function test_a_schema_step_reaches_an_install_already_at_this_version(): void {
		// The testes site sits at the released FFC_VERSION for a whole batch:
		// the version gate is current, and the retired table is still missing.
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => 'ffc_url_shortener_schema_version' === $key ? FFC_VERSION : $default );
		$written = array();
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$written ) {
				$written[ $key ] = $value;
				return true;
			}
		);
		$created = false;
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $q, ...$args ) => (string) ( $args[0] ?? $q ) );
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( $q ) use ( &$created ) {
				if ( 'wp_ffc_short_url_retired' === $q ) {
					return $created ? $q : null;
				}
				return 'wp_ffc_short_urls' === $q ? $q : 'utf8mb4_bin';
			}
		);
		$this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET utf8mb4' );
		Functions\when( 'dbDelta' )->alias(
			static function () use ( &$created ) {
				$created = true;
			}
		);

		UrlShortenerActivator::maybe_migrate();

		$this->assertTrue( $created, 'The retired codes table is created without a version bump.' );
		$this->assertSame( UrlShortenerActivator::SCHEMA_REVISION, $written[ UrlShortenerActivator::SCHEMA_REVISION_OPTION ] ?? null );
	}

	public function test_the_revision_is_not_recorded_while_the_table_is_missing(): void {
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => 'ffc_url_shortener_schema_version' === $key ? FFC_VERSION : $default );
		$written = array();
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$written ) {
				$written[ $key ] = $value;
				return true;
			}
		);
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $q, ...$args ) => (string) ( $args[0] ?? $q ) );
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing( static fn( $q ) => 'wp_ffc_short_url_retired' === $q ? null : $q );
		$this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET utf8mb4' );
		$this->wpdb->shouldReceive( 'query' )->andReturn( 1 );
		Functions\when( 'dbDelta' )->justReturn( array() ); // The CREATE fails.

		UrlShortenerActivator::maybe_migrate();

		$this->assertArrayNotHasKey( UrlShortenerActivator::SCHEMA_REVISION_OPTION, $written, 'The next request tries again.' );
	}

	/**
	 * Every `SHOW TABLES LIKE` answers with the table it asked about.
	 */
	/**
	 * What prepare() answers: the table for a table check, and table|column
	 * for a column lookup, so the column double can tell columns apart.
	 *
	 * @param string $q       Query.
	 * @param mixed  ...$args Arguments.
	 */
	private static function prepared( $q, ...$args ): string {
		return 0 === strpos( (string) $q, 'SHOW COLUMNS' ) ? implode( '|', $args ) : (string) ( $args[0] ?? $q );
	}

	/**
	 * An established install at the current schema: the instants are
	 * BIGINT already and no staging column exists (#2337).
	 */
	private function established_columns(): void {
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $s ) => $s );
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( $q ) {
				$column = (string) substr( (string) strrchr( (string) $q, '|' ), 1 );
				if ( in_array( $column, array( 'created_at', 'updated_at' ), true ) ) {
					return array( (object) array( 'Field' => $column, 'Type' => 'bigint(20) unsigned' ) );
				}
				return 'qr_cache' === $column ? array( (object) array( 'Field' => 'qr_cache' ) ) : array();
			}
		);
	}

	private function every_table_exists(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( self::prepared( ... ) );
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing( static fn( $table ) => $table );
	}
}
