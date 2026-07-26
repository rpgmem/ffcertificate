<?php
/**
 * RecruitmentExampleCsvSource
 *
 * Synchronous {@see \FreeFormCertificate\Core\SyncSourceInterface} for the
 * recruitment importer's downloadable example CSV: a fixed two-row sample whose
 * columns match the importer's REQUIRED + OPTIONAL header shape, so operators
 * see the canonical column order (and both a PCD and a non-PCD candidate) before
 * importing. Streamed via {@see \FreeFormCertificate\Core\SyncCsvExport}. (#772.)
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.17.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\SyncSourceInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The recruitment example/sample CSV as a synchronous export source.
 */
class RecruitmentExampleCsvSource implements SyncSourceInterface {

	/**
	 * Cap gating the download (same as the notice edit screen).
	 */
	private const CAP = 'ffc_manage_recruitment';

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function authorize(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}
		check_admin_referer( 'ffc_recruitment_download_csv_example' );
		nocache_headers();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function filename(): string {
		return 'ffc-recruitment-example.csv';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 */
	public function header(): array {
		// `time_points` and `hab_emebs` are optional headers (v6) — kept in the
		// example so operators see the canonical column order. Existing CSVs
		// that omit them keep importing unchanged.
		return array( 'name', 'cpf', 'rf', 'email', 'phone', 'adjutancy', 'rank', 'score', 'time_points', 'hab_emebs', 'pcd' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The first candidate is PCD, the second is not, so operators see both
	 * shapes. Adjutancy slugs match the Adjutancies-tab catalog convention;
	 * operators must replace them with slugs that exist on the target notice
	 * before importing.
	 *
	 * @return iterable
	 * @phpstan-return iterable<array<int, mixed>>
	 */
	public function rows(): iterable {
		return array(
			array( 'Maria da Silva', '12345678909', '111111', 'maria@example.com', '11999990000', 'portugues', '1', '85.50', '12.00', '1', '1' ),
			array( 'João Souza', '98765432100', '222222', 'joao@example.com', '11988887777', 'matematica', '2', '78.25', '8.50', '0', '0' ),
		);
	}
}
