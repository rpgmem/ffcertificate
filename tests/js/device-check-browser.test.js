// The device test names the browser and its version (#2052): the brands a
// Chromium browser declares first, with the full version when it answers,
// and the user agent as the fallback. An unknown browser stays generic.
import { describe, it, expect, beforeAll, afterEach } from 'vitest';
import { loadScript } from './helpers.js';

const UA = {
	chromeWin: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
	edgeWin: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0',
	operaAndroid: 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/117.0.0.0 Mobile Safari/537.36 OPR/103.0.0.0',
	samsung: 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36',
	firefox: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0',
	firefoxAndroid: 'Mozilla/5.0 (Android 14; Mobile; rv:131.0) Gecko/131.0 Firefox/131.0',
	safariMac: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15',
	safariIphone: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1',
	chromeIphone: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/129.0.6668.69 Mobile/15E148 Safari/604.1',
	firefoxIphone: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/131.0 Mobile/15E148 Safari/605.1.15',
	chromiumLinux: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chromium/129.0.6668.58 Chrome/129.0.6668.58 Safari/537.36',
	unknown: 'SomeBrowser/1.0',
};

const define = (name, value) => Object.defineProperty(navigator, name, { configurable: true, value });

function device(ua, data) {
	define('userAgent', UA[ua]);
	define('maxTouchPoints', /iPhone/.test(UA[ua]) ? 5 : 0);
	define('userAgentData', data);
}

/** A userAgentData whose brands and full versions are given. */
function brands(list, full) {
	return {
		brands: list.map(([brand, version]) => ({ brand, version: version.split('.')[0] })),
		getHighEntropyValues: () => (full === 'reject'
			? Promise.reject(new Error('blocked'))
			: Promise.resolve(full ? { fullVersionList: list.map(([brand, version]) => ({ brand, version })) } : {})),
	};
}

const name = () => window.FFCDeviceCheck.browserName(window.FFC.Platform);
const NOT_A_BRAND = ['Not)A;Brand', '99.0.0.0'];

beforeAll(() => {
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-preflight.js');
	loadScript('assets/js/ffc-device-check.js');
});

afterEach(() => {
	define('userAgentData', undefined);
});

describe('device test: the browser by name (#2052)', () => {
	it('names Chrome with its full version', async () => {
		device('chromeWin', brands([NOT_A_BRAND, ['Chromium', '129.0.6668.81'], ['Google Chrome', '129.0.6668.81']], true));
		expect(await name()).toBe('Chrome (v. 129.0.6668.81)');
	});

	it('tells Chromium and Brave from Chrome, whose user agents are the same', async () => {
		device('chromeWin', brands([NOT_A_BRAND, ['Chromium', '129.0.6668.81']], true));
		expect(await name()).toBe('Chromium (v. 129.0.6668.81)');

		device('chromeWin', brands([['Brave', '129.0.0.0'], ['Chromium', '129.0.6668.81'], NOT_A_BRAND], true));
		expect(await name()).toBe('Brave / Chromium (v. 129.0.6668.81)');
	});

	it('names a derivative with the engine version, and its own number where it has one', async () => {
		device('edgeWin', brands([['Microsoft Edge', '129.0.2792.89'], ['Chromium', '129.0.6668.89'], NOT_A_BRAND], true));
		expect(await name()).toBe('Edge / Chromium (v. 129.0.6668.89)');

		device('operaAndroid', brands([['Opera', '103.0.4928.34'], ['Chromium', '117.0.5938.60'], NOT_A_BRAND], true));
		expect(await name()).toBe('Opera 103 / Chromium (v. 117.0.5938.60)');

		device('samsung', brands([['Samsung Internet', '26.0'], ['Chromium', '122.0.6261.105'], NOT_A_BRAND], true));
		expect(await name()).toBe('Samsung Internet 26 / Chromium (v. 122.0.6261.105)');
	});

	it('falls back to the major version when the full list is refused or empty', async () => {
		device('chromeWin', brands([['Chromium', '129.0.6668.81'], ['Google Chrome', '129.0.6668.81']], 'reject'));
		expect(await name()).toBe('Chrome (v. 129)');

		device('chromeWin', brands([['Chromium', '129.0.6668.81'], ['Google Chrome', '129.0.6668.81']], false));
		expect(await name()).toBe('Chrome (v. 129)');
	});

	it.each([
		['chromeWin', 'Chrome (v. 129)'],
		['edgeWin', 'Edge / Chromium (v. 129)'],
		['operaAndroid', 'Opera 103 / Chromium (v. 117)'],
		['samsung', 'Samsung Internet 26 / Chromium (v. 122)'],
		['firefox', 'Firefox (v. 131)'],
		['firefoxAndroid', 'Firefox (v. 131)'],
		['safariMac', 'Safari (v. 17.6)'],
		['safariIphone', 'Safari (v. 17.6)'],
		['chromeIphone', 'Chrome / WebKit (v. 129.0.6668.69)'],
		['firefoxIphone', 'Firefox / WebKit (v. 131)'],
		['chromiumLinux', 'Chromium (v. 129.0.6668.58)'],
		['unknown', ''],
	])('reads %s from the user agent when no brands are declared', async (ua, expected) => {
		device(ua, undefined);
		expect(await name()).toBe(expected);
	});
});

describe('device test: the browser row (#2052)', () => {
	function page() {
		document.body.innerHTML = '<ul>' + ['secure', 'browser', 'cookies', 'location', 'accuracy', 'pdf'].map((k) =>
			'<li class="ffc-device-check-row" data-ffc-check="' + k + '"><span class="ffc-device-check-label">' + k + '</span><span class="ffc-device-check-result"></span></li>').join('') + '</ul>';
	}
	const flush = () => new Promise((r) => setTimeout(r, 10));
	const row = () => document.querySelector('[data-ffc-check="browser"] .ffc-device-check-result').textContent;

	it('shows the name with the device, and the generic wording for an unknown browser', async () => {
		window.ffcDeviceCheck = { strings: {} };
		device('chromeWin', brands([['Chromium', '129.0.6668.81'], ['Google Chrome', '129.0.6668.81']], true));
		page();
		loadScript('assets/js/ffc-device-check.js');
		await flush();
		expect(row()).toBe('Chrome (v. 129.0.6668.81) — computer');

		device('unknown', undefined);
		page();
		loadScript('assets/js/ffc-device-check.js');
		await flush();
		expect(row()).toBe('Ordinary browser (computer)');
	});

	it('names the app whose own browser it is', async () => {
		window.ffcDeviceCheck = { strings: {} };
		define('userAgent', 'Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.81 Mobile Safari/537.36 Instagram 350.0.0.42.92 Android');
		define('maxTouchPoints', 5);
		page();
		loadScript('assets/js/ffc-device-check.js');
		await flush();
		expect(row()).toContain('(Instagram, Android)');
	});
});
