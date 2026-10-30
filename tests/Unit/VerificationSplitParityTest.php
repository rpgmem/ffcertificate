<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\VerificationHandler;
use FreeFormCertificate\Submissions\SubmissionHandler;
use FreeFormCertificate\Tests\Support\LegacyVerificationHandler;

/**
 * The split of `VerificationHandler` into one lookup per kind changed no
 * answer (#2241).
 *
 * Each scenario runs through the handler as it stood before the split
 * (`Tests\Support\LegacyVerificationHandler`) and through the current one,
 * over the same rows, and demands the same result AND the same database reads
 * in the same order — so a change to the routing between kinds fails here
 * even when both happen to end on the same document.
 *
 * @covers \FreeFormCertificate\Frontend\VerificationHandler
 * @covers \FreeFormCertificate\Frontend\CertificateVerificationLookup
 * @covers \FreeFormCertificate\Frontend\AppointmentVerificationLookup
 * @covers \FreeFormCertificate\Frontend\ReregistrationVerificationLookup
 * @covers \FreeFormCertificate\Frontend\VerificationResult
 */
class VerificationSplitParityTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Rows `get_row()` answers, in order; null when the queue is empty.
	 *
	 * @var list<mixed>
	 */
	private array $rows = array();

	/**
	 * The queries `get_row()` received, in order.
	 *
	 * @var list<string>
	 */
	private array $reads = array();

	/** @var Mockery\MockInterface */
	private $submission_handler;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$rl = new \ReflectionClass( \FreeFormCertificate\Security\RateLimitChecker::class );
		if ( $rl->hasProperty( 'settings_cache' ) ) {
			$prop = $rl->getProperty( 'settings_cache' );
			$prop->setAccessible( true );
			$prop->setValue( null, null );
		}

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $query, ...$args ): string {
				return $query . ' | ' . json_encode( $args );
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $query ) {
				$this->reads[] = (string) $query;
				return array() === $this->rows ? null : array_shift( $this->rows );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() );

		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( intval( $v ) ) );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'apply_filters' )->alias( static fn( ...$a ) => $a[1] ?? null );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = array() ) => is_array( $args ) ? array_merge( $defaults, $args ) : $defaults );

		$this->submission_handler = Mockery::mock( SubmissionHandler::class );
		$this->submission_handler->shouldReceive( 'decrypt_submission_data' )->andReturnUsing( static fn( $row ) => $row );
		$this->submission_handler->shouldReceive( 'ensure_magic_token' )->andReturn( 'ensured-token' );
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Run one lookup through the old and the new handler over the same rows.
	 *
	 * @param string       $method Private search method or public magic-token entry.
	 * @param string       $input  The code or the token.
	 * @param list<mixed>  $rows   What `get_row()` answers, in order.
	 * @param mixed        $token_row What `get_submission_by_token()` answers.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function both( string $method, string $input, array $rows, $token_row = null ): array {
		$this->submission_handler->shouldReceive( 'get_submission_by_token' )->andReturn( $token_row );

		$outcomes = array();
		foreach ( array( new LegacyVerificationHandler( $this->submission_handler ), new VerificationHandler( $this->submission_handler ) ) as $handler ) {
			$this->rows  = $rows;
			$this->reads = array();
			if ( 'verify_by_magic_token' === $method ) {
				$result = $handler->verify_by_magic_token( $input );
			} else {
				$ref = new \ReflectionMethod( $handler, $method );
				$ref->setAccessible( true );
				$result = $ref->invoke( $handler, $input );
			}
			$outcomes[] = array(
				'result' => json_decode( (string) json_encode( $result ), true ),
				'reads'  => $this->reads,
			);
		}
		return $outcomes;
	}

	/**
	 * A certificate row.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private static function certificate( array $over = array() ): array {
		return $over + array(
			'id'          => '12',
			'form_id'     => '3',
			'status'      => 'publish',
			'email'       => 'ana@example.test',
			'cpf_rf'      => '12345678901',
			'auth_code'   => 'ABCD1234EFGH',
			'data'        => '{"name":"Ana","course":"PHP"}',
			'magic_token' => str_repeat( 'ab', 16 ),
		);
	}

	/**
	 * An appointment row.
	 *
	 * @return array<string, mixed>
	 */
	private static function appointment(): array {
		return array(
			'id'                 => '40',
			'calendar_id'        => '0',
			'name'               => 'Bia',
			'email'              => 'bia@example.test',
			'validation_code'    => 'ABCD1234EFGH',
			'appointment_date'   => '2026-11-03',
			'start_time'         => '09:00',
			'end_time'           => '09:30',
			'status'             => 'confirmed',
			'confirmation_token' => str_repeat( 'cd', 16 ),
			'created_at'         => '2026-10-01 10:00:00',
		);
	}

	/**
	 * A reregistration submission row and its campaign.
	 *
	 * @return array{0: object, 1: object}
	 */
	private static function reregistration(): array {
		return array(
			(object) array(
				'id'                => '50',
				'reregistration_id' => '3',
				'user_id'           => '10',
				'auth_code'         => 'ABCD1234EFGH',
				'status'            => 'submitted',
				'data'              => '{"fields":{"display_name":"Caio"}}',
				'magic_token'       => str_repeat( 'ef', 16 ),
				'submitted_at'      => '2026-10-02 11:00:00',
			),
			(object) array(
				'id'         => '3',
				'title'      => 'Campaign 2026',
				'start_date' => '2026-10-01',
				'end_date'   => '2026-10-31',
			),
		);
	}

	/**
	 * Code lookups: every prefix against every kind found first, found
	 * later, revoked, an unpassed quiz attempt, and nothing.
	 *
	 * @return array<string, array{0: string, 1: list<mixed>}>
	 */
	public function codes(): array {
		$rereg = self::reregistration();
		$quiz  = self::certificate( array( 'quiz_status' => 'failed' ) );
		return array(
			'empty code'                        => array( '', array() ),
			'certificate, no prefix'            => array( 'ABCD-1234-EFGH', array( self::certificate() ) ),
			'certificate, C prefix'             => array( 'C-ABCD-1234-EFGH', array( self::certificate() ) ),
			'certificate, slashed JSON'         => array( 'ABCD1234EFGH', array( self::certificate( array( 'data' => '{\"name\":\"Ana\"}' ) ) ) ),
			'revoked certificate'               => array( 'ABCD1234EFGH', array( self::certificate( array( 'status' => 'trash', 'revoked_at' => '1790000000' ) ) ) ),
			'unpassed quiz, then appointment'   => array( 'ABCD1234EFGH', array( $quiz, self::appointment() ) ),
			'appointment after no certificate'  => array( 'ABCD1234EFGH', array( null, self::appointment() ) ),
			'reregistration after both missing' => array( 'ABCD1234EFGH', array( null, null, $rereg[0], $rereg[1] ) ),
			'nothing, no prefix'                => array( 'ABCD1234EFGH', array() ),
			'appointment, A prefix'             => array( 'A-ABCD-1234-EFGH', array( self::appointment() ) ),
			'A prefix, then certificate'        => array( 'A-ABCD-1234-EFGH', array( null, self::certificate() ) ),
			'A prefix, then reregistration'     => array( 'A-ABCD-1234-EFGH', array( null, null, $rereg[0], $rereg[1] ) ),
			'A prefix, nothing'                 => array( 'A-ABCD-1234-EFGH', array() ),
			'reregistration, R prefix'          => array( 'R-ABCD-1234-EFGH', array( $rereg[0], $rereg[1] ) ),
			'R prefix, then certificate'        => array( 'R-ABCD-1234-EFGH', array( null, self::certificate() ) ),
			'R prefix, then appointment'        => array( 'R-ABCD-1234-EFGH', array( null, null, self::appointment() ) ),
			'R prefix, revoked certificate'     => array( 'R-ABCD-1234-EFGH', array( null, self::certificate( array( 'status' => 'trash' ) ) ) ),
			'R prefix, nothing'                 => array( 'R-ABCD-1234-EFGH', array() ),
		);
	}

	/**
	 * @dataProvider codes
	 *
	 * @param string      $code Typed code.
	 * @param list<mixed> $rows Rows in read order.
	 */
	public function test_a_code_finds_the_same_document_through_the_same_reads( string $code, array $rows ): void {
		list( $legacy, $current ) = $this->both( 'search_certificate', $code, $rows );

		$this->assertSame( $legacy['result'], $current['result'] );
		$this->assertSame( $legacy['reads'], $current['reads'] );
	}

	/**
	 * Magic-token lookups: a certificate (with and without a stored token),
	 * revoked, an unpassed quiz attempt, an appointment, a reregistration,
	 * nothing, and a malformed token.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: list<mixed>}>
	 */
	public function tokens(): array {
		$token = str_repeat( 'ab', 16 );
		$rereg = self::reregistration();
		return array(
			'certificate'                      => array( $token, self::certificate(), array() ),
			'certificate with no stored token' => array( $token, self::certificate( array( 'magic_token' => '' ) ), array() ),
			'revoked certificate'              => array( $token, self::certificate( array( 'status' => 'trash', 'revoked_at' => '1790000000' ) ), array() ),
			'unpassed quiz, then appointment'  => array( $token, self::certificate( array( 'quiz_status' => 'failed' ) ), array( self::appointment() ) ),
			'appointment'                      => array( $token, null, array( self::appointment() ) ),
			'reregistration'                   => array( $token, null, array( null, $rereg[0], $rereg[1] ) ),
			'nothing'                          => array( $token, null, array() ),
			'malformed token'                  => array( 'not-a-token', null, array() ),
		);
	}

	/**
	 * @dataProvider tokens
	 *
	 * @param string      $token     Magic token.
	 * @param mixed       $token_row The certificate the token names.
	 * @param list<mixed> $rows      Rows in read order.
	 */
	public function test_a_magic_link_opens_the_same_document_through_the_same_reads( string $token, $token_row, array $rows ): void {
		list( $legacy, $current ) = $this->both( 'verify_by_magic_token', $token, $rows, $token_row );

		$this->assertSame( $legacy['result'], $current['result'] );
		$this->assertSame( $legacy['reads'], $current['reads'] );
	}

	public function test_the_scenarios_reach_every_kind(): void {
		// A parity that never left the not-found branch would prove nothing.
		$kinds = array();
		foreach ( $this->codes() as list( $code, $rows ) ) {
			list( , $current ) = $this->both( 'search_certificate', $code, $rows );
			$kinds[]           = $current['result']['type'] ?? ( ! empty( $current['result']['revoked'] ) ? 'revoked' : ( $current['result']['found'] ? 'certificate' : 'none' ) );
		}
		foreach ( array( 'certificate', 'appointment', 'reregistration', 'revoked', 'none' ) as $kind ) {
			$this->assertContains( $kind, $kinds );
		}
	}
}
