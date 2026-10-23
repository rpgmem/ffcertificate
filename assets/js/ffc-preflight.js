/**
 * Visitor pre-flight (#2030): the queue of checks a visitor's browser runs
 * before a form or gated content is shown, and the boot watchdog.
 *
 * THE QUEUE. A step is registered once, by name, with `applies(ctx)` and
 * `run(ctx, next)`; `FFC.Preflight.run(names, ctx)` walks the names in
 * order, skips a step that does not apply, and stops at the first step that
 * does not call `next`. A step that blocks draws its own message and simply
 * returns; one that offers "Try anyway" passes `next` to the button, so the
 * queue carries on from the step after it. The order is the caller's, so a
 * form and gated content can run different lists through the same steps.
 *
 * Steps registered here: `browser` (an app's own browser, or one missing
 * what the form needs) and `cookies` (the probe and its banner). The
 * geofence script registers `datetime`, `secure` and `geo`. The context
 * carries the wrapper (jQuery), the config, `text(key, fallback)` for the
 * localized strings, `content` for gated content, `browserCheck` (the
 * setting that turns the browser step off) and `altchaOnly`.
 *
 * THE WATCHDOG. A certificate form is submitted over AJAX and its PDF is
 * drawn in the browser, so a script that did not load (a content blocker, a
 * dropped request) or a captcha and nonce refresh that did not finish
 * leaves a form that looks usable and fails on submit. The watchdog waits
 * for the parts that make the form work to report in, and marks each form
 * `data-ffc-ready` once they have. When they have not by the deadline it
 * shows the notice the server printed in the form's
 * `<template data-ffc-boot-notice>`, takes the form away and disables its
 * submit button. A part that reports late undoes all of it, so a slow
 * network costs a moment of notice and never the form.
 *
 * The parts:
 * - `frontend`: ffc-frontend.js finished its setup with the request helper
 *   and the PDF libraries it submits through;
 * - `fragments`: ffc-dynamic-fragments.js applied the fresh captcha and
 *   nonces, or found nothing to refresh. Expected only where that script's
 *   config (`ffcDynamic`) is on the page.
 *
 * No dependencies on purpose: the watchdog has to run when the scripts it
 * watches did not. The queue's steps run only when their callers do, which
 * is after jQuery and ffc-core are on the page. A part reports with
 * `FFC.Preflight.ready( name )`, which may happen before or after the
 * watchdog starts.
 */
(function () {
	'use strict';

	var ns = (window.FFC = window.FFC || {});
	if (ns.Preflight) {
		return;
	}

	/**
	 * How long the parts have, from DOMContentLoaded. Long enough for the
	 * captcha and nonce refresh on a slow mobile connection; a false alarm
	 * is undone the moment the part reports anyway.
	 */
	var DEADLINE_MS = 10000;

	var reported = {};
	var started = false;
	var expired = false;
	var timer = null;

	/**
	 * Every certificate form on the page that carries the watchdog notice.
	 *
	 * @returns {Element[]}
	 */
	function wrappers() {
		var list = [];
		var nodes = document.querySelectorAll('.ffc-form-wrapper');
		for (var i = 0; i < nodes.length; i++) {
			if (nodes[i].querySelector('template[data-ffc-boot-notice]') && nodes[i].querySelector('form.ffc-submission-form')) {
				list.push(nodes[i]);
			}
		}
		return list;
	}

	/**
	 * The parts this page waits for.
	 *
	 * @returns {string[]}
	 */
	function expected() {
		var parts = ['frontend'];
		if (typeof window.ffcDynamic !== 'undefined') {
			parts.push('fragments');
		}
		return parts;
	}

	/**
	 * Whether every expected part has reported.
	 *
	 * @returns {boolean}
	 */
	function complete() {
		var parts = expected();
		for (var i = 0; i < parts.length; i++) {
			if (!reported[parts[i]]) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The submit buttons of a form.
	 *
	 * @param {Element} wrapper Form wrapper.
	 * @returns {NodeListOf<Element>}
	 */
	function submits(wrapper) {
		return wrapper.querySelectorAll('form.ffc-submission-form [type="submit"]');
	}

	/**
	 * Show the notice and take the form away.
	 *
	 * @param {Element} wrapper Form wrapper.
	 */
	function block(wrapper) {
		if (wrapper.classList.contains('ffc-boot-failed')) {
			return;
		}
		var template = wrapper.querySelector('template[data-ffc-boot-notice]');
		var notice = document.importNode(template.content, true).firstElementChild;
		if (notice) {
			var title = wrapper.querySelector('.ffc-form-title');
			wrapper.insertBefore(notice, title && title.parentNode === wrapper ? title : wrapper.firstChild);
			var reload = notice.querySelector('.ffc-boot-reload');
			if (reload) {
				reload.addEventListener('click', function () {
					window.location.reload();
				});
			}
		}
		var buttons = submits(wrapper);
		for (var i = 0; i < buttons.length; i++) {
			if (!buttons[i].disabled) {
				buttons[i].disabled = true;
				buttons[i].setAttribute('data-ffc-boot-disabled', '1');
			}
		}
		wrapper.classList.add('ffc-boot-failed');
		// Only when the request helper loaded: the log goes through it.
		log(wrapper, 'browser_boot');
	}

	/**
	 * Undo {@link block}: a part reported after the deadline.
	 *
	 * @param {Element} wrapper Form wrapper.
	 */
	function unblock(wrapper) {
		if (!wrapper.classList.contains('ffc-boot-failed')) {
			return;
		}
		var notice = wrapper.querySelector(':scope > [data-ffc-notice="boot-failed"]');
		if (notice) {
			notice.parentNode.removeChild(notice);
		}
		var buttons = wrapper.querySelectorAll('[data-ffc-boot-disabled]');
		for (var i = 0; i < buttons.length; i++) {
			buttons[i].disabled = false;
			buttons[i].removeAttribute('data-ffc-boot-disabled');
		}
		wrapper.classList.remove('ffc-boot-failed');
	}

	/**
	 * Mark the forms ready when every part is in.
	 */
	function settle() {
		if (!started || !complete()) {
			return;
		}
		if (timer) {
			clearTimeout(timer);
			timer = null;
		}
		var list = wrappers();
		for (var i = 0; i < list.length; i++) {
			unblock(list[i]);
			list[i].setAttribute('data-ffc-ready', '1');
		}
	}

	/**
	 * The deadline passed: block every form not yet ready.
	 */
	function expire() {
		timer = null;
		expired = true;
		if (complete()) {
			settle();
			return;
		}
		var list = wrappers();
		for (var i = 0; i < list.length; i++) {
			if (!list[i].hasAttribute('data-ffc-ready')) {
				block(list[i]);
			}
		}
	}

	/**
	 * Start the watch: on a page with no certificate form there is nothing
	 * to watch.
	 */
	function start() {
		if (started) {
			return;
		}
		started = true;
		if (!wrappers().length) {
			return;
		}
		if (complete()) {
			settle();
			return;
		}
		timer = setTimeout(expire, DEADLINE_MS);
	}

	// ------------------------------------------------------------------
	// The queue.
	// ------------------------------------------------------------------

	var steps = {};

	/**
	 * Register a step.
	 *
	 * @param {string} name Step name.
	 * @param {{applies: function(object): boolean, run: function(object, function)}} step
	 */
	function register(name, step) {
		steps[name] = step;
	}

	/**
	 * Run the named steps in order until one does not call `next`.
	 *
	 * @param {string[]} names Step names, in order.
	 * @param {object}   ctx   Context every step receives.
	 */
	function run(names, ctx) {
		var index = 0;
		// A newer run on the same wrapper supersedes this one: a step that
		// waits on something asynchronous (the GPS permission query) reads
		// this and drops a stale answer, so a recheck while it waits does
		// not draw its message twice.
		if (ctx && ctx.wrapper && typeof ctx.wrapper.data === 'function') {
			ctx.wrapper.data('ffcPreflightRun', (ctx.wrapper.data('ffcPreflightRun') || 0) + 1);
		}
		(function next() {
			while (index < names.length) {
				var step = steps[names[index++]];
				if (!step || (typeof step.applies === 'function' && !step.applies(ctx))) {
					continue;
				}
				var called = false;
				step.run(ctx, function () {
					// A step continues the queue once, however often its
					// "Try anyway" is reached.
					if (!called) {
						called = true;
						next();
					}
				});
				return;
			}
		})();
	}

	/**
	 * Fire-and-forget telemetry: one Activity Log row per banner shown
	 * (`ffc_log_preflight_bail`). Any failure is swallowed — the banner is
	 * the visitor's feedback, the log only the administrator's.
	 *
	 * @param {jQuery|Element} wrapper Form wrapper (`#ffc-form-<id>`).
	 * @param {string}         reason  `cookies` | `gps_denied` | `gps_prompt` |
	 *                                 `secure` | `browser_inapp` |
	 *                                 `browser_inapp_continue` |
	 *                                 `browser_features` | `browser_boot`
	 */
	function log(wrapper, reason) {
		try {
			var id = wrapper && wrapper.jquery ? wrapper.attr('id') : wrapper && wrapper.id;
			var formId = parseInt((id || '').replace('ffc-form-', ''), 10);
			if (!formId || isNaN(formId)) {
				return;
			}
			if (typeof window.FFC.request === 'function') {
				window.FFC.request('ffc_log_preflight_bail', { form_id: formId, reason: reason }).catch(function () {
					// swallow
				});
			}
		} catch (e) {
			// swallow — telemetry must never break the form flow
		}
	}

	/**
	 * The warning banner of a step that can be passed anyway (cookies, GPS
	 * denied, GPS prompt): a title, the explanation, the platform's how-to
	 * when there is one, and the button. The form is hidden behind it until
	 * the visitor fixes the setting and reloads, or presses the button.
	 *
	 * @param {jQuery}   wrapper      Form or content wrapper.
	 * @param {string}   hook         `preflight-cookies` | `preflight-gps`
	 * @param {string}   title        Banner title.
	 * @param {string}   body         Explanation.
	 * @param {string}   instructions How-to for this platform; may be empty.
	 * @param {string}   ctaLabel     Button label.
	 * @param {Function} onCta        Called after the banner is removed.
	 */
	function banner(wrapper, hook, title, body, instructions, ctaLabel, onCta) {
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'ffc-preflight-try-anyway';
		button.textContent = ctaLabel;

		var notice = window.FFC.notice('warning', 'warning', [body, instructions], title, {
			role: 'alert',
			live: 'assertive',
			hook: hook,
			children: [button]
		});
		button.addEventListener('click', function () {
			if (notice.parentNode) {
				notice.parentNode.removeChild(notice);
			}
			onCta();
		});
		wrapper.find('.ffc-submission-form, .ffc-form').hide();
		// One banner per hook: a second one replaces the first.
		wrapper.find('[data-ffc-notice="' + hook + '"]').remove();
		wrapper.prepend(notice);
	}

	/**
	 * Whether the browser keeps a first-party cookie: the write-and-read
	 * round trip, which `navigator.cookieEnabled` alone does not prove.
	 * Partitioning that strips cookies later in the session is handled on
	 * the server (the nonce auto-recovery), not here.
	 *
	 * @returns {boolean}
	 */
	function cookiesWork() {
		try {
			if (typeof navigator !== 'undefined' && navigator.cookieEnabled === false) {
				return false;
			}
			if (typeof document === 'undefined') {
				return false;
			}
			var probe = 'ffc_cookie_probe=1';
			document.cookie = probe + '; path=/; SameSite=Lax; max-age=10';
			var present = document.cookie.split('; ').some(function (c) {
				return c.indexOf(probe) === 0;
			});
			// Do not leave the probe in the visitor's cookie jar.
			document.cookie = 'ffc_cookie_probe=; path=/; SameSite=Lax; max-age=0';
			return present;
		} catch (e) {
			// Some sandboxed contexts throw on `document.cookie`.
			return false;
		}
	}

	register('cookies', {
		// The session the nonce is bound to lives in a cookie. An
		// administrator under bypass is not stopped by it.
		applies: function (ctx) {
			return !(ctx.config && ctx.config.adminBypass === true);
		},
		run: function (ctx, next) {
			if (cookiesWork()) {
				next();
				return;
			}
			log(ctx.wrapper, 'cookies');
			var os = window.FFC.Platform.os();
			banner(
				ctx.wrapper,
				'preflight-cookies',
				ctx.text('cookieBlockedTitle', 'Cookies blocked'),
				ctx.text('cookieBlockedBody', 'Cookies are blocked in this browser. Without cookies, the form submission may fail. Enable cookies for this site, or try a different browser.'),
				ctx.text(os === 'ios' ? 'cookieBlockedHowIos' : os === 'android' ? 'cookieBlockedHowAndroid' : 'cookieBlockedHowDesktop', ''),
				ctx.text('cookieTryAnyway', 'Try anyway'),
				next
			);
		}
	});

	/**
	 * The browsers to name on a platform, as the localized list.
	 *
	 * @param {object} ctx Step context.
	 * @returns {string}
	 */
	function browserList(ctx) {
		var os = window.FFC.Platform.os();
		if (os === 'android') {
			return ctx.text('browsersAndroid', 'Chrome, Firefox, Edge, Opera or Samsung Internet');
		}
		if (os === 'ios') {
			return ctx.text('browsersIos', 'Safari, Chrome, Firefox or Edge');
		}
		return ctx.text('browsersOther', 'Chrome, Firefox, Edge or Safari');
	}

	/**
	 * The features the form cannot work without that this browser lacks:
	 * the request and form-data APIs it submits through, and the canvas and
	 * Blob the PDF is drawn with. Only their presence is read; drawing on a
	 * canvas here would cost every visitor a render for a rare case.
	 *
	 * @returns {string[]} Names of the missing features; empty when none.
	 */
	function missingFeatures() {
		var missing = [];
		if (typeof window.Promise !== 'function') {
			missing.push('Promise');
		}
		if (typeof window.FormData !== 'function') {
			missing.push('FormData');
		}
		if (typeof window.fetch !== 'function' && typeof window.XMLHttpRequest !== 'function') {
			missing.push('fetch');
		}
		if (typeof window.Blob !== 'function') {
			missing.push('Blob');
		}
		try {
			var canvas = document.createElement('canvas');
			if (typeof canvas.getContext !== 'function' || typeof canvas.toDataURL !== 'function') {
				missing.push('canvas');
			}
		} catch (e) {
			missing.push('canvas');
		}
		return missing;
	}

	/**
	 * Session flag set by "Continue anyway", so the visitor who chose to go
	 * on is not stopped again on the next page of the same visit.
	 */
	var CONTINUE_KEY = 'ffc_inapp_continue';

	function continued() {
		try {
			return window.sessionStorage.getItem(CONTINUE_KEY) === '1';
		} catch (e) {
			return false;
		}
	}

	function rememberContinue() {
		try {
			window.sessionStorage.setItem(CONTINUE_KEY, '1');
		} catch (e) {
			// Private mode: the choice lasts for this page only.
		}
	}

	/**
	 * Hand the page to Chrome from an Android in-app browser. Most WebViews
	 * follow an `intent://` link; one that refuses leaves the visitor where
	 * they are, with the menu instructions still on screen.
	 */
	function openInChrome() {
		var url = window.location.href.replace(/^https?:\/\//, '');
		try {
			window.location.href = 'intent://' + url + '#Intent;scheme=https;package=com.android.chrome;end';
		} catch (e) {
			// The instructions in the notice cover it.
		}
	}

	/**
	 * Take the form away behind a notice.
	 *
	 * @param {jQuery}      wrapper Form or content wrapper.
	 * @param {HTMLElement} notice  The notice.
	 */
	function hideBehind(wrapper, notice) {
		wrapper.find('.ffc-submission-form, .ffc-form').hide();
		wrapper.prepend(notice);
	}

	register('browser', {
		// Every form, and gated content that asks for the location. The
		// setting turns it off; an administrator under bypass is not stopped
		// by it, as by the cookie step.
		applies: function (ctx) {
			if (ctx.browserCheck === false || (ctx.config && ctx.config.adminBypass === true)) {
				return false;
			}
			if (ctx.content) {
				return !!(ctx.config && ctx.config.geo && ctx.config.geo.enabled && ctx.config.geo.gpsEnabled);
			}
			return true;
		},
		run: function (ctx, next) {
			var list = browserList(ctx);

			// A browser without the features cannot issue the certificate,
			// whatever the visitor chooses: there is no way through.
			if (missingFeatures().length) {
				log(ctx.wrapper, 'browser_features');
				hideBehind(ctx.wrapper, window.FFC.notice(
					'warning',
					'monitor',
					ctx.text('browserFeaturesBody', 'This browser is missing features the form needs to issue your certificate. Open this page in %s.').replace('%s', list),
					ctx.text('browserFeaturesTitle', 'This browser cannot run the form'),
					{ role: 'alert', live: 'assertive', hook: 'preflight-browser' }
				));
				return;
			}

			if (!window.FFC.Platform.inApp() || continued()) {
				next();
				return;
			}

			// An app's own browser: the location and the download are
			// unreliable there. Detection is by user agent and can misfire,
			// so the visitor may go on; the steps after this still run.
			log(ctx.wrapper, 'browser_inapp');
			var os = window.FFC.Platform.os();
			var actions = document.createElement('div');
			actions.className = 'ffc-notice-actions';
			if (os === 'android') {
				var open = window.FFC.button({
					label: ctx.text('openInChrome', 'Open in Chrome'),
					category: 'primary',
					icon: 'external',
					className: 'ffc-preflight-open-browser'
				});
				open.addEventListener('click', openInChrome);
				actions.appendChild(open);
			}
			var go = document.createElement('button');
			go.type = 'button';
			go.className = 'ffc-preflight-continue';
			go.textContent = ctx.text('continueAnyway', 'Continue anyway');
			actions.appendChild(go);

			var how = os === 'ios'
				? ctx.text('inAppHowIos', 'Tap the menu (••• or the share icon) and choose "Open in Safari" or "Open in browser".')
				: ctx.text('inAppHowAndroid', 'Or tap the menu (⋮) and choose "Open in browser".');
			var notice = window.FFC.notice(
				'warning',
				'phone',
				[ctx.text('inAppBody', 'You are viewing this page inside an app, where your location and the certificate download often fail. Open it in %s.').replace('%s', list), how],
				ctx.text('inAppTitle', 'Open this page in your browser'),
				{ role: 'alert', live: 'assertive', hook: 'preflight-browser', children: [actions] }
			);
			go.addEventListener('click', function () {
				log(ctx.wrapper, 'browser_inapp_continue');
				rememberContinue();
				if (notice.parentNode) {
					notice.parentNode.removeChild(notice);
				}
				next();
			});
			hideBehind(ctx.wrapper, notice);
		}
	});

	ns.Preflight = {
		/**
		 * Report that a part finished.
		 *
		 * @param {string} part `frontend` or `fragments`.
		 */
		ready: function (part) {
			reported[part] = true;
			settle();
		},

		/**
		 * Whether the deadline has passed. For tests and diagnostics.
		 *
		 * @returns {boolean}
		 */
		expired: function () {
			return expired;
		},

		/** The deadline, in milliseconds. */
		DEADLINE_MS: DEADLINE_MS,

		register: register,
		run: run,
		log: log,
		banner: banner,
		cookiesWork: cookiesWork,
		missingFeatures: missingFeatures,
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
