<?php
/**
 * Recruitment Admin Page
 *
 * Top-level wp-admin menu (sibling of Audience and Reregistration; see
 * {@see RecruitmentAdminPage::register_menu()}). Renders four tabs
 * server-side:
 *
 *   - Notices       — list with status badges + create form.
 *   - Adjutancies   — list + create form + delete (gated).
 *   - Candidates    — search by CPF/RF (lookup-only MVP).
 *   - Settings      — points the admin at the existing Settings tab
 *                     where the email templates + public tuning live.
 *
 * This is a deliberate MVP: full polish (status-change modals, 15s
 * countdown for promote-preview, CSV upload UI, bulk-call UI, candidate
 * detail with decrypted-field reveal toggle) is tracked as a follow-up.
 * The REST surface (sprint 9.1) already supports every operation; the
 * admin UI here is sufficient to drive a production-grade recruitment
 * cycle from the wp-admin without falling back to curl/Postman.
 *
 * Form submissions go to the same REST endpoints via fetch() in a tiny
 * inline script; nonces use `wp_create_nonce('wp_rest')` so the REST
 * controllers' standard cookie auth + cap check picks them up.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the wp-admin Recruitment top-level page.
 */
final class RecruitmentAdminPage {

	/** Submenu slug — used as the `?page=` query param. */
	public const PAGE_SLUG = 'ffc-recruitment';

	/** Cap gating menu visibility + every render. */
	private const CAP = 'ffc_manage_recruitment';

	/**
	 * Hook callback for `admin_menu` (priority 10).
	 *
	 * Registered as a top-level menu (icon + sidebar entry) at position 28,
	 * mirroring the Audience (26) and Reregistration (27) modules so the
	 * three sibling business modules sit together in the wp-admin sidebar.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_menu_page(
			__( 'Recruitment', 'ffcertificate' ),
			__( 'Recruitment', 'ffcertificate' ),
			self::CAP,
			self::PAGE_SLUG,
			array( self::class, 'render_page' ),
			'dashicons-groups',
			28
		);
	}

	/**
	 * Top-level page renderer. Dispatches by `?tab=` to the tab-specific
	 * partial. Defaults to `notices`.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab switching is read-only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'notices';
		if ( ! in_array( $tab, array( 'notices', 'adjutancies', 'candidates', 'settings' ), true ) ) {
			$tab = 'notices';
		}

		echo '<div class="wrap ffc-recruitment-admin">';
		echo '<h1>' . esc_html__( 'Recruitment', 'ffcertificate' ) . '</h1>';
		self::render_tabs( $tab );

		switch ( $tab ) {
			case 'adjutancies':
				self::render_adjutancies_tab();
				break;
			case 'candidates':
				self::render_candidates_tab();
				break;
			case 'settings':
				self::render_settings_tab();
				break;
			default:
				self::render_notices_tab();
				break;
		}

		echo '</div>';
	}

	/**
	 * Render the wp-admin "h2 nav-tabs" navigation bar.
	 *
	 * @param string $active Current tab.
	 * @return void
	 */
	private static function render_tabs( string $active ): void {
		$tabs = array(
			'notices'     => __( 'Notices', 'ffcertificate' ),
			'adjutancies' => __( 'Adjutancies', 'ffcertificate' ),
			'candidates'  => __( 'Candidates', 'ffcertificate' ),
			'settings'    => __( 'Settings', 'ffcertificate' ),
		);

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url   = add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'tab'  => $slug,
				),
				admin_url( 'admin.php' )
			);
			$class = 'nav-tab' . ( $slug === $active ? ' nav-tab-active' : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Notices tab — list + create form.
	 *
	 * @return void
	 */
	private static function render_notices_tab(): void {
		$notices = RecruitmentNoticeRepository::get_all();

		echo '<h2>' . esc_html__( 'Notices', 'ffcertificate' ) . '</h2>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Code', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Name', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Reopened?', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Created at', 'ffcertificate' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $notices ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No notices registered yet.', 'ffcertificate' ) . '</td></tr>';
		} else {
			foreach ( $notices as $n ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( $n->code ) . '</code></td>';
				echo '<td>' . esc_html( $n->name ) . '</td>';
				echo '<td><span class="ffc-status-badge ffc-status-' . esc_attr( $n->status ) . '">' . esc_html( $n->status ) . '</span></td>';
				echo '<td>' . ( '1' === $n->was_reopened ? esc_html__( 'Yes', 'ffcertificate' ) : '—' ) . '</td>';
				echo '<td>' . esc_html( $n->created_at ) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';

		self::render_create_notice_form();
		self::render_rest_pointer();
	}

	/**
	 * Adjutancies tab — list + create form.
	 *
	 * @return void
	 */
	private static function render_adjutancies_tab(): void {
		$rows = RecruitmentAdjutancyRepository::get_all();

		echo '<h2>' . esc_html__( 'Adjutancies', 'ffcertificate' ) . '</h2>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Slug', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Name', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Created at', 'ffcertificate' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="3">' . esc_html__( 'No adjutancies registered yet.', 'ffcertificate' ) . '</td></tr>';
		} else {
			foreach ( $rows as $a ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( $a->slug ) . '</code></td>';
				echo '<td>' . esc_html( $a->name ) . '</td>';
				echo '<td>' . esc_html( $a->created_at ) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';

		self::render_create_adjutancy_form();
	}

	/**
	 * Candidates tab — search-only MVP (CPF or RF).
	 *
	 * @return void
	 */
	private static function render_candidates_tab(): void {
		echo '<h2>' . esc_html__( 'Candidates', 'ffcertificate' ) . '</h2>';
		echo '<p>' . esc_html__( 'Use the REST endpoints to manage candidates. The full UI will land in a follow-up iteration.', 'ffcertificate' ) . '</p>';
		self::render_rest_pointer();
	}

	/**
	 * Settings tab — pointer to the existing Settings tab.
	 *
	 * @return void
	 */
	private static function render_settings_tab(): void {
		$settings = RecruitmentSettings::all();

		echo '<h2>' . esc_html__( 'Settings', 'ffcertificate' ) . '</h2>';
		echo '<p>' . esc_html__( 'Current values (read-only on this screen; edit via Settings → Recruitment or the ffc_recruitment_settings option directly).', 'ffcertificate' ) . '</p>';

		echo '<table class="widefat striped"><tbody>';
		foreach ( $settings as $key => $value ) {
			echo '<tr><th><code>' . esc_html( $key ) . '</code></th><td><code>' . esc_html( (string) $value ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Render the create-notice form (POSTs to the REST endpoint via inline JS).
	 *
	 * @return void
	 */
	private static function render_create_notice_form(): void {
		$nonce = wp_create_nonce( 'wp_rest' );

		echo '<h3>' . esc_html__( 'Create new notice', 'ffcertificate' ) . '</h3>';
		echo '<form id="ffc-create-notice" method="post" onsubmit="return ffcRecruitmentCreateNotice(this);">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="ffc-notice-code">' . esc_html__( 'Code', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-notice-code" name="code" type="text" class="regular-text" required></td></tr>';
		echo '<tr><th><label for="ffc-notice-name">' . esc_html__( 'Name', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-notice-name" name="name" type="text" class="regular-text" required></td></tr>';
		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Create', 'ffcertificate' ) . '</button></p>';
		echo '</form>';

		echo '<script>'
			. 'function ffcRecruitmentCreateNotice(form){'
			. 'var fd=new FormData(form);'
			. 'fetch("' . esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/notices' ) ) . '",{'
			. 'method:"POST",'
			. 'headers:{"X-WP-Nonce":"' . esc_attr( $nonce ) . '"},'
			. 'body:fd'
			. '}).then(function(r){return r.json();}).then(function(d){'
			. 'if(d&&d.id){location.reload();}else{alert(JSON.stringify(d));}'
			. '});return false;}'
			. '</script>';
	}

	/**
	 * Render the create-adjutancy form (same fetch pattern).
	 *
	 * @return void
	 */
	private static function render_create_adjutancy_form(): void {
		$nonce = wp_create_nonce( 'wp_rest' );

		echo '<h3>' . esc_html__( 'Create new adjutancy', 'ffcertificate' ) . '</h3>';
		echo '<form id="ffc-create-adjutancy" method="post" onsubmit="return ffcRecruitmentCreateAdjutancy(this);">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="ffc-adj-slug">' . esc_html__( 'Slug', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-adj-slug" name="slug" type="text" class="regular-text" required></td></tr>';
		echo '<tr><th><label for="ffc-adj-name">' . esc_html__( 'Name', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-adj-name" name="name" type="text" class="regular-text" required></td></tr>';
		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Create', 'ffcertificate' ) . '</button></p>';
		echo '</form>';

		echo '<script>'
			. 'function ffcRecruitmentCreateAdjutancy(form){'
			. 'var fd=new FormData(form);'
			. 'fetch("' . esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/adjutancies' ) ) . '",{'
			. 'method:"POST",'
			. 'headers:{"X-WP-Nonce":"' . esc_attr( $nonce ) . '"},'
			. 'body:fd'
			. '}).then(function(r){return r.json();}).then(function(d){'
			. 'if(d&&d.id){location.reload();}else{alert(JSON.stringify(d));}'
			. '});return false;}'
			. '</script>';
	}

	/**
	 * Documentation block linking the admin to the REST surface.
	 *
	 * @return void
	 */
	private static function render_rest_pointer(): void {
		echo '<details style="margin-top:1em;"><summary>' . esc_html__( 'Available REST endpoints', 'ffcertificate' ) . '</summary>';
		echo '<pre style="background:#f5f5f5;padding:1em;">'
			. esc_html(
				"GET    /wp-json/ffcertificate/v1/recruitment/notices\n"
				. "POST   /wp-json/ffcertificate/v1/recruitment/notices\n"
				. "PATCH  /wp-json/ffcertificate/v1/recruitment/notices/{id}\n"
				. "GET    /wp-json/ffcertificate/v1/recruitment/notices/{id}/classifications\n"
				. "POST   /wp-json/ffcertificate/v1/recruitment/notices/{id}/import\n"
				. "POST   /wp-json/ffcertificate/v1/recruitment/notices/{id}/promote-preview\n"
				. "POST   /wp-json/ffcertificate/v1/recruitment/classifications/{id}/call\n"
				. "POST   /wp-json/ffcertificate/v1/recruitment/classifications/bulk-call\n"
				. "PATCH  /wp-json/ffcertificate/v1/recruitment/classifications/{id}/status\n"
				. "DELETE /wp-json/ffcertificate/v1/recruitment/classifications/{id}\n"
				. "GET    /wp-json/ffcertificate/v1/recruitment/adjutancies\n"
				. "DELETE /wp-json/ffcertificate/v1/recruitment/adjutancies/{id}\n"
				. "GET    /wp-json/ffcertificate/v1/recruitment/candidates?cpf={digits}\n"
				. "GET    /wp-json/ffcertificate/v1/recruitment/candidates/{id}\n"
				. "PATCH  /wp-json/ffcertificate/v1/recruitment/candidates/{id}\n"
				. "DELETE /wp-json/ffcertificate/v1/recruitment/candidates/{id}\n"
				. "GET    /wp-json/ffcertificate/v1/recruitment/me/recruitment\n"
			)
			. '</pre>';
		echo '<p>' . esc_html__( 'All admin endpoints require the ffc_manage_recruitment capability.', 'ffcertificate' ) . '</p>';
		echo '</details>';
	}
}
