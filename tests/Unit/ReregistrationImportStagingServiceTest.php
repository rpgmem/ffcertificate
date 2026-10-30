<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Reregistration\ReregistrationImportStagingService;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use FreeFormCertificate\Tests\Support\CallLog;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for the reregistration CSV import (#1214, sprints 2 to 4).
 *
 * The decisions under test are the ones #1214 took explicitly, so each has a
 * test that fails if the decision is reversed: an unknown column is ignored
 * and reported, a required field with no column at all refuses the file
 * before anything is staged, one failed row blocks the whole job, and the
 * promote phase writes through `process_submission()` with both e-mails
 * suppressed.
 *
 * Every collaborator is the real class — the custom-field reader and
 * validator, the campaign repository, the sanitizer, `Encryption` (keyed by
 * the bootstrap's constants), `UserManager` down to `UserCreator`, the
 * audience writer, the submission reader and writer and the data processor —
 * over a `$wpdb` double that holds the campaign, its audience and fields, the
 * job, its staged rows and the seeded submissions, answers each query by its
 * SQL text and records every write. A query the double does not know fails
 * the test. User creation ends at stubbed `wp_create_user()` /
 * `wp_update_user()`, and both mail paths at counted `wp_mail()` /
 * `wp_new_user_notification()`.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationImportStagingService
 */
class ReregistrationImportStagingServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var list<array{sql: string, values: array<int, mixed>}> Every prepare(), with its bound values. */
	private array $queries = array();

	/** @var list<array{table: string, data: array<string, mixed>, where: array<string, mixed>}> */
	private array $updates = array();

	/** @var list<array{table: string, data: array<string, mixed>}> */
	private array $inserts = array();

	/** @var list<string> Tables `$wpdb->delete()` was called on. */
	private array $deletes = array();

	/** @var list<int> Audiences linked to campaign 1. */
	private array $campaign_audiences = array( 7 );

	/** @var list<object> Active custom fields of audience 7. */
	private array $fields = array();

	/** @var object|null The job header row. */
	private ?object $job = null;

	/** @var list<object> The job's staged rows. */
	private array $staged = array();

	/** @var object|null Campaign 1. */
	private ?object $campaign = null;

	/** @var array<int, object|null> Seeded submission of campaign 1 per user id. */
	private array $submissions = array();

	/** @var array<string, int> Normalised CPF/RF of an existing account => user id. */
	private array $people_by_id = array();

	/** @var array<string, int> E-mail of an existing account => user id. */
	private array $people_by_email = array();

	/** @var int The id `wp_create_user()` answers with. */
	private int $created_user_id = 42;

	/** @var string What seeding a missing submission does: ok, lost_the_race or nothing_there. */
	private string $seed_result = 'ok';

	/**
	 * The status a submission moved to after validation, or null when it did
	 * not move: then the conditional write changes no row (#2242).
	 *
	 * @var string|null
	 */
	private ?string $moved_to = null;

	/**
	 * Accounts that hold a staff capability (#2242): an administrator here,
	 * by id. Everyone else holds none.
	 *
	 * @var array<int, string>
	 */
	private array $staff = array();

	/** @var list<mixed> Statuses the last conditional submission write allowed. */
	private array $written_statuses = array();

	/** @var array{unprocessed: int, ready: int, skipped: int} Staging counts commit reads. */
	private array $staging_counts = array(
		'unprocessed' => 0,
		'ready'       => 2,
		'skipped'     => 1,
	);

	/** @var list<array<int, mixed>> Arguments of each `wp_create_user()` call. */
	private array $created_users = array();

	/** @var list<array<string, mixed>> Arguments of each `wp_update_user()` call. */
	private array $user_updates = array();

	/** @var list<string> Values `sanitize_text_field()` received. */
	private array $sanitized = array();

	private CallLog $mail;

	private CallLog $user_notifications;

	/**
	 * A CPF in the `xxx.xxx.xxx-xx` mask a visitor types.
	 *
	 * @param string $cpf Eleven digits.
	 */
	private static function masked( string $cpf ): string {
		return substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9 );
	}

	/**
	 * An RF in the `xxx.xxx-x` mask.
	 *
	 * @param string $rf Seven digits.
	 */
	private static function masked_rf( string $rf ): string {
		return substr( $rf, 0, 3 ) . '.' . substr( $rf, 3, 3 ) . '-' . substr( $rf, 6 );
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov attribution preload (CLAUDE.md pcov gotcha).
		class_exists( '\FreeFormCertificate\Reregistration\ReregistrationImportStagingService' );

		// promote_batch() flushes ActivityLog's buffer; start it empty.
		ActivityLogBuffer::reset();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;

		// Whether an account is staff is a capability read (#2242).
		$this->staff = array();
		Functions\when( 'user_can' )->alias( fn( $user_id, $cap ) => ( $this->staff[ (int) $user_id ] ?? null ) === $cap );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'job-uuid-0001' );
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $v ) => json_encode( $v ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the stub IS the alternative.
		);
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		// Debug off, activity log off, e-mails enabled: an install nobody configured.
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = array() ) => array_merge( (array) $defaults, (array) $args ) );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_rand' )->alias( static fn( $min = 0, $max = 0 ) => random_int( (int) $min, (int) $max ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'generatedpw' );
		Functions\when( 'current_time' )->justReturn( '2026-10-28 10:00:00' );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'update_user_meta' )->justReturn( true );

		$this->sanitized = array();
		$sanitized       = &$this->sanitized;
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $v ) use ( &$sanitized ) {
				$sanitized[] = (string) $v;
				return trim( strip_tags( (string) $v ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- the stub IS the alternative.
			}
		);

		$people = &$this->people_by_email;
		Functions\when( 'get_user_by' )->alias(
			static function ( $field, $value ) use ( &$people ) {
				if ( 'email' !== $field || ! isset( $people[ (string) $value ] ) ) {
					return false;
				}
				$user             = new \WP_User( $people[ (string) $value ] );
				$user->user_email = (string) $value;
				return $user;
			}
		);

		$this->created_users = array();
		$created             = &$this->created_users;
		$created_id          = &$this->created_user_id;
		Functions\when( 'wp_create_user' )->alias(
			static function ( $username, $password, $email = '' ) use ( &$created, &$created_id ) {
				$created[] = array( $username, $password, $email );
				if ( '' === (string) $email ) {
					// What WordPress answers for an empty address.
					return new \WP_Error( 'empty_user_email', 'Cannot create a user with an empty email address.' );
				}
				return $created_id;
			}
		);

		$this->user_updates = array();
		$user_updates       = &$this->user_updates;
		Functions\when( 'wp_update_user' )->alias(
			static function ( $data ) use ( &$user_updates ) {
				$user_updates[] = (array) $data;
				return (int) ( $data['ID'] ?? 0 );
			}
		);

		$this->mail               = CallLog::record( 'wp_mail', true );
		$this->user_notifications = CallLog::record( 'wp_new_user_notification', null );

		$this->wpdb      = $this->database();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		$this->queries = array();
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// The database double
	// ==================================================================

	/**
	 * A `$wpdb` double over the fixture properties above.
	 *
	 * `prepare()` records its bound values and interpolates them, so each read
	 * dispatches on the statement it would run. Anything it does not recognise
	 * throws, so a query the import was not expected to make fails the test.
	 *
	 * @return Mockery\MockInterface
	 */
	private function database() {
		$wpdb            = Mockery::mock( 'wpdb' );
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 0;

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$values          = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				$this->queries[] = array(
					'sql'    => (string) $sql,
					'values' => $values,
				);
				return (string) preg_replace_callback(
					'/%[ids]/',
					static function ( array $m ) use ( &$values ) {
						$value = array_shift( $values );
						if ( '%d' === $m[0] ) {
							return (string) (int) $value;
						}
						return '%s' === $m[0] ? "'" . (string) $value . "'" : (string) $value;
					},
					(string) $sql
				);
			}
		);

		$wpdb->shouldReceive( 'get_row' )->andReturnUsing( fn( $sql ) => $this->answer_row( (string) $sql ) );
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( fn( $sql ) => $this->answer_results( (string) $sql ) );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing( fn( $sql ) => $this->answer_var( (string) $sql ) );
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing( fn( $sql ) => $this->answer_col( (string) $sql ) );
		$wpdb->shouldReceive( 'query' )->andReturnUsing( fn( $sql ) => $this->answer_query( (string) $sql ) );

		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( $wpdb ) {
				$this->inserts[] = array(
					'table' => (string) $table,
					'data'  => (array) $data,
				);
				return $this->answer_insert( $wpdb, (string) $table, (array) $data );
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				if ( ! in_array( $table, array( 'wp_ffc_reregistration_import_staging', 'wp_ffc_reregistration_import_jobs', 'wp_ffc_reregistration_submissions' ), true ) ) {
					throw new \LogicException( 'Unexpected update of ' . $table );
				}
				$this->updates[] = array(
					'table' => (string) $table,
					'data'  => (array) $data,
					'where' => (array) $where,
				);
				return 1;
			}
		);
		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table ) {
				$this->deletes[] = (string) $table;
				return 1;
			}
		);

		return $wpdb;
	}

	/** @return object|array<string, mixed>|null */
	private function answer_row( string $sql ) {
		if ( str_contains( $sql, 'FROM wp_ffc_reregistration_import_jobs WHERE job_id' ) ) {
			return ( null !== $this->job && str_contains( $sql, "'" . $this->job->job_id . "'" ) ) ? $this->job : null;
		}
		if ( preg_match( '/FROM wp_ffc_audiences WHERE id = (\d+)/', $sql, $m ) ) {
			return '7' === $m[1]
				? (object) array(
					'id'        => '7',
					'name'      => 'Audience 7',
					'parent_id' => null,
				)
				: null;
		}
		if ( str_contains( $sql, 'FROM wp_ffc_reregistrations WHERE id = 1' ) ) {
			return $this->campaign;
		}
		if ( preg_match( '/FROM wp_ffc_reregistration_submissions WHERE reregistration_id = 1 AND user_id = (\d+)/', $sql, $m ) ) {
			return $this->submissions[ (int) $m[1] ] ?? null;
		}
		if ( null !== $this->moved_to && preg_match( '/FROM wp_ffc_reregistration_submissions WHERE id = (\d+)/', $sql, $m ) ) {
			return $this->submission( (int) $m[1], $this->moved_to );
		}
		if ( str_contains( $sql, 'FROM wp_ffc_user_profiles WHERE user_id' ) ) {
			return null;
		}
		throw new \LogicException( 'Unexpected get_row: ' . $sql );
	}

	/** @return list<object> */
	private function answer_results( string $sql ): array {
		if ( str_contains( $sql, 'FROM wp_ffc_reregistration_import_staging WHERE job_id' ) ) {
			return $this->staged;
		}
		if ( str_contains( $sql, 'FROM wp_ffc_custom_fields WHERE audience_id = 7 AND is_active = 1' ) ) {
			// Cloned: the reader decorates each row it returns.
			return array_map( static fn( object $f ) => clone $f, $this->fields );
		}
		throw new \LogicException( 'Unexpected get_results: ' . $sql );
	}

	/** @return string|null */
	private function answer_var( string $sql ) {
		if ( null !== $this->moved_to && str_starts_with( $sql, 'SELECT status FROM wp_ffc_reregistration_submissions WHERE id' ) ) {
			return $this->moved_to;
		}
		if ( str_starts_with( $sql, 'SHOW TABLES LIKE' ) ) {
			// Optional tables are absent: no appointment linking, no profile row on create.
			return null;
		}
		if ( str_contains( $sql, 'SELECT user_id FROM wp_ffc_user_profiles WHERE (' ) ) {
			preg_match_all( "/(?:cpf|rf)_hash = '([0-9a-f]+)'/", $sql, $m );
			foreach ( $this->people_by_id as $identifier => $user_id ) {
				if ( in_array( Encryption::hash( (string) $identifier ), $m[1], true ) ) {
					return (string) $user_id;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'SELECT user_id FROM wp_ffc_submissions WHERE (' ) ) {
			return null;
		}
		if ( str_contains( $sql, 'FROM wp_ffc_audience_members WHERE audience_id' ) ) {
			return '0';
		}
		if ( str_contains( $sql, 'WHERE auth_code =' ) || str_contains( $sql, 'WHERE validation_code =' ) ) {
			return null;
		}
		if ( str_contains( $sql, 'SELECT id FROM wp_ffc_user_profiles WHERE user_id' ) ) {
			return null;
		}
		if ( str_contains( $sql, 'FROM wp_ffc_reregistration_import_staging WHERE job_id' ) && str_contains( $sql, 'COUNT(*)' ) ) {
			if ( str_contains( $sql, 'processed = 0' ) ) {
				return (string) $this->staging_counts['unprocessed'];
			}
			if ( str_contains( $sql, "row_status = 'ready'" ) ) {
				return (string) $this->staging_counts['ready'];
			}
			if ( str_contains( $sql, "row_status = 'skipped'" ) ) {
				return (string) $this->staging_counts['skipped'];
			}
		}
		throw new \LogicException( 'Unexpected get_var: ' . $sql );
	}

	/** @return list<string> */
	private function answer_col( string $sql ): array {
		if ( str_contains( $sql, 'FROM wp_ffc_reregistration_audiences WHERE reregistration_id = 1' ) ) {
			return array_map( 'strval', $this->campaign_audiences );
		}
		throw new \LogicException( 'Unexpected get_col: ' . $sql );
	}

	/** @return int */
	private function answer_query( string $sql ): int {
		if ( str_starts_with( $sql, 'INSERT INTO wp_ffc_reregistration_import_staging' ) ) {
			return 1;
		}
		if ( str_starts_with( $sql, 'DELETE' ) && str_contains( $sql, 'wp_ffc_reregistration_import_jobs' ) ) {
			return 0;
		}
		if ( str_starts_with( $sql, 'UPDATE wp_ffc_reregistration_import_jobs SET processed_count' ) ) {
			return 1;
		}
		if ( str_starts_with( $sql, 'UPDATE wp_ffc_reregistration_submissions SET' ) ) {
			// The conditional write (#2242), recorded in the shape `update()`
			// records, decoded from its own prepare() call.
			$last     = end( $this->queries );
			$template = (string) $last['sql'];
			$values   = array_values( (array) $last['values'] );
			preg_match( '/^UPDATE %i SET (.+) WHERE id = %d AND status IN \(/', $template, $m );
			$table = array_shift( $values );
			$data  = array();
			foreach ( explode( ', ', $m[1] ) as $assignment ) {
				$column          = array_shift( $values );
				$data[ $column ] = str_ends_with( $assignment, 'NULL' ) ? null : array_shift( $values );
			}
			$id                     = array_shift( $values );
			$this->written_statuses = $values;
			if ( null !== $this->moved_to ) {
				return 0;
			}
			$this->updates[] = array(
				'table' => (string) $table,
				'data'  => $data,
				'where' => array( 'id' => $id ),
			);
			return 1;
		}
		if ( str_starts_with( $sql, 'UPDATE wp_ffc_submissions SET user_id' ) ) {
			// No orphaned certificate to adopt.
			return 0;
		}
		throw new \LogicException( 'Unexpected query: ' . $sql );
	}

	/**
	 * @param Mockery\MockInterface $wpdb  The double, for `insert_id`.
	 * @param string                $table Table written.
	 * @param array<string, mixed>  $data  Row written.
	 * @return int|false
	 */
	private function answer_insert( $wpdb, string $table, array $data ) {
		switch ( $table ) {
			case 'wp_ffc_reregistration_import_jobs':
			case 'wp_ffc_user_profiles':
				return 1;
			case 'wp_ffc_audience_members':
				$wpdb->insert_id = 5;
				return 1;
			case 'wp_ffc_reregistration_submissions':
				$user_id = (int) $data['user_id'];
				if ( 'nothing_there' === $this->seed_result ) {
					// The INSERT did not land AND no row appeared: nothing to
					// write into, so promotion of this row cannot proceed.
					return false;
				}
				if ( 'lost_the_race' === $this->seed_result ) {
					// The INSERT lost to `idx_reregistration_user` — and the
					// row the winner created is right there.
					$this->submissions[ $user_id ] = $this->submission( 888, 'pending' );
					return false;
				}
				// A real seed makes the row findable on the re-read that follows.
				$this->submissions[ $user_id ] = $this->submission( 777, 'pending' );
				$wpdb->insert_id               = 777;
				return 1;
		}
		throw new \LogicException( 'Unexpected insert into ' . $table );
	}

	/**
	 * Data of the writes to one table, in order.
	 *
	 * @param string $table Unprefixed table name.
	 * @return list<array<string, mixed>>
	 */
	private function updates_to( string $table ): array {
		$out = array();
		foreach ( $this->updates as $update ) {
			if ( 'wp_' . $table === $update['table'] ) {
				$out[] = $update['data'];
			}
		}
		return $out;
	}

	/** @return list<array<string, mixed>> */
	private function staging_updates(): array {
		return $this->updates_to( 'ffc_reregistration_import_staging' );
	}

	/** @return list<array<string, mixed>> The submission writes `process_submission()` made. */
	private function submission_writes(): array {
		return $this->updates_to( 'ffc_reregistration_submissions' );
	}

	/**
	 * Rows inserted into one table, in order.
	 *
	 * @param string $table Unprefixed table name.
	 * @return list<array<string, mixed>>
	 */
	private function inserts_into( string $table ): array {
		$out = array();
		foreach ( $this->inserts as $insert ) {
			if ( 'wp_' . $table === $insert['table'] ) {
				$out[] = $insert['data'];
			}
		}
		return $out;
	}

	// ==================================================================
	// Fixtures
	// ==================================================================

	/**
	 * Build a field definition row the way `ffc_custom_fields` stores one.
	 *
	 * @param string $key      Field key.
	 * @param string $label    Field label.
	 * @param bool   $required Whether the field is required.
	 * @param string $type     Field type.
	 * @return object
	 */
	private function field( string $key, string $label, bool $required = false, string $type = 'text' ): object {
		return (object) array(
			'id'                => (string) crc32( $key ),
			'audience_id'       => '7',
			'field_key'         => $key,
			'field_label'       => $label,
			'field_type'        => $type,
			'field_options'     => null,
			'validation_rules'  => null,
			'field_profile_key' => null,
			'is_sensitive'      => '0',
			'is_required'       => $required ? '1' : '0',
			'is_active'         => '1',
		);
	}

	/**
	 * Store campaign 1's audiences and audience 7's fields.
	 *
	 * @param list<object> $fields       Field definitions for the audience.
	 * @param list<int>    $audience_ids Audiences linked to the campaign.
	 * @return void
	 */
	private function with_campaign( array $fields, array $audience_ids = array( 7 ) ): void {
		$this->fields             = $fields;
		$this->campaign_audiences = $audience_ids;
	}

	/**
	 * Store the accounts and seeded submissions the validate phase reads.
	 *
	 * @param array<string, int>  $people      Normalised CPF/RF or e-mail => user id.
	 * @param array<int, ?object> $submissions User id => seeded submission, or null.
	 * @return void
	 */
	private function with_validation( array $people, array $submissions = array() ): void {
		foreach ( $people as $identifier => $user_id ) {
			if ( str_contains( (string) $identifier, '@' ) ) {
				$this->people_by_email[ (string) $identifier ] = $user_id;
			} else {
				$this->people_by_id[ (string) $identifier ] = $user_id;
			}
		}
		$this->submissions = $submissions;
	}

	/** A seeded submission row. */
	private function submission( int $id, string $status ): object {
		return (object) array(
			'id'                => (string) $id,
			'reregistration_id' => '1',
			'status'            => $status,
		);
	}

	/** The staging INSERT, or null when none ran. */
	private function staging_insert(): ?array {
		foreach ( $this->queries as $q ) {
			if ( str_contains( $q['sql'], 'ffc_reregistration_import_staging' ) && str_contains( $q['sql'], 'INSERT INTO' ) ) {
				return $q;
			}
		}
		return null;
	}

	// ==================================================================
	// map_header() — the decision: unknown column ignored and reported
	// ==================================================================

	public function test_header_matches_on_field_key_and_on_label(): void {
		$this->with_campaign(
			array(
				$this->field( 'nome_completo', 'Full name' ),
				$this->field( 'cpf', 'CPF' ),
			)
		);

		$map = ReregistrationImportStagingService::map_header( array( 'nome_completo', 'CPF' ), 7 );

		$this->assertSame( array( 0 => 'nome_completo', 1 => 'cpf' ), $map['mapped'] );
		$this->assertSame( array(), $map['ignored'] );
	}

	public function test_matching_ignores_case_and_surrounding_space(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );

		$map = ReregistrationImportStagingService::map_header( array( '  Cpf  ' ), 7 );

		$this->assertSame( array( 0 => 'cpf' ), $map['mapped'] );
	}

	/**
	 * The #1214 decision, stated as a test: a column matching no field is
	 * ignored and reported — never a reason to refuse the file. An operator's
	 * spreadsheet carries notes and working columns that are none of ours.
	 */
	public function test_unknown_column_is_ignored_and_reported(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );

		$map = ReregistrationImportStagingService::map_header( array( 'CPF', 'Observações do RH' ), 7 );

		$this->assertSame( array( 0 => 'cpf' ), $map['mapped'] );
		$this->assertSame( array( 'Observações do RH' ), $map['ignored'] );
	}

	/**
	 * Two columns claiming one field: the first wins and the second is
	 * reported like any other column we cannot place. Letting the later one
	 * overwrite would make the import depend on column order, which the
	 * operator has no reason to think matters.
	 */
	public function test_duplicate_column_for_one_field_keeps_the_first(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );

		$map = ReregistrationImportStagingService::map_header( array( 'cpf', 'CPF' ), 7 );

		$this->assertSame( array( 0 => 'cpf' ), $map['mapped'] );
		$this->assertSame( array( 'CPF' ), $map['ignored'] );
	}

	public function test_required_field_without_a_column_is_reported_by_the_map(): void {
		$this->with_campaign(
			array(
				$this->field( 'cpf', 'CPF' ),
				$this->field( 'nome_completo', 'Full name', true ),
			)
		);

		$map = ReregistrationImportStagingService::map_header( array( 'cpf' ), 7 );

		$this->assertSame( array( 'nome_completo' ), $map['missing_required'] );
	}

	// ==================================================================
	// ingest_job() — refusals
	// ==================================================================

	/**
	 * The #1214 decision: a required field with no column refuses the file
	 * before anything is staged. An absent COLUMN means every row would fail,
	 * so one line beats staging the file to say it once per row — and the
	 * outcome is the same all-or-nothing the issue asked for.
	 */
	public function test_required_column_absent_refuses_before_staging(): void {
		$this->with_campaign(
			array(
				$this->field( 'cpf', 'CPF' ),
				$this->field( 'nome_completo', 'Full name', true ),
			)
		);

		$result = ReregistrationImportStagingService::ingest_job( 1, 7, "cpf\n" . Identifiers::CPF_A . "\n", 3 );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'rereg_import_required_column_absent' ), $result['errors'] );
		$this->assertSame( array( 'nome_completo' ), $result['missing_required'] );
		$this->assertNull( $this->staging_insert(), 'Nothing may be staged when a required column is absent.' );
	}

	public function test_audience_outside_the_campaign_is_refused(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ), array( 99 ) );

		$result = ReregistrationImportStagingService::ingest_job( 1, 7, "cpf\n123\n", 3 );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'rereg_import_audience_not_in_campaign' ), $result['errors'] );
	}

	public function test_a_header_matching_nothing_is_refused(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );

		$result = ReregistrationImportStagingService::ingest_job( 1, 7, "notes,more notes\na,b\n", 3 );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'rereg_import_no_column_matched' ), $result['errors'] );
	}

	public function test_a_header_with_no_body_rows_is_refused(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );

		$result = ReregistrationImportStagingService::ingest_job( 1, 7, "cpf\n", 3 );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'rereg_import_csv_has_no_rows' ), $result['errors'] );
	}

	// ==================================================================
	// ingest_job() — the staged row
	// ==================================================================

	public function test_the_row_is_staged_as_json_under_its_field_keys(): void {
		$this->with_campaign(
			array(
				$this->field( 'cpf', 'CPF' ),
				$this->field( 'nome_completo', 'Full name' ),
			)
		);

		$result = ReregistrationImportStagingService::ingest_job( 1, 7, "CPF,Full name\n" . self::masked( Identifiers::CPF_A ) . ",Ada Lovelace\n", 3 );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'job-uuid-0001', $result['job_id'] );

		$insert = $this->staging_insert();
		$this->assertNotNull( $insert );
		$payload = json_decode( (string) $insert['values'][5], true );
		$this->assertSame(
			array(
				'cpf'           => self::masked( Identifiers::CPF_A ),
				'nome_completo' => 'Ada Lovelace',
			),
			$payload,
			'The payload keeps the operator\'s value verbatim; normalising belongs to the resolution columns.'
		);
	}

	/**
	 * The resolution columns are lifted out of the payload and normalised,
	 * because validation and promotion query on them. The payload keeps what
	 * the operator typed, so "this CPF is malformed" can quote it back.
	 */
	public function test_identifiers_are_lifted_out_and_normalised(): void {
		$this->with_campaign(
			array(
				$this->field( 'cpf', 'CPF' ),
				$this->field( 'rf', 'RF' ),
				$this->field( 'email', 'E-mail' ),
			)
		);

		ReregistrationImportStagingService::ingest_job( 1, 7, "CPF,RF,E-mail\n" . self::masked( Identifiers::CPF_A ) . ',' . self::masked_rf( Identifiers::RF_A ) . ",  Ada@Example.COM \n", 3 );

		$values = $this->staging_insert()['values'];
		$this->assertSame( Identifiers::CPF_A, $values[6], 'cpf_normalized' );
		$this->assertSame( Identifiers::RF_A, $values[7], 'rf_normalized' );
		$this->assertSame( 'ada@example.com', $values[8], 'email' );
	}

	/**
	 * `line_no` is the line in the file the operator opens; `row_no` counts
	 * body rows. They differ by the header, which is exactly the off-by-one
	 * an error message gets wrong.
	 */
	public function test_row_and_line_numbers_differ_by_the_header(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );

		ReregistrationImportStagingService::ingest_job( 1, 7, "cpf\n111\n222\n", 3 );

		$values = $this->staging_insert()['values'];
		// First row: row_no 1, line_no 2. Second row: row_no 2, line_no 3.
		$this->assertSame( 1, $values[1] );
		$this->assertSame( 2, $values[2] );
		$this->assertSame( 2, $values[11] );
		$this->assertSame( 3, $values[12] );
	}

	public function test_a_missing_cell_stages_as_empty_rather_than_shifting_the_row(): void {
		$this->with_campaign(
			array(
				$this->field( 'cpf', 'CPF' ),
				$this->field( 'rf', 'RF' ),
			)
		);

		// The second row is short: only one cell for two columns.
		ReregistrationImportStagingService::ingest_job( 1, 7, "cpf,rf\n111,222\n333\n", 3 );

		$values = $this->staging_insert()['values'];
		$second = json_decode( (string) $values[15], true );
		$this->assertSame( array( 'cpf' => '333', 'rf' => '' ), $second );
	}

	// ==================================================================
	// validate_job() — the all-or-nothing boundary (#1214)
	// ==================================================================

	/**
	 * Run validate over a fixed set of staged rows.
	 *
	 * @param list<array<string, string>> $staged Each row's cpf/rf/email/payload.
	 * @return array<string, mixed>
	 */
	private function run_validate( array $staged ): array {
		$this->job = (object) array(
			'job_id'            => 'job-uuid-0001',
			'reregistration_id' => '1',
			'audience_id'       => '7',
			'status'            => 'ingested',
		);

		$this->staged = array();
		foreach ( $staged as $i => $row ) {
			$this->staged[] = (object) array(
				'id'             => (string) ( $i + 1 ),
				'row_no'         => (string) ( $i + 1 ),
				'line_no'        => (string) ( $i + 2 ),
				'payload'        => $row['payload'] ?? '{}',
				'cpf_normalized' => $row['cpf'] ?? '',
				'rf_normalized'  => $row['rf'] ?? '',
				'email'          => $row['email'] ?? '',
			);
		}

		return ReregistrationImportStagingService::validate_job( 'job-uuid-0001' );
	}

	/** The row_status written for the Nth staged row (0-based). */
	private function row_status( int $index ): string {
		return (string) ( $this->staging_updates()[ $index ]['row_status'] ?? '' );
	}

	public function test_a_clean_job_validates_and_is_ready_to_promote(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );
		$this->with_validation( array( Identifiers::CPF_A => 42 ), array( 42 => $this->submission( 900, 'pending' ) ) );

		$result = $this->run_validate( array( array( 'cpf' => Identifiers::CPF_A, 'payload' => (string) json_encode( array( 'cpf' => Identifiers::CPF_A ) ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test fixture.

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'validated', $result['status'] );
		$this->assertSame( 1, $result['ready'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertSame( 42, $this->staging_updates()[0]['user_id'] );
		$this->assertSame( 900, $this->staging_updates()[0]['submission_id'], 'The seeded submission is recorded so promote re-reads a decision.' );
	}

	/**
	 * The #1214 decision: one bad row blocks the whole job. Promotion is
	 * batched across requests and cannot roll back an earlier batch, so this
	 * boundary is the only place all-or-nothing can hold.
	 *
	 * `idade` is a number field and both rows carry a word in it, so the real
	 * validator refuses each.
	 */
	public function test_one_invalid_row_blocks_the_entire_job(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ), $this->field( 'idade', 'Idade', false, 'number' ) ) );
		$this->with_validation(
			array(
				'111' => 42,
				'222' => 43,
			),
			array(
				42 => null,
				43 => null,
			)
		);

		$result = $this->run_validate(
			array(
				array( 'cpf' => '111', 'payload' => '{"cpf":"111","idade":"x"}' ),
				array( 'cpf' => '222', 'payload' => '{"cpf":"222","idade":"y"}' ),
			)
		);

		$this->assertFalse( $result['ok'], 'A job with any failure must not be promotable.' );
		$this->assertSame( 'blocked', $result['status'] );
		$this->assertSame( 2, $result['failed'] );
		$this->assertSame( 'field_invalid_number:idade', $result['failures'][0]['error'] );
		$this->assertSame( 2, $result['failures'][0]['line'], 'The operator reads the line in the file, not the row ordinal.' );
	}

	/**
	 * The other half of the decision: "already submitted" is expected state,
	 * not a defect in the spreadsheet, so it skips its row and does NOT block.
	 * The person got there first and their own answers are theirs to keep.
	 */
	public function test_an_already_submitted_row_skips_without_blocking(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );
		$this->with_validation(
			array(
				'111' => 42,
				'222' => 43,
			),
			array(
				42 => $this->submission( 900, 'approved' ),
				43 => $this->submission( 901, 'pending' ),
			)
		);

		$result = $this->run_validate(
			array(
				array( 'cpf' => '111', 'payload' => '{"cpf":"111"}' ),
				array( 'cpf' => '222', 'payload' => '{"cpf":"222"}' ),
			)
		);

		$this->assertTrue( $result['ok'], 'A skip is not a failure.' );
		$this->assertSame( 'validated', $result['status'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 1, $result['ready'] );
		$this->assertSame( 'skipped', $this->row_status( 0 ) );
		$this->assertSame( 'ready', $this->row_status( 1 ) );
	}

	public function test_a_draft_submission_is_still_fillable(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );
		// A draft in progress is `in_progress`: the status `draft` the check
		// used to name does not exist, so a member mid-draft read as already
		// submitted and was skipped (#2242).
		$this->with_validation( array( '111' => 42 ), array( 42 => $this->submission( 900, 'in_progress' ) ) );

		$result = $this->run_validate( array( array( 'cpf' => '111', 'payload' => '{"cpf":"111"}' ) ) );

		$this->assertSame( 1, $result['ready'], 'A draft is the user having started, not having submitted.' );
	}

	public function test_a_rejected_submission_is_not_overwritten(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );
		$this->with_validation( array( '111' => 42 ), array( 42 => $this->submission( 900, 'rejected' ) ) );

		$result = $this->run_validate( array( array( 'cpf' => '111', 'payload' => '{"cpf":"111"}' ) ) );

		$this->assertSame( 1, $result['skipped'], 'A reviewed answer is not the import\'s to replace.' );
	}

	/**
	 * Two rows resolving to one account is certainly wrong, and it is exactly
	 * how a shared institutional mailbox presents: e-mail matching binds every
	 * one of them to whichever account that address hits (#1295). Catching it
	 * here means the operator sees it before a single write.
	 */
	public function test_two_rows_resolving_to_one_user_block_the_job(): void {
		$this->with_campaign( array( $this->field( 'email', 'E-mail' ) ) );
		$this->with_validation( array( 'shared@unit.example' => 42 ), array( 42 => null ) );

		$result = $this->run_validate(
			array(
				array( 'email' => 'shared@unit.example', 'payload' => '{"email":"shared@unit.example"}' ),
				array( 'email' => 'shared@unit.example', 'payload' => '{"email":"shared@unit.example"}' ),
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( 'rereg_import_duplicate_identity:2', $result['failures'][0]['error'], 'The error names the line that claimed the user first.' );
		$this->assertSame( 3, $result['failures'][0]['line'] );
	}

	/**
	 * An import never rewrites a staff account (#2242): it would overwrite the
	 * name and identifiers of an administrator, and a takeover needs no more.
	 *
	 * @dataProvider staff_capability_provider
	 */
	public function test_a_row_resolving_to_a_staff_account_fails( string $capability ): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );
		$this->with_validation( array( '111' => 42 ), array( 42 => null ) );
		$this->staff = array( 42 => $capability );

		$result = $this->run_validate( array( array( 'cpf' => '111', 'payload' => '{"cpf":"111"}' ) ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( 'rereg_import_staff_account', $result['failures'][0]['error'] );
	}

	/** @return array<string, array{string}> */
	public static function staff_capability_provider(): array {
		return array(
			'a WordPress administrator'  => array( 'manage_options' ),
			'a reregistration PII tier'  => array( 'ffc_view_reregistration_pii' ),
			'another module\'s PII tier' => array( 'ffc_view_certificates_pii' ),
		);
	}

	public function test_an_account_with_other_capabilities_is_not_staff(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );
		$this->with_validation( array( '111' => 42 ), array( 42 => null ) );
		$this->staff = array( 42 => 'ffc_view_reregistration' );

		$result = $this->run_validate( array( array( 'cpf' => '111', 'payload' => '{"cpf":"111"}' ) ) );

		$this->assertSame( 1, $result['ready'], 'A Viewer or Operator holds no PII cap and is an ordinary member here.' );
	}

	/**
	 * The account became staff between validation and promotion: the row is
	 * marked failed and nothing is written to it -- no membership, no
	 * submission, no profile.
	 */
	public function test_promotion_refuses_an_account_that_became_staff(): void {
		$this->with_promotion( 42, $this->submission( 900, 'pending' ) );
		$this->staff = array( 42 => 'manage_options' );

		$result = $this->run_promote( array( array( 'cpf' => '111', 'payload' => '{"cpf":"111"}' ) ) );

		$this->assertTrue( $result['ok'], 'A refused row does not abort the batch.' );
		$this->assertSame( array(), $this->submission_writes() );
		$this->assertSame( 'failed', $this->row_update()['row_status'] );
		$this->assertSame( 'rereg_import_staff_account', $this->row_update()['error'] );
		$this->assertSame( array(), array_filter( $this->inserts, static fn( array $i ) => str_contains( $i['table'], 'audience_members' ) ), 'The account is not added to the audience.' );
	}

	/**
	 * A row that matches nobody and carries no e-mail cannot be promoted:
	 * `wp_create_user` rejects an empty address, so it fails here rather than
	 * halfway through a batch.
	 */
	public function test_a_row_with_no_identifier_and_no_email_fails(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );
		$this->with_validation( array() );

		$result = $this->run_validate( array( array( 'cpf' => '', 'email' => '', 'payload' => '{}' ) ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'rereg_import_no_identifier', $result['failures'][0]['error'] );
	}

	/**
	 * A row for somebody who does not exist yet is valid — promotion creates
	 * them. Recording `user_id` as 0 is what lets the operator see, before any
	 * write, how many accounts the import would open.
	 */
	public function test_a_row_that_would_create_a_user_is_ready_and_visible(): void {
		$this->with_campaign( array( $this->field( 'email', 'E-mail' ) ) );
		$this->with_validation( array() );

		$result = $this->run_validate( array( array( 'email' => 'new@example.com', 'payload' => '{"email":"new@example.com"}' ) ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1, $result['ready'] );
		$this->assertSame( 0, $this->staging_updates()[0]['user_id'], '0 means "promotion would create this one".' );
	}

	public function test_an_unknown_job_is_refused(): void {
		$this->with_campaign( array( $this->field( 'cpf', 'CPF' ) ) );

		$result = ReregistrationImportStagingService::validate_job( 'nope' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'rereg_import_job_not_found' ), $result['errors'] );
	}

	// ==================================================================
	// promote_batch() / commit_job() — sprint 4
	// ==================================================================

	/**
	 * Store what the promote phase reads.
	 *
	 * A row whose CPF is `111` or `222` belongs to an existing account with id
	 * $resolved_user (none when it is 0); a row with only an e-mail belongs to
	 * nobody, so promotion creates the account and `wp_create_user()` answers
	 * $resolved_user. Every write the phase makes is recorded, so a test can
	 * assert the SEQUENCE — which is what this phase is: resolve, add to the
	 * audience, seed the row, write through the processor.
	 *
	 * @param int         $resolved_user Id of the account a row resolves to.
	 * @param object|null $seeded        Existing seeded submission, or null.
	 * @param string      $seed_result   What seeding a missing row does.
	 * @return void
	 */
	private function with_promotion( int $resolved_user = 42, ?object $seeded = null, string $seed_result = 'ok' ): void {
		$this->people_by_id = $resolved_user > 0
			? array(
				'111' => $resolved_user,
				'222' => $resolved_user,
			)
			: array();
		$this->created_user_id = $resolved_user;
		$this->submissions     = array( $resolved_user => $seeded );
		$this->seed_result     = $seed_result;

		$this->with_campaign(
			array(
				$this->field( 'cpf', 'CPF' ),
				$this->field( 'rf', 'RF' ),
				$this->field( 'email', 'E-mail' ),
				$this->field( 'nome_completo', 'Full name' ),
			)
		);
	}

	/**
	 * Run one promote batch over a fixed set of staged rows.
	 *
	 * @param list<array<string, string>> $staged     Each row's status/payload/identifiers.
	 * @param string                      $job_status Job phase.
	 * @param int                         $total      Rows in the job.
	 * @return array<string, mixed>
	 */
	private function run_promote( array $staged, string $job_status = 'validated', int $total = 0 ): array {
		$this->campaign = (object) array(
			'id'           => '1',
			'auto_approve' => '0',
		);

		$this->job = (object) array(
			'job_id'            => 'job-uuid-0001',
			'reregistration_id' => '1',
			'audience_id'       => '7',
			'status'            => $job_status,
			'total'             => (string) ( 0 === $total ? count( $staged ) : $total ),
			'processed_count'   => '0',
		);

		$this->staged = array();
		foreach ( $staged as $i => $row ) {
			$this->staged[] = (object) array(
				'id'             => (string) ( $i + 1 ),
				'line_no'        => (string) ( $i + 2 ),
				'payload'        => $row['payload'] ?? '{}',
				'cpf_normalized' => $row['cpf'] ?? '',
				'rf_normalized'  => $row['rf'] ?? '',
				'email'          => $row['email'] ?? '',
				'user_id'        => $row['user_id'] ?? '0',
				'submission_id'  => $row['submission_id'] ?? '0',
				'row_status'     => $row['row_status'] ?? 'ready',
			);
		}

		return ReregistrationImportStagingService::promote_batch( 'job-uuid-0001', 10 );
	}

	/**
	 * The Nth STAGING-ROW "processed" update, skipping every other write.
	 *
	 * `promote_batch()` flips the job to `promoting` on its first non-empty
	 * batch and the processor writes the submission, so neither the first
	 * update nor every update is a row's. Indexing by position would make
	 * every assertion here depend on those writes — which are different
	 * facts, tested on their own.
	 *
	 * @param int $index 0-based row-update ordinal.
	 * @return array<string, mixed>
	 */
	private function row_update( int $index = 0 ): array {
		$rows = array_values(
			array_filter(
				$this->staging_updates(),
				static fn( array $data ): bool => array_key_exists( 'processed', $data )
			)
		);

		return $rows[ $index ] ?? array();
	}

	public function test_a_ready_row_is_written_through_the_processor(): void {
		$this->with_promotion( 42, $this->submission( 900, 'pending' ) );

		$result = $this->run_promote( array( array( 'cpf' => '111', 'payload' => '{"cpf":"111"}' ) ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1, $result['processed'] );
		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $this->submission_writes(), 'process_submission() writes the seeded row once.' );
		$targets = array_values(
			array_map(
				static fn( array $u ) => $u['where'],
				array_filter( $this->updates, static fn( array $u ) => 'wp_ffc_reregistration_submissions' === $u['table'] )
			)
		);
		$this->assertSame( array( array( 'id' => 900 ) ), $targets, 'The write lands on the seeded submission.' );
		$this->assertSame( 1, $this->row_update()['processed'] );
		$this->assertSame( 42, $this->row_update()['user_id'] );
		$this->assertSame( 900, $this->row_update()['submission_id'] );
	}

	public function test_the_write_is_limited_to_the_importable_statuses(): void {
		$this->with_promotion( 42, $this->submission( 900, 'pending' ) );

		$this->run_promote( array( array( 'cpf' => '111', 'payload' => '{"cpf":"111"}' ) ) );

		$this->assertSame( array( 'pending', 'in_progress' ), $this->written_statuses, 'The import writes only over a submission nobody sent yet (#2242).' );
	}

	/**
	 * The member submitted between validation and this batch (#2242). The
	 * write tests the status in the same statement, so it changes nothing and
	 * the row is reported skipped — the person was first, and the file is not
	 * wrong for it.
	 */
	public function test_a_submission_made_after_validation_is_not_overwritten(): void {
		$this->with_promotion( 42, $this->submission( 900, 'pending' ) );
		$this->moved_to = 'submitted';

		$result = $this->run_promote( array( array( 'cpf' => '111', 'payload' => '{"cpf":"111"}' ) ) );

		$this->assertTrue( $result['ok'], 'A skipped row does not abort the batch.' );
		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( array(), $this->submission_writes(), 'Nothing is written over the member\'s own submission.' );
		$this->assertSame( 'skipped', $this->row_update()['row_status'] );
		$this->assertSame( 'rereg_import_already_submitted:submitted', $this->row_update()['error'] );
		$this->assertSame( 1, $this->row_update()['processed'] );
	}

	/**
	 * The load-bearing rule of the whole phase (#1214): promotion writes through
	 * `process_submission()` and never touches the `data` JSON itself, because
	 * that method is where every `is_sensitive` value is encrypted.
	 *
	 * With the real processor running, the only write carrying `data` is its
	 * own — the submission row, written together with the status and the
	 * auth code it generates — and the import's own tables never carry one.
	 */
	public function test_the_import_never_writes_the_submission_json_itself(): void {
		$this->with_promotion( 42, $this->submission( 900, 'pending' ) );

		$this->run_promote( array( array( 'cpf' => '111', 'payload' => '{"cpf":"111"}' ) ) );

		foreach ( array_merge( $this->staging_updates(), $this->updates_to( 'ffc_reregistration_import_jobs' ) ) as $data ) {
			$this->assertArrayNotHasKey( 'data', $data, 'Only process_submission() may write the submission body.' );
		}
		$writes = $this->submission_writes();
		$this->assertCount( 1, $writes );
		$this->assertArrayHasKey( 'data', $writes[0] );
		$this->assertSame( 'submitted', $writes[0]['status'], 'The body arrives with the processor\'s own status change.' );
		$this->assertArrayHasKey( 'auth_code', $writes[0], 'The body arrives with the auth code the processor generates.' );
	}

	/**
	 * The gap sprint 4 measured: `process_submission()` encrypts and persists,
	 * it does NOT sanitize — the public form's sanitizer lives in
	 * `collect_form_data()`. Handing it the raw staged payload would write past
	 * a boundary the form submit never skips.
	 */
	public function test_the_payload_is_sanitized_the_way_a_form_submit_is(): void {
		$this->with_promotion( 42, $this->submission( 900, 'pending' ) );

		$this->run_promote( array( array( 'cpf' => '111', 'payload' => '{"cpf":"<b>111</b>"}' ) ) );

		$this->assertContains( '<b>111</b>', $this->sanitized, 'The raw payload reaches the sanitizer.' );
		$written = json_decode( (string) $this->submission_writes()[0]['data'], true );
		$this->assertSame(
			'111',
			$written['fields']['cpf'],
			'What reaches the processor is the sanitizer output, never the raw payload.'
		);
	}

	/**
	 * Both e-mails are suppressed on this path, and each for its own reason —
	 * the account notification because 500 rows is 500 synchronous sends, the
	 * confirmation because it tells somebody their submission was received when
	 * an operator filed it for them. The campaign's invitation still reaches
	 * them (`get_awaiting_invitation()` selects on `invited_at IS NULL`,
	 * whatever the status).
	 *
	 * The row belongs to nobody yet, so the account is created and the
	 * submission written — both places an e-mail would leave from.
	 */
	public function test_promotion_sends_no_email(): void {
		$this->with_promotion( 42, $this->submission( 900, 'pending' ) );

		$this->run_promote( array( array( 'email' => 'new@example.com', 'payload' => '{}' ) ) );

		$this->assertCount( 1, $this->created_users, 'The account is created on this path.' );
		$this->assertCount( 1, $this->submission_writes(), 'The submission is written on this path.' );
		$this->assertSame( 0, $this->user_notifications->count(), 'A created account is not mailed by the importer.' );
		$this->assertSame( 0, $this->mail->count(), 'No confirmation per imported row.' );
	}

	/**
	 * A user this import creates has no seeded row — saving the campaign seeds
	 * one per audience member (#1234), and they were not a member then.
	 */
	public function test_a_missing_seeded_row_is_created_before_the_write(): void {
		$this->with_promotion( 42, null );

		$result = $this->run_promote( array( array( 'email' => 'new@example.com', 'payload' => '{}' ) ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame(
			array(
				'reregistration_id' => 1,
				'user_id'           => 42,
				'status'            => 'pending',
			),
			$this->inserts_into( 'ffc_reregistration_submissions' )[0] ?? null,
			'Seeded on the same terms the campaign save uses.'
		);
		$this->assertSame( 777, $this->row_update()['submission_id'] );
	}

	/**
	 * The flat staged payload reaches `get_or_create_user_dual()` unwrapped,
	 * because `generate_username()` and `sync_user_metadata()` look for
	 * `nome_completo` and its siblings AT THE TOP LEVEL. Wrapping it would give
	 * every created account a random username with no visible failure.
	 *
	 * The e-mail's local part is too short to be a username, so the name is
	 * what the account is named after.
	 */
	public function test_the_payload_reaches_the_resolver_flat(): void {
		$this->with_promotion( 42, $this->submission( 900, 'pending' ) );

		$this->run_promote( array( array( 'email' => 'jo@example.com', 'payload' => '{"nome_completo":"Jo Silva"}' ) ) );

		$this->assertSame( 'josilva', $this->created_users[0][0] ?? null, 'A wrapper here costs the created account its username.' );
		$this->assertSame( 'Jo Silva', $this->user_updates[0]['display_name'] ?? null, 'A wrapper here costs the created account its display name.' );
	}

	public function test_the_audience_the_operator_chose_gets_the_member(): void {
		$this->with_promotion( 42, $this->submission( 900, 'pending' ) );

		$this->run_promote( array( array( 'cpf' => '111', 'payload' => '{}' ) ) );

		$this->assertSame(
			array(
				array(
					'audience_id' => 7,
					'user_id'     => 42,
				),
			),
			$this->inserts_into( 'ffc_audience_members' )
		);
	}

	/**
	 * A skipped row is counted so the progress bar reaches the end, and written
	 * nowhere so the person who submitted first keeps their own answers.
	 */
	public function test_a_skipped_row_advances_progress_and_writes_nothing(): void {
		$this->with_promotion( 42, $this->submission( 900, 'pending' ) );

		$result = $this->run_promote(
			array(
				array( 'cpf' => '111', 'row_status' => 'skipped', 'user_id' => '43', 'submission_id' => '901' ),
				array( 'cpf' => '222', 'row_status' => 'ready', 'payload' => '{}' ),
			)
		);

		$this->assertSame( 2, $result['processed'] );
		$this->assertCount( 1, $this->submission_writes(), 'Only the ready row is written.' );
		$this->assertSame( 43, $this->row_update()['user_id'], 'The skipped row keeps what validate resolved.' );
		$this->assertSame( 1, $this->row_update()['processed'] );
	}

	/**
	 * The flip the `row_update()` helper exists to look past: a job moves to
	 * `promoting` on its first non-empty batch, so a `commit_job()` or a second
	 * `validate_job()` arriving mid-promote sees the phase it is really in.
	 */
	public function test_the_first_batch_moves_the_job_to_promoting(): void {
		$this->with_promotion( 42, $this->submission( 900, 'pending' ) );

		$this->run_promote( array( array( 'cpf' => '111', 'payload' => '{}' ) ) );

		$statuses = array_column( $this->updates_to( 'ffc_reregistration_import_jobs' ), 'status' );
		$this->assertContains( 'promoting', $statuses );
	}

	/**
	 * The request-level half of all-or-nothing: `validate_job()` refuses to
	 * label a job with any failure as `validated`, and this refuses to promote
	 * anything that is not.
	 */
	public function test_a_blocked_job_cannot_promote(): void {
		$this->with_promotion();

		$result = $this->run_promote( array( array( 'cpf' => '111' ) ), 'blocked' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'rereg_import_job_invalid_state_for_promote' ), $result['errors'] );
		$this->assertSame( array(), $this->submission_writes() );
	}

	/**
	 * Both rows carry a CPF no account holds and no e-mail, so creating the
	 * account fails (`wp_create_user()` refuses an empty address) and the
	 * first row aborts the batch.
	 */
	public function test_a_failed_resolution_aborts_the_batch_naming_the_file_line(): void {
		$this->with_promotion( 0, $this->submission( 900, 'pending' ) );

		$result = $this->run_promote(
			array(
				array( 'cpf' => '111', 'payload' => '{}' ),
				array( 'cpf' => '222', 'payload' => '{}' ),
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'rereg_import_user_resolution_failed:2' ), $result['errors'], 'The line in the file is what makes it actionable.' );
		$this->assertSame( array(), $this->submission_writes() );
	}

	/**
	 * An over-iterating client gets the job's own counters back rather than
	 * whatever it thought it had sent.
	 */
	public function test_an_exhausted_job_answers_with_its_own_counters(): void {
		$this->with_promotion();

		$result = $this->run_promote( array(), 'promoting', 5 );

		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 5, $result['total'] );
	}

	/**
	 * Run commit over a job whose staging holds $unprocessed pending rows.
	 *
	 * @param int    $unprocessed Rows still to promote.
	 * @param string $job_status  Job phase.
	 * @return array<string, mixed>
	 */
	private function run_commit( int $unprocessed, string $job_status = 'promoting' ): array {
		$this->job = (object) array(
			'job_id'            => 'job-uuid-0001',
			'reregistration_id' => '1',
			'audience_id'       => '7',
			'status'            => $job_status,
			'total'             => '3',
			'processed_count'   => '3',
		);

		$this->staging_counts = array(
			'unprocessed' => $unprocessed,
			'ready'       => 2,
			'skipped'     => 1,
		);

		return ReregistrationImportStagingService::commit_job( 'job-uuid-0001' );
	}

	public function test_commit_drops_the_cleartext_staging_and_reports(): void {
		$result = $this->run_commit( 0 );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 2, $result['promoted'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertContains( 'wp_ffc_reregistration_import_staging', $this->deletes, 'Cleartext CPF/RF/e-mail must not survive the job.' );
		$this->assertContains( 'wp_ffc_reregistration_import_jobs', $this->deletes );
	}

	/**
	 * A hand-rolled POST must not be able to discard the half of a file that
	 * has not been written yet.
	 */
	public function test_commit_refuses_while_rows_are_unpromoted(): void {
		$result = $this->run_commit( 4 );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'rereg_import_job_not_finished' ), $result['errors'] );
		$this->assertSame( array(), $this->deletes );
	}

	/**
	 * @dataProvider provide_non_promoting_states
	 * @param string $status Job phase commit must refuse.
	 */
	public function test_commit_refuses_a_job_that_is_not_promoting( string $status ): void {
		$result = $this->run_commit( 0, $status );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'rereg_import_job_invalid_state_for_commit' ), $result['errors'] );
	}

	/** @return array<string, array{string}> */
	public function provide_non_promoting_states(): array {
		return array(
			'never promoted'       => array( 'validated' ),
			'never even validated' => array( 'ingested' ),
			'blocked'              => array( 'blocked' ),
		);
	}

	/**
	 * A crash mid-delete leaves the header at `promoting` with no staging rows.
	 * That is the only retry shape this phase has — there is no `committed`
	 * state to accept, because the cleanup IS the whole phase.
	 */
	public function test_commit_finishes_a_half_deleted_job(): void {
		$result = $this->run_commit( 0, 'promoting' );

		$this->assertTrue( $result['ok'] );
		$this->assertContains( 'wp_ffc_reregistration_import_jobs', $this->deletes );
	}

	// ------------------------------------------------------------------
	// The refusals. Each is a branch this phase can take on a real install,
	// and CLAUDE.md does not allow adding an uncovered path in a
	// coverage-aware PR — so each is exercised rather than deferred.
	// ------------------------------------------------------------------

	public function test_promote_refuses_an_unknown_job(): void {
		$this->with_promotion();

		$result = ReregistrationImportStagingService::promote_batch( 'nope', 10 );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'rereg_import_job_not_found' ), $result['errors'] );
		$this->assertSame( array(), $this->submission_writes() );
	}

	/**
	 * The campaign can be deleted between validate and promote — the job
	 * carries its id, not the row.
	 */
	public function test_promote_refuses_when_the_campaign_is_gone(): void {
		$this->with_promotion();

		$this->campaign = null;
		$this->job      = (object) array(
			'job_id'            => 'job-uuid-0001',
			'reregistration_id' => '1',
			'audience_id'       => '7',
			'status'            => 'validated',
			'total'             => '1',
			'processed_count'   => '0',
		);

		$result = ReregistrationImportStagingService::promote_batch( 'job-uuid-0001', 10 );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'rereg_import_campaign_not_found' ), $result['errors'] );
	}

	public function test_commit_refuses_an_unknown_job(): void {
		$result = ReregistrationImportStagingService::commit_job( 'nope' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'rereg_import_job_not_found' ), $result['errors'] );
	}

	/**
	 * With no row to write into, promotion of that row aborts the batch rather
	 * than calling `process_submission()` with a null — which would write the
	 * campaign's answers onto whatever id a null happened to cast to.
	 */
	public function test_a_seed_that_leaves_no_row_aborts_the_batch(): void {
		$this->with_promotion( 42, null, 'nothing_there' );

		$result = $this->run_promote( array( array( 'email' => 'new@example.com', 'payload' => '{}' ) ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'rereg_import_submission_seed_failed:2' ), $result['errors'] );
		$this->assertSame( array(), $this->submission_writes() );
	}

	/**
	 * The other half, and the reason the INSERT's return value is not the
	 * guard: `idx_reregistration_user` makes a concurrent seed — another
	 * promote, or the campaign being saved in a second tab (#1234) — fail this
	 * INSERT while leaving the row there. Aborting on `false` would refuse a
	 * row that exists.
	 */
	public function test_a_seed_that_lost_the_unique_race_still_promotes(): void {
		$this->with_promotion( 42, null, 'lost_the_race' );

		$result = $this->run_promote( array( array( 'email' => 'new@example.com', 'payload' => '{}' ) ) );

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 1, $this->submission_writes() );
		$this->assertSame( 888, $this->row_update()['submission_id'], 'The winner\'s row is the one written into.' );
	}
}
