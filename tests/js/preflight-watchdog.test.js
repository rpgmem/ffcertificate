// Tests for the boot watchdog in `assets/js/ffc-preflight.js` (#2030), and
// for the two scripts that report to it: `ffc-dynamic-fragments.js` and
// `ffc-frontend.js`.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const NOTICE = '<div class="ffc-notice ffc-notice-warning" role="alert" data-ffc-notice="boot-failed">'
	+ '<div class="ffc-notice-body"><p class="ffc-notice-text">Part of this form did not load.</p>'
	+ '<div class="ffc-notice-actions"><button type="button" class="ffc-btn ffc-btn-primary ffc-icon-sync ffc-boot-reload">Reload the page</button></div>'
	+ '</div></div>';

/**
 * One certificate form, as the shortcode prints it.
 *
 * @param {string} [extra] Markup appended to the form.
 */
function mountForm(extra = '') {
	document.body.innerHTML = '<div class="ffc-shortcode ffc-form-wrapper" id="ffc-form-7">'
		+ '<noscript></noscript>'
		+ '<template data-ffc-boot-notice>' + NOTICE + '</template>'
		+ '<h2 class="ffc-form-title">Course</h2>'
		+ '<form class="ffc-submission-form" method="post"><input name="name">'
		+ '<button type="submit" class="ffc-submit-btn">Submit</button>' + extra + '</form>'
		+ '</div>';
	return document.getElementById('ffc-form-7');
}

/**
 * Load the watchdog fresh: it installs itself once per window.
 */
function loadWatchdog() {
	if (window.FFC) {
		delete window.FFC.Preflight;
	}
	loadScript('assets/js/ffc-preflight.js');
	return window.FFC.Preflight;
}

describe('boot watchdog (#2030)', () => {
	beforeEach(() => {
		vi.useFakeTimers();
		delete window.ffcDynamic;
	});

	afterEach(() => {
		vi.useRealTimers();
		document.body.innerHTML = '';
		delete window.ffcDynamic;
	});

	it('marks the form ready when every part reports in time, and shows nothing', () => {
		window.ffcDynamic = { ajaxUrl: '/wp-admin/admin-ajax.php' };
		const wrapper = mountForm();
		const watchdog = loadWatchdog();

		watchdog.ready('frontend');
		expect(wrapper.hasAttribute('data-ffc-ready')).toBe(false);
		watchdog.ready('fragments');
		expect(wrapper.getAttribute('data-ffc-ready')).toBe('1');

		vi.advanceTimersByTime(watchdog.DEADLINE_MS * 2);
		expect(wrapper.querySelector(':scope > [data-ffc-notice="boot-failed"]')).toBeNull();
		expect(wrapper.classList.contains('ffc-boot-failed')).toBe(false);
		expect(wrapper.querySelector('[type="submit"]').disabled).toBe(false);
	});

	it('waits for the fragments only where their script is on the page', () => {
		const wrapper = mountForm();
		const watchdog = loadWatchdog();

		watchdog.ready('frontend');
		expect(wrapper.getAttribute('data-ffc-ready')).toBe('1');
	});

	it('blocks the form at the deadline: notice above the title, class, submit disabled', () => {
		window.ffcDynamic = { ajaxUrl: '/wp-admin/admin-ajax.php' };
		const wrapper = mountForm();
		const watchdog = loadWatchdog();
		watchdog.ready('frontend');

		vi.advanceTimersByTime(watchdog.DEADLINE_MS - 1);
		expect(wrapper.classList.contains('ffc-boot-failed')).toBe(false);

		vi.advanceTimersByTime(1);
		expect(watchdog.expired()).toBe(true);
		const notice = wrapper.querySelector(':scope > [data-ffc-notice="boot-failed"]');
		expect(notice).not.toBeNull();
		expect(notice.nextElementSibling.classList.contains('ffc-form-title')).toBe(true);
		expect(wrapper.classList.contains('ffc-boot-failed')).toBe(true);
		expect(wrapper.querySelector('[type="submit"]').disabled).toBe(true);
		expect(wrapper.hasAttribute('data-ffc-ready')).toBe(false);
	});

	it('undoes the block when the late part reports, leaving a button it did not disable alone', () => {
		const wrapper = mountForm('<button type="submit" class="other" disabled>Other</button>');
		const watchdog = loadWatchdog();

		vi.advanceTimersByTime(watchdog.DEADLINE_MS);
		expect(wrapper.classList.contains('ffc-boot-failed')).toBe(true);

		watchdog.ready('frontend');
		expect(wrapper.classList.contains('ffc-boot-failed')).toBe(false);
		expect(wrapper.querySelector('[data-ffc-notice="boot-failed"]')).toBeNull();
		expect(wrapper.querySelector('.ffc-submit-btn').disabled).toBe(false);
		expect(wrapper.querySelector('.other').disabled).toBe(true);
		expect(wrapper.getAttribute('data-ffc-ready')).toBe('1');
	});

	it('draws the notice once, however often it is checked', () => {
		const wrapper = mountForm();
		const watchdog = loadWatchdog();
		vi.advanceTimersByTime(watchdog.DEADLINE_MS * 3);
		expect(wrapper.querySelectorAll('[data-ffc-notice="boot-failed"]').length).toBe(1);
	});

	it('reloads the page from the notice button', () => {
		const wrapper = mountForm();
		const reload = vi.fn();
		const original = window.location;
		Object.defineProperty(window, 'location', { configurable: true, value: { reload } });
		try {
			const watchdog = loadWatchdog();
			vi.advanceTimersByTime(watchdog.DEADLINE_MS);
			wrapper.querySelector('.ffc-boot-reload').click();
			expect(reload).toHaveBeenCalledTimes(1);
		} finally {
			Object.defineProperty(window, 'location', { configurable: true, value: original });
		}
	});

	it('does nothing on a page without a certificate form', () => {
		document.body.innerHTML = '<div class="ffc-form-wrapper"><form class="ffc-verification-form"></form></div>';
		const watchdog = loadWatchdog();
		vi.advanceTimersByTime(watchdog.DEADLINE_MS * 2);
		expect(document.querySelector('[data-ffc-notice="boot-failed"]')).toBeNull();
		expect(watchdog.expired()).toBe(false);
	});

	it('keeps a namespace the core will merge, and installs only once', () => {
		mountForm();
		const first = loadWatchdog();
		loadScript('assets/js/ffc-preflight.js');
		expect(window.FFC.Preflight).toBe(first);
	});
});

describe('the dynamic fragments report to the watchdog (#2030)', () => {
	const RealXHR = window.XMLHttpRequest;
	let xhr;

	beforeEach(() => {
		window.ffcDynamic = { ajaxUrl: '/wp-admin/admin-ajax.php' };
		window.FFC = { Preflight: { ready: vi.fn() } };
		xhr = null;
		window.XMLHttpRequest = function () {
			xhr = this;
			this.open = () => {};
			this.setRequestHeader = () => {};
			this.send = () => {};
		};
	});

	afterEach(() => {
		window.XMLHttpRequest = RealXHR;
		delete window.ffcDynamic;
		delete window.FFC;
		document.body.innerHTML = '';
	});

	it('reports once the fresh values are applied', () => {
		mountForm('<div class="ffc-security-container"></div>');
		loadScript('assets/js/ffc-dynamic-fragments.js');
		expect(window.FFC.Preflight.ready).not.toHaveBeenCalled();

		xhr.status = 200;
		xhr.responseText = JSON.stringify({ success: true, data: { nonces: {} } });
		xhr.onload();
		expect(window.FFC.Preflight.ready).toHaveBeenCalledWith('fragments');
	});

	it('does not report a refresh that failed', () => {
		mountForm('<div class="ffc-security-container"></div>');
		loadScript('assets/js/ffc-dynamic-fragments.js');

		xhr.status = 500;
		xhr.onload();
		xhr.status = 200;
		xhr.responseText = JSON.stringify({ success: false });
		xhr.onload();
		xhr.responseText = 'not json';
		xhr.onload();
		expect(window.FFC.Preflight.ready).not.toHaveBeenCalled();
	});

	it('reports at once when the page has nothing to refresh', () => {
		document.body.innerHTML = '<p>No form here</p>';
		loadScript('assets/js/ffc-dynamic-fragments.js');
		expect(window.FFC.Preflight.ready).toHaveBeenCalledWith('fragments');
		expect(xhr).toBeNull();
	});

	it('reports at once when no endpoint is known', () => {
		delete window.ffcDynamic;
		mountForm('<div class="ffc-security-container"></div>');
		loadScript('assets/js/ffc-dynamic-fragments.js');
		expect(window.FFC.Preflight.ready).toHaveBeenCalledWith('fragments');
	});
});
