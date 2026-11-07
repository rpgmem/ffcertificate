// #2341 — the geofence date/GPS modules load only for a form that restricts
// by date or location. A cached page whose form gained such a restriction
// since carries the core alone; the form must still open, and the server's
// check of the submission decides.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-preflight.js');
	loadScript('assets/js/ffc-geofence-frontend.js');
});

beforeEach(() => {
	document.body.innerHTML = '<div id="ffc-form-42"><form></form></div>';
	window.$.fx.off = true;
});

describe('FFCGeofence without its modules', () => {
	it('has no module methods when only the core is loaded', () => {
		expect(typeof window.FFCGeofence.validateDateTime).toBe('undefined');
		expect(typeof window.FFCGeofence.validateGeolocation).toBe('undefined');
	});

	it('opens a GPS form instead of calling a module that is not there', () => {
		const wrapper = window.$('#ffc-form-42');
		expect(() =>
			window.FFCGeofence.processGeoOrShow(wrapper, { geo: { enabled: true, gpsEnabled: true } })
		).not.toThrow();
		expect(wrapper.hasClass('ffc-validated')).toBe(true);
	});

	it('skips the date step instead of calling a module that is not there', () => {
		const ran = [];
		window.FFC.Preflight.register('probe', {
			run: (ctx, next) => {
				ran.push('probe');
				next();
			},
		});
		const ctx = {
			wrapper: window.$('#ffc-form-42'),
			config: { datetime: { enabled: true } },
		};
		expect(() => window.FFC.Preflight.run(['datetime', 'probe'], ctx)).not.toThrow();
		expect(ran).toEqual(['probe']);
	});
});
