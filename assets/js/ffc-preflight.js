/**
 * Visitor pre-flight (#2030): the boot watchdog.
 *
 * A certificate form is submitted over AJAX and its PDF is drawn in the
 * browser, so a script that did not load (a content blocker, a dropped
 * request) or a captcha and nonce refresh that did not finish leaves a form
 * that looks usable and fails on submit. The watchdog waits for the parts
 * that make the form work to report in, and marks each form `data-ffc-ready`
 * once they have. When they have not by the deadline it shows the notice the
 * server printed in the form's `<template data-ffc-boot-notice>`, takes the
 * form away and disables its submit button. A part that reports late undoes
 * all of it, so a slow network costs a moment of notice and never the form.
 *
 * The parts:
 * - `frontend`: ffc-frontend.js finished its setup with the request helper
 *   and the PDF libraries it submits through;
 * - `fragments`: ffc-dynamic-fragments.js applied the fresh captcha and
 *   nonces, or found nothing to refresh. Expected only where that script's
 *   config (`ffcDynamic`) is on the page.
 *
 * No dependencies on purpose: this file has to run when the ones it watches
 * did not. A part reports with `FFC.Preflight.ready( name )`, which may
 * happen before or after the watchdog starts.
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
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
