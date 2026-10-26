// The PR 4 steps of the visitor pre-flight (#2030): the `browser` step (an
// app's own browser, a browser missing what the form needs), `secure` for
// the ALTCHA-only captcha, the queue on a form with no restriction, the
// "already submitted" notice after the queue, and the boot watchdog's
// telemetry.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const UAS = {
	androidChrome: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
	androidInstagram: 'Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.81 Mobile Safari/537.36 Instagram 350.0.0.42.92 Android',
	iphoneWhatsApp: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 WhatsApp/24.20.71',
	desktop: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
};

let logged;

beforeAll(() => {
	loadScript('assets/js/ffc-core.js');
	delete window.FFC.Preflight;
	loadScript('assets/js/ffc-preflight.js');
	loadScript('assets/js/ffc-geofence-frontend.js');
	loadScript('assets/js/ffc-geofence-datetime.js');
	loadScript('assets/js/ffc-geofence-gps.js');
	loadScript('assets/js/ffc-geofence-permission.js');
});

beforeEach(() => {
	logged = [];
	window.FFC.request = vi.fn((action, data) => {
		logged.push(action + ':' + data.reason);
		return Promise.resolve({});
	});
	window.sessionStorage.clear();
	window.$.fx.off = true;
	useUa('androidChrome');
});

afterEach(() => {
	document.body.innerHTML = '';
	window.ffcGeofenceConfig = undefined;
});

function useUa(name) {
	Object.defineProperty(window.navigator, 'userAgent', { value: UAS[name], configurable: true });
	Object.defineProperty(window.navigator, 'maxTouchPoints', { value: name.indexOf('iphone') === 0 ? 5 : 0, configurable: true });
}

function mountForm(id = 7) {
	document.body.innerHTML = '<div class="ffc-shortcode ffc-form-wrapper" id="ffc-form-' + id + '">'
		+ '<h2 class="ffc-form-title">Course</h2>'
		+ '<form class="ffc-submission-form"><input type="hidden" name="form_id" value="' + id + '"></form>'
		+ '</div>';
	return window.$('#ffc-form-' + id);
}

function context($w, extra = {}) {
	return Object.assign({ wrapper: $w, config: { datetime: { enabled: false }, geo: { enabled: false } }, browserCheck: true, text: (_k, f) => f }, extra);
}

function notice($w) {
	return $w.find('[data-ffc-notice="preflight-browser"]');
}

describe('browser step (#2030)', () => {
	it('lets an ordinary browser through, and logs nothing', () => {
		const $w = mountForm();
		const next = vi.fn();
		window.FFC.Preflight.register('after-ordinary', { run: next });
		window.FFC.Preflight.run(['browser', 'after-ordinary'], context($w));
		expect(next).toHaveBeenCalledTimes(1);
		expect(notice($w).length).toBe(0);
		expect(logged).toEqual([]);
	});

	it('stops an Android in-app browser, offers Chrome, and lets the visitor continue once', () => {
		useUa('androidInstagram');
		const $w = mountForm();
		const after = vi.fn();
		window.FFC.Preflight.register('after-android', { run: after });
		window.FFC.Preflight.run(['browser', 'after-android'], context($w));

		expect(notice($w).length).toBe(1);
		expect(notice($w).text()).toContain('Chrome, Firefox, Edge, Opera or Samsung Internet');
		expect(notice($w).find('.ffc-preflight-open-browser').length).toBe(1);
		expect($w.find('.ffc-submission-form').css('display')).toBe('none');
		expect(after).not.toHaveBeenCalled();
		expect(logged).toEqual(['ffc_log_preflight_bail:browser_inapp']);

		notice($w).find('.ffc-preflight-continue').trigger('click');
		expect(notice($w).length).toBe(0);
		expect(after).toHaveBeenCalledTimes(1);
		expect(logged).toEqual(['ffc_log_preflight_bail:browser_inapp', 'ffc_log_preflight_bail:browser_inapp_continue']);
		expect(window.sessionStorage.getItem('ffc_inapp_continue')).toBe('1');
	});

	it('does not stop again a visitor who continued in this session', () => {
		useUa('androidInstagram');
		window.sessionStorage.setItem('ffc_inapp_continue', '1');
		const $w = mountForm();
		const after = vi.fn();
		window.FFC.Preflight.register('after-continued', { run: after });
		window.FFC.Preflight.run(['browser', 'after-continued'], context($w));
		expect(notice($w).length).toBe(0);
		expect(after).toHaveBeenCalledTimes(1);
	});

	it('names the iOS browsers and gives the menu steps, with no Chrome button', () => {
		useUa('iphoneWhatsApp');
		const $w = mountForm();
		window.FFC.Preflight.run(['browser'], context($w));
		expect(notice($w).text()).toContain('Safari, Chrome, Firefox or Edge');
		expect(notice($w).text()).toContain('Open in Safari');
		expect(notice($w).find('.ffc-preflight-open-browser').length).toBe(0);
		expect(notice($w).find('.ffc-preflight-continue').length).toBe(1);
	});

	it('stops a browser missing a feature, with no way through', () => {
		const original = window.FormData;
		window.FormData = undefined;
		try {
			expect(window.FFC.Preflight.missingFeatures()).toEqual(['FormData']);
			const $w = mountForm();
			const after = vi.fn();
			window.FFC.Preflight.register('after-features', { run: after });
			window.FFC.Preflight.run(['browser', 'after-features'], context($w));
			expect(notice($w).text()).toContain('cannot run the form');
			expect(notice($w).find('button').length).toBe(0);
			expect(after).not.toHaveBeenCalled();
			expect(logged).toEqual(['ffc_log_preflight_bail:browser_features']);
		} finally {
			window.FormData = original;
		}
	});

	it('finds every feature in a complete browser', () => {
		expect(window.FFC.Preflight.missingFeatures()).toEqual([]);
	});

	it('is skipped when the setting is off, for an administrator under bypass, and on content without GPS', () => {
		useUa('androidInstagram');
		const cases = [
			{ browserCheck: false },
			{ config: { adminBypass: true, datetime: { enabled: false }, geo: { enabled: false } } },
			{ content: true },
		];
		cases.forEach((extra, i) => {
			const $w = mountForm(20 + i);
			const after = vi.fn();
			window.FFC.Preflight.register('after-skip-' + i, { run: after });
			window.FFC.Preflight.run(['browser', 'after-skip-' + i], context($w, extra));
			expect(notice($w).length).toBe(0);
			expect(after).toHaveBeenCalledTimes(1);
		});
	});

	it('runs on content that asks for the location', () => {
		useUa('androidInstagram');
		const $w = mountForm(30);
		window.FFC.Preflight.run(['browser'], context($w, { content: true, config: { geo: { enabled: true, gpsEnabled: true } } }));
		expect(notice($w).length).toBe(1);
	});
});

describe('the queue on every form (#2030)', () => {
	function configure(formConfig, global = {}) {
		window.ffcGeofenceConfig = { 7: formConfig, _global: Object.assign({ strings: {} }, global) };
	}

	it('checks cookies on a form with no restriction', () => {
		Object.defineProperty(window.navigator, 'cookieEnabled', { value: false, configurable: true });
		try {
			const $w = mountForm();
			configure({ formId: 7, adminBypass: false, bypassInfo: null, datetime: { enabled: false }, geo: { enabled: false } });
			window.FFCGeofence.processForm('7', window.ffcGeofenceConfig[7]);
			expect($w.find('[data-ffc-notice="preflight-cookies"]').length).toBe(1);
			expect(logged).toEqual(['ffc_log_preflight_bail:cookies']);
		} finally {
			Object.defineProperty(window.navigator, 'cookieEnabled', { value: true, configurable: true });
		}
	});

	it('shows a form with no restriction, and says the queue passed', () => {
		const $w = mountForm();
		const passed = vi.fn();
		$w.on('ffc:preflight-passed', passed);
		configure({ formId: 7, adminBypass: false, bypassInfo: null, datetime: { enabled: false }, geo: { enabled: false } });
		window.FFCGeofence.processForm('7', window.ffcGeofenceConfig[7]);
		expect($w.hasClass('ffc-validated')).toBe(true);
		expect(passed).toHaveBeenCalledTimes(1);
		expect($w.find('[data-ffc-notice]').length).toBe(0);
	});

	it('runs the browser step before the cookies, and the setting turns it off', () => {
		useUa('androidInstagram');
		const $w = mountForm();
		configure({ formId: 7, adminBypass: false, bypassInfo: null, datetime: { enabled: false }, geo: { enabled: false } });
		window.FFCGeofence.processForm('7', window.ffcGeofenceConfig[7]);
		expect(notice($w).length).toBe(1);

		const $off = mountForm(8);
		window.ffcGeofenceConfig = { 8: { formId: 8, adminBypass: false, datetime: { enabled: false }, geo: { enabled: false } }, _global: { strings: {}, browserCheck: false } };
		window.FFCGeofence.processForm('8', window.ffcGeofenceConfig[8]);
		expect(notice($off).length).toBe(0);
		expect($off.hasClass('ffc-validated')).toBe(true);
	});
});

describe('secure step for the ALTCHA-only captcha (#2030)', () => {
	function installLocation(protocol, hostname) {
		const original = window.location;
		Object.defineProperty(window, 'location', { configurable: true, writable: true, value: { protocol, hostname, pathname: '/', href: protocol + '//' + hostname + '/' } });
		return () => Object.defineProperty(window, 'location', { configurable: true, writable: true, value: original });
	}

	// The geofence's context: it says when a form needs a secure page.
	function formContext($w, extra) {
		return Object.assign(window.FFCGeofence.preflightContext($w, context($w).config), extra);
	}

	it('blocks a form on an insecure page when the captcha is ALTCHA only', () => {
		const restore = installLocation('http:', 'example.org');
		try {
			const $w = mountForm();
			window.FFC.Preflight.run(['secure', 'geo'], formContext($w, { altchaOnly: true }));
			expect($w.find('[data-ffc-notice="geofence-blocked"]').text()).toContain('secure connection (HTTPS). Please contact');
			expect($w.hasClass('ffc-validated')).toBe(false);
			expect(logged).toEqual(['ffc_log_preflight_bail:secure']);
		} finally {
			restore();
		}
	});

	it('lets the form through on HTTPS, in the composite mode, and on gated content', () => {
		const restore = installLocation('http:', 'example.org');
		try {
			const $composite = mountForm(9);
			window.FFC.Preflight.run(['secure', 'geo'], formContext($composite, { altchaOnly: false }));
			expect($composite.hasClass('ffc-validated')).toBe(true);
		} finally {
			restore();
		}
		const restoreHttps = installLocation('https:', 'example.org');
		try {
			const $w = mountForm(10);
			window.FFC.Preflight.run(['secure', 'geo'], formContext($w, { altchaOnly: true }));
			expect($w.hasClass('ffc-validated')).toBe(true);
			expect(logged).toEqual([]);
		} finally {
			restoreHttps();
		}
	});
});

describe('the queue\'s secure step (#2040)', () => {
	afterEach(() => {
		delete window.isSecureContext;
	});

	it('asks the browser, not the protocol', () => {
		// The page is http://localhost in the tests: the old protocol check
		// let it through, the browser's own answer does not.
		Object.defineProperty(window, 'isSecureContext', { configurable: true, value: false });
		const $w = mountForm(21);
		const next = vi.fn();
		window.FFC.Preflight.register('after-secure', { run: next });
		window.FFC.Preflight.run(['secure', 'after-secure'], context($w, { needsSecure: true }));

		expect(next).not.toHaveBeenCalled();
		expect($w.find('[data-ffc-notice="preflight-secure"]').text()).toContain('secure connection (HTTPS)');
		expect($w.find('.ffc-submission-form').css('display')).toBe('none');
		expect(logged).toEqual(['ffc_log_preflight_bail:secure']);
	});

	it('lets a secure context through, and skips a caller that needs none', () => {
		Object.defineProperty(window, 'isSecureContext', { configurable: true, value: true });
		const $w = mountForm(22);
		const next = vi.fn();
		window.FFC.Preflight.register('after-secure-2', { run: next });
		window.FFC.Preflight.run(['secure', 'after-secure-2'], context($w, { needsSecure: true }));

		Object.defineProperty(window, 'isSecureContext', { configurable: true, value: false });
		window.FFC.Preflight.run(['secure', 'after-secure-2'], context($w, { needsSecure: false }));

		expect(next).toHaveBeenCalledTimes(2);
		expect(logged).toEqual([]);
	});

	it('hands the message to the caller when it has one', () => {
		Object.defineProperty(window, 'isSecureContext', { configurable: true, value: false });
		const $w = mountForm(23);
		const onInsecure = vi.fn();
		window.FFC.Preflight.run(['secure'], context($w, { needsSecure: () => true, onInsecure }));

		expect(onInsecure).toHaveBeenCalledTimes(1);
		expect($w.find('[data-ffc-notice="preflight-secure"]').length).toBe(0);
	});

	it('falls back to the protocol in a browser too old to say', () => {
		expect(window.isSecureContext).toBeUndefined();
		expect(window.FFC.Preflight.secureContext()).toBe(window.location.protocol === 'https:' || window.location.hostname === 'localhost');
	});
});

describe('boot watchdog telemetry (#2030)', () => {
	it('logs browser_boot once when it takes a form away', () => {
		vi.useFakeTimers();
		try {
			document.body.innerHTML = '<div class="ffc-form-wrapper" id="ffc-form-7">'
				+ '<template data-ffc-boot-notice><div data-ffc-notice="boot-failed">x</div></template>'
				+ '<form class="ffc-submission-form"><button type="submit">Go</button></form></div>';
			const request = window.FFC.request;
			delete window.FFC.Preflight;
			loadScript('assets/js/ffc-preflight.js');
			window.FFC.request = request;
			vi.advanceTimersByTime(window.FFC.Preflight.DEADLINE_MS + 1);
			expect(logged).toEqual(['ffc_log_preflight_bail:browser_boot']);
		} finally {
			vi.useRealTimers();
		}
	});
});
