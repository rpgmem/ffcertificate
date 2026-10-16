// Tests for `assets/js/ffc-admin.js` — the admin hub script.
//
// Public surface: window.FFC.Admin.showNotification. The rest of the
// IIFE wires document-level handlers (#ffc_btn_generate_codes click,
// restriction-toggle checkboxes, dark-mode-aware menus, etc.). Tests
// exercise the public helper directly and drive the most-used document
// handlers via synthetic events.
//
// Sprint M of #175.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// FFC.request — the migration target — wraps jQuery.post() in a Promise.
// Mock $.post and return a chain whose .done / .fail callback the
// FFC.request internals invoke.
function postChain(spec) {
	const chain = { done: () => chain, fail: () => chain };
	if (spec && 'done' in spec) chain.done = (cb) => { cb(spec.done); return chain; };
	if (spec && spec.fail) chain.fail = (cb) => { cb(spec.fail === true ? undefined : spec.fail); return chain; };
	return chain;
}

// Microtask flush so .then/.catch reactions run before assertions.
function flush() { return Promise.resolve().then(() => Promise.resolve()); }


beforeAll(async () => {
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'test-nonce',
		strings: {
			dismiss: 'Dismiss',
			enterValidNumber: 'Please enter a valid number.',
			generating: 'Generating...',
			generatingTickets: 'Generating tickets...',
		},
	};
	window.ajaxurl = '/wp-admin/admin-ajax.php';
	document.body.innerHTML = '';
	// ffc-admin.js was migrated to FFC.request — load ffc-core.js so
	// window.FFC is defined before the subject IIFE evaluates.
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-admin.js');
	await new Promise((r) => setTimeout(r, 0));
});

beforeEach(() => {
	document.body.innerHTML = `
		<div id="wpbody-content"><div class="wrap"><h1>Admin</h1></div></div>
	`;
});

afterEach(() => {
	vi.restoreAllMocks();
});

// ----------------------------------------------------------------------
// FFC.Admin.showNotification
// ----------------------------------------------------------------------

describe('FFC.Admin.showNotification', () => {
	it('injects a notice with the message after <h1>', async () => {
		window.FFC.Admin.showNotification('All good', 'success');
		const notice = document.querySelector('.ffc-admin-notification');
		expect(notice).not.toBeNull();
		expect(notice.textContent).toContain('All good');
		expect(notice.classList.contains('notice-success')).toBe(true);
		// Sits right after the wrap > h1.
		const h1 = document.querySelector('.wrap > h1');
		expect(h1.nextElementSibling).toBe(notice);
	});

	it('replaces any previous notification rather than stacking', async () => {
		window.FFC.Admin.showNotification('first', 'info');
		window.FFC.Admin.showNotification('second', 'info');
		const notices = document.querySelectorAll('.ffc-admin-notification');
		expect(notices.length).toBe(1);
		expect(notices[0].textContent).toContain('second');
	});

	it('defaults to type=info when omitted', async () => {
		window.FFC.Admin.showNotification('plain message');
		expect(document.querySelector('.ffc-admin-notification.notice-info')).not.toBeNull();
	});

	it('applies the correct notice class for each type', async () => {
		const types = ['success', 'error', 'warning', 'info'];
		for (const t of types) {
			window.FFC.Admin.showNotification('m', t);
			expect(document.querySelector(`.ffc-admin-notification.notice-${t}`)).not.toBeNull();
		}
	});

	it('dismiss button removes the notice after fadeOut', async () => {
		window.FFC.Admin.showNotification('dismissable');
		const dismiss = document.querySelector('.ffc-admin-notification .notice-dismiss');
		dismiss.click();
		// fadeOut(200) + remove. Wait for the animation to settle.
		await new Promise((r) => setTimeout(r, 300));
		expect(document.querySelector('.ffc-admin-notification')).toBeNull();
	});

	it('falls back to #wpbody-content prepend when no .wrap > h1 exists', async () => {
		document.body.innerHTML = `<div id="wpbody-content"></div>`;
		window.FFC.Admin.showNotification('no h1');
		expect(document.querySelector('#wpbody-content .ffc-admin-notification')).not.toBeNull();
	});

	it('is the same function as FFC.Admin.notify', () => {
		expect(window.FFC.Admin.showNotification).toBe(window.FFC.Admin.notify);
	});
});

// ----------------------------------------------------------------------
// FFC.Admin.notify (#1843)
// ----------------------------------------------------------------------

describe('FFC.Admin.notify', () => {
	it('leads the paragraph with the type icon in its tone', () => {
		window.FFC.Admin.notify('Saved', 'success');
		const icon = document.querySelector('.ffc-admin-notification p > span:first-child');
		expect(icon.className).toContain('ffc-icon-success');
		expect(icon.className).toContain('ffc-icon-tone-success');
		expect(icon.getAttribute('aria-hidden')).toBe('true');
	});

	it('paints an error with the error icon in the danger tone', () => {
		window.FFC.Admin.notify('Broken', 'error');
		const icon = document.querySelector('.ffc-admin-notification .ffc-admin-notice-icon');
		expect(icon.classList.contains('ffc-icon-error')).toBe(true);
		expect(icon.classList.contains('ffc-icon-tone-danger')).toBe(true);
	});

	it('writes the message as text, never as markup', () => {
		window.FFC.Admin.notify('<img src=x onerror="window.__pwned=1">', 'error');
		const p = document.querySelector('.ffc-admin-notification p');
		expect(p.querySelector('img')).toBeNull();
		expect(p.textContent).toContain('<img src=x');
	});

	it('lets success and info close, and not a warning or an error', () => {
		window.FFC.Admin.notify('a', 'success');
		expect(document.querySelector('.ffc-admin-notification .notice-dismiss')).not.toBeNull();
		window.FFC.Admin.notify('b', 'info');
		expect(document.querySelector('.ffc-admin-notification.is-dismissible')).not.toBeNull();
		window.FFC.Admin.notify('c', 'warning');
		expect(document.querySelector('.ffc-admin-notification .notice-dismiss')).toBeNull();
		window.FFC.Admin.notify('d', 'error');
		expect(document.querySelector('.ffc-admin-notification.is-dismissible')).toBeNull();
	});

	it('announces errors as an alert and the rest as a status', () => {
		window.FFC.Admin.notify('x', 'error');
		expect(document.querySelector('.ffc-admin-notification').getAttribute('role')).toBe('alert');
		window.FFC.Admin.notify('y', 'success');
		expect(document.querySelector('.ffc-admin-notification').getAttribute('role')).toBe('status');
	});

	it('keeps an error until it is replaced unless a duration is passed', () => {
		vi.useFakeTimers();
		window.FFC.Admin.notify('stays', 'error');
		vi.advanceTimersByTime(10000);
		expect(document.querySelector('.ffc-admin-notification')).not.toBeNull();
		vi.useRealTimers();
	});

	it('reads an unknown type as info', () => {
		window.FFC.Admin.notify('m', 'bogus');
		expect(document.querySelector('.ffc-admin-notification.notice-info')).not.toBeNull();
	});
});

// ----------------------------------------------------------------------
// Generate codes button (#ffc_btn_generate_codes)
// ----------------------------------------------------------------------

describe('Generate codes — #ffc_btn_generate_codes click', () => {
	function setupGenerateForm(qty = '') {
		document.body.innerHTML += `
			<input id="ffc_qty_codes" value="${qty}" />
			<span id="ffc_gen_status"></span>
			<button id="ffc_btn_generate_codes">Generate</button>
			<textarea id="ffc_generated_list"></textarea>
		`;
	}

	it('shows an inline error when quantity is blank', async () => {
		setupGenerateForm('');
		const spy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));
		window.$('#ffc_btn_generate_codes').trigger('click');
		await flush();
		expect(spy).not.toHaveBeenCalled();
		expect(document.getElementById('ffc_gen_status').textContent).toBe('Please enter a valid number.');
	});

	it('shows an inline error when quantity is not a number', async () => {
		setupGenerateForm('abc');
		const spy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));
		window.$('#ffc_btn_generate_codes').trigger('click');
		await flush();
		expect(spy).not.toHaveBeenCalled();
		expect(document.getElementById('ffc_gen_status').textContent).toBe('Please enter a valid number.');
	});

	it('POSTs ffc_generate_codes with the quantity + nonce', async () => {
		setupGenerateForm('10');
		const spy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));
		window.$('#ffc_btn_generate_codes').trigger('click');
		await flush();
		expect(spy).toHaveBeenCalledOnce();
		// FFC.request → jQuery.post(url, payload).
		const [, payload] = spy.mock.calls[0];
		expect(payload.action).toBe('ffc_generate_codes');
		expect(payload.qty).toBe('10');
		expect(payload.nonce).toBe('test-nonce');
	});

	it("disables the button and shows 'Generating tickets...' status while submitting", async () => {
		setupGenerateForm('5');
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));
		window.$('#ffc_btn_generate_codes').trigger('click');
		await flush();
		expect(document.getElementById('ffc_btn_generate_codes').disabled).toBe(true);
		expect(document.getElementById('ffc_gen_status').textContent).toBe('Generating tickets...');
	});
});

// ----------------------------------------------------------------------
// Restriction-toggle checkboxes (#ffc_restriction_*)
// ----------------------------------------------------------------------

// Restriction toggles migrated post-#238 from per-handler slideUp/slideDown
// to the unified `.ffc-collapsed-target` system. The fixture now mirrors
// the production markup: each conditional <tr> carries
// `data-ffc-master="<id>"` and starts with `.ffc-collapsed` when off.
describe('Restriction toggles (post-#238)', () => {
	beforeEach(() => {
		// Fresh page-level script binding context — the generic
		// `.ffc-collapsed-target` initializer scans the DOM at IIFE run.
		document.body.innerHTML = `
			<input type="checkbox" id="ffc_restriction_password" />
			<input type="checkbox" id="ffc_restriction_allowlist" />
			<input type="checkbox" id="ffc_restriction_denylist" />
			<input type="checkbox" id="ffc_restriction_ticket" />
			<div id="ffc_password_field" class="ffc-collapsed-target ffc-collapsed" data-ffc-master="ffc_restriction_password"></div>
			<div id="ffc_allowlist_field" class="ffc-collapsed-target ffc-collapsed" data-ffc-master="ffc_restriction_allowlist"></div>
			<div id="ffc_denylist_field" class="ffc-collapsed-target ffc-collapsed" data-ffc-master="ffc_restriction_denylist"></div>
			<div id="ffc_ticket_field" class="ffc-collapsed-target ffc-collapsed" data-ffc-master="ffc_restriction_ticket"></div>
		`;
		loadScript('assets/js/ffc-admin.js');
	});

	it('checking #ffc_restriction_password removes .ffc-collapsed from #ffc_password_field', async () => {
		const cb = document.getElementById('ffc_restriction_password');
		cb.checked = true;
		window.$(cb).trigger('change');
		await flush();
		expect(document.getElementById('ffc_password_field').classList.contains('ffc-collapsed')).toBe(false);
	});

	it('unchecking #ffc_restriction_allowlist re-adds .ffc-collapsed to #ffc_allowlist_field', async () => {
		const cb = document.getElementById('ffc_restriction_allowlist');
		const field = document.getElementById('ffc_allowlist_field');
		// Start visible (uncollapsed) by checking the master first.
		cb.checked = true;
		window.$(cb).trigger('change');
		await flush();
		expect(field.classList.contains('ffc-collapsed')).toBe(false);

		cb.checked = false;
		window.$(cb).trigger('change');
		await flush();
		expect(field.classList.contains('ffc-collapsed')).toBe(true);
	});
});
