// The device test's diagnostic rows (#2054): the device ID (the signals the
// forms send, read twice, read-only), the theme, local storage, the captcha's
// requirements, the clock against the server, time zone and language, the
// screen and the connection.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { webcrypto } from 'node:crypto';
import { loadScript } from './helpers.js';

const ORIG_CRYPTO = window.crypto;
const flush = () => new Promise((r) => setTimeout(r, 20));
const KEYS = ['device', 'theme', 'storage', 'captcha', 'clock', 'locale', 'screen', 'connection'];

function page() {
	document.body.innerHTML = '<ul>' + KEYS.map((k) =>
		'<li class="ffc-device-check-row" data-ffc-check="' + k + '"><span class="ffc-device-check-label">' + k + '</span><span class="ffc-device-check-result"></span></li>').join('') + '</ul>';
}

function row(key) {
	const el = document.querySelector('[data-ffc-check="' + key + '"]');
	return { state: ['ok', 'warn', 'fail', 'info'].find((s) => el.classList.contains('is-' + s)), text: el.querySelector('.ffc-device-check-result').textContent };
}

async function run() {
	page();
	loadScript('assets/js/ffc-device-check.js');
	await flush();
}

function matchMedia(scheme) {
	window.matchMedia = (q) => ({ matches: q === '(prefers-color-scheme: ' + scheme + ')' });
}

beforeAll(() => {
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-preflight.js');
});

beforeEach(() => {
	Object.defineProperty(window, 'crypto', { value: webcrypto, configurable: true });
	window.ffcDeviceCheck = { strings: {} };
	delete window.FFCDeviceSignals;
	delete window.ffc_device_config;
	delete window.ffcDarkMode;
	document.documentElement.classList.remove('ffc-dark-mode');
	matchMedia('none');
});

afterEach(() => {
	Object.defineProperty(window, 'crypto', { value: ORIG_CRYPTO, configurable: true });
});

describe('device ID row (#2054)', () => {
	it('says the device limit is off when the collector is not on the page', async () => {
		await run();
		expect(row('device')).toEqual({ state: 'info', text: 'Not used on this site: the device limit is off.' });
	});

	it('says the browser cannot read it when the limit is on but the collector stayed away', async () => {
		window.ffc_device_config = { signals: ['ua'] };
		await run();
		expect(row('device').state).toBe('warn');
		expect(row('device').text).toContain('secure connection');
	});

	it('shows a short code, the count and "stable" when two reads agree', async () => {
		const hashed = { ua: 'a1', screen: 'b2', canvas: 'c3' };
		window.FFCDeviceSignals = { enabled: ['ua', 'screen', 'canvas', 'audio'], collect: () => Promise.resolve({ ...hashed }) };
		await run();
		expect(row('device').state).toBe('ok');
		expect(row('device').text).toMatch(/^[0-9a-f]{4} [0-9a-f]{4} · 3 of 4 signals · stable$/);

		// The same signals give the same code.
		const first = row('device').text;
		await run();
		expect(row('device').text).toBe(first);
	});

	it('names the signals that change between reads', async () => {
		let n = 0;
		window.FFCDeviceSignals = {
			enabled: ['ua', 'canvas', 'audio'],
			collect: () => Promise.resolve({ ua: 'a1', canvas: 'c' + (n++), audio: 'd' + n }),
		};
		await run();
		expect(row('device').state).toBe('warn');
		expect(row('device').text).toMatch(/changes on each read: audio, canvas$/);
	});

	it('reports a failed read', async () => {
		window.FFCDeviceSignals = { enabled: ['ua'], collect: () => Promise.reject(new Error('blocked')) };
		await run();
		expect(row('device')).toEqual({ state: 'fail', text: 'The device signals could not be read.' });
	});
});

describe('theme row (#2054)', () => {
	it.each([
		[undefined, false, 'none', 'Light (site: always light; system: not stated)'],
		['on', true, 'light', 'Dark (site: always dark; system: light)'],
		['auto', true, 'dark', 'Dark (site: follows the system; system: dark)'],
		['auto', false, 'light', 'Light (site: follows the system; system: light)'],
	])('mode %s, dark %s, system %s', async (mode, dark, scheme, expected) => {
		if (mode) {
			window.ffcDarkMode = { mode };
		}
		document.documentElement.classList.toggle('ffc-dark-mode', dark);
		matchMedia(scheme);
		await run();
		expect(row('theme')).toEqual({ state: 'info', text: expected });
	});
});

describe('the collector in read-only mode (#2054)', () => {
	it('never creates the persistent device id', async () => {
		window.localStorage.removeItem('ffc_device_id');
		window.ffc_device_config = { signals: ['cookie', 'ua'] };
		window.ThumbmarkJS = {
			setOption: () => {},
			getFingerprintData: () => Promise.resolve({ system: { useragent: 'UA/1.0', platform: 'x' }, locales: {} }),
		};
		loadScript('assets/js/ffc-device-signals.js');
		const hashed = await window.FFCDeviceSignals.collect();
		expect(window.localStorage.getItem('ffc_device_id')).toBeNull();
		expect(Object.keys(hashed)).toEqual(['ua']);
		delete window.ThumbmarkJS;
	});
});

describe('storage, captcha, clock, locale, screen and connection rows (#2054)', () => {
	const define = (target, name, value) => Object.defineProperty(target, name, { configurable: true, value });

	afterEach(() => {
		vi.restoreAllMocks();
		delete window.fetch;
		define(navigator, 'connection', undefined);
		define(navigator, 'onLine', true);
	});

	it('reports storage allowed and leaves no probe behind', async () => {
		await run();
		expect(row('storage')).toEqual({ state: 'ok', text: 'Allowed' });
		expect(window.localStorage.getItem('ffc_device_check_probe')).toBeNull();
	});

	it('reports storage blocked', async () => {
		vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
			throw new Error('QuotaExceededError');
		});
		await run();
		expect(row('storage').state).toBe('warn');
	});

	it('names what the captcha lacks', async () => {
		const worker = window.Worker;
		window.Worker = function () {};
		await run();
		expect(row('captcha')).toEqual({ state: 'ok', text: 'Supported' });

		Object.defineProperty(window, 'crypto', { value: {}, configurable: true });
		delete window.Worker;
		await run();
		expect(row('captcha').state).toBe('fail');
		expect(row('captcha').text).toContain('Web Crypto, Web Workers');
		window.Worker = worker;
	});

	it.each([
		[2, 'ok', 'Correct (differs by 2 s)'],
		[420, 'warn', 'Ahead by 7 min'],
		[-7200, 'warn', 'Behind by 2 h'],
	])('compares the clock with the server (skew %i s)', async (skew, state, prefix) => {
		// The Date header has second resolution: start on a whole second.
		const now = Math.floor(Date.now() / 1000) * 1000;
		window.fetch = vi.fn(() => Promise.resolve({ headers: { get: () => new Date(now - skew * 1000).toUTCString() } }));
		vi.spyOn(Date, 'now').mockReturnValue(now);
		await run();
		expect(window.fetch).toHaveBeenCalledWith(window.location.href, { method: 'HEAD', cache: 'no-store', credentials: 'same-origin' });
		expect(row('clock').state).toBe(state);
		expect(row('clock').text.startsWith(prefix)).toBe(true);
	});

	it('says so when the server time cannot be read', async () => {
		window.fetch = vi.fn(() => Promise.resolve({ headers: { get: () => null } }));
		await run();
		expect(row('clock')).toEqual({ state: 'info', text: 'Could not compare with the server.' });

		window.fetch = vi.fn(() => Promise.reject(new Error('offline')));
		await run();
		expect(row('clock').text).toBe('Could not compare with the server.');
	});

	it('shows the time zone, the UTC offset and the language', async () => {
		await run();
		expect(row('locale').state).toBe('info');
		expect(row('locale').text).toMatch(/UTC[+-]\d/);
		expect(row('locale').text).toContain(navigator.language);
	});

	it('shows the window, the screen and the pixel density', async () => {
		await run();
		expect(row('screen').text).toMatch(/^Window \d+ × \d+ · screen .+ · density [\d.]+×$/);
	});

	it('reports the connection as one medium / speed pair on every device (#2060)', async () => {
		const NONE = 'Online/Speed: Connection type not reported / Connection speed not reported';
		await run();
		expect(row('connection')).toEqual({ state: 'info', text: NONE });

		// A desktop browser gives only the speed class: shown as a speed,
		// never as a network generation.
		define(navigator, 'connection', { effectiveType: '4g', saveData: false });
		await run();
		expect(row('connection')).toEqual({ state: 'info', text: 'Online/Speed: Connection type not reported / Fast (like 4G or better)' });

		// Chromium for Android also gives the medium.
		define(navigator, 'connection', { type: 'wifi', effectiveType: '4g', saveData: false });
		await run();
		expect(row('connection').text).toBe('Online/Speed: Wi-Fi / Fast (like 4G or better)');

		define(navigator, 'connection', { type: 'cellular', effectiveType: '3g', saveData: false });
		await run();
		expect(row('connection').text).toBe('Online/Speed: Mobile data / Slow (like 3G)');

		define(navigator, 'connection', { type: 'ethernet', effectiveType: '2g', saveData: false });
		await run();
		expect(row('connection').text).toBe('Online/Speed: Wired / Very slow (like 2G)');

		define(navigator, 'connection', { type: 'bluetooth', effectiveType: 'slow-2g', saveData: false });
		await run();
		expect(row('connection').text).toBe('Online/Speed: Bluetooth / Very slow (below 2G)');

		// A value nobody maps, or an inherited key, reads as not reported
		// rather than as something wrong.
		define(navigator, 'connection', { type: 'unknown', effectiveType: '5g', saveData: false });
		await run();
		expect(row('connection').text).toBe(NONE);
		define(navigator, 'connection', { type: 'toString', effectiveType: 'constructor', saveData: false });
		await run();
		expect(row('connection').text).toBe(NONE);
	});

	it('warns about data saver and fails offline', async () => {
		define(navigator, 'connection', { type: 'cellular', effectiveType: '3g', saveData: true });
		await run();
		expect(row('connection')).toEqual({ state: 'warn', text: 'Online/Speed: Mobile data / Slow (like 3G)' });

		define(navigator, 'onLine', false);
		await run();
		expect(row('connection')).toEqual({ state: 'fail', text: 'Offline' });
	});
});

describe('"How to fix it" on a failing row (#2054)', () => {
	const tip = (key) => document.querySelector('[data-ffc-check="' + key + '"] .ffc-device-check-tip');
	const fix = (key) => document.querySelector('[data-ffc-check="' + key + '"] .ffc-device-check-fix');
	const define = (target, name, value) => Object.defineProperty(target, name, { configurable: true, value });

	afterEach(() => {
		define(navigator, 'onLine', true);
		define(navigator, 'connection', undefined);
	});

	it('adds an information button that reveals the fix', async () => {
		window.ffcDeviceCheck = { strings: { helpOffline: 'Connect to the internet.' } };
		Object.defineProperty(navigator, 'onLine', { configurable: true, value: false });
		await run();

		const button = tip('connection');
		expect(button).not.toBeNull();
		expect(button.className).toContain('ffc-btn-icon');
		expect(button.className).toContain('ffc-icon-info');
		expect(button.getAttribute('aria-label')).toBe('How to fix it');
		expect(button.getAttribute('aria-expanded')).toBe('false');
		expect(fix('connection').hidden).toBe(true);
		expect(fix('connection').textContent).toBe('Connect to the internet.');
		expect(button.getAttribute('aria-controls')).toBe(fix('connection').id);

		button.click();
		expect(fix('connection').hidden).toBe(false);
		expect(button.getAttribute('aria-expanded')).toBe('true');
		button.click();
		expect(fix('connection').hidden).toBe(true);
	});

	it('draws nothing on a passing or informational row', async () => {
		define(navigator, 'connection', { effectiveType: '4g', saveData: false });
		await run();
		expect(tip('storage')).toBeNull();
		expect(tip('theme')).toBeNull();
		expect(tip('connection')).toBeNull();
		expect(document.querySelectorAll('.ffc-device-check-fix')).toHaveLength(0);
	});

	it('explains a connection the browser does not describe, by platform (#2060)', async () => {
		window.ffcDeviceCheck = { strings: { helpConnectionIos: 'iOS gives nothing.', helpConnectionBrowser: 'This browser gives nothing.' } };
		define(navigator, 'connection', undefined);
		await run();
		expect(row('connection').state).toBe('info');
		expect(tip('connection')).not.toBeNull();
		expect(fix('connection').textContent).toBe('This browser gives nothing.');

		const ios = vi.spyOn(window.FFC.Platform, 'ios').mockReturnValue(true);
		try {
			await run();
			expect(fix('connection').textContent).toBe('iOS gives nothing.');
		} finally {
			ios.mockRestore();
		}

		// Data saver is a warning with its own fix.
		window.ffcDeviceCheck = { strings: { helpDataSaver: 'Turn it off.' } };
		define(navigator, 'connection', { type: 'wifi', effectiveType: '4g', saveData: true });
		await run();
		expect(fix('connection').textContent).toBe('Turn it off.');
	});

	it('names the signals and explains them when the device ID changes', async () => {
		window.ffcDeviceCheck = { strings: { helpDeviceUnstable: 'Fingerprint protection changes them.' } };
		let n = 0;
		window.FFCDeviceSignals = { enabled: ['canvas'], collect: () => Promise.resolve({ canvas: 'c' + (n++) }) };
		await run();
		expect(fix('device').textContent).toBe('Fingerprint protection changes them.');
	});
});


describe('a check still running counts down (#2054)', () => {
	it('shows the seconds left and says when no answer came', async () => {
		vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] });
		try {
			window.FFCDeviceSignals = { enabled: ['ua'], collect: () => new Promise(() => {}) };
			page();
			loadScript('assets/js/ffc-device-check.js');
			expect(row('device')).toEqual({ state: 'info', text: 'Reading the device signals… (20 s)' });

			vi.advanceTimersByTime(5000);
			expect(row('device').text).toBe('Reading the device signals… (15 s)');

			vi.advanceTimersByTime(15000);
			expect(row('device')).toEqual({ state: 'warn', text: 'No answer after 20 s: reload the page to test again.' });
		} finally {
			vi.useRealTimers();
		}
	});

	it('stops counting once the answer arrives', async () => {
		window.FFCDeviceSignals = { enabled: ['ua'], collect: () => Promise.resolve({ ua: 'a1' }) };
		await run();
		const answered = row('device').text;
		await new Promise((r) => setTimeout(r, 1100));
		expect(row('device').text).toBe(answered);
	});
});
