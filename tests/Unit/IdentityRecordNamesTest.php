<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\IdentityRecordNames;

/**
 * Who a finding is about, read from the records (#1397 sprint 4).
 *
 * The isolated tier is a check-digit failure no account explains, so there is
 * often no account to ask — a recruitment candidacy carries no WordPress user
 * until it is promoted. The stores hold the name themselves.
 *
 * THE THREE OUTCOMES ARE NOT TWO.
 *
 * A name; no name recorded beside a store that was read; and no readable
 * store present at all, which is not a result. Collapsing the last
 * two makes an absence render as an answer — the #1071 rule.
 *
 * @covers \FreeFormCertificate\Maintenance\IdentityRecordNames
 */
class IdentityRecordNamesTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Names each table answers with, keyed `table => hash => names`.
	 *
	 * @var array<string, array<string, array<int, string>>>
	 */
	private array $names = array();

	/**
	 * Tables that exist on this install.
	 *
	 * @var array<int, string>
	 */
	private array $present = array();

	/**
	 * Set up Brain\Monkey and the `$wpdb` double.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Maintenance\IdentityRecordNames' );

		$this->names            = array();
		$this->submissions      = array();
		$this->submission_limit = 0;
		$this->present          = array(
			'wp_ffc_recruitment_candidate',
			'wp_ffc_self_scheduling_appointments',
			'wp_ffc_submissions',
		);

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				return array(
					'sql'  => $sql,
					'args' => $args,
				);
			}
		);

		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $prepared ) {
				$table = (string) ( $prepared['args'][0] ?? '' );

				return in_array( $table, $this->present, true ) ? $table : null;
			}
		);

		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $prepared ) {
				$table = (string) ( $prepared['args'][0] ?? '' );
				$hash  = (string) ( $prepared['args'][2] ?? '' );

				return $this->names[ $table ][ $hash ] ?? array();
			}
		);

		// Only the submissions store is read through `get_results`.
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $prepared ) {
				$this->submission_limit = (int) ( $prepared['args'][3] ?? 0 );

				return $this->submissions[ (string) ( $prepared['args'][2] ?? '' ) ] ?? array();
			}
		);

		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * Submission rows per hash, as `data_encrypted` + `data`.
	 *
	 * @var array<string, array<int, array<string, string|null>>>
	 */
	private array $submissions = array();

	/**
	 * The row limit the submissions statement carried.
	 *
	 * @var int
	 */
	private int $submission_limit = 0;

	/**
	 * A reader whose decryption is a lookup table.
	 *
	 * @param array<string, string> $plain Ciphertext => plaintext.
	 * @return IdentityRecordNames
	 */
	private function reader( array $plain = array() ): IdentityRecordNames {
		return new class( $plain ) extends IdentityRecordNames {

			/** @var array<string, string> */
			private array $plain;

			/**
			 * @param array<string, string> $plain Ciphertext => plaintext.
			 */
			public function __construct( array $plain ) {
				$this->plain = $plain;
			}

			/**
			 * @param string $cipher Ciphertext.
			 * @return string|null
			 */
			protected function decrypt( string $cipher ): ?string {
				return $this->plain[ $cipher ] ?? null;
			}
		};
	}

	/**
	 * Tear down Brain\Monkey.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Teach a store which names sit beside a hash.
	 *
	 * @param string            $table Unprefixed table.
	 * @param string            $hash  The hash.
	 * @param array<int, string> $names The names.
	 * @return void
	 */
	private function given( string $table, string $hash, array $names ): void {
		$this->names[ 'wp_' . $table ][ $hash ] = $names;
	}

	/**
	 * The ordinary case: a candidacy with no account carries the name.
	 */
	public function test_it_reads_the_name_a_candidacy_carries(): void {
		$this->given( 'ffc_recruitment_candidate', 'h', array( 'Clarice Fontes Miranda' ) );

		$out = ( new IdentityRecordNames() )->for_hash( 'h' );

		$this->assertSame( array( 'Clarice Fontes Miranda' ), $out['names'] );
		$this->assertTrue( $out['readable'] );
		$this->assertFalse( $out['capped'] );
	}

	/**
	 * One person spelled two ways across two stores is one finding with two
	 * names, and both are shown: which spelling is right is the operator's
	 * call, and picking one would hide the question.
	 */
	public function test_it_reports_every_distinct_spelling_once(): void {
		$this->given( 'ffc_recruitment_candidate', 'h', array( 'Clarice F. Miranda', 'Clarice F. Miranda' ) );
		$this->given( 'ffc_self_scheduling_appointments', 'h', array( 'Clarice Fontes Miranda' ) );

		$out = ( new IdentityRecordNames() )->for_hash( 'h' );

		$this->assertSame(
			array( 'Clarice F. Miranda', 'Clarice Fontes Miranda' ),
			$out['names']
		);
	}

	/**
	 * A finding whose rows carry no name is reported as "none recorded" over
	 * a store that WAS read.
	 */
	public function test_a_finding_with_no_recorded_name_is_readable_and_empty(): void {
		$out = ( new IdentityRecordNames() )->for_hash( 'h' );

		$this->assertSame( array(), $out['names'] );
		$this->assertTrue( $out['readable'], 'A store was present, so the absence is a result.' );
	}

	/**
	 * NOTHING SCANNED IS NOT A CLEAN RESULT.
	 *
	 * On an install carrying neither store the answer is "not looked up",
	 * never "nobody is named" — the #1071 rule, which binds any surface that
	 * reports an absence and not only CI.
	 */
	public function test_an_install_with_neither_store_says_it_looked_at_nothing(): void {
		$this->present = array();

		$out = ( new IdentityRecordNames() )->for_hash( 'h' );

		$this->assertSame( array(), $out['names'] );
		$this->assertFalse( $out['readable'] );
	}

	/**
	 * More names than the cap are reported as capped rather than trimmed in
	 * silence — the same rule the queue's own per-check cap follows.
	 */
	public function test_more_names_than_the_cap_says_so(): void {
		$this->given(
			'ffc_recruitment_candidate',
			'h',
			array( 'A', 'B', 'C', 'D', 'E' )
		);

		$out = ( new IdentityRecordNames() )->for_hash( 'h' );

		$this->assertCount( IdentityRecordNames::LIMIT, $out['names'] );
		$this->assertTrue( $out['capped'] );
	}

	/**
	 * An identifier this does not read answers with nothing and reads no
	 * store, rather than composing a statement against a column that may not
	 * exist.
	 */
	public function test_an_unknown_identifier_reads_nothing(): void {
		$this->given( 'ffc_recruitment_candidate', 'h', array( 'Clarice' ) );

		$out = ( new IdentityRecordNames() )->for_hash( 'h', 'email' );

		$this->assertSame( array(), $out['names'] );
		$this->assertFalse( $out['readable'] );
	}

	/**
	 * THE SHARED-MAILBOX CASE (#1684): one login submitted for several
	 * people, so every row is a submission and the name is in the answers.
	 * The encrypted copy is read, and each number gets its own person.
	 */
	public function test_it_reads_the_name_inside_a_submission_s_encrypted_answers(): void {
		$this->submissions['h'] = array(
			array(
				'data_encrypted' => 'c1',
				'data'           => null,
			),
		);

		$out = $this->reader( array( 'c1' => '{"nome_completo":"Eronides Neves da Silva Braga","email":"x@example.org"}' ) )->for_hash( 'h', 'cpf' );

		$this->assertSame( array( 'Eronides Neves da Silva Braga' ), $out['names'] );
		$this->assertTrue( $out['readable'] );
		$this->assertSame( IdentityRecordNames::SUBMISSION_ROWS, $this->submission_limit, 'Decryption is bounded per hash.' );
	}

	/**
	 * The ciphertext is the current copy, so it wins over the plaintext one;
	 * a row whose ciphertext does not decrypt falls back to the plaintext.
	 */
	public function test_the_encrypted_answers_win_and_the_plaintext_is_the_fallback(): void {
		$this->submissions['h'] = array(
			array(
				'data_encrypted' => 'c1',
				'data'           => '{"nome":"Old Spelling"}',
			),
			array(
				'data_encrypted' => 'unreadable',
				'data'           => '{"name":"Legacy Row"}',
			),
		);

		$out = $this->reader( array( 'c1' => '{"nome":"Current Spelling"}' ) )->for_hash( 'h', 'cpf' );

		$this->assertSame( array( 'Current Spelling', 'Legacy Row' ), $out['names'] );
	}

	/**
	 * A submission whose answers carry no name key adds nothing, and the
	 * names from the stores with a column still come through first.
	 */
	public function test_a_nameless_submission_adds_nothing(): void {
		$this->given( 'ffc_recruitment_candidate', 'h', array( 'Clarice Fontes Miranda' ) );
		$this->submissions['h'] = array(
			array(
				'data_encrypted' => null,
				'data'           => '{"email":"x@example.org"}',
			),
			array(
				'data_encrypted' => null,
				'data'           => 'not json',
			),
		);

		$out = $this->reader()->for_hash( 'h' );

		$this->assertSame( array( 'Clarice Fontes Miranda' ), $out['names'] );
	}

	/**
	 * An install holding only submissions still reads them: the store was
	 * present, so the answer is a result.
	 */
	public function test_submissions_alone_are_a_readable_store(): void {
		$this->present = array( 'wp_ffc_submissions' );

		$out = $this->reader()->for_hash( 'h' );

		$this->assertTrue( $out['readable'] );
	}
}
