<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\SigningKeys;
use FreeFormCertificate\Submissions\CertificateSignatureRepository;
use FreeFormCertificate\Submissions\CertificateSigner;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use FreeFormCertificate\Tests\Support\FakeSignatureTable;
use FreeFormCertificate\Tests\Support\Identifiers;
use PHPUnit\Framework\TestCase;

/**
 * Signing a certificate and checking it against its signed record (#2639).
 *
 * Sodium, Encryption and the record format are the real ones; the table is an
 * in-memory double that keeps what it is given.
 *
 * @covers \FreeFormCertificate\Submissions\CertificateSigner
 * @covers \FreeFormCertificate\Submissions\CertificateSignatureRepository
 * @covers \FreeFormCertificate\Submissions\CertificateSignatureActivator
 */
final class CertificateSignerTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, array<string, mixed>> */
	private array $meta = array();

	private FakeSignatureTable $table;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		global $wpdb;
		$this->table = new FakeSignatureTable();
		$wpdb        = $this->table;

		$this->options = array( 'ffc_settings' => ActivityLogBuffer::ENABLED_SETTINGS );
		$this->meta    = array(
			12 => array(
				'_ffc_form_config'      => array( 'qr_mode' => 'full' ),
				'_ffc_geofence_config'  => array(
					'class_date_start' => '2026-11-10',
					'class_date_end'   => '2026-11-14',
				),
				'_ffc_template_values'  => array( 'workload' => '40 horas' ),
			),
		);
		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_post_meta' )->alias( fn( int $id, string $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'get_post' )->alias(
			static function ( int $id ) {
				$post             = new \WP_Post();
				$post->ID         = $id;
				$post->post_title = 'Formação em Libras – Módulo I';
				return $post;
			}
		);
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $ts ): string => gmdate( $format, $ts ) );
		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();
	}

	protected function tearDown(): void {
		ActivityLogBuffer::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A decrypted submission row as the handler returns it.
	 *
	 * @param array<string, mixed> $over Values to change.
	 * @return array<string, mixed>
	 */
	private function submission( array $over = array() ): array {
		return array_merge(
			array(
				'id'              => 345,
				'form_id'         => 12,
				'auth_code'       => 'A1B2C3D4E5F6',
				'submission_date' => 1795392000,
				'cpf_rf'          => Identifiers::CPF_A,
				'email'           => 'maria@example.test',
				'data'            => self::json( array( 'name' => 'Maria Aparecida da Silva Santos' ) ),
			),
			$over
		);
	}

	public function test_a_certificate_is_signed_and_reads_intact(): void {
		SigningKeys::rotate();

		$this->assertTrue( CertificateSigner::sign( 345, $this->submission() ) );

		$integrity = CertificateSigner::integrity( $this->submission() );
		$this->assertSame( CertificateSigner::STATE_INTACT, $integrity['state'] );
		$this->assertSame( 'k1', $integrity['key'] );
		$this->assertSame( array(), $integrity['changed'] );
	}

	public function test_the_stored_record_names_no_cpf_and_is_encrypted(): void {
		SigningKeys::rotate();
		CertificateSigner::sign( 345, $this->submission() );

		$row    = current( $this->table->rows );
		$digits = (string) preg_replace( '/\D+/', '', Identifiers::CPF_A );
		$this->assertStringStartsWith( 'v2:', $row['record_encrypted'] );
		$this->assertStringNotContainsString( 'Maria', $row['record_encrypted'] );

		$record = CertificateSignatureRepository::current( 345 )['record'];
		$this->assertStringContainsString( 'Maria Aparecida', $record );
		$this->assertStringNotContainsString( $digits, $record, 'The record carries an HMAC of the CPF, never the CPF.' );
	}

	public function test_a_value_changed_in_the_database_reads_as_changed_and_names_the_field(): void {
		SigningKeys::rotate();
		CertificateSigner::sign( 345, $this->submission() );

		$tampered  = $this->submission( array( 'data' => self::json( array( 'name' => 'Outra Pessoa' ) ) ) );
		$integrity = CertificateSigner::integrity( $tampered );

		$this->assertSame( CertificateSigner::STATE_CHANGED, $integrity['state'] );
		$this->assertSame( array( 'name' ), $integrity['changed'] );
	}

	public function test_a_changed_cpf_reads_as_changed_without_the_cpf_being_stored(): void {
		SigningKeys::rotate();
		CertificateSigner::sign( 345, $this->submission() );

		$integrity = CertificateSigner::integrity( $this->submission( array( 'cpf_rf' => Identifiers::CPF_B ) ) );

		$this->assertSame( array( 'identifier' ), $integrity['changed'] );
	}

	public function test_a_tampered_signature_row_reads_as_invalid(): void {
		SigningKeys::rotate();
		CertificateSigner::sign( 345, $this->submission() );
		$id                                      = array_key_first( $this->table->rows );
		$this->table->rows[ $id ]['record_hash'] = str_repeat( '0', 64 );

		$this->assertSame( CertificateSigner::STATE_INVALID, CertificateSigner::integrity( $this->submission() )['state'] );
	}

	public function test_a_record_signed_by_a_retired_key_still_reads_intact(): void {
		SigningKeys::rotate();
		CertificateSigner::sign( 345, $this->submission() );
		SigningKeys::rotate();

		$integrity = CertificateSigner::integrity( $this->submission() );
		$this->assertSame( CertificateSigner::STATE_INTACT, $integrity['state'] );
		$this->assertSame( 'k1', $integrity['key'] );
	}

	public function test_signing_again_supersedes_the_previous_record(): void {
		SigningKeys::rotate();
		CertificateSigner::sign( 345, $this->submission() );
		CertificateSigner::sign( 345, $this->submission( array( 'data' => self::json( array( 'name' => 'Maria A. Santos' ) ) ) ) );

		$this->assertCount( 2, $this->table->rows );
		$this->assertNotNull( current( $this->table->rows )['superseded_at'] );
		$this->assertSame( CertificateSigner::STATE_INTACT, CertificateSigner::integrity( $this->submission( array( 'data' => self::json( array( 'name' => 'Maria A. Santos' ) ) ) ) )['state'] );
		$this->assertSame( array( 'k1' => 1 ), CertificateSignatureRepository::count_by_key() );
	}

	public function test_an_unsigned_certificate_reads_as_unsigned(): void {
		$this->assertSame( CertificateSigner::STATE_UNSIGNED, CertificateSigner::integrity( $this->submission() )['state'] );
	}

	public function test_no_key_fails_without_storing_and_is_logged(): void {
		$this->assertFalse( CertificateSigner::sign( 345, $this->submission() ) );

		$this->assertSame( array(), $this->table->rows );
		$entries = ActivityLogBuffer::entries();
		$this->assertSame( 'certificate_signing_failed', $entries[0]['action'] ?? '' );
	}

	public function test_a_link_only_form_is_never_signed_on_issue(): void {
		SigningKeys::rotate();
		$this->meta[12]['_ffc_form_config'] = array( 'qr_mode' => 'link' );

		CertificateSigner::on_saved( 345, 12 );

		$this->assertSame( array(), $this->table->rows );
	}

	public function test_an_edit_does_not_sign_a_certificate_that_was_never_signed(): void {
		SigningKeys::rotate();

		CertificateSigner::on_updated( 345 );

		$this->assertSame( array(), $this->table->rows );
	}

	/**
	 * @dataProvider modes
	 */
	public function test_the_mode_falls_back_to_link_only( $stored, string $mode ): void {
		$this->assertSame( $mode, CertificateSigner::sanitize_mode( $stored ) );
	}

	/**
	 * @return array<string, array{mixed, string}>
	 */
	public static function modes(): array {
		return array(
			'full'    => array( 'full', 'full' ),
			'compact' => array( 'compact', 'compact' ),
			'unknown' => array( 'qr', 'link' ),
			'missing' => array( null, 'link' ),
		);
	}

	/**
	 * JSON for the `data` column, as the handler stores it.
	 *
	 * @param array<string, mixed> $data Data.
	 */
	private static function json( array $data ): string {
		return (string) json_encode( $data, JSON_UNESCAPED_UNICODE );
	}

	public function test_deleting_the_certificate_deletes_its_records(): void {
		SigningKeys::rotate();
		CertificateSigner::sign( 345, $this->submission() );

		CertificateSignatureRepository::delete_for( 345 );

		$this->assertSame( array(), $this->table->rows );
	}
}

