// Tests for the joinable-groups sub-panel
// (assets/js/ffc-user-dashboard-audience-join.js).
//
// Not a full panel — exposes only `FFCDashboard.audienceJoin.load()`,
// which fetches via AJAX and renders into `#ffc-audience-join-section`.
// We mock `$.ajax` to feed deterministic responses and assert against
// the rendered DOM.
//
// Sprint B of #168.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { installDashboardFixtures, loadDashboardCore, loadPanel } from './dashboard-fixtures.js';

beforeAll(() => {
	installDashboardFixtures();
	Object.assign(window.ffcDashboard.strings, {
		joinGroups: 'Join Groups',
		joinGroupsDesc: 'Select up to {max} groups.',
		joinGroup: 'Join',
		leaveGroup: 'Leave',
	});
	window.ffcDashboard.restUrl = 'https://x.test/wp-json/ffc/v1/';
	window.ffcDashboard.nonce = 'test-nonce';

	// Inject the section the load() target writes into.
	document.getElementById('ffc-dashboard').insertAdjacentHTML(
		'beforeend',
		'<div id="ffc-audience-join-section"></div>'
	);

	loadDashboardCore();
	loadPanel('audience-join');
});

beforeEach(() => {
	document.getElementById('ffc-audience-join-section').innerHTML = '';
});

afterEach(() => {
	vi.restoreAllMocks();
});

function mockAjaxSuccess(data) {
	return vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
		if (opts.success) opts.success(data);
		return {};
	});
}

const audienceJoin = () => window.FFCDashboard.audienceJoin;

describe('FFCDashboard.audienceJoin.load', () => {
	it('empties the section when parents array is empty', () => {
		mockAjaxSuccess({ parents: [], max_groups: 3, joined_count: 0 });
		audienceJoin().load();
		expect(document.querySelector('#ffc-audience-join-section').innerHTML).toBe('');
	});

	it('renders a heading + description when parents have items', () => {
		mockAjaxSuccess({
			parents: [{ id: 1, name: 'Adults', color: '#aa0000', children: [], is_member: false }],
			max_groups: 5,
			joined_count: 0,
		});
		audienceJoin().load();
		const section = document.querySelector('#ffc-audience-join-section');
		expect(section.querySelector('h3').textContent).toBe('Join Groups');
		expect(section.querySelector('.ffc-audience-limit-msg').textContent).toContain('5'); // {max} replaced
	});

	it('renders a Join button on a non-member leaf group', () => {
		mockAjaxSuccess({
			parents: [{ id: 42, name: 'Beta', color: '#00aa00', children: [], is_member: false }],
			max_groups: 3,
			joined_count: 0,
		});
		audienceJoin().load();
		const btn = document.querySelector('#ffc-audience-join-section .ffc-audience-join-btn');
		expect(btn).not.toBeNull();
		expect(btn.getAttribute('data-id')).toBe('42');
		expect(btn.disabled).toBe(false);
	});

	it('renders a Leave button on a member leaf group', () => {
		mockAjaxSuccess({
			parents: [{ id: 7, name: 'In', color: '#0000aa', children: [], is_member: true }],
			max_groups: 3,
			joined_count: 1,
		});
		audienceJoin().load();
		const btn = document.querySelector('#ffc-audience-join-section .ffc-audience-leave-btn');
		expect(btn).not.toBeNull();
		expect(btn.getAttribute('data-id')).toBe('7');
	});

	it('disables the Join button when joined_count >= max_groups', () => {
		mockAjaxSuccess({
			parents: [{ id: 9, name: 'Group', color: null, children: [], is_member: false }],
			max_groups: 1,
			joined_count: 1,
		});
		audienceJoin().load();
		const btn = document.querySelector('#ffc-audience-join-section .ffc-audience-join-btn');
		expect(btn.disabled).toBe(true);
	});

	it('renders a parent accordion when the parent has children', () => {
		mockAjaxSuccess({
			parents: [{
				id: 1, name: 'Parent', color: '#000',
				children: [
					{ id: 2, name: 'Child A', color: null, children: [], is_member: false },
					{ id: 3, name: 'Child B', color: null, children: [], is_member: true },
				],
			}],
			max_groups: 5,
			joined_count: 1,
		});
		audienceJoin().load();
		const section = document.querySelector('#ffc-audience-join-section');
		expect(section.querySelector('.ffc-audience-parent-header')).not.toBeNull();
		// One join, one leave (child A non-member, child B member).
		expect(section.querySelectorAll('.ffc-audience-join-btn').length).toBe(1);
		expect(section.querySelectorAll('.ffc-audience-leave-btn').length).toBe(1);
	});

	it('empties the section when AJAX errors out', () => {
		// Pre-populate so we can prove the error branch clears it.
		document.querySelector('#ffc-audience-join-section').innerHTML = '<p>old</p>';
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			if (opts.error) opts.error({});
			return {};
		});
		audienceJoin().load();
		expect(document.querySelector('#ffc-audience-join-section').innerHTML).toBe('');
	});

	it('is a no-op when the section element is missing from the DOM', () => {
		// Remove the section entirely.
		document.querySelector('#ffc-audience-join-section').remove();
		const ajaxSpy = vi.spyOn(window.$, 'ajax');
		audienceJoin().load();
		expect(ajaxSpy).not.toHaveBeenCalled();
		// Re-add for subsequent tests in same file (none after).
		document.getElementById('ffc-dashboard').insertAdjacentHTML(
			'beforeend',
			'<div id="ffc-audience-join-section"></div>'
		);
	});
});
