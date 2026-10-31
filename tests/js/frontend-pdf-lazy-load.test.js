// #2241 — the PDF libraries are fetched when a page is about to need them:
// on the first focus in a form field, and right after the page loads on the
// verification page and the magic link. The boot watchdog counts the form
// ready on the generator's loader, not on the libraries being present.
import { describe, it, expect, beforeAll, vi } from 'vitest';
import { loadScript } from './helpers.js';

const load = vi.fn(() => Promise.resolve(true));
// A plain record, not a mock: the call happens before the first test runs.
const readyCalls = [];
const ready = (name) => readyCalls.push(name);

beforeAll(async () => {
	document.body.innerHTML =
		'<form class="ffc-submission-form"><input type="text" name="name" id="f-name"><input type="text" name="email" id="f-mail"></form>';
	window.ffc_ajax = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'n', strings: {} };
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-frontend-helpers.js');
	window.ffcGeneratePDF = function () {};
	window.ffcPdfGenerator = { generatePDF: window.ffcGeneratePDF, checkLibraries() { return false; }, load };
	window.FFC.Preflight = { ready, run() {} };
	delete window.html2canvas;
	delete window.jspdf;
	loadScript('assets/js/ffc-frontend.js');
	await new Promise((r) => setTimeout(r, 0));
});

describe('ffc-frontend.js — PDF libraries on demand', () => {
	it('does not fetch them on a form page until a field is focused', () => {
		expect(load).not.toHaveBeenCalled();
	});

	it('fetches them on the first focus in a field, once', () => {
		const field = document.getElementById('f-name');
		field.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
		document.getElementById('f-mail').dispatchEvent(new FocusEvent('focusin', { bubbles: true }));

		expect(load).toHaveBeenCalledTimes(1);
	});

	it('tells the watchdog the form is ready without the libraries on the page', () => {
		expect(readyCalls).toContain('frontend');
	});
});

describe('ffc-frontend.js — verification page', () => {
	it('fetches them right after the page loads', async () => {
		load.mockClear();
		document.body.innerHTML = '<form class="ffc-verification-form"><input name="code"></form>';
		loadScript('assets/js/ffc-frontend.js');
		await new Promise((r) => setTimeout(r, 0));

		expect(load).toHaveBeenCalled();
	});
});
