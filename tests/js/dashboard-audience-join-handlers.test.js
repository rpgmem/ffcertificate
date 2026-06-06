// Deep coverage for the join/leave/leave-all action handlers in
// ffc-user-dashboard-audience-join.js. The shallow load() test lives
// in dashboard-audience-join.test.js; this file completes the picture
// by driving the document-level click delegates that mutate state via
// REST.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { installDashboardFixtures, loadDashboardCore, loadPanel, flushPromises } from './dashboard-fixtures.js';

beforeAll(() => {
	installDashboardFixtures();
	Object.assign(window.ffcDashboard.strings, {
		joinGroup: 'Join',
		leaveGroup: 'Leave',
		leaveAllGroups: 'Leave all groups',
		saving: 'Saving…',
		confirmLeaveGroup: 'Leave?',
		confirmLeaveAllGroups: 'Confirm leave all %d?',
		error: 'Generic error',
	});
	window.ffcDashboard.restUrl = 'https://x.test/wp-json/ffc/v1/';
	window.ffcDashboard.nonce = 'aj-nonce';

	loadDashboardCore();
	loadPanel('audience-join');

	// Stub the profile panel so reload() can be observed.
	window.FFCDashboard.panels.profile = {
		state: { audience_groups: [] },
		reload: vi.fn(),
	};
});

beforeEach(() => {
	document.body.innerHTML = `
		<div>
			<button class="ffc-audience-join-btn" data-id="7">Join</button>
			<button class="ffc-audience-leave-btn" data-id="9">Leave</button>
			<button class="ffc-leave-all-groups-btn">Leave all</button>
		</div>
	`;
	// Reset profile state for leaveAll.
	window.FFCDashboard.panels.profile.state = { audience_groups: [{ id: 1 }, { id: 2 }] };
	window.FFCDashboard.panels.profile.reload = vi.fn();
});

afterEach(() => {
	vi.restoreAllMocks();
});

// ----------------------------------------------------------------------
// joinGroup
// ----------------------------------------------------------------------

describe('audience-join — joinGroup', () => {
	it('POSTs to /user/audience-group/join with the group_id payload', async () => {
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({});
			return {};
		});

		window.$('.ffc-audience-join-btn[data-id="7"]').trigger('click');
		await flushPromises();

		expect(ajaxSpy).toHaveBeenCalled();
		const opts = ajaxSpy.mock.calls[0][0];
		expect(opts.url).toBe('https://x.test/wp-json/ffc/v1/user/audience-group/join');
		expect(opts.method).toBe('POST');
		expect(JSON.parse(opts.data)).toEqual({ group_id: 7 });
		// On success, the profile panel reloads.
		expect(window.FFCDashboard.panels.profile.reload).toHaveBeenCalled();
	});

	it('appends viewAsUserId query string when impersonating', async () => {
		window.ffcDashboard.viewAsUserId = 42;
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		window.$('.ffc-audience-join-btn[data-id="7"]').trigger('click');
		await flushPromises();

		expect(ajaxSpy.mock.calls[0][0].url).toContain('?viewAsUserId=42');
		delete window.ffcDashboard.viewAsUserId;
	});

	it('sets X-WP-Nonce on the request', async () => {
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			const xhr = { setRequestHeader: vi.fn() };
			opts.beforeSend(xhr);
			expect(xhr.setRequestHeader).toHaveBeenCalledWith('X-WP-Nonce', 'aj-nonce');
			return {};
		});

		window.$('.ffc-audience-join-btn[data-id="7"]').trigger('click');
		await flushPromises();
		expect(ajaxSpy).toHaveBeenCalled();
	});

	it('on error: alerts the server message and re-enables the button', async () => {
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error({ responseJSON: { message: 'Quota exceeded' } });
			return {};
		});
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		window.$('.ffc-audience-join-btn[data-id="7"]').trigger('click');
		await flushPromises();

		expect(alertSpy).toHaveBeenCalledWith('Quota exceeded');
		const $btn = window.$('.ffc-audience-join-btn[data-id="7"]');
		expect($btn.prop('disabled')).toBe(false);
		expect($btn.text()).toBe('Join');
	});

	it('on error without a responseJSON.message: falls back to the localised error', async () => {
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error({});
			return {};
		});
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		window.$('.ffc-audience-join-btn[data-id="7"]').trigger('click');
		await flushPromises();

		expect(alertSpy).toHaveBeenCalledWith('Generic error');
	});
});

// ----------------------------------------------------------------------
// leaveGroup
// ----------------------------------------------------------------------

describe('audience-join — leaveGroup', () => {
	it('bails when the user declines the confirm', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(false);
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		window.$('.ffc-audience-leave-btn[data-id="9"]').trigger('click');
		await flushPromises();

		expect(ajaxSpy).not.toHaveBeenCalled();
	});

	it('POSTs to /user/audience-group/leave and reloads the profile panel', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({});
			return {};
		});

		window.$('.ffc-audience-leave-btn[data-id="9"]').trigger('click');
		await flushPromises();

		expect(JSON.parse(ajaxSpy.mock.calls[0][0].data)).toEqual({ group_id: 9 });
		expect(window.FFCDashboard.panels.profile.reload).toHaveBeenCalled();
	});

	it('on error: alerts the server message and re-enables the button', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error({ responseJSON: { message: 'Not a member' } });
			return {};
		});
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		window.$('.ffc-audience-leave-btn[data-id="9"]').trigger('click');
		await flushPromises();

		expect(alertSpy).toHaveBeenCalledWith('Not a member');
		const $btn = window.$('.ffc-audience-leave-btn[data-id="9"]');
		expect($btn.prop('disabled')).toBe(false);
		expect($btn.text()).toBe('Leave');
	});
});

// ----------------------------------------------------------------------
// leaveAllGroups
// ----------------------------------------------------------------------

describe('audience-join — leaveAllGroups', () => {
	it('bails when there are no groups to leave', async () => {
		window.FFCDashboard.panels.profile.state.audience_groups = [];
		const confirmSpy = vi.spyOn(window, 'confirm');
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		window.$('.ffc-leave-all-groups-btn').trigger('click');
		await flushPromises();

		expect(confirmSpy).not.toHaveBeenCalled();
		expect(ajaxSpy).not.toHaveBeenCalled();
	});

	it('asks for confirmation, substituting %d with the group count', async () => {
		const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

		window.$('.ffc-leave-all-groups-btn').trigger('click');
		await flushPromises();

		expect(confirmSpy).toHaveBeenCalledWith('Confirm leave all 2?');
	});

	it('on confirm + success: POSTs leave-all and reloads the profile panel', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({});
			return {};
		});

		window.$('.ffc-leave-all-groups-btn').trigger('click');
		await flushPromises();

		const opts = ajaxSpy.mock.calls[0][0];
		expect(opts.url).toContain('/user/audience-group/leave-all');
		expect(opts.method).toBe('POST');
		expect(JSON.parse(opts.data)).toEqual({});
		expect(window.FFCDashboard.panels.profile.reload).toHaveBeenCalled();
	});

	it('on error: alerts the message and re-enables the button', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error({ responseJSON: { message: 'Server down' } });
			return {};
		});
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		window.$('.ffc-leave-all-groups-btn').trigger('click');
		await flushPromises();

		expect(alertSpy).toHaveBeenCalledWith('Server down');
		const $btn = window.$('.ffc-leave-all-groups-btn');
		expect($btn.prop('disabled')).toBe(false);
		expect($btn.text()).toBe('Leave all groups');
	});
});
