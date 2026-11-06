// The public CSV screen's operator actions take their own challenge
// (#2341): the info answer carries a security block bound to the actions'
// context, each dialog mounts a copy, the request sends the dialog's fields
// instead of the download's, and a refusal's fresh challenge reaches the
// next dialog.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function postChain(spec) {
	const chain = { done: () => chain, fail: () => chain };
	if (spec && 'done' in spec) chain.done = (cb) => { cb(spec.done); return chain; };
	return chain;
}

function flush() { return Promise.resolve().then(() => Promise.resolve()); }

function securityBlock(context, question, hash) {
	return '<div class="ffc-security-container" data-ffc-captcha-context="' + context + '">'
		+ '<input type="text" name="ffc_hp_trap" value="">'
		+ '<input type="hidden" name="ffc_hp_ts" value="stamp-' + context + '">'
		+ '<div class="ffc-captcha-row"><label><span class="ffc-captcha-label-text">' + question + '</span></label>'
		+ '<input type="number" name="ffc_captcha_ans" value="">'
		+ '<input type="hidden" name="ffc_captcha_hash" value="' + hash + '"></div>'
		+ '</div>';
}

function info() {
	return {
		form_title: 'My Form',
		submission_count: 0,
		restrictions: {},
		datetime: { has_dates: false, has_times: false },
		geolocation: { enabled: false },
		quiz: null,
		csv: { count: 0, limit: 100 },
		status: {
			has_end_date: true,
			can_preview_cert: false,
			can_download: false,
			can_open_early: true,
			start_date_formatted: '2026-12-31 23:00',
		},
		action_security: securityBlock('public_csv_action', 'What is 2 + 3?', 'ACT'),
	};
}

async function reachInfoScreen() {
	document.body.innerHTML = '<div class="ffc-public-csv-download">'
		+ '<div class="ffc-verification-header">header</div>'
		+ '<form>'
		+ '<input type="text" name="form_id" value="42">'
		+ '<input type="text" name="hash" value="abc">'
		+ '<input type="hidden" name="_ffc_pcd_nonce" value="n">'
		+ securityBlock('public_csv', 'What is 1 + 1?', 'DL')
		+ '<button type="submit" class="ffc-submit-btn">Info</button>'
		+ '</form></div>';
	window.ffc_ajax = { ajax_url: '/x', nonce: '', strings: {} };
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-field-behaviours.js');
	loadScript('assets/js/ffc-frontend-helpers.js');
	loadScript('assets/js/ffc-csv-download.js');
	loadScript('assets/js/ffc-csv-info-screen.js');
	loadScript('assets/js/ffc-csv-open-early.js');
	loadScript('assets/js/ffc-csv-extend-end.js');
	loadScript('assets/js/ffc-csv-schedule-exception.js');
	await new Promise((r) => setTimeout(r, 0));
	const postSpy = vi.spyOn(window.$, 'post');
	postSpy.mockImplementationOnce(() => postChain({ done: { success: true, data: info() } }));
	window.$('.ffc-public-csv-download form').trigger('submit');
	await flush();
	return postSpy;
}

function lastPayload(postSpy) {
	const calls = postSpy.mock.calls;
	return new URLSearchParams(calls[calls.length - 1][1]);
}

beforeEach(() => {
	delete window.FFC;
	window.ffc_csv_download = { ajax_url: '/x', min_display_ms: 1, strings: {} };
	window.$.fx.off = true;
});

afterEach(() => {
	vi.restoreAllMocks();
	document.body.innerHTML = '';
});

describe('operator actions — their own challenge', () => {
	it('mounts the actions\' block in the dialog', async () => {
		await reachInfoScreen();
		window.$('.ffc-btn-open-early').trigger('click');
		const $block = window.$('.ffc-csv-action-security .ffc-security-container');
		expect($block.length).toBe(1);
		expect($block.attr('data-ffc-captcha-context')).toBe('public_csv_action');
		expect($block.find('.ffc-captcha-label-text').text()).toBe('What is 2 + 3?');
	});

	it('sends the dialog\'s fields and not the download\'s', async () => {
		const postSpy = await reachInfoScreen();
		vi.spyOn(window, 'alert').mockImplementation(() => {});
		postSpy.mockImplementation(() => postChain({ done: { success: false, data: { message: 'no' } } }));

		window.$('.ffc-btn-open-early').trigger('click');
		window.$('.ffc-csv-action-security input[name="ffc_captcha_ans"]').val('5');
		window.$('.ffc-open-early-confirm').trigger('click');
		await flush();

		const sent = lastPayload(postSpy);
		expect(sent.get('action')).toBe('ffc_public_open_early');
		expect(sent.get('form_id')).toBe('42');
		expect(sent.getAll('ffc_captcha_hash')).toEqual(['ACT']);
		expect(sent.getAll('ffc_captcha_ans')).toEqual(['5']);
		expect(sent.getAll('ffc_hp_ts')).toEqual(['stamp-public_csv_action']);
	});

	it('shows the fresh challenge of a refusal in the next dialog', async () => {
		const postSpy = await reachInfoScreen();
		vi.spyOn(window, 'alert').mockImplementation(() => {});
		postSpy.mockImplementation(() => postChain({ done: { success: false, data: {
			message: 'The math answer is incorrect.',
			refresh_captcha: true,
			provider: 'math',
			new_label: 'What is 4 + 4?',
			new_hash: 'NEW',
		} } }));

		window.$('.ffc-btn-open-early').trigger('click');
		window.$('.ffc-open-early-confirm').trigger('click');
		await flush();

		window.$('.ffc-btn-open-early').trigger('click');
		const $block = window.$('.ffc-csv-action-security');
		expect($block.find('.ffc-captcha-label-text').text()).toBe('What is 4 + 4?');
		expect($block.find('input[name="ffc_captcha_hash"]').val()).toBe('NEW');
	});
});
