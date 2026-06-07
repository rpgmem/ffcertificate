// 6.6.4 Sprint 6 — RateLimit.show countdown polish.
//
// Already had a live mm:ss countdown but suffered a "0:00" flicker
// on first paint because the prepend was hardcoded. This pin
// asserts:
//   - First paint shows the correct mm:ss synchronously (no flicker)
//   - Subsequent ticks decrement correctly
//   - role="status" + aria-live="polite" land on the notice
//   - User-supplied `message` is HTML-escaped (XSS regression guard)
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-frontend-helpers.js');
});

beforeEach(() => {
	document.body.innerHTML = '<form class="ffc-form"><button type="submit">Send</button></form>';
	window.ffc_ajax = {
		strings: { wait: 'Wait', send: 'Send' },
	};
});

afterEach(() => {
	vi.restoreAllMocks();
	vi.useRealTimers();
});

describe('RateLimit.show initial render', () => {
	it('paints the correct mm:ss on first render, no "0:00" flicker', () => {
		window.FFC.Frontend.RateLimit.show('Try later', 95);

		expect(document.getElementById('ffc-countdown').textContent).toBe('1:35');
		expect(document.getElementById('ffc-countdown-btn').textContent).toBe('1:35');
	});

	it('paints "0:30" for a 30-second wait', () => {
		window.FFC.Frontend.RateLimit.show('Try later', 30);
		expect(document.getElementById('ffc-countdown').textContent).toBe('0:30');
	});

	it('adds role=status + aria-live=polite to the notice for screen readers', () => {
		window.FFC.Frontend.RateLimit.show('Try later', 60);
		const notice = document.querySelector('.ffc-rate-limit-notice');
		expect(notice.getAttribute('role')).toBe('status');
		expect(notice.getAttribute('aria-live')).toBe('polite');
	});

	it('HTML-escapes the message argument (XSS regression guard)', () => {
		window.FFC.Frontend.RateLimit.show('<img src=x onerror=alert(1)>', 60);
		// The literal text is rendered, not the image element.
		const msgEl = document.querySelector('.ffc-rate-limit-message');
		expect(msgEl.querySelector('img')).toBeNull();
		expect(msgEl.textContent).toContain('<img src=x onerror=alert(1)>');
	});

	it('disables the submit button and shows the countdown next to "Wait"', () => {
		window.FFC.Frontend.RateLimit.show('Try later', 60);
		const btn = document.querySelector('button[type="submit"]');
		expect(btn.disabled).toBe(true);
		expect(btn.textContent).toContain('Wait');
		expect(btn.textContent).toContain('1:00');
	});
});

describe('RateLimit countdown ticking', () => {
	it('decrements every second and re-enables the form on zero', () => {
		vi.useFakeTimers();
		window.FFC.Frontend.RateLimit.show('Wait', 3);

		// Initial render: 0:03 (synchronous from show()).
		expect(document.getElementById('ffc-countdown').textContent).toBe('0:03');

		// +1s → first tick → 0:02
		vi.advanceTimersByTime(1000);
		expect(document.getElementById('ffc-countdown').textContent).toBe('0:02');

		// +1s → 0:01
		vi.advanceTimersByTime(1000);
		expect(document.getElementById('ffc-countdown').textContent).toBe('0:01');

		// +1s → enable() fires → notice removed, button re-enabled
		vi.advanceTimersByTime(1000);
		expect(document.querySelector('.ffc-rate-limit-notice')).toBeNull();
		expect(document.querySelector('button[type="submit"]').disabled).toBe(false);
	});
});
