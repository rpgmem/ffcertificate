// The public CSV download runs the visitor pre-flight's browser step before
// its form (#2030): an app's own browser is asked to switch, with "Continue
// anyway", and the setting that turns the step off on certificate forms
// turns it off here too.
import { describe, it, expect, beforeAll, beforeEach, afterEach } from 'vitest';
import { loadScript } from './helpers.js';

const UAS = {
	desktop: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
	instagram: 'Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.81 Mobile Safari/537.36 Instagram 350.0.0.42.92 Android',
};

function useUa(name) {
	Object.defineProperty(window.navigator, 'userAgent', { value: UAS[name], configurable: true });
}

function mount() {
	document.body.innerHTML = '<div class="ffc-shortcode ffc-public-csv-download">'
		+ '<div class="ffc-verification-header">CSV</div>'
		+ '<form class="ffc-verification-form"><input name="form_id"><button type="submit" class="ffc-submit-btn">Go</button></form>'
		+ '</div>';
}

async function load(preflight) {
	window.ffc_csv_download = { strings: {}, preflight };
	loadScript('assets/js/ffc-csv-download.js');
	await new Promise((r) => setTimeout(r, 0));
}

const notice = () => document.querySelector('[data-ffc-notice="preflight-browser"]');
const formDisplay = () => window.$('.ffc-verification-form').css('display');

beforeAll(() => {
	loadScript('assets/js/ffc-core.js');
	delete window.FFC.Preflight;
	loadScript('assets/js/ffc-preflight.js');
	window.FFC.request = () => Promise.resolve({});
});

beforeEach(() => {
	window.sessionStorage.clear();
	mount();
});

afterEach(() => {
	document.body.innerHTML = '';
	useUa('desktop');
});

describe('public CSV download: browser check (#2030)', () => {
	it('leaves an ordinary browser alone', async () => {
		useUa('desktop');
		await load({ browserCheck: true, strings: {} });
		expect(notice()).toBeNull();
		expect(formDisplay()).not.toBe('none');
	});

	it('stops an app\'s own browser, hiding the form, and shows it again on "Continue anyway"', async () => {
		useUa('instagram');
		await load({ browserCheck: true, strings: { inAppTitle: 'Abra esta página no seu navegador' } });
		expect(notice()).not.toBeNull();
		expect(notice().textContent).toContain('Abra esta página no seu navegador');
		expect(formDisplay()).toBe('none');

		notice().querySelector('.ffc-preflight-continue').click();
		expect(notice()).toBeNull();
		expect(formDisplay()).not.toBe('none');
	});

	it('is off when the setting is off', async () => {
		useUa('instagram');
		await load({ browserCheck: false, strings: {} });
		expect(notice()).toBeNull();
		expect(formDisplay()).not.toBe('none');
	});
});
