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
 *   4. Say how long to wait when the challenge endpoint refuses a network
 *      over its cap (#2339). The widget fetches the challenge itself and only
 *      throws on a 429, so each widget is given a fetch that reads the
 *      refusal's `Retry-After` first. Asking for a reload there would ask for
 *      one more challenge and be refused again.
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
			return;
		}

		// The bundle loads first — it has to, since it creates the store this
		// writes to — so by now each widget has already resolved its language
		// against a store that did not yet contain ours, and fallen back to
		// English. Re-applying `language` through the element's own
		// `configure()` is what makes it resolve again, now that the entry
		// exists. Cheap, and idempotent when the strings were already in
		// place.
		reapplyLanguage(config.language);
	}

	/**
	 * Push the language through each widget's published configure() method.
	 *
	 * @param {string} language Key registered in the i18n store.
	 */
	function reapplyLanguage(language) {
		var found = widgets();
		for (var i = 0; i < found.length; i++) {
			if (typeof found[i].configure === 'function') {
				try {
					found[i].configure({ language: language });
				} catch (e) {
					// An element that has not upgraded yet gets the language
					// from its own attribute anyway; nothing to recover.
				}
			}
		}
	}

	/**
	 * Seconds a 429 response asks the caller to wait, or 0 when it says none.
	 *
	 * The header first, the body's `retryAfter` second: a server that had
	 * already sent output cannot add the header, and the body is read from a
	 * clone so the widget can still read its own copy.
	 *
	 * @param {Response} response
	 * @returns {Promise<number>}
	 */
	function retryAfter(response) {
		var header = parseInt(response.headers && response.headers.get('Retry-After'), 10);
		if (header > 0) {
			return Promise.resolve(header);
		}
		if (typeof response.clone !== 'function') {
			return Promise.resolve(0);
		}
		return response.clone().json().then(function (body) {
			var seconds = parseInt(body && body.retryAfter, 10);
			return seconds > 0 ? seconds : 0;
		}, function () {
			return 0;
		});
	}

	/**
	 * A fetch for one widget that notes when the endpoint refused it.
	 *
	 * The note is the moment the widget may ask again, kept on the element so
	 * the error state, which carries no reason, can tell a refusal from any
	 * other failure.
	 *
	 * @param {Element} widget
	 * @returns {Function}
	 */
	function fetchFor(widget) {
		return function (url, init) {
			return window.fetch(url, init).then(function (response) {
				if (429 !== response.status) {
					widget.ffcRetryAt = 0;
					return response;
				}
				return retryAfter(response).then(function (seconds) {
					widget.ffcRetryAt = Date.now() + Math.max(1, seconds) * 1000;
					return response;
				});
			});
		};
	}

	/**
	 * Give each widget the fetch that reads a refusal's wait.
	 *
	 * Once per element: `configure()` merges, so the fetch stays while other
	 * options change.
	 */
	function installFetch() {
		if (typeof window.fetch !== 'function') {
			return;
		}
		var found = widgets();
		for (var i = 0; i < found.length; i++) {
			if (found[i].ffcFetchInstalled || typeof found[i].configure !== 'function') {
				continue;
			}
			try {
				found[i].configure({ fetch: fetchFor(found[i]) });
				found[i].ffcFetchInstalled = true;
			} catch (e) {
				// The widget then keeps its own fetch and the generic message.
			}
		}
	}

	/**
	 * A wait in words: seconds under a minute, else minutes, else hours.
	 *
	 * @param {number} seconds
	 * @returns {string}
	 */
	function duration(seconds) {
		seconds = Math.max(1, Math.round(seconds));
		if (seconds < 60) {
			return seconds + ' s';
		}
		if (seconds < 3600) {
			return Math.ceil(seconds / 60) + ' min';
		}
		return Math.ceil(seconds / 3600) + ' h';
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

	/**
	 * Say how long to wait, then let the visitor try again by themselves.
	 *
	 * When the wait is over the message goes and the widget returns to its
	 * unverified state, so a click asks for a challenge in the new window —
	 * no reload, which on a long form would lose what was typed.
	 *
	 * @param {Element} widget
	 * @param {number}  seconds
	 */
	function showLimited(widget, seconds) {
		var config  = cfg();
		var message = (config.limitedMessage || 'Too many verification requests from this network. Try again in %s.')
			.replace('%s', duration(seconds));

		showFailure(widget, message);

		if (widget.ffcRetryTimer) {
			clearTimeout(widget.ffcRetryTimer);
		}
		widget.ffcRetryTimer = setTimeout(function () {
			widget.ffcRetryTimer = null;
			widget.ffcRetryAt    = 0;
			clearFailure(widget);
			if (typeof widget.reset === 'function') {
				try {
					widget.reset();
				} catch (e) {
					// A widget that cannot reset still takes a click.
				}
			}
		}, Math.ceil(seconds) * 1000);
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
				var wait = widget.ffcRetryAt ? (widget.ffcRetryAt - Date.now()) / 1000 : 0;
				if (wait > 0) {
					showLimited(widget, wait);
					return;
				}
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
	installFetch();
	watchStates();

	// A widget that upgrades after this module ran resolves its language on
	// its own, against a store that already carries our entry — but a page
	// where the elements are not in the DOM yet would have had nothing to
	// re-configure above, so try once more when it is.
	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', function () {
			reapplyLanguage(cfg().language);
			installFetch();
		});
	}

	// The single wire from FFC.request — see ffc-core.js. Every server-side
	// rejection resets, regardless of which endpoint refused or why.
	document.addEventListener('ffc:request-rejected', function () {
		resetAll();
	});

	// `registerStrings` is exposed so the store can be re-seeded after the
	// widget bundle loads late, and so a test can exercise it without
	// re-running this module and stacking a second set of listeners.
	window.FFCCaptcha = { reset: resetAll, registerStrings: registerStrings, installFetch: installFetch };
})();
