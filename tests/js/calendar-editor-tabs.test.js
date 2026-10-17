// The personal calendar editor in vertical tabs (#1761).
//
// The editor reuses the shared vertical-tab component
// (`ffc-vertical-tabs.js`), so these tests drive the three scripts the
// screen loads together -- the tabs, the collapsible sections the Emails tab
// still holds, and the calendar editor's own behaviours -- over markup in the
// shape `SelfSchedulingEditor::render_box_config()` prints through
// `AdminUI::render_vertical_tabs()`. What they hold is that moving the fields
// into hidden panels broke nothing: switching tabs, the state dots, a
// rejected field opening its tab, and the show/hide rules that now run on
// fields the operator may not be looking at.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

const TABS = [
	{ key: 'calendar', label: 'Calendar' },
	{ key: 'slots', label: 'Slots & hours' },
	{ key: 'window', label: 'Booking window' },
	{ key: 'cancellation', label: 'Cancellation', masters: 'allow_cancellation' },
	{ key: 'approval', label: 'Approval & waitlist' },
	{ key: 'access', label: 'Who can see and book' },
	{ key: 'bypass', label: 'Administrator bypass', masters: 'admin_bypass' },
	{ key: 'emails', label: 'Emails' },
];

const BODIES = {
	calendar: `<table class="form-table"><tr><td>
		<select id="calendar_status" name="ffc_self_scheduling_config[status]"><option value="active" selected>Active</option></select>
	</td></tr></table>`,
	slots: `<table class="form-table">
		<tr><td>
			<input type="radio" name="ffc_self_scheduling_config[schedule_type]" value="regular" class="ffc-schedule-type-radio" checked />
			<input type="radio" name="ffc_self_scheduling_config[schedule_type]" value="custom" class="ffc-schedule-type-radio" />
		</td></tr>
		<tr class="ffc-regular-only"><td><input type="number" id="slot_duration" name="ffc_self_scheduling_config[slot_duration]" value="30" min="5" max="480" required /></td></tr>
	</table>
	<div id="ffc-working-hours-wrapper" class="ffc-regular-only">
		<table><tbody id="ffc-working-hours-list"><tr>
			<td><input type="time" name="ffc_self_scheduling_working_hours[0][start]" value="09:00" required /></td>
		</tr></tbody></table>
	</div>
	<div id="ffc-custom-slots-wrapper" class="ffc-custom-only">
		<table><tbody id="ffc-custom-slots-list"></tbody></table>
		<p><button type="button" class="button ffc-icon-plus" id="ffc-add-custom-slot">Add Block</button></p>
	</div>`,
	window: `<table class="form-table"><tr><td>
		<input type="number" id="advance_booking_min" name="ffc_self_scheduling_config[advance_booking_min]" value="0" min="0" max="720" required />
	</td></tr></table>`,
	cancellation: `<table class="form-table">
		<tr><td><label class="ffc-control-toggle"><input type="checkbox" id="allow_cancellation" name="ffc_self_scheduling_config[allow_cancellation]" value="1" checked /></label></td></tr>
		<tr class="ffc-cancellation-hours"><td><input type="number" id="cancellation_min_hours" name="ffc_self_scheduling_config[cancellation_min_hours]" value="24" min="0" max="168" required /></td></tr>
	</table>`,
	approval: `<table class="form-table">
		<tr><td><input type="checkbox" id="waitlist_enabled" name="ffc_self_scheduling_config[waitlist_enabled]" value="1" /></td></tr>
		<tr class="ffc-waitlist-capacity" style="display:none;"><td><input type="number" id="waitlist_capacity" name="ffc_self_scheduling_config[waitlist_capacity]" value="0" min="0" max="9999" required /></td></tr>
	</table>`,
	access: `<table class="form-table"><tr><td>
		<select id="ffc_visibility" name="ffc_self_scheduling_config[visibility]"><option value="public" selected>Public</option><option value="private">Private</option></select>
		<select id="ffc_scheduling_visibility" name="ffc_self_scheduling_config[scheduling_visibility]"><option value="public" selected>Public</option><option value="private">Private</option></select>
		<p class="description" id="ffc-scheduling-desc">Public: anyone can book.</p>
	</td></tr></table>`,
	bypass: `<table class="form-table"><tr><td>
		<input type="checkbox" id="admin_bypass" name="ffc_self_scheduling_config[admin_bypass]" value="1" />
	</td></tr></table>`,
	emails: `<div class="ffc-sections">
		<details class="ffc-section" data-ffc-section><summary class="ffc-section__summary">Which emails are sent</summary>
			<div class="ffc-section__body">
				<input type="number" id="reminder_hours_before" name="ffc_self_scheduling_email_config[reminder_hours_before]" value="24" min="1" max="168" required />
			</div>
		</details>
	</div>`,
};

// The markup AdminUI::render_vertical_tabs() prints.
function buildEditor() {
	const nav = TABS.map((t, i) => {
		const active = i === 0;
		return (
			`<li class="ffc-vtabs__nav-item" role="presentation">` +
			`<a href="#ffc-tab-${t.key}" id="ffc-tabnav-${t.key}" class="ffc-vtabs__tab${active ? ' is-active' : ''}" role="tab" ` +
			`aria-controls="ffc-tabpanel-${t.key}" aria-selected="${active}" tabindex="${active ? 0 : -1}"` +
			(t.masters ? ` data-ffc-tab-masters="${t.masters}"` : '') +
			`><span class="ffc-vtabs__label">${t.label}</span></a></li>`
		);
	}).join('');
	const panels = TABS.map((t, i) =>
		`<section id="ffc-tabpanel-${t.key}" class="ffc-vtabs__panel${i === 0 ? ' is-active' : ''}" role="tabpanel" aria-labelledby="ffc-tabnav-${t.key}" tabindex="0">` +
		`<h2 class="ffc-vtabs__panel-title">${t.label}</h2>${BODIES[t.key]}</section>`
	).join('');

	return (
		`<form id="post"><input type="hidden" name="ffc_self_scheduling_config_nonce" value="n" />` +
		`<div class="ffc-vtabs" data-ffc-vtabs>` +
		`<ul class="ffc-vtabs__nav" role="tablist" aria-orientation="vertical" data-ffc-state-on="On" data-ffc-state-off="Off" data-ffc-state-pending="Incomplete">${nav}</ul>` +
		`<div class="ffc-vtabs__panels">${panels}</div></div></form>`
	);
}

const $ = (sel) => window.$(sel);

beforeAll(async () => {
	window.ffcSelfSchedulingEditor = {
		strings: {
			remove: 'Remove',
			schedulingForced: 'Forced to Private because Visibility is Private.',
			schedulingDesc: 'Public: anyone can book. Private: only logged-in users can book.',
		},
	};
	window.ffc_ajax = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'n', strings: {} };
	window.history.replaceState(null, '', window.location.pathname);
	document.body.innerHTML = buildEditor();
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-vertical-tabs.js');
	loadScript('assets/js/ffc-admin-sections.js');
	loadScript('assets/js/ffc-calendar-editor.js');
	await new Promise((r) => setTimeout(r, 0));
});

beforeEach(() => {
	$('#ffc-tabnav-calendar').trigger('click');
});

describe('the calendar editor in vertical tabs (#1761)', () => {
	it('starts on the Calendar tab with the panels hidden behind it', () => {
		expect($('.ffc-vtabs').hasClass('is-ready')).toBe(true);
		expect($('#ffc-tabpanel-calendar').hasClass('is-active')).toBe(true);
		expect($('.ffc-vtabs__panel.is-active')).toHaveLength(1);
	});

	it('switches to the tab that is clicked', () => {
		$('#ffc-tabnav-window').trigger('click');

		expect($('#ffc-tabnav-window').attr('aria-selected')).toBe('true');
		expect($('#ffc-tabpanel-window').hasClass('is-active')).toBe(true);
		expect($('#ffc-tabpanel-calendar').hasClass('is-active')).toBe(false);
		expect($('#ffc-tabnav-calendar').attr('tabindex')).toBe('-1');
	});

	it('draws a dot on Cancellation and Administrator bypass only, following their toggles', () => {
		expect($('.ffc-vtabs__state')).toHaveLength(2);
		expect($('#ffc-tabnav-cancellation').hasClass('is-on')).toBe(true);
		expect($('#ffc-tabnav-bypass').hasClass('is-off')).toBe(true);

		$('#admin_bypass').prop('checked', true).trigger('change');
		expect($('#ffc-tabnav-bypass').hasClass('is-on')).toBe(true);
		$('#admin_bypass').prop('checked', false).trigger('change');
	});

	it('still hides the deadline and drops its required while its tab is not open', () => {
		$('#allow_cancellation').prop('checked', false).trigger('change');

		expect($('.ffc-cancellation-hours').css('display')).toBe('none');
		expect($('#cancellation_min_hours').prop('required')).toBe(false);
		expect($('#ffc-tabnav-cancellation').hasClass('is-off')).toBe(true);

		$('#allow_cancellation').prop('checked', true).trigger('change');
		expect($('#cancellation_min_hours').prop('required')).toBe(true);
		expect($('#ffc-tabnav-cancellation').hasClass('is-on')).toBe(true);
	});

	it('still shows the waitlist capacity from another tab', () => {
		$('#waitlist_enabled').prop('checked', true).trigger('change');
		expect($('.ffc-waitlist-capacity').css('display')).not.toBe('none');
		$('#waitlist_enabled').prop('checked', false).trigger('change');
		expect($('.ffc-waitlist-capacity').css('display')).toBe('none');
	});

	it('still forces private scheduling when the calendar is private', () => {
		$('#ffc_visibility').val('private').trigger('change');
		expect($('#ffc_scheduling_visibility').val()).toBe('private');
		expect($('#ffc_scheduling_visibility').prop('disabled')).toBe(true);
		$('#ffc_visibility').val('public').trigger('change');
		expect($('#ffc_scheduling_visibility').prop('disabled')).toBe(false);
	});

	it('still switches the regular and custom fields, and their required, from a hidden tab', () => {
		$('.ffc-schedule-type-radio[value="custom"]').prop('checked', true).trigger('change');

		expect($('#ffc-working-hours-wrapper').css('display')).toBe('none');
		expect($('#slot_duration').prop('required')).toBe(false);
		expect($('#ffc-custom-slots-wrapper').css('display')).not.toBe('none');

		$('#ffc-add-custom-slot').trigger('click');
		expect($('#ffc-custom-slots-list tr')).toHaveLength(1);

		$('.ffc-schedule-type-radio[value="regular"]').prop('checked', true).trigger('change');
		expect($('#slot_duration').prop('required')).toBe(true);
	});

	it('opens the tab holding a field the browser rejects on submit', () => {
		const field = document.getElementById('advance_booking_min');
		field.value = '';

		expect(field.checkValidity()).toBe(false);

		expect($('#ffc-tabpanel-window').hasClass('is-active')).toBe(true);
		expect($('#ffc-tabnav-window').attr('aria-selected')).toBe('true');
		field.value = '0';
	});

	it('opens both the Emails tab and the closed section around a rejected field', () => {
		const field = document.getElementById('reminder_hours_before');
		const section = field.closest('details');
		section.open = false;
		field.value = '';

		field.checkValidity();

		expect($('#ffc-tabpanel-emails').hasClass('is-active')).toBe(true);
		expect(section.open).toBe(true);
		field.value = '24';
	});
});
