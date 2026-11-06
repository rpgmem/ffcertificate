// Tests for `.ffc-disabled-target` and the section counters in
// `assets/js/ffc-admin.js` (#2340): sub-options that stay visible but grey
// out while their master toggle is off, and an "N of M on" chip that follows
// a section's toggles.

import { describe, it, expect, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

beforeEach(() => {
	document.body.innerHTML = '';
});

function boot() {
	loadScript('assets/js/ffc-admin.js');
}

describe('ffc-admin: .ffc-disabled-target', () => {
	it('disables every control while the master is off and enables it again', () => {
		document.body.innerHTML = `
			<input type="checkbox" id="log" checked>
			<div class="ffc-disabled-target" data-ffc-master="log">
				<input type="checkbox" id="a">
				<input type="radio" id="b">
			</div>
		`;
		boot();
		const $ = window.jQuery;

		expect(document.getElementById('a').disabled).toBe(false);

		$('#log').prop('checked', false).trigger('change');
		expect(document.getElementById('a').disabled).toBe(true);
		expect(document.getElementById('b').disabled).toBe(true);
		expect(document.querySelector('.ffc-disabled-target').classList.contains('is-off')).toBe(true);

		$('#log').prop('checked', true).trigger('change');
		expect(document.getElementById('a').disabled).toBe(false);
		expect(document.querySelector('.ffc-disabled-target').classList.contains('is-off')).toBe(false);
	});

	it('greys the controls out at load when the master starts off', () => {
		document.body.innerHTML = `
			<input type="checkbox" id="log">
			<div class="ffc-disabled-target" data-ffc-master="log"><input type="checkbox" id="a"></div>
		`;
		boot();

		expect(document.getElementById('a').disabled).toBe(true);
	});

	it('leaves a locked control disabled whatever the master says', () => {
		document.body.innerHTML = `
			<input type="checkbox" id="log">
			<div class="ffc-disabled-target" data-ffc-master="log">
				<input type="checkbox" id="locked" data-ffc-locked="1" disabled>
			</div>
		`;
		boot();
		const $ = window.jQuery;

		$('#log').prop('checked', true).trigger('change');
		expect(document.getElementById('locked').disabled).toBe(true);
	});

	it('skips a target whose master does not exist', () => {
		document.body.innerHTML = `
			<div class="ffc-disabled-target" data-ffc-master="missing"><input type="checkbox" id="a"></div>
		`;
		boot();

		expect(document.getElementById('a').disabled).toBe(false);
	});
});

describe('ffc-admin: section counters', () => {
	it('keeps the chip in step with the toggles', () => {
		document.body.innerHTML = `
			<details class="ffc-section" data-ffc-item-count open>
				<summary><span class="ffc-section__chip" data-ffc-count-template="%1$d of %2$d on">0 of 2 on</span></summary>
				<input type="checkbox" id="one">
				<input type="checkbox" id="two">
			</details>
		`;
		boot();
		const $ = window.jQuery;
		const chip = document.querySelector('.ffc-section__chip');

		$('#one').prop('checked', true).trigger('change');
		expect(chip.textContent).toBe('1 of 2 on');
		expect(chip.classList.contains('is-on')).toBe(true);

		$('#one').prop('checked', false).trigger('change');
		expect(chip.textContent).toBe('0 of 2 on');
		expect(chip.classList.contains('is-on')).toBe(false);
	});
});
