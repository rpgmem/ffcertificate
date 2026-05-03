<?php
/**
 * Recruitment Reason Edit Page.
 *
 * Dedicated wp-admin screen reached via the "Edit" row action on the
 * Reasons list table:
 *
 *   admin.php?page=ffc-recruitment&tab=reasons&action=edit-reason&reason_id=N
 *
 * One section: General — slug, label, color, applies_to. The reason
 * catalog is global (no notice attach junction), so editing is purely
 * about the catalog row's own attributes; nothing on this screen
 * cascades into classification rows that hold the reason via
 * `preview_reason_id`.
 *
 * The save handler is gated by the per-reason nonce. Slug edits flow
 * through the repository's UNIQUE constraint, so collisions surface
 * as a flash error rather than a silent overwrite.
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
 * Reason edit screen renderer + admin-post save handler.
 *
 * @phpstan-import-type ReasonRow from RecruitmentReasonRepository
 */
final class RecruitmentReasonEditPage {

	private const CAP = 'ffc_manage_recruitment';

	/**
	 * Hook the admin-post save endpoint.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_ffc_recruitment_save_reason', array( self::class, 'handle_save' ), 10 );
	}

	/**
	 * Render the edit screen body. Called by RecruitmentAdminPage when
	 * `?action=edit-reason` is detected on the page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render.
		$reason_id = isset( $_GET['reason_id'] ) ? absint( wp_unslash( (string) $_GET['reason_id'] ) ) : 0;
		$reason    = $reason_id > 0 ? RecruitmentReasonRepository::get_by_id( $reason_id ) : null;

		if ( null === $reason ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Reason not found.', 'ffcertificate' ) . '</p></div>';
			echo '<p><a href="' . esc_url( self::back_url() ) . '">&larr; ' . esc_html__( 'Back to Reasons', 'ffcertificate' ) . '</a></p>';
			return;
		}

		echo '<p><a href="' . esc_url( self::back_url() ) . '">&larr; ' . esc_html__( 'Back to Reasons', 'ffcertificate' ) . '</a></p>';
		echo '<h2>' . sprintf(
			/* translators: %s — reason label */
			esc_html__( 'Edit reason — %s', 'ffcertificate' ),
			esc_html( (string) $reason->label )
		) . '</h2>';

		self::render_general_section( $reason );
	}

	/**
	 * Section: General editable fields.
	 *
	 * @param object $reason Reason row.
	 * @phpstan-param ReasonRow $reason
	 * @return void
	 */
	private static function render_general_section( object $reason ): void {
		$id           = (int) $reason->id;
		$nonce_action = 'ffc_recruitment_save_reason_' . $id;
		$applies_to   = RecruitmentReasonRepository::decode_applies_to( (string) ( $reason->applies_to ?? '' ) );
		// `decode_applies_to` returns the full set when storage is empty
		// (= "applies to all"). Distinguish "stored empty" from "stored
		// every value" so the checkbox grid renders correctly: when
		// stored is empty, leave every box unchecked.
		$is_applies_all = '' === trim( (string) ( $reason->applies_to ?? '' ) );
		$color          = isset( $reason->color ) ? (string) $reason->color : RecruitmentReasonRepository::DEFAULT_COLOR;

		$applies_options = array(
			'denied'         => __( 'Denied', 'ffcertificate' ),
			'granted'        => __( 'Granted', 'ffcertificate' ),
			'appeal_denied'  => __( 'Appeal denied', 'ffcertificate' ),
			'appeal_granted' => __( 'Appeal granted', 'ffcertificate' ),
		);

		echo '<div class="postbox" style="margin-top:20px;">';
		echo '<h2 class="hndle"><span>' . esc_html__( 'General', 'ffcertificate' ) . '</span></h2>';
		echo '<div class="inside">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ffc_recruitment_save_reason">';
		echo '<input type="hidden" name="reason_id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( $nonce_action );

		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="ffc-reason-edit-slug">' . esc_html__( 'Slug', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-reason-edit-slug" type="text" class="regular-text" name="slug" value="' . esc_attr( (string) $reason->slug ) . '" required>';
		echo '<p class="description">' . esc_html__( 'Unique identifier (slug-shaped). Must be unique across the catalog. Existing classifications keep their reference because they link by id, not by slug.', 'ffcertificate' ) . '</p></td></tr>';

		echo '<tr><th><label for="ffc-reason-edit-label">' . esc_html__( 'Label', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-reason-edit-label" type="text" class="regular-text" name="label" value="' . esc_attr( (string) $reason->label ) . '" required></td></tr>';

		echo '<tr><th><label for="ffc-reason-edit-color">' . esc_html__( 'Badge color', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-reason-edit-color" type="color" name="color" value="' . esc_attr( $color ) . '">';
		echo ' <code style="margin-left:.5em;">' . esc_html( $color ) . '</code></td></tr>';

		echo '<tr><th>' . esc_html__( 'Applies to', 'ffcertificate' ) . '</th><td>';
		echo '<div style="display:flex;flex-wrap:wrap;gap:6px 16px;">';
		foreach ( $applies_options as $key => $label ) {
			$id_attr = 'ffc-reason-edit-applies-' . $key;
			$checked = ! $is_applies_all && in_array( $key, $applies_to, true );
			echo '<label for="' . esc_attr( $id_attr ) . '" style="display:flex;align-items:center;gap:6px;">';
			echo '<input id="' . esc_attr( $id_attr ) . '" type="checkbox" name="applies_to[]" value="' . esc_attr( $key ) . '"' . ( $checked ? ' checked' : '' ) . '>';
			echo esc_html( $label );
			echo '</label>';
		}
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Leave all unchecked to make this reason applicable to every preliminary status.', 'ffcertificate' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save reason', 'ffcertificate' ) );
		echo '</form>';

		echo '</div></div>';
	}

	/**
	 * Admin-post handler for the General save.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}
		$reason_id = isset( $_POST['reason_id'] ) ? absint( wp_unslash( (string) $_POST['reason_id'] ) ) : 0;
		check_admin_referer( 'ffc_recruitment_save_reason_' . $reason_id );

		if ( $reason_id <= 0 ) {
			wp_safe_redirect( self::back_url() );
			exit;
		}

		$slug  = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( (string) $_POST['slug'] ) ) : '';
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : '';
		$color = isset( $_POST['color'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['color'] ) ) : '';

		$applies_raw = isset( $_POST['applies_to'] ) && is_array( $_POST['applies_to'] )
			? wp_unslash( $_POST['applies_to'] )
			: array();
		$applies     = array();
		foreach ( $applies_raw as $candidate ) {
			if ( is_string( $candidate ) ) {
				$applies[] = $candidate;
			}
		}

		RecruitmentReasonRepository::update(
			$reason_id,
			array(
				'slug'       => $slug,
				'label'      => $label,
				'color'      => $color,
				'applies_to' => $applies,
			)
		);

		wp_safe_redirect( self::edit_url( $reason_id, 'saved' ) );
		exit;
	}

	/**
	 * Build the back-to-reasons-list URL.
	 *
	 * @return string
	 */
	private static function back_url(): string {
		return add_query_arg(
			array(
				'page' => RecruitmentAdminPage::PAGE_SLUG,
				'tab'  => 'reasons',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build the edit-reason URL with an optional flash message key.
	 *
	 * @param int    $reason_id Reason ID.
	 * @param string $msg       Flash message key (echoed via `?ffc_msg=`).
	 * @return string
	 */
	private static function edit_url( int $reason_id, string $msg = '' ): string {
		$args = array(
			'page'      => RecruitmentAdminPage::PAGE_SLUG,
			'tab'       => 'reasons',
			'action'    => 'edit-reason',
			'reason_id' => $reason_id,
		);
		if ( '' !== $msg ) {
			$args['ffc_msg'] = $msg;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
