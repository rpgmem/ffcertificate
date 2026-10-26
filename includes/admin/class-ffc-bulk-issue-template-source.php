<?php
/**
 * BulkIssueTemplateSource
 *
 * The blank participants file for "Issue in bulk" (#1945): the three required
 * identifiers first, then the form's own fields in form order. It is a static
 * template, so it goes through the synchronous CSV adapter.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\SyncSourceInterface;
use FreeFormCertificate\Submissions\BulkIssuePlanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Header-only CSV describing the columns a bulk issue of one form expects.
 */
final class BulkIssueTemplateSource implements SyncSourceInterface {

	/**
	 * Form the template describes.
	 *
	 * @var int
	 */
	private int $form_id;

	/**
	 * Binds the source to one form.
	 *
	 * @param int $form_id Form post ID.
	 */
	public function __construct( int $form_id ) {
		$this->form_id = $form_id;
	}

	/**
	 * Checks the capability and the nonce bound to this form, then sends the
	 * no-cache headers. Stops the request on failure.
	 *
	 * @return void
	 */
	public function authorize(): void {
		if ( ! current_user_can( BulkIssuePage::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}

		check_admin_referer( BulkIssuePage::template_nonce_action( $this->form_id ) );

		if ( 'ffc_form' !== get_post_type( $this->form_id ) ) {
			wp_die( esc_html__( 'Invalid form.', 'ffcertificate' ) );
		}

		nocache_headers();
	}

	/**
	 * The download file name, including the form ID.
	 *
	 * @return string Download file name, including the form ID.
	 */
	public function filename(): string {
		return 'ffc-issue-form-' . $this->form_id . '.csv';
	}

	/**
	 * The columns of the template: required identifiers, the form's fields
	 * and the optional issue date.
	 *
	 * @return array<int, string> Column names.
	 */
	public function header(): array {
		$fields = get_post_meta( $this->form_id, '_ffc_form_fields', true );

		return BulkIssuePlanner::template_header( is_array( $fields ) ? $fields : array() );
	}

	/**
	 * A template carries no participants.
	 *
	 * @return iterable<int, array<int, string>>
	 */
	public function rows(): iterable {
		return array();
	}
}
