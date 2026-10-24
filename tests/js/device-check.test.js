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

const KEYS = ['secure', 'browser', 'cookies', 'location', 'accuracy', 'pdf'];
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
	window.ffcDeviceCheck = { strings: { accuracyResult: 'About %1$s m, read in %2$s s' } };
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
		expect(row('location')).toEqual({ state: 'info', text: 'Not decided yet: the browser will ask.' });
		expect(row('accuracy').text).toBe('Press "Test my location".');
		expect(['ok', 'fail']).toContain(row('secure').state);
	});

	it('warns in an app\'s own browser', async () => {
		await run({ ua: 'instagram' });
		expect(row('browser').state).toBe('warn');
		expect(row('browser').text).toContain('Android');
	});

	it('reports a blocked or granted permission', async () => {
		await run({ permission: 'denied' });
		expect(row('location').state).toBe('fail');
		await run({ permission: 'granted' });
		expect(row('location').state).toBe('ok');
	});

	it('says so when the browser has no geolocation', async () => {
		await run({ geo: false });
		expect(row('location').state).toBe('fail');
		document.querySelector('.ffc-device-check-locate').click();
		expect(row('accuracy').state).toBe('fail');
	});

	it('shows the precision and never the position', async () => {
		await run({ permission: 'granted', position: { latitude: -23.5505, longitude: -46.6333, accuracy: 12.4 } });
		document.querySelector('.ffc-device-check-locate').click();
		await flush();
		expect(row('accuracy').state).toBe('ok');
		expect(row('accuracy').text).toMatch(/^About 12 m, read in [\d.]+ s$/);
		expect(document.body.textContent).not.toContain('23.55');
		expect(document.body.textContent).not.toContain('46.63');
	});

	it('warns on a coarse reading and fails on each error', async () => {
		await run({ position: { latitude: 1, longitude: 1, accuracy: 900 } });
		document.querySelector('.ffc-device-check-locate').click();
		await flush();
		expect(row('accuracy').state).toBe('warn');

		for (const [code, text] of [[1, 'Refused'], [2, 'Could not be read'], [3, 'Took too long']]) {
			await run({ error: { code } });
			document.querySelector('.ffc-device-check-locate').click();
			await flush();
			expect(row('accuracy').state).toBe('fail');
			expect(row('accuracy').text).toContain(text);
		}
	});

	it('copies a summary with every row, the user agent and the time', async () => {
		let copied = null;
		define(window.navigator, 'clipboard', { writeText: (t) => { copied = t; return Promise.resolve(); } });
		await run({ position: { latitude: -23.5505, longitude: -46.6333, accuracy: 20 } });
		document.querySelector('.ffc-device-check-locate').click();
		await flush();
		document.querySelector('.ffc-device-check-copy').click();
		await flush();

		const lines = copied.split('\n');
		expect(lines).toHaveLength(KEYS.length + 2);
		KEYS.forEach((k, i) => expect(lines[i].startsWith(k + ': ')).toBe(true));
		expect(lines[KEYS.length]).toBe('User agent: ' + UA.desktop);
		expect(lines[KEYS.length + 1]).toMatch(/^\d{4}-\d{2}-\d{2}T/);
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
