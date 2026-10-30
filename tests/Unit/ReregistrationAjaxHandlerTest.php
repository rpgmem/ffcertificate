<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Reregistration\ReregistrationAjaxHandler;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for the reregistration admin AJAX endpoints: record generation,
 * submission-details modal, the affected-member count and the invitation
 * send.
 *
 * The readers, the repository, RecordGenerator, the details renderer, the
 * email handler and ActivityLog are the real classes, over a `$wpdb` double
 * that answers from the in-memory rows below and fails the test on any
 * query it does not recognise. Invitations go out through the plugin's email
 * path to a stubbed `wp_mail()`; audit rows are read from ActivityLog's write
 * buffer, with the activity log switched on in `ffc_settings`.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationAjaxHandler
 */
class ReregistrationAjaxHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * A CPF in its `ddd.ddd.ddd-dd` display form, built from the standard fixture.
	 *
	 * @param string $cpf Eleven digits.
	 * @return string
	 */
	private static function mask_cpf( string $cpf ): string {
		return substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9, 2 );
	}

	/** @var array<int, array{type: string, data: mixed}> */
	private array $json = array();

	/** @var array<int, int|null> HTTP status passed to each wp_send_json_error(). */
	private array $statuses = array();

	/** @var array<int, object> Reregistration submissions by id. */
	private array $submissions = array();

	/** @var array<int, object> Reregistration campaigns by id. */
	private array $campaigns = array();

	/** @var array<int, list<int>> Audience ids linked to each campaign. */
	private array $campaign_audiences = array();

	/** @var array<int, object> Audiences by id. */
	private array $audiences = array();

	/** @var array<int, list<int>> Member user ids per audience. */
	private array $members = array();

	/** @var array<int, list<object>> Custom fields per audience. */
	private array $fields = array();

	/** @var list<string> Every read query, as prepared. */
	private array $reads = array();

	/** @var list<list<int>> The audience id lists the member reads asked for. */
	private array $member_reads = array();

	/** @var list<string> Recipients `wp_mail()` was called with. */
	private array $mailed = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> `$_POST` before this test. */
	private array $post_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$this->post_backup = $_POST ?? array();

		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();

		Functions\when( '__' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $v ) => abs( (int) $v ) );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		// The details endpoint resolves the PII tier (#1815). By default the
		// caller holds no PII cap and no role, which is the masked tier.
		Functions\when( 'get_current_user_id' )->justReturn( 9 );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_parse_args' )->alias( static fn ( $args, $defaults = array() ) => array_merge( $defaults, (array) $args ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		// The activity log is on, so an audit row is observable; emails are
		// on and debug is off, as on an install nobody configured.
		Functions\when( 'get_option' )->alias(
			static fn ( $key, $default = false ) => 'ffc_settings' === $key ? ActivityLogBuffer::ENABLED_SETTINGS : $default
		);

		$this->json     = array();
		$this->statuses = array();
		$ref =& $this->json;
		$codes =& $this->statuses;
		Functions\when( 'wp_send_json_success' )->alias( static function ( $data = null ) use ( &$ref ) {
			$ref[] = array( 'type' => 'success', 'data' => $data );
			throw new \RuntimeException( 'wp_send_json_success' );
		} );
		Functions\when( 'wp_send_json_error' )->alias( static function ( $data = null, $status = null ) use ( &$ref, &$codes ) {
			$ref[]   = array( 'type' => 'error', 'data' => $data );
			$codes[] = $status;
			throw new \RuntimeException( 'wp_send_json_error' );
		} );

		$this->mailed = array();
		$mailed       = &$this->mailed;
		Functions\when( 'wp_mail' )->alias( static function ( $to ) use ( &$mailed ) {
			$mailed[] = $to;
			return true;
		} );

		$GLOBALS['wpdb'] = $this->database();
	}

	protected function tearDown(): void {
		ActivityLogBuffer::reset();
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$_POST = $this->post_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A `$wpdb` double over the in-memory rows of this test.
	 *
	 * @return Mockery\MockInterface
	 */
	private function database() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		// Interpolates, so the reads below can dispatch on the query's intent.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				foreach ( $values as $v ) {
					$sql = (string) preg_replace( '/%[ids]/', (string) $v, (string) $sql, 1 );
				}
				return $sql;
			}
		);

		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				$this->reads[] = $sql;
				if ( preg_match( '/FROM (wp_ffc_reregistration_submissions|wp_ffc_reregistrations|wp_ffc_audiences) WHERE id = (\d+)$/', $sql, $m ) ) {
					$rows = array(
						'wp_ffc_reregistration_submissions' => $this->submissions,
						'wp_ffc_reregistrations'            => $this->campaigns,
						'wp_ffc_audiences'                  => $this->audiences,
					)[ $m[1] ];
					return $rows[ (int) $m[2] ] ?? null;
				}
				$this->fail( 'Unexpected get_row: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $sql ) {
				$this->reads[] = $sql;
				if ( preg_match( '/FROM wp_ffc_reregistration_audiences WHERE reregistration_id = (\d+)$/', $sql, $m ) ) {
					return array_map( 'strval', $this->campaign_audiences[ (int) $m[1] ] ?? array() );
				}
				if ( preg_match( '/FROM wp_ffc_audience_members WHERE audience_id IN \(([\d,]+)\)$/', $sql, $m ) ) {
					$ids                  = array_map( 'intval', explode( ',', $m[1] ) );
					$this->member_reads[] = $ids;
					$users                = array();
					foreach ( $ids as $id ) {
						$users = array_merge( $users, $this->members[ $id ] ?? array() );
					}
					return array_map( 'strval', array_values( array_unique( $users ) ) );
				}
				$this->fail( 'Unexpected get_col: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$this->reads[] = $sql;
				if ( preg_match( '/FROM wp_ffc_audiences WHERE parent_id = (\d+) /', $sql, $m ) ) {
					return array_values(
						array_filter( $this->audiences, static fn ( $a ) => (int) $a->parent_id === (int) $m[1] )
					);
				}
				if ( preg_match( '/FROM wp_ffc_custom_fields WHERE audience_id = (\d+) (?:AND is_active = 1 )?/', $sql, $m ) ) {
					return $this->fields[ (int) $m[1] ] ?? array();
				}
				if ( preg_match( '/FROM wp_ffc_reregistration_submissions WHERE reregistration_id = (\d+) AND \( invited_at IS NULL \) ORDER BY id ASC$/', $sql, $m ) ) {
					$campaign = (int) $m[1];
					return array_values(
						array_filter(
							$this->submissions,
							static fn ( $s ) => (int) $s->reregistration_id === $campaign && null === $s->invited_at
						)
					);
				}
				$this->fail( 'Unexpected get_results: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) {
				if ( preg_match( '/^UPDATE wp_ffc_reregistration_submissions SET invited_at = (\d+) WHERE id IN \(([\d,]+)\)$/', $sql, $m ) ) {
					$ids = array_map( 'intval', explode( ',', $m[2] ) );
					foreach ( $ids as $id ) {
						$this->submissions[ $id ]->invited_at = (int) $m[1];
					}
					return count( $ids );
				}
				$this->fail( 'Unexpected query: ' . $sql );
			}
		);

		return $wpdb;
	}

	/** Invoke an ajax method, swallowing the halt exception thrown by the JSON stubs. */
	private function invoke( ReregistrationAjaxHandler $h, string $method ): void {
		try {
			$h->$method();
		} catch ( \RuntimeException $e ) {
			// Expected — wp_send_json_* halts execution.
		}
	}

	/** @return array{type: string, data: mixed} */
	private function last(): array {
		$last = end( $this->json );
		$this->assertIsArray( $last );
		return $last;
	}

	/** @return array<string, mixed> */
	private function lastData(): array {
		$data = $this->last()['data'];
		$this->assertIsArray( $data );
		return $data;
	}

	public function test_constructor_creates_instance(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->assertInstanceOf( ReregistrationAjaxHandler::class, new ReregistrationAjaxHandler() );
	}

	// --- generate_record guards -------------------------------------------

	public function test_generate_record_denies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_generate_record' );
		$this->assertSame( 'error', $this->last()['type'] );
		$this->assertSame( 'Permission denied.', $this->lastData()['message'] );
		$this->assertSame( array( 403 ), $this->statuses );
	}

	public function test_generate_record_denies_a_viewer_without_the_personal_data_capability(): void {
		// The Record prints the whole submission unmasked, so it follows the
		// personal-data policy, not the screen's surface cap (#2242).
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'user_can' )->justReturn( false );
		$_POST = array( 'submission_id' => '15' );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_generate_record' );

		$this->assertSame( 'Permission denied.', $this->lastData()['message'] );
		$this->assertSame( array( 403 ), $this->statuses );
		$this->assertSame( array(), $this->tables_read(), 'Nothing is read before the gate.' );
	}

	public function test_generate_record_rejects_missing_submission_id(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'user_can' )->justReturn( true );
		$_POST = array(); // no submission_id
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_generate_record' );
		$this->assertSame( 'error', $this->last()['type'] );
		$this->assertSame( 'Invalid submission.', $this->lastData()['message'] );
	}

	// --- view_submission_details guards ----------------------------------

	public function test_view_details_denies_without_the_view_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );
		$this->assertSame( 'error', $this->last()['type'] );
		$this->assertSame( 'Permission denied.', $this->lastData()['message'] );
		$this->assertSame( array( 403 ), $this->statuses );
	}

	public function test_view_details_rejects_missing_submission_id(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array();
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );
		$this->assertSame( 'error', $this->last()['type'] );
		$this->assertSame( 'Invalid submission.', $this->lastData()['message'] );
	}

	// --- count_members ----------------------------------------------------

	public function test_count_members_denies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_count_members' );
		$this->assertSame( 'error', $this->last()['type'] );
	}

	public function test_count_members_returns_zero_for_empty_audience_set(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array( 'audience_ids' => array( 0, '', 'x' ) ); // all filtered to empty
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_count_members' );
		$this->assertSame( 'success', $this->last()['type'] );
		$this->assertSame( 0, $this->lastData()['count'] );
	}

	// --- happy paths and not-found guards --------------------------------

	public function test_count_members_counts_the_distinct_users_of_the_audiences(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST                = array( 'audience_ids' => array( '4', '0', '7' ) );
		$this->members[4]     = array( 10, 11 );
		$this->members[7]     = array( 11, 12 );
		$this->audiences[4]   = (object) array( 'id' => 4, 'parent_id' => null, 'name' => 'Four' );
		$this->audiences[7]   = (object) array( 'id' => 7, 'parent_id' => null, 'name' => 'Seven' );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_count_members' );

		$this->assertSame( array( 'type' => 'success', 'data' => array( 'count' => 3 ) ), $this->last() );
		$this->assertSame( array( array( 4 ), array( 7 ) ), $this->member_reads, 'Only the non-zero audience ids are read.' );
	}

	public function test_generate_record_reports_a_record_that_cannot_be_built(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'user_can' )->justReturn( true );
		$_POST = array( 'submission_id' => '15' );
		// Submission 15 is not stored, so the real generator answers null.

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_generate_record' );

		$this->assertSame( 'Could not generate record.', $this->lastData()['message'] );
		$this->assertSame( array( 'wp_ffc_reregistration_submissions' ), $this->tables_read() );
	}

	public function test_generate_record_returns_the_pdf_data(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'user_can' )->justReturn( true ); // A site admin: the reveal tier.
		$this->stub_record_rendering();
		$masked = self::mask_cpf( Identifiers::CPF_A );
		$cipher = Encryption::encrypt( $masked );
		$this->assertIsString( $cipher );
		$this->store_submission_15( $cipher );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_generate_record' );

		$this->assertSame( 'success', $this->last()['type'] );
		$pdf = $this->lastData()['pdf_data'];
		$this->assertSame( 'ficha', $pdf['type'] );
		$this->assertSame( 'portrait', $pdf['orientation'] );
		$this->assertSame(
			array(
				'id'    => 42,
				'name'  => 'Owner Name',
				'email' => 'owner@example.com',
			),
			$pdf['user']
		);
		$this->assertNotSame( '', $pdf['filename'] );
		$this->assertStringContainsString( 'Owner Name', $pdf['html'] );
		$this->assertStringContainsString( 'Document number', $pdf['html'], 'The campaign field is in the custom section.' );
		$this->assertStringContainsString( $masked, $pdf['html'], 'The stored value is printed decrypted.' );
		$this->assertStringNotContainsString( $cipher, $pdf['html'] );
		$this->assertSame( array( 'reregistration_record_downloaded' ), array_column( ActivityLogBuffer::entries(), 'action' ), 'Every staff download is audited (#2242).' );
	}

	public function test_view_details_reports_an_unknown_submission(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array( 'submission_id' => '15' );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );

		$this->assertSame( 'Submission not found.', $this->lastData()['message'] );
		$this->assertSame( array( 'wp_ffc_reregistration_submissions' ), $this->tables_read() );
	}

	public function test_view_details_reports_a_submission_whose_campaign_is_gone(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'user_can' )->justReturn( true ); // A site admin: the reveal tier.
		$_POST                 = array( 'submission_id' => '15' );
		$this->submissions[15] = (object) array(
			'id'                => 15,
			'reregistration_id' => '3',
			'data'              => null,
		);
		// Campaign 3 is not stored.

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );

		$this->assertSame( 'Reregistration not found.', $this->lastData()['message'] );
		$this->assertSame( array( 'wp_ffc_reregistration_submissions', 'wp_ffc_reregistrations' ), $this->tables_read() );
	}

	public function test_view_details_renders_the_decrypted_values(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'user_can' )->justReturn( true ); // A site admin: the reveal tier.
		$this->stub_details_rendering();
		$masked = self::mask_cpf( Identifiers::CPF_A );
		$cipher = Encryption::encrypt( $masked );
		$this->assertIsString( $cipher );
		$this->store_submission_15( $cipher );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );

		$this->assertSame( 'success', $this->last()['type'] );
		$html = $this->lastData()['html'];
		$this->assertStringContainsString( 'Document number', $html, 'The field the campaign declares is rendered.' );
		$this->assertStringContainsString( $masked, $html, 'The stored value is rendered decrypted.' );
		$this->assertStringNotContainsString( $cipher, $html );
		// A site admin's open is audited like any other (#1815).
		$this->assertSame( array( 'reregistration_details_viewed' ), array_column( ActivityLogBuffer::entries(), 'action' ) );
	}

	// --- view_submission_details: PII tiers (#1815) ------------------------

	/**
	 * Post submission 15 and store it: user 42's submission in campaign 3,
	 * which is linked to audience 5 and its one text field.
	 *
	 * @param string|null $cpf_cipher The stored value of the `cpf` field, or null for no data.
	 */
	private function store_submission_15( ?string $cpf_cipher ): void {
		$_POST                 = array( 'submission_id' => '15' );
		$this->submissions[15] = (object) array(
			'id'                => 15,
			'user_id'           => '42',
			'reregistration_id' => '3',
			'status'            => 'submitted',
			'data'              => null === $cpf_cipher ? null : json_encode( array( 'fields' => array( 'cpf' => $cpf_cipher ) ) ),
		);
		$this->campaigns[3]          = (object) array(
			'id'         => 3,
			'title'      => 'Census 2026',
			'start_date' => '2026-02-15',
			'end_date'   => '2026-03-15',
		);
		$this->campaign_audiences[3] = array( 5 );
		$this->audiences[5]          = (object) array( 'id' => 5, 'parent_id' => null, 'name' => 'Five' );
		$this->fields[5]             = array(
			(object) array(
				'id'          => 70,
				'field_key'   => 'cpf',
				'field_label' => 'Document number',
				'field_type'  => 'text',
				'field_group' => '',
			),
		);
	}

	/**
	 * The WordPress functions the real details renderer reaches.
	 */
	private function stub_details_rendering(): void {
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) {
			echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub of an escaping echo.
		} );
		Functions\when( 'wp_kses_post' )->returnArg();
	}

	/**
	 * The WordPress functions the real record generator reaches.
	 */
	private function stub_record_rendering(): void {
		Functions\when( 'get_userdata' )->alias(
			static fn ( $id ) => 42 === (int) $id
				? (object) array(
					'ID'           => 42,
					'display_name' => 'Owner Name',
					'user_email'   => 'owner@example.com',
				)
				: false
		);
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'get_home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'untrailingslashit' )->alias( static fn ( $v ) => rtrim( (string) $v, '/' ) );
		Functions\when( 'sanitize_file_name' )->alias( static fn ( $v ) => preg_replace( '/[^a-zA-Z0-9_\-.]/', '_', (string) $v ) );
		Functions\when( 'wp_timezone' )->alias( static fn () => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn ( $format, $ts = null ) => gmdate( (string) $format, (int) $ts ) );
	}

	/**
	 * The tables the requests read, in first-read order.
	 *
	 * @return list<string>
	 */
	private function tables_read(): array {
		$tables = array();
		foreach ( $this->reads as $sql ) {
			if ( preg_match( '/FROM (wp_\w+)/', $sql, $m ) && ! in_array( $m[1], $tables, true ) ) {
				$tables[] = $m[1];
			}
		}
		return $tables;
	}

	/**
	 * A viewer of the list without the PII cap is the masked tier: 403, and
	 * nothing is decrypted.
	 */
	public function test_view_details_refuses_the_masked_tier_with_a_403(): void {
		Functions\when( 'current_user_can' )->alias( static fn ( $cap ) => 'ffc_view_reregistration' === $cap );
		$this->stub_details_rendering();
		$this->store_submission_15( null );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );

		$this->assertSame( 'error', $this->last()['type'] );
		$this->assertSame( "You do not have permission to view this submission's personal data.", $this->lastData()['message'] );
		$this->assertSame( array( 403 ), $this->statuses );
		$this->assertSame( array( 'wp_ffc_reregistration_submissions' ), $this->tables_read(), 'Neither the campaign nor its fields are read.' );
		$this->assertSame( array(), ActivityLogBuffer::entries() );
	}

	/**
	 * The manage cap alone no longer opens the details: the PII cap does.
	 */
	public function test_view_details_refuses_a_manager_without_the_pii_cap(): void {
		Functions\when( 'current_user_can' )->alias(
			static fn ( $cap ) => in_array( $cap, array( 'ffc_view_reregistration', 'ffc_manage_reregistration' ), true )
		);
		Functions\when( 'user_can' )->alias(
			static fn ( $uid, $cap ) => in_array( $cap, array( 'ffc_view_reregistration', 'ffc_manage_reregistration' ), true )
		);
		$this->stub_details_rendering();
		$this->store_submission_15( null );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );

		$this->assertSame( array( 403 ), $this->statuses );
		$this->assertSame( array( 'wp_ffc_reregistration_submissions' ), $this->tables_read(), 'Neither the campaign nor its fields are read.' );
		$this->assertSame( array(), ActivityLogBuffer::entries() );
	}

	/**
	 * The reveal tier opens the details and writes an audit row naming the
	 * submission.
	 */
	public function test_view_details_opens_for_the_reveal_tier_and_audits(): void {
		Functions\when( 'current_user_can' )->alias( static fn ( $cap ) => 'ffc_view_reregistration' === $cap );
		Functions\when( 'user_can' )->alias( static fn ( $uid, $cap ) => 'ffc_view_reregistration_pii' === $cap );
		$this->stub_details_rendering();
		$this->store_submission_15( null );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );

		$this->assertSame( 'success', $this->last()['type'] );
		$this->assertStringContainsString( 'ffc-submission-details', $this->lastData()['html'] );
		$this->assertAuditedOpenOf15();
	}

	/**
	 * The reregistration admin role holds the PII cap and opens the modal
	 * like any other reveal-tier user, audited: since #1863 no role sees
	 * the decrypted record any other way.
	 */
	public function test_view_details_opens_for_the_module_admin_role_and_audits(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'user_can' )->alias( static fn ( $uid, $cap ) => 'ffc_view_reregistration_pii' === $cap );
		$this->stub_details_rendering();
		$this->store_submission_15( null );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );

		$this->assertSame( 'success', $this->last()['type'] );
		$this->assertAuditedOpenOf15();
	}

	/**
	 * Exactly one audit row: the open of submission 15 by user 9, naming campaign 3.
	 */
	private function assertAuditedOpenOf15(): void {
		$entries = ActivityLogBuffer::entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'reregistration_details_viewed', $entries[0]['action'] );
		$this->assertSame( 'info', $entries[0]['level'] );
		$this->assertSame( 9, $entries[0]['user_id'] );
		$this->assertSame( 15, $entries[0]['submission_id'] );
		$this->assertSame( array( 'reregistration_id' => 3 ), ActivityLogBuffer::contexts()[0]['context'] );
	}

	// --- send_invitations -------------------------------------------------

	public function test_send_invitations_denies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_send_invitations' );

		$this->assertSame( 'Permission denied.', $this->lastData()['message'] );
	}

	public function test_send_invitations_rejects_a_missing_campaign_id(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		$_POST = array( 'reregistration_id' => '0' );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_send_invitations' );

		$this->assertSame( 'Invalid campaign.', $this->lastData()['message'] );
	}

	public function test_send_invitations_reports_an_unknown_campaign(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		$_POST = array( 'reregistration_id' => '9' );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_send_invitations' );

		$this->assertSame( 'Campaign not found.', $this->lastData()['message'] );
		$this->assertSame( array( 'wp_ffc_reregistrations' ), $this->tables_read() );
	}

	public function test_send_invitations_refuses_when_invitations_are_disabled(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		$_POST                 = array( 'reregistration_id' => '9' );
		$this->campaigns[9]    = (object) array(
			'id'                       => 9,
			'email_invitation_enabled' => '0',
		);
		$this->submissions[31] = (object) array(
			'id'                => 31,
			'reregistration_id' => 9,
			'user_id'           => 21,
			'status'            => 'pending',
			'invited_at'        => null,
		);

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_send_invitations' );

		$this->assertSame( 'Invitation emails are disabled for this campaign.', $this->lastData()['message'] );
		$this->assertSame( array(), $this->mailed );
		$this->assertSame( array( 'wp_ffc_reregistrations' ), $this->tables_read(), 'Nobody awaiting an invitation is looked up.' );
	}

	public function test_send_invitations_reports_how_many_were_sent(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( '_n' )->alias( static fn ( $single, $plural, $n ) => 1 === $n ? $single : $plural );
		$this->stub_invitation_mail();
		$_POST              = array( 'reregistration_id' => '9' );
		$this->campaigns[9] = (object) array(
			'id'                       => 9,
			'title'                    => 'Census 2026',
			'email_invitation_enabled' => '1',
			'start_date'               => '2026-02-15',
			'end_date'                 => '2026-03-15',
		);
		foreach ( array( 31 => 21, 32 => 22, 33 => 23 ) as $id => $user ) {
			$this->submissions[ $id ] = (object) array(
				'id'                => $id,
				'reregistration_id' => 9,
				'user_id'           => $user,
				'status'            => 'pending',
				'invited_at'        => null,
			);
		}

		// The second press finds everyone already stamped by the first.
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_send_invitations' );
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_send_invitations' );

		$this->assertSame( array( 'sent' => 3, 'message' => '3 invitations sent.' ), $this->json[0]['data'] );
		$this->assertSame( array( 'sent' => 0, 'message' => 'Nobody was awaiting an invitation.' ), $this->json[1]['data'] );
		$this->assertSame( array( 'user21@example.com', 'user22@example.com', 'user23@example.com' ), $this->mailed );
	}

	/**
	 * The WordPress functions an invitation reaches on its way to `wp_mail()`.
	 */
	private function stub_invitation_mail(): void {
		Functions\when( 'get_userdata' )->alias(
			static fn ( $id ) => (object) array(
				'ID'           => (int) $id,
				'display_name' => 'User ' . (int) $id,
				'user_email'   => 'user' . (int) $id . '@example.com',
			)
		);
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'home_url' )->alias( static fn ( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'wp_timezone' )->alias( static fn () => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn ( $format, $ts = null ) => gmdate( (string) $format, (int) $ts ) );
	}
}
