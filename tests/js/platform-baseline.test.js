// Baseline of the five platform detections as they answer today (#2030).
//
// The same question (iPhone? Android? in-app browser? Samsung Internet?) is
// answered in five places with slightly different patterns:
//
//   1. ffc-frontend.js — the success card's "where is my file" line;
//   2. FFCGeofence.detectPlatformFamily — the pre-flight banners' how-to;
//   3. FFCGeofence.isSafari — the GPS timeouts and messages;
//
// Since #2030 PR 3 the five read FFC.Platform; entries 2 and 3 are read
// from what replaced them there (os() and webkit()), and the fixture is
// unchanged, which is the proof the merge answers every browser as before.
//   4. ffc-pdf-generator.js — the placeholder tab, the canvas scale and the
//      message after the download;
//   5. ffc-webview-warning.js — the in-app browser banner.
//
// They are about to become one FFC.Platform. Each answer is read through the
// behaviour a visitor sees, never by copying a pattern into the test, and
// frozen in tests/fixtures/platform-baseline.json, so the merge can be proven
// to answer every one of these browsers the way it is answered today.
//
// Regenerate only on the code being frozen:
//   FFC_UPDATE_PREFLIGHT_BASELINE=1 npx vitest run tests/js/platform-baseline.test.js
import { describe, it, expect, beforeAll, vi } from 'vitest';
import { loadScript } from './helpers.js';
import { baseline } from './preflight-baseline-support.js';

/** Real user agents, by the name a reader would give them. touch = maxTouchPoints. */
const CORPUS = {
	'iPhone Safari 17': { ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1', touch: 5 },
	'iPhone Safari 15': { ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_8 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6 Mobile/15E148 Safari/604.1', touch: 5 },
	'iPhone Chrome': { ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/129.0.6668.69 Mobile/15E148 Safari/604.1', touch: 5 },
	'iPhone Firefox': { ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/130.0 Mobile/15E148 Safari/605.1.15', touch: 5 },
	'iPhone Edge': { ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 EdgiOS/129.0.2792.84 Mobile/15E148 Safari/605.1.15', touch: 5 },
	'iPhone Instagram': { ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 350.0.0.0.0 (iPhone15,2; iOS 17_6; pt_BR; pt; scale=3.00; 1179x2556; 0)', touch: 5 },
	'iPhone Facebook': { ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/480.0.0.0;FBBV/1;FBDV/iPhone15,2;FBMD/iPhone;FBSN/iOS;FBSV/17.6;FBSS/3;FBLC/pt_BR]', touch: 5 },
	'iPhone Messenger': { ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/MessengerForiOS;FBAV/470.0.0.0]', touch: 5 },
	'iPhone WhatsApp': { ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 WhatsApp/24.20.71', touch: 5 },
	'iPhone LinkedIn': { ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [LinkedInApp]/9.30.1', touch: 5 },
	'iPhone TikTok': { ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 musical_ly_36.5.0 JsSdk/2.0 NetType/WIFI Channel/App Store ByteLocale/pt Region/BR BytedanceWebview/d8a21c6', touch: 5 },
	'iPhone Twitter': { ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Twitter for iPhone/10.60', touch: 5 },
	'iPhone Line': { ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Safari Line/14.13.0', touch: 5 },
	'iPad Safari (desktop UA)': { ua: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15', touch: 5 },
	'iPad Safari (mobile UA)': { ua: 'Mozilla/5.0 (iPad; CPU OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1', touch: 5 },
	'Android Chrome': { ua: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36', touch: 5 },
	'Android Chrome tablet': { ua: 'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', touch: 5 },
	'Android Firefox': { ua: 'Mozilla/5.0 (Android 14; Mobile; rv:130.0) Gecko/130.0 Firefox/130.0', touch: 5 },
	'Android Edge': { ua: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36 EdgA/129.0.2792.84', touch: 5 },
	'Android Opera': { ua: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36 OPR/84.0.0.0', touch: 5 },
	'Android Opera Mini': { ua: 'Opera/9.80 (Android; Opera Mini/36.2.2254/119.132; U; pt) Presto/2.12.423 Version/12.16', touch: 5 },
	'Android Samsung Internet': { ua: 'Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36', touch: 5 },
	'Android Samsung Internet (desktop mode)': { ua: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Safari/537.36', touch: 5 },
	'Android WebView (generic)': { ua: 'Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/AP2A.240905.003; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.81 Mobile Safari/537.36', touch: 5 },
	'Android Instagram': { ua: 'Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.81 Mobile Safari/537.36 Instagram 350.0.0.42.92 Android (34/14; 480dpi; 1080x2340; samsung; SM-S918B; dm3q; qcom; pt_BR; 640000000)', touch: 5 },
	'Android Facebook': { ua: 'Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.81 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/480.0.0.53.81;]', touch: 5 },
	'Android WhatsApp': { ua: 'Mozilla/5.0 (Linux; Android 14; SM-A546E Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.81 Mobile Safari/537.36', touch: 5 },
	'Android TikTok': { ua: 'Mozilla/5.0 (Linux; Android 14; SM-A546E Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.81 Mobile Safari/537.36 musical_ly_2023605030 JsSdk/1.0 NetType/WIFI Channel/googleplay AppName/musical_ly app_version/36.5.3 ByteLocale/pt-BR', touch: 5 },
	'Android LinkedIn': { ua: 'Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/AP2A.240905.003; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.81 Mobile Safari/537.36 [LinkedInApp]/4.1.1015', touch: 5 },
	'Android old stock browser (4.3)': { ua: 'Mozilla/5.0 (Linux; U; Android 4.3; pt-br; GT-I9300 Build/JSS15J) AppleWebKit/534.30 (KHTML, like Gecko) Version/4.0 Mobile Safari/534.30', touch: 5 },
	'Android Go (Chrome)': { ua: 'Mozilla/5.0 (Linux; Android 11; moto e20) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36', touch: 5 },
	'KaiOS': { ua: 'Mozilla/5.0 (Mobile; LYF/F300B/LYF-F300B-001-01-15-130718-i; Android; rv:48.0) Gecko/48.0 Firefox/48.0 KAIOS/2.5', touch: 0 },
	'Windows Chrome': { ua: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', touch: 0 },
	'Windows Edge': { ua: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.2792.79', touch: 0 },
	'Windows Firefox': { ua: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0', touch: 0 },
	'Windows touch laptop Chrome': { ua: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', touch: 10 },
	'macOS Safari': { ua: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15', touch: 0 },
	'macOS Chrome': { ua: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', touch: 0 },
	'macOS Firefox': { ua: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:130.0) Gecko/20100101 Firefox/130.0', touch: 0 },
	'Linux Firefox': { ua: 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0', touch: 0 },
	'ChromeOS': { ua: 'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', touch: 0 },
	'Empty user agent': { ua: '', touch: 0 },
};

function useDevice(entry) {
	Object.defineProperty(navigator, 'userAgent', { configurable: true, value: entry.ua });
	Object.defineProperty(navigator, 'maxTouchPoints', { configurable: true, value: entry.touch });
}

beforeAll(async () => {
	window.ffc_ajax = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'n', strings: {} };
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-field-behaviours.js');
	loadScript('assets/js/ffc-frontend-helpers.js');
	loadScript('assets/js/ffc-frontend.js');
	loadScript('assets/js/ffc-preflight.js');
	loadScript('assets/js/ffc-geofence-frontend.js');
	loadScript('assets/js/ffc-geofence-datetime.js');
	loadScript('assets/js/ffc-geofence-gps.js');
	loadScript('assets/js/ffc-geofence-permission.js');
	await new Promise((r) => setTimeout(r, 0));
});

/** 1. The success card shows the "where is my file" line of one platform. */
async function successCardPlatform() {
	document.body.innerHTML = `
		<form class="ffc-submission-form">
			<input type="hidden" name="form_id" value="1" />
			<button type="submit">Send</button>
		</form>`;
	const html = `
		<div class="ffc-success-response"><ul class="ffc-success-where-is-file">
			<li data-platform="android">a</li><li data-platform="ios">i</li><li data-platform="desktop">d</li>
		</ul></div>`;
	const post = vi.spyOn(window.$, 'post').mockImplementation(() => {
		const chain = {
			done(cb) { cb({ success: true, data: { html } }); return chain; },
			fail() { return chain; },
		};
		return chain;
	});
	window.$('form.ffc-submission-form').trigger('submit');
	await Promise.resolve().then(() => Promise.resolve());
	post.mockRestore();
	return Array.from(document.querySelectorAll('li[data-platform]'))
		.filter((li) => li.style.display !== 'none')
		.map((li) => li.getAttribute('data-platform'))
		.join(',');
}

/** 4. The PDF path: placeholder tab, canvas scale, the message after it. */
async function pdfPath() {
	let scale = null;
	window.jspdf = {
		jsPDF: function () {
			return { addImage() {}, save() {}, output() { return 'blob:fake'; } };
		},
	};
	window.html2canvas = (el, opts) => {
		scale = opts.scale;
		const data = new Uint8ClampedArray(400);
		return Promise.resolve({
			width: 10,
			height: 10,
			getContext: () => ({ getImageData: () => ({ data }) }),
			toDataURL: () => 'data:image/png;base64,x',
		});
	};
	const open = vi.spyOn(window, 'open').mockReturnValue({
		closed: false,
		document: { title: '', body: { innerHTML: '', style: { cssText: '' } } },
		location: {},
	});
	document.body.innerHTML = '';
	loadScript('assets/js/ffc-pdf-generator.js');
	vi.useFakeTimers();
	window.ffcGeneratePDF({ html: '<p>cert</p>', orientation: 'landscape' }, 'cert.pdf');
	await vi.advanceTimersByTimeAsync(2000);
	vi.useRealTimers();
	const preOpen = open.mock.calls.some((c) => c[0] === 'about:blank');
	open.mockRestore();
	const h3 = document.querySelector('#ffc-pdf-overlay h3');
	return { preOpen, scale, message: h3 ? h3.textContent.trim() : null };
}

/** 5. The in-app browser banner, drawn on load or not. */
function webviewBanner() {
	try {
		window.sessionStorage.clear();
	} catch (_e) {
		/* ignore */
	}
	document.body.innerHTML = '<div class="ffc-form-wrapper"><form class="ffc-submission-form"></form></div>';
	window.ffc_webview_warning = { strings: {} };
	loadScript('assets/js/ffc-webview-warning.js');
	return !!document.querySelector('[data-ffc-notice="webview-warning"]');
}

describe('platform detection baseline (#2030)', () => {
	it('answers every browser of the corpus as recorded', async () => {
		const records = {};
		for (const name of Object.keys(CORPUS)) {
			useDevice(CORPUS[name]);
			records[name] = {
				successCard: await successCardPlatform(),
				preflightFamily: window.FFC.Platform.os(),
				gpsSafari: window.FFC.Platform.webkit(),
				pdf: await pdfPath(),
				webviewBanner: webviewBanner(),
			};
		}

		const { expected, actual } = baseline('platform-baseline.json', records);

		expect(Object.keys(actual).length).toBe(Object.keys(CORPUS).length);
		expect(Object.keys(actual).sort()).toEqual(Object.keys(expected).sort());
		const differing = Object.keys(expected).filter((k) => JSON.stringify(expected[k]) !== JSON.stringify(actual[k]));
		expect(differing, differing.length + ' browser(s) answered differently').toEqual([]);
	}, 60000);
});
