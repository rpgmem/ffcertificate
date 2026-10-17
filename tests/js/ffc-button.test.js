// The button family (#1874): `FFC.button()` / `FFC.buttonHtml()` in
// `assets/js/ffc-core.js`, the JS twin of `Core\Button::html()`. The
// expected strings are the markup the PHP helper prints for the same
// arguments (tests/Unit/ButtonTest.php), so the two cannot drift silently.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

const XSS = '<img src=x onerror="window.__pwned=1">';

beforeAll(() => {
	loadScript('assets/js/ffc-core.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
	window.__pwned = undefined;
});

describe('FFC.button', () => {
	it('lists the same five categories as the PHP helper', () => {
		expect(window.FFC.buttonCategories).toEqual(['primary', 'neutral', 'create', 'caution', 'danger']);
	});

	it('prints the PHP markup for every category', () => {
		window.FFC.buttonCategories.forEach((category) => {
			expect(window.FFC.buttonHtml({ label: 'Go', category })).toBe(
				'<button type="button" class="ffc-btn ffc-btn-' + category + '">Go</button>'
			);
		});
	});

	it('falls back to neutral for an unknown category and by default', () => {
		expect(window.FFC.button({ label: 'Go', category: 'success' }).className).toBe('ffc-btn ffc-btn-neutral');
		expect(window.FFC.button({ label: 'Go' }).className).toBe('ffc-btn ffc-btn-neutral');
	});

	it('adds the icon as a class, and refuses a malformed icon name', () => {
		expect(window.FFC.button({ label: 'Remove', category: 'danger', icon: 'delete' }).className)
			.toBe('ffc-btn ffc-btn-danger ffc-icon-delete');
		expect(window.FFC.button({ label: 'Go', icon: 'x" onclick="y' }).className).toBe('ffc-btn ffc-btn-neutral');
	});

	it('returns null without a label', () => {
		expect(window.FFC.button({ category: 'primary' })).toBeNull();
		expect(window.FFC.button({ label: '' })).toBeNull();
		expect(window.FFC.buttonHtml({})).toBe('');
	});

	it('never turns the label or an attribute into markup', () => {
		const el = window.FFC.button({ label: XSS, attrs: { 'data-x': XSS } });
		document.body.appendChild(el);
		expect(el.textContent).toBe(XSS);
		expect(el.querySelector('img')).toBeNull();
		expect(el.getAttribute('data-x')).toBe(XSS);
		expect(window.__pwned).toBeUndefined();
	});

	it('refuses event handlers and malformed names, keeps booleans', () => {
		const el = window.FFC.button({
			label: 'Go',
			attrs: { onclick: 'x()', OnMouseOver: 'x()', 'bad name': '1', 'data-ok': 'yes', 'data-gone': false, 'data-null': null, hidden: true },
		});
		expect(el.hasAttribute('onclick')).toBe(false);
		expect(el.hasAttribute('onmouseover')).toBe(false);
		expect(el.getAttribute('data-ok')).toBe('yes');
		expect(el.hasAttribute('data-gone')).toBe(false);
		expect(el.hasAttribute('data-null')).toBe(false);
		expect(el.hasAttribute('hidden')).toBe(true);
	});

	it('does not let attrs override the class or the type', () => {
		const el = window.FFC.button({ label: 'Go', attrs: { class: 'button', type: 'submit' } });
		expect(el.className).toBe('ffc-btn ffc-btn-neutral');
		expect(el.type).toBe('button');
	});

	it('builds a submit button, disabled', () => {
		const el = window.FFC.button({ label: 'Send', category: 'primary', type: 'submit', disabled: true, id: 'send' });
		expect(el.tagName).toBe('BUTTON');
		expect(el.type).toBe('submit');
		expect(el.disabled).toBe(true);
		expect(el.id).toBe('send');
	});

	it('treats an unknown type as a plain button', () => {
		expect(window.FFC.button({ label: 'Go', type: 'image' }).type).toBe('button');
	});

	it('builds a link when given an href, disabled through aria and a class', () => {
		const el = window.FFC.button({ label: 'Open', href: '/x?a=1', disabled: true, category: 'neutral', icon: 'external' });
		expect(el.tagName).toBe('A');
		expect(el.getAttribute('href')).toBe('/x?a=1');
		expect(el.hasAttribute('type')).toBe(false);
		expect(el.getAttribute('aria-disabled')).toBe('true');
		expect(el.className).toBe('ffc-btn ffc-btn-neutral ffc-icon-external disabled');
	});

	it('appends hook classes once, in order', () => {
		expect(window.FFC.button({ label: 'Go', category: 'create', className: '  ffc-hook  ffc-btn other ' }).className)
			.toBe('ffc-btn ffc-btn-create ffc-hook other');
	});

	it('keeps the icon when a script later rewrites the label', () => {
		const el = window.FFC.button({ label: 'Clear', category: 'danger', icon: 'delete' });
		el.textContent = 'Working…';
		expect(el.classList.contains('ffc-icon-delete')).toBe(true);
	});

	it('prints the PHP markup for an icon-only button: no text, the label as its name', () => {
		expect(window.FFC.buttonHtml({ label: 'Close', icon: 'cross', iconOnly: true, className: 'ffc-modal-close' })).toBe(
			'<button type="button" class="ffc-btn ffc-btn-neutral ffc-btn-icon ffc-icon-cross ffc-modal-close" aria-label="Close" title="Close"></button>'
		);
	});

	it('keeps the icon-only name out of markup and away from attrs', () => {
		const el = window.FFC.button({ label: XSS, category: 'danger', icon: 'cross', iconOnly: true, attrs: { 'aria-label': 'other' } });
		document.body.appendChild(el);
		expect(el.textContent).toBe('');
		expect(el.getAttribute('aria-label')).toBe(XSS);
		expect(el.getAttribute('title')).toBe(XSS);
		expect(el.querySelector('img')).toBeNull();
		expect(window.__pwned).toBeUndefined();
	});

	it('refuses an icon-only button without a label or an icon, like any bad input', () => {
		expect(window.FFC.button({ icon: 'cross', iconOnly: true })).toBeNull();
		expect(window.FFC.button({ label: 'Close', iconOnly: true })).toBeNull();
		expect(window.FFC.button({ label: 'Close', icon: 'x" onclick="y', iconOnly: true })).toBeNull();
		expect(window.FFC.buttonHtml({ label: '', icon: 'cross', iconOnly: true })).toBe('');
	});
});
