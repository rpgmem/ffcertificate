// Tests for the unified `.ffc-collapsed-target` toggle system added in
// Sprint 3 of issue #238. The handler lives at the bottom of
// `assets/js/ffc-admin.js` — it binds change handlers to each master
// input identified by `data-ffc-master="<id>"` so dependent sub-options
// reveal / collapse without a save round-trip.
//
// Covered:
//   - Checkbox-driven gates flip `.ffc-collapsed` on the target.
//   - Select-driven gates respect `data-ffc-master-value`.
//   - `aria-hidden` and `aria-expanded` flip in sync.
//   - Initial-state sync (sync() runs once at init).
//   - Targets with unknown / missing master are silently skipped.

import { describe, it, expect, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

beforeEach(() => {
	document.body.innerHTML = '';
});

function flushReady() {
	// loadScript executes the IIFE synchronously; jQuery $(fn) callbacks
	// inside fire on document-ready, which jsdom + jQuery resolves
	// synchronously when the document is already complete.
	loadScript('assets/js/ffc-admin.js');
}

describe('ffc-admin: .ffc-collapsed-target generic handler', () => {
	it('reveals the target when a checkbox master is checked', () => {
		document.body.innerHTML = `
			<input type="checkbox" id="m1" checked>
			<div class="ffc-collapsed-target" data-ffc-master="m1" aria-hidden="false">
				<input id="sub" type="text">
			</div>
		`;
		flushReady();

		const $ = window.jQuery;
		const target = document.querySelector('.ffc-collapsed-target');
		expect(target.classList.contains('ffc-collapsed')).toBe(false);
		expect(target.getAttribute('aria-hidden')).toBe('false');

		$('#m1').prop('checked', false).trigger('change');
		expect(target.classList.contains('ffc-collapsed')).toBe(true);
		expect(target.getAttribute('aria-hidden')).toBe('true');

		$('#m1').prop('checked', true).trigger('change');
		expect(target.classList.contains('ffc-collapsed')).toBe(false);
		expect(target.getAttribute('aria-hidden')).toBe('false');
	});

	it('flips aria-expanded on the master to mirror the state', () => {
		document.body.innerHTML = `
			<input type="checkbox" id="m2" checked>
			<div class="ffc-collapsed-target" data-ffc-master="m2"></div>
		`;
		flushReady();

		const $ = window.jQuery;
		const master = document.getElementById('m2');
		expect(master.getAttribute('aria-expanded')).toBe('true');

		$(master).prop('checked', false).trigger('change');
		expect(master.getAttribute('aria-expanded')).toBe('false');
	});

	it('honours data-ffc-master-value for select-driven gates', () => {
		document.body.innerHTML = `
			<select id="sel">
				<option value="none" selected>none</option>
				<option value="whitelist">whitelist</option>
			</select>
			<div class="ffc-collapsed-target"
				data-ffc-master="sel"
				data-ffc-master-value="whitelist"></div>
		`;
		flushReady();

		const $ = window.jQuery;
		const target = document.querySelector('.ffc-collapsed-target');
		// Initial state: select is at 'none' → collapsed.
		expect(target.classList.contains('ffc-collapsed')).toBe(true);

		$('#sel').val('whitelist').trigger('change');
		expect(target.classList.contains('ffc-collapsed')).toBe(false);

		$('#sel').val('none').trigger('change');
		expect(target.classList.contains('ffc-collapsed')).toBe(true);
	});

	it('renders initial collapsed state correctly when master is unchecked at load', () => {
		document.body.innerHTML = `
			<input type="checkbox" id="m3">
			<div class="ffc-collapsed-target" data-ffc-master="m3"></div>
		`;
		flushReady();

		const target = document.querySelector('.ffc-collapsed-target');
		// init sync() ran and added .ffc-collapsed since the checkbox is off.
		expect(target.classList.contains('ffc-collapsed')).toBe(true);
		expect(target.getAttribute('aria-hidden')).toBe('true');
	});

	it('silently skips targets whose data-ffc-master is missing or unknown', () => {
		document.body.innerHTML = `
			<div class="ffc-collapsed-target"></div>
			<div class="ffc-collapsed-target" data-ffc-master="nonexistent-id"></div>
		`;
		// Must not throw.
		expect(() => flushReady()).not.toThrow();
	});

	it('supports multiple targets sharing the same master', () => {
		document.body.innerHTML = `
			<input type="checkbox" id="m4">
			<div class="ffc-collapsed-target" id="t1" data-ffc-master="m4"></div>
			<div class="ffc-collapsed-target" id="t2" data-ffc-master="m4"></div>
		`;
		flushReady();

		const $ = window.jQuery;
		const t1 = document.getElementById('t1');
		const t2 = document.getElementById('t2');
		expect(t1.classList.contains('ffc-collapsed')).toBe(true);
		expect(t2.classList.contains('ffc-collapsed')).toBe(true);

		$('#m4').prop('checked', true).trigger('change');
		expect(t1.classList.contains('ffc-collapsed')).toBe(false);
		expect(t2.classList.contains('ffc-collapsed')).toBe(false);
	});
});
