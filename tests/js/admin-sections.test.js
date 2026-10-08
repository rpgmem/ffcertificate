// Tests for FFC.AdminSections — the collapsible `.ffc-section` component
// (#1614): chips that follow a toggle, and sections that open around a
// control the browser rejects.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-admin-sections.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
});

function section(inner, { open = false, chip = '' } = {}) {
	return (
		`<details class="ffc-section"${open ? ' open' : ''} data-ffc-section>` +
		`<summary class="ffc-section__summary"><span class="ffc-section__title">T</span>${chip}</summary>` +
		`<div class="ffc-section__body">${inner}</div></details>`
	);
}

describe('FFC.AdminSections chips', () => {
	it('paints the chip from its master and follows it live', () => {
		document.body.innerHTML =
			'<input type="checkbox" id="m1">' +
			section('', { chip: '<span class="ffc-section__chip is-on" data-ffc-section-master="m1" data-on="On" data-off="Off">On</span>' });
		window.FFC.AdminSections.init(document);
		const chip = document.querySelector('.ffc-section__chip');

		expect(chip.classList.contains('is-off')).toBe(true);
		expect(chip.textContent).toBe('Off');

		const master = document.getElementById('m1');
		master.checked = true;
		master.dispatchEvent(new Event('change'));
		expect(chip.classList.contains('is-on')).toBe(true);
		expect(chip.classList.contains('is-off')).toBe(false);
		expect(chip.textContent).toBe('On');
	});

	it('leaves a chip alone when its master is missing, and binds once', () => {
		document.body.innerHTML =
			'<input type="checkbox" id="m2" checked>' +
			section('', { chip: '<span class="ffc-section__chip" id="lost" data-ffc-section-master="nope">09:00</span>' }) +
			section('', { chip: '<span class="ffc-section__chip" id="bound" data-ffc-section-master="m2" data-on="On" data-off="Off"></span>' });
		window.FFC.AdminSections.init(document);
		window.FFC.AdminSections.init(document);

		expect(document.getElementById('lost').textContent).toBe('09:00');
		expect(document.getElementById('bound').getAttribute('data-ffc-section-bound')).toBe('1');
		expect(document.getElementById('bound').textContent).toBe('On');
	});
});

describe('FFC.AdminSections invalid controls', () => {
	it('opens every section around a control the browser rejects', () => {
		document.body.innerHTML = section(section('<input id="req" required>'));
		const [outer, inner] = document.querySelectorAll('details');
		expect(outer.open).toBe(false);

		document.getElementById('req').checkValidity();

		expect(outer.open).toBe(true);
		expect(inner.open).toBe(true);
	});

	it('does nothing for a control outside a section', () => {
		document.body.innerHTML = '<details id="other"><summary>x</summary><input id="req" required></details>';
		document.getElementById('req').checkValidity();
		expect(document.getElementById('other').open).toBe(false);
	});
});
