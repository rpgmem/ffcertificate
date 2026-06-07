// Tests for `assets/js/ffc-self-scheduling-receipt.js` — the appointment
// receipt "Download PDF" button, which calls window.ffcGeneratePDF with
// either the server-built pdfData or the rendered receipt HTML.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const SCRIPT = 'assets/js/ffc-self-scheduling-receipt.js';

let originalGen;
let originalAlert;

beforeEach(() => {
	originalGen   = window.ffcGeneratePDF;
	originalAlert = window.alert;
	document.body.innerHTML =
		'<div id="ffc-receipt-content">RECEIPT HTML</div>' +
		'<button id="ffc-download-pdf-btn">Download</button>';
});

afterEach(() => {
	window.ffcGeneratePDF = originalGen;
	window.alert          = originalAlert;
	delete window.ffcReceiptData;
	document.body.innerHTML = '';
});

describe('ffc-self-scheduling-receipt', () => {
	it('uses the server pdfData when it has html', async () => {
		window.ffcReceiptData = { pdfData: { html: '<b>x</b>', filename: 'r.pdf' } };
		window.ffcGeneratePDF = vi.fn();
		loadScript(SCRIPT);
		await new Promise(function (r) { setTimeout(r, 0); });

		document.getElementById("ffc-download-pdf-btn").click();

		expect(window.ffcGeneratePDF).toHaveBeenCalledWith({ html: '<b>x</b>', filename: 'r.pdf' }, 'r.pdf');
	});

	it('falls back to the receipt HTML + validation-code filename', async () => {
		window.ffcReceiptData = { pdfData: null, validationCode: 'C-1234', appointmentId: '7' };
		window.ffcGeneratePDF = vi.fn();
		loadScript(SCRIPT);
		await new Promise(function (r) { setTimeout(r, 0); });

		document.getElementById("ffc-download-pdf-btn").click();

		expect(window.ffcGeneratePDF).toHaveBeenCalledWith(
			{ html: 'RECEIPT HTML', bg_image: null },
			'Appointment_Receipt_C-1234.pdf'
		);
	});

	it('falls back to the appointment id when there is no validation code', async () => {
		window.ffcReceiptData = { pdfData: null, validationCode: '', appointmentId: '42' };
		window.ffcGeneratePDF = vi.fn();
		loadScript(SCRIPT);
		await new Promise(function (r) { setTimeout(r, 0); });

		document.getElementById("ffc-download-pdf-btn").click();

		expect(window.ffcGeneratePDF).toHaveBeenCalledWith(
			{ html: 'RECEIPT HTML', bg_image: null },
			'Appointment_Receipt_42.pdf'
		);
	});

	it('alerts when the PDF generator is not loaded', async () => {
		window.ffcReceiptData = { errorMsg: 'not loaded' };
		window.ffcGeneratePDF = undefined;
		window.alert = vi.fn();
		loadScript(SCRIPT);
		await new Promise(function (r) { setTimeout(r, 0); });

		document.getElementById("ffc-download-pdf-btn").click();

		expect(window.alert).toHaveBeenCalledWith('not loaded');
	});
});
