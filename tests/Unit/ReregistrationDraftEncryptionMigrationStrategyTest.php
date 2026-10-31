<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Migrations\Strategies\ReregistrationDraftEncryptionMigrationStrategy;
use FreeFormCertificate\Tests\Support\Identifiers;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReregistrationDraftEncryptionMigrationStrategy: the sensitive
 * values drafts stored in clear are encrypted, row by row, behind a cursor
 * that advances over every row examined (#2242).
 *
 * Runs with the real Encryption (the bootstrap's key constants) over a
 * `$wpdb` double: campaign 1 links audience 1, whose fields are a sensitive
 * `cpf` and a plain `nickname`.
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\ReregistrationDraftEncryptionMigrationStrategy
 */
class ReregistrationDraftEncryptionMigrationStrategyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $wpdb;

	/**
	 * Stored submissions: id => data JSON.
	 *
	 * @var array<int, string>
	 */
	private array $rows = array();

	/** @var array<string, mixed> Options written, by name. */
	private array $options = array();

	/** @var bool Whether the submissions table exists. */
	private bool $table = true;

	/** @var array{0: bool, 1: mixed} Whether `$wpdb` was set, and its value. */
	private array $wpdb_backup = array( false, null );

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb_backup = array( array_key_exists( 'wpdb', $GLOBALS ), $GLOBALS['wpdb'] ?? null );

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$this->wpdb       = $wpdb;

		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $query, ...$args ): string {
				$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				return $query . ' | ' . implode( ',', array_map( 'strval', $values ) );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( string $sql ) {
				if ( 0 === strpos( $sql, 'SHOW TABLES' ) ) {
					return $this->table ? 'wp_ffc_reregistration_submissions' : null;
				}
				if ( 0 === strpos( $sql, 'SELECT COUNT(*) FROM %i WHERE id > %d AND data IS NOT NULL' ) ) {
					$after = (int) explode( ',', $sql )[1];
					return (string) count( array_filter( array_keys( $this->rows ), static fn( $id ) => $id > $after ) );
				}
				$this->fail( 'Unexpected get_var: ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturn( array( '1' ) );
		$wpdb->shouldReceive( 'get_row' )->andReturn(
			(object) array(
				'id'        => 1,
				'name'      => 'Audience',
				'parent_id' => 0,
			)
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( string $sql ) {
				if ( false !== strpos( $sql, 'SELECT id, reregistration_id, data FROM %i' ) ) {
					list( , $after, $limit ) = explode( ',', explode( ' | ', $sql )[1] );
					$out                     = array();
					foreach ( $this->rows as $id => $data ) {
						if ( $id > (int) $after && count( $out ) < (int) $limit ) {
							$out[] = array(
								'id'                => (string) $id,
								'reregistration_id' => '1',
								'data'              => $data,
							);
						}
					}
					return $out;
				}
				if ( false !== strpos( $sql, 'ffc_custom_fields' ) ) {
					return array(
						$this->field( 1, 'cpf', 1 ),
						$this->field( 2, 'nickname', 0 ),
					);
				}
				$this->fail( 'Unexpected get_results: ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( string $table, array $data, array $where ) {
				$this->rows[ (int) $where['id'] ] = (string) $data['data'];
				return 1;
			}
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		if ( $this->wpdb_backup[0] ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup[1];
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	private function field( int $id, string $key, int $sensitive ): object {
		return (object) array(
			'id'                => $id,
			'audience_id'       => 1,
			'field_key'         => $key,
			'field_label'       => $key,
			'field_type'        => 'text',
			'field_group'       => 'personal',
			'field_source'      => 'custom',
			'field_profile_key' => null,
			'field_mask'        => null,
			'is_sensitive'      => $sensitive,
			'field_options'     => null,
			'validation_rules'  => null,
			'sort_order'        => $id,
			'is_required'       => 0,
			'is_active'         => 1,
		);
	}

	private static function data( array $fields ): string {
		return (string) json_encode( array( 'fields' => $fields ) );
	}

	public function test_a_plaintext_sensitive_value_is_encrypted_and_the_rest_left_alone(): void {
		$this->rows = array( 5 => self::data( array( 'cpf' => Identifiers::CPF_A, 'nickname' => 'Jo' ) ) );

		$result = ( new ReregistrationDraftEncryptionMigrationStrategy() )->execute( 'rereg_draft_encryption', array( 'batch_size' => 10 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['processed'] );
		$this->assertFalse( $result['has_more'] );
		$stored = json_decode( $this->rows[5], true )['fields'];
		$this->assertNotSame( Identifiers::CPF_A, $stored['cpf'] );
		$this->assertSame( Identifiers::CPF_A, Encryption::decrypt( $stored['cpf'] ) );
		$this->assertSame( 'Jo', $stored['nickname'], 'A field that is not sensitive stays as it was.' );
	}

	/**
	 * A row already encrypted is examined and counted as done, not changed:
	 * the batch counts what CHANGED (#1378), and an envelope is never
	 * wrapped a second time.
	 */
	public function test_an_encrypted_row_is_examined_and_left_alone(): void {
		$envelope   = (string) Encryption::encrypt( Identifiers::CPF_A );
		$this->rows = array(
			5 => self::data( array( 'cpf' => $envelope, 'nickname' => 'Jo' ) ),
			6 => self::data( array( 'cpf' => '', 'nickname' => 'Bia' ) ),
		);
		$before     = $this->rows;

		$result = ( new ReregistrationDraftEncryptionMigrationStrategy() )->execute( 'rereg_draft_encryption', array( 'batch_size' => 10 ) );

		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( $before, $this->rows );
		$this->assertSame( array( 'cursor' => 6 ), $this->options[ ReregistrationDraftEncryptionMigrationStrategy::STATE_OPTION ], 'The cursor still moves over every row examined.' );
	}

	public function test_the_cursor_carries_the_walk_across_batches_and_it_ends(): void {
		$this->rows = array(
			1 => self::data( array( 'cpf' => Identifiers::CPF_A ) ),
			2 => self::data( array( 'cpf' => '' ) ),
			3 => self::data( array( 'cpf' => Identifiers::CPF_B ) ),
		);
		$strategy   = new ReregistrationDraftEncryptionMigrationStrategy();

		$first = $strategy->execute( 'rereg_draft_encryption', array( 'batch_size' => 2 ) );
		$this->assertSame( 1, $first['processed'] );
		$this->assertTrue( $first['has_more'] );
		$this->assertSame( 1, $strategy->calculate_status( 'rereg_draft_encryption', array() )['pending'] );

		$second = $strategy->execute( 'rereg_draft_encryption', array( 'batch_size' => 2 ) );
		$this->assertSame( 1, $second['processed'] );
		$this->assertFalse( $second['has_more'], 'The walk ends at the last row, whatever the last batch changed.' );

		$status = $strategy->calculate_status( 'rereg_draft_encryption', array() );
		$this->assertSame( 0, $status['pending'] );
		$this->assertTrue( $status['is_complete'] );
		$this->assertSame( 3, $status['total'] );
	}

	public function test_a_row_without_answers_is_skipped(): void {
		$this->rows = array( 4 => '{"not":"fields"}' );

		$result = ( new ReregistrationDraftEncryptionMigrationStrategy() )->execute( 'rereg_draft_encryption', array() );

		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( '{"not":"fields"}', $this->rows[4] );
	}

	public function test_no_table_reads_as_complete(): void {
		$this->table = false;
		$strategy    = new ReregistrationDraftEncryptionMigrationStrategy();

		$this->assertTrue( $strategy->calculate_status( 'rereg_draft_encryption', array() )['is_complete'] );
		$this->assertFalse( $strategy->execute( 'rereg_draft_encryption', array() )['has_more'] );
	}

	public function test_it_runs_only_with_encryption_and_names_itself(): void {
		$strategy = new ReregistrationDraftEncryptionMigrationStrategy();

		$this->assertTrue( $strategy->can_run( 'rereg_draft_encryption', array() ) );
		$this->assertSame( 'Reregistration: Encrypt Draft Answers', $strategy->get_name() );
	}
}
