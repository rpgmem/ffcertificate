<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\ActivityLogQuery;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Privacy\PrivacyErasers;
use FreeFormCertificate\Privacy\PrivacyExporters;
use FreeFormCertificate\Security\RateLimitRepository;
use FreeFormCertificate\Tests\Support\Identifiers;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * What an erasure reaches and how an export reads (#2343).
 *
 * The erasure keeps a certificate's answers and says so, and reaches the
 * device signals, the rate-limit records, the date-message deliveries and
 * the activity-log client data the exporter or the stores hold; the export
 * hands the subject decrypted values under labels.
 *
 * @covers \FreeFormCertificate\Privacy\PrivacyErasers
 * @covers \FreeFormCertificate\Privacy\PrivacyExporters
 * @covers \FreeFormCertificate\Security\RateLimitRepository
 * @covers \FreeFormCertificate\Core\ActivityLogQuery
 */
class PrivacyErasureScopeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $wpdb;

	/** @var mixed The `$wpdb` an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/**
	 * Every statement handed to query(), in order.
	 *
	 * @var array<int, string>
	 */
	private array $queries = array();

	/**
	 * Every delete() call as [table, where].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $deletes = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		if ( $this->had_wpdb ) {
			$this->wpdb_backup = $GLOBALS['wpdb'];
		}

		global $wpdb;
		$wpdb           = Mockery::mock( 'wpdb' );
		$wpdb->prefix   = 'wp_';
		$wpdb->posts    = 'wp_posts';
		$wpdb->usermeta = 'wp_usermeta';
		$this->wpdb     = $wpdb;

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function () {
				$args = func_get_args();
				if ( false !== strpos( (string) $args[0], 'SHOW TABLES LIKE' ) ) {
					return 'SHOW TABLES LIKE ' . $args[1];
				}
				// Bind every value so a test can read what a statement is about.
				$sql   = (string) array_shift( $args );
				$flat  = array();
				array_walk_recursive(
					$args,
					static function ( $v ) use ( &$flat ) {
						$flat[] = $v;
					}
				);
				$index = 0;
				return (string) preg_replace_callback(
					'/%[dsi]/',
					static function () use ( &$index, $flat ) {
						return (string) ( $flat[ $index++ ] ?? '' );
					},
					$sql
				);
			}
		)->byDefault();
		// Every table exists.
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static fn( $sql ) => 0 === strpos( (string) $sql, 'SHOW TABLES LIKE ' ) ? substr( (string) $sql, 17 ) : '0'
		)->byDefault();
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = (string) $sql;
				return 1;
			}
		)->byDefault();
		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) {
				$this->deletes[] = array( (string) $table, (array) $where );
				return 1;
			}
		)->byDefault();
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => addcslashes( (string) $v, '_%\\' ) )->byDefault();
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() )->byDefault();

		Functions\when( '__' )->returnArg();
		Functions\when( 'is_email' )->alias( static fn( $v ) => false !== strpos( (string) $v, '@' ) ? $v : false );
		Functions\when( 'get_option' )->alias( static fn( $name, $fallback = false ) => $fallback );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts ) => gmdate( $format, (int) $ts ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => strip_tags( (string) $v ) );
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	private function account( int $id ): void {
		$user     = new \stdClass();
		$user->ID = $id;
		Functions\when( 'get_user_by' )->justReturn( $user );
	}

	// ------------------------------------------------------------------
	// Erasure
	// ------------------------------------------------------------------

	public function test_certificate_answers_are_reported_retained_never_anonymized(): void {
		$this->account( 42 );

		$result = PrivacyErasers::erase_personal_data( 'ana@example.com' );

		$this->assertTrue( $result['items_retained'], 'The kept answers are a retained record.' );
		$joined = implode( ' ', $result['messages'] );
		$this->assertStringContainsString( 'The form answers are retained', $joined );
		$this->assertStringNotContainsString( 'certificate submissions anonymized', $joined );

		$submissions = array_values( array_filter( $this->queries, static fn( $q ) => false !== strpos( $q, 'wp_ffc_submissions' ) && 0 === strpos( ltrim( $q ), 'UPDATE' ) ) );
		$this->assertCount( 1, $submissions );
		$this->assertStringNotContainsString( 't.data', $submissions[0], 'The answers are not touched.' );
	}

	public function test_device_signals_of_the_subjects_submissions_are_deleted(): void {
		$this->account( 42 );
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array( '7', '9' ) );

		PrivacyErasers::erase_personal_data( 'ana@example.com' );

		$signals = array_values( array_filter( $this->queries, static fn( $q ) => false !== strpos( $q, 'wp_ffc_device_signals' ) ) );
		$this->assertCount( 1, $signals );
		$this->assertStringContainsString( 'DELETE FROM wp_ffc_device_signals WHERE submission_id IN (7,9)', $signals[0] );
	}

	public function test_date_message_deliveries_lose_the_person(): void {
		$this->account( 42 );

		PrivacyErasers::erase_personal_data( 'ana@example.com' );

		$this->assertContains( 'UPDATE wp_ffc_date_message_log SET user_id = NULL WHERE user_id = 42', $this->queries );
	}

	public function test_activity_lines_lose_the_client_address_and_the_encrypted_context(): void {
		$this->account( 42 );

		PrivacyErasers::erase_personal_data( 'ana@example.com' );

		$this->assertContains(
			'UPDATE wp_ffc_activity_log SET user_id = NULL, user_ip = NULL, user_ip_encrypted = NULL, context_encrypted = NULL WHERE user_id = 42',
			$this->queries
		);
	}

	public function test_rate_limit_records_keyed_on_the_address_and_the_documents_are_deleted(): void {
		$this->account( 42 );
		$cpf = (string) Encryption::encrypt( Identifiers::CPF_A );
		$this->assertNotSame( '', $cpf, 'The bootstrap keys must make Encryption usable.' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static fn( $sql ) => false !== strpos( (string) $sql, 'cpf_encrypted' ) && false !== strpos( (string) $sql, 'ffc_submissions' )
				? array(
					array(
						'cpf_encrypted' => $cpf,
						'rf_encrypted'  => null,
					),
				)
				: array()
		);

		PrivacyErasers::erase_personal_data( 'Ana@Example.com' );

		$keys = array();
		foreach ( $this->deletes as $delete ) {
			if ( in_array( $delete[0], array( 'wp_ffc_rate_limits', 'wp_ffc_rate_limit_logs' ), true ) ) {
				$keys[ $delete[1]['type'] ][] = $delete[1]['identifier'];
			}
		}
		$this->assertContains( hash( 'sha256', 'ana@example.com' ), $keys['email'], 'The address as the limiter hashes it, lowercased.' );
		$this->assertContains( hash( 'sha256', Identifiers::CPF_A ), $keys['cpf'], 'The CPF read off the submission before it was cleared.' );
	}

	public function test_a_rate_limit_erasure_with_nothing_to_hash_deletes_nothing(): void {
		$this->wpdb->shouldReceive( 'delete' )->never();

		$this->assertSame( 0, RateLimitRepository::erase_identifiers( 'cpf', array( '', '  ' ) ) );
	}

	public function test_the_activity_log_erasure_skips_a_missing_table_and_a_non_account(): void {
		$this->assertSame( 0, ActivityLogQuery::erase_user_data( 0 ) );

		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$this->assertSame( 0, ActivityLogQuery::erase_user_data( 42 ) );
		$this->assertSame( array(), $this->queries );
	}

	public function test_the_user_meta_patterns_escape_the_underscore(): void {
		$this->account( 42 );

		PrivacyErasers::erase_personal_data( 'ana@example.com' );

		$meta = array_values( array_filter( $this->queries, static fn( $q ) => false !== strpos( $q, 'wp_usermeta' ) ) );
		$this->assertCount( 1, $meta );
		$this->assertStringContainsString( "LIKE ffc\\_%", $meta[0], 'An unescaped _ matches any character.' );
	}

	// ------------------------------------------------------------------
	// Export
	// ------------------------------------------------------------------

	public function test_a_certificate_exports_its_answers_documents_and_status(): void {
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn(
			array(
				array(
					'name'  => 'nome',
					'label' => '<b>Full name</b>',
				),
			)
		);
		$answers = (string) Encryption::encrypt( (string) json_encode( array( 'nome' => 'Maria', 'turma' => '3B', 'ffc_lgpd_consent' => '1' ) ) );
		$cpf     = (string) Encryption::encrypt( Identifiers::CPF_A );

		$sql = '';
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturnUsing(
			static function ( $query ) use ( &$sql, $answers, $cpf ) {
				$sql = (string) $query;
				return array(
					array(
						'id'              => '5',
						'form_id'         => '3',
						'submission_date' => '1700000000',
						'auth_code'       => 'ABCD1234EFGH',
						'consent_given'   => '1',
						'status'          => 'trash',
						'email_encrypted' => null,
						'cpf_encrypted'   => $cpf,
						'rf_encrypted'    => null,
						'data'            => null,
						'data_encrypted'  => $answers,
						'form_title'      => 'Workshop',
					),
				);
			}
		);

		$result = PrivacyExporters::export_certificates( 'maria@example.com' );
		$values = array_column( $result['data'][0]['data'], 'value', 'name' );

		$this->assertStringNotContainsString( "!= 'trash'", $sql, 'A trashed submission is still the subject\'s data.' );
		$this->assertSame( 'Trash', $values['Status'] );
		$this->assertSame( '14/11/2023 22:13', $values['Submission Date'] );
		$this->assertSame( Identifiers::CPF_A, $values['CPF'] );
		$this->assertSame( 'Maria', $values['Full name'], 'An answer is named by its field label.' );
		$this->assertSame( '3B', $values['Turma'], 'A field no longer in the form reads as words.' );
		$this->assertArrayNotHasKey( 'Ffc lgpd consent', $values, 'Consent is its own row.' );
	}

	public function test_an_appointment_exports_its_documents_custom_data_and_status_label(): void {
		Functions\when( 'get_user_by' )->justReturn( false );
		$custom = (string) Encryption::encrypt( (string) json_encode( array( 'school_name' => 'EMEF Norte' ) ) );
		$rf     = (string) Encryption::encrypt( Identifiers::RF_A );

		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array(
					'id'                    => '8',
					'appointment_date'      => '2026-11-10',
					'start_time'            => '09:00:00',
					'end_time'              => '09:30:00',
					'status'                => 'no_show',
					'name'                  => 'Maria',
					'email_encrypted'       => null,
					'phone_encrypted'       => null,
					'cpf_encrypted'         => null,
					'rf_encrypted'          => $rf,
					'custom_data_encrypted' => $custom,
					'user_notes'            => '',
					'calendar_title'        => 'Clinic',
				),
			)
		);

		$result = PrivacyExporters::export_appointments( 'maria@example.com' );
		$values = array_column( $result['data'][0]['data'], 'value', 'name' );

		$this->assertSame( 'No Show', $values['Status'] );
		$this->assertSame( '10/11/2026', $values['Date'] );
		$this->assertSame( Identifiers::RF_A, $values['RF'] );
		$this->assertSame( 'EMEF Norte', $values['School name'] );
		$this->assertArrayNotHasKey( 'CPF', $values, 'An absent document is not listed empty.' );
	}

	public function test_user_meta_opens_the_custom_field_snapshot_under_its_labels(): void {
		$this->account( 42 );
		Functions\when( 'maybe_unserialize' )->alias( static fn( $v ) => is_string( $v ) && false !== ( $u = @unserialize( $v ) ) ? $u : $v ); // phpcs:ignore
		$cpf = (string) Encryption::encrypt( Identifiers::CPF_A );

		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( $sql ) use ( $cpf ) {
				if ( false !== strpos( (string) $sql, 'field_label' ) ) {
					return array(
						array(
							'field_key'   => 'sindicato',
							'field_label' => 'Union',
						),
					);
				}
				return array(
					array(
						'meta_key'   => 'ffc_custom_fields_data',
						'meta_value' => serialize( array( 'sindicato' => 'SINPEEM', 'cpf' => $cpf ) ), // phpcs:ignore
					),
					array(
						'meta_key'   => 'ffc_cpf_rf_hash',
						'meta_value' => 'abc',
					),
				);
			}
		);

		$result = PrivacyExporters::export_usermeta( 'maria@example.com' );
		$values = array_column( $result['data'][0]['data'], 'value', 'name' );

		$this->assertSame(
			array(
				'Union' => 'SINPEEM',
				'Cpf'   => Identifiers::CPF_A,
			),
			$values
		);
	}
}

