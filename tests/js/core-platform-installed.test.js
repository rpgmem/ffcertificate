// FFC.Platform.installed() and howKey() (#2954): the installed app has no
// address bar, so a how-to for a refused permission names its own path.
import { describe, it, expect, beforeAll, afterEach } from 'vitest';
import { loadScript } from './helpers.js';

const UA = {
	android: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/129.0.0.0 Mobile Safari/537.36',
	iphone: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1',
	desktop: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/129.0.0.0 Safari/537.36',
};

describe('FFC.Platform.installed / howKey', () => {
	const matchMedia = window.matchMedia;

	beforeAll(() => {
		loadScript('assets/js/ffc-core.js');
	});

	afterEach(() => {
		window.matchMedia = matchMedia;
		Object.defineProperty(window.navigator, 'standalone', { value: undefined, configurable: true });
	});

	const as = (ua, mode) => {
		Object.defineProperty(window.navigator, 'userAgent', { value: UA[ua], configurable: true });
		window.matchMedia = (query) => ({ matches: '(display-mode: ' + mode + ')' === query });
	};

	it('reads a standalone or fullscreen window as the installed app, and a tab as not', () => {
		as('android', 'standalone');
		expect(window.FFC.Platform.installed()).toBe(true);
		as('android', 'fullscreen');
		expect(window.FFC.Platform.installed()).toBe(true);
		as('android', 'browser');
		expect(window.FFC.Platform.installed()).toBe(false);
	});

	it('reads iOS\'s navigator.standalone, and a failing matchMedia as a tab', () => {
		as('iphone', 'browser');
		Object.defineProperty(window.navigator, 'standalone', { value: true, configurable: true });
		expect(window.FFC.Platform.installed()).toBe(true);

		Object.defineProperty(window.navigator, 'standalone', { value: undefined, configurable: true });
		window.matchMedia = () => { throw new Error('no media queries'); };
		expect(window.FFC.Platform.installed()).toBe(false);
	});

	it('names the app\'s how-to on a phone running the app, and the browser\'s everywhere else', () => {
		as('android', 'standalone');
		expect(window.FFC.Platform.howKey('gpsDeniedHow')).toBe('gpsDeniedHowAndroidApp');
		as('iphone', 'standalone');
		expect(window.FFC.Platform.howKey('gpsDeniedHow')).toBe('gpsDeniedHowIosApp');
		as('iphone', 'browser');
		expect(window.FFC.Platform.howKey('gpsDeniedHow')).toBe('gpsDeniedHowIos');
		as('desktop', 'standalone');
		expect(window.FFC.Platform.howKey('gpsDeniedHow')).toBe('gpsDeniedHowDesktop');
	});
});
