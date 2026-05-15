<?php
/**
 * Recruitment Adjutancy Edit Page.
 *
 * Dedicated wp-admin screen reached via the "Edit" row action on the
 * Adjutancies list table:
 *
 *   admin.php?page=ffc-recruitment&tab=adjutancies&action=edit-adjutancy&adjutancy_id=N
 *
 * Adjutancies are catalog rows referenced by both `notice_adjutancy`
 * (notice ↔ adjutancy join) and `classification.adjutancy_id`. Editing
 * is purely about the catalog row's own attributes — slug / name /
 * color — and nothing on this screen cascades into those FK consumers,
 * which keep pointing at the same `id`.
 *
 * Mirrors {@see RecruitmentReasonEditPage} structurally.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.5.7
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adjutancy edit screen renderer + admin-post save handler.
 *
 * @phpstan-import-type AdjutancyRow from RecruitmentAdjutancyRepository
 */
final class RecruitmentAdjutancyEditPage {

	private const CAP = 'ffc_manage_recruitment';

	/**
	 * Hook the admin-post save endpoint.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_ffc_recruitment_save_adjutancy', array( self::class, 'handle_save' ), 10 );
	}

	/**
	 * Render the edit screen body. Called by RecruitmentAdminPage when
	 * `?action=edit-adjutancy` is detected on the page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render.
		$adjutancy_id = isset( $_GET['adjutancy_id'] ) ? absint( wp_unslash( (string) $_GET['adjutancy_id'] ) ) : 0;
		$adjutancy    = $adjutancy_id > 0 ? RecruitmentAdjutancyRepository::get_by_id( $adjutancy_id ) : null;

		if ( null === $adjutancy ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Adjutancy not found.', 'ffcertificate' ) . '</p></div>';
			echo '<p><a href="' . esc_url( self::back_url() ) . '">&larr; ' . esc_html__( 'Back to Adjutancies', 'ffcertificate' ) . '</a></p>';
			return;
		}

		echo '<p><a href="' . esc_url( self::back_url() ) . '">&larr; ' . esc_html__( 'Back to Adjutancies', 'ffcertificate' ) . '</a></p>';
		echo '<h2>' . sprintf(
			/* translators: %s — adjutancy name */
			esc_html__( 'Edit adjutancy — %s', 'ffcertificate' ),
			esc_html( (string) $adjutancy->name )
		) . '</h2>';

		self::render_general_section( $adjutancy );
	}

	/**
	 * Section: General editable fields (slug, name, color).
	 *
	 * @param object $adjutancy Adjutancy row.
	 * @phpstan-param AdjutancyRow $adjutancy
	 * @return void
	 */
	private static function render_general_section( object $adjutancy ): void {
		$id           = (int) $adjutancy->id;
		$nonce_action = 'ffc_recruitment_save_adjutancy_' . $id;
		$color        = isset( $adjutancy->color ) && '' !== (string) $adjutancy->color
			? (string) $adjutancy->color
			: RecruitmentAdjutancyRepository::DEFAULT_COLOR;

		echo '<div class="postbox" style="margin-top:20px;">';
		echo '<h2 class="hndle"><span>' . esc_html__( 'General', 'ffcertificate' ) . '</span></h2>';
		echo '<div class="inside">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ffc_recruitment_save_adjutancy">';
		echo '<input type="hidden" name="adjutancy_id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( $nonce_action );

		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="ffc-adjutancy-edit-slug">' . esc_html__( 'Slug', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-adjutancy-edit-slug" type="text" class="regular-text" name="slug" value="' . esc_attr( (string) $adjutancy->slug ) . '" required>';
		echo '<p class="description">' . esc_html__( 'Unique identifier (slug-shaped). Must be unique across the catalog. Existing notices and classifications keep their reference because they link by id, not by slug.', 'ffcertificate' ) . '</p></td></tr>';

		echo '<tr><th><label for="ffc-adjutancy-edit-name">' . esc_html__( 'Name', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-adjutancy-edit-name" type="text" class="regular-text" name="name" value="' . esc_attr( (string) $adjutancy->name ) . '" required></td></tr>';

		echo '<tr><th><label for="ffc-adjutancy-edit-color">' . esc_html__( 'Badge color', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-adjutancy-edit-color" type="color" name="color" value="' . esc_attr( $color ) . '">';
		echo ' <code style="margin-left:.5em;">' . esc_html( $color ) . '</code>';
		echo '<p class="description">' . esc_html__( 'Background color for the badge on the public shortcode. Accepts #RGB / #RRGGBB / #RRGGBBAA. Bad values fall back to the default.', 'ffcertificate' ) . '</p></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save adjutancy', 'ffcertificate' ) );
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
		$adjutancy_id = isset( $_POST['adjutancy_id'] ) ? absint( wp_unslash( (string) $_POST['adjutancy_id'] ) ) : 0;
		check_admin_referer( 'ffc_recruitment_save_adjutancy_' . $adjutancy_id );

		if ( $adjutancy_id <= 0 ) {
			wp_safe_redirect( self::back_url() );
			exit;
		}

		$slug  = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( (string) $_POST['slug'] ) ) : '';
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
		$color = isset( $_POST['color'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['color'] ) ) : '';

		// Guard against silent UNIQUE-constraint failure: if the new slug
		// is already used by a different adjutancy row, surface a flash
		// error instead of letting wpdb->update return false and pretend
		// nothing happened.
		if ( '' !== $slug ) {
			$existing = RecruitmentAdjutancyRepository::get_by_slug( $slug );
			if ( null !== $existing && (int) $existing->id !== $adjutancy_id ) {
				wp_safe_redirect( self::edit_url( $adjutancy_id, 'slug-taken' ) );
				exit;
			}
		}

		$ok = RecruitmentAdjutancyRepository::update(
			$adjutancy_id,
			array(
				'slug'  => $slug,
				'name'  => $name,
				'color' => $color,
			)
		);

		wp_safe_redirect( self::edit_url( $adjutancy_id, $ok ? 'saved' : 'save-failed' ) );
		exit;
	}

	/**
	 * Build the back-to-adjutancies-list URL.
	 *
	 * @return string
	 */
	private static function back_url(): string {
		return add_query_arg(
			array(
				'page' => RecruitmentAdminPage::PAGE_SLUG,
				'tab'  => 'adjutancies',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build the edit-adjutancy URL with an optional flash message key.
	 *
	 * @param int    $adjutancy_id Adjutancy ID.
	 * @param string $msg          Flash message key (echoed via `?ffc_msg=`).
	 * @return string
	 */
	private static function edit_url( int $adjutancy_id, string $msg = '' ): string {
		$args = array(
			'page'         => RecruitmentAdminPage::PAGE_SLUG,
			'tab'          => 'adjutancies',
			'action'       => 'edit-adjutancy',
			'adjutancy_id' => $adjutancy_id,
		);
		if ( '' !== $msg ) {
			$args['ffc_msg'] = $msg;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
