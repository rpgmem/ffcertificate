// Tests for assets/js/ffc-admin-migrations.js — the JSON-batch runner
// for the Migrations settings tab. Replaces the legacy HTML-parsing
// loop with FFC.request + JSON updates per iteration.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'admin-nonce',
		strings: {},
	};
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-admin.js');
	loadScript('assets/js/ffc-admin-migrations.js');
});

beforeEach(() => {
	window.ffcMigrations = {
		nonce: 'mig-nonce',
		strings: {
			processing:         'Processing...',
			complete:           'Complete',
			processed:          'Processed ',
			records:            'records...',
			migrationComplete:  'Migration Complete',
			allRecordsMigrated: 'All records have been successfully migrated.',
			errorOccurred:      'Error occurred. Please try again.',
		},
	};

	// One migration card matching the live PHP markup.
	document.body.innerHTML = `
		<div class="ffc-migration-card">
			<h3>My migration</h3>
			<div class="ffc-migration-stats">
				<div class="ffc-migration-stat-value">5,000</div>
				<div class="ffc-migration-stat-value success">0</div>
				<div class="ffc-migration-stat-value pending">5,000</div>
				<div class="ffc-migration-stat-value info">0.0%</div>
			</div>
			<div class="ffc-migration-progress-bar">
				<div class="ffc-progress-bar-container" aria-valuenow="0">
					<div class="ffc-progress-bar-fill pending" style="width: 0%"></div>
					<div class="ffc-progress-bar-label light">0.0% Complete</div>
				</div>
			</div>
			<div class="ffc-migration-actions">
				<a href="?ffc_run_migration=my_migration&_wpnonce=x"
				   class="button button-primary"
				   data-confirm="Run my_migration?"><span></span>Run Migration</a>
				<p class="description">Click once to process all records automatically.</p>
			</div>
		</div>
	`;
});

afterEach(() => {
	vi.restoreAllMocks();
});

describe('FFC admin-migrations — batch loop', () => {
	it('extracts the migration key from the href and posts ffc_migration_run_batch', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({
			processed: 100, total: 5000, migrated: 100, pending: 4900, percent: 2.0, is_complete: true,
		});

		window.$('.ffc-migration-actions a.button-primary').trigger('click');
		await Promise.resolve(); await Promise.resolve();

		expect(requestSpy).toHaveBeenCalledWith('ffc_migration_run_batch', {
			migration_key: 'my_migration',
			nonce:         'mig-nonce',
		});
	});

	it('bails when confirm is declined', () => {
		vi.spyOn(window, 'confirm').mockReturnValue(false);
		const requestSpy = vi.spyOn(window.FFC, 'request');

		window.$('.ffc-migration-actions a.button-primary').trigger('click');

		expect(requestSpy).not.toHaveBeenCalled();
	});

	it('loops until is_complete=true, accumulating the REAL processed count', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.useFakeTimers();
		const requestSpy = vi.spyOn(window.FFC, 'request')
			.mockResolvedValueOnce({ processed: 100, total: 5000, migrated: 100, pending: 4900, percent: 2.0,  is_complete: false })
			.mockResolvedValueOnce({ processed: 100, total: 5000, migrated: 200, pending: 4800, percent: 4.0,  is_complete: false })
			.mockResolvedValueOnce({ processed: 32,  total: 5000, migrated: 232, pending: 4768, percent: 4.64, is_complete: true });

		window.$('.ffc-migration-actions a.button-primary').trigger('click');
		// First batch resolves.
		await vi.runAllTicks(); await Promise.resolve(); await Promise.resolve();
		// Advance the 300ms setTimeout for second batch.
		await vi.advanceTimersByTimeAsync(400);
		await vi.advanceTimersByTimeAsync(400);
		await Promise.resolve(); await Promise.resolve();

		expect(requestSpy).toHaveBeenCalledTimes(3);
		// Description counter shows 232 (100 + 100 + 32 — the LAST batch
		// is smaller than 100, exactly the case the legacy fake counter
		// got wrong).
		expect(document.querySelector('.ffc-migration-actions .description').innerHTML).toContain('All records');
		vi.useRealTimers();
	});

	it('repaints the progress bar + counters from the JSON payload', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.spyOn(window.FFC, 'request').mockResolvedValue({
			processed: 100, total: 5000, migrated: 100, pending: 4900, percent: 2.0, is_complete: true,
		});

		window.$('.ffc-migration-actions a.button-primary').trigger('click');
		await Promise.resolve(); await Promise.resolve(); await Promise.resolve();

		const $card = window.$('.ffc-migration-card');
		expect($card.find('.ffc-progress-bar-fill').attr('style')).toContain('width: 2');
		expect($card.find('.ffc-progress-bar-container').attr('aria-valuenow')).toBe('2.0');
		const stats = $card.find('.ffc-migration-stats .ffc-migration-stat-value');
		// total / migrated / pending / percent
		expect(stats.eq(1).text()).toBe('100');
		expect(stats.eq(2).text()).toBe('4,900');
		expect(stats.eq(3).text()).toBe('2.0%');
	});

	it('on error: re-enables the button and shows the server message', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.spyOn(window.FFC, 'request').mockRejectedValue(new Error('DB down'));

		const $btn = window.$('.ffc-migration-actions a.button-primary');
		const originalHtml = $btn.html();
		$btn.trigger('click');
		await Promise.resolve(); await Promise.resolve();

		expect($btn.prop('disabled')).toBe(false);
		// Button HTML restored to original (span + label text).
		expect($btn.html()).toBe(originalHtml);
		expect(window.$('.ffc-migration-actions .description').text()).toContain('DB down');
	});

	it('skips when href has no ffc_run_migration query (defensive)', () => {
		document.querySelector('.button-primary').setAttribute('href', '#');
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		const requestSpy = vi.spyOn(window.FFC, 'request');

		window.$('.ffc-migration-actions a.button-primary').trigger('click');

		expect(requestSpy).not.toHaveBeenCalled();
	});
});
