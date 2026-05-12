// Tests for the two critical-path frontend scripts:
//   - assets/js/ffc-csv-download.js          (668 LOC) — Public CSV download flow
//   - assets/js/ffc-reregistration-frontend.js (507 LOC) — Reregistration form
//
// Both are large IIFEs with extensive internal state and many bail
// points; deep coverage requires fixtures matching every querySelector
// call. This sprint focuses on (a) the load-side smoke (no-container
// no-op) and (b) the cleanest delegate handlers.
//
// Sprint 1 of the JS coverage roadmap — last critical-path block.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeEach(() => {
	document.body.innerHTML = '';
	window.ffc_csv_download = undefined;
	window.ffcReregistration = undefined;
});

afterEach(() => {
	vi.restoreAllMocks();
});

async function loadOnReady(path) {
	loadScript(path);
	await new Promise((r) => setTimeout(r, 0));
}

// ======================================================================
// ffc-csv-download
// ======================================================================

describe('ffc-csv-download — load-side', () => {
	it('does nothing when .ffc-public-csv-download container is absent', async () => {
		// The IIFE init() bails early if the container can't be found.
		document.body.innerHTML = '<div>no csv</div>';
		expect(() => loadScript('assets/js/ffc-csv-download.js')).not.toThrow();
		await new Promise((r) => setTimeout(r, 0));
	});

	it('initialises against a minimal fixture without throwing', async () => {
		window.ffc_csv_download = {
			min_display_ms: 1500,
			strings: {
				processing: 'Processing…',
				downloading: 'Downloading…',
				error: 'Error',
			},
		};
		document.body.innerHTML = `
			<div class="ffc-public-csv-download">
				<form>
					<input type="text" name="form_id" value="42" />
					<input type="text" name="hash" value="abc" />
					<button type="submit" class="ffc-csv-info-btn">Info</button>
				</form>
				<div class="ffc-csv-overlay" style="display:none"></div>
			</div>
		`;
		expect(() => loadScript('assets/js/ffc-csv-download.js')).not.toThrow();
		await new Promise((r) => setTimeout(r, 0));
	});

	it('intercepts the form submit when the info button is clicked', async () => {
		window.ffc_csv_download = {
			strings: {},
		};
		document.body.innerHTML = `
			<div class="ffc-public-csv-download">
				<form>
					<input type="text" name="form_id" value="42" />
					<input type="text" name="hash" value="abc" />
					<button type="submit">Info</button>
				</form>
			</div>
		`;
		await loadOnReady('assets/js/ffc-csv-download.js');

		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));
		const ev = window.$.Event('submit');
		window.$('.ffc-public-csv-download form').trigger(ev);
		// Event was prevented and AJAX was attempted.
		expect(ev.isDefaultPrevented()).toBe(true);
		expect(ajaxSpy).toHaveBeenCalled();
	});
});

// ======================================================================
// ffc-reregistration-frontend
// ======================================================================

describe('ffc-reregistration-frontend — load-side', () => {
	beforeEach(() => {
		window.ffcReregistration = {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			restUrl: '/wp-json/ffc/v1/',
			nonce: 'r-nonce',
			strings: {
				loading: 'Loading…',
				saving: 'Saving…',
				saveDraftSuccess: 'Saved.',
				submitSuccess: 'Submitted.',
				cpfInvalid: 'Invalid CPF',
				required: 'Required',
			},
		};
	});

	it('loads without throwing on a minimal page (no banner buttons)', async () => {
		document.body.innerHTML = '<div>no rereg here</div>';
		expect(() => loadScript('assets/js/ffc-reregistration-frontend.js')).not.toThrow();
		await new Promise((r) => setTimeout(r, 0));
	});

	it('binds a click delegate on .ffc-rereg-open-form that calls $.post', async () => {
		document.body.innerHTML = `
			<button class="ffc-rereg-open-form" data-reregistration-id="7">Open</button>
			<div id="ffc-rereg-form-container"></div>
		`;
		await loadOnReady('assets/js/ffc-reregistration-frontend.js');

		// $.post returns a thenable with .fail/.always — return a stub that
		// satisfies the chain.
		const chain = { done: () => chain, fail: () => chain, always: () => chain };
		const postSpy = vi.spyOn(window.$, 'post').mockReturnValue(chain);
		window.$('.ffc-rereg-open-form').trigger('click');

		// The handler should fire $.post (loadForm ajax call).
		expect(postSpy).toHaveBeenCalled();
	});

	it('script loads cleanly when the page has rereg form fixtures', async () => {
		document.body.innerHTML = `
			<button class="ffc-rereg-open-form" data-reregistration-id="42">Open</button>
			<div id="ffc-rereg-form-container">
				<form class="ffc-rereg-form">
					<input type="text" name="cpf" class="ffc-mask-cpf" />
					<input type="text" name="phone" class="ffc-mask-phone" />
				</form>
			</div>
		`;
		expect(() => loadScript('assets/js/ffc-reregistration-frontend.js')).not.toThrow();
		await new Promise((r) => setTimeout(r, 0));
	});
});
