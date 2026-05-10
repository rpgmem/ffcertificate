<?php
/**
 * Recruitment Notice Edit Page.
 *
 * Dedicated wp-admin screen reached via the "Edit" row action on the
 * Notices list table:
 *
 *   admin.php?page=ffc-recruitment&tab=notices&action=edit-notice&notice_id=N
 *
 * Renders four metabox-style sections inspired by the certificate form
 * editor pattern (Admin\FormEditor) but adapted to the recruitment
 * domain's custom-table backing:
 *
 *   1. General        — code (read-only after creation per §3.2 stable
 *                       identifier rule) + name + public_columns_config.
 *   2. Status         — current state badge + transition buttons that
 *                       hit NoticeStateMachine::transition_to (driver
 *                       for all draft↔preliminary↔definitive↔closed moves).
 *   3. Adjutancies    — attach/detach UI relocated from the row inline
 *                       (sprint A1) and wired to the existing REST
 *                       routes via the assets manager's fetch helper.
 *   4. Classifications — preview + definitive lists side by side; pure
 *                       reader for now (per-classification status edit
 *                       lands when the bulk-call modal does).
 *
 * Save flow runs through the admin-post handler at
 * `admin.php?action=ffc_recruitment_save_notice`, gated by a per-notice
 * nonce. The state-machine guards reject illegal transitions at the
 * service layer regardless of which UI surface invokes them.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notice edit screen renderer + admin-post save handler.
 *
 * @phpstan-import-type NoticeRow         from RecruitmentNoticeRepository
 * @phpstan-import-type ClassificationRow from RecruitmentClassificationRepository
 * @phpstan-import-type ReasonRow         from RecruitmentReasonRepository
 */
final class RecruitmentNoticeEditPage {

	/**
	 * Cap gating render + save.
	 */
	private const CAP = 'ffc_manage_recruitment';

	/**
	 * Hook the admin-post save endpoint. Called from RecruitmentLoader.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_ffc_recruitment_save_notice', array( self::class, 'handle_save' ), 10 );
		add_action( 'admin_post_ffc_recruitment_transition_notice', array( self::class, 'handle_transition' ), 10 );
		add_action( 'admin_post_ffc_recruitment_download_csv_example', array( self::class, 'handle_download_csv_example' ), 10 );
	}

	/**
	 * Stream a small example CSV that matches the importer's header
	 * shape (REQUIRED + OPTIONAL_HEADERS in
	 * {@see RecruitmentCsvImporter}). Two rows, semicolon delimiter to
	 * survive the BR/EU spreadsheet round-trip — the importer auto-
	 * detects either delimiter on read.
	 *
	 * Cap-gated; nonce-gated. Sends a Content-Disposition: attachment
	 * header so browsers offer a download instead of inline-rendering.
	 *
	 * @return void
	 */
	public static function handle_download_csv_example(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}
		check_admin_referer( 'ffc_recruitment_download_csv_example' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="ffc-recruitment-example.csv"' );

		// Header line + two example rows. The first candidate is PCD,
		// the second is not, so operators see both shapes. Adjutancy
		// slugs match the catalog convention from the Adjutancies tab;
		// operators must replace them with slugs that exist on the
		// target notice before importing.
		// `time_points` and `hab_emebs` are optional headers (v6) — kept
		// in the example so operators see the canonical column order.
		// Existing CSVs that omit them keep importing unchanged.
		$rows = array(
			array( 'name', 'cpf', 'rf', 'email', 'phone', 'adjutancy', 'rank', 'score', 'time_points', 'hab_emebs', 'pcd' ),
			array( 'Maria da Silva', '12345678909', '111111', 'maria@example.com', '11999990000', 'portugues', '1', '85.50', '12.00', '1', '1' ),
			array( 'João Souza', '98765432100', '222222', 'joao@example.com', '11988887777', 'matematica', '2', '78.25', '8.50', '0', '0' ),
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV template to php://output.
		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}
		$writer = \FreeFormCertificate\Core\Csv::writer( $out );
		$writer->rows( $rows );
		$writer->close();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output handle this method opened.
		fclose( $out );
		exit;
	}

	/**
	 * Render the edit screen body. Called by RecruitmentAdminPage when
	 * `?action=edit-notice` is detected on the page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render; mutating actions live on the dedicated handlers.
		$notice_id = isset( $_GET['notice_id'] ) ? absint( wp_unslash( (string) $_GET['notice_id'] ) ) : 0;
		$notice    = $notice_id > 0 ? RecruitmentNoticeRepository::get_by_id( $notice_id ) : null;
		if ( null === $notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Notice not found.', 'ffcertificate' ) . '</p></div>';
			echo '<p><a href="' . esc_url( self::back_url() ) . '">&larr; ' . esc_html__( 'Back to Notices', 'ffcertificate' ) . '</a></p>';
			return;
		}

		echo '<p><a href="' . esc_url( self::back_url() ) . '">&larr; ' . esc_html__( 'Back to Notices', 'ffcertificate' ) . '</a></p>';
		echo '<h2>' . sprintf(
			/* translators: %s — notice code */
			esc_html__( 'Edit notice — %s', 'ffcertificate' ),
			'<code>' . esc_html( (string) $notice->code ) . '</code>'
		) . '</h2>';

		RecruitmentNoticeEditPageRenderer::render_general_section( $notice );
		RecruitmentNoticeEditPageRenderer::render_status_section( $notice );
		RecruitmentNoticeEditPageRenderer::render_adjutancies_section( $notice );
		RecruitmentNoticeEditPageRenderer::render_csv_import_section( $notice );
		RecruitmentNoticeEditPageRenderer::render_classifications_section( $notice );
	}

	/**
	 * Admin-post handler for the General section save.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}
		$notice_id = isset( $_POST['notice_id'] ) ? absint( wp_unslash( (string) $_POST['notice_id'] ) ) : 0;
		check_admin_referer( 'ffc_recruitment_save_notice_' . $notice_id );

		if ( $notice_id <= 0 ) {
			wp_safe_redirect( self::back_url() );
			exit;
		}

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';

		// Build the JSON config from the checkbox grid. Every key in
		// columns_label_map() emits an entry; unchecked checkboxes don't
		// POST so we treat them as `false`, while the mandatory columns
		// (rank, name) ride on hidden value=1 inputs that always post.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above via check_admin_referer.
		$posted = isset( $_POST['public_columns'] ) && is_array( $_POST['public_columns'] )
			? wp_unslash( $_POST['public_columns'] )
			: array();
		$labels = RecruitmentNoticeEditPageRenderer::columns_label_map();
		$built  = array();
		foreach ( array_keys( $labels ) as $key ) {
			$built[ $key ] = isset( $posted[ $key ] ) && '' !== (string) $posted[ $key ];
		}
		$built['rank'] = true;
		$built['name'] = true;
		$config        = wp_json_encode( $built );

		RecruitmentNoticeRepository::update(
			$notice_id,
			array(
				'name'                  => $name,
				'public_columns_config' => is_string( $config ) ? $config : '{}',
			)
		);

		self::redirect_with_notice( $notice_id, 'saved' );
	}

	/**
	 * Admin-post handler for status transitions.
	 *
	 * @return void
	 */
	public static function handle_transition(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}
		$notice_id = isset( $_POST['notice_id'] ) ? absint( wp_unslash( (string) $_POST['notice_id'] ) ) : 0;
		check_admin_referer( 'ffc_recruitment_transition_notice_' . $notice_id );

		$target = isset( $_POST['target_status'] ) ? sanitize_key( wp_unslash( (string) $_POST['target_status'] ) ) : '';
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['reason'] ) ) : null;
		if ( '' !== (string) $reason && null === $reason ) {
			$reason = null;
		}

		// Default to a generic invalid-target failure when the operator
		// somehow lands here with a non-enum value (form-tampering case).
		$flash = 'transition-invalid-target';

		if ( $notice_id > 0 && in_array( $target, array( 'draft', 'preliminary', 'definitive', 'closed' ), true ) ) {
			$result = RecruitmentNoticeStateMachine::transition_to( $notice_id, $target, '' === (string) $reason ? null : $reason );
			if ( true === $result['success'] ) {
				$flash = 'transitioned';
			} else {
				// Map the state-machine error code to a flash key the
				// renderer's notice map knows. Anything we don't have a
				// dedicated copy for falls through to a generic
				// transition-failed key carrying the raw code so the
				// operator can see what blocked it.
				$first_error = empty( $result['errors'] ) ? '' : (string) $result['errors'][0];
				switch ( $first_error ) {
					case 'recruitment_definitive_to_preliminary_blocked_by_calls':
						$flash = 'transition-blocked-by-calls';
						break;
					case 'recruitment_transition_reason_required':
						$flash = 'transition-reason-required';
						break;
					case 'recruitment_transition_race_lost':
						$flash = 'transition-race-lost';
						break;
					default:
						// Includes recruitment_invalid_transition: …
						// and recruitment_notice_not_found.
						$flash = 'transition-failed';
						break;
				}
			}
		}

		self::redirect_with_notice( $notice_id, $flash );
	}

	/**
	 * Back-to-list URL.
	 *
	 * @return string
	 */
	private static function back_url(): string {
		return add_query_arg(
			array(
				'page' => RecruitmentAdminPage::PAGE_SLUG,
				'tab'  => 'notices',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Redirect back to the edit screen with a `?ffc_msg=…` flash for
	 * the next render to surface (admin notice).
	 *
	 * @param int    $notice_id Notice ID.
	 * @param string $message_key Flash key.
	 * @return never
	 */
	private static function redirect_with_notice( int $notice_id, string $message_key ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => RecruitmentAdminPage::PAGE_SLUG,
					'tab'       => 'notices',
					'action'    => 'edit-notice',
					'notice_id' => $notice_id,
					'ffc_msg'   => $message_key,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
