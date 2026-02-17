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
		// Only run if the page contains FFC elements that need refreshing
		var needsRefresh =
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

		xhr.send('action=ffc_get_dynamic_fragments');
	}

	/**
	 * Patch the DOM with fresh captcha, nonce, and user values.
	 */
	function applyFragments(data) {
		var i;

		// --- Captcha ---
		if (data.captcha) {
			var labels  = document.querySelectorAll('.ffc-captcha-row label');
			var hashes  = document.querySelectorAll('input[name="ffc_captcha_hash"]');
			var answers = document.querySelectorAll('input[name="ffc_captcha_ans"]');

			for (i = 0; i < labels.length; i++) {
				labels[i].textContent = data.captcha.label;
			}
			for (i = 0; i < hashes.length; i++) {
				hashes[i].value = data.captcha.hash;
			}
			for (i = 0; i < answers.length; i++) {
				answers[i].value = '';
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
	}

	// Run as soon as the DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', refreshFragments);
	} else {
		refreshFragments();
	}
})();
