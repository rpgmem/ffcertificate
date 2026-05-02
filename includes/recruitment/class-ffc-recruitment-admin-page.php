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

		// Action dispatcher — row actions / GET-link operations land here
		// before the default tab render runs. Each action validates its
		// own nonce and short-circuits with `wp_safe_redirect` so the
		// page reloads onto the canonical tab URL.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Each action runs `check_admin_referer`.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';
		if ( '' !== $action ) {
			self::dispatch_action( $action );
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
		echo '<h2>' . esc_html__( 'Notices', 'ffcertificate' ) . '</h2>';

		$table = new RecruitmentNoticesListTable();
		$table->prepare_items();

		// Search box + form wrapper so WP's bulk-action + sort + search
		// query params are preserved on submit.
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
		$table->search_box( __( 'Search notices', 'ffcertificate' ), 'ffc-recruitment-notices' );
		$table->display();
		echo '</form>';

		self::render_create_notice_form();
		self::render_rest_pointer();
	}

	/**
	 * Dispatch a `?action=…` GET-link operation triggered from a row
	 * action in the list tables. Each branch validates its own nonce
	 * via `check_admin_referer` and short-circuits with `wp_safe_redirect`
	 * back to the canonical tab so the URL stays clean.
	 *
	 * Sprint A1 ships only `delete-notice`. Sprint B adds the rest of
	 * the row actions (edit-notice, status transitions, promote, etc.).
	 *
	 * @param string $action Sanitized action key from the request.
	 * @return void
	 */
	private static function dispatch_action( string $action ): void {
		switch ( $action ) {
			case 'delete-notice':
				$id = isset( $_GET['notice_id'] ) ? absint( wp_unslash( (string) $_GET['notice_id'] ) ) : 0;
				if ( $id > 0 ) {
					check_admin_referer( 'ffc_recruitment_delete_notice_' . $id );
					RecruitmentNoticeRepository::delete( $id );
				}
				wp_safe_redirect(
					add_query_arg(
						array(
							'page' => self::PAGE_SLUG,
							'tab'  => 'notices',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;

			case 'delete-adjutancy':
				$id = isset( $_GET['adjutancy_id'] ) ? absint( wp_unslash( (string) $_GET['adjutancy_id'] ) ) : 0;
				if ( $id > 0 ) {
					check_admin_referer( 'ffc_recruitment_delete_adjutancy_' . $id );
					// DeleteService gates on §14: rejects when notice_adjutancy
					// or classification rows reference this adjutancy. The
					// envelope's success flag is opaque from the redirect path
					// (UI lands in sprint B's edit screen with proper feedback).
					RecruitmentDeleteService::delete_adjutancy( $id );
				}
				wp_safe_redirect(
					add_query_arg(
						array(
							'page' => self::PAGE_SLUG,
							'tab'  => 'adjutancies',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;

			case 'delete-candidate':
				$id = isset( $_GET['candidate_id'] ) ? absint( wp_unslash( (string) $_GET['candidate_id'] ) ) : 0;
				if ( $id > 0 ) {
					check_admin_referer( 'ffc_recruitment_delete_candidate_' . $id );
					// DeleteService gates on §7-bis: zero classifications.
					// The reason-collection UI lives on sprint C's edit screen.
					RecruitmentDeleteService::delete_candidate( $id );
				}
				wp_safe_redirect(
					add_query_arg(
						array(
							'page' => self::PAGE_SLUG,
							'tab'  => 'candidates',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
		}
	}

	/**
	 * Adjutancies tab — list + create form.
	 *
	 * @return void
	 */
	private static function render_adjutancies_tab(): void {
		echo '<h2>' . esc_html__( 'Adjutancies', 'ffcertificate' ) . '</h2>';

		$table = new RecruitmentAdjutanciesListTable();
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
		echo '<input type="hidden" name="tab" value="adjutancies">';
		$table->search_box( __( 'Search adjutancies', 'ffcertificate' ), 'ffc-recruitment-adjutancies' );
		$table->display();
		echo '</form>';

		self::render_create_adjutancy_form();
	}

	/**
	 * Candidates tab — CSV import flow.
	 *
	 * Admin selects the target notice, picks a CSV file, and the form POSTs
	 * via fetch() to `/notices/{id}/import`. The endpoint writes to
	 * `list_type='preview'` and is gated to notices in `draft` or
	 * `preliminary` (per §5.1 of the plan); already-active notices reject
	 * the import. The full per-candidate UI (search, edit, hard-delete)
	 * stays as a §7-bis follow-up — for the operator-facing CSV import
	 * flow this is the canonical entry point.
	 *
	 * @return void
	 */
	private static function render_candidates_tab(): void {
		echo '<h2>' . esc_html__( 'Candidates', 'ffcertificate' ) . '</h2>';

		$table = new RecruitmentCandidatesListTable();
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
		echo '<input type="hidden" name="tab" value="candidates">';
		$table->search_box( __( 'Search by name', 'ffcertificate' ), 'ffc-recruitment-candidates' );
		$table->display();
		echo '</form>';

		echo '<hr style="margin:2em 0;">';
		self::render_csv_import_form();
	}

	/**
	 * Render the CSV import form (multipart) targeting a chosen notice.
	 *
	 * Lists all notices in `draft`/`preliminary` (the only states where
	 * preview-list import is accepted). On submit, POSTs the multipart
	 * payload to `/notices/{id}/import` with `X-WP-Nonce` cookie auth.
	 *
	 * @return void
	 */
	private static function render_csv_import_form(): void {
		$notices = RecruitmentNoticeRepository::get_all();

		// Only notices in draft/preliminary accept new preview imports
		// (per §5.1 of the plan). Filtering them in the dropdown avoids
		// the user picking an active/closed notice and getting a 409.
		$importable = array_values(
			array_filter(
				$notices,
				static function ( $n ): bool {
					return in_array( (string) $n->status, array( 'draft', 'preliminary' ), true );
				}
			)
		);

		echo '<h3>' . esc_html__( 'Import CSV', 'ffcertificate' ) . '</h3>';

		if ( empty( $importable ) ) {
			echo '<p>' . esc_html__( 'No notices in draft or preliminary state. Create a notice (Notices tab) before importing candidates.', 'ffcertificate' ) . '</p>';
			return;
		}

		$nonce = wp_create_nonce( 'wp_rest' );

		echo '<form id="ffc-recruitment-csv-import" method="post" enctype="multipart/form-data" onsubmit="return ffcRecruitmentImportCsv(this);">';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="ffc-csv-notice">' . esc_html__( 'Notice', 'ffcertificate' ) . '</label></th><td>';
		echo '<select id="ffc-csv-notice" name="notice_id" required>';
		foreach ( $importable as $n ) {
			$label = sprintf( '%s — %s (%s)', (string) $n->code, (string) $n->name, (string) $n->status );
			echo '<option value="' . esc_attr( (string) $n->id ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '</td></tr>';

		echo '<tr><th><label for="ffc-csv-file">' . esc_html__( 'CSV file', 'ffcertificate' ) . '</label></th><td>';
		echo '<input id="ffc-csv-file" name="csv_file" type="file" accept=".csv,text/csv" required>';
		echo '<p class="description">' . esc_html__( 'UTF-8 (BOM optional). Headers (English): name, cpf, rf, email, phone, adjutancy, rank, score, pcd. At least one of cpf/rf required per row.', 'ffcertificate' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Import', 'ffcertificate' ) . '</button> ';
		echo '<span id="ffc-csv-status" style="margin-left:1em;"></span></p>';
		echo '</form>';

		// Inline fetch handler — same pattern as the create-notice / create-adjutancy forms above.
		echo '<script>'
			. 'function ffcRecruitmentImportCsv(form){'
			. 'var noticeId=form.notice_id.value;'
			. 'var fd=new FormData();'
			. 'fd.append("csv_file",form.csv_file.files[0]);'
			. 'var status=document.getElementById("ffc-csv-status");'
			. 'status.textContent="…";'
			. 'fetch("' . esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/notices/' ) ) . '"+noticeId+"/import",{'
			. 'method:"POST",'
			. 'headers:{"X-WP-Nonce":"' . esc_attr( $nonce ) . '"},'
			. 'body:fd,'
			. 'credentials:"same-origin"'
			. '}).then(function(r){return r.json().then(function(d){return{status:r.status,body:d};});}).then(function(o){'
			. 'if(o.status>=200&&o.status<300){status.textContent="OK ("+JSON.stringify(o.body)+")";}'
			. 'else{status.textContent="Error: "+JSON.stringify(o.body);}'
			. '}).catch(function(e){status.textContent="Network error: "+e.message;});'
			. 'return false;}'
			. '</script>';
	}

	/**
	 * Settings tab — editable form backed by the WP Settings API.
	 *
	 * Posts to `options.php` with `settings_fields(OPTION_GROUP)` so the
	 * registered `sanitize` callback runs on save. Settings panel is
	 * gated by the same `ffc_manage_recruitment` cap as the rest of the
	 * page (enforced at render_page() entry).
	 *
	 * @return void
	 */
	private static function render_settings_tab(): void {
		$settings = RecruitmentSettings::all();

		echo '<h2>' . esc_html__( 'Settings', 'ffcertificate' ) . '</h2>';
		echo '<p>' . esc_html__( 'Email templates and public shortcode tuning. Saved values populate the convocation email and the public shortcode cache/rate-limit/page-size knobs.', 'ffcertificate' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( RecruitmentSettings::OPTION_GROUP );

		$opt = RecruitmentSettings::OPTION_NAME;

		echo '<h3>' . esc_html__( 'Email template', 'ffcertificate' ) . '</h3>';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="ffc-rs-subject">' . esc_html__( 'Subject', 'ffcertificate' ) . '</label></th><td>';
		echo '<input id="ffc-rs-subject" type="text" class="large-text" name="' . esc_attr( $opt ) . '[email_subject]" value="' . esc_attr( (string) $settings['email_subject'] ) . '">';
		echo '<p class="description">' . esc_html__( 'Placeholders: {{notice_code}}, {{notice_name}}, {{adjutancy}}, {{name}}, {{rank}}, {{score}}, {{date_to_assume}}, {{time_to_assume}}, {{is_pcd}}, {{site_name}}, {{site_url}}, {{notes}}, and the masked variants {{cpf_masked}}, {{rf_masked}}, {{email_masked}}.', 'ffcertificate' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th><label for="ffc-rs-from-address">' . esc_html__( 'From address', 'ffcertificate' ) . '</label></th><td>';
		echo '<input id="ffc-rs-from-address" type="email" class="regular-text" name="' . esc_attr( $opt ) . '[email_from_address]" value="' . esc_attr( (string) $settings['email_from_address'] ) . '" placeholder="(falls back to wp_mail default)">';
		echo '</td></tr>';

		echo '<tr><th><label for="ffc-rs-from-name">' . esc_html__( 'From name', 'ffcertificate' ) . '</label></th><td>';
		echo '<input id="ffc-rs-from-name" type="text" class="regular-text" name="' . esc_attr( $opt ) . '[email_from_name]" value="' . esc_attr( (string) $settings['email_from_name'] ) . '" placeholder="(falls back to site name)">';
		echo '</td></tr>';

		echo '<tr><th><label for="ffc-rs-body">' . esc_html__( 'Body (HTML)', 'ffcertificate' ) . '</label></th><td>';
		echo '<textarea id="ffc-rs-body" name="' . esc_attr( $opt ) . '[email_body_html]" rows="12" class="large-text code">' . esc_textarea( (string) $settings['email_body_html'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Same placeholder set as the subject. The text/plain alternative is auto-derived via wp_strip_all_tags.', 'ffcertificate' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Public shortcode', 'ffcertificate' ) . '</h3>';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="ffc-rs-cache">' . esc_html__( 'Cache TTL (seconds)', 'ffcertificate' ) . '</label></th><td>';
		echo '<input id="ffc-rs-cache" type="number" min="0" name="' . esc_attr( $opt ) . '[public_cache_seconds]" value="' . esc_attr( (string) $settings['public_cache_seconds'] ) . '">';
		echo '<p class="description">' . esc_html__( 'Transient cache for the public shortcode. 0 disables.', 'ffcertificate' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th><label for="ffc-rs-rate">' . esc_html__( 'Rate limit (requests / minute / IP)', 'ffcertificate' ) . '</label></th><td>';
		echo '<input id="ffc-rs-rate" type="number" min="0" name="' . esc_attr( $opt ) . '[public_rate_limit_per_minute]" value="' . esc_attr( (string) $settings['public_rate_limit_per_minute'] ) . '">';
		echo '<p class="description">' . esc_html__( '0 disables the per-IP rate limit.', 'ffcertificate' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th><label for="ffc-rs-pagesize">' . esc_html__( 'Default page size', 'ffcertificate' ) . '</label></th><td>';
		echo '<input id="ffc-rs-pagesize" type="number" min="1" max="500" name="' . esc_attr( $opt ) . '[public_default_page_size]" value="' . esc_attr( (string) $settings['public_default_page_size'] ) . '">';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button();
		echo '</form>';
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
