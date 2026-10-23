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
 * Steps registered here: `cookies` (the probe and its banner). The
 * geofence script registers `datetime`, `secure` and `geo`. The context
 * carries the wrapper (jQuery), the config, and `text(key, fallback)` for
 * the localized strings.
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
	 * @param {jQuery} wrapper Form wrapper (`#ffc-form-<id>`).
	 * @param {string} reason  `cookies` | `gps_denied` | `gps_prompt`
	 */
	function log(wrapper, reason) {
		try {
			var formId = parseInt((wrapper.attr('id') || '').replace('ffc-form-', ''), 10);
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
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
