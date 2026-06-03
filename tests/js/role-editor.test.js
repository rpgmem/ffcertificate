// Tests for assets/js/ffc-role-editor.js — the global role→capability editor
// (Settings → User Access). Picking a role swaps the toggles to that role's
// caps; toggling a cap persists immediately via AJAX (WP_Role add/remove).

import { describe, it, expect, beforeEach, afterEach, beforeAll, vi } from 'vitest';
import { loadScript } from './helpers.js';

function row(slug, checked) {
	return `<div class="ffc-cap-row" data-ffc-cap-slug="${slug}" data-ffc-cap-name="${slug}">
		<label class="ffc-toggle"><input type="checkbox" class="ffc-role-cap" data-ffc-cap-slug="${slug}"${checked ? ' checked' : ''}><span class="ffc-toggle-track"></span></label>
		<div><span class="ffc-cap-row-name">${slug}</span></div>
		<span class="ffc-cap-savestate" data-ffc-savestate></span>
	</div>`;
}

function markup() {
	return `
	<div class="ffc-cap-panel ffc-role-editor">
		<div class="ffc-cap-toolbar">
			<label class="ffc-role-pick">Role:
				<select class="ffc-role-select">
					<option value="ffc_user" selected>FFC User</option>
					<option value="ffc_recruitment_manager">Recruitment Manager</option>
				</select>
			</label>
			<input type="search" class="ffc-cap-search">
		</div>
		<section class="ffc-cap-group" data-ffc-group="g">
			<button class="ffc-cap-group-h"><span class="ffc-cap-count">1</span>/3</button>
			<div class="ffc-cap-group-body">
				${row('cap_a', true)}
				${row('cap_b', false)}
				${row('cap_c', false)}
			</div>
		</section>
	</div>`;
}

function setup(fetchImpl) {
	window.ffcRoleEditor = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		nonce: 'role-cap-nonce',
		roleCaps: { ffc_user: ['cap_a'], ffc_recruitment_manager: ['cap_b', 'cap_c'] },
		i18n: { error: 'Err', saved: 'Saved' },
	};
	if (fetchImpl) {
		window.fetch = fetchImpl;
		globalThis.fetch = fetchImpl;
	}
	document.body.innerHTML = markup();
	window.ffcRoleEditorInit();
}

beforeAll(() => {
	loadScript('assets/js/ffc-role-editor.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
});

afterEach(() => {
	delete window.ffcRoleEditor;
	vi.restoreAllMocks();
});

describe('role picker', () => {
	it('swaps the toggles to the selected role’s caps and updates the count', () => {
		setup();
		const select = document.querySelector('.ffc-role-select');
		select.value = 'ffc_recruitment_manager';
		select.dispatchEvent(new Event('change'));

		expect(document.querySelector('[data-ffc-cap-slug="cap_a"] .ffc-role-cap').checked).toBe(false);
		expect(document.querySelector('[data-ffc-cap-slug="cap_b"] .ffc-role-cap').checked).toBe(true);
		expect(document.querySelector('[data-ffc-cap-slug="cap_c"] .ffc-role-cap').checked).toBe(true);
		expect(document.querySelector('.ffc-cap-count').textContent).toBe('2');
	});
});

describe('persist toggle', () => {
	it('POSTs the role + cap + grant and shows a saved indicator on success', async () => {
		const fetchMock = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
		setup(fetchMock);

		const cb = document.querySelector('[data-ffc-cap-slug="cap_b"] .ffc-role-cap');
		cb.checked = true;
		cb.dispatchEvent(new Event('change'));

		expect(fetchMock).toHaveBeenCalledTimes(1);
		const body = fetchMock.mock.calls[0][1].body;
		expect(body).toContain('action=ffc_set_role_cap');
		expect(body).toContain('role=ffc_user');
		expect(body).toContain('cap=cap_b');
		expect(body).toContain('grant=1');

		await new Promise((r) => setTimeout(r, 0));

		const state = document.querySelector('[data-ffc-cap-slug="cap_b"] [data-ffc-savestate]');
		expect(state.textContent).toBe('Saved');
		// in-memory map updated so re-selecting reflects it
		expect(window.ffcRoleEditor.roleCaps.ffc_user).toContain('cap_b');
	});

	it('reverts the toggle when the request fails', async () => {
		const fetchMock = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: false }) }));
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		setup(fetchMock);

		const cb = document.querySelector('[data-ffc-cap-slug="cap_a"] .ffc-role-cap');
		cb.checked = false; // user unchecks
		cb.dispatchEvent(new Event('change'));

		await new Promise((r) => setTimeout(r, 0));

		// reverted back to checked (cap_a is granted by ffc_user)
		expect(cb.checked).toBe(true);
		expect(alertSpy).toHaveBeenCalledWith('Err');
	});
});
