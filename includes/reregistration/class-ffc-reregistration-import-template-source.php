<?php
/**
 * ReregistrationImportTemplateSource
 *
 * The blank answers file for one audience of a campaign (#2506): the header
 * the import reads, and no rows. A static template, so it goes through the
 * synchronous CSV adapter.
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\SyncSourceInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Header-only CSV of the columns an import into one audience expects.
 */
final class ReregistrationImportTemplateSource implements SyncSourceInterface {

	/**
	 * The `admin_post_` action that streams it.
	 */
	public const ACTION = 'ffc_rereg_import_template';

	/**
	 * Campaign.
	 *
	 * @var int
	 */
	private int $reregistration_id;

	/**
	 * Audience whose fields are the columns.
	 *
	 * @var int
	 */
	private int $audience_id;

	/**
	 * Binds the source to one audience of one campaign.
	 *
	 * @param int $reregistration_id Campaign.
	 * @param int $audience_id       Audience.
	 */
	public function __construct( int $reregistration_id, int $audience_id ) {
		$this->reregistration_id = $reregistration_id;
		$this->audience_id       = $audience_id;
	}

	/**
	 * The import's own nonce and capability, and an audience the campaign
	 * reaches, as the import itself requires. Stops the request on failure.
	 *
	 * @return void
	 */
	public function authorize(): void {
		check_admin_referer( ReregistrationImportAjaxHandler::NONCE_ACTION );

		if ( ! Capabilities::current_user_can_admin_or( ReregistrationImportAjaxHandler::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		$audience_ids = array_map( 'intval', ReregistrationRepository::get_audience_ids( $this->reregistration_id ) );
		if ( $this->audience_id <= 0 || ! in_array( $this->audience_id, $audience_ids, true ) ) {
			wp_die( esc_html__( 'This audience is not part of the campaign.', 'ffcertificate' ), '', array( 'response' => 400 ) );
		}

		nocache_headers();
	}

	/**
	 * The download file name: the campaign and the audience.
	 *
	 * @return string
	 */
	public function filename(): string {
		return 'ffc-reregistration-' . $this->reregistration_id . '-audience-' . $this->audience_id . '.csv';
	}

	/**
	 * The columns the import maps.
	 *
	 * @return array<int, string>
	 */
	public function header(): array {
		return ReregistrationImportStagingService::template_header( $this->audience_id );
	}

	/**
	 * A template carries no answers.
	 *
	 * @return iterable<int, array<int, string>>
	 */
	public function rows(): iterable {
		return array();
	}
}
