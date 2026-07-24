<?php
/**
 * AudienceMembersExportSource
 *
 * Synchronous {@see \FreeFormCertificate\Core\SyncSourceInterface} for the
 * "export members" CSV on the Audience Import & Export page: one row per unique
 * member+audience pair (email, display name, audience name), scoped to a single
 * audience or all of them. Streamed via {@see \FreeFormCertificate\Core\SyncCsvExport}.
 *
 * Gating note: this source is dispatched from the page-load handler
 * {@see AudienceAdminImport::handle_csv_import()}, which enforces the
 * `ffc_export_audiences` capability + per-action nonce and *silently returns*
 * (re-rendering the page) on denial — semantics that a `wp_die()` gate would
 * change — so `authorize()` here is intentionally a no-op; the page handler is
 * the gate. (Issue #772.)
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
 * Audience members export as a synchronous source.
 */
class AudienceMembersExportSource implements SyncSourceInterface {

	/**
	 * Audience id to export, or 0 for all audiences.
	 *
	 * @var int
	 */
	private int $audience_id;

	/**
	 * Constructor.
	 *
	 * @param int $audience_id Audience id to export (0 = all).
	 */
	public function __construct( int $audience_id ) {
		$this->audience_id = $audience_id;
	}

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
		return FilenameHelper::get_export_filename( 'members-export' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 */
	public function header(): array {
		return array( 'email', 'name', 'audience_name' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * One row per unique member+audience pair; users that no longer exist are
	 * skipped.
	 *
	 * @return iterable
	 * @phpstan-return iterable<array<int, string>>
	 */
	public function rows(): iterable {
		$audience_ids = array();
		if ( $this->audience_id > 0 ) {
			$audience_ids[] = $this->audience_id;
		} else {
			foreach ( AudienceReader::get_all() as $aud ) {
				$audience_ids[] = (int) $aud->id;
			}
		}

		$audience_map = array();
		foreach ( AudienceReader::get_all() as $aud ) {
			$audience_map[ (int) $aud->id ] = $aud->name;
		}

		$seen = array(); // Avoid duplicate rows for the same user+audience.
		foreach ( $audience_ids as $aid ) {
			$member_ids    = AudienceReader::get_members( $aid );
			$audience_name = isset( $audience_map[ $aid ] ) ? $audience_map[ $aid ] : '';

			foreach ( $member_ids as $user_id ) {
				$key = $user_id . '-' . $aid;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;

				$user = get_user_by( 'id', $user_id );
				if ( ! $user ) {
					continue;
				}

				yield array(
					$user->user_email,
					$user->display_name,
					$audience_name,
				);
			}
		}
	}
}
