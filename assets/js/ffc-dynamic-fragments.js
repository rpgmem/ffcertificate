/**
 * FFC Dynamic Fragments
 * Refreshes captcha and nonces on page load for full-page cache compatibility.
 *
 * When a page cache (LiteSpeed, Varnish, etc.) serves a cached copy, the
 * server-rendered captcha and WordPress nonces may be stale.  This script
 * fires a lightweight AJAX request on DOMContentLoaded to fetch fresh values
 * and patches the DOM before the user can interact with the form.
 *
 * @since 4.12.0
 */
(function () {
	'use strict';

	function refreshFragments() {
		// Only run if the page contains FFC elements that need refreshing.
		// `.ffc-security-container` is the provider-independent wrapper: it
		// carries the honeypot, which every provider renders. `.ffc-captcha-row`
		// is math-specific and disappears under a provider that renders no
		// arithmetic row — on a page whose only FFC marker was that row (the
		// [ffc_csv_download] shortcode), nonce refresh would silently stop and
		// surface later as a random "security check failed" on cached pages.
		var needsRefresh =
			document.querySelector('.ffc-security-container') ||
			document.querySelector('.ffc-captcha-row') ||
			document.querySelector('.ffc-verification-form') ||
			document.querySelector('.ffc-form-container') ||
			document.querySelector('.ffc-booking-form');

		if (!needsRefresh) {
			return;
		}

		var ajaxUrl =
			(typeof ffcDynamic !== 'undefined' && ffcDynamic.ajaxUrl) ||
			(typeof ffc_ajax !== 'undefined' && ffc_ajax.ajax_url) ||
			(typeof ffcCalendar !== 'undefined' && ffcCalendar.ajaxurl) ||
			null;

		if (!ajaxUrl) {
			return;
		}

		// Collect form IDs from the page so the backend can return fresh
		// geofence configs for each form (cached pages may have stale data).
		var formIds = [];
		var formWrappers = document.querySelectorAll('.ffc-form-wrapper[id^="ffc-form-"]');
		for (var f = 0; f < formWrappers.length; f++) {
			var wId = formWrappers[f].id.replace('ffc-form-', '');
			if (wId) {
				formIds.push(wId);
			}
		}

		var payload = 'action=ffc_get_dynamic_fragments';
		for (var fi = 0; fi < formIds.length; fi++) {
			payload += '&form_ids%5B%5D=' + encodeURIComponent(formIds[fi]);
		}

		var xhr = new XMLHttpRequest();
		xhr.open('POST', ajaxUrl, true);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

		xhr.onload = function () {
			if (xhr.status !== 200) {
				return;
			}

			try {
				var response = JSON.parse(xhr.responseText);
				if (!response.success || !response.data) {
					return;
				}
				applyFragments(response.data);
			} catch (e) {
				// Silent fail — the page still works with the server-rendered values
			}
		};

		xhr.send(payload);
	}

	/**
	 * Every security block on the page, in DOM order.
	 *
	 * `.ffc-security-container` is the provider-independent wrapper each
	 * render site emits. A bare `.ffc-captcha-row` outside one is markup from
	 * before that wrapper existed — and cached HTML is exactly this
	 * endpoint's audience, so it is collected too rather than skipped.
	 *
	 * @returns {Element[]} Roots to patch, one per rendered challenge.
	 */
	function securityBlocks() {
		var blocks = [];
		var i;

		var containers = document.querySelectorAll('.ffc-security-container');
		for (i = 0; i < containers.length; i++) {
			blocks.push(containers[i]);
		}

		var rows = document.querySelectorAll('.ffc-captcha-row');
		for (i = 0; i < rows.length; i++) {
			if (!rows[i].closest('.ffc-security-container')) {
				blocks.push(rows[i]);
			}
		}

		return blocks;
	}

	/**
	 * Apply a challenge payload inside one security block.
	 *
	 * The payload is whatever the configured captcha strategy issued, and it
	 * names itself in `provider`. Dispatching on that — rather than assuming
	 * the math shape — is what keeps this honest once a strategy with
	 * different fields exists: an unrecognised provider is left alone instead
	 * of being half-applied, which would blank a challenge the visitor may
	 * already have solved.
	 *
	 * @param {Element} root    Security block to patch within.
	 * @param {Object}  payload Challenge payload from the server.
	 * @returns {void}
	 */
	function applyChallenge(root, payload) {
		if (!payload) {
			return;
		}

		// A proof-of-work widget fetches its own challenge from an endpoint
		// whenever it needs one, which is why that endpoint exists — there is
		// nothing in the cached HTML to go stale, so nothing here to patch.
		// Named rather than left to the fallback below, because "no work to
		// do" and "provider I do not recognise" are different answers.
		if (payload.provider === 'altcha') {
			return;
		}

		if (payload.provider !== 'math') {
			return;
		}

		var label = root.querySelector('.ffc-captcha-label-text');
		var hash  = root.querySelector('input[name="ffc_captcha_hash"]');
		var ans   = root.querySelector('input[name="ffc_captcha_ans"]');

		if (label) { label.textContent = payload.new_label; }
		if (hash)  { hash.value = payload.new_hash; }
		// Clearing the answer matters as much as the token: a stale answer
		// beside a fresh challenge submits a pair that cannot verify.
		if (ans)   { ans.value = ''; }
	}

	/**
	 * Patch the DOM with fresh captcha, nonce, and user values.
	 */
	function applyFragments(data) {
		var i;

		// --- Captchas ---
		// One pass over the security blocks. A block inside a form wrapper
		// takes that form's own payload when the server sent one (several
		// forms on a page must never share a challenge — #1056); everything
		// else takes the default. Scoping by block rather than by document
		// is what keeps two challenges on one page independent.
		if (data.captcha || data.captchas) {
			var blocks = securityBlocks();
			for (i = 0; i < blocks.length; i++) {
				var wrapper = blocks[i].closest('.ffc-form-wrapper');
				var formId  = wrapper ? wrapper.id.replace('ffc-form-', '') : '';
				var payload = (data.captchas && formId && data.captchas[formId])
					? data.captchas[formId]
					: data.captcha;

				applyChallenge(blocks[i], payload);
			}
		}

		// --- Nonces ---
		if (data.nonces) {
			// ffc_ajax object (form submission & verification)
			if (typeof ffc_ajax !== 'undefined' && data.nonces.ffc_frontend_nonce) {
				ffc_ajax.nonce = data.nonces.ffc_frontend_nonce;
			}

			// ffcCalendar object (self-scheduling)
			if (typeof ffcCalendar !== 'undefined' && data.nonces.ffc_self_scheduling_nonce) {
				ffcCalendar.nonce = data.nonces.ffc_self_scheduling_nonce;
			}

			// Hidden nonce fields inside self-scheduling booking form
			if (data.nonces.ffc_self_scheduling_nonce) {
				var nonceFields = document.querySelectorAll(
					'#ffc-self-scheduling-form input[name="nonce"]'
				);
				for (i = 0; i < nonceFields.length; i++) {
					nonceFields[i].value = data.nonces.ffc_self_scheduling_nonce;
				}
			}

			// Public CSV download: refresh the hidden _ffc_pcd_nonce field
			// generated by wp_nonce_field() in the [ffc_csv_download]
			// shortcode. Cached HTML would otherwise carry a stale value.
			if (data.nonces.ffc_public_csv_download) {
				var pcdFields = document.querySelectorAll(
					'.ffc-public-csv-download input[name="_ffc_pcd_nonce"]'
				);
				for (i = 0; i < pcdFields.length; i++) {
					pcdFields[i].value = data.nonces.ffc_public_csv_download;
				}
			}

			// ffc_audience shortcode: patch the two nonces localised on the
			// `ffcAudience` global. Each REST/AJAX call in ffc-audience.js
			// reads the current value of the global, so updating it here
			// in-place is enough — no per-call patching needed.
			if (typeof ffcAudience !== 'undefined') {
				if (data.nonces.wp_rest) {
					ffcAudience.nonce = data.nonces.wp_rest;
				}
				if (data.nonces.ffc_search_users) {
					ffcAudience.searchUsersNonce = data.nonces.ffc_search_users;
				}
			}
		}

		// --- User pre-fill (booking form) ---
		if (data.user) {
			var nameField  = document.getElementById('ffc-booking-name');
			var emailField = document.getElementById('ffc-booking-email');

			if (nameField && data.user.name) {
				nameField.value = data.user.name;
				nameField.setAttribute('readonly', 'readonly');
			}
			if (emailField && data.user.email) {
				emailField.value = data.user.email;
				emailField.setAttribute('readonly', 'readonly');
			}
		}

		// --- Geofence configs (refresh stale cached data) ---
		if (data.geofence && typeof ffcGeofenceConfig !== 'undefined') {
			for (formId in data.geofence) {
				if (data.geofence.hasOwnProperty(formId)) {
					ffcGeofenceConfig[formId] = data.geofence[formId];
				}
			}

			if (typeof window.FFCGeofence !== 'undefined' && typeof window.FFCGeofence.recheck === 'function') {
				window.FFCGeofence.recheck();
			}
		}
	}

	// Run as soon as the DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', refreshFragments);
	} else {
		refreshFragments();
	}
})();
