/**
 * FFC Captcha — ALTCHA widget glue.
 *
 * Three jobs, none of which the widget does on its own:
 *
 *   1. Register the plugin's translated strings in the widget's i18n store.
 *      Version 3 reads labels from `globalThis.$altcha.i18n`, keyed by
 *      language and selected by the element's `language` attribute — the
 *      alternative being the upstream 52 KB i18n bundle for strings this
 *      plugin already translates.
 *   2. Reset the widget on every server-side rejection. A solved challenge is
 *      spent the moment it reaches the server, so leaving a verified-looking
 *      widget on screen after any refusal means the visitor's next attempt
 *      fails on the captcha rather than on whatever actually rejected them.
 *   3. Say something legible when the widget cannot run. Its own failure text
 *      is a bare "Verification failed", and the most likely cause here is
 *      structural rather than transient — the widget refuses to run outside a
 *      secure context, so a site served over plain HTTP fails every time.
 *
 * @since 6.23.0
 */
(function () {
	'use strict';

	/**
	 * The localised payload, read on use rather than captured at load.
	 *
	 * `wp_localize_script` prints it above this file, so capturing once would
	 * work — but reading live costs nothing and keeps the module independent
	 * of load order, which is one less thing to be true.
	 */
	function cfg() {
		return window.ffcCaptcha || {};
	}

	/**
	 * Register translated strings under the site's language.
	 *
	 * The store is seeded by the widget bundle, so this has to run after it —
	 * guaranteed by the enqueue dependency, not by timing.
	 */
	function registerStrings() {
		var config = cfg();

		if (!config.language || !config.strings) {
			return;
		}
		var altcha = window.$altcha;
		if (!altcha || !altcha.i18n || typeof altcha.i18n.set !== 'function') {
			return;
		}
		try {
			altcha.i18n.set(config.language, config.strings);
		} catch (e) {
			// A widget in English beats a form that does not load.
		}
	}

	/**
	 * Every ALTCHA widget on the page, or inside a root when one is given.
	 */
	function widgets(root) {
		return (root || document).querySelectorAll('altcha-widget');
	}

	/**
	 * Return each widget to its unverified state.
	 *
	 * `reset()` is one of the element's published methods. It is called
	 * defensively anyway: the element upgrades asynchronously, so a rejection
	 * arriving before the bundle has run would otherwise throw inside an
	 * event listener and take the rest of it down.
	 */
	function resetAll(root) {
		var found = widgets(root);
		for (var i = 0; i < found.length; i++) {
			if (typeof found[i].reset === 'function') {
				try {
					found[i].reset();
				} catch (e) {
					// Ignore: a widget that cannot reset is still replaced on
					// the next page load, and throwing here would abort the
					// listener for the widgets after it.
				}
			}
		}
	}

	/**
	 * Show a failure message beside a widget that reported an error.
	 *
	 * Rendered as a sibling rather than inside the element: the widget uses a
	 * shadow root, so anything injected into it is not addressable, and it
	 * would be discarded on the next re-render anyway.
	 */
	function showFailure(widget, message) {
		var existing = widget.parentNode
			&& widget.parentNode.querySelector('.ffc-altcha-error');

		if (!existing) {
			existing = document.createElement('p');
			existing.className = 'ffc-altcha-error';
			existing.setAttribute('role', 'alert');
			if (widget.parentNode) {
				widget.parentNode.insertBefore(existing, widget.nextSibling);
			}
		}

		existing.textContent = message;
	}

	function clearFailure(widget) {
		var existing = widget.parentNode
			&& widget.parentNode.querySelector('.ffc-altcha-error');
		if (existing && existing.parentNode) {
			existing.parentNode.removeChild(existing);
		}
	}

	/**
	 * Watch each widget's state.
	 *
	 * `statechange` carries the widget's own lifecycle. ERROR is the one that
	 * needs a human-readable explanation; every other state either resolves
	 * on its own or is already visible in the widget.
	 */
	function watchStates() {
		document.addEventListener('statechange', function (event) {
			var widget = event.target;
			if (!widget || 'ALTCHA-WIDGET' !== widget.tagName) {
				return;
			}

			var state  = event.detail && event.detail.state;
			var config = cfg();

			if ('error' === String(state).toLowerCase()) {
				showFailure(
					widget,
					window.isSecureContext === false
						? (config.insecureMessage || 'Verification is unavailable on an insecure connection.')
						: (config.errorMessage || 'Verification failed. Please reload the page and try again.')
				);
				return;
			}

			clearFailure(widget);
		}, true);
	}

	registerStrings();
	watchStates();

	// The single wire from FFC.request — see ffc-core.js. Every server-side
	// rejection resets, regardless of which endpoint refused or why.
	document.addEventListener('ffc:request-rejected', function () {
		resetAll();
	});

	// `registerStrings` is exposed so the store can be re-seeded after the
	// widget bundle loads late, and so a test can exercise it without
	// re-running this module and stacking a second set of listeners.
	window.FFCCaptcha = { reset: resetAll, registerStrings: registerStrings };
})();
