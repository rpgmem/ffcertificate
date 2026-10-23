// Shared harness for the visitor pre-flight baseline (#2030).
//
// The pre-flight is being moved out of the geofence scripts into a queue of
// its own, and the move must change nothing. This harness drives the
// scripts as they are today through every combination that decides what a
// visitor sees, and reduces each run to a plain record: what is shown, which
// notices, what was asked of the browser and what telemetry left. The
// records are frozen in tests/fixtures/, so a later change is proven by
// comparison, not by reading.
//
// A record describes the outcome, never the markup: a later refactor may
// build the same notice through another path, and that is not a change a
// visitor can see.
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { vi, beforeAll, beforeEach, afterEach } from 'vitest';
import { loadScript } from './helpers.js';

export const NOW = '2027-03-10T12:00:00';

export const AREA = { lat: -23.55, lng: -46.63, radius: 200 };

export const UAS = {
	android: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
	iphone: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1',
};

export const DATETIMES = {
	off: { enabled: false },
	open: { enabled: true, dateStart: '2027-03-01', dateEnd: '2027-03-31', timeStart: '', timeEnd: '', timeMode: 'daily' },
	before: { enabled: true, dateStart: '2027-04-01', dateEnd: '2027-04-30', timeStart: '', timeEnd: '', timeMode: 'daily' },
	after: { enabled: true, dateStart: '2027-02-01', dateEnd: '2027-02-28', timeStart: '', timeEnd: '', timeMode: 'daily' },
	hours: { enabled: true, dateStart: '2027-03-01', dateEnd: '2027-03-31', timeStart: '14:00', timeEnd: '16:00', timeMode: 'daily' },
};

export const PRESETS = {
	tolerant: { permissionDenied: true, noApi: true, positionUnavailable: true, timeout: true, safetyTimer: true },
	hybrid: { permissionDenied: true, noApi: true, positionUnavailable: false, timeout: false, safetyTimer: false },
	strict: { permissionDenied: false, noApi: false, positionUnavailable: false, timeout: false, safetyTimer: false },
};

/** What the device answers when asked for the position. */
export const OUTCOMES = ['noApi', 'inside', 'outside', 'denied', 'unavailable', 'timeout', 'silent'];

/** What the Permissions API says before anything is asked. */
export const PERMISSIONS = ['none', 'granted', 'prompt', 'denied'];

/**
 * The datetime block the server emits, with distinct hide modes per phase
 * so the record shows which one was picked.
 *
 * @param {string} key DATETIMES key.
 * @returns {object}
 */
export function datetimeConfig(key) {
	const base = DATETIMES[key];
	if (!base.enabled) {
		return { enabled: false };
	}
	return Object.assign({}, base, {
		message: '',
		hideModeBefore: 'message',
		hideModeDuring: 'title_message',
		hideModeAfter: 'hide',
	});
}

/**
 * The geo block the server emits.
 *
 * @param {string} kind   off | ip | gps.
 * @param {string} preset PRESETS key, for gps.
 * @returns {object}
 */
export function geoConfig(kind, preset) {
	if (kind === 'off') {
		return { enabled: false };
	}
	return {
		enabled: true,
		gpsEnabled: kind === 'gps',
		ipEnabled: kind === 'ip',
		areas: [AREA],
		gpsIpLogic: 'or',
		messageBlocked: '',
		messageError: '',
		hideMode: 'message',
		gpsFallback: PRESETS[preset || 'hybrid'],
		cacheEnabled: true,
		cacheTtl: 600,
	};
}

/**
 * Put a device and a browser in place for one run.
 *
 * @param {object} env
 * @param {string} env.ua         UAS key.
 * @param {boolean} env.cookies   Whether the browser keeps cookies.
 * @param {string} env.outcome    OUTCOMES member, or 'none' when geo is off.
 * @param {string} env.permission PERMISSIONS member.
 * @returns {{positionCalls: () => number, permissionQueries: () => number}}
 */
export function installDevice(env) {
	let positionCalls = 0;
	let permissionQueries = 0;
	const define = (name, value) => Object.defineProperty(window.navigator, name, { value, configurable: true });

	define('userAgent', UAS[env.ua]);
	define('maxTouchPoints', env.ua === 'iphone' ? 5 : 0);
	define('cookieEnabled', env.cookies);

	if (env.outcome === 'noApi') {
		define('geolocation', undefined);
	} else {
		define('geolocation', {
			getCurrentPosition(success, error) {
				positionCalls++;
				const codes = { PERMISSION_DENIED: 1, POSITION_UNAVAILABLE: 2, TIMEOUT: 3 };
				const fail = (code) => setTimeout(() => error(Object.assign({ code }, codes)), 50);
				switch (env.outcome) {
					case 'inside':
						setTimeout(() => success({ coords: { latitude: AREA.lat, longitude: AREA.lng, accuracy: 10 } }), 50);
						break;
					case 'outside':
						setTimeout(() => success({ coords: { latitude: -22.9, longitude: -43.2, accuracy: 10 } }), 50);
						break;
					case 'denied':
						fail(1);
						break;
					case 'unavailable':
						fail(2);
						break;
					case 'timeout':
						fail(3);
						break;
					default:
						// 'silent': the device never answers.
				}
			},
		});
	}

	if (env.permission === 'none') {
		define('permissions', undefined);
	} else {
		define('permissions', {
			query() {
				permissionQueries++;
				return Promise.resolve({ state: env.permission, onchange: null });
			},
		});
	}

	return {
		positionCalls: () => positionCalls,
		permissionQueries: () => permissionQueries,
	};
}

const clean = (text) => String(text || '').replace(/\s+/g, ' ').trim();

/**
 * What a visitor sees in a wrapper, reduced to a record.
 *
 * @param {jQuery} $w Wrapper.
 * @returns {object}
 */
export function snapshot($w) {
	const shown = (el) => !!el && el.style.display !== 'none';
	const wrapper = $w[0];
	const form = wrapper.querySelector('.ffc-submission-form');
	const title = wrapper.querySelector('.ffc-form-title');
	const loading = wrapper.querySelector('.ffc-geofence-loading-msg');
	return {
		validated: $w.hasClass('ffc-validated'),
		loading: $w.hasClass('ffc-geofence-loading'),
		wrapperShown: shown(wrapper),
		titleShown: title ? shown(title) : null,
		formShown: form ? shown(form) : null,
		loadingText: loading ? clean(loading.textContent) : null,
		notices: Array.from(wrapper.querySelectorAll('[data-ffc-notice]')).map((n) => ({
			hook: n.getAttribute('data-ffc-notice'),
			text: clean(n.textContent),
		})),
		ctas: Array.from(wrapper.querySelectorAll('button')).map((b) => clean(b.textContent)),
		content: wrapper.querySelector('.ffc-loaded-content') ? clean(wrapper.querySelector('.ffc-loaded-content').textContent) : null,
	};
}

/**
 * Let every timer the scripts set run out, the 40 s safety timer included.
 *
 * @returns {Promise<void>}
 */
export async function settle() {
	await vi.advanceTimersByTimeAsync(60000);
}

/**
 * Click the pre-flight banner's button, if one is waiting, and settle.
 *
 * @param {jQuery} $w Wrapper.
 * @returns {Promise<boolean>} Whether a button was clicked.
 */
export async function clickPreflightCta($w) {
	const button = $w[0].querySelector('.ffc-preflight-try-anyway');
	if (!button) {
		return false;
	}
	button.click();
	await settle();
	return true;
}

/**
 * Compare a set of records with its frozen file, or rewrite the file when
 * FFC_UPDATE_PREFLIGHT_BASELINE=1.
 *
 * @param {string} name     File name under tests/fixtures/.
 * @param {object} records  Scenario key => record.
 * @returns {{expected: object, actual: object}}
 */
export function baseline(name, records) {
	const file = resolve(process.cwd(), 'tests/fixtures', name);
	if (process.env.FFC_UPDATE_PREFLIGHT_BASELINE === '1') {
		// One scenario per line, sorted, so a diff names the scenario that moved.
		const lines = Object.keys(records).sort().map((k) => '\t' + JSON.stringify(k) + ': ' + JSON.stringify(records[k]));
		writeFileSync(file, '{\n' + lines.join(',\n') + '\n}\n');
	}
	if (!existsSync(file)) {
		// A missing baseline must fail, never be written as the answer.
		throw new Error('Missing baseline ' + name + ': run with FFC_UPDATE_PREFLIGHT_BASELINE=1 on the code being frozen.');
	}
	return {
		expected: JSON.parse(readFileSync(file, 'utf8')),
		actual: JSON.parse(JSON.stringify(records)),
	};
}

const FORM_ID = 7;

let requests = [];

/**
 * The config the server emits for one combination.
 *
 * @param {object} s Scenario.
 * @returns {object}
 */
export function serverConfig(s) {
	const datetime = datetimeConfig(s.datetime);
	const geo = geoConfig(s.geo, s.preset);
	if (!s.bypass) {
		return { adminBypass: false, datetime, geo };
	}
	// Under bypass the server sends the restrictions disabled and says
	// which ones it skipped.
	return {
		adminBypass: true,
		bypassInfo: { hasDatetime: datetime.enabled, hasGeo: geo.enabled },
		datetime: { enabled: false },
		geo: { enabled: false },
	};
}

function mountForm() {
	document.body.innerHTML = `
		<div id="ffc-form-${FORM_ID}" class="ffc-shortcode ffc-form-wrapper ffc-has-geofence">
			<h2 class="ffc-form-title">Course certificate</h2>
			<form class="ffc-submission-form"><input name="name"></form>
		</div>`;
	return window.$('#ffc-form-' + FORM_ID);
}

function mountContent(config) {
	const json = JSON.stringify(config).replace(/"/g, '&quot;');
	document.body.innerHTML = `
		<div id="ffc-geofence-content-1" class="ffc-shortcode ffc-geofence-content"
			data-ffc-geofence-handle="7.0.abcdef0123456789.${'0'.repeat(32)}" data-ffc-geofence-config="${json}">
			<div class="ffc-geofence-loading-msg"><div class="ffc-spinner"></div><p>Checking access…</p></div>
		</div>`;
	return window.$('#ffc-geofence-content-1');
}

/**
 * Run one combination and reduce it to a record.
 *
 * @param {object} s Scenario.
 * @returns {Promise<object>}
 */
async function run(s) {
	const device = installDevice({
		ua: s.ua,
		cookies: s.cookies,
		outcome: s.geo === 'gps' ? s.outcome : 'none',
		permission: s.permission || 'none',
	});
	const config = serverConfig(s);
	let $w;
	if (s.target === 'content') {
		$w = mountContent(config);
	} else {
		$w = mountForm();
		window.ffcGeofenceConfig = { [FORM_ID]: config, _global: { strings: {} } };
	}
	if (s.cached) {
		window.FFCGeofence.cacheGeofencePass($w, config.geo);
	}

	if (s.target === 'content') {
		window.FFCGeofence.processContent($w);
	} else {
		window.FFCGeofence.processForm(String(FORM_ID), config);
	}
	await vi.advanceTimersByTimeAsync(0);
	const first = snapshot($w);
	await settle();
	const steps = [snapshot($w)];
	// Follow each pre-flight banner the way a visitor would, at most three.
	for (let i = 0; i < 3 && (await clickPreflightCta($w)); i++) {
		steps.push(snapshot($w));
	}

	return {
		first,
		steps,
		positionRequests: device.positionCalls(),
		permissionQueries: device.permissionQueries(),
		requests: requests.slice(),
	};
}

export function key(s) {
	return [
		s.target, 'dt=' + s.datetime, 'cookies=' + (s.cookies ? 'ok' : 'blocked'), 'bypass=' + (s.bypass ? 'yes' : 'no'),
		'geo=' + s.geo, s.geo === 'gps' ? [s.permission, s.outcome, s.preset, s.ua, s.cached ? 'cached' : 'fresh'].join('/') : s.ua,
	].join(' ');
}


/**
 * Load the scripts once, reset the page between scenarios, and hand back
 * the runner. Registers the Vitest hooks of the calling file.
 *
 * @returns {{run: Function, key: Function, reset: Function}}
 */
export function useHarness() {
	let realRequest;

	const reset = () => {
		requests = [];
		document.body.innerHTML = '';
		window.ffcGeofenceConfig = undefined;
		try {
			window.localStorage.clear();
		} catch (_e) {
			/* ignore */
		}
	};

	beforeAll(() => {
		loadScript('assets/js/ffc-core.js');
		loadScript('assets/js/ffc-geofence-frontend.js');
		loadScript('assets/js/ffc-geofence-datetime.js');
		loadScript('assets/js/ffc-geofence-gps.js');
		loadScript('assets/js/ffc-geofence-preflight.js');
		loadScript('assets/js/ffc-geofence-content.js');
		realRequest = window.FFC.request;
	});

	beforeEach(() => {
		vi.useFakeTimers({ now: new Date(NOW), toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] });
		window.$.fx.off = true;
		window.FFC.request = (action, data) => {
			requests.push(action === 'ffc_log_preflight_bail' ? action + ':' + data.reason : action);
			if (action === 'ffc_geofence_content') {
				return Promise.resolve({ html: '<div class="ffc-loaded-content">Protected content</div>' });
			}
			return Promise.resolve({});
		};
		window.ffcGeofenceContent = { ajaxUrl: '/wp-admin/admin-ajax.php', action: 'ffc_geofence_content', strings: {} };
		reset();
	});

	afterEach(() => {
		vi.useRealTimers();
		window.FFC.request = realRequest;
		reset();
	});

	return { run, key, reset };
}
