<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\CsvDownloadValidator;
use FreeFormCertificate\Frontend\PublicCsvDownload;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for CsvDownloadValidator: the form-access gate (steps 5–9), the
 * hash-only subset, the per-form CPF gate (none/audit/whitelist/owner/
 * participants/unknown) and the audit-log writer.
 *
 * Everything below the validator is real: DataSanitizer, DocumentFormatter,
 * Encryption (configured by the bootstrap's key constants),
 * SensitiveFieldRegistry, Geofence, SettingsReader, ClientIpResolver,
 * CsvDownloadAuditLog, and the UserProfileRepository / SubmissionRepository
 * the owner and participants modes read through, over a `$wpdb` double that
 * answers the profile row and the submission count and fails the test on any
 * other query. Post meta lives in an in-memory store behind the global
 * `get_post_meta()` / `update_post_meta()` stubs.
 *
 * @covers \FreeFormCertificate\Frontend\CsvDownloadValidator
 */
class CsvDownloadValidatorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var CsvDownloadValidator */
	private $validator;

	/** @var array<string, mixed> post-meta store keyed by "post_id:key" */
	private $meta_store = array();

	/** @var array<string, int> update_post_meta call counts */
	private $meta_updates = array();

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether REMOTE_ADDR was set before this test. */
	private bool $had_remote_addr = false;

	/** @var mixed The REMOTE_ADDR an earlier test left, restored afterwards. */
	private $remote_addr_backup;

	/** @var array{0: string, 1: array<int, mixed>} SQL and bound arguments of the last prepare(). */
	private array $prepared = array( '', array() );

	/** @var array<int, array<string, mixed>> `ffc_user_profiles` rows by user_id. */
	private array $profiles = array();

	/** @var array<string, int> Submissions per "form_id:cpf_hash". */
	private array $submission_counts = array();

	/** @var list<array{0: int, 1: string}> Every submission count asked, as [form_id, cpf_hash]. */
	private array $submission_queries = array();

	/** @var list<int> Every user_id whose profile row was read. */
	private array $profile_queries = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->meta_store         = array();
		$this->meta_updates       = array();
		$this->profiles           = array();
		$this->submission_counts  = array();
		$this->submission_queries = array();
		$this->profile_queries    = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_timezone' )->alias( fn () => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
		// Also decides Debug::is_enabled() and the activity-log gate a decrypt
		// failure reaches, so neither branch depends on test order.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key, $single = false ) {
				return $this->meta_store[ (int) $post_id . ':' . $key ] ?? '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				$sk                       = (int) $post_id . ':' . $key;
				$this->meta_store[ $sk ]    = $value;
				$this->meta_updates[ $sk ]  = ( $this->meta_updates[ $sk ] ?? 0 ) + 1;
				return true;
			}
		);
		Functions\when( 'get_post_field' )->alias(
			function ( $field, $post_id ) {
				return $this->meta_store[ (int) $post_id . ':__field_' . $field ] ?? 0;
			}
		);
		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id, $key, $single = false ) {
				return $this->meta_store[ 'user_' . (int) $user_id . ':' . $key ] ?? '';
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->make_wpdb();

		$this->had_remote_addr    = array_key_exists( 'REMOTE_ADDR', $_SERVER );
		$this->remote_addr_backup = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR']   = '203.0.113.5';

		$this->validator = new CsvDownloadValidator();
	}

	protected function tearDown(): void {
		if ( $this->had_remote_addr ) {
			$_SERVER['REMOTE_ADDR'] = $this->remote_addr_backup;
		} else {
			unset( $_SERVER['REMOTE_ADDR'] );
		}
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A `$wpdb` double answering the two reads the CPF gate makes: the form
	 * author's profile row and the participants count. Anything else fails.
	 *
	 * @return \Mockery\MockInterface
	 */
	private function make_wpdb() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->prepared = array( (string) $sql, $args );
				return (string) $sql;
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function () {
				list( $sql, $args ) = $this->prepared;
				if ( 'wp_ffc_user_profiles' === $args[0] && 'SELECT * FROM %i WHERE user_id = %d' === $sql ) {
					$this->profile_queries[] = (int) $args[1];
					return $this->profiles[ (int) $args[1] ] ?? null;
				}
				$this->fail( 'Unexpected get_row: ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function () {
				list( $sql, $args ) = $this->prepared;
				if ( 'wp_ffc_submissions' === $args[0] && 'SELECT COUNT(*) FROM %i WHERE form_id = %d AND cpf_hash = %s' === $sql ) {
					$this->submission_queries[] = array( (int) $args[1], (string) $args[2] );
					return (string) ( $this->submission_counts[ (int) $args[1] . ':' . $args[2] ] ?? 0 );
				}
				$this->fail( 'Unexpected get_var: ' . $sql );
			}
		);
		foreach ( array( 'get_results', 'get_col', 'insert', 'update', 'delete', 'query' ) as $method ) {
			$wpdb->shouldNotReceive( $method );
		}

		return $wpdb;
	}

	/** A CPF punctuated as an operator would paste it, built from the fixture. */
	private static function punctuated( string $cpf ): string {
		return substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9 );
	}

	/** The lookup hash the write path stores for a CPF. */
	private static function cpf_hash( string $cpf ): string {
		return (string) \FreeFormCertificate\Core\SensitiveFieldRegistry::hash_identifier( 'cpf', $cpf );
	}

	/** Seed meta so validate_form_access passes every gate by default. */
	private function seed_access( int $form_id, array $overrides = array() ): void {
		$cfg = array_merge(
			array(
				'type'        => 'ffc_form',
				'enabled'     => '1',
				'hash'        => 'storedhash',
				'download'    => '1',
				'limit'       => 5,
				'count'       => 0,
				'date_end'    => '2000-01-01',
				'time_end'    => '00:00:00',
			),
			$overrides
		);
		Functions\when( 'get_post_type' )->justReturn( $cfg['type'] );
		$this->meta_store[ $form_id . ':' . PublicCsvDownload::META_ENABLED ]        = $cfg['enabled'];
		$this->meta_store[ $form_id . ':' . PublicCsvDownload::META_HASH ]           = $cfg['hash'];
		$this->meta_store[ $form_id . ':_ffc_csv_public_download_enabled' ]          = $cfg['download'];
		$this->meta_store[ $form_id . ':' . PublicCsvDownload::META_LIMIT ]          = $cfg['limit'];
		$this->meta_store[ $form_id . ':' . PublicCsvDownload::META_COUNT ]          = $cfg['count'];
		$this->meta_store[ $form_id . ':_ffc_geofence_config' ]                      = array(
			'date_end' => $cfg['date_end'],
			'time_end' => $cfg['time_end'],
		);
	}

	// ==================================================================
	// validate_form_access
	// ==================================================================

	public function test_form_access_success(): void {
		$this->seed_access( 42 );
		$this->assertNull( $this->validator->validate_form_access( 42, 'storedhash' ) );
	}

	public function test_form_access_rejects_wrong_post_type(): void {
		$this->seed_access( 42, array( 'type' => 'post' ) );
		$this->assertSame( 'Form not found.', $this->validator->validate_form_access( 42, 'storedhash' ) );
	}

	public function test_form_access_rejects_feature_disabled(): void {
		$this->seed_access( 42, array( 'enabled' => '0' ) );
		$this->assertStringContainsString( 'not enabled', $this->validator->validate_form_access( 42, 'storedhash' ) );
	}

	public function test_form_access_rejects_hash_mismatch(): void {
		$this->seed_access( 42 );
		$this->assertSame( 'Invalid access hash.', $this->validator->validate_form_access( 42, 'wronghash' ) );
	}

	public function test_form_access_rejects_empty_stored_hash(): void {
		$this->seed_access( 42, array( 'hash' => '' ) );
		$this->assertSame( 'Invalid access hash.', $this->validator->validate_form_access( 42, '' ) );
	}

	public function test_form_access_rejects_download_disabled(): void {
		$this->seed_access( 42, array( 'download' => '0' ) );
		$this->assertStringContainsString( 'CSV download is disabled', $this->validator->validate_form_access( 42, 'storedhash' ) );
	}

	public function test_form_access_empty_download_meta_defaults_enabled(): void {
		$this->seed_access( 42, array( 'download' => '' ) );
		$this->assertNull( $this->validator->validate_form_access( 42, 'storedhash' ) );
	}

	public function test_form_access_rejects_when_no_end_date(): void {
		$this->seed_access( 42 );
		// Remove geofence config so Geofence::get_form_end_timestamp() returns null.
		$this->meta_store[ '42:_ffc_geofence_config' ] = '';
		$this->assertStringContainsString( 'no end date', $this->validator->validate_form_access( 42, 'storedhash' ) );
	}

	public function test_form_access_rejects_when_form_still_active(): void {
		$this->seed_access( 42, array( 'date_end' => '2999-01-01', 'time_end' => '00:00:00' ) );
		$this->assertStringContainsString( 'still active', $this->validator->validate_form_access( 42, 'storedhash' ) );
	}

	public function test_form_access_rejects_when_quota_reached(): void {
		$this->seed_access( 42, array( 'limit' => 2, 'count' => 2 ) );
		$this->assertStringContainsString( 'maximum number of downloads', $this->validator->validate_form_access( 42, 'storedhash' ) );
	}

	public function test_form_access_falls_back_to_default_limit_when_zero(): void {
		// limit meta 0 → falls back; SettingsReader::get_int returns 0 (no option)
		// → effective limit 1. count 0 < 1 passes.
		$this->seed_access( 42, array( 'limit' => 0, 'count' => 0 ) );
		$this->assertNull( $this->validator->validate_form_access( 42, 'storedhash' ) );
	}

	// ==================================================================
	// validate_hash_only
	// ==================================================================

	public function test_hash_only_success(): void {
		$this->seed_access( 7 );
		$this->assertNull( $this->validator->validate_hash_only( 7, 'storedhash' ) );
	}

	public function test_hash_only_rejects_non_positive_id(): void {
		$this->assertSame( 'Form not found.', $this->validator->validate_hash_only( 0, 'x' ) );
	}

	public function test_hash_only_rejects_wrong_type(): void {
		$this->seed_access( 7, array( 'type' => 'page' ) );
		$this->assertSame( 'Form not found.', $this->validator->validate_hash_only( 7, 'storedhash' ) );
	}

	public function test_hash_only_rejects_disabled(): void {
		$this->seed_access( 7, array( 'enabled' => '0' ) );
		$this->assertStringContainsString( 'not enabled', $this->validator->validate_hash_only( 7, 'storedhash' ) );
	}

	public function test_hash_only_rejects_hash_mismatch(): void {
		$this->seed_access( 7 );
		$this->assertSame( 'Invalid access hash.', $this->validator->validate_hash_only( 7, 'nope' ) );
	}

	// ==================================================================
	// validate_cpf_requirement — none / audit / whitelist / owner
	// ==================================================================

	public function test_cpf_none_mode_returns_null(): void {
		// No mode meta → defaults to 'none'.
		$this->assertNull( $this->validator->validate_cpf_requirement( 10, '' ) );
	}

	public function test_cpf_none_mode_audits_voluntary_valid_cpf(): void {
		$this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'none';
		$this->assertNull( $this->validator->validate_cpf_requirement( 10, self::punctuated( Identifiers::CPF_A ) ) );
		// A voluntary audit row was written.
		$this->assertSame( 1, $this->meta_updates[ '10:' . PublicCsvDownload::META_DOWNLOAD_LOG ] ?? 0 );
	}

	public function test_cpf_none_mode_drops_junk_silently(): void {
		$this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'none';
		$this->assertNull( $this->validator->validate_cpf_requirement( 10, '123' ) );
		$this->assertArrayNotHasKey( '10:' . PublicCsvDownload::META_DOWNLOAD_LOG, $this->meta_updates );
	}

	public function test_cpf_audit_mode_requires_missing_cpf(): void {
		$this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'audit';
		$msg = $this->validator->validate_cpf_requirement( 10, '' );
		$this->assertStringContainsString( 'CPF is required', $msg );
	}

	public function test_cpf_audit_mode_rejects_bad_format(): void {
		$this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'audit';
		$this->assertSame( 'Invalid CPF.', $this->validator->validate_cpf_requirement( 10, Identifiers::CPF_REPEATED_DIGITS ) );
	}

	public function test_cpf_audit_mode_passes_valid_cpf(): void {
		$this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'audit';
		$this->assertNull( $this->validator->validate_cpf_requirement( 10, Identifiers::CPF_A ) );
	}

	public function test_cpf_whitelist_allows_listed_cpf(): void {
		$this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ]      = 'whitelist';
		// Person A is on the list, punctuated as an operator would paste it.
		$this->meta_store[ '10:' . PublicCsvDownload::META_CPF_WHITELIST ] = self::punctuated( Identifiers::CPF_A ) . "\n" . self::punctuated( Identifiers::CPF_B );
		$this->assertNull( $this->validator->validate_cpf_requirement( 10, Identifiers::CPF_A ) );
	}

	public function test_cpf_whitelist_blocks_unlisted_cpf(): void {
		$this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ]      = 'whitelist';
		// Person B is listed; person A is the one asking. They must differ, which
		// is the whole case -- a sweep that collapsed them into one value made
		// this pass for the wrong reason while its sibling failed.
		$this->meta_store[ '10:' . PublicCsvDownload::META_CPF_WHITELIST ] = self::punctuated( Identifiers::CPF_B );
		$msg = $this->validator->validate_cpf_requirement( 10, Identifiers::CPF_A );
		$this->assertStringContainsString( 'not authorized', $msg );
	}

	public function test_cpf_owner_blocks_when_no_author(): void {
		$this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'owner';
		$this->meta_store[ '10:__field_post_author' ]                = 0;
		$msg = $this->validator->validate_cpf_requirement( 10, Identifiers::CPF_A );
		$this->assertStringContainsString( 'no author', $msg );
		$this->assertSame( array(), $this->profile_queries, 'No author means no profile is read.' );
	}

	/**
	 * Owner mode passes when the author's STORED HASH matches the typed CPF.
	 *
	 * THE PREVIOUS VERSION OF THIS TEST IS WHY #1443 SHIPPED. It put
	 * a plaintext, formatted CPF into the meta store for `ffc_user_cpf` and
	 * asserted the gate opened. Production never holds that:
	 * `UserProfileFieldMap` flags `cpf` sensitive and
	 * `UserProfileService::write_usermeta()` encrypts before writing, so the
	 * meta is always a `v2:` envelope. The gate compared digits stripped out of
	 * base64 against an 11-digit CPF and could never match, while the test was
	 * green because the fixture supplied the very value under test -- the shape
	 * `CLAUDE.md` names as "asserting against a mock that supplies the value".
	 *
	 * So the fixture is now what the write path actually produces: the hash on
	 * `ffc_user_profiles.cpf_hash`, computed through
	 * `SensitiveFieldRegistry::hash_identifier()` -- the same boundary the gate
	 * uses, so the test asserts agreement with the registry rather than with a
	 * hash spelled out here. (Encryption IS configured in the unit bootstrap, so
	 * the `sha256` fallback is not the branch under test.) The row is read by
	 * the real UserProfileRepository, keyed on the author's id.
	 */
	public function test_cpf_owner_matches_the_authors_stored_hash(): void {
		$this->profiles[88] = array( 'user_id' => '88', 'cpf_hash' => self::cpf_hash( Identifiers::CPF_A ) );

		$this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'owner';
		$this->meta_store[ '10:__field_post_author' ]                = 88;

		$this->assertNull( $this->validator->validate_cpf_requirement( 10, Identifiers::CPF_A ) );
		$this->assertSame( array( 88 ), $this->profile_queries );
	}

	public function test_cpf_owner_blocks_on_mismatch(): void {
		$this->profiles[88] = array( 'user_id' => '88', 'cpf_hash' => self::cpf_hash( Identifiers::CPF_B ) );

		$this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'owner';
		$this->meta_store[ '10:__field_post_author' ]                = 88;

		$msg = $this->validator->validate_cpf_requirement( 10, Identifiers::CPF_A );
		$this->assertStringContainsString( 'does not match', $msg );
		$this->assertSame( array( 88 ), $this->profile_queries );
	}

	/**
	 * An author with no stored hash FAILS the gate, it does not open it.
	 *
	 * The empty string is the answer for a user who has no profile row, and an
	 * equality comparison against '' would otherwise match every other author
	 * who also has none -- the reason `read_identity_index()` refuses '' as hard
	 * as null.
	 */
	public function test_cpf_owner_blocks_when_the_author_has_no_stored_hash(): void {
		// No profile row for user 88.
		$this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'owner';
		$this->meta_store[ '10:__field_post_author' ]                = 88;

		$msg = $this->validator->validate_cpf_requirement( 10, Identifiers::CPF_A );
		$this->assertStringContainsString( 'does not match', $msg );
		$this->assertSame( array( 88 ), $this->profile_queries );
	}

	/**
	 * Two absences must not match each other.
	 *
	 * `hash_equals( '', '' )` is TRUE, so an author with no stored hash and a
	 * typed CPF whose hash cannot be computed would open the gate. Only the PAIR
	 * is dangerous -- `hash_equals` refuses an empty string against a real one
	 * on length -- and only this test reaches it.
	 *
	 * What it kills is the emptiness guard as a WHOLE. Removing either condition
	 * on its own leaves this test green, and that is not a gap in it: with the
	 * other half still standing the behaviour is identical, so each is an
	 * equivalent mutant. Measured both ways -- one condition removed: green;
	 * both removed: this test fails, naming the gate it opened.
	 *
	 * Kept in its own process because the real SensitiveFieldRegistry cannot
	 * produce a missing hash for a CPF that passed the format gate, so it is
	 * replaced by an alias mock answering null, and an alias outlives the test.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cpf_owner_blocks_when_neither_side_has_a_hash(): void {
		$this->profiles[88] = array( 'user_id' => '88', 'cpf_hash' => '' );

		$registry = Mockery::mock( 'alias:FreeFormCertificate\\Core\\SensitiveFieldRegistry' );
		$registry->shouldReceive( 'hash_identifier' )->andReturn( null );

		$this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'owner';
		$this->meta_store[ '10:__field_post_author' ]                = 88;

		$msg = $this->validator->validate_cpf_requirement( 10, Identifiers::CPF_A );
		$this->assertNotNull( $msg, 'Two absent hashes opened the owner gate: hash_equals( \'\', \'\' ) is TRUE.' );
		$this->assertStringContainsString( 'does not match', $msg );
	}

	public function test_cpf_unknown_mode_fails_closed(): void {
		$this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'bogus';
		$msg = $this->validator->validate_cpf_requirement( 10, Identifiers::CPF_A );
		$this->assertStringContainsString( 'misconfigured', $msg );
	}

	public function test_cpf_participants_mode_passes_when_submission_found(): void {
		$hash                                    = self::cpf_hash( Identifiers::CPF_A );
		$this->submission_counts[ '10:' . $hash ] = 1;

		$this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'participants';
		$this->assertNull( $this->validator->validate_cpf_requirement( 10, Identifiers::CPF_A ) );
		$this->assertSame( array( array( 10, $hash ) ), $this->submission_queries );
	}

	public function test_cpf_participants_mode_blocks_when_no_submission(): void {
		$this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'participants';
		$msg = $this->validator->validate_cpf_requirement( 10, Identifiers::CPF_A );
		$this->assertStringContainsString( 'No submission with this CPF', $msg );
		$this->assertSame( array( array( 10, self::cpf_hash( Identifiers::CPF_A ) ) ), $this->submission_queries );
	}

	public function test_cpf_silent_audit_suppresses_log_row(): void {
		$this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'audit';
		$this->validator->validate_cpf_requirement( 10, Identifiers::CPF_A, true );
		$this->assertArrayNotHasKey( '10:' . PublicCsvDownload::META_DOWNLOAD_LOG, $this->meta_updates );
	}

	// ==================================================================
	// record_download_log_entry — ring buffer + encryption
	// ==================================================================

	public function test_record_download_log_entry_appends_row(): void {
		$this->validator->record_download_log_entry( 10, 'access', '', 'fail_other' );
		$log = $this->meta_store[ '10:' . PublicCsvDownload::META_DOWNLOAD_LOG ];
		$this->assertCount( 1, $log );
		$this->assertSame( 'fail_other', $log[0]['result'] );
		$this->assertSame( 'access', $log[0]['mode'] );
	}

	public function test_record_download_log_entry_encrypts_cpf_when_digits_present(): void {
		// Encryption is configured in the unit bootstrap, so cpf_encrypted
		// should be a non-empty ciphertext.
		$this->validator->record_download_log_entry( 10, 'audit', Identifiers::CPF_A, 'audit_pass' );
		$log = $this->meta_store[ '10:' . PublicCsvDownload::META_DOWNLOAD_LOG ];
		$this->assertNotSame( '', $log[0]['cpf_encrypted'] );
	}

	public function test_record_download_log_entry_never_stores_the_ip_in_clear(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		$this->validator->record_download_log_entry( 10, 'access', '', 'success' );
		unset( $_SERVER['REMOTE_ADDR'] );

		$entry = $this->meta_store[ '10:' . PublicCsvDownload::META_DOWNLOAD_LOG ][0];
		$this->assertArrayNotHasKey( 'ip', $entry, 'The address is stored encrypted only (#1574).' );
		$this->assertStringNotContainsString( '203.0.113.77', (string) $entry['ip_encrypted'] );
		$this->assertSame(
			'203.0.113.77',
			\FreeFormCertificate\Frontend\Csv\CsvDownloadAuditLog::decrypt_log_entry_ip( $entry ),
			'The stored ciphertext decrypts back to the address that was recorded.'
		);
	}

	public function test_record_download_log_entry_encrypts_what_an_older_release_stored_in_clear(): void {
		$this->meta_store[ '10:' . PublicCsvDownload::META_DOWNLOAD_LOG ] = array(
			array( 'ts' => 1, 'ip' => '198.51.100.9', 'mode' => 'access', 'cpf_encrypted' => '', 'result' => 'success' ),
		);

		$this->validator->record_download_log_entry( 10, 'access', '', 'success' );

		$log = $this->meta_store[ '10:' . PublicCsvDownload::META_DOWNLOAD_LOG ];
		$this->assertArrayNotHasKey( 'ip', $log[0] );
		$this->assertSame( '198.51.100.9', \FreeFormCertificate\Frontend\Csv\CsvDownloadAuditLog::decrypt_log_entry_ip( $log[0] ) );
	}

	public function test_an_entry_from_an_older_release_still_reads_its_ip(): void {
		$this->assertSame(
			'198.51.100.9',
			\FreeFormCertificate\Frontend\Csv\CsvDownloadAuditLog::decrypt_log_entry_ip( array( 'ip' => '198.51.100.9' ) )
		);
		$this->assertSame( '', \FreeFormCertificate\Frontend\Csv\CsvDownloadAuditLog::decrypt_log_entry_ip( array() ) );
		$this->assertSame(
			'',
			\FreeFormCertificate\Frontend\Csv\CsvDownloadAuditLog::decrypt_log_entry_ip( array( 'ip_encrypted' => 'v2:garbage' ) ),
			'An unreadable ciphertext reads as no address, never as the blob.'
		);
	}

	public function test_record_download_log_entry_prunes_to_max(): void {
		// Pre-seed DOWNLOAD_LOG_MAX rows, then append one more.
		$rows = array_fill( 0, PublicCsvDownload::DOWNLOAD_LOG_MAX, array( 'result' => 'old' ) );
		$this->meta_store[ '10:' . PublicCsvDownload::META_DOWNLOAD_LOG ] = $rows;

		$this->validator->record_download_log_entry( 10, 'access', '', 'success' );

		$log = $this->meta_store[ '10:' . PublicCsvDownload::META_DOWNLOAD_LOG ];
		$this->assertCount( PublicCsvDownload::DOWNLOAD_LOG_MAX, $log );
		// The oldest row is dropped; the new row is last.
		$this->assertSame( 'success', $log[ PublicCsvDownload::DOWNLOAD_LOG_MAX - 1 ]['result'] );
	}
}
