// The appointment receipt runs the visitor pre-flight's browser step before
// its PDF is built (#2040): an app's own browser is asked to switch, with
// "Continue anyway", which then downloads; the setting turns it off; and a
// stop is logged against the appointment.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const UA = {
	desktop: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
	instagram: 'Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.81 Mobile Safari/537.36 Instagram 350.0.0.42.92 Android',
};

let generated;
let sent;

function useUa(name) {
	Object.defineProperty(window.navigator, 'userAgent', { value: UA[name], configurable: true });
}

const flush = () => new Promise((r) => setTimeout(r, 10));
const notice = () => document.querySelector('[data-ffc-notice="preflight-browser"]');

async function receiptPage(preflight) {
	document.body.innerHTML =
		'<div id="ffc-receipt-content"><p>Receipt</p></div>' +
		'<div class="ffc-receipt-actions"><button id="ffc-download-pdf-btn">Download</button></div>';
	window.ffcReceiptData = { pdfData: { html: '<b>x</b>', filename: 'r.pdf' }, appointmentId: '44', errorMsg: 'Missing.', preflight };
	loadScript('assets/js/ffc-self-scheduling-receipt.js');
	await flush();
	return document.getElementById('ffc-download-pdf-btn');
}

beforeAll(() => {
	loadScript('assets/js/ffc-core.js');
	delete window.FFC.Preflight;
	loadScript('assets/js/ffc-preflight.js');
});

beforeEach(() => {
	window.sessionStorage.clear();
	generated = [];
	sent = [];
	window.ffcGeneratePDF = (data, filename) => generated.push(filename);
	window.FFC.request = (action, data) => {
		sent.push(data);
		return Promise.resolve({});
	};
});

afterEach(() => {
	useUa('desktop');
	window.$(document).off();
});

describe('receipt page: browser step before the download (#2040)', () => {
	it('downloads at once in an ordinary browser', async () => {
		useUa('desktop');
		const button = await receiptPage({ browserCheck: true, strings: {} });
		button.click();

		expect(generated).toEqual(['r.pdf']);
		expect(notice()).toBeNull();
		expect(sent).toEqual([]);
	});

	it('asks an app\'s browser to switch, and downloads on "Continue anyway"', async () => {
		useUa('instagram');
		const button = await receiptPage({ browserCheck: true, strings: { inAppBody: 'Receipt fails in apps: %s.' } });
		button.click();

		expect(generated).toEqual([]);
		expect(notice().textContent).toContain('Receipt fails in apps');
		expect(window.$(button).css('display')).toBe('none');
		expect(sent).toEqual([{ type: 'receipt', id: 44, reason: 'browser_inapp' }]);

		notice().querySelector('.ffc-preflight-continue').click();

		expect(generated).toEqual(['r.pdf']);
		expect(notice()).toBeNull();
		expect(window.$(button).css('display')).not.toBe('none');

		// The choice holds for the visit: a second download goes straight on.
		button.click();
		expect(generated).toEqual(['r.pdf', 'r.pdf']);
	});

	it('downloads as before when the setting is off', async () => {
		useUa('instagram');
		const button = await receiptPage({ browserCheck: false, strings: {} });
		button.click();

		expect(generated).toEqual(['r.pdf']);
		expect(notice()).toBeNull();
	});
});

describe('booking confirmation: browser step before the receipt (#2040)', () => {
	async function confirm() {
		document.body.innerHTML =
			'<div class="ffc-confirmation-wrapper" style="display:none;"><div class="ffc-confirmation-success"><h3>Done</h3></div>' +
			'<div class="ffc-appointment-details"></div><div class="ffc-confirmation-email" style="display:none;"></div></div>' +
			'<div id="ffc-self-scheduling-modal"></div>';
		window.ffcCalendar = {
			nonce: 'n',
			ajaxurl: '/wp-admin/admin-ajax.php',
			strings: { downloadReceipt: 'Download receipt', months: [] },
			preflight: { browserCheck: true, strings: {} },
		};
		loadScript('assets/js/ffc-calendar-frontend.js');
		await flush();
		const ctl = window.ffcCalendarFrontend;
		ctl.selectedDate = '2027-03-10';
		ctl.selectedTime = '09:00';
		ctl.showConfirmation({ appointment_id: 12, pdf_data: { html: '<b>x</b>', filename: 'booking.pdf' } });
	}

	it('holds the automatic download in an app\'s browser and logs it against the appointment', async () => {
		vi.useFakeTimers({ toFake: ['setTimeout'] });
		try {
			useUa('instagram');
			const pending = confirm();
			await vi.advanceTimersByTimeAsync(20);
			await pending;
			await vi.advanceTimersByTimeAsync(1000);
		} finally {
			vi.useRealTimers();
		}

		expect(generated).toEqual([]);
		expect(document.querySelectorAll('[data-ffc-notice="preflight-browser"]').length).toBe(1);
		expect(sent).toEqual([{ type: 'receipt', id: 12, reason: 'browser_inapp' }]);

		notice().querySelector('.ffc-preflight-continue').click();
		expect(generated).toEqual(['booking.pdf']);
		expect(window.$('.ffc-download-receipt-btn').css('display')).not.toBe('none');
	});
});
