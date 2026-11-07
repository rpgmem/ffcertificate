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
			// The error stays, with a Close button, until the visitor reads it (#2344).
			if (window.FFCProgressOverlay) { window.FFCProgressOverlay.error(msg, strings.error || 'Error'); }
		},

		// ── Flash message ───────────────────────────────────────────

		showFlash: function (msg, type) {
			api.$container.find('.ffc-pcd-message').remove();
			var notice = type === 'error'
				? window.FFC.notice('danger', 'error', msg, '', { role: 'alert' })
				: window.FFC.notice('success', 'success', msg, '', { role: 'status' });
			var $flash = $('<div class="ffc-verify-result ffc-pcd-message"></div>').append(notice);
			api.$container.find('.ffc-verification-header').after($flash);
		},

		// ── Form state helpers ──────────────────────────────────────

		disableBtn: function () {
			if (api.$btn) api.$btn.prop('disabled', true).addClass('ffc-btn-loading');
		},

		enableBtn: function () {
			if (api.$btn) api.$btn.prop('disabled', false).removeClass('ffc-btn-loading');
		},

		// ── Dialogs (#2341) ─────────────────────────────────────────

		/**
		 * Show a dialog of this screen, and return the function that closes it.
		 *
		 * The dialog goes into a `.ffc-shortcode` host, so the operator's
		 * Public Design reaches it as it reaches the screen. Tab and Shift+Tab
		 * stay inside it, and closing it returns the focus to the control
		 * that opened it, when that control is still on the page.
		 *
		 * @param {jQuery} $dialog The dialog's root element.
		 * @return {Function} Closes the dialog.
		 */
		openDialog: function ($dialog) {
			var opener = document.activeElement;
			var $host  = $('<div class="ffc-shortcode ffc-csv-dialog-host"></div>').append($dialog).appendTo(document.body);
			var selector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
			$dialog.on('keydown.ffcDialogTrap', function (e) {
				if (e.key !== 'Tab') {
					return;
				}
				var items = $dialog.find(selector).filter(function () {
					return !$(this).closest('[hidden]').length;
				});
				if (!items.length) {
					return;
				}
				var first = items[0];
				var last  = items[items.length - 1];
				if (e.shiftKey && (document.activeElement === first || !$dialog[0].contains(document.activeElement))) {
					e.preventDefault();
					last.focus();
				} else if (!e.shiftKey && document.activeElement === last) {
					e.preventDefault();
					first.focus();
				}
			});
			return function () {
				$host.remove();
				if (opener && opener !== document.body && typeof opener.focus === 'function' && document.contains(opener)) {
					opener.focus();
				}
			};
		},

		// ── Misc ────────────────────────────────────────────────────

		// Stashed by the info screen for the modal flows that need the
		// original schedule formatting (open-early / extend-end / exception).
		lastInfo: function () {
			return api.$container && api.$container.data('ffc-last-info');
		},

		// ── The site's clock (#2341) ────────────────────────────────
		// The info answer carries the server's `now`, the site's UTC offset
		// and its formats. The gap to this browser's clock is kept, so a
		// dialog opened minutes later still reads the site's wall time, not
		// the operator's timezone or a wrong clock.

		clockSkew: 0,

		setClock: function (clock) {
			api.clock = clock || null;
			api.clockSkew = clock && clock.now ? clock.now * 1000 - Date.now() : 0;
		},

		/**
		 * The site's current wall time as `HH:MM`, and today's date in the
		 * plugin's date format; the browser's clock when no info was read.
		 *
		 * @return {{hm: string, today: string}}
		 */
		siteNow: function () {
			var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
			var c = api.clock;
			if (!c || typeof c.offset !== 'number') {
				var local = new Date();
				return { hm: pad(local.getHours()) + ':' + pad(local.getMinutes()), today: '' };
			}
			var d = new Date(Date.now() + api.clockSkew + c.offset * 1000);
			return { hm: pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()), today: c.today || '' };
		},

		/**
		 * A wall-clock `HH:MM` in the plugin's time format. Reads the PHP
		 * format letters a time format uses (H G h g i s a A, `\` escapes);
		 * anything else is printed as written.
		 *
		 * @param {string} hm `HH:MM` or `HH:MM:SS`.
		 * @return {string}
		 */
		formatTime: function (hm) {
			var m = /^(\d{1,2}):(\d{2})(?::(\d{2}))?$/.exec(String(hm || ''));
			var fmt = api.clock && api.clock.time_format;
			if (!m || !fmt) {
				return hm || '';
			}
			var h = parseInt(m[1], 10);
			var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
			var h12 = h % 12 === 0 ? 12 : h % 12;
			var out = '';
			for (var i = 0; i < fmt.length; i++) {
				var ch = fmt.charAt(i);
				if (ch === '\\') {
					out += fmt.charAt(++i);
					continue;
				}
				switch (ch) {
					case 'H': out += pad(h); break;
					case 'G': out += h; break;
					case 'h': out += pad(h12); break;
					case 'g': out += h12; break;
					case 'i': out += m[2]; break;
					case 's': out += m[3] || '00'; break;
					case 'a': out += h < 12 ? 'am' : 'pm'; break;
					case 'A': out += h < 12 ? 'AM' : 'PM'; break;
					default: out += ch;
				}
			}
			return out;
		},

		goBack: function () {
			location.reload();
		},

		// ── The operator actions' challenge (#2341) ────────────────

		// The info answer carries a security block bound to the actions'
		// own context. It is kept detached as a template: each dialog
		// mounts a copy, and a refusal's fresh challenge updates the
		// template, so the next dialog shows the new question.
		$actionSecurity: null,

		setActionSecurity: function (html) {
			api.$actionSecurity = html ? $('<div></div>').html(html) : null;
		},

		// Put a copy of the block into a dialog; nothing when the screen
		// did not send one.
		mountActionSecurity: function ($target) {
			if (!api.$actionSecurity || !$target || !$target.length) {
				return;
			}
			$target.empty().append(api.$actionSecurity.children().clone());
		},

		// The request of an action: the page form without its action and
		// without its own security fields (those belong to the download),
		// plus the dialog's security fields.
		actionPayload: function ($dialog) {
			var own = {};
			api.$form.find('.ffc-security-container :input').each(function () {
				if (this.name) own[this.name] = true;
			});
			var base = api.$form.serializeArray().filter(function (field) {
				return field.name !== 'action' && !own[field.name];
			});
			var fields = $dialog ? $dialog.find('.ffc-security-container :input').serializeArray() : [];
			return $.param(base.concat(fields));
		},

		// The download's own challenge after the first one (#2341). The
		// screen's challenge is spent by the first download's start, which
		// sends a fresh one; the screen shows it above the actions and the
		// next download sends it instead of the page form's.
		$downloadSecurity: null,

		setDownloadSecurity: function (html) {
			api.$downloadSecurity = html ? $('<div></div>').html(html) : null;
		},

		// The start request of a download: the page form as read for the
		// info screen, or, once a download spent its challenge, the page
		// form without its security fields plus the screen's fresh block.
		downloadPayload: function () {
			var $block = api.$container ? api.$container.find('.ffc-csv-download-security') : $();
			if (!api.$downloadSecurity || !$block.length || !api.$form) {
				return api.formData;
			}
			var own = {};
			api.$form.find('.ffc-security-container :input').each(function () {
				if (this.name) own[this.name] = true;
			});
			var base = api.$form.serializeArray().filter(function (field) {
				return !own[field.name];
			});
			return $.param(base.concat($block.find('.ffc-security-container :input').serializeArray()));
		},

		// After a refused action: the server spent the challenge and sent
		// a fresh one, which the next dialog must show.
		refreshActionSecurity: function (err) {
			var data = err && err.data;
			if (!data || !data.refresh_captcha || !api.$actionSecurity) {
				return;
			}
			var UI = window.FFC && window.FFC.Frontend && window.FFC.Frontend.UI;
			if (UI && typeof UI.refreshCaptcha === 'function') {
				UI.refreshCaptcha(api.$actionSecurity, data);
			}
		},

		// ── Browser check (#2030) ───────────────────────────────────

		// The visitor pre-flight's browser step, as on a certificate form:
		// the download is a file the browser builds and saves, which an
		// app's own browser often cannot do, and which a browser without
		// Blob or fetch cannot do at all. The step hides the form while it
		// blocks; the step after it shows the form again once the visitor
		// goes on.
		checkBrowser: function () {
			var preflight = window.FFC && window.FFC.Preflight;
			if (!preflight || typeof preflight.run !== 'function') {
				return;
			}
			var cfg = (window.ffc_csv_download && window.ffc_csv_download.preflight) || {};
			var strings = cfg.strings || {};
			preflight.register('csv-download-form', {
				run: function (ctx) {
					ctx.wrapper.find(ctx.formSelector).show();
				}
			});
			preflight.run(['browser', 'csv-download-form'], {
				wrapper: api.$container,
				config: {},
				browserCheck: cfg.browserCheck !== false,
				formSelector: '.ffc-verification-form',
				telemetry: cfg.telemetry,
				text: function (key, fallback) {
					return strings[key] || fallback;
				}
			});
		},

		// ── Initialise ──────────────────────────────────────────────

		// The access hash is the operator's credential: once the page has
		// read it into the form, it leaves the address bar, so it does not
		// sit in the history, a bookmark or a screen share (#2341). The form
		// id stays; the link keeps working because the server reads the
		// query on load.
		stripHashFromAddress: function () {
			if (!window.history || typeof window.history.replaceState !== 'function' || typeof window.URL !== 'function') {
				return;
			}
			var url;
			try {
				url = new window.URL(window.location.href);
			} catch (e) {
				return;
			}
			if (!url.searchParams.has('hash')) {
				return;
			}
			url.searchParams.delete('hash');
			window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
		},

		init: function () {
			api.stripHashFromAddress();
			api.$container = $('.ffc-public-csv-download');
			api.$form      = api.$container.find('form');
			if (!api.$form.length) {
				return;
			}
			api.$btn = api.$form.find('.ffc-submit-btn');
			api.$form.on('submit', api.onSubmitInfo);
			api.checkBrowser();

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
