// FFC.reorder (#2344): Move up / Move down beside a drag handle, so a list
// that reorders by dragging reorders by keyboard too.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffcCoreConfig = { strings: { moveUp: 'Subir', moveDown: 'Descer' } };
	loadScript('assets/js/ffc-core.js');
});

let seq = 0;

function mount() {
	seq++;
	const id = 'list-' + seq;
	document.body.innerHTML = '<ul id="' + id + '">'
		+ ['A', 'B', 'C'].map((n) => '<li class="row" data-n="' + n + '"><span class="grip"></span><input value="' + n + '"></li>').join('')
		+ '</ul>';
	const api = window.FFC.reorder({ list: '#' + id, item: '.row', handle: '.grip' });
	return { id, api };
}

function order(id) {
	return Array.from(document.querySelectorAll('#' + id + ' > .row')).map((r) => r.dataset.n).join('');
}

function btn(id, n, dir) {
	return document.querySelector('#' + id + ' > .row[data-n="' + n + '"] .ffc-reorder-' + dir);
}

// The observer runs after a microtask and then a 0 ms timer: wait past both.
const tick = () => new Promise((r) => setTimeout(r, 5));

describe('FFC.reorder', () => {
	beforeEach(() => {
		document.body.innerHTML = '';
	});

	it('puts a named icon-only pair right after each handle', () => {
		const { id } = mount();
		const grip = document.querySelector('#' + id + ' .row .grip');
		const pair = grip.nextElementSibling;
		expect(pair.className).toBe('ffc-reorder');
		const up = pair.querySelector('.ffc-reorder-up');
		expect(up.getAttribute('aria-label')).toBe('Subir');
		expect(up.classList.contains('ffc-btn-icon')).toBe(true);
		expect(pair.querySelector('.ffc-reorder-down').getAttribute('aria-label')).toBe('Descer');
	});

	it('disables Up on the first row and Down on the last', () => {
		const { id } = mount();
		expect(btn(id, 'A', 'up').disabled).toBe(true);
		expect(btn(id, 'A', 'down').disabled).toBe(false);
		expect(btn(id, 'C', 'down').disabled).toBe(true);
	});

	it('moves a row and keeps the focus on the pressed button', () => {
		const { id } = mount();
		let fired = 0;
		window.$('#' + id).on('ffc:reordered', () => { fired++; });
		btn(id, 'B', 'up').click();
		expect(order(id)).toBe('BAC');
		expect(fired).toBe(1);
		// B is first now: its Up is disabled, so the focus went to its Down.
		expect(document.activeElement).toBe(btn(id, 'B', 'down'));
		btn(id, 'B', 'down').click();
		btn(id, 'B', 'down').click();
		expect(order(id)).toBe('ACB');
		expect(document.activeElement).toBe(btn(id, 'B', 'up'));
	});

	it('gives a row added later its buttons, and fixes the end states after a removal', async () => {
		const { id } = mount();
		const li = document.createElement('li');
		li.className = 'row';
		li.dataset.n = 'D';
		li.innerHTML = '<span class="grip"></span>';
		document.getElementById(id).appendChild(li);
		await tick();
		expect(btn(id, 'D', 'up')).not.toBeNull();
		expect(btn(id, 'C', 'down').disabled).toBe(false);
		li.remove();
		await tick();
		expect(btn(id, 'C', 'down').disabled).toBe(true);
	});

	it('binds a list once, however often a screen initialises it', () => {
		const { id } = mount();
		window.FFC.reorder({ list: '#' + id, item: '.row', handle: '.grip' });
		btn(id, 'A', 'down').click();
		expect(order(id)).toBe('BAC');
		expect(document.querySelectorAll('#' + id + ' .ffc-reorder').length).toBe(3);
	});
});
