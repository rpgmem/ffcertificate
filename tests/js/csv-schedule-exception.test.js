// Regression coverage for assets/js/ffc-csv-schedule-exception.js (#366):
// the "End now (start stays at baseline)" mode must keep the start at the
// baseline and must NOT flag it as out-of-window when the baseline schedule
// is wider than the override window (e.g. 00:00–23:59 baseline under a
// 14:30–23:00 window). Mirrors the csv-download fixture harness.
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
			<form>
				<input type="text" name="form_id" value="42" />
				<input type="text" name="hash" value="abc" />
				<input type="hidden" name="_ffc_pcd_nonce" value="n" />
				<button type="submit" class="ffc-submit-btn">Info</button>
			</form>
		</div>
	`;
}

async function loadAndReady() {
	if (! window.FFC) {
		window.ffc_ajax = window.ffc_ajax || { ajax_url: '/wp-admin/admin-ajax.php', nonce: '', strings: {} };
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

// Load the CSV stack (real timers — loadScript uses setTimeout) and seed
// the container's last-info, WITHOUT opening the modal yet. Callers may then
// freeze the clock before invoking onScheduleExceptionClick().
async function prepare(status) {
	mountContainer();
	await loadAndReady();
	const api = window.FFCCsv;
	api.$container = window.$('.ffc-public-csv-download');
	api.$form = api.$container.find('form');
	api.$container.data('ffc-last-info', { status });
	return api;
}

const BASELINE = {
	schedule_baseline_start: '00:00',
	schedule_baseline_end:   '23:59',
	schedule_window_start:   '14:30',
	schedule_window_end:     '23:00',
};

beforeEach(() => {
	window.ffc_csv_download = {
		ajax_url: '/wp-admin/admin-ajax.php',
		min_display_ms: 1,
		strings: {
			scheduleExceptionTitle: 'Schedule exception',
			scheduleExceptionModeNow: 'End now (start stays at baseline)',
			scheduleExceptionModeManual: 'Edit both ends manually',
			scheduleExceptionConfirm: 'Create exception',
			scheduleExceptionOutOfWindow: 'Range must stay within %1$s–%2$s.',
			scheduleExceptionRangeInverted: 'Start must be earlier than end.',
			scheduleExceptionNoChange: 'Nothing to do.',
			cancel: 'Cancel',
			error: 'Error',
		},
	};
	window.$.fx.off = true;
});

afterEach(() => {
	vi.restoreAllMocks();
	vi.useRealTimers();
	document.body.innerHTML = '';
	document.querySelectorAll('.ffc-schedule-exception-modal').forEach((n) => n.remove());
});

describe('csv schedule-exception — End now mode (start stays at baseline)', () => {
	it('pre-fills + disables the start at baseline and sets end to now', async () => {
		const api = await prepare({ ...BASELINE, schedule_default_mode: 'now' });
		vi.useFakeTimers();
		vi.setSystemTime(new Date('2026-06-10T15:40:00'));
		api.onScheduleExceptionClick();

		const $start = window.$('.ffc-sched-exc-start');
		const $end = window.$('.ffc-sched-exc-end');
		expect($start.val()).toBe('00:00');
		expect($start.prop('disabled')).toBe(true);
		expect($end.val()).toBe('15:40');
	});

	it('does NOT flag out-of-window for the unchanged baseline start, and submits start_override empty', async () => {
		const api = await prepare({ ...BASELINE, schedule_default_mode: 'now' });
		vi.useFakeTimers();
		vi.setSystemTime(new Date('2026-06-10T15:40:00'));
		api.onScheduleExceptionClick();
		vi.useRealTimers();

		const reqSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({ form_url: '/x' });

		window.$('.ffc-sched-exc-confirm').trigger('click');
		await flush();

		// validate() passed (the bug blocked it with out-of-window): the
		// request fired and the modal swapped to the success CTA.
		expect(reqSpy).toHaveBeenCalled();
		const payload = reqSpy.mock.calls[0][1];
		// Start kept at baseline → empty override; new end carried through.
		expect(payload).toContain('start_override=&');
		expect(payload).toContain('end_override=15%3A40');
		expect(window.$('.ffc-sched-exc-open').length).toBe(1);
	});

	it('still rejects a manually-overridden start below the window', async () => {
		const api = await prepare({ ...BASELINE, schedule_default_mode: 'manual' });
		api.onScheduleExceptionClick();
		await flush();
		// Manual mode: set start below window.
		window.$('.ffc-sched-exc-start').val('13:00');
		window.$('.ffc-sched-exc-end').val('15:40');
		const reqSpy = vi.spyOn(window.FFC, 'request');
		window.$('.ffc-sched-exc-confirm').trigger('click');
		await flush();

		expect(window.$('.ffc-sched-exc-error').attr('hidden')).toBeUndefined();
		expect(window.$('.ffc-sched-exc-error').text()).toContain('14:30');
		expect(reqSpy).not.toHaveBeenCalled();
	});
});
