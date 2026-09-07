<?php
/**
 * FormEditorSaveHandler
 *
 * Handles saving and validation of form data.
 * Extracted from FFC_Form_Editor class to follow Single Responsibility Principle.
 *
 * @package FreeFormCertificate\Admin
 * @since 3.2.0 (Extracted from FFC_Form_Editor)
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\ArrayValue;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for form editor save operations.
 */
class FormEditorSaveHandler {

	/**
	 * Minimum length (characters) required for each geofence block /
	 * error message when its parent restriction is enabled.
	 *
	 * Empty or too-short messages produced silent frontend failures: a
	 * legitimate date/time or geolocation block returned an empty
	 * `message`, which ffc-core.js (`err.fromServer = !!serverMsg`)
	 * treated as "no server message" and surfaced as a generic
	 * "Connection error" — the user saw nothing actionable. The runtime
	 * fallback in {@see \FreeFormCertificate\Security\Geofence::message_or_default()}
	 * guarantees a non-empty string at render time; this save-side gate
	 * pushes the requirement upstream so operators author a meaningful
	 * message instead of relying on the generic fallback. Tune here.
	 */
	public const GEOFENCE_MESSAGE_MIN_LENGTH = FormEditorSaveValidator::GEOFENCE_MESSAGE_MIN_LENGTH;

	/**
	 * Validation cluster (pure helpers extracted in #591 phase-3).
	 *
	 * @var FormEditorSaveValidator|null
	 */
	private ?FormEditorSaveValidator $validator = null;

	/**
	 * Lazily resolve the validation helper.
	 *
	 * @return FormEditorSaveValidator
	 */
	private function validator(): FormEditorSaveValidator {
		if ( null === $this->validator ) {
			$this->validator = new FormEditorSaveValidator();
		}
		return $this->validator;
	}

	/**
	 * Saves all form data and configurations
	 *
	 * @param int $post_id The post ID.
	 */
	public function save_form_data( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! wp_verify_nonce( RequestInput::get_post_string( 'ffc_form_nonce' ), 'ffc_save_form_data' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$this->reset_operator_one_shot_meta( $post_id );

		// 1. Save Form Fields.
		$this->save_fields_meta( $post_id );

		// 2. Save Configurations.
		$this->save_config_meta( $post_id );

		// 3. Save Geofence Configuration.
		$this->save_geofence_meta( $post_id );

		// 4. Save Public CSV Download configuration.
		$this->save_csv_public_meta( $post_id );

		// 5. Save Device Fingerprint Limit override.
		$this->save_device_limit_meta( $post_id );
	}

	/**
	 * Reset the public-operator one-shot guards.
	 *
	 * Both `ExtendEndAction` and `EarlyOpenAction` persist a pair
	 * of metas the first time their respective action fires:
	 * - `META_POSTPONED_AT` / `META_POSTPONED_FROM` (extend end)
	 * - `META_OPENED_AT`    / `META_OPENED_FROM`    (early open)
	 * — after which `is_eligible()` returns `already_postponed` /
	 * `already_opened` and the frontend button disappears.
	 *
	 * An admin edit of the form is the natural cycle boundary —
	 * whatever the admin is now configuring supersedes the prior
	 * operator state, so we wipe both pairs and let the operator
	 * postpone / advance again within the new window.
	 *
	 * @param int $post_id The post ID.
	 */
	private function reset_operator_one_shot_meta( int $post_id ): void {
		delete_post_meta( $post_id, \FreeFormCertificate\Frontend\ExtendEndAction::META_POSTPONED_AT );
		delete_post_meta( $post_id, \FreeFormCertificate\Frontend\ExtendEndAction::META_POSTPONED_FROM );
		delete_post_meta( $post_id, \FreeFormCertificate\Frontend\EarlyOpenAction::META_OPENED_AT );
		delete_post_meta( $post_id, \FreeFormCertificate\Frontend\EarlyOpenAction::META_OPENED_FROM );
	}

	/**
	 * Persist the form-fields meta (`_ffc_form_fields`).
	 *
	 * @param int $post_id The post ID.
	 */
	private function save_fields_meta( int $post_id ): void {
		$rows = RequestInput::get_post_raw_array( 'ffc_fields' );
		if ( empty( $rows ) ) {
			update_post_meta( $post_id, '_ffc_form_fields', array() );
			return;
		}

		$clean_fields = array();
		foreach ( $rows as $index => $field ) {
			// A row that is not an array is not a row. The `empty()` chain
			// below already skipped it — `empty( $string['label'] )` is
			// silently true for an illegal offset — so this guard changes
			// nothing at runtime; it states the precondition the rest of
			// the body relies on instead of leaving it to that accident,
			// and it is what lets the reads below be typed (#1084).
			if ( 'TEMPLATE' === $index || ! is_array( $field ) ) {
				continue;
			}
			if ( empty( $field['label'] ) && empty( $field['name'] ) && empty( $field['content'] ) && empty( $field['embed_url'] ) ) {
				continue;
			}

			$clean_fields[] = array(
				'label'     => sanitize_text_field( ArrayValue::string( $field, 'label' ) ),
				'name'      => sanitize_key( ArrayValue::string( $field, 'name' ) ),
				'type'      => sanitize_key( ArrayValue::string( $field, 'type' ) ),
				'required'  => isset( $field['required'] ) ? '1' : '',
				'options'   => sanitize_text_field( ArrayValue::string( $field, 'options' ) ),
				'content'   => wp_kses_post( ArrayValue::string( $field, 'content' ) ),
				'embed_url' => esc_url_raw( ArrayValue::string( $field, 'embed_url' ) ),
				'points'    => sanitize_text_field( ArrayValue::string( $field, 'points' ) ),
			);
		}
		update_post_meta( $post_id, '_ffc_form_fields', $clean_fields );
	}

	/**
	 * Persist the form configuration meta (`_ffc_form_config`).
	 *
	 * @param int $post_id The post ID.
	 */
	private function save_config_meta( int $post_id ): void {
		if ( RequestInput::has_post( 'ffc_config' ) ) {
			$config       = RequestInput::get_post_raw_array( 'ffc_config' );
			$allowed_html = \FreeFormCertificate\Core\HtmlPolicy::get_allowed_html_tags();

			// Form essentials — not gated by any toggle.
			$clean_config               = array();
			$clean_config['pdf_layout'] = wp_kses( ArrayValue::string( $config, 'pdf_layout' ), $allowed_html );
			$clean_config['bg_image']   = esc_url_raw( ArrayValue::string( $config, 'bg_image' ) );

			$clean_config['enable_restriction'] = sanitize_key( ArrayValue::string( $config, 'enable_restriction' ) );

			// Email — master toggle is `send_user_email`. Sub-options
			// (subject + body) are only written when the toggle is on, so
			// turning email off preserves the prior subject/body verbatim
			// for when the admin turns it back on (Sprint 2 / #238).
			$send_user_email                 = '1' === ArrayValue::string( $config, 'send_user_email' ) ? '1' : '0';
			$clean_config['send_user_email'] = $send_user_email;
			if ( '1' === $send_user_email ) {
				$clean_config['email_subject'] = sanitize_text_field( ArrayValue::string( $config, 'email_subject' ) );
				// Email body is authored in the teeny wp_editor; wp_kses_post()
				// is the canonical WordPress allowlist for post-like rich
				// content and already strips scripts/forms while keeping the
				// formatting users expect.
				$clean_config['email_body'] = wp_kses_post( ArrayValue::string( $config, 'email_body' ) );
			}

			// Admin notification — independent opt-in toggle (default off, #649).
			// The recipient list is only written when the toggle is on, so
			// turning it off preserves the prior recipients verbatim.
			$send_admin_email                 = '1' === ArrayValue::string( $config, 'send_admin_email' ) ? '1' : '0';
			$clean_config['send_admin_email'] = $send_admin_email;
			if ( '1' === $send_admin_email ) {
				$clean_config['email_admin'] = sanitize_text_field( ArrayValue::string( $config, 'email_admin' ) );
			}

			// Restrictions — 4 independent toggles. Each gates exactly one
			// data field; when its toggle is off, the corresponding field
			// is not written to $clean_config so the prior value rides
			// through `array_merge` at the end of this block.
			$posted_restrictions          = ArrayValue::array( $config, 'restrictions' );
			$restrictions                 = array(
				'password'  => isset( $posted_restrictions['password'] ) ? '1' : '0',
				'allowlist' => isset( $posted_restrictions['allowlist'] ) ? '1' : '0',
				'denylist'  => isset( $posted_restrictions['denylist'] ) ? '1' : '0',
				'ticket'    => isset( $posted_restrictions['ticket'] ) ? '1' : '0',
			);
			$clean_config['restrictions'] = $restrictions;

			if ( '1' === $restrictions['password'] ) {
				$clean_config['validation_code'] = sanitize_text_field( ArrayValue::string( $config, 'validation_code' ) );
			}
			if ( '1' === $restrictions['allowlist'] ) {
				$clean_config['allowed_users_list'] = sanitize_textarea_field( ArrayValue::string( $config, 'allowed_users_list' ) );
			}
			if ( '1' === $restrictions['denylist'] ) {
				$clean_config['denied_users_list'] = sanitize_textarea_field( ArrayValue::string( $config, 'denied_users_list' ) );
			}
			if ( '1' === $restrictions['ticket'] ) {
				$clean_config['generated_codes_list'] = sanitize_textarea_field( ArrayValue::string( $config, 'generated_codes_list' ) );
			}

			// Quiz / Evaluation Mode — 1 master toggle, 4 sub-options
			// (passing_score / max_attempts / show_score / show_correct).
			$quiz_enabled                 = isset( $config['quiz_enabled'] ) ? '1' : '0';
			$clean_config['quiz_enabled'] = $quiz_enabled;
			if ( '1' === $quiz_enabled ) {
				$clean_config['quiz_passing_score'] = absint( ArrayValue::int( $config, 'quiz_passing_score', 70 ) );
				$clean_config['quiz_max_attempts']  = absint( ArrayValue::int( $config, 'quiz_max_attempts', 0 ) );
				$clean_config['quiz_show_score']    = isset( $config['quiz_show_score'] ) ? '1' : '0';
				$clean_config['quiz_show_correct']  = isset( $config['quiz_show_correct'] ) ? '1' : '0';
			}

			// Tag Validation (server-side backstop): warn if the layout is
			// missing any tag from the configurable required list. The
			// editor also blocks the submit client-side; this catches the
			// JS-disabled case. Non-blocking — the post still saves.
			$missing_tags = $this->validator()->missing_required_tags( $clean_config['pdf_layout'], $post_id );
			if ( ! empty( $missing_tags ) ) {
				set_transient( 'ffc_save_error_' . get_current_user_id(), $missing_tags, 45 );
			}

			// Legacy html/ reference linter (#865 Phase 4): warn — non-blocking —
			// when the saved layout or background URL still points at the
			// update-fragile plugin `html/` drop-folder, whose files a plugin
			// update or deploy deletes. Same detection the Phase 0 notice and the
			// Rewrite-html/-image-refs migration use.
			$html_refs = array_merge(
				\FreeFormCertificate\Core\LegacyHtmlRefs::find_urls( $clean_config['pdf_layout'] ),
				\FreeFormCertificate\Core\LegacyHtmlRefs::find_urls( $clean_config['bg_image'] )
			);
			if ( ! empty( $html_refs ) ) {
				set_transient( 'ffc_html_lint_' . get_current_user_id(), array_values( array_unique( $html_refs ) ), 45 );
			}

			$current_config = get_post_meta( $post_id, '_ffc_form_config', true );
			if ( ! is_array( $current_config ) ) {
				$current_config = array();
			}

			update_post_meta( $post_id, '_ffc_form_config', array_merge( $current_config, $clean_config ) );
		}
	}

	/**
	 * Persist the geofence configuration meta (`_ffc_geofence_config`).
	 *
	 * @param int $post_id The post ID.
	 */
	private function save_geofence_meta( int $post_id ): void {
		if ( RequestInput::has_post( 'ffc_geofence' ) ) {
			$geofence = RequestInput::get_post_raw_array( 'ffc_geofence' );

			// Skip-on-off per master toggle (Sprint 2 / #238). Each
			// $clean_geofence entry is only added when its gating toggle
			// is on; the final array_merge() at the bottom of this block
			// preserves any keys skipped here from the existing config.

			$datetime_enabled = isset( $geofence['datetime_enabled'] ) ? '1' : '0';
			$geo_enabled      = isset( $geofence['geo_enabled'] ) ? '1' : '0';
			$ip_permissive    = isset( $geofence['geo_ip_areas_permissive'] ) ? '1' : '0';

			$clean_geofence = array(
				'datetime_enabled'        => $datetime_enabled,
				'geo_enabled'             => $geo_enabled,
				'geo_ip_areas_permissive' => $ip_permissive,
			);

			// Schedule exception per submission (#366). Independent of
			// `datetime_enabled` — admin can offer the operator-driven
			// exception flow without imposing a hard date/time window.
			$clean_geofence = array_merge(
				$clean_geofence,
				self::sanitize_schedule_exception_config( $geofence )
			);

			// DateTime — 1 master toggle gating 9 sub-options.
			if ( '1' === $datetime_enabled ) {
				// Per-phase datetime hide modes (#159 S1). Each new key
				// falls back to the legacy single `datetime_hide_mode`
				// POSTed value while the UI is being migrated, so a save
				// under the old single-dropdown UI still produces a valid
				// three-key payload. The legacy key is no longer
				// persisted on save.
				$legacy_hide_mode = sanitize_key( ArrayValue::string( $geofence, 'datetime_hide_mode', 'message' ) );

				$clean_geofence['date_start']                = sanitize_text_field( ArrayValue::string( $geofence, 'date_start' ) );
				$clean_geofence['date_end']                  = sanitize_text_field( ArrayValue::string( $geofence, 'date_end' ) );
				$clean_geofence['time_start']                = sanitize_text_field( ArrayValue::string( $geofence, 'time_start' ) );
				$clean_geofence['time_end']                  = sanitize_text_field( ArrayValue::string( $geofence, 'time_end' ) );
				$clean_geofence['time_mode']                 = sanitize_key( ArrayValue::string( $geofence, 'time_mode', 'daily' ) );
				$clean_geofence['datetime_hide_mode_before'] = sanitize_key( ArrayValue::string( $geofence, 'datetime_hide_mode_before', $legacy_hide_mode ) );
				$clean_geofence['datetime_hide_mode_during'] = sanitize_key( ArrayValue::string( $geofence, 'datetime_hide_mode_during', $legacy_hide_mode ) );
				$clean_geofence['datetime_hide_mode_after']  = sanitize_key( ArrayValue::string( $geofence, 'datetime_hide_mode_after', $legacy_hide_mode ) );
				$clean_geofence['msg_datetime']              = sanitize_textarea_field( ArrayValue::string( $geofence, 'msg_datetime' ) );

				// Multi-day toggle (UX scope: hides the End Date / Time
				// Behavior / Display-during-slot controls when off). When
				// the operator unchecks it, mirror date_end onto date_start
				// so the runtime bounds the form to a single calendar day
				// (the hidden End Date input can't be edited, so a stale
				// range from a prior multi-day config must not linger).
				//
				// time_mode is left untouched: a single-day event with a
				// time window is exactly "daily mode, one day", which
				// validate_datetime handles directly. Forcing 'span' here
				// (an earlier approach) was both unnecessary — the
				// `date_start !== date_end` guard in validate_datetime
				// neutralises span the moment the dates are equal — and
				// surprising, so it's been dropped.
				$clean_geofence['multi_day'] = isset( $geofence['multi_day'] ) ? '1' : '0';
				if ( '0' === $clean_geofence['multi_day'] ) {
					$clean_geofence['date_end'] = $clean_geofence['date_start'];
				}
			}

			// Geolocation — 1 master toggle gating 8 sub-options +
			// 1 nested toggle (`geo_ip_areas_permissive`) gating 3 more.
			if ( '1' === $geo_enabled ) {
				$gps_src                                 = ArrayValue::string( $geofence, 'geo_area_source', 'custom' );
				$clean_geofence['geo_gps_enabled']       = isset( $geofence['geo_gps_enabled'] ) ? '1' : '0';
				$clean_geofence['geo_ip_enabled']        = isset( $geofence['geo_ip_enabled'] ) ? '1' : '0';
				$clean_geofence['geo_area_source']       = in_array( $gps_src, array( 'locations', 'custom' ), true ) ? $gps_src : 'custom';
				$clean_geofence['geo_area_location_ids'] = self::sanitized_key_list( ArrayValue::array( $geofence, 'geo_area_location_ids' ) );
				$clean_geofence['geo_areas']             = sanitize_textarea_field( ArrayValue::string( $geofence, 'geo_areas' ) );
				$clean_geofence['geo_gps_ip_logic']      = sanitize_key( ArrayValue::string( $geofence, 'geo_gps_ip_logic', 'or' ) );
				$clean_geofence['geo_hide_mode']         = sanitize_key( ArrayValue::string( $geofence, 'geo_hide_mode', 'message' ) );
				$clean_geofence['msg_geo_blocked']       = sanitize_textarea_field( ArrayValue::string( $geofence, 'msg_geo_blocked' ) );
				$clean_geofence['msg_geo_error']         = sanitize_textarea_field( ArrayValue::string( $geofence, 'msg_geo_error' ) );

				if ( '1' === $ip_permissive ) {
					$ip_src                                     = ArrayValue::string( $geofence, 'geo_ip_area_source', 'custom' );
					$clean_geofence['geo_ip_area_source']       = in_array( $ip_src, array( 'locations', 'custom' ), true ) ? $ip_src : 'custom';
					$clean_geofence['geo_ip_area_location_ids'] = self::sanitized_key_list( ArrayValue::array( $geofence, 'geo_ip_area_location_ids' ) );
					$clean_geofence['geo_ip_areas']             = sanitize_textarea_field( ArrayValue::string( $geofence, 'geo_ip_areas' ) );
				}
			}

			// Build the merged final state and validate THAT — so
			// preserved sub-options participate in validation correctly.
			// Without the merge, validating $clean_geofence alone would
			// see missing fields when masters were just turned off and
			// emit spurious errors.
			$current_geofence = get_post_meta( $post_id, '_ffc_geofence_config', true );
			if ( ! is_array( $current_geofence ) ) {
				$current_geofence = array();
			}
			$merged_geofence = array_merge( $current_geofence, $clean_geofence );

			$validation_errors = $this->validator()->validate_geofence_config( $merged_geofence );
			if ( ! empty( $validation_errors ) ) {
				set_transient( 'ffc_geofence_error_' . get_current_user_id(), $validation_errors, 45 );
				// Route each error category to its tab so the tab script can
				// open the offending one (datetime → Time, area → Geolocation).
				set_transient( 'ffc_geofence_error_tabs_' . get_current_user_id(), $this->validator()->geofence_error_tab_keys( $merged_geofence, $validation_errors ), 45 );
			} else {
				update_post_meta( $post_id, '_ffc_geofence_config', $merged_geofence );
				// Public visibility of date_start/date_end (the form's
				// availability window) flows through page caches —
				// invalidate so the public CSV download page + the
				// rendered form page pick up the change immediately.
				// The `ffc_form` CPT is `'public' => false`, so the
				// per-post `flush_post()` doesn't help the page that
				// embeds the form via `[ffc_form id=N]` shortcode.
				// Use the aggressive site-wide purge — geofence edits
				// are admin-triggered, infrequent, and the visible
				// surface lives on whatever page hosts the shortcode.
				\FreeFormCertificate\Submissions\FormCache::clear_form_cache( $post_id );
				\FreeFormCertificate\Submissions\FormCache::purge_external_caches( $post_id, 'geofence_changed' );
				\FreeFormCertificate\Submissions\FormCache::purge_all_pages( $post_id, 'geofence_changed' );
			}
		}
	}

	/**
	 * Persist the Public CSV Download configuration metas.
	 *
	 * @param int $post_id The post ID.
	 */
	private function save_csv_public_meta( int $post_id ): void {
		// The metabox posts a hidden `ffc_csv_public[present]` carrier, so a
		// rendered box always yields a non-empty array — an empty one means
		// the box was not on the screen, which is the case the old
		// `isset()`/`is_array()` pair refused too (#1084).
		$public_raw = RequestInput::get_post_raw_array( 'ffc_csv_public' );
		if ( ! empty( $public_raw ) ) {

			// `get_post_meta()` is mixed. The repo-wide census in #1084 found
			// no writer that stores a non-scalar in any of these keys, so the
			// cast is correct — but this file is going to zero, and a guard
			// on the two sites it owns costs nothing.
			$previous_meta    = get_post_meta( $post_id, '_ffc_csv_public_enabled', true );
			$previous_enabled = is_scalar( $previous_meta ) ? (string) $previous_meta : '';
			$enabled          = ! empty( $public_raw['enabled'] ) ? '1' : '0';
			update_post_meta( $post_id, '_ffc_csv_public_enabled', $enabled );

			// When the master toggle is off, the sub-fields are rendered
			// disabled so the browser does not include them in the POST.
			// Skip the rest of the block to preserve the persisted limit /
			// hash / mode / whitelist instead of overwriting them with the
			// "no fields submitted" defaults.
			if ( '1' === $enabled ) {
				// CSV Download — new in #241 follow-up. The 3 operator
				// feature toggles now sit at the top of Section 7 (same
				// shape as the Restriction & Security list). Empty meta
				// reads as '1' in the metabox; on save we persist the
				// POSTed value verbatim ('1' if checked, '0' otherwise).
				$download_enabled = ! empty( $public_raw['download_enabled'] ) ? '1' : '0';
				update_post_meta( $post_id, '_ffc_csv_public_download_enabled', $download_enabled );

				// Certificate Preview — new in #243 Sprint 5. Like the
				// other operator-feature sub-toggles in this block, empty
				// meta reads as '1' in the metabox, but on save we persist
				// the POSTed value verbatim.
				$preview_enabled = ! empty( $public_raw['preview_enabled'] ) ? '1' : '0';
				update_post_meta( $post_id, '_ffc_csv_public_preview_enabled', $preview_enabled );

				// Start Form Early — per-form opt-out for the early-open
				// action introduced in 6.5.6. Defaults to '1' for installs
				// upgrading from 6.5.6 / 6.5.7 so the feature doesn't
				// disappear from forms already using it. The metabox
				// renders the toggle as enabled-by-default for new forms
				// too, so a missing POST key means the admin intentionally
				// flipped it off.
				$start_early = ! empty( $public_raw['start_early_enabled'] ) ? '1' : '0';
				update_post_meta( $post_id, '_ffc_csv_public_start_early_enabled', $start_early );

				// Postergar fim — per-form opt-IN for the postpone-end
				// action introduced in 6.5.12. Defaults to '0' when
				// unset (conservative: extending a public-facing window
				// is destructive enough to require explicit consent).
				$extend_end = ! empty( $public_raw['extend_end_enabled'] ) ? '1' : '0';
				update_post_meta( $post_id, '_ffc_csv_public_extend_end_enabled', $extend_end );

				// Limit: a positive integer ≥ 1, or empty to inherit the global
				// default (Settings → Advanced → Default Download Limit). An empty
				// value deletes the meta so the read paths (validator + info
				// builder) fall back to the global, matching the device-fingerprint
				// inherit semantics — no per-form value is forced.
				$limit_raw = trim( ArrayValue::string( $public_raw, 'limit' ) );
				if ( '' === $limit_raw || absint( $limit_raw ) < 1 ) {
					delete_post_meta( $post_id, '_ffc_csv_public_limit' );
				} else {
					update_post_meta( $post_id, '_ffc_csv_public_limit', absint( $limit_raw ) );
				}

				// Hash handling: auto-generate on first enable, or regenerate on request.
				$stored_hash    = get_post_meta( $post_id, '_ffc_csv_public_hash', true );
				$current_hash   = is_scalar( $stored_hash ) ? (string) $stored_hash : '';
				$regenerate     = ! empty( $public_raw['regenerate_hash'] );
				$needs_new_hash = $regenerate || '' === $current_hash;

				if ( $needs_new_hash ) {
					try {
						$new_hash = bin2hex( random_bytes( 16 ) );
					} catch ( \Exception $e ) {
						$new_hash = wp_generate_password( 32, false, false );
					}
					update_post_meta( $post_id, '_ffc_csv_public_hash', $new_hash );
				}

				// Counter reset (explicit opt-in).
				if ( ! empty( $public_raw['reset_counter'] ) ) {
					update_post_meta( $post_id, '_ffc_csv_public_count', 0 );
				}

				// CPF gate mode for the public download. On the very first
				// enable transition (toggle just flipped 0→1) and the user
				// left the dropdown at the 'none' default, upgrade to 'audit'
				// so newly-enabled public downloads always log who pulled
				// them. On subsequent saves, respect the user's explicit
				// choice — including 'none' if they actively pick it.
				$valid_modes = array( 'none', 'audit', 'participants', 'owner', 'whitelist' );
				$mode_raw    = sanitize_key( ArrayValue::string( $public_raw, 'cpf_mode', 'none' ) );
				if ( ! in_array( $mode_raw, $valid_modes, true ) ) {
					$mode_raw = 'none';
				}
				if ( '1' !== $previous_enabled && 'none' === $mode_raw ) {
					$mode_raw = 'audit';
				}
				update_post_meta( $post_id, '_ffc_csv_public_cpf_mode', $mode_raw );

				// Whitelist textarea is rendered only when the persisted mode
				// is already 'whitelist'. When the user is in the process of
				// switching modes via the dropdown, the textarea is absent
				// from the POST — preserve the existing list instead of
				// silently deleting it.
				if ( array_key_exists( 'cpf_whitelist', $public_raw ) ) {
					$wl_raw = sanitize_textarea_field( ArrayValue::string( $public_raw, 'cpf_whitelist' ) );
					if ( '' !== trim( $wl_raw ) ) {
						$cleaned_lines = array();
						$lines         = preg_split( '/[\r\n,]+/', $wl_raw );
						$lines         = is_array( $lines ) ? $lines : array();
						foreach ( $lines as $line ) {
							$digits = preg_replace( '/\D/', '', (string) $line );
							if ( is_string( $digits ) && 11 === strlen( $digits ) ) {
								$cleaned_lines[ $digits ] = $digits;
							}
						}
						update_post_meta( $post_id, '_ffc_csv_public_cpf_whitelist', implode( "\n", array_values( $cleaned_lines ) ) );
					} else {
						delete_post_meta( $post_id, '_ffc_csv_public_cpf_whitelist' );
					}
				}
			}
		}
	}

	/**
	 * Persist the Device Fingerprint Limit override metas.
	 *
	 * @param int $post_id The post ID.
	 */
	private function save_device_limit_meta( int $post_id ): void {
		// Same hidden `ffc_device_limit[present]` carrier as the CSV box.
		$device_raw = RequestInput::get_post_raw_array( 'ffc_device_limit' );
		if ( ! empty( $device_raw ) ) {

			$device_enabled = ! empty( $device_raw['enabled'] ) ? '1' : '0';
			update_post_meta( $post_id, '_ffc_device_limit_enabled', $device_enabled );

			// When the master toggle is off the sub-fields render disabled
			// and never make it into the POST. Skip the rest of the block to
			// preserve the persisted max / threshold / message instead of
			// silently deleting them.
			if ( '1' === $device_enabled ) {
				// Max submissions: an empty value inherits the global default
				// (Settings → Rate Limit → Device Fingerprint → max_per_form).
				// Deleting the meta lets RateLimitChecker::get_device_effective_settings()
				// fall back to the global at read time — same inherit-from-global
				// semantics as the threshold + message below.
				$max_raw = trim( ArrayValue::string( $device_raw, 'max' ) );
				if ( '' === $max_raw ) {
					delete_post_meta( $post_id, '_ffc_device_limit_max' );
				} else {
					update_post_meta( $post_id, '_ffc_device_limit_max', max( 1, absint( $max_raw ) ) );
				}

				// Threshold and message keep the inherit-from-global semantic:
				// empty value deletes the meta so RateLimiter::get_settings()
				// supplies the global default at read time.
				$thr_raw = trim( ArrayValue::string( $device_raw, 'threshold' ) );
				if ( '' === $thr_raw ) {
					delete_post_meta( $post_id, '_ffc_device_match_threshold' );
				} else {
					update_post_meta( $post_id, '_ffc_device_match_threshold', max( 3, min( 12, absint( $thr_raw ) ) ) );
				}

				// Minimum strong signals (two-tier match). Empty inherits the
				// global default; 0 is a valid explicit value (disables the
				// strong tier for this form).
				$strong_raw = trim( ArrayValue::string( $device_raw, 'strong_min' ) );
				if ( '' === $strong_raw ) {
					delete_post_meta( $post_id, '_ffc_device_strong_min' );
				} else {
					update_post_meta( $post_id, '_ffc_device_strong_min', max( 0, min( 6, absint( $strong_raw ) ) ) );
				}

				$msg_raw = sanitize_textarea_field( ArrayValue::string( $device_raw, 'message' ) );
				if ( '' === $msg_raw ) {
					delete_post_meta( $post_id, '_ffc_device_limit_message' );
				} else {
					update_post_meta( $post_id, '_ffc_device_limit_message', $msg_raw );
				}
			}
		}
	}

	/**
	 * Sanitize a posted list of location ids.
	 *
	 * `array_map( 'sanitize_key', … )` over a raw POST list hands
	 * `sanitize_key()` whatever the request nested there — an array element
	 * is a TypeError, not a sanitised key. Non-scalar entries are dropped
	 * instead (#1084).
	 *
	 * @param array<array-key, mixed> $ids Posted list.
	 * @return list<string>
	 */
	private static function sanitized_key_list( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			if ( is_scalar( $id ) ) {
				$out[] = sanitize_key( (string) $id );
			}
		}
		return $out;
	}

	/**
	 * Sanitize the four schedule-exception keys (#366) out of the raw
	 * POSTed `ffc_geofence` array. Extracted from `save_form_data()` so
	 * the rules are exercisable from PHPUnit via Reflection without
	 * having to wire up the full `save_form_data()` POST pipeline.
	 *
	 * Behaviour:
	 * - `schedule_exception_enabled` is treated as a checkbox: present in
	 *   the POST → '1', absent → '0'. Master toggle for the feature.
	 * - `class_time_start` / `class_time_end` are TIME inputs. Empty
	 *   strings stay empty (meaning "fall back to geofence baseline"); a
	 *   value is preserved verbatim after `sanitize_text_field()` strips
	 *   any incidental whitespace / control characters. We deliberately
	 *   do NOT enforce `HH:MM` shape here — the browser type=time control
	 *   already does it; locking the format would also reject the empty
	 *   string we want to allow.
	 * - `schedule_default_mode` is a whitelist of `now` / `manual`,
	 *   anything else folds to the default `now`.
	 *
	 * @param array<string, mixed> $geofence Raw, unslashed POST payload.
	 * @return array<string, string> The four sanitized schedule-exception keys.
	 */
	private static function sanitize_schedule_exception_config( array $geofence ): array {
		$mode = sanitize_key( ArrayValue::string( $geofence, 'schedule_default_mode', 'now' ) );
		if ( ! in_array( $mode, array( 'now', 'manual' ), true ) ) {
			$mode = 'now';
		}

		return array(
			'schedule_exception_enabled' => isset( $geofence['schedule_exception_enabled'] ) ? '1' : '0',
			'class_time_start'           => sanitize_text_field( ArrayValue::string( $geofence, 'class_time_start' ) ),
			'class_time_end'             => sanitize_text_field( ArrayValue::string( $geofence, 'class_time_end' ) ),
			'schedule_default_mode'      => $mode,
		);
	}

	/**
	 * Displays validation warnings after saving
	 */
	public function display_save_errors(): void {
		// Display PDF layout errors.
		// `get_transient()` is mixed: these three reads carry whatever a
		// previous save wrote, and the renderers below iterate and echo it
		// (#1084).
		$error_tags = get_transient( 'ffc_save_error_' . get_current_user_id() );
		if ( is_array( $error_tags ) && ! empty( $error_tags ) ) {
			delete_transient( 'ffc_save_error_' . get_current_user_id() );
			ob_start();
			?>
			<p><strong><?php esc_html_e( 'Warning! Missing required tags in PDF Layout:', 'ffcertificate' ); ?></strong> <code><?php echo esc_html( implode( ', ', array_map( 'strval', array_filter( $error_tags, 'is_scalar' ) ) ) ); ?></code>.</p>
			<?php
			wp_admin_notice(
				(string) ob_get_clean(),
				array(
					'type'           => 'error',
					'dismissible'    => true,
					'paragraph_wrap' => false,
				)
			);
		}

		// Display legacy html/ reference warnings (#865 Phase 4). Non-blocking:
		// the post already saved; this only nudges the admin to move the image
		// into the Media Library before an update deletes it.
		$html_lint = get_transient( 'ffc_html_lint_' . get_current_user_id() );
		if ( $html_lint ) {
			delete_transient( 'ffc_html_lint_' . get_current_user_id() );
			$migrations_url = admin_url( 'admin.php?page=ffc-settings&tab=migrations' );
			ob_start();
			?>
			<p><strong><?php esc_html_e( 'Heads up — this certificate references images inside the plugin folder:', 'ffcertificate' ); ?></strong></p>
			<ul class="ffc-list-disc ffc-ml-20">
				<?php foreach ( (array) $html_lint as $ref ) : ?>
					<li><code><?php echo esc_html( is_scalar( $ref ) ? (string) $ref : '' ); ?></code></li>
				<?php endforeach; ?>
			</ul>
			<p>
				<?php esc_html_e( 'A plugin update or deploy will delete these files and break the images. Use the "Insert Image" button to add them from the Media Library instead, or run', 'ffcertificate' ); ?>
				<a href="<?php echo esc_url( $migrations_url ); ?>"><?php esc_html_e( 'Settings → Migrations → Rewrite html/ Image References', 'ffcertificate' ); ?></a>.
			</p>
			<?php
			wp_admin_notice(
				(string) ob_get_clean(),
				array(
					'type'           => 'warning',
					'dismissible'    => true,
					'paragraph_wrap' => false,
				)
			);
		}

		// Display geofence validation errors.
		$geofence_errors = get_transient( 'ffc_geofence_error_' . get_current_user_id() );
		if ( is_array( $geofence_errors ) && ! empty( $geofence_errors ) ) {
			delete_transient( 'ffc_geofence_error_' . get_current_user_id() );
			// Companion tab-routing transient set alongside the error list.
			delete_transient( 'ffc_geofence_error_tabs_' . get_current_user_id() );
			ob_start();
			?>
			<p><strong><?php esc_html_e( 'Geolocation Configuration Error:', 'ffcertificate' ); ?></strong></p>
			<ul class="ffc-list-disc ffc-ml-20">
				<?php foreach ( $geofence_errors as $error ) : ?>
					<li><?php echo esc_html( is_scalar( $error ) ? (string) $error : '' ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php
			wp_admin_notice(
				(string) ob_get_clean(),
				array(
					'type'           => 'error',
					'dismissible'    => true,
					'paragraph_wrap' => false,
				)
			);
		}
	}
}
