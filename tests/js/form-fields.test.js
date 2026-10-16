// Tests for `assets/js/ffc-form-fields.js` (#1729): shuffle, character
// counters, the checkbox cap and "choose at least one".
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffcFormFields = {
		strings: {
			selected: '%1$s of %2$s selected.',
			limitReached: 'Untick one to choose another.',
			chooseOne: 'Choose at least one option.',
		},
	};
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-form-fields.js');
});

const FF = () => window.FFC.FormFields;
const $$ = (sel) => Array.from(document.querySelectorAll(sel));

function group({ max = 0, required = false, n = 4 } = {}) {
	const attrs = (max ? ` data-ffc-max-select="${max}"` : '') + (required ? ' data-ffc-required="1"' : '');
	const boxes = Array.from({ length: n }, (_, i) => `<label><input type="checkbox" name="g[]" value="o${i}"> o${i}</label>`).join('');
	// The renderer's markup: the boxes' group, then the foot row (#1844).
	const foot = max ? '<div class="ffc-field-foot"><span class="ffc-field-counter" aria-hidden="true" data-ffc-counter-for="g"></span></div>' : '';
	return `<fieldset class="ffc-choice-field ffc-choice-checkbox"${attrs}><div class="ffc-radio-group">${boxes}</div>${foot}</fieldset>`;
}

function tick(index) {
	const box = $$('input[type="checkbox"]')[index];
	box.checked = !box.checked;
	box.dispatchEvent(new Event('change', { bubbles: true }));
}

describe('shuffle', () => {
	it('reorders the options with the given random source and keeps every one', () => {
		document.body.innerHTML = '<div id="g"><label>a</label><label>b</label><label>c</label></div>';
		const seq = [0, 0];
		FF().shuffle(document.getElementById('g'), () => seq.shift());
		expect($$('#g label').map((l) => l.textContent)).toEqual(['b', 'c', 'a']);
	});

	it('keeps the empty "Select..." option of a select first', () => {
		document.body.innerHTML = '<select id="s"><option value="">Select...</option><option>a</option><option>b</option><option>c</option></select>';
		FF().shuffle(document.getElementById('s'), () => 0);
		const options = $$('#s option');
		expect(options[0].value).toBe('');
		expect(options.slice(1).map((o) => o.textContent).sort()).toEqual(['a', 'b', 'c']);
	});

	it('init() shuffles a marked container once', () => {
		document.body.innerHTML = '<div data-ffc-shuffle="1"><label>a</label><label>b</label></div>';
		FF().init(document);
		const container = document.querySelector('[data-ffc-shuffle]');
		expect(container.getAttribute('data-ffc-shuffled')).toBe('1');
		const order = $$('label').map((l) => l.textContent).join();
		FF().init(document);
		expect($$('label').map((l) => l.textContent).join()).toBe(order);
	});
});

describe('length counter', () => {
	beforeEach(() => {
		document.documentElement.lang = 'en-US';
		document.body.innerHTML = '<textarea id="t" maxlength="2000" minlength="5"></textarea>'
			+ '<div class="ffc-field-foot"><span class="ffc-field-counter" aria-hidden="true" data-ffc-counter-for="t">0 / 2,000</span></div>';
	});

	it('finds its counter through data-ffc-counter-for, past a message in between', () => {
		const ta = document.querySelector('textarea');
		window.FFC.fieldMessage(ta, 'error', 'Too short.');
		ta.value = 'abcd';
		ta.dispatchEvent(new Event('input', { bubbles: true }));
		expect(document.querySelector('.ffc-field-counter').textContent).toBe('4 / 2,000');
	});

	it('counts characters as the participant types', () => {
		const ta = document.querySelector('textarea');
		ta.value = 'abc';
		ta.dispatchEvent(new Event('input', { bubbles: true }));
		expect(document.querySelector('.ffc-field-counter').textContent).toBe('3 / 2,000');
	});

	it('marks a non-empty answer below the minimum', () => {
		const ta = document.querySelector('textarea');
		const counter = document.querySelector('.ffc-field-counter');
		ta.value = 'abc';
		FF().updateLength(ta);
		expect(counter.classList.contains('is-below-min')).toBe(true);
		ta.value = 'abcdef';
		FF().updateLength(ta);
		expect(counter.classList.contains('is-below-min')).toBe(false);
		ta.value = '';
		FF().updateLength(ta);
		expect(counter.classList.contains('is-below-min')).toBe(false);
	});

	it('counts a character outside the BMP once', () => {
		const ta = document.querySelector('textarea');
		ta.value = 'a😀';
		FF().updateLength(ta);
		expect(document.querySelector('.ffc-field-counter').textContent).toBe('2 / 2,000');
	});
});

describe('checkbox cap', () => {
	beforeEach(() => {
		document.body.innerHTML = group({ max: 2 });
		FF().init(document);
	});

	it('disables the unticked boxes once the cap is reached, and releases them', () => {
		tick(0);
		tick(1);
		const boxes = $$('input[type="checkbox"]');
		expect(boxes.map((b) => b.disabled)).toEqual([false, false, true, true]);
		expect(boxes[2].closest('label').classList.contains('is-disabled')).toBe(true);
		// The counter only counts; the limit is said by a warning message
		// under the boxes, announced politely, which the boxes name (#1844).
		expect(document.querySelector('.ffc-field-counter').textContent).toBe('2 of 2 selected.');
		const msg = document.querySelector('.ffc-field-message');
		expect(msg.textContent).toBe('Untick one to choose another.');
		expect(msg.classList.contains('is-warning')).toBe(true);
		expect(msg.getAttribute('role')).toBe('status');
		expect(msg.previousElementSibling.classList.contains('ffc-radio-group')).toBe(true);
		expect(boxes[0].getAttribute('aria-describedby')).toBe(msg.id);
		expect(boxes[0].hasAttribute('aria-invalid')).toBe(false);

		tick(0);
		expect($$('input[type="checkbox"]').some((b) => b.disabled)).toBe(false);
		expect(document.querySelector('.ffc-field-counter').textContent).toBe('1 of 2 selected.');
		expect(document.querySelector('.ffc-field-message')).toBeNull();
		expect(boxes[0].hasAttribute('aria-describedby')).toBe(false);
	});
});

describe('required checkbox group', () => {
	beforeEach(() => {
		document.body.innerHTML = '<form>' + group({ required: true }) + '</form>';
		FF().init(document);
	});

	it('is invalid with nothing ticked and valid once one is', () => {
		const form = document.querySelector('form');
		expect(form.checkValidity()).toBe(false);
		expect($$('input')[0].validationMessage).toBe('Choose at least one option.');
		tick(2);
		expect(form.checkValidity()).toBe(true);
	});

	it('does not demand a box on an optional group', () => {
		document.body.innerHTML = '<form>' + group() + '</form>';
		FF().init(document);
		expect(document.querySelector('form').checkValidity()).toBe(true);
	});
});
