<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\SigningKeys;
use FreeFormCertificate\Migrations\Strategies\CertificateSigningBackfillMigrationStrategy;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use PHPUnit\Framework\TestCase;

/**
 * Signing the certificates issued before their form chose a signed QR mode
 * (#2639).
 *
 * Termination first, as for every cursor card: a certificate that cannot be
 * signed stays unsigned, so the model below keeps it in the population and the
 * card has to stop anyway (#1378). The model is cursor-aware for that reason:
 * one that answered the same ids whatever the cursor said would make the
 * termination assertion theatre.
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\CertificateSigningBackfillMigrationStrategy
 */
final class CertificateSigningBackfillMigrationStrategyTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array( 'ffc_settings' => ActivityLogBuffer::ENABLED_SETTINGS );
		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( string $one, string $many, int $n ): string => 1 === $n ? $one : $many );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ): bool => $thing instanceof \WP_Error );
		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();
	}

	protected function tearDown(): void {
		ActivityLogBuffer::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A card over an in-memory population: published certificates by id,
	 * the form each belongs to, and which are signed.
	 *
	 * @param array<int, int>  $certificates Submission id => form id.
	 * @param list<int>        $forms        Forms on a signed mode.
	 * @param list<int>        $unsignable   Ids whose signing fails.
	 */
	private function card( array $certificates, array $forms, array $unsignable = array() ): object {
		return new class( $certificates, $forms, $unsignable ) extends CertificateSigningBackfillMigrationStrategy {

			/** @var array<int, bool> Submission id => signed. */
			public array $signed = array();

			/** @var list<int> */
			public array $attempts = array();

			/**
			 * @param array<int, int> $certificates Submission id => form id.
			 * @param list<int>       $forms        Forms on a signed mode.
			 * @param list<int>       $unsignable   Ids whose signing fails.
			 */
			public function __construct( public array $certificates, public array $forms, public array $unsignable ) {}

			protected function signed_forms(): array {
				return $this->forms;
			}

			protected function sign( int $submission_id ): bool {
				$this->attempts[] = $submission_id;
				if ( in_array( $submission_id, $this->unsignable, true ) ) {
					return false;
				}
				$this->signed[ $submission_id ] = true;
				return true;
			}

			/** @return list<int> */
			private function matching( array $forms, bool $unsigned_only, int $after ): array {
				$out = array();
				foreach ( $this->certificates as $id => $form ) {
					if ( $id > $after && in_array( $form, $forms, true ) && ( ! $unsigned_only || empty( $this->signed[ $id ] ) ) ) {
						$out[] = $id;
					}
				}
				sort( $out );
				return $out;
			}

			protected function count_certificates( array $forms, bool $unsigned_only, int $after ): int {
				return count( $this->matching( $forms, $unsigned_only, $after ) );
			}

			protected function next_unsigned( array $forms, int $after, int $limit ): array {
				return array_slice( $this->matching( $forms, true, $after ), 0, $limit );
			}
		};
	}

	/**
	 * Run batches until one reports it examined nothing, as the card does.
	 *
	 * @return int How many batches ran, the empty one included.
	 */
	private static function run_to_end( object $card, int $batch_size ): int {
		for ( $batches = 1; $batches <= 50; $batches++ ) {
			$result = $card->execute( 'certificate_signing_backfill', array( 'batch_size' => $batch_size ) );
			if ( str_starts_with( (string) $result['message'], 'Every certificate' ) ) {
				return $batches;
			}
		}
		self::fail( 'The card never reported the walk finished.' );
	}

	public function test_it_refuses_to_run_without_a_signing_key(): void {
		$card = $this->card( array( 1 => 7 ), array( 7 ) );

		$this->assertInstanceOf( \WP_Error::class, $card->can_run( 'certificate_signing_backfill', array() ) );
		$result = $card->execute( 'certificate_signing_backfill', array() );
		$this->assertFalse( $result['success'] );
		$this->assertSame( array(), $card->attempts, 'Nothing is signed without a key.' );
	}

	public function test_it_signs_only_the_unsigned_certificates_of_forms_on_a_signed_mode(): void {
		SigningKeys::rotate();
		// Form 7 is on a signed mode, form 8 on Link only; 3 is already signed.
		$card         = $this->card( array( 1 => 7, 2 => 8, 3 => 7, 4 => 7 ), array( 7 ) );
		$card->signed = array( 3 => true );

		self::run_to_end( $card, 10 );

		$this->assertSame( array( 1, 4 ), $card->attempts );
		$status = $card->calculate_status( 'certificate_signing_backfill', array() );
		$this->assertSame( 3, $status['total'] );
		$this->assertSame( 0, $status['pending'] );
		$this->assertTrue( $status['is_complete'] );
	}

	public function test_a_certificate_that_cannot_be_signed_does_not_keep_the_card_running(): void {
		SigningKeys::rotate();
		$card = $this->card( array( 1 => 7, 2 => 7, 3 => 7 ), array( 7 ), array( 2 ) );

		$batches = self::run_to_end( $card, 1 );

		$this->assertSame( 4, $batches, 'Three certificates at one per batch, then the empty batch.' );
		$this->assertSame( array( 1, 2, 3 ), $card->attempts, 'Each examined once.' );
		$this->assertTrue( $card->calculate_status( 'certificate_signing_backfill', array() )['is_complete'] );
	}

	public function test_a_batch_reports_what_it_signed_not_what_it_selected(): void {
		SigningKeys::rotate();
		$card = $this->card( array( 1 => 7, 2 => 7, 3 => 7 ), array( 7 ), array( 2 ) );

		$result = $card->execute( 'certificate_signing_backfill', array( 'batch_size' => 3 ) );

		$this->assertSame( 2, $result['processed'] );
		$this->assertStringContainsString( '1 could not be signed', (string) $result['message'] );
	}

	public function test_a_batch_that_signed_nothing_still_asks_for_the_next_one(): void {
		SigningKeys::rotate();
		$card = $this->card( array( 1 => 7, 2 => 7, 3 => 7 ), array( 7 ), array( 1, 2 ) );

		$first  = $card->execute( 'certificate_signing_backfill', array( 'batch_size' => 2 ) );
		$second = $card->execute( 'certificate_signing_backfill', array( 'batch_size' => 2 ) );

		$this->assertSame( 0, $first['processed'] );
		$this->assertTrue( $first['has_more'], 'The runner stops on processed 0 unless told otherwise.' );
		$this->assertSame( 1, $second['processed'] );
		$this->assertFalse( $second['has_more'] );
	}

	public function test_switching_another_form_to_a_signed_mode_starts_the_walk_again(): void {
		SigningKeys::rotate();
		$card = $this->card( array( 1 => 7, 2 => 8, 3 => 7 ), array( 7 ), array( 1 ) );
		self::run_to_end( $card, 10 );
		$this->assertTrue( $card->calculate_status( 'certificate_signing_backfill', array() )['is_complete'] );

		$card->forms      = array( 7, 8 );
		$card->unsignable = array();
		$this->assertSame( 2, $card->calculate_status( 'certificate_signing_backfill', array() )['pending'], 'Form 8\'s certificate, and the one that failed.' );

		self::run_to_end( $card, 10 );
		$this->assertSame( array( 1, 3, 1, 2 ), $card->attempts );
	}

	public function test_no_form_on_a_signed_mode_reads_as_complete_without_a_query(): void {
		SigningKeys::rotate();
		$card = $this->card( array( 1 => 7 ), array() );

		$status = $card->calculate_status( 'certificate_signing_backfill', array() );

		$this->assertSame( 0, $status['total'] );
		$this->assertTrue( $status['is_complete'] );
		$this->assertSame( 0, $card->execute( 'certificate_signing_backfill', array() )['processed'] );
	}

	/**
	 * The statements themselves: placeholders only, the published rows of the
	 * listed forms past the cursor, and the unsigned ones measured against the
	 * current signed record.
	 */
	public function test_the_queries_read_published_rows_of_the_listed_forms_past_the_cursor(): void {
		global $wpdb;
		$wpdb = new class() {
			public string $prefix = 'wp_';

			/** @var array<int, array{sql: string, args: array<int, mixed>}> */
			public array $log = array();

			public function prepare( string $sql, ...$args ): string {
				$this->log[] = array(
					'sql'  => $sql,
					'args' => $args,
				);
				return $sql;
			}

			public function get_var( string $sql ): string {
				return '4';
			}

			/** @return list<string> */
			public function get_col( string $sql ): array {
				return array( '11', '12' );
			}
		};
		$this->options['ffc_certificate_signing_backfill_state'] = array(
			'forms'  => '7,9',
			'cursor' => 10,
		);
		$card = new class() extends CertificateSigningBackfillMigrationStrategy {
			protected function signed_forms(): array {
				return array( 7, 9 );
			}

			/** @var list<int> */
			public array $attempts = array();

			protected function sign( int $submission_id ): bool {
				$this->attempts[] = $submission_id;
				return true;
			}
		};
		SigningKeys::rotate();

		$status = $card->calculate_status( 'certificate_signing_backfill', array() );
		$result = $card->execute( 'certificate_signing_backfill', array( 'batch_size' => 5 ) );

		$this->assertSame( 4, $status['total'] );
		$this->assertSame( array( 11, 12 ), $card->attempts );
		$this->assertSame( 2, $result['processed'] );

		list( $total, $pending, $next ) = $wpdb->log;
		$this->assertStringNotContainsString( 'NOT EXISTS', $total['sql'], 'The total counts signed certificates too.' );
		$this->assertSame( array( 'wp_ffc_submissions', 'publish', 0, 7, 9 ), $total['args'] );
		$this->assertStringContainsString( 'superseded_at IS NULL', $pending['sql'] );
		$this->assertSame( array( 'wp_ffc_submissions', 'publish', 10, 7, 9, 'wp_ffc_certificate_signatures' ), $pending['args'] );
		$this->assertStringEndsWith( 'ORDER BY s.id ASC LIMIT %d', $next['sql'] );
		$this->assertSame( 5, end( $next['args'] ) );
		$this->assertStringContainsString( 's.form_id IN (%d,%d)', $next['sql'], 'One placeholder per form.' );
		$this->assertSame( 12, $this->options['ffc_certificate_signing_backfill_state']['cursor'] );
	}
}
