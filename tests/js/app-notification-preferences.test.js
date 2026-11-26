/**
 * The Notifications section of the app's Preferences (site-pwa#66): a flipped
 * toggle saves both preferences through the profile route.
 */
import { describe, it, expect, beforeAll, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

beforeAll(() => {
	window.ffcAppNotificationPreferences = { rest: '/wp-json/ffc/v1/user/profile', nonce: 'n', strings: { saved: 'Saved!', failed: 'Failed!' } };
	loadScript('assets/js/ffc-app-notification-preferences.js');
});

beforeEach(() => {
	document.body.innerHTML = `
		<div class="ffc-app-notification-preferences">
			<label><input type="checkbox" class="ffc-app-notification-toggle" data-key="notify_appointment_reminder" checked></label>
			<label><input type="checkbox" class="ffc-app-notification-toggle" data-key="notify_date_messages" checked></label>
			<label><input type="checkbox" class="ffc-app-notification-toggle" data-key="notify_date_announcements"></label>
			<span class="ffc-app-notification-status ffc-status" hidden></span>
		</div>
		<input type="checkbox" class="ffc-app-notification-toggle" data-key="elsewhere">`;
});

function change(input) {
	input.dispatchEvent(new Event('change', { bubbles: true }));
}

describe('app notification preferences', () => {
	it('saves every toggle drawn through the profile route and says so', async () => {
		const rest = vi.fn(() => Promise.resolve({}));
		window.FFC = { rest };

		const first = document.querySelector('[data-key="notify_date_messages"]');
		first.checked = false;
		change(first);
		await flush();

		expect(rest).toHaveBeenCalledWith('/wp-json/ffc/v1/user/profile', {
			method: 'PUT',
			data: { preferences: { notify_appointment_reminder: true, notify_date_messages: false, notify_date_announcements: false } },
			nonce: 'n',
		});
		const status = document.querySelector('.ffc-app-notification-status');
		expect(status.hidden).toBe(false);
		expect(status.textContent).toBe('Saved!');
		expect(status.classList.contains('is-success')).toBe(true);
	});

	it('says when the save failed', async () => {
		window.FFC = { rest: vi.fn(() => Promise.reject(new Error('x'))) };

		change(document.querySelector('[data-key="notify_date_announcements"]'));
		await flush();

		const status = document.querySelector('.ffc-app-notification-status');
		expect(status.textContent).toBe('Failed!');
		expect(status.classList.contains('is-error')).toBe(true);
	});

	it('ignores a toggle outside the section', async () => {
		const rest = vi.fn(() => Promise.resolve({}));
		window.FFC = { rest };

		change(document.querySelector('[data-key="elsewhere"]'));
		await flush();

		expect(rest).not.toHaveBeenCalled();
	});
});
