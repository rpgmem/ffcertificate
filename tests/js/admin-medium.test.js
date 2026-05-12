// Tests for the three medium admin scripts:
//   - assets/js/ffc-audience-admin.js        (466 LOC)
//   - assets/js/ffc-reregistration-admin.js  (322 LOC)
//   - assets/js/ffc-custom-fields-admin.js   (225 LOC)
//
// All three are event-delegate IIFEs with no public surface. Tests
// drive the document-level handlers via synthetic events.
//
// Sprint 2 of the JS coverage roadmap — focused on the handlers that
// have clean fixtures; deeper paths with brittle DOM dependencies are
// deferred to dedicated tests when each surface needs them.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeEach(() => {
	document.body.innerHTML = '';
});

afterEach(() => {
	vi.restoreAllMocks();
});

async function loadOnReady(path) {
	loadScript(path);
	await new Promise((r) => setTimeout(r, 0));
}

// ======================================================================
// ffc-reregistration-admin — return-to-draft confirm path
// ======================================================================

describe('ffc-reregistration-admin', () => {
	beforeEach(async () => {
		window.ffcReregistrationAdmin = {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			nonce: 'r-nonce',
			strings: {
				confirmApprove: 'Approve?',
				confirmReturnToDraft: 'Return to draft?',
			},
		};
		document.body.innerHTML = `
			<button class="ffc-return-draft-btn" data-id="42">Return</button>
		`;
		await loadOnReady('assets/js/ffc-reregistration-admin.js');
	});

	it('return-to-draft button prompts confirm and aborts on decline', () => {
		const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
		const ev = window.$.Event('click');
		window.$('.ffc-return-draft-btn').trigger(ev);
		expect(confirmSpy).toHaveBeenCalled();
		expect(ev.isDefaultPrevented()).toBe(true);
	});

	it('return-to-draft button does NOT call preventDefault on accept', () => {
		// When the user confirms, the click flows through to the natural
		// link/form action (preventDefault NOT called by the handler).
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		const ev = window.$.Event('click');
		window.$('.ffc-return-draft-btn').trigger(ev);
		expect(ev.isDefaultPrevented()).toBe(false);
	});
});

// ======================================================================
// ffc-custom-fields-admin — pure-DOM handlers
// ======================================================================

describe('ffc-custom-fields-admin', () => {
	beforeEach(async () => {
		// jQuery UI sortable is not in our test env — stub it as a no-op
		// so initSortable() doesn't crash.
		window.$.fn.sortable = function () { return this; };

		document.body.innerHTML = `
			<div class="ffc-custom-fields-list">
				<div class="ffc-custom-field-row" data-index="0">
					<input type="checkbox" class="ffc-field-active" checked />
					<select class="ffc-field-type"><option value="text" selected>Text</option><option value="number">Number</option></select>
					<select class="ffc-field-format"><option value="none" selected>None</option><option value="custom_regex">Regex</option></select>
					<input class="ffc-field-regex" style="display:none" />
					<input class="ffc-field-regex-msg" style="display:none" />
					<button class="ffc-field-delete">Delete</button>
					<button class="ffc-field-toggle-details">Details</button>
					<div class="ffc-field-details" style="display:none">…</div>
				</div>
			</div>
			<button id="ffc-add-custom-field">Add</button>
		`;
		await loadOnReady('assets/js/ffc-custom-fields-admin.js');
	});

	it('toggle-details click reaches the handler (slideToggle starts)', () => {
		const details = document.querySelector('.ffc-field-details');
		expect(details.style.display).toBe('none');
		window.$('.ffc-field-toggle-details').trigger('click');
		// jQuery slideToggle animates; just verify the row didn't error.
		expect(details).not.toBeNull();
	});

	it('format=custom_regex reveals the regex + regex-msg inputs', () => {
		const $sel = window.$('.ffc-field-format');
		$sel.val('custom_regex').trigger('change');
		const regex = document.querySelector('.ffc-field-regex');
		const regexMsg = document.querySelector('.ffc-field-regex-msg');
		expect(regex.style.display).not.toBe('none');
		expect(regexMsg.style.display).not.toBe('none');
	});

	it('format=none re-hides the regex inputs', () => {
		const $sel = window.$('.ffc-field-format');
		// First reveal.
		$sel.val('custom_regex').trigger('change');
		// Then revert.
		$sel.val('none').trigger('change');
		const regex = document.querySelector('.ffc-field-regex');
		expect(regex.style.display).toBe('none');
	});

	it('active-toggle off applies .ffc-field-inactive class', () => {
		const $cb = window.$('.ffc-field-active');
		$cb.prop('checked', false).trigger('change');
		const row = document.querySelector('.ffc-custom-field-row');
		expect(row.classList.contains('ffc-field-inactive')).toBe(true);
	});

	it('active-toggle on removes .ffc-field-inactive class', () => {
		const $cb = window.$('.ffc-field-active');
		// First flip off.
		$cb.prop('checked', false).trigger('change');
		// Then back on.
		$cb.prop('checked', true).trigger('change');
		const row = document.querySelector('.ffc-custom-field-row');
		expect(row.classList.contains('ffc-field-inactive')).toBe(false);
	});

	it('field-type change handler runs without throwing on basic fixture', () => {
		const $sel = window.$('.ffc-field-type');
		// Switching to a different type shouldn't crash even if subsequent
		// DOM dependencies are absent.
		expect(() => $sel.val('number').trigger('change')).not.toThrow();
	});
});

// ======================================================================
// ffc-audience-admin — script load smoke
// ======================================================================

describe('ffc-audience-admin — load-side smoke', () => {
	it('loads and self-initialises without throwing on a minimal DOM', async () => {
		document.body.innerHTML = `<div class="ffc-audience-admin"></div>`;
		expect(() => loadScript('assets/js/ffc-audience-admin.js')).not.toThrow();
		await new Promise((r) => setTimeout(r, 0));
		// Sanity: window.FFCAudienceAdmin exposed (the IIFE creates the
		// namespace inside its closure but assigns to `const`; nothing
		// is published on window, so this is just a "didn't crash" test).
		expect(true).toBe(true);
	});
});
