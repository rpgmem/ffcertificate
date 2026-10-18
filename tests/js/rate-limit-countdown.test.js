// RateLimit: the notice and countdown a refused submission shows (#1846).
//
// The notice used to go into `$('.ffc-form')`, a class no public form
// carries, so it never appeared on the certificate form. These run on the
// markup `Shortcodes::render_form()` prints, with no `.ffc-form` anywhere,
// and pin:
//   - the notice lands directly above the refused form, as a warning
//     `.ffc-notice` with the clock icon, role=status + aria-live=polite
//   - first paint shows the correct mm:ss synchronously (no "0:00" flicker)
//   - the countdown ticks in the notice and on the button
//   - at zero the notice goes and the button gets its original label back
//   - two forms on one page never share a notice, a timer or a button
//   - the message is text, never markup
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(async () => {
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'n',
		strings: { wait: 'Wait', send: 'Send', processing: 'Processing…', error: 'Error' },
	};
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-field-behaviours.js');
	loadScript('assets/js/ffc-frontend-helpers.js');
	loadScript('assets/js/ffc-frontend.js');
	await new Promise((r) => setTimeout(r, 0));
});

// What Shortcodes::render_form() prints, trimmed to what the path reads.
function certForm(id) {
	return `
		<div class="ffc-shortcode ffc-form-wrapper" id="ffc-form-${id}">
			<h2 class="ffc-form-title">Form ${id}</h2>
			<form class="ffc-submission-form" id="ffc-form-element-${id}" autocomplete="off">
				<input type="hidden" name="form_id" value="${id}">
				<div class="ffc-form-field"><input class="ffc-input" name="name" value="Ana"></div>
				<button type="submit" class="ffc-submit-btn">Submit</button>
			</form>
		</div>`;
}

const form = (id) => document.getElementById('ffc-form-element-' + id);
const notice = (id) => document.querySelector('#ffc-form-' + id + ' [data-ffc-notice="rate-limit"]');
const button = (id) => form(id).querySelector('button[type="submit"]');
const RateLimit = () => window.FFC.Frontend.RateLimit;

beforeEach(() => {
	document.body.innerHTML = certForm(1) + certForm(2);
	expect(document.querySelector('.ffc-form')).toBeNull();
});

afterEach(() => {
	RateLimit().enable();
	vi.restoreAllMocks();
	vi.useRealTimers();
});

describe('RateLimit.show on the certificate form', () => {
	it('puts a warning notice with the clock icon directly above the refused form', () => {
		RateLimit().show('Try later', 95, form(1));

		const n = notice(1);
		expect(n).not.toBeNull();
		expect(n.className).toBe('ffc-notice ffc-notice-warning');
		expect(n.querySelector('.ffc-notice-icon').className).toBe('ffc-notice-icon ffc-icon-clock');
		expect(n.getAttribute('role')).toBe('status');
		expect(n.getAttribute('aria-live')).toBe('polite');
		expect(n.nextElementSibling).toBe(form(1));
		expect(n.hasAttribute('style')).toBe(false);
		expect(n.querySelector('[style]')).toBeNull();
	});

	it('paints the correct mm:ss on first render, no "0:00" flicker', () => {
		RateLimit().show('Try later', 95, form(1));
		expect(notice(1).querySelector('.ffc-notice-text strong').textContent).toBe('1:35');
		expect(button(1).textContent).toBe('Wait (1:35)');
		expect(button(1).disabled).toBe(true);
	});

	it('writes the message as text, never as markup', () => {
		RateLimit().show('<img src=x onerror=alert(1)>', 60, form(1));
		const text = notice(1).querySelector('.ffc-notice-text');
		expect(text.querySelector('img')).toBeNull();
		expect(text.textContent).toBe('<img src=x onerror=alert(1)> 1:00');
	});

	it('ticks, then removes the notice and restores the original label', () => {
		vi.useFakeTimers();
		RateLimit().show('Wait', 3, form(1), 'Submit');

		vi.advanceTimersByTime(1000);
		expect(notice(1).querySelector('strong').textContent).toBe('0:02');
		expect(button(1).textContent).toBe('Wait (0:02)');
		vi.advanceTimersByTime(1000);
		expect(notice(1).querySelector('strong').textContent).toBe('0:01');
		vi.advanceTimersByTime(1000);

		expect(notice(1)).toBeNull();
		expect(button(1).disabled).toBe(false);
		expect(button(1).textContent).toBe('Submit');
		expect(RateLimit().blocked).toBe(false);
	});

	it('keeps the label the button had when no label is given', () => {
		vi.useFakeTimers();
		button(1).textContent = 'Get my certificate';
		RateLimit().show('Wait', 1, form(1));
		vi.advanceTimersByTime(1000);
		expect(button(1).textContent).toBe('Get my certificate');
	});

	it('two forms on one page do not interfere', () => {
		vi.useFakeTimers();
		RateLimit().show('First', 2, form(1), 'Submit');
		RateLimit().show('Second', 5, form(2), 'Submit');

		expect(notice(1).textContent).toContain('First');
		expect(notice(2).textContent).toContain('Second');
		expect(document.querySelectorAll('[data-ffc-notice="rate-limit"]')).toHaveLength(2);

		vi.advanceTimersByTime(2000);
		expect(notice(1)).toBeNull();
		expect(button(1).disabled).toBe(false);
		expect(notice(2).querySelector('strong').textContent).toBe('0:03');
		expect(button(2).disabled).toBe(true);
		expect(RateLimit().blocked).toBe(true);

		vi.advanceTimersByTime(3000);
		expect(notice(2)).toBeNull();
		expect(button(2).textContent).toBe('Submit');
		expect(RateLimit().blocked).toBe(false);
	});

	it('a second refusal on the same form replaces the first countdown', () => {
		vi.useFakeTimers();
		RateLimit().show('One', 2, form(1), 'Submit');
		RateLimit().show('Two', 10, form(1), 'Submit');

		expect(document.querySelectorAll('#ffc-form-1 [data-ffc-notice="rate-limit"]')).toHaveLength(1);
		vi.advanceTimersByTime(3000);
		expect(notice(1).textContent).toContain('Two');
		expect(notice(1).querySelector('strong').textContent).toBe('0:07');
		expect(button(1).disabled).toBe(true);
	});

	it('does nothing without a form', () => {
		RateLimit().show('Nowhere', 30);
		expect(document.querySelector('[data-ffc-notice="rate-limit"]')).toBeNull();
		expect(RateLimit().blocked).toBe(false);
	});
});

describe('a refused submission, end to end', () => {
	it('shows the notice on the form that was submitted and holds its button', async () => {
		vi.useFakeTimers();
		vi.spyOn(window.FFC, 'request').mockRejectedValue({
			fromServer: true,
			message: 'Too many attempts',
			data: { rate_limit: true, message: 'Too many attempts. Wait:', wait_seconds: 2 },
		});

		window.$(form(2)).trigger('submit');
		await vi.advanceTimersByTimeAsync(0);

		expect(notice(1)).toBeNull();
		expect(notice(2)).not.toBeNull();
		expect(notice(2).textContent).toBe('Too many attempts. Wait: 0:02');
		expect(button(2).disabled).toBe(true);
		expect(button(2).textContent).toBe('Wait (0:02)');
		expect(button(1).disabled).toBe(false);

		await vi.advanceTimersByTimeAsync(2000);
		expect(notice(2)).toBeNull();
		expect(button(2).disabled).toBe(false);
		expect(button(2).textContent).toBe('Submit');
	});
});
