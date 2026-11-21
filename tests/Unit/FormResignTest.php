<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\FormResignNotice;
use FreeFormCertificate\Core\SigningKeys;
use FreeFormCertificate\Submissions\CertificateSignatureRepository;
use FreeFormCertificate\Submissions\CertificateSigner;
use FreeFormCertificate\Submissions\FormResign;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use FreeFormCertificate\Tests\Support\FakeSignatureTable;
use FreeFormCertificate\Tests\Support\Identifiers;
use PHPUnit\Framework\TestCase;

/**
 * Signing a form's certificates again after the form changed (#2696).
 *
 * Sodium, Encryption and the record format are the real ones; the signature
 * table is the in-memory double the signer's own test uses.
 *
 * @covers \FreeFormCertificate\Submissions\FormResign
 * @covers \FreeFormCertificate\Admin\FormResignNotice::editing_warning
 * @covers \FreeFormCertificate\Submissions\CertificateSignatureRepository::count_for_form
 * @covers \FreeFormCertificate\Submissions\CertificateSignatureRepository::signed_for_form
 * @covers \FreeFormCertificate\Submissions\CertificateSignatureRepository::latest_for_form
 */
final class FormResignTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, array<string, mixed>> */
	private array $meta = array();

	private string $title = 'Formação em Libras';

	private FakeSignatureTable $table;

	/** @var mixed The `$wpdb` before the test. */
	private $wpdb_backup;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$this->table       = new FakeSignatureTable();
		$GLOBALS['wpdb']   = $this->table;

		$this->options = array( 'ffc_settings' => ActivityLogBuffer::ENABLED_SETTINGS );
		$this->meta    = array(
			12 => array(
				'_ffc_form_config'     => array( 'qr_mode' => 'full' ),
				'_ffc_template_values' => array( 'workload' => '40 horas' ),
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
		Functions\when( 'update_post_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				$this->meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( int $id, string $key ): bool {
				unset( $this->meta[ $id ][ $key ] );
				return true;
			}
		);
		Functions\when( 'get_post' )->alias(
			function ( int $id ) {
				$post             = new \WP_Post();
				$post->ID         = $id;
				$post->post_title = $this->title;
				return $post;
			}
		);
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $ts ): string => gmdate( $format, $ts ) );
		Functions\when( '_n' )->alias( static fn( string $one, string $many, int $n ): string => 1 === $n ? $one : $many );
		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_backup;
		ActivityLogBuffer::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A decrypted submission row of form 12.
	 *
	 * @param int $id Submission id.
	 * @return array<string, mixed>
	 */
	private function submission( int $id ): array {
		return array(
			'id'              => $id,
			'form_id'         => 12,
			'auth_code'       => 'CODE' . $id,
			'submission_date' => 1795392000,
			'cpf_rf'          => Identifiers::CPF_A,
			'data'            => (string) json_encode( array( 'name' => 'Pessoa ' . $id ) ),
		);
	}

	/**
	 * Sign `$count` certificates of form 12.
	 *
	 * @param int $count How many.
	 */
	private function sign_certificates( int $count ): void {
		SigningKeys::rotate();
		for ( $id = 1; $id <= $count; $id++ ) {
			$this->table->forms[ $id ] = 12;
			CertificateSigner::sign( $id, $this->submission( $id ) );
		}
	}

	public function test_a_form_whose_signed_fields_did_not_change_is_not_stale(): void {
		$this->sign_certificates( 2 );

		$this->assertFalse( FormResign::is_stale( 12 ) );
		$this->assertSame( 2, CertificateSignatureRepository::count_for_form( 12 ) );
	}

	public function test_a_changed_title_or_workload_makes_the_form_stale(): void {
		$this->sign_certificates( 1 );

		$this->title = 'Formação em Libras – Módulo II';
		$this->assertTrue( FormResign::is_stale( 12 ) );

		$this->title = 'Formação em Libras';
		$this->meta[12]['_ffc_template_values'] = array( 'workload' => '60 horas' );
		$this->assertTrue( FormResign::is_stale( 12 ) );
	}

	public function test_a_form_with_no_signed_certificate_is_never_stale(): void {
		$this->assertFalse( FormResign::is_stale( 12 ) );
		$this->assertSame( '', FormResignNotice::editing_warning( 12 ) );
	}

	public function test_signing_again_attests_the_new_form_data_and_is_logged(): void {
		$this->sign_certificates( 3 );
		$this->title = 'Formação em Libras – Módulo II';
		$this->assertSame( CertificateSigner::STATE_CHANGED, CertificateSigner::integrity( $this->submission( 2 ) )['state'] );

		$totals = FormResign::run_batch( 12, fn( int $id ): array => $this->submission( $id ) );

		$this->assertSame(
			array(
				'examined' => 3,
				'signed'   => 3,
				'done'     => true,
			),
			$totals
		);
		$this->assertSame( CertificateSigner::STATE_INTACT, CertificateSigner::integrity( $this->submission( 2 ) )['state'] );
		$this->assertFalse( FormResign::is_stale( 12 ) );
		$this->assertFalse( FormResign::in_progress( 12 ) );

		$entries = ActivityLogBuffer::entries();
		$last    = end( $entries );
		$this->assertSame( FormResign::LOG_ACTION, $last['action'] ?? '' );
	}

	public function test_a_certificate_whose_row_cannot_be_read_does_not_stop_the_walk(): void {
		$this->sign_certificates( 2 );
		$this->title = 'Outro título';

		$totals = FormResign::run_batch( 12, fn( int $id ): ?array => 1 === $id ? null : $this->submission( $id ) );

		$this->assertSame( 2, $totals['examined'] );
		$this->assertSame( 1, $totals['signed'] );
		$this->assertTrue( $totals['done'] );
	}

	public function test_a_long_walk_keeps_its_cursor_between_requests(): void {
		$this->sign_certificates( FormResign::BATCH + 1 );
		$this->title = 'Outro título';
		$load        = fn( int $id ): array => $this->submission( $id );

		$first = FormResign::run_batch( 12, $load );
		$this->assertFalse( $first['done'] );
		$this->assertTrue( FormResign::in_progress( 12 ) );

		$second = FormResign::run_batch( 12, $load );
		$this->assertTrue( $second['done'] );
		$this->assertSame( FormResign::BATCH + 1, $second['examined'] );
		$this->assertSame( FormResign::BATCH + 1, $second['signed'] );
		$this->assertFalse( FormResign::in_progress( 12 ) );
	}

	public function test_the_editor_warns_before_a_save_while_certificates_are_signed(): void {
		$this->sign_certificates( 2 );

		$warning = FormResignNotice::editing_warning( 12 );

		$this->assertStringContainsString( '2 certificates of this form are signed', $warning );
	}
}

