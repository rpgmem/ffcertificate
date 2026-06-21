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

	/**
	 * Edit/save gate. GAP I moved reasons onto their own strict tier — the
	 * umbrella `ffc_manage_recruitment` no longer grants reason editing.
	 */
	private const CAP = 'ffc_manage_recruitment_reasons';

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
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( self::CAP ) ) {
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

		echo '<div class="postbox ffc-rec-mt-20">';
		echo '<h2 class="hndle"><span>' . esc_html__( 'General', 'ffcertificate' ) . '</span></h2>';
		echo '<div class="inside">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ffc_recruitment_save_reason">';
		echo '<input type="hidden" name="reason_id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( $nonce_action );

		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="ffc-reason-edit-slug">' . esc_html__( 'Slug', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-reason-edit-slug" type="text" class="regular-text" value="' . esc_attr( (string) $reason->slug ) . '" readonly disabled>';
		echo '<p class="description">' . esc_html__( 'The slug is locked after creation. It is referenced by activity-log entries and possibly by external automations. Edit the Label field instead to change the user-facing text.', 'ffcertificate' ) . '</p></td></tr>';

		echo '<tr><th><label for="ffc-reason-edit-label">' . esc_html__( 'Label', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-reason-edit-label" type="text" class="regular-text" name="label" value="' . esc_attr( (string) $reason->label ) . '" required></td></tr>';

		echo '<tr><th><label for="ffc-reason-edit-color">' . esc_html__( 'Badge color', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-reason-edit-color" type="color" name="color" value="' . esc_attr( $color ) . '">';
		echo ' <code class="ffc-rec-ml-half">' . esc_html( $color ) . '</code></td></tr>';

		echo '<tr><th>' . esc_html__( 'Applies to', 'ffcertificate' ) . '</th><td>';
		echo '<div class="ffc-rec-flex-wrap">';
		foreach ( $applies_options as $key => $label ) {
			$id_attr = 'ffc-reason-edit-applies-' . $key;
			$checked = ! $is_applies_all && in_array( $key, $applies_to, true );
			\FreeFormCertificate\Admin\AdminUI::render_toggle(
				array(
					'name'    => 'applies_to[]',
					'id'      => $id_attr,
					'value'   => $key,
					'checked' => $checked,
					'label'   => $label,
				)
			);
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
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( self::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}
		$reason_id = isset( $_POST['reason_id'] ) ? absint( wp_unslash( (string) $_POST['reason_id'] ) ) : 0;
		check_admin_referer( 'ffc_recruitment_save_reason_' . $reason_id );

		if ( $reason_id <= 0 ) {
			wp_safe_redirect( self::back_url() );
			exit;
		}

		// Slug is locked after creation — see the rendered description.
		// Tampered $_POST['slug'] from a hostile or out-of-date client is
		// dropped at the boundary so the immutability holds even if the
		// disabled input somehow makes it back into the payload.
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
