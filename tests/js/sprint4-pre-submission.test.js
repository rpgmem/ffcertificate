// Sprint 4 of #313 (6.6.2) — pre-submission warnings:
//   - "Don't close this tab" hint paints inside the overlay during
//     generation (was previously only visible in the iOS/Samsung
//     placeholder-tab path).
//   - navigator.onLine === false short-circuits both the form-submit
//     flow and the download-button click with an actionable message
//     before the AJAX even fires.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(async () => {
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'test-nonce',
		strings: {
			offlineMessage: 'Você parece estar offline.',
			pdfDontCloseTab: 'Não feche esta aba até o PDF aparecer.',
			fillRequired: 'preencha tudo',
			processing: 'Processando…',
			error: 'erro',
			connectionError: 'falha de conexão',
		},
	};
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-frontend-helpers.js');
	loadScript('assets/js/ffc-frontend.js');
	loadScript('assets/js/ffc-pdf-generator.js');
	await new Promise((r) => setTimeout(r, 0));
});

beforeEach(() => {
	document.body.innerHTML = '';
	// Default to online; individual tests override.
	Object.defineProperty(navigator, 'onLine', { configurable: true, value: true });
});

afterEach(() => {
	vi.restoreAllMocks();
});

describe('Sprint 4 — overlay "don\'t close this tab" hint', () => {
	it('paints the dontCloseTab line inside the overlay during generation', () => {
		// Trigger the overlay via the lib-load error path (libraries
		// stubs cleared by the bigger pdf-generator.test.js fixture).
		// We don't need a successful generation — just opening the
		// overlay once.
		delete window.html2canvas;
		delete window.jspdf;
		vi.spyOn(console, 'error').mockImplementation(() => {});

		window.ffcGeneratePDF({}, 'x.pdf');

		const overlay = document.querySelector('#ffc-pdf-overlay');
		expect(overlay).not.toBeNull();
		const dontClose = overlay.querySelector('.ffc-pdf-dont-close');
		// renderOverlayError() empties the content on error paths, so
		// the don't-close paragraph is removed on error. We assert that
		// the paragraph WAS rendered initially — call showOverlay
		// directly via the public API.
		// The lib-failed path empties the overlay; instead inspect a
		// fresh overlay opened by ffcGeneratePDF before any error UI
		// replaces it. To do that we mock checkLibraries to pass once.
		window.html2canvas = function () { return new Promise(() => {}); };
		window.jspdf = { jsPDF: function () { return { addImage() {}, save() {}, output() { return ''; } }; } };
		// Clear and re-open.
		$('#ffc-pdf-overlay').remove();
		window.ffcGeneratePDF({ html: '<p>x</p>' }, 'x.pdf');
		const overlay2 = document.querySelector('#ffc-pdf-overlay');
		expect(overlay2).not.toBeNull();
		const dontClose2 = overlay2.querySelector('.ffc-pdf-dont-close');
		expect(dontClose2).not.toBeNull();
		expect(dontClose2.textContent).toContain('Não feche');
	});

	it('falls back to the English default when the i18n string is absent', () => {
		const savedStrings = window.ffc_ajax.strings;
		window.ffc_ajax.strings = {};
		window.html2canvas = function () { return new Promise(() => {}); };
		window.jspdf = { jsPDF: function () { return { addImage() {}, save() {}, output() { return ''; } }; } };

		$('#ffc-pdf-overlay').remove();
		window.ffcGeneratePDF({ html: '<p>x</p>' }, 'x.pdf');
		const dontClose = document.querySelector('.ffc-pdf-dont-close');
		expect(dontClose).not.toBeNull();
		expect(dontClose.textContent).toMatch(/do not close/i);

		window.ffc_ajax.strings = savedStrings;
	});
});

describe('Sprint 4 — offline short-circuit on form submit', () => {
	function setupForm() {
		document.body.innerHTML = `
			<form class="ffc-submission-form">
				<input type="hidden" name="form_id" value="1" />
				<button type="submit">Send</button>
			</form>
		`;
		return window.$('form.ffc-submission-form');
	}

	it('bails with the offline message when navigator.onLine is false', async () => {
		Object.defineProperty(navigator, 'onLine', { configurable: true, value: false });
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => ({ done: () => ({ fail: () => ({}) }), fail: () => ({}) }));
		const $form = setupForm();
		$form.trigger('submit');
		await Promise.resolve().then(() => Promise.resolve());

		// Form-submit handler aborted BEFORE the AJAX layer.
		expect(postSpy).not.toHaveBeenCalled();
		const alert = document.querySelector('.ffc-accessible-alert');
		expect(alert).not.toBeNull();
		expect(alert.textContent).toContain('offline');
	});

	it('proceeds to AJAX submit when navigator.onLine is true', async () => {
		Object.defineProperty(navigator, 'onLine', { configurable: true, value: true });
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => {
			const chain = {
				done() { return chain; },
				fail() { return chain; },
			};
			return chain;
		});
		const $form = setupForm();
		$form.trigger('submit');
		await Promise.resolve().then(() => Promise.resolve());

		expect(postSpy).toHaveBeenCalled();
	});
});

describe('Sprint 4 — offline short-circuit on download click', () => {
	it('bails with the offline message when navigator.onLine is false', () => {
		Object.defineProperty(navigator, 'onLine', { configurable: true, value: false });
		document.body.innerHTML = `
			<div class="ffc-form-wrapper">
				<button class="ffc-download-pdf-btn" data-pdf-data='{"html":"x"}'>Download</button>
			</div>
		`;
		const generateSpy = vi.fn();
		window.ffcGeneratePDF = generateSpy;

		window.$('.ffc-download-pdf-btn').trigger('click');

		expect(generateSpy).not.toHaveBeenCalled();
		const alert = document.querySelector('.ffc-accessible-alert');
		expect(alert).not.toBeNull();
		expect(alert.textContent).toContain('offline');
	});
});
