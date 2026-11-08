// The plugin's replacements for the browser's native dialogs (#2535):
// FFC.confirmDialog() for confirm() and prompt(), FFC.say() and
// FFC.adminNotice() for alert(). A native dialog blocks the page, ignores
// the notice and button standard and the dark theme, and a prompt() has no
// label assistive technology can read.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffc_ajax = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'n', strings: {} };
	window.ffcCoreConfig = { version: 'x', strings: { confirmTitle: 'Are you sure?', confirm: 'Confirm', cancel: 'Cancel' } };
	loadScript('assets/js/ffc-core.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
});

afterEach(() => {
	vi.restoreAllMocks();
});

const dialog = () => document.querySelector('.ffc-override-dialog');
const agree = () => dialog().querySelector('[data-ffc-override="agree"]');
const keep = () => dialog().querySelector('[data-ffc-override="cancel"]');

describe('FFC.confirmDialog', () => {
	it('asks the question in the plugin dialog, one paragraph per line, with no rule list', () => {
		window.FFC.confirmDialog({ message: 'Delete this field?\n\nIts answers stay in the submissions.', title: 'Delete field' });

		expect(dialog().getAttribute('role')).toBe('alertdialog');
		expect(dialog().getAttribute('aria-modal')).toBe('true');
		expect(dialog().querySelector('h3').textContent).toBe('Delete field');
		const paragraphs = Array.from(dialog().querySelectorAll('.ffc-confirm-message')).map((p) => p.textContent);
		expect(paragraphs).toEqual(['Delete this field?', 'Its answers stay in the submissions.']);
		expect(dialog().querySelector('.ffc-override-reasons')).toBeNull();
		expect(dialog().querySelector('[data-ffc-notice="override-reasons"]')).toBeNull();
	});

	it('takes the default title and labels from the core strings, and focuses Cancel', () => {
		window.FFC.confirmDialog({ message: 'Go?' });

		expect(dialog().querySelector('h3').textContent).toBe('Are you sure?');
		expect(agree().textContent).toBe('Confirm');
		expect(keep().textContent).toBe('Cancel');
		expect(document.activeElement).toBe(keep());
	});

	it('confirms at once: no countdown', async () => {
		const promise = window.FFC.confirmDialog({ message: 'Go?', confirmLabel: 'Delete', category: 'danger', icon: 'trash' });
		expect(agree().disabled).toBe(false);
		expect(agree().textContent).toBe('Delete');
		expect(agree().className).toContain('ffc-btn-danger');
		expect(agree().className).toContain('ffc-icon-trash');
		agree().click();
		await expect(promise).resolves.toBe(true);
		expect(dialog()).toBeNull();
	});

	it('resolves false on Cancel, on Escape and on the backdrop, and gives the focus back', async () => {
		const trigger = document.createElement('button');
		document.body.appendChild(trigger);
		trigger.focus();

		let promise = window.FFC.confirmDialog({ message: 'Go?' });
		keep().click();
		await expect(promise).resolves.toBe(false);
		expect(document.activeElement).toBe(trigger);

		promise = window.FFC.confirmDialog({ message: 'Go?' });
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
		await expect(promise).resolves.toBe(false);

		promise = window.FFC.confirmDialog({ message: 'Go?' });
		dialog().querySelector('.ffc-modal-backdrop').click();
		await expect(promise).resolves.toBe(false);
	});

	it('with a reason field it replaces prompt(): labelled, required, and resolves with the text', async () => {
		const promise = window.FFC.confirmDialog({ message: 'Cancel this appointment?', reasonField: { label: 'Reason', min: 5 } });
		const field = dialog().querySelector('.ffc-override-reason textarea');
		expect(dialog().querySelector('label[for="' + field.id + '"]').textContent).toBe('Reason');
		expect(agree().disabled).toBe(true);

		field.value = 'abc';
		field.dispatchEvent(new Event('input'));
		expect(agree().disabled).toBe(true);
		field.value = '  Room flooded ';
		field.dispatchEvent(new Event('input'));
		expect(agree().disabled).toBe(false);

		agree().click();
		await expect(promise).resolves.toBe('Room flooded');
	});

	it('prints the message as text, never as markup', () => {
		window.FFC.confirmDialog({ message: '<img src=x onerror="window.__pwned=1">' });
		expect(dialog().querySelector('img')).toBeNull();
		expect(dialog().querySelector('.ffc-confirm-message').textContent).toContain('<img');
	});
});

describe('FFC.say', () => {
	it('replaces the host content with one notice: an error is a danger alert', () => {
		document.body.innerHTML = '<div id="host" aria-live="polite" hidden><p>old</p></div>';
		const notice = window.FFC.say('#host', 'danger', 'It failed.');

		const host = document.getElementById('host');
		expect(host.hidden).toBe(false);
		expect(host.children).toHaveLength(1);
		expect(notice.classList.contains('ffc-notice-danger')).toBe(true);
		expect(notice.getAttribute('role')).toBe('alert');
		expect(notice.textContent).toContain('It failed.');
	});

	it('anything else is a status in its tone, and a jQuery host works too', () => {
		document.body.innerHTML = '<div id="host"></div>';
		const notice = window.FFC.say(window.jQuery('#host'), 'success', 'Saved.');
		expect(notice.classList.contains('ffc-notice-success')).toBe(true);
		expect(notice.getAttribute('role')).toBe('status');
	});

	it('focuses the notice when asked, and writes text, not markup', () => {
		document.body.innerHTML = '<div id="host"></div>';
		const notice = window.FFC.say('#host', 'info', '<b>x</b>', { focus: true });
		expect(document.activeElement).toBe(notice);
		expect(notice.querySelector('b')).toBeNull();
	});

	it('does nothing without a host or a message', () => {
		document.body.innerHTML = '<div id="host"><p>kept</p></div>';
		expect(window.FFC.say('#missing', 'info', 'x')).toBeNull();
		expect(window.FFC.say('#host', 'info', '')).toBeNull();
		expect(document.getElementById('host').textContent).toBe('kept');
	});
});

describe('FFC.adminNotice', () => {
	it('creates one polite host right under the screen header and focuses the notice', () => {
		document.body.innerHTML = '<div class="wrap"><h1>Screen</h1><hr class="wp-header-end"><form></form></div>';
		const first = window.FFC.adminNotice('success', 'Done.');

		const host = document.getElementById('ffc-admin-status');
		expect(host.getAttribute('aria-live')).toBe('polite');
		expect(host.previousElementSibling.classList.contains('wp-header-end')).toBe(true);
		expect(document.activeElement).toBe(first);

		window.FFC.adminNotice('danger', 'Failed.');
		expect(document.querySelectorAll('#ffc-admin-status')).toHaveLength(1);
		expect(host.textContent).toContain('Failed.');
		expect(host.textContent).not.toContain('Done.');
	});

	it('falls back to the top of the wrap, then of the body', () => {
		document.body.innerHTML = '<div class="wrap"><h1>Screen</h1></div>';
		window.FFC.adminNotice('info', 'x');
		expect(document.querySelector('.wrap').firstElementChild.id).toBe('ffc-admin-status');

		document.body.innerHTML = '<main></main>';
		window.FFC.adminNotice('info', 'y', { focus: false });
		expect(document.body.firstElementChild.id).toBe('ffc-admin-status');
		expect(document.activeElement).not.toBe(document.querySelector('#ffc-admin-status .ffc-notice'));
	});
});
