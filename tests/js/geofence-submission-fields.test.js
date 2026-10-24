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

describe('FFCGeofence.checkLocation: the accuracy rule (#2040)', () => {
	afterEach(() => {
		vi.restoreAllMocks();
	});

	it('blocks a reading less precise than the smallest area when the policy blocks position_unavailable', () => {
		const $w = mount();
		window.FFCGeofence.checkLocation($w, { latitude: -23.55, longitude: -46.63, accuracy: 350 }, window.ffcGeofenceConfig[7].geo);

		expect($w.hasClass('ffc-validated')).toBe(false);
		expect($w.text()).toContain('not precise enough');
	});

	it('shows the form for an imprecise reading when the policy allows position_unavailable', () => {
		const $w = mount({ gpsFallback: { positionUnavailable: true } });
		window.FFCGeofence.checkLocation($w, { latitude: -23.55, longitude: -46.63, accuracy: 350 }, window.ffcGeofenceConfig[7].geo);

		expect($w.hasClass('ffc-validated')).toBe(true);
	});

	it('takes a reading exactly as precise as the area', () => {
		const $w = mount();
		window.FFCGeofence.checkLocation($w, { latitude: -23.55, longitude: -46.63, accuracy: 200 }, window.ffcGeofenceConfig[7].geo);

		expect($w.hasClass('ffc-validated')).toBe(true);
	});
});
