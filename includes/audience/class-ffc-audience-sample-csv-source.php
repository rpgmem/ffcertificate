<?php
/**
 * AudienceSampleCsvSource
 *
 * Synchronous {@see \FreeFormCertificate\Core\SyncSourceInterface} for the
 * downloadable audience/members *import template* (the "Download sample" link on
 * the Audience Import & Export page). The sample content is the single source of
 * truth in {@see AudienceCsvImporter::get_sample_rows()} — shared with the
 * on-screen `<pre>` example — so this source just adapts it to the streaming
 * contract. Streamed via {@see \FreeFormCertificate\Core\SyncCsvExport}. (#772.)
 *
 * Gating note: dispatched from the page-load handler
 * {@see AudienceAdminImport::handle_csv_import()} (cap `ffc_manage_audiences`
 * or `ffc_import_audiences` + nonce, silent-return on denial), so `authorize()`
 * is a no-op — the page handler is the gate.
 *
 * @package FreeFormCertificate\Audience
 * @since   6.17.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\SyncSourceInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The audience import sample template as a synchronous export source.
 */
class AudienceSampleCsvSource implements SyncSourceInterface {

	/**
	 * Sample variant: 'audiences' or 'members'.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * Constructor.
	 *
	 * @param string $type Sample type ('audiences' or 'members'; anything else = members).
	 */
	public function __construct( string $type ) {
		$this->type = 'audiences' === $type ? 'audiences' : 'members';
	}

	/**
	 * {@inheritDoc}
	 *
	 * No-op: the page-load handler gates this download (silent-return on denial).
	 *
	 * @return void
	 */
	public function authorize(): void {
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function filename(): string {
		return 'audiences' === $this->type ? 'audiences-sample.csv' : 'members-sample.csv';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 */
	public function header(): array {
		return AudienceCsvImporter::get_sample_rows( $this->type )['header'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return iterable
	 * @phpstan-return iterable<array<int, string>>
	 */
	public function rows(): iterable {
		return AudienceCsvImporter::get_sample_rows( $this->type )['rows'];
	}
}
