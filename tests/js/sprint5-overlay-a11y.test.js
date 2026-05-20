// Sprint 5 of #313 (6.6.2) — accessibility for the PDF generation
// overlay. Pins:
//   - role="dialog" + aria-modal + aria-labelledby + aria-describedby
//   - aria-busy toggles between in-flight (true) and final (false)
//   - focus moves into the dialog when it opens
//   - focus is returned to the trigger element on hideOverlay
//   - Escape only closes when interactive controls are present
//   - aria-live="polite" on the message paragraph so status changes
//     are announced.
import { describe, it, expect, beforeAll, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(async () => {
	window.ffc_ajax = {
		strings: {
			generatingPdf: 'Generating PDF…',
			pleaseWait: 'Please wait…',
			pdfDontCloseTab: "Don't close",
			pdfErrorTitle: 'Falha',
			pdfErrorGeneric: 'Generic',
			pdfErrorRetry: 'Retry',
			pdfErrorClose: 'Close',
			pdfErrorHtml2canvas: 'h2c failed',
			pdfLibrariesFailed: 'libs not loaded',
			pdfErrorLibrariesBody: 'reload the page',
		},
	};
	loadScript('assets/js/ffc-pdf-generator.js');
	await new Promise((r) => setTimeout(r, 0));
});

beforeEach(() => {
	document.body.innerHTML = '';
	delete window.__ffcPdfOverlayReturnFocus;
});

describe('overlay ARIA contract', () => {
	it('paints role=dialog, aria-modal, aria-labelledby/-describedby, aria-busy=true', () => {
		// Stub the libs so we land in the generation overlay (not the
		// error panel which would clear the live region IDs).
		window.html2canvas = function () { return new Promise(() => {}); };
		window.jspdf = { jsPDF: function () { return { addImage() {}, save() {}, output() { return ''; } }; } };
		vi.spyOn(console, 'error').mockImplementation(() => {});

		window.ffcGeneratePDF({ html: '<p>x</p>' }, 'x.pdf');

		const overlay = document.querySelector('#ffc-pdf-overlay');
		expect(overlay).not.toBeNull();
		expect(overlay.getAttribute('role')).toBe('dialog');
		expect(overlay.getAttribute('aria-modal')).toBe('true');
		expect(overlay.getAttribute('aria-busy')).toBe('true');
		expect(overlay.getAttribute('aria-labelledby')).toBe('ffc-pdf-overlay-title');
		expect(overlay.getAttribute('aria-describedby')).toBe('ffc-pdf-overlay-message');
		expect(document.getElementById('ffc-pdf-overlay-title')).not.toBeNull();
		const desc = document.getElementById('ffc-pdf-overlay-message');
		expect(desc).not.toBeNull();
		expect(desc.getAttribute('aria-live')).toBe('polite');
	});

	it('flips aria-busy to false when the error panel paints', () => {
		// Trigger the lib-load error path → overlay opens, then
		// renderOverlayError() repaints with interactive controls.
		delete window.html2canvas;
		delete window.jspdf;
		vi.spyOn(console, 'error').mockImplementation(() => {});

		window.ffcGeneratePDF({}, 'x.pdf');

		const overlay = document.querySelector('#ffc-pdf-overlay');
		expect(overlay).not.toBeNull();
		expect(overlay.getAttribute('aria-busy')).toBe('false');
	});
});

describe('focus management', () => {
	it('moves focus into the dialog on open and back to the trigger on close', async () => {
		// Build a focusable trigger, focus it, then open the overlay.
		document.body.innerHTML = `<button id="trigger">open</button>`;
		const trigger = document.getElementById('trigger');
		trigger.focus();
		expect(document.activeElement).toBe(trigger);

		window.html2canvas = function () { return new Promise(() => {}); };
		window.jspdf = { jsPDF: function () { return { addImage() {}, save() {}, output() { return ''; } }; } };
		vi.spyOn(console, 'error').mockImplementation(() => {});

		window.ffcGeneratePDF({ html: '<p>x</p>' }, 'x.pdf');

		const overlay = document.querySelector('#ffc-pdf-overlay');
		expect(overlay).not.toBeNull();
		expect(document.activeElement).toBe(overlay);

		// Close the overlay via the public surface: simulate the
		// fadeOut callback synchronously via $.fx.off.
		window.$.fx.off = true;
		// Re-trigger close by removing the overlay through hideOverlay.
		// We don't have a public hideOverlay export, but the error
		// path's Close button calls it. Open the error panel first.
		// Easier: emit Escape after error-panel paints (requires lib failure).
		// Simpler: just remove the overlay and call the return-focus
		// behaviour manually by inspecting window.__ffcPdfOverlayReturnFocus.
		expect(window.__ffcPdfOverlayReturnFocus).toBe(trigger);

		// Tear down: full close via the lib-failed path with Close button.
		document.body.innerHTML = `<button id="trigger2">go</button>`;
		const trigger2 = document.getElementById('trigger2');
		trigger2.focus();
		delete window.html2canvas;
		delete window.jspdf;
		window.ffcGeneratePDF({}, 'x.pdf');
		const closeBtn = document.querySelector('.ffc-pdf-error-close');
		expect(closeBtn).not.toBeNull();
		closeBtn.click();
		// fadeOut(300) is short-circuited by $.fx.off=true.
		expect(document.querySelector('#ffc-pdf-overlay')).toBeNull();
		expect(document.activeElement).toBe(trigger2);
		window.$.fx.off = false;
	});

	it('moves focus to the first action button when error panel renders', () => {
		delete window.html2canvas;
		delete window.jspdf;
		vi.spyOn(console, 'error').mockImplementation(() => {});

		window.ffcGeneratePDF({}, 'x.pdf');

		const closeBtn = document.querySelector('.ffc-pdf-error-close');
		expect(closeBtn).not.toBeNull();
		// Lib-failed error has no retry button, so close is the only one
		// → that's where focus lands.
		expect(document.activeElement).toBe(closeBtn);
	});
});

describe('keyboard behaviour', () => {
	it('Escape is ignored during in-flight generation (no buttons visible)', () => {
		window.html2canvas = function () { return new Promise(() => {}); };
		window.jspdf = { jsPDF: function () { return { addImage() {}, save() {}, output() { return ''; } }; } };
		vi.spyOn(console, 'error').mockImplementation(() => {});
		window.$.fx.off = true;

		window.ffcGeneratePDF({ html: '<p>x</p>' }, 'x.pdf');
		const overlay = document.querySelector('#ffc-pdf-overlay');
		expect(overlay).not.toBeNull();

		// Fire Escape on the overlay; nothing interactive yet → overlay stays open.
		window.$(overlay).trigger(window.$.Event('keydown', { key: 'Escape' }));
		expect(document.querySelector('#ffc-pdf-overlay')).not.toBeNull();

		window.$.fx.off = false;
	});

	it('Escape closes the overlay once the error panel is showing', () => {
		delete window.html2canvas;
		delete window.jspdf;
		vi.spyOn(console, 'error').mockImplementation(() => {});
		window.$.fx.off = true;

		window.ffcGeneratePDF({}, 'x.pdf');
		const overlay = document.querySelector('#ffc-pdf-overlay');
		expect(overlay).not.toBeNull();
		// Close button is present → Escape is wired.
		window.$(overlay).trigger(window.$.Event('keydown', { key: 'Escape' }));
		expect(document.querySelector('#ffc-pdf-overlay')).toBeNull();

		window.$.fx.off = false;
	});
});
