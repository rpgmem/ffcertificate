// Tests for assets/js/ffc-role-editor.js — the global role→capability editor
// (Settings → User Access). Picking a role swaps the toggles to that role's
// caps; toggling a cap persists immediately via AJAX (WP_Role add/remove).

import { describe, it, expect, beforeEach, afterEach, beforeAll, vi } from 'vitest';
import { loadScript } from './helpers.js';

function row(slug, checked) {
	return `<div class="ffc-cap-row" data-ffc-cap-slug="${slug}" data-ffc-cap-name="${slug}">
		<label class="ffc-control-toggle"><input type="checkbox" class="ffc-role-cap" data-ffc-cap-slug="${slug}"${checked ? ' checked' : ''}><span class="ffc-control-toggle-track"></span></label>
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
					<option value="ffc_end_user" selected>FFC End User</option>
					<option value="ffc_recruitment_manager">FFC Recruitment - Manager</option>
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
		roleCaps: { ffc_end_user: ['cap_a'], ffc_recruitment_manager: ['cap_b', 'cap_c'] },
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
		expect(body).toContain('role=ffc_end_user');
		expect(body).toContain('cap=cap_b');
		expect(body).toContain('grant=1');

		await new Promise((r) => setTimeout(r, 0));

		const state = document.querySelector('[data-ffc-cap-slug="cap_b"] [data-ffc-savestate]');
		expect(state.textContent).toBe('Saved');
		// in-memory map updated so re-selecting reflects it
		expect(window.ffcRoleEditor.roleCaps.ffc_end_user).toContain('cap_b');
	});

	it('reverts the toggle when the request fails', async () => {
		const fetchMock = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: false }) }));
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		setup(fetchMock);

		const cb = document.querySelector('[data-ffc-cap-slug="cap_a"] .ffc-role-cap');
		cb.checked = false; // user unchecks
		cb.dispatchEvent(new Event('change'));

		await new Promise((r) => setTimeout(r, 0));

		// reverted back to checked (cap_a is granted by ffc_end_user)
		expect(cb.checked).toBe(true);
		expect(alertSpy).toHaveBeenCalledWith('Err');
	});

	it('reverts the toggle and alerts when the network request rejects', async () => {
		const fetchMock = vi.fn(() => Promise.reject(new Error('offline')));
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		setup(fetchMock);

		const cb = document.querySelector('[data-ffc-cap-slug="cap_b"] .ffc-role-cap');
		cb.checked = true;
		cb.dispatchEvent(new Event('change'));

		await new Promise((r) => setTimeout(r, 0));
		expect(cb.checked).toBe(false);
		expect(cb.disabled).toBe(false);
		expect(alertSpy).toHaveBeenCalledWith('Err');
	});

	it('removes the cap from the in-memory map when ungranting succeeds', async () => {
		const fetchMock = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
		setup(fetchMock);

		// cap_a is granted to ffc_end_user; unchecking it should splice it out.
		const cb = document.querySelector('[data-ffc-cap-slug="cap_a"] .ffc-role-cap');
		cb.checked = false;
		cb.dispatchEvent(new Event('change'));

		await new Promise((r) => setTimeout(r, 0));
		expect(window.ffcRoleEditor.roleCaps.ffc_end_user).not.toContain('cap_a');
	});

	it('does not POST when ajaxUrl is absent', () => {
		const fetchMock = vi.fn();
		setup(fetchMock);
		// Remove ajaxUrl from the live config and re-init so the change
		// handler takes the no-persist branch.
		window.ffcRoleEditor.ajaxUrl = '';
		window.ffcRoleEditorInit();
		const cb = document.querySelector('[data-ffc-cap-slug="cap_b"] .ffc-role-cap');
		cb.checked = true;
		cb.dispatchEvent(new Event('change'));
		expect(fetchMock).not.toHaveBeenCalled();
	});
});

describe('group collapse', () => {
	it('toggles is-collapsed and aria-expanded when the group header is clicked', () => {
		setup();
		const header = document.querySelector('.ffc-cap-group-h');
		const group = header.closest('.ffc-cap-group');

		header.click();
		expect(group.classList.contains('is-collapsed')).toBe(true);
		expect(header.getAttribute('aria-expanded')).toBe('false');

		header.click();
		expect(group.classList.contains('is-collapsed')).toBe(false);
		expect(header.getAttribute('aria-expanded')).toBe('true');
	});
});

describe('capability search', () => {
	it('hides rows that do not match and reveals matching groups', () => {
		setup();
		const search = document.querySelector('.ffc-cap-search');
		const group = document.querySelector('.ffc-cap-group');
		// Collapse first so we can verify search re-expands on a hit.
		group.classList.add('is-collapsed');

		search.value = 'cap_b';
		search.dispatchEvent(new Event('input'));

		expect(document.querySelector('[data-ffc-cap-slug="cap_a"].ffc-cap-row').hidden).toBe(true);
		expect(document.querySelector('[data-ffc-cap-slug="cap_b"].ffc-cap-row').hidden).toBe(false);
		expect(group.hidden).toBe(false);
		// A query with a hit re-expands the group.
		expect(group.classList.contains('is-collapsed')).toBe(false);
	});

	it('hides the entire group when nothing matches', () => {
		setup();
		const search = document.querySelector('.ffc-cap-search');
		const group = document.querySelector('.ffc-cap-group');

		search.value = 'zzz-no-match';
		search.dispatchEvent(new Event('input'));

		expect(group.hidden).toBe(true);
	});

	it('shows every row again when the query is cleared', () => {
		setup();
		const search = document.querySelector('.ffc-cap-search');
		search.value = 'cap_b';
		search.dispatchEvent(new Event('input'));
		search.value = '';
		search.dispatchEvent(new Event('input'));

		expect(document.querySelector('[data-ffc-cap-slug="cap_a"].ffc-cap-row').hidden).toBe(false);
		expect(document.querySelector('[data-ffc-cap-slug="cap_c"].ffc-cap-row').hidden).toBe(false);
	});
});

describe('init guards', () => {
	it('returns without error when no .ffc-role-editor panel exists', () => {
		document.body.innerHTML = '<div id="nope"></div>';
		expect(() => window.ffcRoleEditorInit()).not.toThrow();
	});

	it('defers init to DOMContentLoaded when the document is still loading', () => {
		const desc = Object.getOwnPropertyDescriptor(document, 'readyState');
		Object.defineProperty(document, 'readyState', { value: 'loading', configurable: true });
		try {
			window.ffcRoleEditor = {
				ajaxUrl: '/x', nonce: 'n', roleCaps: { ffc_end_user: ['cap_a'] }, i18n: {},
			};
			document.body.innerHTML = markup();
			// Re-evaluating the IIFE while "loading" registers a
			// DOMContentLoaded listener instead of calling init() now.
			loadScript('assets/js/ffc-role-editor.js');
			const select = document.querySelector('.ffc-role-select');
			const cbB = document.querySelector('[data-ffc-cap-slug="cap_b"] .ffc-role-cap');
			// Before DOMContentLoaded init runs, the change listener isn't
			// wired, so switching the role does not reflect onto the toggles.
			select.value = 'ffc_recruitment_manager';
			window.ffcRoleEditor.roleCaps.ffc_recruitment_manager = ['cap_b'];
			select.dispatchEvent(new Event('change'));
			expect(cbB.checked).toBe(false);
			// Fire DOMContentLoaded → init wires the listener.
			document.dispatchEvent(new Event('DOMContentLoaded'));
			select.dispatchEvent(new Event('change'));
			expect(cbB.checked).toBe(true);
		} finally {
			if (desc) { Object.defineProperty(document, 'readyState', desc); }
		}
	});

	it('applyRole skips rows that have no checkbox', () => {
		window.ffcRoleEditor = {
			ajaxUrl: '/x',
			nonce: 'n',
			roleCaps: { ffc_end_user: [] },
			i18n: {},
		};
		document.body.innerHTML = `
			<div class="ffc-role-editor">
				<select class="ffc-role-select"><option value="ffc_end_user" selected>U</option></select>
				<div class="ffc-cap-group">
					<div class="ffc-cap-row" data-ffc-cap-slug="orphan"></div>
				</div>
			</div>`;
		window.ffcRoleEditorInit();
		const select = document.querySelector('.ffc-role-select');
		// Triggering applyRole must not throw on the checkbox-less row.
		expect(() => select.dispatchEvent(new Event('change'))).not.toThrow();
	});

	it('persist() tolerates a row with no savestate element', async () => {
		const fetchMock = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
		window.ffcRoleEditor = {
			ajaxUrl: '/x',
			nonce: 'n',
			roleCaps: { ffc_end_user: [] },
			i18n: { saved: 'Saved' },
		};
		window.fetch = fetchMock;
		globalThis.fetch = fetchMock;
		document.body.innerHTML = `
			<div class="ffc-role-editor">
				<select class="ffc-role-select"><option value="ffc_end_user" selected>U</option></select>
				<div class="ffc-cap-group">
					<div class="ffc-cap-row" data-ffc-cap-slug="cap_x">
						<input type="checkbox" class="ffc-role-cap" data-ffc-cap-slug="cap_x">
					</div>
				</div>
			</div>`;
		window.ffcRoleEditorInit();
		const cb = document.querySelector('.ffc-role-cap');
		cb.checked = true;
		cb.dispatchEvent(new Event('change'));
		await new Promise((r) => setTimeout(r, 0));
		// No throw despite the missing [data-ffc-savestate] node.
		expect(window.ffcRoleEditor.roleCaps.ffc_end_user).toContain('cap_x');
	});

	it('showState clears the indicator after the timeout', async () => {
		vi.useFakeTimers();
		const fetchMock = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
		setup(fetchMock);
		const cb = document.querySelector('[data-ffc-cap-slug="cap_b"] .ffc-role-cap');
		cb.checked = true;
		cb.dispatchEvent(new Event('change'));
		// Resolve the fetch promise chain.
		await vi.runAllTimersAsync?.() ?? Promise.resolve();
		const state = document.querySelector('[data-ffc-cap-slug="cap_b"] [data-ffc-savestate]');
		// After the 1500ms timeout the saved text clears.
		expect(state.textContent).toBe('');
		vi.useRealTimers();
	});
});

// A role that differs from its shipped definition (#2340).
function diffMarkup() {
	return `
	<div class="ffc-cap-panel ffc-role-editor">
		<select class="ffc-role-select">
			<option value="ffc_x_operator" selected>Operator</option>
			<option value="ffc_end_user">End user</option>
		</select>
		<div class="ffc-role-diff" role="status" hidden>
			<p><span class="ffc-role-diff-count"></span></p>
			<button type="button" class="ffc-role-restore">Restore role default</button>
		</div>
		<section class="ffc-cap-group">
			<button class="ffc-cap-group-h"><span class="ffc-cap-count">0</span>/3</button>
			<div class="ffc-cap-group-body">
				${['cap_a', 'cap_b', 'cap_c'].map((slug) => `<div class="ffc-cap-row" data-ffc-cap-slug="${slug}" data-ffc-cap-name="${slug}">
					<input type="checkbox" class="ffc-role-cap">
					<div><span class="ffc-cap-row-name">Name ${slug}<span class="badge">front</span></span></div>
					<span class="ffc-role-cap-side"><span class="ffc-role-diff-tag" hidden></span><span data-ffc-savestate></span></span>
				</div>`).join('')}
			</div>
		</section>
	</div>`;
}

function setupDiff(fetchImpl, confirmAnswer = true) {
	window.ffcRoleEditor = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		nonce: 'n',
		roleCaps: { ffc_x_operator: ['cap_a', 'cap_c'], ffc_end_user: ['cap_b'] },
		roleDefaults: { ffc_x_operator: ['cap_a', 'cap_b'] },
		roles: { ffc_x_operator: { label: 'FFC X - Operator', users: 4 } },
		i18n: {
			error: 'Err',
			saved: 'Saved',
			tagAdded: 'Added to default',
			tagRemoved: 'Removed from default',
			diffSummary: '%1$d added, %2$d removed.',
			restoreIntro: '%1$s goes back (users holding it: %2$d):',
			comesBack: '%s comes back',
			isRemoved: '%s is removed',
			dialog: { title: 'Restore?', seconds: 0 },
		},
	};
	const confirm = vi.fn(() => Promise.resolve(confirmAnswer));
	window.FFC = { overrideConfirm: confirm };
	if (fetchImpl) {
		window.fetch = fetchImpl;
		globalThis.fetch = fetchImpl;
	}
	document.body.innerHTML = diffMarkup();
	window.ffcRoleEditorInit();
	return confirm;
}

const tag = (slug) => document.querySelector(`[data-ffc-cap-slug="${slug}"] .ffc-role-diff-tag`);
const flush = () => new Promise((r) => setTimeout(r, 0));

describe('difference from the default', () => {
	afterEach(() => {
		delete window.FFC;
	});

	it('shows the bar and tags only for the changed rows', () => {
		setupDiff();
		const bar = document.querySelector('.ffc-role-diff');
		expect(bar.hidden).toBe(false);
		expect(bar.querySelector('.ffc-role-diff-count').textContent).toBe('1 added, 1 removed.');
		expect(tag('cap_a').hidden).toBe(true);
		expect(tag('cap_b').textContent).toBe('Removed from default');
		expect(tag('cap_b').classList.contains('ffc-role-diff-tag--removed')).toBe(true);
		expect(tag('cap_c').textContent).toBe('Added to default');
		expect(tag('cap_c').classList.contains('ffc-role-diff-tag--added')).toBe(true);
	});

	it('shows nothing for a role the plugin does not define', () => {
		setupDiff();
		const select = document.querySelector('.ffc-role-select');
		select.value = 'ffc_end_user';
		select.dispatchEvent(new Event('change'));
		expect(document.querySelector('.ffc-role-diff').hidden).toBe(true);
		expect(tag('cap_b').hidden).toBe(true);
	});

	it('updates after a toggle brings the role back to its default', async () => {
		const fetchImpl = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
		setupDiff(fetchImpl);
		const b = document.querySelector('[data-ffc-cap-slug="cap_b"] .ffc-role-cap');
		b.checked = true;
		b.dispatchEvent(new Event('change'));
		await flush();
		const c = document.querySelector('[data-ffc-cap-slug="cap_c"] .ffc-role-cap');
		c.checked = false;
		c.dispatchEvent(new Event('change'));
		await flush();
		expect(document.querySelector('.ffc-role-diff').hidden).toBe(true);
	});

	it('asks first, naming what comes back and what goes, then restores', async () => {
		const fetchImpl = vi.fn(() => Promise.resolve({
			json: () => Promise.resolve({ success: true, data: { caps: ['cap_a', 'cap_b'] } }),
		}));
		const confirm = setupDiff(fetchImpl);
		document.querySelector('.ffc-role-restore').click();
		await flush();
		await flush();

		const opts = confirm.mock.calls[0][0];
		expect(opts.reasons).toEqual(['Name cap_b comes back', 'Name cap_c is removed']);
		expect(opts.strings.intro).toBe('FFC X - Operator goes back (users holding it: 4):');
		expect(opts.strings.title).toBe('Restore?');
		expect(opts.agreeCategory).toBe('caution');
		expect(opts.agreeIcon).toBe('restore');
		expect(opts.strings.seconds).toBe(0);

		const body = String(fetchImpl.mock.calls[0][1].body);
		expect(body).toContain('action=ffc_restore_role_default');
		expect(body).toContain('role=ffc_x_operator');
		expect(document.querySelector('.ffc-role-diff').hidden).toBe(true);
		expect(document.querySelector('[data-ffc-cap-slug="cap_b"] .ffc-role-cap').checked).toBe(true);
		expect(document.querySelector('[data-ffc-cap-slug="cap_c"] .ffc-role-cap').checked).toBe(false);
	});

	it('changes nothing when the dialog is cancelled', async () => {
		const fetchImpl = vi.fn();
		setupDiff(fetchImpl, false);
		document.querySelector('.ffc-role-restore').click();
		await flush();
		expect(fetchImpl).not.toHaveBeenCalled();
		expect(document.querySelector('.ffc-role-diff').hidden).toBe(false);
	});
});

describe('granting a powerful capability (#2340)', () => {
	function powerfulSetup(fetchMock, answer) {
		setup(fetchMock);
		window.ffcRoleEditor.roles = { ffc_end_user: { label: 'FFC End User', users: 7 } };
		window.ffcRoleEditor.i18n.grantTitle = 'Grant "%s"?';
		window.ffcRoleEditor.i18n.grantIntro = '%1$d people hold %2$s. Each of them will be able to:';
		window.ffcRoleEditor.i18n.grantAgree = 'Grant';
		const target = document.querySelector('[data-ffc-cap-slug="cap_b"]');
		target.setAttribute('data-ffc-confirm', '1');
		target.querySelector('div').insertAdjacentHTML('beforeend', '<span class="ffc-cap-row-desc">Reveal the CPF.</span>');
		window.FFC = window.FFC || {};
		const confirmSpy = vi.fn(() => Promise.resolve(answer));
		window.FFC.overrideConfirm = confirmSpy;
		return confirmSpy;
	}

	it('asks first, naming the capability, the role and how many people hold it, then saves', async () => {
		const fetchMock = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
		const confirmSpy = powerfulSetup(fetchMock, true);
		const cb = document.querySelector('[data-ffc-cap-slug="cap_b"] .ffc-role-cap');
		cb.checked = true;
		cb.dispatchEvent(new Event('change'));

		expect(fetchMock).not.toHaveBeenCalled();
		const opts = confirmSpy.mock.calls[0][0];
		expect(opts.strings.title).toBe('Grant "cap_b"?');
		expect(opts.strings.intro).toBe('7 people hold FFC End User. Each of them will be able to:');
		expect(opts.reasons).toEqual(['Reveal the CPF.']);
		expect(opts.agreeCategory).toBe('caution');

		await new Promise((r) => setTimeout(r, 0));
		expect(fetchMock).toHaveBeenCalledTimes(1);
		expect(fetchMock.mock.calls[0][1].body).toContain('grant=1');
	});

	it('puts the toggle back and saves nothing when the dialog is cancelled', async () => {
		const fetchMock = vi.fn();
		powerfulSetup(fetchMock, false);
		const cb = document.querySelector('[data-ffc-cap-slug="cap_b"] .ffc-role-cap');
		cb.checked = true;
		cb.dispatchEvent(new Event('change'));
		await new Promise((r) => setTimeout(r, 0));

		expect(cb.checked).toBe(false);
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('removes a powerful capability without asking', () => {
		const fetchMock = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
		const confirmSpy = powerfulSetup(fetchMock, true);
		document.querySelector('[data-ffc-cap-slug="cap_b"] .ffc-role-cap').checked = true;
		const cb = document.querySelector('[data-ffc-cap-slug="cap_b"] .ffc-role-cap');
		cb.checked = false;
		cb.dispatchEvent(new Event('change'));

		expect(confirmSpy).not.toHaveBeenCalled();
		expect(fetchMock).toHaveBeenCalledTimes(1);
	});
});
