// FFC.status() (#1843): the one status line beside a control, admin and
// dashboard alike. Its states, its registry icon, its live region and its
// escaping.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-core.js');
});

beforeEach(() => {
	document.body.innerHTML = '<span id="line" hidden></span>';
});

function line() {
	return document.getElementById('line');
}

describe('FFC.status', () => {
	it.each([
		['busy', 'ffc-icon-sync'],
		['success', 'ffc-icon-success'],
		['error', 'ffc-icon-error'],
		['warning', 'ffc-icon-warning'],
	])('draws the %s state with its registry icon', (state, icon) => {
		window.FFC.status(line(), state, 'text');

		const el = line();
		expect(el.classList.contains('ffc-status')).toBe(true);
		expect(el.classList.contains('is-' + state)).toBe(true);
		const mark = el.querySelector('.ffc-status-icon');
		expect(mark.classList.contains(icon)).toBe(true);
		expect(mark.getAttribute('aria-hidden')).toBe('true');
		expect(el.querySelector('.ffc-status-text').textContent).toBe('text');
		expect(el.hasAttribute('hidden')).toBe(false);
	});

	it('spins the busy icon and only the busy one', () => {
		window.FFC.status(line(), 'busy', 'Saving…');
		expect(line().querySelector('.ffc-status-icon').classList.contains('ffc-icon-spin')).toBe(true);

		window.FFC.status(line(), 'success', 'Saved');
		expect(line().querySelector('.ffc-icon-spin')).toBeNull();
	});

	it('keeps one state at a time', () => {
		window.FFC.status(line(), 'error', 'a');
		window.FFC.status(line(), 'success', 'b');

		expect(line().className.split(/\s+/).filter((c) => c.indexOf('is-') === 0)).toEqual(['is-success']);
		expect(line().querySelectorAll('.ffc-status-icon')).toHaveLength(1);
	});

	it('announces politely, without overriding a role the markup set', () => {
		window.FFC.status(line(), 'success', 'ok');
		expect(line().getAttribute('role')).toBe('status');
		expect(line().getAttribute('aria-live')).toBe('polite');

		document.body.innerHTML = '<span id="line" role="alert"></span>';
		window.FFC.status(line(), 'error', 'x');
		expect(line().getAttribute('role')).toBe('alert');
	});

	it('writes the text as text, never as markup', () => {
		window.FFC.status(line(), 'error', '<img src=x onerror="window.__ffcStatusPwned=1">');

		expect(line().querySelector('img')).toBeNull();
		expect(line().textContent).toContain('<img src=x');
		expect(window.__ffcStatusPwned).toBeUndefined();
	});

	it('clears and hides the line with no state and no text', () => {
		window.FFC.status(line(), 'success', 'Saved');
		window.FFC.status(line(), '', '');

		expect(line().textContent).toBe('');
		expect(line().getAttribute('hidden')).toBe('hidden');
		expect(line().className).toBe('ffc-status');
	});

	it('shows plain text without an icon for an unknown state', () => {
		window.FFC.status(line(), 'bogus', 'Plain');

		expect(line().querySelector('.ffc-status-icon')).toBeNull();
		expect(line().textContent).toBe('Plain');
		expect(line().hasAttribute('hidden')).toBe(false);
	});

	it('takes a jQuery object or a selector, and returns null for nothing', () => {
		window.FFC.status(window.$('#line'), 'success', 'jq');
		expect(line().textContent).toBe('jq');

		window.FFC.status('#line', 'error', 'sel');
		expect(line().textContent).toBe('sel');

		expect(window.FFC.status('#missing', 'error', 'x')).toBeNull();
		expect(window.FFC.status(null, 'error', 'x')).toBeNull();
	});
});
