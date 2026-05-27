// Tests for FFC.FormEditorTabs — vertical-tab behaviour on the certificate
// form editor (introduced with the tabs refactor).
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-form-editor-tabs.js');
});

const TABS = [
	{ key: 'layout', icon: 'media-document', label: 'Layout' },
	{ key: 'email', icon: 'email', label: 'Email' },
	{ key: 'geolocation', icon: 'location-alt', label: 'Geolocation' },
];

// Build the markup FormEditorMetaboxRenderer::render_tabbed_container() emits.
function buildTabs(tabs = TABS) {
	const nav = tabs
		.map((t, i) => {
			const active = i === 0;
			return (
				`<li class="ffc-form-tabs__nav-item" role="presentation">` +
				`<a href="#ffc-tab-${t.key}" id="ffc-tabnav-${t.key}" ` +
				`class="ffc-form-tabs__tab${active ? ' is-active' : ''}" role="tab" ` +
				`aria-controls="ffc-tabpanel-${t.key}" aria-selected="${active ? 'true' : 'false'}" ` +
				`tabindex="${active ? '0' : '-1'}">` +
				`<span class="dashicons dashicons-${t.icon}"></span>` +
				`<span class="ffc-form-tabs__label">${t.label}</span></a></li>`
			);
		})
		.join('');

	const panels = tabs
		.map((t, i) => {
			const active = i === 0;
			return (
				`<section id="ffc-tabpanel-${t.key}" ` +
				`class="ffc-form-tabs__panel${active ? ' is-active' : ''}" role="tabpanel" ` +
				`aria-labelledby="ffc-tabnav-${t.key}" tabindex="0">` +
				`<h2 class="ffc-form-tabs__panel-title">${t.label}</h2></section>`
			);
		})
		.join('');

	document.body.innerHTML =
		`<div class="ffc-form-tabs" data-ffc-form-tabs>` +
		`<ul class="ffc-form-tabs__nav" role="tablist" aria-orientation="vertical">${nav}</ul>` +
		`<div class="ffc-form-tabs__panels">${panels}</div>` +
		`</div>`;
}

function tab(key) {
	return window.$('#ffc-tabnav-' + key);
}
function panel(key) {
	return window.$('#ffc-tabpanel-' + key);
}

beforeEach(() => {
	document.body.innerHTML = '';
	window.history.replaceState(null, '', window.location.pathname);
	delete window.ffcFormTabsErrors;
	delete window.ffcFormRequiredTags;
});

afterEach(() => {
	// setupContainer() binds a window-scoped hashchange handler per init;
	// drop it so a later test's hashchange doesn't fire stale handlers.
	window.$(window).off('hashchange.ffcFormTabs');
	vi.restoreAllMocks();
});

describe('FFC.FormEditorTabs.init', () => {
	it('marks the container ready so the CSS can hide inactive panels', () => {
		buildTabs();
		window.FFC.FormEditorTabs.init();
		expect(window.$('.ffc-form-tabs').hasClass('is-ready')).toBe(true);
	});

	it('keeps the first tab active on load when there is no hash', () => {
		buildTabs();
		window.FFC.FormEditorTabs.init();

		expect(tab('layout').attr('aria-selected')).toBe('true');
		expect(tab('layout').attr('tabindex')).toBe('0');
		expect(panel('layout').hasClass('is-active')).toBe(true);
		expect(tab('email').attr('aria-selected')).toBe('false');
		expect(tab('email').attr('tabindex')).toBe('-1');
		expect(panel('email').hasClass('is-active')).toBe(false);
	});

	it('activates the tab named in a deep-link hash on load', () => {
		window.history.replaceState(null, '', '#ffc-tab-geolocation');
		buildTabs();
		window.FFC.FormEditorTabs.init();

		expect(tab('geolocation').attr('aria-selected')).toBe('true');
		expect(panel('geolocation').hasClass('is-active')).toBe(true);
		expect(panel('layout').hasClass('is-active')).toBe(false);
	});

	it('ignores a hash that does not map to a tab and falls back to the first', () => {
		window.history.replaceState(null, '', '#ffc-tab-nope');
		buildTabs();
		window.FFC.FormEditorTabs.init();

		expect(tab('layout').attr('aria-selected')).toBe('true');
	});

	it('switches panels on click and updates the URL hash', () => {
		buildTabs();
		window.FFC.FormEditorTabs.init();

		tab('email').trigger('click');

		expect(tab('email').attr('aria-selected')).toBe('true');
		expect(panel('email').hasClass('is-active')).toBe(true);
		expect(tab('layout').attr('aria-selected')).toBe('false');
		expect(panel('layout').hasClass('is-active')).toBe(false);
		expect(window.location.hash).toBe('#ffc-tab-email');
	});

	it('moves to the next/previous tab with arrow keys (roving tabindex)', () => {
		buildTabs();
		window.FFC.FormEditorTabs.init();

		const down = window.$.Event('keydown', { key: 'ArrowDown' });
		tab('layout').trigger(down);
		expect(tab('email').attr('aria-selected')).toBe('true');
		expect(tab('email').attr('tabindex')).toBe('0');

		const up = window.$.Event('keydown', { key: 'ArrowUp' });
		tab('email').trigger(up);
		expect(tab('layout').attr('aria-selected')).toBe('true');
	});

	it('wraps with ArrowUp from the first tab to the last, and Home/End jump', () => {
		buildTabs();
		window.FFC.FormEditorTabs.init();

		tab('layout').trigger(window.$.Event('keydown', { key: 'ArrowUp' }));
		expect(tab('geolocation').attr('aria-selected')).toBe('true');

		tab('geolocation').trigger(window.$.Event('keydown', { key: 'Home' }));
		expect(tab('layout').attr('aria-selected')).toBe('true');

		tab('layout').trigger(window.$.Event('keydown', { key: 'End' }));
		expect(tab('geolocation').attr('aria-selected')).toBe('true');
	});

	it('reacts to an external hashchange without re-writing the hash', () => {
		buildTabs();
		window.FFC.FormEditorTabs.init();

		window.history.replaceState(null, '', '#ffc-tab-email');
		window.$(window).trigger('hashchange');

		expect(tab('email').attr('aria-selected')).toBe('true');
		expect(panel('email').hasClass('is-active')).toBe(true);
	});

	it('refreshes a CodeMirror instance when its panel becomes visible', () => {
		buildTabs();
		const refresh = vi.fn();
		const cm = document.createElement('div');
		cm.className = 'CodeMirror';
		cm.CodeMirror = { refresh };
		panel('layout')[0].appendChild(cm);

		// Start on email so layout is hidden, then reveal it via click.
		window.history.replaceState(null, '', '#ffc-tab-email');
		window.FFC.FormEditorTabs.init();
		expect(refresh).not.toHaveBeenCalled();

		tab('layout').trigger('click');
		expect(refresh).toHaveBeenCalled();
	});

	it('is a no-op when no tab container is present', () => {
		document.body.innerHTML = '<div>no tabs here</div>';
		expect(() => window.FFC.FormEditorTabs.init()).not.toThrow();
	});

	it('flags error tabs from window.ffcFormTabsErrors and opens the first', () => {
		buildTabs();
		window.ffcFormTabsErrors = ['geolocation'];
		window.FFC.FormEditorTabs.init();

		expect(tab('geolocation').hasClass('has-error')).toBe(true);
		expect(tab('geolocation').find('.ffc-form-tabs__error-dot').length).toBe(1);
		// First errored tab is auto-opened over the default first tab.
		expect(tab('geolocation').attr('aria-selected')).toBe('true');
		expect(panel('geolocation').hasClass('is-active')).toBe(true);
		expect(tab('layout').attr('aria-selected')).toBe('false');
	});

	it('does not add a duplicate error dot when one is already present', () => {
		buildTabs();
		window.ffcFormTabsErrors = ['layout', 'geolocation'];
		window.FFC.FormEditorTabs.init();
		// Re-running init must not stack a second dot on each flagged tab.
		window.FFC.FormEditorTabs.init();

		expect(tab('layout').find('.ffc-form-tabs__error-dot').length).toBe(1);
		expect(tab('geolocation').find('.ffc-form-tabs__error-dot').length).toBe(1);
		// The first key in the list is the one opened.
		expect(tab('layout').attr('aria-selected')).toBe('true');
	});

	it('ignores error keys that do not match any tab', () => {
		buildTabs();
		window.ffcFormTabsErrors = ['nonexistent'];
		window.FFC.FormEditorTabs.init();

		// No tab flagged, default first tab stays active.
		expect(window.$('.has-error').length).toBe(0);
		expect(tab('layout').attr('aria-selected')).toBe('true');
	});
});

describe('FFC.FormEditorTabs — required-tag save guard', () => {
	function buildEditorForm(layoutValue) {
		document.body.innerHTML =
			`<form id="post">` +
			`<div class="ffc-form-tabs" data-ffc-form-tabs>` +
			`<ul class="ffc-form-tabs__nav" role="tablist" aria-orientation="vertical">` +
			`<li><a id="ffc-tabnav-layout" class="ffc-form-tabs__tab is-active" role="tab" aria-controls="ffc-tabpanel-layout" aria-selected="true" tabindex="0">L</a></li>` +
			`<li><a id="ffc-tabnav-email" class="ffc-form-tabs__tab" role="tab" aria-controls="ffc-tabpanel-email" aria-selected="false" tabindex="-1">E</a></li>` +
			`</ul>` +
			`<div class="ffc-form-tabs__panels">` +
			`<section id="ffc-tabpanel-layout" class="ffc-form-tabs__panel is-active" role="tabpanel">` +
			`<textarea id="ffc_pdf_layout">${layoutValue}</textarea></section>` +
			`<section id="ffc-tabpanel-email" class="ffc-form-tabs__panel" role="tabpanel"></section>` +
			`</div></div></form>`;
	}

	beforeEach(() => {
		window.ffcFormRequiredTags = {
			tags: ['{{auth_code}}', '{{name}}', '{{cpf_rf}}'],
			aliases: { '{{name}}': ['{{nome}}'] },
			message: 'Missing required tags:',
		};
	});

	it('blocks the submit and banners the missing tags on the Layout tab', () => {
		buildEditorForm('{{auth_code}} only');
		window.FFC.FormEditorTabs.init();

		const ev = window.$.Event('submit');
		window.$('#post').trigger(ev);

		expect(ev.isDefaultPrevented()).toBe(true);
		const $warn = window.$('.ffc-form-tabs__required-warning');
		expect($warn.length).toBe(1);
		// Banner lives inside the Layout panel.
		expect($warn.closest('#ffc-tabpanel-layout').length).toBe(1);
		// Lists the two missing tags but not the present one.
		expect($warn.text()).toContain('{{name}}');
		expect($warn.text()).toContain('{{cpf_rf}}');
		expect($warn.text()).not.toContain('{{auth_code}}');
		// Layout tab is opened.
		expect(tab('layout').attr('aria-selected')).toBe('true');
	});

	it('does not block the submit when every required tag is present', () => {
		buildEditorForm('{{auth_code}} {{name}} {{cpf_rf}}');
		window.FFC.FormEditorTabs.init();

		const ev = window.$.Event('submit');
		window.$('#post').trigger(ev);

		expect(ev.isDefaultPrevented()).toBe(false);
		expect(window.$('.ffc-form-tabs__required-warning').length).toBe(0);
	});

	it('accepts the {{nome}} alias as satisfying {{name}}', () => {
		buildEditorForm('{{auth_code}} {{nome}} {{cpf_rf}}');
		window.FFC.FormEditorTabs.init();

		const ev = window.$.Event('submit');
		window.$('#post').trigger(ev);

		expect(ev.isDefaultPrevented()).toBe(false);
	});

	it('no-ops when no required-tag config is localised', () => {
		delete window.ffcFormRequiredTags;
		buildEditorForm('empty');
		window.FFC.FormEditorTabs.init();

		const ev = window.$.Event('submit');
		window.$('#post').trigger(ev);

		expect(ev.isDefaultPrevented()).toBe(false);
		expect(window.$('.ffc-form-tabs__required-warning').length).toBe(0);
	});
});
