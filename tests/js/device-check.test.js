// The "Test my device" mode of the verification page (#2040): each row gets
// the answer the forms' pre-flight would give, the location is read only on
// request and only its precision is shown, and the copied summary carries
// the rows, the browser identification and the time, never a coordinate.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const UA = {
	desktop: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
	instagram: 'Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.81 Mobile Safari/537.36 Instagram 350.0.0.42.92 Android',
};

const KEYS = ['secure', 'browser', 'cookies', 'location', 'pdf'];
const flush = () => new Promise((r) => setTimeout(r, 10));
const define = (target, name, value) => Object.defineProperty(target, name, { value, configurable: true });

function page() {
	document.body.innerHTML =
		'<div class="ffc-shortcode ffc-device-check"><ul>' +
		KEYS.map((k) => '<li class="ffc-device-check-row is-info" data-ffc-check="' + k + '"><span class="ffc-device-check-label">' + k + '</span><span class="ffc-device-check-result">…</span></li>').join('') +
		'</ul><button class="ffc-btn ffc-device-check-locate"><span class="ffc-btn-label">Locate</span></button>' +
		'<button class="ffc-btn ffc-device-check-copy"><span class="ffc-btn-label">Copy</span></button></div>';
}

function row(key) {
	const el = document.querySelector('[data-ffc-check="' + key + '"]');
	return { state: ['ok', 'warn', 'fail', 'info'].find((s) => el.classList.contains('is-' + s)), text: el.querySelector('.ffc-device-check-result').textContent };
}

function device({ ua = 'desktop', permission = 'prompt', position = null, error = null, geo = true } = {}) {
	define(window.navigator, 'userAgent', UA[ua]);
	define(window.navigator, 'permissions', { query: () => Promise.resolve({ state: permission }) });
	define(window.navigator, 'geolocation', geo ? {
		getCurrentPosition(success, fail) {
			if (position) {
				success({ coords: position });
			} else {
				fail(error);
			}
		},
	} : undefined);
}

async function run(opts) {
	device(opts);
	page();
	loadScript('assets/js/ffc-device-check.js');
	await flush();
}

beforeAll(() => {
	loadScript('assets/js/ffc-core.js');
	delete window.FFC.Preflight;
	loadScript('assets/js/ffc-preflight.js');
});

beforeEach(() => {
	window.ffcDeviceCheck = { strings: {}, geo: { reference: 50, weakShare: 0.5, tolerance: 1.5 } };
});

afterEach(() => {
	vi.restoreAllMocks();
	define(window.navigator, 'userAgent', UA.desktop);
});

describe('device check (#2040)', () => {
	it('fills every row on load, the precision waiting for the button', async () => {
		await run();
		expect(row('browser').state).toBe('ok');
		expect(row('cookies').state).toBe('ok');
		expect(row('pdf').state).toBe('ok');
		// The precision is a step the visitor owes (#2065).
		expect(row('location')).toEqual({ state: 'info', text: 'Not decided yet: the browser will ask. · precision not tested yet' });
		expect(['ok', 'fail']).toContain(row('secure').state);
	});

	it('warns in an app\'s own browser', async () => {
		await run({ ua: 'instagram' });
		expect(row('browser').state).toBe('warn');
		expect(row('browser').text).toContain('Android');
	});

	it('reports a blocked or granted permission', async () => {
		await run({ permission: 'denied', error: { code: 1 } });
		expect(row('location').state).toBe('fail');
		await run({ permission: 'granted' });
		expect(row('location').state).toBe('ok');
	});

	it('believes the device over a "denied" it contradicts (#2048)', async () => {
		// Opera for Android: the Permissions API says denied, the read works.
		await run({ permission: 'denied', position: { latitude: -23.5505, longitude: -46.6333, accuracy: 22 } });
		expect(row('location')).toEqual({ state: 'ok', text: 'Allowed · precision not tested yet' });
		expect(document.body.textContent).not.toContain('23.55');

		// A timeout says nothing about the permission.
		await run({ permission: 'denied', error: { code: 3 } });
		expect(row('location').state).toBe('info');
	});

	it('says so when the browser has no geolocation', async () => {
		await run({ geo: false });
		expect(row('location').state).toBe('fail');
		document.querySelector('.ffc-device-check-locate').click();
		expect(row('location').state).toBe('fail');
	});

	it('shows the precision beside the permission, and never the position (#2062)', async () => {
		await run({ permission: 'granted', position: { latitude: -23.5505, longitude: -46.6333, accuracy: 12.4 } });
		document.querySelector('.ffc-device-check-locate').click();
		await flush();
		expect(row('location').state).toBe('ok');
		expect(row('location').text).toMatch(/^Allowed · About 12\.4 m \(precise\), read in \d+(\.\d)? s$/);
		expect(document.body.textContent).not.toContain('23.55');
		expect(document.body.textContent).not.toContain('46.63');
	});

	it('grades the precision against the 50 m reference area with the forms\' settings (#2074)', async () => {
		const read = async (accuracy) => {
			await run({ permission: 'granted', position: { latitude: 1, longitude: 1, accuracy } });
			document.querySelector('.ffc-device-check-locate').click();
			await flush();
			return row('location');
		};
		const fix = () => document.querySelector('[data-ffc-check="location"] .ffc-device-check-fix');

		expect((await read(25)).state).toBe('ok');
		expect(row('location').text).toContain('About 25 m (precise)');
		expect(fix()).toBeNull();

		expect((await read(25.1)).state).toBe('warn');
		expect(row('location').text).toContain('About 25.1 m (low precision, but accepted)');

		expect((await read(75)).state).toBe('warn');
		expect((await read(75.1)).state).toBe('fail');
		expect(row('location').text).toContain('About 75.1 m (too imprecise to confirm the area)');

		// The settings move the lines.
		window.ffcDeviceCheck.geo = { reference: 50, weakShare: 0.8, tolerance: 2 };
		expect((await read(30)).state).toBe('ok');
		expect((await read(90)).state).toBe('warn');
	});

	it('names the reference area in the help of a weak reading', async () => {
		window.ffcDeviceCheck.strings = { helpAccuracyWeak: 'Area of %s m: accepted, imprecise.', helpAccuracyTooWeak: 'Area of %s m: cannot confirm.' };
		const fix = () => document.querySelector('[data-ffc-check="location"] .ffc-device-check-fix').textContent;

		await run({ permission: 'granted', position: { latitude: 1, longitude: 1, accuracy: 40 } });
		document.querySelector('.ffc-device-check-locate').click();
		await flush();
		expect(fix()).toBe('Area of 50 m: accepted, imprecise.');

		await run({ permission: 'granted', position: { latitude: 1, longitude: 1, accuracy: 900 } });
		document.querySelector('.ffc-device-check-locate').click();
		await flush();
		expect(fix()).toBe('Area of 50 m: cannot confirm.');
	});

	it('fails on each error', async () => {

		for (const [code, text] of [[1, 'Refused'], [2, 'Could not be read'], [3, 'Took too long']]) {
			await run({ error: { code } });
			document.querySelector('.ffc-device-check-locate').click();
			await flush();
			expect(row('location').state).toBe('fail');
			expect(row('location').text).toContain(text);
		}
	});

	it('copies a summary with every row, the site\'s setup, the user agent and the time', async () => {
		let copied = null;
		define(window.navigator, 'clipboard', { writeText: (t) => { copied = t; return Promise.resolve(); } });
		await run({ position: { latitude: -23.5505, longitude: -46.6333, accuracy: 20 } });
		document.querySelector('.ffc-device-check-locate').click();
		await flush();
		document.querySelector('.ffc-device-check-copy').click();
		await flush();

		const lines = copied.split('\n');
		expect(lines).toHaveLength(KEYS.length + 5);
		KEYS.forEach((k, i) => expect(lines[i].startsWith(k + ': ')).toBe(true));
		expect(lines[KEYS.length]).toBe('Plugin version: ' + (window.ffcCoreConfig ? window.ffcCoreConfig.version : '?'));
		expect(lines[KEYS.length + 1]).toBe('Captcha mode: ?');
		expect(lines[KEYS.length + 2]).toBe('Device limit: off');
		expect(lines[KEYS.length + 3]).toBe('User agent: ' + UA.desktop);
		expect(lines[KEYS.length + 4]).toMatch(/^\d{4}-\d{2}-\d{2}T/);
		expect(copied).not.toContain('23.55');
		expect(document.querySelector('.ffc-device-check-copy .ffc-btn-label').textContent).toBe('Copied');
	});

	it('says to take a screenshot when the clipboard is unavailable', async () => {
		define(window.navigator, 'clipboard', undefined);
		await run();
		document.querySelector('.ffc-device-check-copy').click();
		expect(document.querySelector('.ffc-device-check-copy .ffc-btn-label').textContent).toContain('screenshot');
	});
});

describe('sections and the location step (#2065)', () => {
	const GROUPS = { network: ['secure'], browser: ['browser', 'cookies'], device: ['location'], pdf: ['pdf'] };

	function sections() {
		document.body.innerHTML = '<div class="ffc-device-check"><div class="ffc-device-check-verdict"></div>' +
			Object.keys(GROUPS).map((g) => '<details class="ffc-device-check-group" data-ffc-group="' + g + '"><summary><span class="ffc-device-check-chip"></span></summary><ul>' +
				GROUPS[g].map((k) => '<li class="ffc-device-check-row is-info" data-ffc-check="' + k + '"><span class="ffc-device-check-label">' + k + '</span><span class="ffc-device-check-result">…</span></li>').join('') +
				'</ul>' + ('device' === g ? '<button class="ffc-btn ffc-device-check-locate"><span class="ffc-btn-label">Locate</span></button>' : '') + '</details>').join('') +
			'</div>';
	}

	async function open(opts) {
		device(opts);
		sections();
		loadScript('assets/js/ffc-device-check.js');
		await flush();
	}

	const group = (g) => document.querySelector('[data-ffc-group="' + g + '"]');
	const chip = (g) => group(g).querySelector('.ffc-device-check-chip');
	const verdictKey = () => document.querySelector('.ffc-device-check-verdict').getAttribute('data-ffc-verdict');

	it('keeps passing sections closed, counts their rows, and asks for the location step', async () => {
		await open({ permission: 'granted' });
		expect(group('browser').open).toBe(false);
		expect(chip('browser').textContent).toBe('2 OK');
		expect(chip('browser').classList.contains('is-ok')).toBe(true);
		// The location is owed: its section opens to show the button.
		expect(group('device').open).toBe(true);
		expect(chip('device').textContent).toBe('Test pending');
		expect(row('location').text).toBe('Allowed · precision not tested yet');
		expect(['todo', 'fail']).toContain(verdictKey());
	});

	it('finishes the test once the location is read, and closes the section it opened (#2074)', async () => {
		await open({ permission: 'granted', position: { latitude: -23.5, longitude: -46.6, accuracy: 12 } });
		document.querySelector('.ffc-device-check-locate').click();
		await flush();
		expect(row('location').state).toBe('ok');
		expect(document.querySelector('[data-ffc-check="location"]').hasAttribute('data-ffc-todo')).toBe(false);
		expect(chip('device').textContent).toBe('1 OK');
		// Opened by the script while the step was owed, it closes once green.
		expect(group('device').open).toBe(false);
		expect(verdictKey()).not.toBe('todo');
	});

	it('leaves a section the visitor toggled as they set it', async () => {
		await open({ permission: 'granted', position: { latitude: -23.5, longitude: -46.6, accuracy: 12 } });
		group('device').querySelector('summary').click();
		group('device').open = true;
		document.querySelector('.ffc-device-check-locate').click();
		await flush();
		expect(row('location').state).toBe('ok');
		expect(group('device').open).toBe(true);
	});

	it('keeps a section open while its reading is weak', async () => {
		await open({ permission: 'granted', position: { latitude: -23.5, longitude: -46.6, accuracy: 40 } });
		document.querySelector('.ffc-device-check-locate').click();
		await flush();
		expect(group('device').open).toBe(true);
		expect(chip('device').textContent).toBe('1 warning');
	});

	it('opens a section on a warning and names the failures', async () => {
		await open({ ua: 'instagram', permission: 'denied', error: { code: 1 } });
		expect(group('browser').open).toBe(true);
		expect(chip('browser').textContent).toBe('1 warning');
		expect(group('browser').getAttribute('data-ffc-state')).toBe('warn');
		expect(chip('device').textContent).toBe('1 failure');
		expect(group('device').getAttribute('data-ffc-state')).toBe('fail');
		// A failure is told before the owed step.
		expect(verdictKey()).toBe('fail');
	});

	it('copies every row, those in closed sections too', async () => {
		let copied = null;
		define(window.navigator, 'clipboard', { writeText: (t) => { copied = t; return Promise.resolve(); } });
		await open({ permission: 'granted' });
		document.body.insertAdjacentHTML('beforeend', '<button class="ffc-btn ffc-device-check-copy"><span class="ffc-btn-label">Copy</span></button>');
		loadScript('assets/js/ffc-device-check.js');
		await flush();
		document.querySelector('.ffc-device-check-copy').click();
		await flush();
		['secure', 'browser', 'cookies', 'location', 'pdf'].forEach((k) => expect(copied).toContain(k + ': '));
	});
});
