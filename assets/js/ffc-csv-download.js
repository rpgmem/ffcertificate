/**
 * Public CSV Download — shared core (config + runtime state + UI helpers).
 *
 * This file owns the `window.FFCCsv` singleton: the localized config, the
 * mutable DOM/job state shared across flows, the progress-overlay helpers,
 * the flash message, the form-button helpers, and the boot sequence.
 *
 * The individual flows live in sibling files that depend on this handle and
 * read/extend the namespace:
 *  - ffc-csv-info-screen.js        → step 1 (info screen) + section builders
 *  - ffc-csv-cert-preview.js       → certificate preview modal
 *  - ffc-csv-download-flow.js      → batched export (start → batch → download)
 *  - ffc-csv-open-early.js         → "Start Form Now" (early open)
 *  - ffc-csv-extend-end.js         → "Postpone close"
 *  - ffc-csv-schedule-exception.js → per-participant schedule exception (#366)
 *
 * Flow overview:
 *  1. ffc_public_csv_info  → validate hash → show form details screen
 *  2. (optional) cert preview → modal with certificate HTML
 *  3. the batched export (steps start → batch → download) runs through the
 *     shared window.FFCBatchedExport driver against the unified `ffc_export_*`
 *     dispatcher (type=public_forms); see ffc-csv-download-flow.js.
 *
 * Falls back to normal form POST when JS is unavailable (graceful degradation).
 *
 * @since 5.2.0
 */
(function ($) {
	'use strict';

	function esc(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	var cfg     = window.ffc_csv_download || {};
	var strings = cfg.strings || {};

	// Shared singleton. Flow modules read config + helpers and read/write the
	// mutable runtime state (DOM refs, serialised payload, overlay handle,
	// safety timer) through this object so behaviour is identical to the
	// pre-split single-file version.
	var api = window.FFCCsv = {
		cfg: cfg,
		strings: strings,
		esc: esc,

		// DOM refs (set in init) + runtime state shared across flow modules.
		$container: null,
		$form: null,
		$btn: null,
		$overlay: null,
		// Serialised form payload reused across the info / start / preview calls.
		formData: null,
		// Hard-timeout handle for the export job (set by the download flow,
		// cleared here in showError and on export completion).
		safetyTimer: null,

		// ── Overlay helpers ─────────────────────────────────────────
		// The overlay implementation lives once in window.FFCProgressOverlay
		// (ffc-batched-export.js), shared with the admin exports (#786) so both
		// surfaces render the identical modal. These thin delegators keep the
		// public flow's api.* method names and the api.$overlay handle that the
		// download flow reads directly.

		showOverlay: function (text) {
			if (!window.FFCProgressOverlay) { return; }
			api.$overlay = window.FFCProgressOverlay.show(text);
		},

		hideOverlay: function () {
			if (window.FFCProgressOverlay) { window.FFCProgressOverlay.hide(); }
			api.$overlay = null;
		},

		updateProgress: function (current, max) {
			if (window.FFCProgressOverlay) { window.FFCProgressOverlay.progress(current, max); }
		},

		updateStatus: function (text) {
			if (window.FFCProgressOverlay) { window.FFCProgressOverlay.status(text); }
		},

		showError: function (msg) {
			clearTimeout(api.safetyTimer);
			if (window.FFCProgressOverlay) { window.FFCProgressOverlay.error(msg, strings.error || 'Error'); }
			setTimeout(function () { api.hideOverlay(); }, 4000);
		},

		// ── Flash message ───────────────────────────────────────────

		showFlash: function (msg, type) {
			api.$container.find('.ffc-pcd-message').remove();
			var cls = type === 'error' ? 'ffc-verify-error' : 'ffc-verify-success';
			var $flash = $('<div class="ffc-verify-result ffc-pcd-message"><div class="' + cls + '">' + esc(msg) + '</div></div>');
			api.$container.find('.ffc-verification-header').after($flash);
		},

		// ── Form state helpers ──────────────────────────────────────

		disableBtn: function () {
			if (api.$btn) api.$btn.prop('disabled', true).addClass('ffc-btn-loading');
		},

		enableBtn: function () {
			if (api.$btn) api.$btn.prop('disabled', false).removeClass('ffc-btn-loading');
		},

		// ── Misc ────────────────────────────────────────────────────

		// Stashed by the info screen for the modal flows that need the
		// original schedule formatting (open-early / extend-end / exception).
		lastInfo: function () {
			return api.$container && api.$container.data('ffc-last-info');
		},

		goBack: function () {
			location.reload();
		},

		// ── Initialise ──────────────────────────────────────────────

		init: function () {
			api.$container = $('.ffc-public-csv-download');
			api.$form      = api.$container.find('form');
			if (!api.$form.length) {
				return;
			}
			api.$btn = api.$form.find('.ffc-submit-btn');
			api.$form.on('submit', api.onSubmitInfo);

			// 6.3.4: apply the canonical CPF/RF mask helper to the optional CPF
			// field rendered when _ffc_csv_public_cpf_mode is set on the target
			// form. Reuses the same Masks API the certificate form uses, so the
			// formatting (XXX.XXX.XXX-XX) and the on-blur valid/invalid styling
			// match exactly. Auto-discovers inputs by name="cpf" / id="ffc-pcd-cpf".
			if (window.FFC && window.FFC.Frontend && window.FFC.Frontend.Masks) {
				window.FFC.Frontend.Masks.applyCpfRf(api.$container.find('input[name="cpf"]'));
			}
		}
	};

	// ── Boot ────────────────────────────────────────────────────

	$(document).ready(api.init);

})(jQuery);
