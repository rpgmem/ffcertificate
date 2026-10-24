// The GPS fields a certificate submission carries (#2040).
//
// The server decides the GPS area by what the browser sends with the
// submission: the reading the form was shown on, the failure the
// "When GPS fails" policy let through, or a fresh reading when the form
// showed from a cached pass or the reading has gone stale. The browser also
// applies the server's accuracy rule before it shows the form.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-preflight.js');
	loadScript('assets/js/ffc-geofence-frontend.js');
	loadScript('assets/js/ffc-geofence-datetime.js');
	loadScript('assets/js/ffc-geofence-gps.js');
	loadScript('assets/js/ffc-geofence-permission.js');
});

const AREA = { lat: -23.55, lng: -46.63, radius: 200 };

function mount(geo) {
	document.body.innerHTML = `
		<div id="ffc-form-7" class="ffc-form-wrapper">
			<div class="ffc-form-title">title</div>
			<form class="ffc-submission-form"><input type="hidden" name="form_id" value="7"></form>
		</div>
	`;
	window.ffcGeofenceConfig = {
		7: { geo: Object.assign({ enabled: true, gpsEnabled: true, areas: [AREA], cacheTtl: 600, hideMode: 'message', gpsFallback: {} }, geo || {}) },
		_global: { strings: {} },
	};
	return window.$('#ffc-form-7');
}

function fields(query) {
	return Object.fromEntries(new URLSearchParams(query.replace(/^&/, '')));
}

function setGeolocation(impl) {
	Object.defineProperty(window.navigator, 'geolocation', { value: impl, configurable: true });
}

describe('FFCGeofence.submissionFields', () => {
	let now;

	beforeEach(() => {
		now = 1800000000000;
		vi.spyOn(Date, 'now').mockImplementation(() => now);
		window.$.fx.off = true;
	});

	afterEach(() => {
		vi.restoreAllMocks();
	});

	it('sends nothing for a form without a GPS check', async () => {
		const $w = mount({ gpsEnabled: false });
		expect(await window.FFCGeofence.submissionFields($w.find('form'))).toBe('');
	});

	it('sends the reading the form was shown on, with its age', async () => {
		const $w = mount();
		window.FFCGeofence.checkLocation($w, { latitude: -23.5501, longitude: -46.6301, accuracy: 12 }, window.ffcGeofenceConfig[7].geo);
		now += 4000;

		expect(fields(await window.FFCGeofence.submissionFields($w.find('form')))).toEqual({
			ffc_geo_lat: '-23.5501',
			ffc_geo_lng: '-46.6301',
			ffc_geo_accuracy: '12',
			ffc_geo_age: '4000',
		});
	});

	it('sends the failure the policy let through instead of a reading', async () => {
		const $w = mount({ gpsFallback: { permissionDenied: true } });
		setGeolocation({ getCurrentPosition: (_ok, fail) => fail({ code: 1, PERMISSION_DENIED: 1, POSITION_UNAVAILABLE: 2, TIMEOUT: 3 }) });
		window.FFCGeofence.validateGeolocation($w, window.ffcGeofenceConfig[7].geo, true);

		expect($w.hasClass('ffc-validated')).toBe(true);
		expect(fields(await window.FFCGeofence.submissionFields($w.find('form')))).toEqual({ ffc_geo_status: 'permission_denied' });
	});

	it('sends no_api when the browser has no geolocation and the policy allows it', async () => {
		const $w = mount({ gpsFallback: { noApi: true } });
		setGeolocation(undefined);
		window.FFCGeofence.validateGeolocation($w, window.ffcGeofenceConfig[7].geo, true);

		expect(fields(await window.FFCGeofence.submissionFields($w.find('form')))).toEqual({ ffc_geo_status: 'no_api' });
	});

	it('reads the position again when the form showed from a cached pass', async () => {
		const $w = mount();
		let asked = null;
		setGeolocation({
			getCurrentPosition: (ok, _fail, options) => {
				asked = options;
				ok({ coords: { latitude: -23.55, longitude: -46.63, accuracy: 8 } });
			},
		});

		const sent = fields(await window.FFCGeofence.submissionFields($w.find('form')));

		// The browser may answer from its own cache, up to the lifetime.
		expect(asked.maximumAge).toBe(600000);
		expect(sent).toEqual({ ffc_geo_lat: '-23.55', ffc_geo_lng: '-46.63', ffc_geo_accuracy: '8', ffc_geo_age: '0' });
	});

	it('reads the position again when the reading is older than the cache lifetime', async () => {
		const $w = mount();
		window.FFCGeofence.checkLocation($w, { latitude: -23.55, longitude: -46.63, accuracy: 12 }, window.ffcGeofenceConfig[7].geo);
		now += 600001;
		setGeolocation({ getCurrentPosition: (ok) => ok({ coords: { latitude: -23.5502, longitude: -46.63, accuracy: 9 } }) });

		expect(fields(await window.FFCGeofence.submissionFields($w.find('form'))).ffc_geo_lat).toBe('-23.5502');
	});

	it('sends the failure when the fresh reading fails', async () => {
		const $w = mount();
		setGeolocation({ getCurrentPosition: (_ok, fail) => fail({ code: 3 }) });

		expect(fields(await window.FFCGeofence.submissionFields($w.find('form')))).toEqual({ ffc_geo_status: 'timeout' });
	});
});

describe('FFCGeofence.checkLocation: the accuracy rule, per area (#2040, #2070)', () => {
	afterEach(() => {
		vi.restoreAllMocks();
	});

	it('blocks a reading that cannot tell inside from outside when the policy blocks position_unavailable', () => {
		const $w = mount();
		// 350 m of error in a 200 m area: past 1.5 × the radius.
		window.FFCGeofence.checkLocation($w, { latitude: -23.55, longitude: -46.63, accuracy: 350 }, window.ffcGeofenceConfig[7].geo);

		expect($w.hasClass('ffc-validated')).toBe(false);
		expect($w.text()).toContain('not precise enough');
		expect($w.text()).toContain('On a computer, open this page on your phone');
	});

	it('shows the form with a notice for an unsure reading when the policy allows position_unavailable', () => {
		const $w = mount({ gpsFallback: { positionUnavailable: true } });
		window.FFCGeofence.checkLocation($w, { latitude: -23.55, longitude: -46.63, accuracy: 350 }, window.ffcGeofenceConfig[7].geo);

		expect($w.hasClass('ffc-validated')).toBe(true);
		const notice = $w.find('[data-ffc-notice="geofence-imprecise"]');
		expect(notice.length).toBe(1);
		expect(notice.text()).toContain('allows access anyway');
	});

	it('takes an error up to 1.5 × the radius as inside, saying the reading is weak', () => {
		const $w = mount();
		window.FFCGeofence.checkLocation($w, { latitude: -23.55, longitude: -46.63, accuracy: 300 }, window.ffcGeofenceConfig[7].geo);

		expect($w.hasClass('ffc-validated')).toBe(true);
		expect($w.find('[data-ffc-notice="geofence-imprecise"]').text()).toContain('accepted, but with low precision');
	});

	it('reads the tolerance the server sends', () => {
		const $w = mount({ tolerance: 1 });
		window.FFCGeofence.checkLocation($w, { latitude: -23.55, longitude: -46.63, accuracy: 201 }, window.ffcGeofenceConfig[7].geo);

		expect($w.hasClass('ffc-validated')).toBe(false);
		expect($w.text()).toContain('not precise enough');
	});

	it('judges each area with its own radius', () => {
		// A 50 m area in Rio and a 100 m one in Sao Paulo.
		const $w = mount({ areas: [{ lat: -22.9, lng: -43.2, radius: 50 }, { lat: -23.55, lng: -46.63, radius: 100 }] });
		window.FFCGeofence.checkLocation($w, { latitude: -23.55, longitude: -46.63, accuracy: 80 }, window.ffcGeofenceConfig[7].geo);

		expect($w.hasClass('ffc-validated')).toBe(true);
	});

	it('refuses as outside an imprecise reading far from every area, even when unsure readings are allowed', () => {
		const $w = mount({ gpsFallback: { positionUnavailable: true }, messageBlocked: 'Outside the area.' });
		// Belo Horizonte with 2 km of error.
		window.FFCGeofence.checkLocation($w, { latitude: -19.92, longitude: -43.94, accuracy: 2000 }, window.ffcGeofenceConfig[7].geo);

		expect($w.hasClass('ffc-validated')).toBe(false);
		expect($w.text()).toContain('Outside the area.');
	});

	it('treats a point outside whose error reaches the area as unsure', () => {
		const $w = mount();
		// About 300 m north of the 200 m area's centre, 150 m of error.
		window.FFCGeofence.checkLocation($w, { latitude: -23.5473, longitude: -46.63, accuracy: 150 }, window.ffcGeofenceConfig[7].geo);

		expect($w.text()).toContain('not precise enough');
	});

	it('says so when the form opens on a weak reading, above half the radius (#2072)', () => {
		const $w = mount();
		// 101 m of error in a 200 m area: inside, but weak.
		window.FFCGeofence.checkLocation($w, { latitude: -23.55, longitude: -46.63, accuracy: 101 }, window.ffcGeofenceConfig[7].geo);

		expect($w.hasClass('ffc-validated')).toBe(true);
		const notice = $w.find('[data-ffc-notice="geofence-imprecise"]');
		expect(notice.length).toBe(1);
		expect(notice.text()).toContain('about 101 m)');
	});

	it('writes the error with one decimal only when it has one (#2074)', () => {
		const $w = mount();
		window.FFCGeofence.checkLocation($w, { latitude: -23.55, longitude: -46.63, accuracy: 120.44 }, window.ffcGeofenceConfig[7].geo);

		expect($w.find('[data-ffc-notice="geofence-imprecise"]').text()).toContain('about 120.4 m)');
		expect(window.FFCGeofence.metres(101.04)).toBe('101');
	});

	it('says nothing at or below half the radius', () => {
		const $w = mount();
		window.FFCGeofence.checkLocation($w, { latitude: -23.55, longitude: -46.63, accuracy: 100 }, window.ffcGeofenceConfig[7].geo);

		expect($w.hasClass('ffc-validated')).toBe(true);
		expect($w.find('[data-ffc-notice="geofence-imprecise"]').length).toBe(0);
	});

	it('judges a weak reading by the area that holds it best', () => {
		// 60 m of error: weak for the 100 m area, comfortable for the 200 m one.
		const $w = mount({ areas: [{ lat: -23.55, lng: -46.63, radius: 100 }, { lat: -23.5501, lng: -46.6301, radius: 200 }] });
		window.FFCGeofence.checkLocation($w, { latitude: -23.55, longitude: -46.63, accuracy: 60 }, window.ffcGeofenceConfig[7].geo);

		expect($w.find('[data-ffc-notice="geofence-imprecise"]').length).toBe(0);
	});

	it('reads the share the server sends', () => {
		const $w = mount({ weakShare: 0.8 });
		// 101 m of error in a 200 m area is below 0.8 × the radius.
		window.FFCGeofence.checkLocation($w, { latitude: -23.55, longitude: -46.63, accuracy: 101 }, window.ffcGeofenceConfig[7].geo);

		expect($w.find('[data-ffc-notice="geofence-imprecise"]').length).toBe(0);
	});
});
