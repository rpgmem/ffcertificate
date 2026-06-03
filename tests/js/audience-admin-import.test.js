// Tests for `assets/js/ffc-audience-admin-import.js`.
//
// jQuery tab-switcher for the audience Import & Export admin screen,
// extracted from an inline <script> in class-ffc-audience-admin-import.php
// (Item 10 of the frontend audit). jsdom has no layout, so visibility is
// asserted via css('display') rather than :visible (per CLAUDE.md).
import { describe, it, expect, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

const SCRIPT = 'assets/js/ffc-audience-admin-import.js';

function installTabs() {
	document.body.innerHTML = `
		<div class="nav-tab-wrapper">
			<a href="#ffc-import-tab" class="nav-tab nav-tab-active" data-tab="ffc-import-tab">Import</a>
			<a href="#ffc-export-tab" class="nav-tab" data-tab="ffc-export-tab">Export</a>
		</div>
		<div id="ffc-import-tab" class="ffc-tab-content">import panel</div>
		<div id="ffc-export-tab" class="ffc-tab-content" style="display:none;">export panel</div>
	`;
}

async function loadOnReady() {
	loadScript(SCRIPT);
	// jQuery 4 defers $(document).ready to a microtask even when the
	// document is already complete.
	await new Promise((r) => setTimeout(r, 0));
}

beforeEach(() => {
	window.$.fx.off = true;
	window.location.hash = '';
});

describe('ffc-audience-admin-import', () => {
	it('shows the clicked tab and hides the others', async () => {
		installTabs();
		await loadOnReady();

		window.$('.nav-tab[data-tab="ffc-export-tab"]').trigger('click');

		expect(window.$('#ffc-export-tab').css('display')).not.toBe('none');
		expect(window.$('#ffc-import-tab').css('display')).toBe('none');
		expect(
			window.$('.nav-tab[data-tab="ffc-export-tab"]').hasClass('nav-tab-active')
		).toBe(true);
		expect(
			window.$('.nav-tab[data-tab="ffc-import-tab"]').hasClass('nav-tab-active')
		).toBe(false);
	});

	it('prevents the default anchor navigation on tab click', async () => {
		installTabs();
		await loadOnReady();

		const ev = window.$.Event('click');
		window.$('.nav-tab[data-tab="ffc-export-tab"]').trigger(ev);

		expect(ev.isDefaultPrevented()).toBe(true);
	});

	it('restores the active tab from the URL hash on load', async () => {
		installTabs();
		window.location.hash = '#ffc-export-tab';
		await loadOnReady();

		expect(window.$('#ffc-export-tab').css('display')).not.toBe('none');
		expect(window.$('#ffc-import-tab').css('display')).toBe('none');
		expect(
			window.$('.nav-tab[data-tab="ffc-export-tab"]').hasClass('nav-tab-active')
		).toBe(true);
	});

	it('ignores a hash that matches no tab', async () => {
		installTabs();
		window.location.hash = '#nope';
		await loadOnReady();

		// Initial state preserved — import tab stays active.
		expect(
			window.$('.nav-tab[data-tab="ffc-import-tab"]').hasClass('nav-tab-active')
		).toBe(true);
	});
});
