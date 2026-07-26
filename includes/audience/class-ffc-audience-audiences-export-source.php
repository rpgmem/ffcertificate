<?php
/**
 * AudienceAudiencesExportSource
 *
 * Synchronous {@see \FreeFormCertificate\Core\SyncSourceInterface} for the
 * "export audiences" CSV on the Audience Import & Export page: one row per
 * audience (name, color, parent), emitted parents-first then their children —
 * the same order the importer expects on read-back. Streamed via
 * {@see \FreeFormCertificate\Core\SyncCsvExport}.
 *
 * Gating note: like {@see AudienceMembersExportSource}, this is dispatched from
 * the page-load handler {@see AudienceAdminImport::handle_csv_import()}, which
 * gates on `ffc_export_audiences` + nonce with silent-return on denial, so
 * `authorize()` here is a no-op. (Issue #772.)
 *
 * @package FreeFormCertificate\Audience
 * @since   6.17.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\SyncSourceInterface;
use FreeFormCertificate\Core\FilenameHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audience taxonomy export as a synchronous source.
 *
 * @phpstan-import-type AudienceRow from AudienceReader
 */
class AudienceAudiencesExportSource implements SyncSourceInterface {

	/**
	 * Default audience color when a row carries none.
	 */
	private const DEFAULT_COLOR = '#3788d8';

	/**
	 * {@inheritDoc}
	 *
	 * No-op: the page-load handler gates this export (silent-return on denial).
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
		return FilenameHelper::get_export_filename( 'audiences-export' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 */
	public function header(): array {
		return array( 'name', 'color', 'parent' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Parents first (empty parent column), then their children carrying the
	 * parent name — matching the importer's expected order.
	 *
	 * @return iterable
	 * @phpstan-return iterable<array<int, string>>
	 */
	public function rows(): iterable {
		foreach ( AudienceReader::get_hierarchical() as $audience ) {
			yield array(
				$audience->name,
				$audience->color ?? self::DEFAULT_COLOR,
				'', // Parents have no parent.
			);

			if ( ! empty( $audience->children ) ) {
				foreach ( $audience->children as $child ) {
					yield array(
						$child->name,
						$child->color ?? self::DEFAULT_COLOR,
						$audience->name,
					);
				}
			}
		}
	}
}
