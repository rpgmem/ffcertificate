// Tests for assets/js/ffc-user-capabilities.js — the grouped per-user
// capability manager interactions: grant/revoke-all presets, live search
// across label + slug, collapsible group cards, copy-slug, and the live
// per-group "granted" counter.
//
// The script binds to elements found under .ffc-cap-panel at init() time,
// so each test injects fresh markup and calls window.ffcUserPermissionsInit().

import { describe, it, expect, beforeEach, afterEach, beforeAll, vi } from 'vitest';
import { loadScript } from './helpers.js';

function panelMarkup() {
	return `
	<div class="ffc-cap-panel">
		<div class="ffc-cap-toolbar">
			<input type="search" class="ffc-cap-search">
			<button type="button" data-ffc-preset="all">all</button>
			<button type="button" data-ffc-preset="none">none</button>
		</div>
		<section class="ffc-cap-group" data-ffc-group="certificate">
			<button type="button" class="ffc-cap-group-h" aria-expanded="true">
				<span class="ffc-cap-count">1</span>/2
			</button>
			<div class="ffc-cap-group-body">
				<div class="ffc-cap-row" data-ffc-cap-name="view own certificates ffc_view_own_certificates" data-ffc-cap-slug="ffc_view_own_certificates">
					<input type="checkbox" class="ffc-cap-checkbox" checked>
					<button type="button" class="ffc-cap-copy" data-ffc-copy="ffc_view_own_certificates">C</button>
				</div>
				<div class="ffc-cap-row" data-ffc-cap-name="download own certificates ffc_download_own_certificates" data-ffc-cap-slug="ffc_download_own_certificates">
					<input type="checkbox" class="ffc-cap-checkbox">
				</div>
			</div>
		</section>
		<section class="ffc-cap-group is-collapsed" data-ffc-group="admin_recruitment">
			<button type="button" class="ffc-cap-group-h" aria-expanded="false">
				<span class="ffc-cap-count">0</span>/1
			</button>
			<div class="ffc-cap-group-body">
				<div class="ffc-cap-row" data-ffc-cap-name="view sensitive data (pii) ffc_view_recruitment_pii" data-ffc-cap-slug="ffc_view_recruitment_pii">
					<input type="checkbox" class="ffc-cap-checkbox">
				</div>
			</div>
		</section>
	</div>`;
}

function setup() {
	document.body.innerHTML = panelMarkup();
	window.ffcUserPermissionsInit();
}

function counts() {
	return [...document.querySelectorAll('.ffc-cap-count')].map((c) => c.textContent);
}

beforeAll(() => {
	loadScript('assets/js/ffc-user-capabilities.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
});

afterEach(() => {
	vi.restoreAllMocks();
});

describe('presets', () => {
	it('"Grant all" checks every box and refreshes the per-group counts', () => {
		setup();
		document.querySelector('[data-ffc-preset="all"]').click();

		const boxes = [...document.querySelectorAll('.ffc-cap-checkbox')];
		expect(boxes.every((b) => b.checked)).toBe(true);
		expect(counts()).toEqual(['2', '1']);
	});

	it('"Revoke all" unchecks every box and zeroes the counts', () => {
		setup();
		document.querySelector('[data-ffc-preset="none"]').click();

		const boxes = [...document.querySelectorAll('.ffc-cap-checkbox')];
		expect(boxes.some((b) => b.checked)).toBe(false);
		expect(counts()).toEqual(['0', '0']);
	});
});

describe('search', () => {
	it('filters rows by slug, hides non-matching groups, and expands matches', () => {
		setup();
		const search = document.querySelector('.ffc-cap-search');
		search.value = 'pii';
		search.dispatchEvent(new Event('input'));

		const certGroup = document.querySelector('[data-ffc-group="certificate"]');
		const admGroup = document.querySelector('[data-ffc-group="admin_recruitment"]');

		expect(certGroup.hidden).toBe(true);
		expect(admGroup.hidden).toBe(false);
		// The matching admin card auto-expands so the hit isn't hidden.
		expect(admGroup.classList.contains('is-collapsed')).toBe(false);
		expect(document.querySelector('[data-ffc-cap-slug="ffc_view_recruitment_pii"]').hidden).toBe(false);
	});

	it('clearing the query restores all rows and groups', () => {
		setup();
		const search = document.querySelector('.ffc-cap-search');
		search.value = 'pii';
		search.dispatchEvent(new Event('input'));
		search.value = '';
		search.dispatchEvent(new Event('input'));

		expect(document.querySelector('[data-ffc-group="certificate"]').hidden).toBe(false);
		expect([...document.querySelectorAll('.ffc-cap-row')].every((r) => !r.hidden)).toBe(true);
	});
});

describe('collapse', () => {
	it('toggles is-collapsed and aria-expanded when the header is clicked', () => {
		setup();
		const group = document.querySelector('[data-ffc-group="certificate"]');
		const header = group.querySelector('.ffc-cap-group-h');

		header.click();
		expect(group.classList.contains('is-collapsed')).toBe(true);
		expect(header.getAttribute('aria-expanded')).toBe('false');

		header.click();
		expect(group.classList.contains('is-collapsed')).toBe(false);
		expect(header.getAttribute('aria-expanded')).toBe('true');
	});
});

describe('copy slug', () => {
	it('writes the slug to the clipboard and shows a confirmation glyph', () => {
		setup();
		const writeText = vi.fn();
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });

		const btn = document.querySelector('.ffc-cap-copy');
		btn.click();

		expect(writeText).toHaveBeenCalledWith('ffc_view_own_certificates');
		expect(btn.textContent).toBe('✓');
	});
});

describe('live count', () => {
	it('updates a group count when one of its checkboxes changes', () => {
		setup();
		const admBox = document.querySelector('[data-ffc-group="admin_recruitment"] .ffc-cap-checkbox');
		admBox.checked = true;
		admBox.dispatchEvent(new Event('change'));

		const admCount = document.querySelector('[data-ffc-group="admin_recruitment"] .ffc-cap-count');
		expect(admCount.textContent).toBe('1');
	});
});

// ---- role preset chips + illumination + live recompute (#Frente A) ----

function rolePanelMarkup() {
	return `
	<div class="ffc-cap-panel">
		<div class="ffc-cap-roles">
			<div class="ffc-cap-role-chips">
				<button class="ffc-cap-role is-on" data-ffc-role="ffc_end_user" aria-pressed="true">
					<span class="ffc-cap-role-mark"></span><span class="ffc-cap-role-nm">FFC End User</span><span class="ffc-cap-role-ct">2 caps</span>
				</button>
				<button class="ffc-cap-role" data-ffc-role="ffc_recruitment_manager" aria-pressed="false">
					<span class="ffc-cap-role-mark"></span><span class="ffc-cap-role-nm">Recruitment Manager</span><span class="ffc-cap-role-ct">1 cap</span>
				</button>
			</div>
		</div>
		<section class="ffc-cap-group" data-ffc-group="g">
			<button class="ffc-cap-group-h"><span class="ffc-cap-count">1</span>/3</button>
			<div class="ffc-cap-group-body">
				${capRow('cap_a', true)}
				${capRow('cap_b', false)}
				${capRow('cap_c', false)}
			</div>
		</section>
	</div>`;
}

function capRow(slug, userGranted) {
	return `<div class="ffc-cap-row" data-ffc-cap-slug="${slug}" data-ffc-cap-name="${slug}" data-ffc-user-granted="${userGranted ? '1' : '0'}">
		<label class="ffc-toggle"><input type="checkbox" class="ffc-cap-checkbox"${userGranted ? ' checked' : ''}><span class="ffc-toggle-track"></span></label>
		<div><span class="ffc-cap-row-name">${slug}</span><span class="ffc-cap-role-tag" data-ffc-role-tag></span></div>
		<span class="ffc-cap-origin" data-ffc-origin>—</span>
	</div>`;
}

function roleSetup(fetchImpl) {
	window.ffcUserPerms = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		nonce: 'role-nonce',
		userId: 5,
		roleCaps: { ffc_end_user: ['cap_a', 'cap_b'], ffc_recruitment_manager: ['cap_c'] },
		assigned: ['ffc_end_user'],
		i18n: { user: 'User', role: 'Role', none: '—', error: 'Err' },
	};
	if (fetchImpl) {
		window.fetch = fetchImpl;
		globalThis.fetch = fetchImpl;
	}
	document.body.innerHTML = rolePanelMarkup();
	window.ffcUserPermissionsInit();
}

describe('role presets', () => {
	afterEach(() => {
		delete window.ffcUserPerms;
	});

	it('illuminates the caps a role grants on hover and clears on leave', () => {
		roleSetup();
		const chip = document.querySelector('[data-ffc-role="ffc_recruitment_manager"]');
		chip.dispatchEvent(new Event('mouseenter'));

		const rowC = document.querySelector('[data-ffc-cap-slug="cap_c"]');
		expect(rowC.classList.contains('is-lit')).toBe(true);
		expect(rowC.querySelector('[data-ffc-role-tag]').textContent).toBe('Recruitment Manager');
		// A cap the role does not grant stays dark.
		expect(document.querySelector('[data-ffc-cap-slug="cap_a"]').classList.contains('is-lit')).toBe(false);

		chip.dispatchEvent(new Event('mouseleave'));
		expect(rowC.classList.contains('is-lit')).toBe(false);
	});

	it('assigns a role via AJAX and locks the caps it grants as "Role"', async () => {
		const fetchMock = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
		roleSetup(fetchMock);

		document.querySelector('[data-ffc-role="ffc_recruitment_manager"]').click();

		expect(fetchMock).toHaveBeenCalledTimes(1);
		const body = fetchMock.mock.calls[0][1].body;
		expect(body).toContain('action=ffc_toggle_user_role');
		expect(body).toContain('role=ffc_recruitment_manager');
		expect(body).toContain('assign=1');

		await new Promise((r) => setTimeout(r, 0));

		const chip = document.querySelector('[data-ffc-role="ffc_recruitment_manager"]');
		expect(chip.classList.contains('is-on')).toBe(true);
		expect(chip.getAttribute('aria-pressed')).toBe('true');

		const rowC = document.querySelector('[data-ffc-cap-slug="cap_c"]');
		const cb = rowC.querySelector('.ffc-cap-checkbox');
		expect(cb.checked).toBe(true);
		expect(cb.disabled).toBe(true);
		expect(rowC.querySelector('[data-ffc-origin]').textContent).toBe('Role');
	});

	it('removing a role restores the per-user state of its caps', async () => {
		const fetchMock = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
		roleSetup(fetchMock);

		// cap_a is user-granted (data-ffc-user-granted="1"); ffc_end_user also grants it.
		document.querySelector('[data-ffc-role="ffc_end_user"]').click();
		await new Promise((r) => setTimeout(r, 0));

		const rowA = document.querySelector('[data-ffc-cap-slug="cap_a"]');
		const cbA = rowA.querySelector('.ffc-cap-checkbox');
		// No longer role-locked → enabled, and restored to its user-granted state.
		expect(cbA.disabled).toBe(false);
		expect(cbA.checked).toBe(true);
		expect(rowA.querySelector('[data-ffc-origin]').textContent).toBe('User');

		// cap_b was not user-granted → reverts to none.
		const rowB = document.querySelector('[data-ffc-cap-slug="cap_b"]');
		expect(rowB.querySelector('.ffc-cap-checkbox').checked).toBe(false);
		expect(rowB.querySelector('[data-ffc-origin]').textContent).toBe('—');
	});

	it('illuminates on keyboard focus and clears on blur', () => {
		roleSetup();
		const chip = document.querySelector('[data-ffc-role="ffc_recruitment_manager"]');
		chip.dispatchEvent(new Event('focus'));
		const rowC = document.querySelector('[data-ffc-cap-slug="cap_c"]');
		expect(rowC.classList.contains('is-lit')).toBe(true);
		chip.dispatchEvent(new Event('blur'));
		expect(rowC.classList.contains('is-lit')).toBe(false);
	});

	it('alerts and reverts the chip when the server reports failure', async () => {
		const fetchMock = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: false }) }));
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		roleSetup(fetchMock);

		const chip = document.querySelector('[data-ffc-role="ffc_recruitment_manager"]');
		chip.click();
		await new Promise((r) => setTimeout(r, 0));

		expect(alertSpy).toHaveBeenCalledWith('Err');
		// Chip is re-enabled but NOT marked on (assignment didn't take).
		expect(chip.disabled).toBe(false);
		expect(chip.classList.contains('is-on')).toBe(false);
	});

	it('alerts when the network request rejects', async () => {
		const fetchMock = vi.fn(() => Promise.reject(new Error('offline')));
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		roleSetup(fetchMock);

		const chip = document.querySelector('[data-ffc-role="ffc_recruitment_manager"]');
		chip.click();
		await new Promise((r) => setTimeout(r, 0));

		expect(alertSpy).toHaveBeenCalledWith('Err');
		expect(chip.disabled).toBe(false);
	});

	it('does not POST on chip click when ajaxUrl is absent', () => {
		const fetchMock = vi.fn();
		roleSetup(fetchMock);
		window.ffcUserPerms.ajaxUrl = '';
		window.ffcUserPermissionsInit();
		document.querySelector('[data-ffc-role="ffc_recruitment_manager"]').click();
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('preset "Grant all" skips role-locked (disabled) checkboxes', () => {
		window.ffcUserPerms = {
			roleCaps: {}, assigned: [], i18n: {},
		};
		// A panel with one disabled (role-locked) checkbox + one enabled.
		document.body.innerHTML = `
			<div class="ffc-cap-panel">
				<button type="button" data-ffc-preset="all">all</button>
				<section class="ffc-cap-group" data-ffc-group="g">
					<button class="ffc-cap-group-h"><span class="ffc-cap-count">0</span></button>
					<div class="ffc-cap-group-body">
						<div class="ffc-cap-row" data-ffc-cap-slug="locked" data-ffc-cap-name="locked">
							<input type="checkbox" class="ffc-cap-checkbox" disabled>
							<span class="ffc-cap-origin" data-ffc-origin>Role</span>
						</div>
						<div class="ffc-cap-row" data-ffc-cap-slug="free" data-ffc-cap-name="free">
							<input type="checkbox" class="ffc-cap-checkbox">
							<span class="ffc-cap-origin" data-ffc-origin>—</span>
						</div>
					</div>
				</section>
			</div>`;
		window.ffcUserPermissionsInit();
		document.querySelector('[data-ffc-preset="all"]').click();

		// The disabled (role-locked) box is left unchecked by the preset...
		expect(document.querySelector('[data-ffc-cap-slug="locked"] .ffc-cap-checkbox').checked).toBe(false);
		// ...while the free box is checked.
		expect(document.querySelector('[data-ffc-cap-slug="free"] .ffc-cap-checkbox').checked).toBe(true);
		delete window.ffcUserPerms;
	});
});

describe('copy slug timeout + edge cases', () => {
	it('restores the original glyph after the timeout', () => {
		vi.useFakeTimers();
		setup();
		const writeText = vi.fn();
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
		const btn = document.querySelector('.ffc-cap-copy');
		const original = btn.textContent;
		btn.click();
		expect(btn.textContent).toBe('✓');
		vi.advanceTimersByTime(900);
		expect(btn.textContent).toBe(original);
		vi.useRealTimers();
	});
});

describe('checkbox change unchecking', () => {
	it('removes from userGranted and sets origin to none when unchecked', () => {
		setup();
		const box = document.querySelector('[data-ffc-group="certificate"] .ffc-cap-checkbox');
		// Initially checked. Uncheck it.
		box.checked = false;
		box.dispatchEvent(new Event('change'));
		const count = document.querySelector('[data-ffc-group="certificate"] .ffc-cap-count');
		expect(count.textContent).toBe('0');
	});
});

describe('init guard / refreshRow no-checkbox', () => {
	it('returns when there is no .ffc-cap-panel', () => {
		document.body.innerHTML = '<div id="x"></div>';
		expect(() => window.ffcUserPermissionsInit()).not.toThrow();
	});

	it('recompute tolerates a cap row without a checkbox', () => {
		window.ffcUserPerms = {
			ajaxUrl: '/x', nonce: 'n', userId: 1,
			roleCaps: { r: ['cap_x'] }, assigned: ['r'],
			i18n: {},
		};
		document.body.innerHTML = `
			<div class="ffc-cap-panel">
				<div class="ffc-cap-roles"><div class="ffc-cap-role-chips">
					<button class="ffc-cap-role" data-ffc-role="r"><span class="ffc-cap-role-nm">R</span></button>
				</div></div>
				<section class="ffc-cap-group" data-ffc-group="g">
					<div class="ffc-cap-group-body">
						<div class="ffc-cap-row" data-ffc-cap-slug="cap_x" data-ffc-cap-name="cap_x"></div>
					</div>
				</section>
			</div>`;
		// init seeds + recompute may be triggered on assign; just ensure the
		// checkbox-less row is skipped without throwing.
		expect(() => window.ffcUserPermissionsInit()).not.toThrow();
		// Hovering the role chip triggers illuminate (safe) and clicking would
		// recompute → refreshRow hits the `if (!cb) return` guard.
		const fetchMock = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
		window.fetch = fetchMock; globalThis.fetch = fetchMock;
		expect(() => document.querySelector('[data-ffc-role="r"]').click()).not.toThrow();
		delete window.ffcUserPerms;
	});

	it('defers init to DOMContentLoaded while the document is loading', () => {
		const desc = Object.getOwnPropertyDescriptor(document, 'readyState');
		Object.defineProperty(document, 'readyState', { value: 'loading', configurable: true });
		try {
			document.body.innerHTML = panelMarkup();
			loadScript('assets/js/ffc-user-capabilities.js');
			// Before DOMContentLoaded the preset handler isn't wired.
			document.querySelector('[data-ffc-preset="all"]').click();
			const boxesBefore = [...document.querySelectorAll('.ffc-cap-checkbox')];
			expect(boxesBefore.every((b) => b.checked)).toBe(false);
			// Fire DOMContentLoaded → init wires the handlers.
			document.dispatchEvent(new Event('DOMContentLoaded'));
			document.querySelector('[data-ffc-preset="all"]').click();
			const boxesAfter = [...document.querySelectorAll('.ffc-cap-checkbox')];
			expect(boxesAfter.every((b) => b.checked)).toBe(true);
		} finally {
			if (desc) { Object.defineProperty(document, 'readyState', desc); }
		}
	});
});
