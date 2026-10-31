// Members-only trees, keep-at-least-one and switch in the Join groups panel
// (#1918): what the panel draws from the payload's `trees`, `leave_locked`
// and `leave_all_keeps`, and the requests its buttons send.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { installDashboardFixtures, loadDashboardCore, loadPanel, flushPromises } from './dashboard-fixtures.js';

// What the dashboard's message region says: alerts left for it (#2246).
const flashText = () => (document.getElementById('ffc-dashboard-flash') || { textContent: '' }).textContent;
afterEach(() => { const el = document.getElementById('ffc-dashboard-flash'); if (el) el.remove(); });

beforeAll(() => {
	installDashboardFixtures();
	Object.assign(window.ffcDashboard.strings, {
		joinGroups: 'Join Groups',
		joinGroupsDesc: 'Select up to {max} groups.',
		joinGroup: 'Join',
		leaveGroup: 'Leave',
		switchGroup: 'Switch to this',
		switchConfirm: 'Switch',
		switchWhich: 'Replace which group with "%s"?',
		confirmSwitchGroup: 'Leave "%1$s" and join "%2$s"?',
		leaveLocked: 'Keep one group.',
		leaveAllKeptTitle: 'Some groups were kept',
		leaveAllKeptLine: 'You stay in "%1$s" because "%2$s" requires at least one group.',
		confirmLeaveAllGroups: 'Confirm leave all %d?',
		cancel: 'Cancel',
		error: 'Generic error',
	});
	window.ffcDashboard.restUrl = 'https://x.test/wp-json/ffc/v1/';
	window.ffcDashboard.nonce = 'tree-nonce';

	loadDashboardCore();
	loadPanel('audience-join');

	window.FFCDashboard.panels.profile = { state: { audience_groups: [] }, reload: vi.fn() };
});

beforeEach(() => {
	document.getElementById('ffc-dashboard').innerHTML =
		'<div id="ffc-audience-join-section"></div><button class="ffc-leave-all-groups-btn">Leave all groups</button>';
	window.FFCDashboard.panels.profile.state = { audience_groups: [{ id: 2 }] };
	window.FFCDashboard.panels.profile.reload = vi.fn();
});

afterEach(() => {
	vi.restoreAllMocks();
});

// Top-level 1 "Staff" (header) over 2 "Morning", 3 "Evening", 4 "Night".
function payload(opts) {
	const o = Object.assign({ member: [2], locked: 0, held: [{ id: 2, name: 'Morning' }], total: 1, keep: true, max: 3, joined: 1, keeps: [] }, opts);
	const child = (id, name) => {
		const node = { id, name, color: '', joinable: true, is_member: o.member.indexOf(id) !== -1, tree_id: 1 };
		if (o.locked === id) node.leave_locked = true;
		return node;
	};
	return {
		parents: [{ id: 1, name: 'Staff', color: '', children: [child(2, 'Morning'), child(3, 'Evening'), child(4, 'Night')] }],
		max_groups: o.max,
		joined_count: o.joined,
		trees: [{ id: 1, members_only: true, require_membership: o.keep, total: o.total, held: o.held }],
		leave_all_keeps: o.keeps,
	};
}

async function render(data) {
	vi.spyOn(window.$, 'ajax').mockImplementationOnce((opts) => {
		opts.success(data);
		return {};
	});
	window.FFCDashboard.audienceJoin.load();
	await flushPromises();
	vi.restoreAllMocks();
}

const row = (id) => document.querySelector('.ffc-audience-join-item[data-group-id="' + id + '"]');

describe('audience-join — keep at least one group', () => {
	it('disables the Leave button of the last kept group and says why', async () => {
		await render(payload({ locked: 2 }));

		const leave = row(2).querySelector('.ffc-audience-leave-btn');
		expect(leave.disabled).toBe(true);
		expect(leave.getAttribute('aria-describedby')).toBe('ffc-audience-locked-2');
		expect(document.getElementById('ffc-audience-locked-2').textContent).toBe('Keep one group.');
	});

	it('keeps Leave enabled when the group is not locked', async () => {
		await render(payload({ keep: false, locked: 0 }));

		expect(row(2).querySelector('.ffc-audience-leave-btn').disabled).toBe(false);
		expect(document.querySelector('.ffc-audience-locked-note')).toBeNull();
	});

	it('offers Join and Switch side by side under the limit', async () => {
		await render(payload({ locked: 2 }));

		expect(row(3).querySelector('.ffc-audience-join-btn').disabled).toBe(false);
		const sw = row(3).querySelector('.ffc-audience-switch-btn');
		expect(sw).not.toBeNull();
		expect(sw.classList.contains('ffc-btn-caution')).toBe(true);
		expect(sw.classList.contains('ffc-icon-switch')).toBe(true);
		expect(sw.textContent).toBe('Switch to this');
	});

	it('names the group Leave all keeps in the confirm', async () => {
		await render(payload({ locked: 2, keeps: [{ tree_id: 1, tree_name: 'Staff', group_id: 2, group_name: 'Morning' }] }));
		const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

		window.$('.ffc-leave-all-groups-btn').trigger('click');
		await flushPromises();

		expect(confirmSpy).toHaveBeenCalledWith('Confirm leave all 1?\n\nYou stay in "Morning" because "Staff" requires at least one group.');
	});

	it('after Leave all, shows a notice naming each kept group and its audience', async () => {
		await render(payload({ locked: 2 }));
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		const kept = [
			{ tree_id: 1, tree_name: 'Staff', group_id: 2, group_name: 'Morning' },
			{ tree_id: 9, tree_name: 'Clubs', group_id: 10, group_name: 'Chess' },
		];
		vi.spyOn(window.$, 'ajax').mockImplementationOnce((opts) => {
			expect(opts.url).toContain('/user/audience-group/leave-all');
			opts.success({ success: true, removed: 2, kept });
			return {};
		});
		// The profile reload renders the panel again.
		window.FFCDashboard.panels.profile.reload = vi.fn(() => render(payload({ locked: 2 })));

		window.$('.ffc-leave-all-groups-btn').trigger('click');
		await flushPromises();
		await flushPromises();

		const notice = document.querySelector('#ffc-audience-join-section .ffc-notice');
		expect(notice).not.toBeNull();
		expect(notice.classList.contains('ffc-notice-info')).toBe(true);
		expect(notice.querySelector('.ffc-notice-title').textContent).toBe('Some groups were kept');
		expect(Array.from(notice.querySelectorAll('.ffc-notice-text')).map((p) => p.textContent)).toEqual([
			'You stay in "Morning" because "Staff" requires at least one group.',
			'You stay in "Chess" because "Clubs" requires at least one group.',
		]);
		expect(flashText()).toBe('');

		// Shown once: the next render has no notice.
		await render(payload({ locked: 2 }));
		expect(document.querySelector('#ffc-audience-join-section .ffc-notice')).toBeNull();
	});

	it('shows no notice when Leave all kept nothing', async () => {
		await render(payload({ keep: false }));
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.spyOn(window.$, 'ajax').mockImplementationOnce((opts) => {
			opts.success({ success: true, removed: 1, kept: [] });
			return {};
		});
		window.FFCDashboard.panels.profile.reload = vi.fn(() => render(payload({ keep: false })));

		window.$('.ffc-leave-all-groups-btn').trigger('click');
		await flushPromises();
		await flushPromises();

		expect(document.querySelector('#ffc-audience-join-section .ffc-notice')).toBeNull();
	});

	it('runs Leave all as before when no tree blocks it', async () => {
		await render(payload({ keep: false }));
		const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

		window.$('.ffc-leave-all-groups-btn').trigger('click');
		await flushPromises();

		expect(confirmSpy).toHaveBeenCalledWith('Confirm leave all 1?');
	});
});

describe('audience-join — switch', () => {
	it('replaces Join with Switch at the limit', async () => {
		await render(payload({ keep: false, max: 1, joined: 1 }));

		expect(row(3).querySelector('.ffc-audience-join-btn')).toBeNull();
		expect(row(3).querySelector('.ffc-audience-switch-btn')).not.toBeNull();
	});

	it('keeps a disabled Join and no Switch when nothing of the tree is held', async () => {
		await render(payload({ keep: false, member: [], held: [], total: 0, max: 1, joined: 1 }));

		expect(row(3).querySelector('.ffc-audience-join-btn').disabled).toBe(true);
		expect(row(3).querySelector('.ffc-audience-switch-btn')).toBeNull();
	});

	it('with one group held: confirms and POSTs one switch request', async () => {
		await render(payload({ keep: false, max: 1, joined: 1 }));
		const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({});
			return {};
		});

		window.$(row(3).querySelector('.ffc-audience-switch-btn')).trigger('click');
		await flushPromises();

		expect(confirmSpy).toHaveBeenCalledWith('Leave "Morning" and join "Evening"?');
		const opts = ajaxSpy.mock.calls[0][0];
		expect(opts.url).toContain('/user/audience-group/switch');
		expect(JSON.parse(opts.data)).toEqual({ from_group_id: 2, group_id: 3 });
		expect(window.FFCDashboard.panels.profile.reload).toHaveBeenCalled();
	});

	it('with several groups held: asks which one to replace, then switches', async () => {
		await render(payload({
			keep: false, max: 2, joined: 2, member: [2, 4],
			held: [{ id: 2, name: 'Morning' }, { id: 4, name: 'Night' }], total: 2,
		}));
		const confirmSpy = vi.spyOn(window, 'confirm');
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({});
			return {};
		});

		window.$(row(3).querySelector('.ffc-audience-switch-btn')).trigger('click');
		const picker = document.querySelector('.ffc-audience-switch-picker');
		expect(confirmSpy).not.toHaveBeenCalled();
		expect(picker.querySelector('label').textContent).toBe('Replace which group with "Evening"?');
		expect(Array.from(picker.querySelectorAll('option')).map((o) => o.textContent)).toEqual(['Morning', 'Night']);

		window.$(picker).find('select').val('4');
		window.$(picker.querySelector('.ffc-audience-switch-confirm')).trigger('click');
		await flushPromises();

		expect(JSON.parse(ajaxSpy.mock.calls[0][0].data)).toEqual({ from_group_id: 4, group_id: 3 });
	});

	it('Cancel closes the picker without a request', async () => {
		await render(payload({
			keep: false, max: 2, joined: 2, member: [2, 4],
			held: [{ id: 2, name: 'Morning' }, { id: 4, name: 'Night' }], total: 2,
		}));
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		window.$(row(3).querySelector('.ffc-audience-switch-btn')).trigger('click');
		window.$('.ffc-audience-switch-cancel').trigger('click');

		expect(document.querySelector('.ffc-audience-switch-picker')).toBeNull();
		expect(ajaxSpy).not.toHaveBeenCalled();
	});

	it('alerts the server refusal', async () => {
		await render(payload({ keep: false, max: 1, joined: 1 }));
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error({ responseJSON: { message: 'Not the same audience' } });
			return {};
		});

		window.$(row(3).querySelector('.ffc-audience-switch-btn')).trigger('click');
		await flushPromises();

		expect(flashText()).toBe('Not the same audience');
		expect(row(3).querySelector('.ffc-audience-switch-btn').disabled).toBe(false);
	});
});
