// Tests for `assets/js/ffc-scheduled-tasks.js`.
//
// The crontab-line generator only swaps a PHP-built line into the read-only
// input; it never assembles a command itself.
import { describe, it, expect, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

const SCRIPT = 'assets/js/ffc-scheduled-tasks.js';

async function loadOnReady() {
	loadScript(SCRIPT);
	await new Promise((r) => setTimeout(r, 0));
}

function installDom() {
	document.body.innerHTML = `
		<select id="ffc-crontab-method">
			<option value="wp_cli">WP-CLI</option>
			<option value="wget">wget</option>
		</select>
		<select id="ffc-crontab-minutes">
			<option value="5">5</option>
			<option value="15" selected>15</option>
		</select>
		<input id="ffc-crontab-line" readonly value="">
	`;
}

beforeEach(() => {
	document.body.innerHTML = '';
	window.ffcScheduledTasks = {
		lines: {
			wp_cli: { 5: 'CLI-5', 15: 'CLI-15' },
			wget: { 5: 'WGET-5', 15: 'WGET-15' },
		},
	};
});

describe('ffc-scheduled-tasks', () => {
	it('shows the line for the initial selection on load', async () => {
		installDom();
		await loadOnReady();
		expect(document.getElementById('ffc-crontab-line').value).toBe('CLI-15');
	});

	it('swaps the line when the method or the frequency changes', async () => {
		installDom();
		await loadOnReady();

		window.$('#ffc-crontab-method').val('wget').trigger('change');
		expect(document.getElementById('ffc-crontab-line').value).toBe('WGET-15');

		window.$('#ffc-crontab-minutes').val('5').trigger('change');
		expect(document.getElementById('ffc-crontab-line').value).toBe('WGET-5');
	});

	it('empties the line rather than showing a stale one for an unknown pair', async () => {
		installDom();
		window.ffcScheduledTasks = { lines: { wp_cli: {} } };
		await loadOnReady();
		expect(document.getElementById('ffc-crontab-line').value).toBe('');
	});

	it('does nothing on a screen without the generator', async () => {
		document.body.innerHTML = '<select id="ffc-crontab-method"></select>';
		await loadOnReady();
		expect(document.getElementById('ffc-crontab-line')).toBeNull();
	});
});
