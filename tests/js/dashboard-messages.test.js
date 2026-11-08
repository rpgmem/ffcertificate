// Tests for the Messages panel (assets/js/ffc-user-dashboard-messages.js, #2503).
//
// The panel draws two groups, each paged three at a time by the core's
// section pager, opens a message in place, and keeps the two date-message
// preferences beside the list.
import { describe, it, expect, beforeAll, beforeEach, vi } from 'vitest';
import { installDashboardFixtures, loadDashboardCore, loadPanel, flushPromises } from './dashboard-fixtures.js';

beforeAll(() => {
	installDashboardFixtures();
	window.ffcDashboard.restUrl = '/wp-json/ffc/v1/';
	const dash = document.getElementById('ffc-dashboard');
	const tab = document.createElement('a');
	tab.className = 'ffc-tab';
	tab.id = 'ffc-tab-messages';
	tab.setAttribute('data-tab', 'messages');
	dash.querySelector('.ffc-tabs').appendChild(tab);
	const el = document.createElement('div');
	el.id = 'ffc-tabpanel-messages';
	el.className = 'ffc-tab-content';
	dash.appendChild(el);
	loadDashboardCore();
	loadPanel('messages');
	window.FFCDashboard.panels.messages.bindEvents();
});

const panel = () => window.FFCDashboard.panels.messages;

function message(n, kind = 'announcement') {
	return { key: `${n}-once`, kind, subject: `Subject ${n}`, body: `<p>Body ${n}</p>`, excerpt: `Body ${n}`, sent: '05/11/2026', sent_at: 1000 - n };
}

function state(current, earlier, preferences = {}) {
	return { current, earlier, noticeDays: 7, preferences };
}

beforeEach(() => {
	const p = panel();
	p.state = null;
	p.sectionPages = {};
	p.openKeys = {};
	p.pendingOpen = null;
	document.getElementById('ffc-tabpanel-messages').innerHTML = '';
});

describe('Messages panel', () => {
	it('draws the current and earlier groups, three items a page each', () => {
		const earlier = [1, 2, 3, 4].map((n) => message(n, 'dated'));
		panel().state = state([message(9)], earlier);
		panel().render(panel().state);

		const sections = document.querySelectorAll('#ffc-tabpanel-messages .ffc-messages-section');
		expect(Array.from(sections).map((s) => s.getAttribute('data-section'))).toEqual(['messagesCurrent', 'messagesEarlier']);
		expect(sections[0].querySelectorAll('.ffc-message-item')).toHaveLength(1);
		expect(sections[1].querySelectorAll('.ffc-message-item')).toHaveLength(3);
		expect(sections[1].querySelector('.ffc-section-pager')).not.toBeNull();
		expect(sections[0].querySelector('.ffc-section-pager')).toBeNull();
		expect(sections[1].querySelector('.ffc-message-meta').textContent).toContain('Also sent by e-mail');
	});

	it('opens a message in place with an icon and closes it again', () => {
		panel().state = state([message(1)], []);
		panel().render(panel().state);

		let toggle = document.querySelector('.ffc-message-toggle');
		expect(toggle.getAttribute('aria-expanded')).toBe('false');
		expect(toggle.classList.contains('ffc-icon-chevron-down')).toBe(true);
		expect(document.querySelector('.ffc-message-body').hidden).toBe(true);

		toggle.click();
		toggle = document.querySelector('.ffc-message-toggle');
		expect(toggle.getAttribute('aria-expanded')).toBe('true');
		expect(toggle.classList.contains('ffc-icon-chevron-up')).toBe(true);
		expect(document.querySelector('.ffc-message-body').hidden).toBe(false);
		expect(document.querySelector('.ffc-message-body').innerHTML).toBe('<p>Body 1</p>');

		toggle.click();
		expect(document.querySelector('.ffc-message-body').hidden).toBe(true);
	});

	it('says when nothing was sent, and still offers the preferences', () => {
		panel().state = state([], []);
		panel().render(panel().state);

		expect(document.querySelector('.ffc-messages-empty')).not.toBeNull();
		expect(document.querySelectorAll('.ffc-messages-section')).toHaveLength(0);
		const keys = Array.from(document.querySelectorAll('.ffc-message-pref-toggle')).map((el) => el.getAttribute('data-key'));
		expect(keys).toEqual(['notify_date_messages', 'notify_date_announcements']);
	});

	it('draws each preference as stored, on unless turned off', () => {
		panel().state = state([], [], { notify_date_announcements: false });
		panel().render(panel().state);

		const toggles = document.querySelectorAll('.ffc-message-pref-toggle');
		expect(toggles[0].checked).toBe(true);
		expect(toggles[1].checked).toBe(false);
	});

	it('saves both preferences through the profile endpoint', async () => {
		const spy = vi.spyOn(window.jQuery, 'ajax').mockImplementation((opts) => {
			opts.success && opts.success({ success: true });
			return { abort() {} };
		});
		panel().state = state([], []);
		panel().render(panel().state);

		const off = document.querySelectorAll('.ffc-message-pref-toggle')[0];
		off.checked = false;
		window.jQuery(off).trigger('change');
		await flushPromises();

		const call = spy.mock.calls.find((c) => String(c[0].url).includes('user/profile'));
		expect(call).toBeDefined();
		expect(call[0].type || call[0].method).toBe('PUT');
		expect(JSON.parse(call[0].data)).toEqual({ preferences: { notify_date_messages: false, notify_date_announcements: true } });
		spy.mockRestore();
	});

	it('opens the message a notice points at, on the page that holds it', () => {
		const earlier = [1, 2, 3, 4].map((n) => message(n, 'dated'));
		panel().state = state([], earlier);
		panel().pendingOpen = '4-once';
		panel().openPending();
		panel().render(panel().state);

		expect(panel().sectionPages.messagesEarlier).toBe(2);
		const open = document.querySelector('.ffc-message-item.is-open');
		expect(open).not.toBeNull();
		expect(open.getAttribute('data-message')).toBe('4-once');
	});
});
