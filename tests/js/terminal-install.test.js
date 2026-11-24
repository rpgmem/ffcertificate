/**
 * Check-in terminal install (#2813): the banner on the terminal page and the
 * install page's states.
 */
import { describe, it, expect, beforeAll, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';
const IPHONE_CHROME = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/124.0 Mobile/15E148 Safari/604.1';
const IPAD = 'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';
const IPAD_DESKTOP = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15';
const ANDROID = 'Mozilla/5.0 (Linux; Android 14; SM-X200) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
const DAY = 24 * 60 * 60 * 1000;
const STATES = ['wait', 'prompt', 'ios-top', 'ios-bottom', 'ios-old', 'installed', 'menu', 'unsupported'];

beforeAll(() => {
	window.FFCTerminalInstallManual = true;
	loadScript('assets/js/ffc-terminal-install.js');
});

const T = () => window.FFCTerminalInstall;

function fakeWindow({ media = [], nav = {}, storage = null } = {}) {
	const listeners = {};
	return {
		navigator: { userAgent: ANDROID, ...nav },
		matchMedia: (query) => ({ matches: media.includes(query) }),
		addEventListener: (type, fn) => {
			(listeners[type] = listeners[type] || []).push(fn);
		},
		fire: (type, event) => (listeners[type] || []).forEach((fn) => fn(event)),
		timers: [],
		setTimeout(fn, ms) {
			this.timers.push({ fn, ms });
		},
		location: { replace: vi.fn() },
		localStorage: storage,
	};
}

function memoryStorage(initial = {}) {
	const data = { ...initial };
	return {
		getItem: (k) => (k in data ? data[k] : null),
		setItem: (k, v) => {
			data[k] = String(v);
		},
		data,
	};
}

describe('decisions', () => {
	it('sorts iOS into Share at the bottom, at the top or an old iOS', () => {
		expect(T().iosShare({ userAgent: IPHONE })).toBe('bottom');
		expect(T().iosShare({ userAgent: IPAD })).toBe('top', 'Safari on an iPad keeps Share beside the address');
		expect(T().iosShare({ userAgent: IPAD_DESKTOP, platform: 'MacIntel', maxTouchPoints: 5 })).toBe('top');
		expect(T().iosShare({ userAgent: IPHONE_CHROME })).toBe('top');
		expect(T().iosShare({ userAgent: IPHONE_CHROME.replace('17_4', '16_3') })).toBe('old');
		expect(T().iosShare({ userAgent: ANDROID })).toBeNull();
		expect(T().iosShare({ userAgent: IPAD_DESKTOP, platform: 'MacIntel', maxTouchPoints: 0 })).toBeNull();
	});

	it('knows the installed terminal, full screen or standalone', () => {
		expect(T().isStandalone(fakeWindow({ media: ['(display-mode: fullscreen)'] }))).toBe(true);
		expect(T().isStandalone(fakeWindow({ media: ['(display-mode: standalone)'] }))).toBe(true);
		expect(T().isStandalone(fakeWindow({ nav: { standalone: true } }))).toBe(true);
		expect(T().isStandalone(fakeWindow())).toBe(false);
	});
});

describe('the banner on the terminal page', () => {
	let banner;

	beforeEach(() => {
		document.body.innerHTML = '<aside data-ffc-terminal-install-banner hidden><a href="/terminal/install/">Install</a><button type="button" data-ffc-terminal-install-dismiss>x</button></aside>';
		banner = document.querySelector('aside');
	});

	it('shows in a browser tab and stays closed for 30 days once closed', () => {
		const storage = memoryStorage();
		const now = () => 100 * DAY;
		expect(T().banner(banner, fakeWindow({ storage }), now)).toBe(true);
		expect(banner.hidden).toBe(false);

		banner.querySelector('button').click();
		expect(banner.hidden).toBe(true);
		expect(storage.data[T().BANNER_KEY]).toBe(String(100 * DAY));

		expect(T().banner(banner, fakeWindow({ storage }), () => 129 * DAY)).toBe(false);
		expect(T().banner(banner, fakeWindow({ storage }), () => 131 * DAY)).toBe(true);
	});

	it('never shows inside the installed terminal', () => {
		expect(T().banner(banner, fakeWindow({ media: ['(display-mode: fullscreen)'] }), () => 0)).toBe(false);
		expect(banner.hidden).toBe(true);
	});
});

describe('the install page', () => {
	let page;

	beforeEach(() => {
		document.body.innerHTML = `
			<main data-ffc-terminal-install-page data-copied="Copied." data-copy-failed="Select it.">
				${STATES.map((state) => `<section data-state="${state}" hidden>${
					'prompt' === state ? '<button type="button" data-ffc-terminal-install-go>Install</button>' : ''
				}${
					'unsupported' === state || 'ios-old' === state ? '<input data-ffc-terminal-install-address value="https://example.org/terminal/install/"><button type="button" data-ffc-terminal-install-copy>Copy</button>' : ''
				}</section>`).join('')}
				<p data-ffc-terminal-install-status></p>
			</main>`;
		page = document.querySelector('main');
	});

	const shown = () => STATES.filter((state) => !page.querySelector(`[data-state="${state}"]`).hidden);

	it('installs in one tap where the browser offers it', async () => {
		const win = fakeWindow();
		T().page(page, win);
		expect(shown()).toEqual(['wait']);

		const event = { preventDefault: vi.fn(), prompt: vi.fn(), userChoice: Promise.resolve({ outcome: 'accepted' }) };
		win.fire('beforeinstallprompt', event);
		expect(event.preventDefault).toHaveBeenCalled();
		expect(shown()).toEqual(['prompt']);

		page.querySelector('[data-ffc-terminal-install-go]').click();
		expect(event.prompt).toHaveBeenCalledTimes(1);
		await event.userChoice;
		await Promise.resolve();
		expect(shown()).toEqual(['installed']);
	});

	it('says the terminal is installed when the browser reports it', async () => {
		const win = fakeWindow({ nav: { getInstalledRelatedApps: () => Promise.resolve([{ platform: 'webapp' }]) } });
		T().page(page, win);
		await Promise.resolve();
		await Promise.resolve();
		expect(shown()).toEqual(['installed']);
		win.timers.forEach((t) => t.fn());
		expect(shown()).toEqual(['installed'], 'the timeout never overrides it');
	});

	it('sends a browser that knows the install event to its menu, and the event still brings the button (#2818)', () => {
		const win = fakeWindow();
		win.onbeforeinstallprompt = null;
		T().page(page, win);
		win.timers[0].fn();
		expect(shown()).toEqual(['menu']);

		win.fire('beforeinstallprompt', { preventDefault: vi.fn(), prompt: vi.fn(), userChoice: Promise.resolve({ outcome: 'dismissed' }) });
		expect(shown()).toEqual(['prompt']);
	});

	it('falls back to the unsupported text when nothing is offered in time', () => {
		const win = fakeWindow();
		T().page(page, win);
		expect(win.timers[0].ms).toBe(T().WAIT_MS);
		win.timers[0].fn();
		expect(shown()).toEqual(['unsupported']);
	});

	it.each([
		[IPHONE, 'ios-bottom'],
		[IPAD, 'ios-top'],
		[IPHONE_CHROME, 'ios-top'],
		[IPHONE_CHROME.replace('17_4', '15_7'), 'ios-old'],
	])('shows the iOS steps that fit the browser', (userAgent, state) => {
		const win = fakeWindow({ nav: { userAgent } });
		expect(T().page(page, win)).toBe(state);
		expect(shown()).toEqual([state]);
		expect(win.timers).toEqual([]);
	});

	it('offers the way back to the terminal inside the installed terminal, without navigating', () => {
		const win = fakeWindow({ media: ['(display-mode: fullscreen)'] });
		expect(T().page(page, win)).toBe('installed');
		expect(shown()).toEqual(['installed']);
		expect(win.location.replace).not.toHaveBeenCalled();
		expect(win.timers).toEqual([]);
	});

	it('copies the address and says so, or selects it when the clipboard refuses', async () => {
		const writeText = vi.fn().mockResolvedValueOnce().mockRejectedValueOnce(new Error('denied'));
		const win = fakeWindow({ nav: { clipboard: { writeText } } });
		T().page(page, win);
		win.timers[0].fn();
		const button = page.querySelector('[data-state="unsupported"] [data-ffc-terminal-install-copy]');
		const status = page.querySelector('[data-ffc-terminal-install-status]');

		button.click();
		await Promise.resolve();
		await Promise.resolve();
		expect(writeText).toHaveBeenCalledWith('https://example.org/terminal/install/');
		expect(status.textContent).toBe('Copied.');

		button.click();
		await Promise.resolve();
		await Promise.resolve();
		expect(status.textContent).toBe('Select it.');
	});
});
