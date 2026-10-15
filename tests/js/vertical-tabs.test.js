// Tests for FFC.VerticalTabs — the shared vertical-tab component of the
// admin editors (the certificate form editor first; renamed for the family in
// #1788).
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	// The required-tag guard flushes the layout editor through the shared
	// window.FFCCodeEditor helper; load the core so it's defined.
	loadScript('assets/js/ffc-code-editor-core.js');
	loadScript('assets/js/ffc-vertical-tabs.js');
});

const TABS = [
	{ key: 'layout', icon: 'file', label: 'Layout' },
	{ key: 'email', icon: 'email', label: 'Email' },
	{ key: 'geolocation', icon: 'map-pin', label: 'Geolocation' },
];

// Build the markup FormEditorMetaboxRenderer::render_tabbed_container() emits.
function buildTabs(tabs = TABS) {
	const nav = tabs
		.map((t, i) => {
			const active = i === 0;
			return (
				`<li class="ffc-vtabs__nav-item" role="presentation">` +
				`<a href="#ffc-tab-${t.key}" id="ffc-tabnav-${t.key}" ` +
				`class="ffc-vtabs__tab${active ? ' is-active' : ''}" role="tab" ` +
				`aria-controls="ffc-tabpanel-${t.key}" aria-selected="${active ? 'true' : 'false'}" ` +
				`tabindex="${active ? '0' : '-1'}">` +
				`<span class="ffc-vtabs__icon ffc-icon-${t.icon}" aria-hidden="true"></span>` +
				`<span class="ffc-vtabs__label">${t.label}</span></a></li>`
			);
		})
		.join('');

	const panels = tabs
		.map((t, i) => {
			const active = i === 0;
			return (
				`<section id="ffc-tabpanel-${t.key}" ` +
				`class="ffc-vtabs__panel${active ? ' is-active' : ''}" role="tabpanel" ` +
				`aria-labelledby="ffc-tabnav-${t.key}" tabindex="0">` +
				`<h2 class="ffc-vtabs__panel-title">${t.label}</h2></section>`
			);
		})
		.join('');

	document.body.innerHTML =
		`<div class="ffc-vtabs" data-ffc-vtabs>` +
		`<ul class="ffc-vtabs__nav" role="tablist" aria-orientation="vertical">${nav}</ul>` +
		`<div class="ffc-vtabs__panels">${panels}</div>` +
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
	window.$(window).off('hashchange.ffcVerticalTabs');
	vi.restoreAllMocks();
});

describe('FFC.VerticalTabs — deprecated global (#1788)', () => {
	it('keeps FFC.FormEditorTabs as an alias of the same object', () => {
		expect(window.FFC.VerticalTabs).toBeDefined();
		expect(typeof window.FFC.VerticalTabs.init).toBe('function');
		expect(window.FFC.FormEditorTabs).toBe(window.FFC.VerticalTabs);
	});
});

describe('FFC.VerticalTabs.init', () => {
	it('marks the container ready so the CSS can hide inactive panels', () => {
		buildTabs();
		window.FFC.VerticalTabs.init();
		expect(window.$('.ffc-vtabs').hasClass('is-ready')).toBe(true);
	});

	it('keeps the first tab active on load when there is no hash', () => {
		buildTabs();
		window.FFC.VerticalTabs.init();

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
		window.FFC.VerticalTabs.init();

		expect(tab('geolocation').attr('aria-selected')).toBe('true');
		expect(panel('geolocation').hasClass('is-active')).toBe(true);
		expect(panel('layout').hasClass('is-active')).toBe(false);
	});

	it('ignores a hash that does not map to a tab and falls back to the first', () => {
		window.history.replaceState(null, '', '#ffc-tab-nope');
		buildTabs();
		window.FFC.VerticalTabs.init();

		expect(tab('layout').attr('aria-selected')).toBe('true');
	});

	it('switches panels on click and updates the URL hash', () => {
		buildTabs();
		window.FFC.VerticalTabs.init();

		tab('email').trigger('click');

		expect(tab('email').attr('aria-selected')).toBe('true');
		expect(panel('email').hasClass('is-active')).toBe(true);
		expect(tab('layout').attr('aria-selected')).toBe('false');
		expect(panel('layout').hasClass('is-active')).toBe(false);
		expect(window.location.hash).toBe('#ffc-tab-email');
	});

	it('moves to the next/previous tab with arrow keys (roving tabindex)', () => {
		buildTabs();
		window.FFC.VerticalTabs.init();

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
		window.FFC.VerticalTabs.init();

		tab('layout').trigger(window.$.Event('keydown', { key: 'ArrowUp' }));
		expect(tab('geolocation').attr('aria-selected')).toBe('true');

		tab('geolocation').trigger(window.$.Event('keydown', { key: 'Home' }));
		expect(tab('layout').attr('aria-selected')).toBe('true');

		tab('layout').trigger(window.$.Event('keydown', { key: 'End' }));
		expect(tab('geolocation').attr('aria-selected')).toBe('true');
	});

	it('reacts to an external hashchange without re-writing the hash', () => {
		buildTabs();
		window.FFC.VerticalTabs.init();

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
		window.FFC.VerticalTabs.init();
		expect(refresh).not.toHaveBeenCalled();

		tab('layout').trigger('click');
		expect(refresh).toHaveBeenCalled();
	});

	it('is a no-op when no tab container is present', () => {
		document.body.innerHTML = '<div>no tabs here</div>';
		expect(() => window.FFC.VerticalTabs.init()).not.toThrow();
	});

	it('returns early for a container that has no tab anchors', () => {
		document.body.innerHTML =
			'<div class="ffc-vtabs" data-ffc-vtabs>' +
			'<ul class="ffc-vtabs__nav"></ul></div>';
		window.FFC.VerticalTabs.init();
		// setupContainer bailed before adding is-ready.
		expect(window.$('.ffc-vtabs').hasClass('is-ready')).toBe(false);
	});

	it('ignores keydown keys outside the navigation set', () => {
		buildTabs();
		window.FFC.VerticalTabs.init();
		const before = tab('layout').attr('aria-selected');
		// A key not in the switch → handler returns without moving focus.
		tab('layout').trigger(window.$.Event('keydown', { key: 'a' }));
		expect(tab('layout').attr('aria-selected')).toBe(before);
	});

	it('falls back to location.hash when history.replaceState is unavailable', () => {
		buildTabs();
		window.FFC.VerticalTabs.init();
		const savedReplace = window.history.replaceState;
		// Force the else branch in updateHash.
		Object.defineProperty(window.history, 'replaceState', {
			value: undefined,
			configurable: true,
		});
		try {
			tab('email').trigger('click');
			expect(window.location.hash).toBe('#ffc-tab-email');
		} finally {
			Object.defineProperty(window.history, 'replaceState', {
				value: savedReplace,
				configurable: true,
			});
		}
	});

	it('flags error tabs from window.ffcFormTabsErrors and opens the first', () => {
		buildTabs();
		window.ffcFormTabsErrors = ['geolocation'];
		window.FFC.VerticalTabs.init();

		expect(tab('geolocation').hasClass('has-error')).toBe(true);
		expect(tab('geolocation').find('.ffc-vtabs__error-dot').length).toBe(1);
		// First errored tab is auto-opened over the default first tab.
		expect(tab('geolocation').attr('aria-selected')).toBe('true');
		expect(panel('geolocation').hasClass('is-active')).toBe(true);
		expect(tab('layout').attr('aria-selected')).toBe('false');
	});

	it('does not add a duplicate error dot when one is already present', () => {
		buildTabs();
		window.ffcFormTabsErrors = ['layout', 'geolocation'];
		window.FFC.VerticalTabs.init();
		// Re-running init must not stack a second dot on each flagged tab.
		window.FFC.VerticalTabs.init();

		expect(tab('layout').find('.ffc-vtabs__error-dot').length).toBe(1);
		expect(tab('geolocation').find('.ffc-vtabs__error-dot').length).toBe(1);
		// The first key in the list is the one opened.
		expect(tab('layout').attr('aria-selected')).toBe('true');
	});

	it('ignores error keys that do not match any tab', () => {
		buildTabs();
		window.ffcFormTabsErrors = ['nonexistent'];
		window.FFC.VerticalTabs.init();

		// No tab flagged, default first tab stays active.
		expect(window.$('.has-error').length).toBe(0);
		expect(tab('layout').attr('aria-selected')).toBe('true');
	});
});

describe('FFC.VerticalTabs — required-tag save guard', () => {
	function buildEditorForm(layoutValue) {
		document.body.innerHTML =
			`<form id="post">` +
			`<div class="ffc-vtabs" data-ffc-vtabs>` +
			`<ul class="ffc-vtabs__nav" role="tablist" aria-orientation="vertical">` +
			`<li><a id="ffc-tabnav-layout" class="ffc-vtabs__tab is-active" role="tab" aria-controls="ffc-tabpanel-layout" aria-selected="true" tabindex="0">L</a></li>` +
			`<li><a id="ffc-tabnav-email" class="ffc-vtabs__tab" role="tab" aria-controls="ffc-tabpanel-email" aria-selected="false" tabindex="-1">E</a></li>` +
			`</ul>` +
			`<div class="ffc-vtabs__panels">` +
			`<section id="ffc-tabpanel-layout" class="ffc-vtabs__panel is-active" role="tabpanel">` +
			`<textarea id="ffc_pdf_layout">${layoutValue}</textarea></section>` +
			`<section id="ffc-tabpanel-email" class="ffc-vtabs__panel" role="tabpanel"></section>` +
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
		window.FFC.VerticalTabs.init();

		const ev = window.$.Event('submit');
		window.$('#post').trigger(ev);

		expect(ev.isDefaultPrevented()).toBe(true);
		const $warn = window.$('.ffc-vtabs__required-warning');
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
		window.FFC.VerticalTabs.init();

		const ev = window.$.Event('submit');
		window.$('#post').trigger(ev);

		expect(ev.isDefaultPrevented()).toBe(false);
		expect(window.$('.ffc-vtabs__required-warning').length).toBe(0);
	});

	it('accepts the {{nome}} alias as satisfying {{name}}', () => {
		buildEditorForm('{{auth_code}} {{nome}} {{cpf_rf}}');
		window.FFC.VerticalTabs.init();

		const ev = window.$.Event('submit');
		window.$('#post').trigger(ev);

		expect(ev.isDefaultPrevented()).toBe(false);
	});

	it('no-ops when no required-tag config is localised', () => {
		delete window.ffcFormRequiredTags;
		buildEditorForm('empty');
		window.FFC.VerticalTabs.init();

		const ev = window.$.Event('submit');
		window.$('#post').trigger(ev);

		expect(ev.isDefaultPrevented()).toBe(false);
		expect(window.$('.ffc-vtabs__required-warning').length).toBe(0);
	});

	it('does not install the guard when the container is not inside a form', () => {
		// Tabs container with NO wrapping <form> → setupRequiredTagGuard bails.
		document.body.innerHTML =
			`<div class="ffc-vtabs" data-ffc-vtabs>` +
			`<ul class="ffc-vtabs__nav" role="tablist">` +
			`<li><a id="ffc-tabnav-layout" class="ffc-vtabs__tab is-active" role="tab" aria-controls="ffc-tabpanel-layout" aria-selected="true" tabindex="0">L</a></li>` +
			`</ul><div class="ffc-vtabs__panels">` +
			`<section id="ffc-tabpanel-layout" class="ffc-vtabs__panel is-active" role="tabpanel">` +
			`<textarea id="ffc_pdf_layout">no tags</textarea></section></div></div>`;
		expect(() => window.FFC.VerticalTabs.init()).not.toThrow();
		// No submit handler exists since there's no form to attach to.
		expect(window.$('.ffc-vtabs__required-warning').length).toBe(0);
	});

	it('lets the submit through when there is no #ffc_pdf_layout textarea', () => {
		// Form + tabs but the layout textarea is absent → guard returns early.
		document.body.innerHTML =
			`<form id="post"><div class="ffc-vtabs" data-ffc-vtabs>` +
			`<ul class="ffc-vtabs__nav" role="tablist">` +
			`<li><a id="ffc-tabnav-layout" class="ffc-vtabs__tab is-active" role="tab" aria-controls="ffc-tabpanel-layout" aria-selected="true" tabindex="0">L</a></li>` +
			`</ul><div class="ffc-vtabs__panels">` +
			`<section id="ffc-tabpanel-layout" class="ffc-vtabs__panel is-active" role="tabpanel"></section>` +
			`</div></div></form>`;
		window.FFC.VerticalTabs.init();

		const ev = window.$.Event('submit');
		window.$('#post').trigger(ev);
		expect(ev.isDefaultPrevented()).toBe(false);
	});

	it('flushes CodeMirror into the textarea before validating on submit', () => {
		buildEditorForm('{{auth_code}} {{name}} {{cpf_rf}}');
		// Insert a sibling CodeMirror after the textarea whose .save() writes
		// the canonical content back into #ffc_pdf_layout.
		const save = vi.fn();
		const cmEl = document.createElement('div');
		cmEl.className = 'CodeMirror';
		cmEl.CodeMirror = { save };
		document.getElementById('ffc_pdf_layout').after(cmEl);

		window.FFC.VerticalTabs.init();
		const ev = window.$.Event('submit');
		window.$('#post').trigger(ev);

		expect(save).toHaveBeenCalled();
		expect(ev.isDefaultPrevented()).toBe(false);
	});

	it('still banners on the Layout tab when the layout panel is missing', () => {
		// Required-tag config + form + textarea, but no #ffc-tabpanel-layout
		// panel → showRequiredWarning takes its early-return; submit still blocks.
		document.body.innerHTML =
			`<form id="post"><div class="ffc-vtabs" data-ffc-vtabs>` +
			`<ul class="ffc-vtabs__nav" role="tablist">` +
			`<li><a id="ffc-tabnav-email" class="ffc-vtabs__tab is-active" role="tab" aria-controls="ffc-tabpanel-email" aria-selected="true" tabindex="0">E</a></li>` +
			`</ul><div class="ffc-vtabs__panels">` +
			`<section id="ffc-tabpanel-email" class="ffc-vtabs__panel is-active" role="tabpanel">` +
			`<textarea id="ffc_pdf_layout">{{auth_code}} only</textarea></section>` +
			`</div></div></form>`;
		window.FFC.VerticalTabs.init();

		const ev = window.$.Event('submit');
		window.$('#post').trigger(ev);

		// Missing tags → submit blocked even though no banner could be placed.
		expect(ev.isDefaultPrevented()).toBe(true);
		expect(window.$('.ffc-vtabs__required-warning').length).toBe(0);
	});
});

describe('FFC.VerticalTabs — feature state dots (#1614)', () => {
	function withMasters() {
		buildTabs();
		const nav = document.querySelector('.ffc-vtabs__nav');
		nav.setAttribute('data-ffc-state-on', 'Ligado');
		nav.setAttribute('data-ffc-state-off', 'Desligado');
		document.getElementById('ffc-tabnav-email').setAttribute('data-ffc-tab-masters', 'm-user m-admin');
		document.getElementById('ffc-tabnav-geolocation').setAttribute('data-ffc-tab-masters', 'm-geo m-missing');
		panel('email').append(
			Object.assign(document.createElement('input'), { type: 'checkbox', id: 'm-user' }),
			Object.assign(document.createElement('input'), { type: 'checkbox', id: 'm-admin', checked: true })
		);
		panel('geolocation').append(Object.assign(document.createElement('input'), { type: 'checkbox', id: 'm-geo' }));
		window.FFC.VerticalTabs.init();
	}

	it('draws a dot only on tabs that name a master, on when any master is checked', () => {
		withMasters();
		expect(tab('layout').find('.ffc-vtabs__state').length).toBe(0);
		expect(tab('email').hasClass('is-on')).toBe(true);
		expect(tab('email').find('.screen-reader-text').text()).toBe(' (Ligado)');
		expect(tab('geolocation').hasClass('is-off')).toBe(true);
		expect(tab('geolocation').find('.screen-reader-text').text()).toBe(' (Desligado)');
	});

	it('follows the masters live', () => {
		withMasters();
		window.$('#m-admin').prop('checked', false).trigger('change');
		expect(tab('email').hasClass('is-off')).toBe(true);
		window.$('#m-geo').prop('checked', true).trigger('change');
		expect(tab('geolocation').hasClass('is-on')).toBe(true);
		expect(tab('geolocation').find('.screen-reader-text').text()).toBe(' (Ligado)');
	});

	it('does not draw a second dot when init runs again', () => {
		withMasters();
		window.FFC.VerticalTabs.init();
		expect(tab('email').find('.ffc-vtabs__state').length).toBe(1);
	});
});

describe('FFC.VerticalTabs — pending state (#1755)', () => {
	const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

	function withRequirement() {
		buildTabs();
		const nav = document.querySelector('.ffc-vtabs__nav');
		nav.setAttribute('data-ffc-state-pending', 'Incompleto');
		document.getElementById('ffc-tabnav-email').setAttribute('data-ffc-tab-masters', 'm-pass');
		const master = Object.assign(document.createElement('input'), { type: 'checkbox', id: 'm-pass', checked: true });
		master.setAttribute('data-ffc-requires', '#pass-value');
		const field = Object.assign(document.createElement('input'), { type: 'text', id: 'pass-value' });
		panel('email').append(master, field);
		window.FFC.VerticalTabs.init();
	}

	it('reads as pending while a checked toggle lacks its field, and on once it is filled', async () => {
		withRequirement();
		expect(tab('email').hasClass('is-pending')).toBe(true);
		expect(tab('email').hasClass('is-on')).toBe(false);
		expect(tab('email').find('.screen-reader-text').text()).toBe(' (Incompleto)');

		window.$('#pass-value').val('PASS2025').trigger('input');
		await tick();
		expect(tab('email').hasClass('is-pending')).toBe(false);
		expect(tab('email').hasClass('is-on')).toBe(true);
	});

	it('ignores a requirement of a toggle that is off', () => {
		withRequirement();
		window.$('#m-pass').prop('checked', false).trigger('change');
		expect(tab('email').hasClass('is-off')).toBe(true);
	});

	it('does not count a filled field inside an .ffc-hidden block', async () => {
		withRequirement();
		const hidden = document.createElement('div');
		hidden.className = 'ffc-hidden';
		hidden.append(Object.assign(document.createElement('textarea'), { className: 'other', value: 'x' }));
		panel('email').append(hidden);
		document.getElementById('m-pass').setAttribute('data-ffc-requires', '#pass-value, .other');
		window.$('#m-pass').trigger('change');
		await tick();
		expect(tab('email').hasClass('is-pending')).toBe(true);
	});

	it('treats a requirement as a selector, never as markup', () => {
		buildTabs();
		document.getElementById('ffc-tabnav-email').setAttribute('data-ffc-tab-masters', 'm-x');
		const master = Object.assign(document.createElement('input'), { type: 'checkbox', id: 'm-x', checked: true });
		master.setAttribute('data-ffc-requires', '<img id="injected" src="x">');
		panel('email').append(master);
		window.FFC.VerticalTabs.init();
		expect(document.getElementById('injected')).toBeNull();
		expect(tab('email').hasClass('is-pending')).toBe(true);
	});

	it('reads as pending on a tab need even with every toggle off', async () => {
		buildTabs();
		const time = document.getElementById('ffc-tabnav-geolocation');
		time.setAttribute('data-ffc-tab-masters', 'm-time');
		time.setAttribute('data-ffc-tab-needs', '#sched-start');
		panel('geolocation').append(
			Object.assign(document.createElement('input'), { type: 'checkbox', id: 'm-time' }),
			Object.assign(document.createElement('input'), { type: 'time', id: 'sched-start' })
		);
		window.FFC.VerticalTabs.init();
		expect(tab('geolocation').hasClass('is-pending')).toBe(true);

		window.$('#sched-start').val('09:00').trigger('change');
		await tick();
		expect(tab('geolocation').hasClass('is-off')).toBe(true);
	});
});

describe('FFC.VerticalTabs — a rejected control opens its tab (#1614)', () => {
	it('activates the inactive tab that holds an invalid control', () => {
		buildTabs();
		const input = Object.assign(document.createElement('input'), { required: true, id: 'needs-value' });
		panel('geolocation').append(input);
		window.FFC.VerticalTabs.init();
		expect(tab('layout').hasClass('is-active')).toBe(true);

		input.checkValidity();

		expect(tab('geolocation').hasClass('is-active')).toBe(true);
		expect(panel('geolocation').hasClass('is-active')).toBe(true);
	});
});

// The audience calendar editor (#1761): the same component, with one form
// spanning the first panels, a Save button outside it that names the form,
// and panels the server may open on something other than the first tab.
function buildAudienceCalendarEditor(activeKey = 'general') {
	const keys = ['general', 'notifications', 'event-list', 'closed-dates'];
	const nav = keys
		.map((key) => {
			const on = key === activeKey;
			return (
				`<li class="ffc-vtabs__nav-item" role="presentation"><a href="#ffc-tab-${key}" id="ffc-tabnav-${key}" ` +
				`class="ffc-vtabs__tab${on ? ' is-active' : ''}" role="tab" aria-controls="ffc-tabpanel-${key}" ` +
				`aria-selected="${on ? 'true' : 'false'}" tabindex="${on ? '0' : '-1'}">${key}</a></li>`
			);
		})
		.join('');
	const section = (key, body) =>
		`<section id="ffc-tabpanel-${key}" class="ffc-vtabs__panel${key === activeKey ? ' is-active' : ''}" role="tabpanel">${body}</section>`;
	document.body.innerHTML =
		`<div class="ffc-aud-editor"><div class="ffc-aud-editor__main">` +
		`<div class="ffc-aud-editor__tabs ffc-vtabs" data-ffc-vtabs><ul class="ffc-vtabs__nav">${nav}</ul>` +
		`<div class="ffc-vtabs__panels">` +
		`<form id="ffc-schedule-form" method="post" action="">` +
		section('general', '<input name="schedule_name" id="schedule_name" required>') +
		section('notifications', '<input name="schedule_admin_emails" id="schedule_admin_emails">') +
		section('event-list', '<input type="number" name="schedule_future_days" id="schedule_future_days" min="1" max="365">') +
		`</form>` +
		section('closed-dates', '<form class="ffc-holiday-form"><input type="date" name="holiday_date" required></form>') +
		`</div></div></div>` +
		`<div class="ffc-aud-editor__side"><div class="card"><input type="submit" name="submit" form="ffc-schedule-form" value="Update Calendar"></div></div>` +
		`</div>`;
}

describe('FFC.VerticalTabs — audience editors (#1761)', () => {
	it('opens the tab the server marked active when there is no hash', () => {
		buildAudienceCalendarEditor('closed-dates');
		window.FFC.VerticalTabs.init();

		expect(tab('closed-dates').attr('aria-selected')).toBe('true');
		expect(panel('closed-dates').hasClass('is-active')).toBe(true);
		expect(panel('general').hasClass('is-active')).toBe(false);
	});

	it('still lets a deep-link hash win over the server-marked tab', () => {
		buildAudienceCalendarEditor('closed-dates');
		window.history.replaceState(null, '', '#ffc-tab-notifications');
		window.FFC.VerticalTabs.init();

		expect(panel('notifications').hasClass('is-active')).toBe(true);
		expect(panel('closed-dates').hasClass('is-active')).toBe(false);
	});

	it('switches between panels inside and outside the one form', () => {
		buildAudienceCalendarEditor();
		window.FFC.VerticalTabs.init();

		tab('closed-dates').trigger('click');
		expect(panel('closed-dates').hasClass('is-active')).toBe(true);
		expect(panel('general').hasClass('is-active')).toBe(false);
		expect(window.location.hash).toBe('#ffc-tab-closed-dates');

		tab('event-list').trigger('click');
		expect(panel('event-list').hasClass('is-active')).toBe(true);
		expect(panel('closed-dates').hasClass('is-active')).toBe(false);
	});

	it('keeps every field of the form in it whichever tab is open, so one Save posts them all', () => {
		buildAudienceCalendarEditor();
		window.FFC.VerticalTabs.init();
		tab('closed-dates').trigger('click');

		const form = document.getElementById('ffc-schedule-form');
		const names = Array.from(new FormData(form).keys());
		expect(names).toEqual(['schedule_name', 'schedule_admin_emails', 'schedule_future_days']);
		const save = document.querySelector('input[name="submit"]');
		expect(save.form).toBe(form);
	});

	it('reveals the hidden tab of an invalid field when the side Save submits the form', () => {
		buildAudienceCalendarEditor();
		window.FFC.VerticalTabs.init();
		window.$('#schedule_name').val('Cal');
		window.$('#schedule_future_days').val('999');
		tab('closed-dates').trigger('click');

		// What the browser does when the side button submits the form it names.
		const valid = document.getElementById('ffc-schedule-form').checkValidity();

		expect(valid).toBe(false);
		expect(panel('event-list').hasClass('is-active')).toBe(true);
		expect(tab('event-list').attr('aria-selected')).toBe('true');
	});

	it('re-initialises a TinyMCE editor that came up without a height in a hidden panel', () => {
		buildAudienceCalendarEditor();
		panel('notifications').append(
			Object.assign(document.createElement('textarea'), { id: 'ffc_termo_12', className: 'wp-editor-area' })
		);
		const container = document.createElement('div');
		const editor = { isHidden: () => false, getContainer: () => container };
		const execCommand = vi.fn();
		window.tinymce = { get: vi.fn((id) => (id === 'ffc_termo_12' ? editor : null)), execCommand };
		try {
			window.FFC.VerticalTabs.init();
			expect(execCommand).not.toHaveBeenCalled();

			tab('notifications').trigger('click');

			// jsdom has no layout, so the container measures 0 like a hidden one.
			expect(execCommand.mock.calls).toEqual([
				['mceRemoveEditor', false, 'ffc_termo_12'],
				['mceAddEditor', false, 'ffc_termo_12'],
			]);
		} finally {
			delete window.tinymce;
		}
	});

	it('leaves a TinyMCE editor alone when it is in text mode or already measured', () => {
		buildAudienceCalendarEditor();
		panel('notifications').append(
			Object.assign(document.createElement('textarea'), { id: 'a', className: 'wp-editor-area' }),
			Object.assign(document.createElement('textarea'), { id: 'b', className: 'wp-editor-area' })
		);
		const measured = document.createElement('div');
		Object.defineProperty(measured, 'offsetHeight', { value: 300 });
		const editors = {
			a: { isHidden: () => true, getContainer: () => document.createElement('div') },
			b: { isHidden: () => false, getContainer: () => measured },
		};
		const execCommand = vi.fn();
		window.tinymce = { get: (id) => editors[id], execCommand };
		try {
			window.FFC.VerticalTabs.init();
			tab('notifications').trigger('click');
			expect(execCommand).not.toHaveBeenCalled();
		} finally {
			delete window.tinymce;
		}
	});
});
