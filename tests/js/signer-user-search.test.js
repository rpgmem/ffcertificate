import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// Add signer's account search (#2622).
function markup() {
	document.body.innerHTML = `
		<form id="f">
			<div data-ffc-signer-user-search>
				<input type="hidden" name="ffc_signer_user" value="">
				<input type="text" id="q" role="combobox" aria-expanded="false" aria-controls="r">
				<ul id="r" role="listbox" hidden></ul>
				<p id="s" role="status"></p>
			</div>
			<button type="submit">Add</button>
		</form>`;
}

const USERS = [
	{ id: 7, label: 'Maria Souza (maria)' },
	{ id: 9, label: 'Mário Lima (mario)' },
];

describe('ffc-signer-user-search', () => {
	let request;

	beforeEach(() => {
		vi.useFakeTimers();
		markup();
		request = vi.fn(() => Promise.resolve({ users: USERS }));
		window.FFC = { request };
		window.ffcSignerUserSearch = {
			action: 'ffc_search_signer_candidates',
			nonce: 'n1',
			minChars: 2,
			strings: { found: '%d found', none: 'none', failed: 'failed', choose: 'choose', searching: '…' },
		};
		loadScript('assets/js/ffc-signer-user-search.js');
	});

	afterEach(() => {
		vi.useRealTimers();
		delete window.FFC;
		delete window.ffcSignerUserSearch;
	});

	const q = () => document.getElementById('q');
	const list = () => document.getElementById('r');
	const hidden = () => document.querySelector('input[name="ffc_signer_user"]');
	const status = () => document.getElementById('s').textContent;

	async function type(value) {
		q().value = value;
		q().dispatchEvent(new window.Event('input'));
		await vi.advanceTimersByTimeAsync(300);
	}

	function key(name) {
		const event = new window.KeyboardEvent('keydown', { key: name, cancelable: true });
		q().dispatchEvent(event);
		return event;
	}

	it('asks the server after a pause and lists the accounts', async () => {
		await type('mar');
		expect(request).toHaveBeenCalledTimes(1);
		expect(request).toHaveBeenCalledWith('ffc_search_signer_candidates', { term: 'mar' }, { nonce: 'n1' });
		const options = list().querySelectorAll('[role="option"]');
		expect(options).toHaveLength(2);
		expect(options[0].textContent).toBe('Maria Souza (maria)');
		expect(list().hidden).toBe(false);
		expect(q().getAttribute('aria-expanded')).toBe('true');
		expect(status()).toBe('2 found');
	});

	it('waits for the shortest term and debounces typing', async () => {
		await type('m');
		expect(request).not.toHaveBeenCalled();
		q().value = 'ma';
		q().dispatchEvent(new window.Event('input'));
		q().value = 'mar';
		q().dispatchEvent(new window.Event('input'));
		await vi.advanceTimersByTimeAsync(300);
		expect(request).toHaveBeenCalledTimes(1);
	});

	it('moves with the arrows, picks with Enter and closes with Escape', async () => {
		await type('mar');
		expect(key('ArrowDown').defaultPrevented).toBe(true);
		key('ArrowDown');
		key('ArrowDown');
		expect(q().getAttribute('aria-activedescendant')).toBe('r-1');
		key('ArrowUp');
		expect(list().querySelector('.is-active').textContent).toBe('Maria Souza (maria)');
		key('Enter');
		expect(hidden().value).toBe('7');
		expect(q().value).toBe('Maria Souza (maria)');
		expect(list().hidden).toBe(true);

		await type('mar');
		key('Escape');
		expect(list().hidden).toBe(true);
		expect(hidden().value).toBe('', 'typing again drops the choice');
		expect(key('Enter').defaultPrevented).toBe(false, 'a closed list leaves Enter alone');
	});

	it('picks with a click', async () => {
		await type('mar');
		const option = list().querySelectorAll('[role="option"]')[1];
		option.dispatchEvent(new window.MouseEvent('mousedown', { bubbles: true, cancelable: true }));
		expect(hidden().value).toBe('9');
		q().dispatchEvent(new window.Event('blur'));
		await vi.advanceTimersByTimeAsync(200);
		expect(list().hidden).toBe(true);
	});

	it('says when nothing matches and when the search fails', async () => {
		request.mockImplementationOnce(() => Promise.resolve({ users: [] }));
		await type('zzz');
		expect(status()).toBe('none');
		expect(list().hidden).toBe(true);

		request.mockImplementationOnce(() => Promise.reject(new Error('x')));
		await type('yyy');
		expect(status()).toBe('failed');
	});

	it('drops an answer that arrives after a newer search', async () => {
		let resolveFirst;
		request.mockImplementationOnce(() => new Promise((r) => { resolveFirst = r; }));
		await type('ma');
		await type('mar');
		resolveFirst({ users: [{ id: 1, label: 'Old' }] });
		await vi.advanceTimersByTimeAsync(0);
		expect(list().textContent).not.toContain('Old');
		expect(list().querySelectorAll('[role="option"]')).toHaveLength(2);
	});

	it('stops a submit with no account chosen', async () => {
		const form = document.getElementById('f');
		const event = new window.Event('submit', { cancelable: true });
		form.dispatchEvent(event);
		expect(event.defaultPrevented).toBe(true);
		expect(q().getAttribute('aria-invalid')).toBe('true');
		expect(status()).toBe('choose');

		await type('mar');
		key('ArrowDown');
		key('Enter');
		const second = new window.Event('submit', { cancelable: true });
		form.dispatchEvent(second);
		expect(second.defaultPrevented).toBe(false);
		expect(q().hasAttribute('aria-invalid')).toBe(false);
	});

	it('boots once per box', () => {
		const root = document.querySelector('[data-ffc-signer-user-search]');
		const first = root.ffcSignerUserSearch;
		window.FFCSignerUserSearch.boot();
		expect(root.ffcSignerUserSearch).toBe(first);
	});
});
