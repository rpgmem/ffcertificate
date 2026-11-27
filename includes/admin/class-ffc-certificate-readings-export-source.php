<?php
/**
 * CertificateReadingsExportSource
 *
 * Batched CSV export of a certificate form's terminal readings (#2892): the
 * rows of the readings screen, with the same search and result filter.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.53.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Audience\AudienceTerminalReader;
use FreeFormCertificate\Core\BatchedExportSourceInterface;
use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Core\PiiExport;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Frontend\CertificateTerminalReadingRepository as Readings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A form's terminal readings as a batched export.
 *
 * Batched rather than synchronous: a form read at a large event holds a row
 * per person, with no bound by construction. The RF is masked unless the
 * exporter may reveal it, and a file with it in clear is recorded as a bulk
 * reveal (CLAUDE.md "Security & PII conventions").
 */
class CertificateReadingsExportSource implements BatchedExportSourceInterface {

	/** The type the dispatcher routes. */
	public const TYPE = 'certificate_readings';

	/** The nonce action of every phase. */
	public const NONCE = 'ffc_certificate_readings_export';

	/**
	 * Per-export cache of what each row names.
	 *
	 * @var array<string, string>
	 */
	private array $names = array();

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function type(): string {
		return self::TYPE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function authorize_start(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! Capabilities::current_user_can_admin_or( CertificateReadingsPage::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to see the terminal readings.', 'ffcertificate' ) ), 403 );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function authorize_batch( array $job ): void {
		$this->authorize_start();
		if ( (int) get_current_user_id() !== (int) ( $job['user_id'] ?? -1 ) ) {
			wp_send_json_error( array( 'message' => __( 'Session mismatch.', 'ffcertificate' ) ), 403 );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function authorize_download( array $job ): void {
		if ( ! wp_verify_nonce( RequestInput::get_get_string( 'nonce' ), self::NONCE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
		}
		if ( ! Capabilities::current_user_can_admin_or( CertificateReadingsPage::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to see the terminal readings.', 'ffcertificate' ) );
		}
		if ( (int) get_current_user_id() !== (int) ( $job['user_id'] ?? -1 ) ) {
			wp_die( esc_html__( 'Session mismatch.', 'ffcertificate' ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function job_owner_fields(): array {
		return array( 'user_id' => get_current_user_id() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The search is resolved to accounts here, once, so every batch filters
	 * the same people.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize_filters(): array {
		$result = sanitize_key( RequestInput::get_post_string( 'result' ) );
		$search = trim( RequestInput::get_post_string( 's' ) );
		return array(
			'form_id' => RequestInput::get_post_int( 'form_id' ),
			'result'  => in_array( $result, array( Readings::ISSUED, Readings::AWAITING_DATA, Readings::REFUSED ), true ) ? $result : '',
			'people'  => '' === $search ? null : CertificateReadingsPage::matching_accounts( $search ),
		);
	}

	/**
	 * The filters, typed.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array{form_id: int, result: string, people: list<int>|null}
	 */
	private static function typed( array $filters ): array {
		$people = $filters['people'] ?? null;
		return array(
			'form_id' => is_numeric( $filters['form_id'] ?? null ) ? (int) $filters['form_id'] : 0,
			'result'  => is_string( $filters['result'] ?? null ) ? $filters['result'] : '',
			'people'  => is_array( $people ) ? array_values( array_map( 'intval', $people ) ) : null,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return int
	 */
	public function count( array $filters ): int {
		$f = self::typed( $filters );
		return $f['form_id'] > 0 ? Readings::count_matching( $f['form_id'], $f['result'], $f['people'] ) : 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Whether the file carries the RF in clear is decided once, at start.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<string, mixed>
	 */
	public function build_context( array $filters ): array {
		unset( $filters );
		return array( 'pii' => PiiExport::allowed( CertificateReadingsPage::PII_CAP ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param array<string, mixed> $context Frozen context.
	 * @return array<int, string>
	 */
	public function header( array $filters, array $context ): array {
		unset( $filters, $context );
		return array(
			__( 'Person', 'ffcertificate' ),
			__( 'RF', 'ffcertificate' ),
			__( 'Reading', 'ffcertificate' ),
			__( 'Terminal', 'ffcertificate' ),
			__( 'Result', 'ffcertificate' ),
			__( 'Detail', 'ffcertificate' ),
			__( 'Certificate', 'ffcertificate' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param array<string, mixed> $context Frozen context.
	 * @return string
	 */
	public function filename( array $filters, array $context ): string {
		unset( $context );
		return 'terminal-readings-' . self::typed( $filters )['form_id'] . '-' . gmdate( 'Y-m-d' ) . '.csv';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param array<string, mixed> $context Frozen context.
	 * @param int                  $cursor  Exclusive upper-bound id.
	 * @param int                  $size    Page size.
	 * @return array<int, array<string, mixed>>
	 */
	public function fetch_page( array $filters, array $context, int $cursor, int $size ): array {
		unset( $context );
		$f = self::typed( $filters );
		return $f['form_id'] > 0 ? Readings::export_batch( $f['form_id'], $f['result'], $f['people'], $cursor, $size ) : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $row Row.
	 * @return int
	 */
	public function cursor_of( array $row ): int {
		return is_int( $row['id'] ?? null ) ? $row['id'] : 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $row     Raw row.
	 * @param array<string, mixed> $context Frozen context.
	 * @return array<int, string>
	 */
	public function format_row( array $row, array $context ): array {
		$user_id = is_int( $row['user_id'] ?? null ) ? $row['user_id'] : 0;
		$read_at = is_int( $row['read_at'] ?? null ) ? $row['read_at'] : 0;
		$code    = is_string( $row['auth_code'] ?? null ) ? $row['auth_code'] : '';
		$rf      = $user_id > 0 ? \FreeFormCertificate\Core\CertificateSigners::user_rf( $user_id ) : '';
		return array(
			$this->person( $user_id ),
			(string) PiiExport::cell( 'rf', $rf, ! empty( $context['pii'] ) ),
			$read_at > 0 ? DateFormatter::format_datetime( $read_at ) : '',
			$this->terminal( is_int( $row['terminal_id'] ?? null ) ? $row['terminal_id'] : 0 ),
			CertificateReadingsPage::result_label( is_string( $row['result'] ?? null ) ? $row['result'] : '' ),
			CertificateReadingsPage::why( $row ),
			'' !== $code ? DocumentFormatter::format_auth_code( $code, DocumentFormatter::PREFIX_CERTIFICATE ) : '',
		);
	}

	/**
	 * A reader's name.
	 *
	 * @param int $user_id Account id.
	 * @return string
	 */
	private function person( int $user_id ): string {
		$key = 'u' . $user_id;
		if ( ! isset( $this->names[ $key ] ) ) {
			$user                = $user_id > 0 ? get_userdata( $user_id ) : false;
			$this->names[ $key ] = $user ? (string) $user->display_name : __( 'Account deleted', 'ffcertificate' );
		}
		return $this->names[ $key ];
	}

	/**
	 * A terminal's name.
	 *
	 * @param int $terminal_id Terminal id.
	 * @return string
	 */
	private function terminal( int $terminal_id ): string {
		$key = 't' . $terminal_id;
		if ( ! isset( $this->names[ $key ] ) ) {
			$terminal            = $terminal_id > 0 ? AudienceTerminalReader::terminal( $terminal_id ) : null;
			$this->names[ $key ] = null !== $terminal ? $terminal['label'] : '';
		}
		return $this->names[ $key ];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $job_id Job id.
	 * @param array<string, mixed> $job    Job state.
	 * @return array<string, mixed>
	 */
	public function extra_start_response( string $job_id, array $job ): array {
		unset( $job_id, $job );
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $job_id Job id.
	 * @param array<string, mixed> $job    Final job state.
	 * @return void
	 */
	public function on_complete( string $job_id, array $job ): void {
		unset( $job_id );
		// A file with the RF in clear is a bulk reveal (#1863).
		$context = is_array( $job['context'] ?? null ) ? $job['context'] : array();
		if ( ! empty( $context['pii'] ) ) {
			$filters = is_array( $job['filters'] ?? null ) ? $job['filters'] : array();
			PiiExport::log( self::TYPE, is_numeric( $job['processed'] ?? null ) ? (int) $job['processed'] : 0, array( 'form_id' => self::typed( $filters )['form_id'] ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function on_before_download( array $job ): void {
		unset( $job );
	}
}
