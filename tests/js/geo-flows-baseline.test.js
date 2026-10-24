// Baseline of the two public flows that still run outside the visitor
// pre-flight queue (#2040): the audience check-in, which reads a position,
// and the appointment receipt, which builds a PDF in the browser.
//
// Both are about to move onto the queue. This file records what a visitor
// gets from them today across the dimensions the move touches (the device,
// whether the page is secure, what the GPS answers), and compares every
// outcome with tests/fixtures/geo-flows-baseline.json, so each later change
// is proven by the scenarios it lists and by nothing else moving.
//
// Regenerate only on the code being frozen:
//   FFC_UPDATE_PREFLIGHT_BASELINE=1 npx vitest run tests/js/geo-flows-baseline.test.js
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';
import { baseline } from './preflight-baseline-support.js';

const UAS = {
	android: { ua: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36', touch: 5 },
	iphone: { ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1', touch: 5 },
	androidInstagram: { ua: 'Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.81 Mobile Safari/537.36 Instagram 350.0.0.42.92 Android (34/14; 480dpi; 1080x2340; samsung; SM-S918B; dm3q; qcom; pt_BR; 640000000)', touch: 5 },
	iphoneInstagram: { ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 350.0.0.0.0 (iPhone15,2; iOS 17_6; pt_BR; pt; scale=3.00; 1179x2556; 0)', touch: 5 },
	desktop: { ua: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', touch: 0 },
};

/** What the device answers when asked for the position. */
const OUTCOMES = ['noApi', 'granted', 'denied', 'unavailable', 'timeout'];

const clean = (text) => String(text || '').replace(/\s+/g, ' ').trim();

let positionCalls;
let permissionQueries;
let alerts;
let generated;

/**
 * Stand the device up: user agent, secure context, geolocation and the
 * Permissions API, each counted so the record says what was asked.
 *
 * @param {string}  ua      UAS key.
 * @param {boolean} secure  Whether the page is a secure context.
 * @param {string}  outcome OUTCOMES member.
 */
function installDevice(ua, secure, outcome) {
	const define = (target, name, value) => Object.defineProperty(target, name, { value, configurable: true });
	define(window.navigator, 'userAgent', UAS[ua].ua);
	define(window.navigator, 'maxTouchPoints', UAS[ua].touch);
	define(window, 'isSecureContext', secure);
	define(window.navigator, 'permissions', {
		query() {
			permissionQueries++;
			return Promise.resolve({ state: 'prompt', onchange: null });
		},
	});

	if ('noApi' === outcome) {
		define(window.navigator, 'geolocation', undefined);
		return;
	}
	define(window.navigator, 'geolocation', {
		getCurrentPosition(success, error, options) {
			positionCalls.push(options || null);
			const fail = (code) => setTimeout(() => error({ code }), 50);
			if ('granted' === outcome) {
				setTimeout(() => success({ coords: { latitude: -23.55, longitude: -46.63, accuracy: 15 } }), 50);
			} else {
				fail({ denied: 1, unavailable: 2, timeout: 3 }[outcome]);
			}
		},
	});
}

function notices() {
	return Array.from(document.querySelectorAll('[data-ffc-notice]')).map((n) => ({
		hook: n.getAttribute('data-ffc-notice'),
		text: clean(n.textContent),
	}));
}

/**
 * One check-in visit: the page loads, the participant taps Confirm.
 *
 * @param {object} s Scenario.
 * @returns {Promise<object>}
 */
async function checkin(s) {
	installDevice(s.ua, s.secure, s.outcome);
	document.body.innerHTML =
		'<form method="post"' + (s.geofence ? ' data-ffc-checkin-geo="1"' : '') + '>' +
		'<input type="hidden" name="ffc_lat" value=""><input type="hidden" name="ffc_lng" value="">' +
		'<p class="ffc-checkin-geo-error" hidden></p>' +
		'<button type="submit" class="ffc-btn ffc-btn-primary ffc-checkin-submit">Confirm</button>' +
		'</form>';
	const form = document.querySelector('form');
	let posted = 0;
	form.submit = () => { posted++; };

	loadScript('assets/js/ffc-audience-checkin.js');
	await vi.advanceTimersByTimeAsync(1000);
	const beforeTap = { notices: notices(), permissionQueries, positionCalls: positionCalls.length };

	const event = new Event('submit', { cancelable: true });
	form.dispatchEvent(event);
	await vi.advanceTimersByTimeAsync(60000);

	const error = form.querySelector('.ffc-checkin-geo-error');
	const button = form.querySelector('.ffc-checkin-submit');
	return {
		beforeTap,
		nativePost: !event.defaultPrevented,
		scriptPosts: posted,
		lat: form.elements.ffc_lat.value,
		lng: form.elements.ffc_lng.value,
		error: error.hidden ? null : clean(error.textContent),
		buttonDisabled: button.disabled,
		buttonLoading: button.classList.contains('ffc-btn-loading'),
		positionOptions: positionCalls,
		permissionQueries,
		notices: notices(),
	};
}

/**
 * One receipt download, on the receipt page or right after booking.
 *
 * @param {object} s Scenario.
 * @returns {Promise<object>}
 */
async function receipt(s) {
	installDevice(s.ua, true, 'granted');
	window.ffcGeneratePDF = s.generator ? (data, filename) => generated.push(filename) : undefined;

	if ('page' === s.path) {
		document.body.innerHTML =
			'<div id="ffc-receipt-content"><p>Receipt</p></div>' +
			'<button id="ffc-download-pdf-btn" class="ffc-btn ffc-btn-primary">Download</button>';
		window.ffcReceiptData = { pdfData: { html: '<b>x</b>', filename: 'r.pdf' }, errorMsg: 'Generator missing.' };
		loadScript('assets/js/ffc-self-scheduling-receipt.js');
		// jQuery runs a ready callback on a timer even when the document is
		// already loaded.
		await vi.advanceTimersByTimeAsync(10);
		document.getElementById('ffc-download-pdf-btn').click();
	} else {
		document.body.innerHTML =
			'<div class="ffc-booking-form-wrapper"><form></form></div>' +
			'<div class="ffc-confirmation-wrapper" style="display:none;"><div class="ffc-confirmation-success"><h3>Done</h3></div>' +
			'<div class="ffc-appointment-details"></div>' +
			'<div class="ffc-confirmation-email" style="display:none;"></div></div>' +
			'<div id="ffc-self-scheduling-modal"><div class="ffc-modal-content"><div class="ffc-modal-title"></div></div></div>';
		window.ffcCalendar = { nonce: 'n', ajaxurl: '/wp-admin/admin-ajax.php', strings: { downloadReceipt: 'Download receipt', months: [] } };
		loadScript('assets/js/ffc-calendar-frontend.js');
		// The controller binds its handlers from its own ready callback.
		await vi.advanceTimersByTimeAsync(10);
		const ctl = window.ffcCalendarFrontend;
		ctl.selectedDate = '2027-03-10';
		ctl.selectedTime = '09:00';
		ctl.showConfirmation({ pdf_data: { html: '<b>x</b>', filename: 'booking.pdf' } });
		if ('button' === s.path) {
			await vi.advanceTimersByTimeAsync(1000);
			generated.length = 0;
			document.querySelector('.ffc-download-receipt-btn').click();
		}
	}
	await vi.advanceTimersByTimeAsync(1000);

	return {
		generated: generated.slice(),
		alerts: alerts.slice(),
		notices: notices(),
		permissionQueries,
	};
}

function scenarios() {
	const list = [];
	Object.keys(UAS).forEach((ua) => [true, false].forEach((secure) => {
		OUTCOMES.forEach((outcome) => list.push({ flow: 'checkin', ua, secure, geofence: true, outcome }));
		list.push({ flow: 'checkin', ua, secure, geofence: false, outcome: 'granted' });
	}));
	Object.keys(UAS).forEach((ua) => ['page', 'auto', 'button'].forEach((path) => [true, false].forEach((generator) =>
		list.push({ flow: 'receipt', ua, path, generator })
	)));
	return list;
}

function key(s) {
	return 'checkin' === s.flow
		? ['checkin', s.ua, s.secure ? 'https' : 'http', s.geofence ? 'geo' : 'nogeo', s.outcome].join('|')
		: ['receipt', s.ua, s.path, s.generator ? 'generator' : 'nogenerator'].join('|');
}

describe('geolocated and PDF flows outside the queue: baseline (#2040)', () => {
	let realFFC;

	beforeAll(() => {
		loadScript('assets/js/ffc-core.js');
		realFFC = window.FFC;
	});

	beforeEach(() => {
		vi.useFakeTimers({ now: new Date('2027-03-10T12:00:00'), toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] });
		window.$.fx.off = true;
		window.$.fn.animate = function () { return this; };
		vi.spyOn(window, 'alert').mockImplementation((m) => alerts.push(String(m)));
		vi.spyOn(window.console, 'error').mockImplementation(() => {});
	});

	afterEach(() => {
		vi.useRealTimers();
		vi.restoreAllMocks();
	});

	it('reproduces every recorded outcome', async () => {
		const records = {};
		for (const s of scenarios()) {
			positionCalls = [];
			permissionQueries = 0;
			alerts = [];
			generated = [];
			window.$(document).off();
			window.FFC = realFFC;
			window.ffcCheckin = { denied: 'Allow the location to check in.', unavailable: 'The location could not be read.' };
			delete window.ffcCalendarFrontend;
			records[key(s)] = 'checkin' === s.flow ? await checkin(s) : await receipt(s);
		}

		const { expected, actual } = baseline('geo-flows-baseline.json', records);

		// The scan must have read something, and the same set of things.
		expect(Object.keys(actual).length).toBe(scenarios().length);
		expect(Object.keys(actual).sort()).toEqual(Object.keys(expected).sort());

		const differing = Object.keys(expected).filter((k) => JSON.stringify(expected[k]) !== JSON.stringify(actual[k]));
		expect(differing.slice(0, 10), differing.length + ' scenario(s) changed').toEqual([]);
	}, 120000);
});
