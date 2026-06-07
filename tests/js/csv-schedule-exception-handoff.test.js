// Coverage for the per-participant schedule-exception flow in
// `assets/js/ffc-csv-schedule-exception.js`, with emphasis on the
// post-create hand-off: success swaps the modal to a "staged" CTA whose
// "Open participant form" link carries the server-resolved form_url, and
// clicking it shows a spinner + notice that lingers for a forced beat
// before the modal disappears (#366 Sprint 5).
//
// Mirrors the fixture style of `csv-download-open-early.test.js`.

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function postChain(spec) {
	const chain = { done: () => chain, fail: () => chain };
	if (spec && 'done' in spec) chain.done = (cb) => { cb(spec.done); return chain; };
	if (spec && spec.fail) chain.fail = (cb) => { cb(); return chain; };
	return chain;
}

function flush() { return Promise.resolve().then(() => Promise.resolve()); }

function mountContainer() {
	document.body.innerHTML = `
		<div class="ffc-public-csv-download">
			<div class="ffc-verification-header">header</div>
			<form>
				<input type="text" name="form_id" value="42" />
				<input type="text" name="hash" value="abc" />
				<input type="hidden" name="_ffc_pcd_nonce" value="n" />
				<button type="submit" class="ffc-submit-btn">Info</button>
			</form>
		</div>
	`;
}

function infoWithScheduleException(overrides = {}) {
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
			can_schedule_exception: true,
			schedule_form_url: 'https://example.test/the-form-page/',
			schedule_baseline_start: '08:00',
			schedule_baseline_end: '18:00',
			schedule_window_start: '08:00',
			schedule_window_end: '18:00',
			schedule_default_mode: 'manual',
			...(overrides.status || {}),
		},
		...overrides,
	};
}

async function loadAndReady() {
	if (! window.FFC) {
		window.ffc_ajax = window.ffc_ajax || {
			ajax_url: window.ffc_csv_download && window.ffc_csv_download.ajax_url,
			nonce: '',
			strings: (window.ffc_csv_download && window.ffc_csv_download.strings) || {},
		};
		loadScript('assets/js/ffc-core.js');
	}
	loadScript('assets/js/ffc-csv-download.js');
	loadScript('assets/js/ffc-csv-info-screen.js');
	loadScript('assets/js/ffc-csv-cert-preview.js');
	loadScript('assets/js/ffc-csv-download-flow.js');
	loadScript('assets/js/ffc-csv-open-early.js');
	loadScript('assets/js/ffc-csv-extend-end.js');
	loadScript('assets/js/ffc-csv-schedule-exception.js');
	await new Promise((r) => setTimeout(r, 0));
}

async function reachInfoScreen(infoOverride) {
	mountContainer();
	await loadAndReady();
	const postSpy = vi.spyOn(window.$, 'post');
	postSpy.mockImplementationOnce(() => postChain({ done: { success: true, data: infoOverride || infoWithScheduleException() } }));
	window.$('.ffc-public-csv-download form').trigger('submit');
	await flush();
	return postSpy;
}

// Walk to the staged-CTA state: open modal, edit the end time so the
// override differs from the baseline, confirm, resolve the AJAX with a
// form_url. Returns the post spy for further assertions.
async function reachStagedCta(postSpy, formUrl) {
	window.$('.ffc-btn-schedule-exception').trigger('click');
	await flush();
	window.$('.ffc-sched-exc-end').val('17:00').trigger('change');
	postSpy.mockImplementation(() => postChain({ done: {
		success: true,
		data: { token: 'a.b', form_url: formUrl },
	} }));
	window.$('.ffc-sched-exc-confirm').trigger('click');
	await flush();
}

beforeEach(() => {
	window.ffc_csv_download = {
		ajax_url: '/wp-admin/admin-ajax.php',
		min_display_ms: 1,
		strings: {
			error: 'Error',
			cancel: 'Cancel',
			scheduleException: 'Entry/exit exception',
			scheduleExceptionTooltip: 'One-use schedule exception.',
			scheduleExceptionTitle: 'Schedule exception',
			scheduleExceptionBody: 'Set a different schedule.',
			scheduleExceptionModeNow: 'End now',
			scheduleExceptionModeManual: 'Edit both ends',
			scheduleExceptionStartLabel: 'New start:',
			scheduleExceptionEndLabel: 'New end:',
			scheduleExceptionConfirm: 'Create exception',
			scheduleExceptionSubmitting: 'Creating…',
			scheduleExceptionStaged: 'Exception staged.',
			scheduleExceptionOpenForm: 'Open participant form',
			scheduleExceptionOpening: 'Opening the participant form in a new tab…',
			scheduleExceptionFormUrlLabel: 'The participant form opens at:',
		},
	};
	window.$.fx.off = true;
});

afterEach(() => {
	vi.restoreAllMocks();
	vi.useRealTimers();
	document.body.innerHTML = '';
	document.querySelectorAll('.ffc-schedule-exception-modal, .ffc-open-early-modal').forEach((n) => n.remove());
});

describe('csv schedule-exception — button render', () => {
	it('renders the button when status.can_schedule_exception is true', async () => {
		await reachInfoScreen();
		expect(window.$('.ffc-btn-schedule-exception').length).toBe(1);
		expect(window.$('.ffc-btn-schedule-exception').text()).toContain('Entry/exit exception');
	});

	it('omits the button when status.can_schedule_exception is false', async () => {
		await reachInfoScreen(
			infoWithScheduleException({ status: { can_schedule_exception: false } })
		);
		expect(window.$('.ffc-btn-schedule-exception').length).toBe(0);
	});

	it('renders a short "open form" link in the summary on validation', async () => {
		await reachInfoScreen();
		const $link = window.$('.ffc-info-summary .ffc-info-form-link');
		expect($link.length).toBe(1);
		expect($link.attr('href')).toBe('https://example.test/the-form-page/');
		expect($link.attr('target')).toBe('_blank');
		// Short link text, not the raw URL.
		expect($link.text()).not.toContain('http');
	});

	it('omits the form link when schedule_form_url is empty', async () => {
		await reachInfoScreen(
			infoWithScheduleException({ status: { schedule_form_url: '' } })
		);
		expect(window.$('.ffc-info-form-link').length).toBe(0);
	});
});

describe('csv schedule-exception — staged CTA hand-off', () => {
	it('POSTs ffc_public_schedule_exception and renders the open link with the server form_url', async () => {
		const postSpy = await reachInfoScreen();
		await reachStagedCta(postSpy, 'https://example.test/the-form-page/');

		const lastPayload = postSpy.mock.calls[postSpy.mock.calls.length - 1][1];
		expect(lastPayload).toContain('action=ffc_public_schedule_exception');

		const $open = window.$('.ffc-sched-exc-open');
		expect($open.length).toBe(1);
		expect($open.attr('href')).toBe('https://example.test/the-form-page/');
		expect($open.attr('target')).toBe('_blank');
		expect($open.attr('rel')).toContain('noopener');
		expect(window.$('.ffc-schedule-exception-modal').text()).toContain('Exception staged.');
	});

	it('falls back to "/" when the server omits form_url', async () => {
		const postSpy = await reachInfoScreen();
		window.$('.ffc-btn-schedule-exception').trigger('click');
		await flush();
		window.$('.ffc-sched-exc-end').val('17:00').trigger('change');
		postSpy.mockImplementation(() => postChain({ done: { success: true, data: { token: 'a.b' } } }));
		window.$('.ffc-sched-exc-confirm').trigger('click');
		await flush();

		expect(window.$('.ffc-sched-exc-open').attr('href')).toBe('/');
	});

	it('shows a spinner + opening notice and holds the modal for a forced beat before removing it', async () => {
		const postSpy = await reachInfoScreen();
		await reachStagedCta(postSpy, 'https://example.test/the-form-page/');

		vi.useFakeTimers();
		// triggerHandler invokes the bound click handler without jsdom
		// attempting to follow the target="_blank" anchor.
		window.$('.ffc-sched-exc-open').triggerHandler('click');

		// Spinner + notice appear immediately; modal is still on screen.
		expect(window.$('.ffc-sched-exc-spinner').length).toBe(1);
		expect(window.$('.ffc-schedule-exception-modal').text()).toContain('Opening the participant form in a new tab…');
		expect(window.$('.ffc-schedule-exception-modal').length).toBe(1);

		// Forced delay (1200ms) elapses → modal removed.
		vi.advanceTimersByTime(1199);
		expect(window.$('.ffc-schedule-exception-modal').length).toBe(1);
		vi.advanceTimersByTime(1);
		expect(window.$('.ffc-schedule-exception-modal').length).toBe(0);
	});
});
