// The participant's check-in page (#1947): the location is read before the
// form posts, and a refusal says why instead of posting without it.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function page(geo) {
	document.body.innerHTML =
		'<form method="post" class="ffc-checkin-form"' + (geo ? ' data-ffc-checkin-geo="1"' : '') + '>' +
		'<input type="hidden" name="ffc_lat" value=""><input type="hidden" name="ffc_lng" value=""><input type="hidden" name="ffc_accuracy" value="">' +
		'<p class="ffc-checkin-geo-error" hidden></p>' +
		'<button type="submit" class="ffc-checkin-submit">Confirm</button>' +
		'</form>';
	return document.querySelector('form');
}

function setGeolocation(impl) {
	Object.defineProperty(window.navigator, 'geolocation', { value: impl, configurable: true });
}

describe('ffc-audience-checkin.js', () => {
	beforeEach(() => {
		window.ffcCheckin = { denied: 'Allow it.', unavailable: 'Not read.' };
	});

	it('fills the position and posts once it is read', () => {
		const form = page(true);
		const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => {});
		setGeolocation({ getCurrentPosition: (ok) => ok({ coords: { latitude: -23.5, longitude: -46.6, accuracy: 12 } }) });
		loadScript('assets/js/ffc-audience-checkin.js');

		form.dispatchEvent(new Event('submit', { cancelable: true }));

		expect(form.elements.ffc_lat.value).toBe('-23.5');
		expect(form.elements.ffc_lng.value).toBe('-46.6');
		expect(form.elements.ffc_accuracy.value).toBe('12');
		expect(submitSpy).toHaveBeenCalledTimes(1);
	});

	it('does not post and says why when the location is refused', () => {
		const form = page(true);
		const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => {});
		setGeolocation({ getCurrentPosition: (ok, fail) => fail({ code: 1 }) });
		loadScript('assets/js/ffc-audience-checkin.js');

		const event = new Event('submit', { cancelable: true });
		form.dispatchEvent(event);

		const error = form.querySelector('.ffc-checkin-geo-error');
		expect(event.defaultPrevented).toBe(true);
		expect(submitSpy).not.toHaveBeenCalled();
		expect(error.hidden).toBe(false);
		expect(error.textContent).toBe('Allow it.');
		expect(form.querySelector('.ffc-checkin-submit').disabled).toBe(false);
	});

	it('reports an unreadable location when the browser has no geolocation', () => {
		const form = page(true);
		setGeolocation(undefined);
		loadScript('assets/js/ffc-audience-checkin.js');

		form.dispatchEvent(new Event('submit', { cancelable: true }));

		expect(form.querySelector('.ffc-checkin-geo-error').textContent).toBe('Not read.');
	});

	it('leaves a form without a geofence alone', () => {
		const form = page(false);
		loadScript('assets/js/ffc-audience-checkin.js');

		const event = new Event('submit', { cancelable: true });
		form.dispatchEvent(event);

		expect(event.defaultPrevented).toBe(false);
	});
});

describe('ffc-audience-checkin.js: the visitor pre-flight (#2040)', () => {
	const UA = {
		desktop: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
		instagram: 'Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.81 Mobile Safari/537.36 Instagram 350.0.0.42.92 Android',
	};
	let sent;

	function card() {
		document.body.innerHTML = '<main class="ffc-checkin-card">' + document.body.innerHTML + '</main>';
		return document.querySelector('form');
	}

	function device({ ua = 'desktop', secure = true, permission = 'prompt' } = {}) {
		Object.defineProperty(window.navigator, 'userAgent', { value: UA[ua], configurable: true });
		Object.defineProperty(window, 'isSecureContext', { value: secure, configurable: true });
		Object.defineProperty(window.navigator, 'permissions', {
			value: { query: () => Promise.resolve({ state: permission }) },
			configurable: true,
		});
	}

	const flush = () => new Promise((r) => setTimeout(r, 0));
	const notice = (hook) => document.querySelector('[data-ffc-notice="' + hook + '"]');

	beforeAll(() => {
		loadScript('assets/js/ffc-core.js');
		delete window.FFC.Preflight;
		loadScript('assets/js/ffc-preflight.js');
	});

	beforeEach(() => {
		window.sessionStorage.clear();
		sent = [];
		window.FFC.request = (action, data) => {
			sent.push(data);
			return Promise.resolve({});
		};
		window.ffcCheckin = {
			denied: 'Allow it.',
			unavailable: 'Not read.',
			telemetry: { type: 'booking', id: 31 },
			strings: { secureRequired: 'This check-in needs HTTPS.' },
		};
	});

	afterEach(() => {
		delete window.isSecureContext;
		Object.defineProperty(window.navigator, 'permissions', { value: undefined, configurable: true });
	});

	it('leaves an ordinary secure browser alone and logs nothing', async () => {
		device();
		page(true);
		const form = card();
		loadScript('assets/js/ffc-audience-checkin.js');
		await flush();

		expect(document.querySelector('[data-ffc-notice]')).toBeNull();
		expect(window.$(form).css('display')).not.toBe('none');
		expect(sent).toEqual([]);
	});

	it('stops an insecure page with its own message, logged against the booking', async () => {
		device({ secure: false });
		page(true);
		const form = card();
		loadScript('assets/js/ffc-audience-checkin.js');
		await flush();

		expect(notice('preflight-secure').textContent).toContain('This check-in needs HTTPS.');
		expect(window.$(form).css('display')).toBe('none');
		expect(sent).toEqual([{ type: 'booking', id: 31, reason: 'secure' }]);
	});

	it('asks an app\'s own browser to switch, and shows the form on "Continue anyway"', async () => {
		device({ ua: 'instagram' });
		page(true);
		const form = card();
		loadScript('assets/js/ffc-audience-checkin.js');
		await flush();

		expect(notice('preflight-browser')).not.toBeNull();
		expect(window.$(form).css('display')).toBe('none');
		notice('preflight-browser').querySelector('.ffc-preflight-continue').click();
		await flush();

		expect(notice('preflight-browser')).toBeNull();
		expect(window.$(form).css('display')).not.toBe('none');
		expect(sent.map((d) => d.reason)).toEqual(['browser_inapp', 'browser_inapp_continue']);
	});

	it('says a refused permission before the tap', async () => {
		device({ permission: 'denied' });
		page(true);
		const form = card();
		loadScript('assets/js/ffc-audience-checkin.js');
		await flush();

		const error = form.querySelector('.ffc-checkin-geo-error');
		expect(error.hidden).toBe(false);
		expect(error.textContent).toBe('Allow it.');
		expect(sent).toEqual([{ type: 'booking', id: 31, reason: 'gps_denied' }]);
	});

	it('does not ask about the PDF features a check-in never uses', async () => {
		device();
		const blob = window.Blob;
		window.Blob = undefined;
		try {
			page(true);
			card();
			loadScript('assets/js/ffc-audience-checkin.js');
			await flush();
			expect(notice('preflight-browser')).toBeNull();
		} finally {
			window.Blob = blob;
		}
	});
});
