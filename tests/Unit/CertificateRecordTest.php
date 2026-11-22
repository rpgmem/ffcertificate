<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\CertificateRecord;
use PHPUnit\Framework\TestCase;

/**
 * The bytes a certificate signature covers (#2639).
 *
 * The verifier on the other side rebuilds these strings, so every shape here
 * is a contract: a change to one breaks every certificate already signed.
 *
 * @covers \FreeFormCertificate\Core\CertificateRecord
 */
final class CertificateRecordTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $ts ): string => gmdate( $format, $ts ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, string>
	 */
	private function fields(): array {
		return array(
			'form_id'       => '12',
			'submission_id' => '345',
			'auth_code'     => 'A1B2C3D4E5F6',
			'issued'        => '1795392000',
			'name'          => 'Maria Aparecida da Silva Santos',
			'identifier'    => str_repeat( 'a', 64 ),
			'course'        => 'Formação em Libras – Módulo I',
			'workload'      => '40 horas',
			'event_start'   => '2026-11-10',
			'event_end'     => '2026-11-14',
		);
	}

	public function test_the_record_lists_every_field_in_order_after_the_version(): void {
		$this->assertSame(
			'v1|12|345|A1B2C3D4E5F6|1795392000|Maria Aparecida da Silva Santos|' . str_repeat( 'a', 64 ) . '|Formação em Libras – Módulo I|40 horas|2026-11-10|2026-11-14',
			CertificateRecord::record( $this->fields() )
		);
	}

	public function test_a_missing_field_is_an_empty_slot_not_a_shorter_record(): void {
		$fields = $this->fields();
		unset( $fields['workload'] );

		$this->assertCount( 11, CertificateRecord::split( CertificateRecord::record( $fields ) ) );
	}

	public function test_the_full_and_compact_payloads(): void {
		$hash = CertificateRecord::hash( CertificateRecord::record( $this->fields() ) );

		$this->assertSame( 64, strlen( $hash ) );
		$this->assertSame(
			'v1|k1|A1B2C3D4E5F6|2026-11-23|Maria Aparecida da Silva Santos|Formação em Libras – Módulo I|40|' . $hash,
			CertificateRecord::full_payload( 'k1', $this->fields(), $hash )
		);
		$this->assertSame( 'v1|k1|A1B2C3D4E5F6|2026-11-23|' . $hash, CertificateRecord::compact_payload( 'k1', $this->fields(), $hash ) );
	}

	public function test_a_decomposed_accent_signs_the_same_as_a_composed_one(): void {
		$composed   = $this->fields();
		$decomposed = $this->fields();

		$decomposed['course'] = "Formac\u{0327}a\u{0303}o em Libras – Módulo I";

		$this->assertSame( CertificateRecord::record( $composed ), CertificateRecord::record( $decomposed ) );
	}

	public function test_surrounding_whitespace_never_changes_the_record(): void {
		$padded         = $this->fields();
		$padded['name'] = "  Maria Aparecida da Silva Santos \n";

		$this->assertSame( CertificateRecord::record( $this->fields() ), CertificateRecord::record( $padded ) );
	}

	public function test_a_separator_or_backslash_inside_a_value_round_trips(): void {
		$fields         = $this->fields();
		$fields['name'] = 'Ana | Bia \\ Carla';

		$record = CertificateRecord::record( $fields );

		$this->assertStringContainsString( 'Ana \\| Bia \\\\ Carla', $record );
		$this->assertSame( 'Ana | Bia \\ Carla', CertificateRecord::fields_of( $record )['name'] );
	}

	public function test_a_record_of_another_version_reads_as_nothing(): void {
		$this->assertSame( array(), CertificateRecord::fields_of( 'v9|1|2' ) );
		$this->assertSame( array(), CertificateRecord::fields_of( 'v1|too|short' ) );
	}

	/**
	 * @dataProvider hours_cases
	 */
	public function test_hours_read_the_number_out_of_the_text( string $text, string $hours ): void {
		$this->assertSame( $hours, CertificateRecord::hours( $text ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function hours_cases(): array {
		return array(
			'with a word' => array( '40 horas', '40' ),
			'suffix'      => array( '8h', '8' ),
			'leading 0'   => array( '08', '8' ),
			'no number'   => array( 'a combinar', '' ),
		);
	}

	public function test_dates_take_an_instant_or_an_iso_date_and_nothing_else(): void {
		$this->assertSame( '2026-11-23', CertificateRecord::date( 1795392000 ) );
		$this->assertSame( '2026-11-10', CertificateRecord::date( '2026-11-10' ) );
		$this->assertSame( '', CertificateRecord::date( '10/11/2026' ) );
	}

	public function test_changed_names_only_the_fields_that_differ(): void {
		$current         = $this->fields();
		$current['name'] = 'Maria A. S. Santos';
		$current['course'] .= ' ';

		$this->assertSame( array( 'name' ), CertificateRecord::changed( $this->fields(), $current ) );
	}

	public function test_every_record_field_has_a_label(): void {
		$this->assertSame( CertificateRecord::RECORD_FIELDS, array_keys( CertificateRecord::field_labels() ) );
	}
}
