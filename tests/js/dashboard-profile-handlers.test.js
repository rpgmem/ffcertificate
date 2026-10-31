// Deep coverage for `assets/js/ffc-user-dashboard-profile.js` — covers
// the form-save, password-change, LGPD privacy, and notification-
// preferences flows left out of #168 S4 (which only covered the
// read-view render).
//
// Pattern: render the panel with a fixture profile, simulate user
// interactions via DOM events (jQuery dispatches them as the
// document-level handlers expect), mock `$.ajax` to capture the
// request and drive the success/error branches.
//
// Sprint I of #173.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { installDashboardFixtures, loadDashboardCore, loadPanel } from './dashboard-fixtures.js';

beforeAll(() => {
	installDashboardFixtures();
	Object.assign(window.ffcDashboard.strings, {
		// Profile-form strings.
		editProfile: 'Edit Profile',
		save: 'Save',
		cancel: 'Cancel',
		saving: 'Saving…',
		saveError: 'Error saving profile',
		// Password strings.
		changePassword: 'Change Password',
		currentPassword: 'Current Password',
		newPassword: 'New Password',
		confirmPassword: 'Confirm Password',
		passwordError: 'All fields required',
		passwordMismatch: 'Passwords do not match',
		passwordTooShort: 'Min 8 characters',
		passwordChanged: 'Password changed!',
		// LGPD / notifications.
		exportRequested: 'Export requested',
		deletionRequested: 'Deletion requested',
		confirmDeletion: 'Are you sure?',
		notificationsSaved: 'Saved',
		// Profile fields.
		name: 'Name',
		phone: 'Phone:',
		department: 'Department:',
		organization: 'Organization:',
		notesLabel: 'Notes:',
		notesPlaceholder: 'Personal notes…',
		linkedEmails: 'Emails',
		cpfRf: 'CPF/RF',
		audienceGroups: 'Groups',
		leaveAllGroups: 'Leave all groups',
		memberSince: 'Member since:',
		logout: 'Log Out',
		preferences: 'Preferences',
		notifications: 'Notifications',
		lgpd: 'Privacy',
	});
	window.ffcDashboard.restUrl = 'https://x.test/wp-json/ffc/v1/';

	const dash = document.getElementById('ffc-dashboard');
	if (dash && ! document.getElementById('ffc-tabpanel-profile')) {
		const el = document.createElement('div');
		el.id = 'ffc-tabpanel-profile';
		el.className = 'ffc-tab-content';
		dash.appendChild(el);
	}
	loadDashboardCore();
	loadPanel('profile');
	// bindEvents() is normally called from FFCDashboard.init() — invoke
	// it manually so the document-level delegates land before the tests
	// click anything.
	window.FFCDashboard.panels.profile.bindEvents();
});

const PROFILE_FIXTURE = {
	display_name: 'Maria Silva',
	names: ['Maria Silva'],
	email: 'maria@example.com',
	emails: ['maria@example.com'],
	cpf_masked: '123.***.***-**',
	cpfs_masked: ['123.***.***-**'],
	phone: '11 99999-0000',
	department: 'TI',
	organization: 'ACME',
	notes: '',
	audience_groups: [],
	member_since: '01/01/2025',
	preferences: { newsletter: true, marketing: false },
};

beforeEach(() => {
	document.getElementById('ffc-tabpanel-profile').innerHTML = '';
	window.FFCDashboard.panels.profile.state = null;
});

afterEach(() => {
	vi.restoreAllMocks();
});

function panel() {
	return window.FFCDashboard.panels.profile;
}

function mockAjax(impl) {
	return vi.spyOn(window.$, 'ajax').mockImplementation(impl);
}

function mockAjaxSuccess(response) {
	return mockAjax((opts) => { if (opts.success) opts.success(response); return {}; });
}

function mockAjaxError(xhr) {
	return mockAjax((opts) => { if (opts.error) opts.error(xhr); return {}; });
}

// FFC.rest wraps the ajax call in a Promise; success/error fires
// synchronously inside the spy, but the .then/.catch reactions run on
// the microtask queue. Await two ticks so the panel-level handler has
// a chance to mutate state / DOM before the assertion runs.
function flush() {
	return Promise.resolve().then(() => Promise.resolve());
}

// ----------------------------------------------------------------------
// showEditForm via .ffc-profile-edit-btn click
// ----------------------------------------------------------------------

describe('profile.showEditForm', () => {
	it('replaces the read view with an edit form populated from state', () => {
		panel().render(PROFILE_FIXTURE);
		window.$('.ffc-profile-edit-btn').trigger('click');
		const form = document.querySelector('#ffc-tabpanel-profile .ffc-profile-edit-form');
		expect(form).not.toBeNull();
		expect(document.getElementById('ffc-edit-display-name').value).toBe('Maria Silva');
		expect(document.getElementById('ffc-edit-phone').value).toBe('(11) 99999-0000');
		expect(document.getElementById('ffc-edit-department').value).toBe('TI');
		expect(document.getElementById('ffc-edit-organization').value).toBe('ACME');
	});

	it('masks the phone as (DD) 98765-4321, eleven digits at most', () => {
		panel().render(PROFILE_FIXTURE);
		window.$('.ffc-profile-edit-btn').trigger('click');
		const $phone = window.$('#ffc-edit-phone');
		expect($phone.attr('maxlength')).toBe('15');
		$phone.val('1198765432199').trigger('input');
		expect($phone.val()).toBe('(11) 98765-4321');
	});

	it('shows a stored number longer than the mask as stored, not truncated', () => {
		panel().render({ ...PROFILE_FIXTURE, phone: '+55 11 98765-4321' });
		window.$('.ffc-profile-edit-btn').trigger('click');
		expect(document.getElementById('ffc-edit-phone').value).toBe('+55 11 98765-4321');
	});

	it("does nothing when state is null (defensive — shouldn't happen at runtime)", () => {
		panel().state = null;
		document.getElementById('ffc-tabpanel-profile').innerHTML = '<button class="ffc-profile-edit-btn">Edit</button>';
		window.$('.ffc-profile-edit-btn').trigger('click');
		expect(document.querySelector('#ffc-tabpanel-profile .ffc-profile-edit-form')).toBeNull();
	});

	it('cancel button re-renders the read view from the preserved state', () => {
		panel().render(PROFILE_FIXTURE);
		window.$('.ffc-profile-edit-btn').trigger('click');
		expect(document.querySelector('#ffc-tabpanel-profile .ffc-profile-edit-form')).not.toBeNull();
		window.$('.ffc-profile-cancel-btn').trigger('click');
		// Read view is back.
		expect(document.querySelector('#ffc-tabpanel-profile .ffc-profile-info')).not.toBeNull();
		expect(document.querySelector('#ffc-tabpanel-profile .ffc-profile-edit-form')).toBeNull();
	});
});

// ----------------------------------------------------------------------
// saveProfile — AJAX PUT + state refresh on success / error display
// ----------------------------------------------------------------------

describe('profile.saveProfile', () => {
	it('sends a PUT to user/profile with the form values as JSON', () => {
		panel().render(PROFILE_FIXTURE);
		window.$('.ffc-profile-edit-btn').trigger('click');
		// User edits a field.
		document.getElementById('ffc-edit-display-name').value = 'Maria S. Silva';

		const spy = mockAjaxSuccess({ ...PROFILE_FIXTURE, display_name: 'Maria S. Silva' });
		window.$('.ffc-profile-save-btn').trigger('click');

		expect(spy).toHaveBeenCalledOnce();
		const call = spy.mock.calls[0][0];
		expect(call.method).toBe('PUT');
		expect(call.url).toContain('user/profile');
		const payload = JSON.parse(call.data);
		expect(payload.display_name).toBe('Maria S. Silva');
		expect(payload.phone).toBe('(11) 99999-0000');
		expect(payload.department).toBe('TI');
		expect(payload.organization).toBe('ACME');
	});

	it('pre-fills the birth date input and sends it back (#1538)', () => {
		panel().render({ ...PROFILE_FIXTURE, birth_date: '1990-05-20', birth_date_display: '20/05/1990' });
		window.$('.ffc-profile-edit-btn').trigger('click');

		const input = document.getElementById('ffc-edit-birth-date');
		expect(input.type).toBe('date');
		expect(input.value).toBe('1990-05-20');

		input.value = '1991-06-21';
		const spy = mockAjaxSuccess(PROFILE_FIXTURE);
		window.$('.ffc-profile-save-btn').trigger('click');

		const payload = JSON.parse(spy.mock.calls[0][0].data);
		expect(payload.birth_date).toBe('1991-06-21');
	});

	it('sends an empty birth date when the field is cleared, so the server clears it', () => {
		panel().render({ ...PROFILE_FIXTURE, birth_date: '1990-05-20' });
		window.$('.ffc-profile-edit-btn').trigger('click');
		document.getElementById('ffc-edit-birth-date').value = '';

		const spy = mockAjaxSuccess(PROFILE_FIXTURE);
		window.$('.ffc-profile-save-btn').trigger('click');

		expect(JSON.parse(spy.mock.calls[0][0].data).birth_date).toBe('');
	});

	it('on success: replaces state and re-renders the read view', async () => {
		panel().render(PROFILE_FIXTURE);
		window.$('.ffc-profile-edit-btn').trigger('click');
		document.getElementById('ffc-edit-display-name').value = 'Updated';

		const updated = { ...PROFILE_FIXTURE, display_name: 'Updated' };
		mockAjaxSuccess(updated);
		window.$('.ffc-profile-save-btn').trigger('click');
		await flush();

		expect(panel().state).toEqual(updated);
		// Read view rendered (the .ffc-profile-info container).
		expect(document.querySelector('#ffc-tabpanel-profile .ffc-profile-info')).not.toBeNull();
	});

	it('on error: shows the server message and re-enables the save button', async () => {
		panel().render(PROFILE_FIXTURE);
		window.$('.ffc-profile-edit-btn').trigger('click');

		mockAjaxError({ responseJSON: { message: 'Custom server error' } });
		window.$('.ffc-profile-save-btn').trigger('click');
		await flush();

		const status = document.querySelector('.ffc-profile-save-status');
		expect(status.textContent).toBe('Custom server error');
		expect(document.querySelector('.ffc-profile-save-btn').disabled).toBe(false);
	});
});

// ----------------------------------------------------------------------
// changePassword — client-side validation + AJAX
// ----------------------------------------------------------------------

describe('profile.changePassword', () => {
	beforeEach(() => {
		// Render the read view so the password form fields exist
		// (#ffc-current-password etc. are inside .ffc-password-form).
		panel().render(PROFILE_FIXTURE);
		// The form is open when the user saves; a hidden control cannot take
		// the focus (jsdom 30.1.2 enforces it, as browsers do).
		window.$('#ffc-tabpanel-profile .ffc-password-form').show();
	});

	function setPasswords(current, neu, conf) {
		document.getElementById('ffc-current-password').value = current;
		document.getElementById('ffc-new-password').value = neu;
		document.getElementById('ffc-confirm-password').value = conf;
	}

	// Each rule is said under its field, in the field-message standard
	// (#1844); the status line is left for the server's answer.
	function messageOf(id) {
		const field = document.getElementById(id);
		const msgId = field.getAttribute('data-ffc-message-id');
		return msgId ? document.getElementById(msgId) : null;
	}

	it('rejects when any field is blank (no AJAX call), under each blank field', () => {
		const spy = mockAjax(() => ({}));
		setPasswords('', '', '');
		window.$('.ffc-password-save-btn').trigger('click');
		expect(spy).not.toHaveBeenCalled();
		['ffc-current-password', 'ffc-new-password', 'ffc-confirm-password'].forEach((id) => {
			expect(messageOf(id).textContent).toBe('This field is required.');
			expect(document.getElementById(id).getAttribute('aria-invalid')).toBe('true');
		});
		expect(document.querySelector('.ffc-password-status').textContent).toBe('');
		expect(document.activeElement.id).toBe('ffc-current-password');
	});

	it('rejects when new + confirm mismatch, under the confirmation', () => {
		const spy = mockAjax(() => ({}));
		setPasswords('old', 'abcdefgh', 'abcdefgi');
		window.$('.ffc-password-save-btn').trigger('click');
		expect(spy).not.toHaveBeenCalled();
		expect(messageOf('ffc-confirm-password').textContent).toBe('Passwords do not match');
		expect(messageOf('ffc-new-password')).toBeNull();
	});

	it('rejects when new password is shorter than 8 chars, under the new password', () => {
		const spy = mockAjax(() => ({}));
		setPasswords('old', 'short', 'short');
		window.$('.ffc-password-save-btn').trigger('click');
		expect(spy).not.toHaveBeenCalled();
		expect(messageOf('ffc-new-password').textContent).toBe('Min 8 characters');
	});

	it('marks the three fields required, with the one marker', () => {
		['ffc-current-password', 'ffc-new-password', 'ffc-confirm-password'].forEach((id) => {
			expect(document.getElementById(id).required).toBe(true);
			const marker = document.querySelector('label[for="' + id + '"] .ffc-required');
			expect(marker.getAttribute('aria-hidden')).toBe('true');
		});
	});

	it('on success: clears the password fields and shows the response message', async () => {
		const spy = mockAjaxSuccess({ message: 'Done!' });
		setPasswords('oldpass', 'newpass123', 'newpass123');
		window.$('.ffc-password-save-btn').trigger('click');
		await flush();

		expect(spy).toHaveBeenCalledOnce();
		const call = spy.mock.calls[0][0];
		expect(call.method).toBe('POST');
		expect(call.url).toContain('user/change-password');
		const payload = JSON.parse(call.data);
		expect(payload.current_password).toBe('oldpass');
		expect(payload.new_password).toBe('newpass123');

		expect(document.querySelector('.ffc-password-status').textContent).toBe('Done!');
		expect(document.getElementById('ffc-current-password').value).toBe('');
		expect(document.getElementById('ffc-new-password').value).toBe('');
		expect(document.getElementById('ffc-confirm-password').value).toBe('');
	});

	it('on error: surfaces the server message', async () => {
		mockAjaxError({ responseJSON: { message: 'Wrong current password' } });
		setPasswords('badpass', 'newpass123', 'newpass123');
		window.$('.ffc-password-save-btn').trigger('click');
		await flush();

		expect(document.querySelector('.ffc-password-status').textContent).toBe('Wrong current password');
	});
});

// ----------------------------------------------------------------------
// privacyRequest — LGPD export + delete (with confirm)
// ----------------------------------------------------------------------

describe('profile.privacyRequest', () => {
	beforeEach(() => {
		panel().render(PROFILE_FIXTURE);
	});

	it('Export button POSTs without asking for confirmation', () => {
		const spy = mockAjaxSuccess({ ok: true });
		const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);

		// The LGPD section is rendered if the panel template includes it
		// — render() emits it conditionally so the button may or may not
		// be there. Check first whether it exists; if not, inject it.
		if (! document.querySelector('.ffc-lgpd-export-btn')) {
			document.getElementById('ffc-tabpanel-profile').insertAdjacentHTML(
				'beforeend',
				'<button class="ffc-lgpd-export-btn"></button><span class="ffc-lgpd-status"></span>'
			);
		}
		window.$('.ffc-lgpd-export-btn').trigger('click');

		expect(confirmSpy).not.toHaveBeenCalled(); // export skips confirm.
		expect(spy).toHaveBeenCalledOnce();
		const call = spy.mock.calls[0][0];
		expect(call.method).toBe('POST');
		expect(JSON.parse(call.data).type).toBe('export_personal_data');
	});

	it('Delete button asks for confirmation; cancels if user declines', () => {
		const spy = mockAjax(() => ({}));
		vi.spyOn(window, 'confirm').mockReturnValue(false);

		if (! document.querySelector('.ffc-lgpd-delete-btn')) {
			document.getElementById('ffc-tabpanel-profile').insertAdjacentHTML(
				'beforeend',
				'<button class="ffc-lgpd-delete-btn"></button><span class="ffc-lgpd-status"></span>'
			);
		}
		window.$('.ffc-lgpd-delete-btn').trigger('click');

		expect(spy).not.toHaveBeenCalled();
	});

	it('Delete button proceeds when confirmation is accepted', () => {
		const spy = mockAjaxSuccess({ ok: true });
		vi.spyOn(window, 'confirm').mockReturnValue(true);

		if (! document.querySelector('.ffc-lgpd-delete-btn')) {
			document.getElementById('ffc-tabpanel-profile').insertAdjacentHTML(
				'beforeend',
				'<button class="ffc-lgpd-delete-btn"></button><span class="ffc-lgpd-status"></span>'
			);
		}
		window.$('.ffc-lgpd-delete-btn').trigger('click');

		expect(spy).toHaveBeenCalledOnce();
		expect(JSON.parse(spy.mock.calls[0][0].data).type).toBe('remove_personal_data');
	});
});

// ----------------------------------------------------------------------
// saveNotificationPreferences — toggle change triggers AJAX
// ----------------------------------------------------------------------

describe('profile.saveNotificationPreferences', () => {
	it('fires AJAX on .ffc-notif-toggle change and updates state.preferences from the response', async () => {
		panel().render(PROFILE_FIXTURE);

		// Inject a notification toggle if the rendered profile didn't
		// include one (template logic varies by preference shape).
		if (! document.querySelector('.ffc-notif-toggle')) {
			document.getElementById('ffc-tabpanel-profile').insertAdjacentHTML(
				'beforeend',
				'<input type="checkbox" class="ffc-notif-toggle" data-key="newsletter" /><span class="ffc-notif-status"></span>'
			);
		}

		const spy = mockAjaxSuccess({ preferences: { newsletter: false, marketing: false } });
		const toggle = document.querySelector('.ffc-notif-toggle');
		toggle.checked = false;
		window.$(toggle).trigger('change');
		await flush();

		expect(spy).toHaveBeenCalledOnce();
		expect(panel().state.preferences.newsletter).toBe(false);
	});

	it('on AJAX error: shows the saveError status', async () => {
		Object.assign(window.ffcDashboard.strings, { saveError: 'Save failed' });
		panel().render(PROFILE_FIXTURE);
		if (! document.querySelector('.ffc-notif-toggle')) {
			document.getElementById('ffc-tabpanel-profile').insertAdjacentHTML(
				'beforeend',
				'<input type="checkbox" class="ffc-notif-toggle" data-key="newsletter" /><span class="ffc-notif-status"></span>'
			);
		}
		mockAjaxError({});
		window.$(document.querySelector('.ffc-notif-toggle')).trigger('change');
		await flush();

		expect(document.querySelector('.ffc-notif-status').textContent).toBe('Save failed');
	});

	it('on success: shows the saved notice then schedules a fadeOut', async () => {
		window.$.fx.off = true;
		Object.assign(window.ffcDashboard.strings, { notifSaved: 'Saved!' });
		panel().render(PROFILE_FIXTURE);
		if (! document.querySelector('.ffc-notif-toggle')) {
			document.getElementById('ffc-tabpanel-profile').insertAdjacentHTML(
				'beforeend',
				'<input type="checkbox" class="ffc-notif-toggle" data-key="newsletter" /><span class="ffc-notif-status"></span>'
			);
		}
		// Drive the success branch under fake timers so the
		// setTimeout(() => $status.fadeOut(), 2000) scheduled inside it can be
		// advanced (with fx.off the fade is instantaneous → display:none).
		vi.useFakeTimers();
		try {
			// $.ajax fires opts.success synchronously inside the spy; the
			// FFC.rest promise resolves on the microtask queue, which the
			// fake-timer clock still drains via the awaited flush below.
			mockAjax((opts) => { if (opts.success) opts.success({ preferences: { newsletter: true } }); return {}; });
			window.$(document.querySelector('.ffc-notif-toggle')).trigger('change');
			// Microtasks (promise .then) still drain under fake timers.
			await flush();
			expect(document.querySelector('.ffc-notif-status').textContent).toBe('Saved!');
			vi.advanceTimersByTime(2100);
			expect(window.$('.ffc-notif-status').css('display')).toBe('none');
		} finally {
			vi.useRealTimers();
		}
	});
});

// ----------------------------------------------------------------------
// renderListField — multi-value / single / fallback branches
// ----------------------------------------------------------------------

describe('profile.render — multi-value list fields', () => {
	it('renders a <ul> when a field has more than one value', () => {
		panel().render({
			...PROFILE_FIXTURE,
			names: ['Maria Silva', 'Maria S.'],
			emails: ['a@x.test', 'b@x.test'],
			cpfs_masked: ['111.***', '222.***'],
		});
		expect(document.querySelectorAll('#ffc-tabpanel-profile ul.ffc-name-list li').length).toBe(2);
		expect(document.querySelectorAll('#ffc-tabpanel-profile ul.ffc-email-list li').length).toBe(2);
		expect(document.querySelectorAll('#ffc-tabpanel-profile ul.ffc-cpf-list li').length).toBe(2);
	});

	it('renders the fallback value when a list field is empty', () => {
		panel().render({
			...PROFILE_FIXTURE,
			names: [],
			display_name: 'Fallback Name',
			emails: [],
			email: 'fallback@x.test',
			cpfs_masked: [],
			cpf_masked: '999.***',
		});
		const text = document.querySelector('#ffc-tabpanel-profile .ffc-profile-info').textContent;
		expect(text).toContain('Fallback Name');
		expect(text).toContain('fallback@x.test');
		expect(text).toContain('999.***');
	});
});

// ----------------------------------------------------------------------
// load() / reload() — REST fetch + cache guard + viewAsUserId
// ----------------------------------------------------------------------

describe('profile.load / reload', () => {
	afterEach(() => {
		delete window.ffcDashboard.viewAsUserId;
	});

	it('load() fetches the profile and renders it', async () => {
		mockAjaxSuccess(PROFILE_FIXTURE);
		panel().load();
		await flush();
		expect(document.querySelector('#ffc-tabpanel-profile .ffc-profile-info')).not.toBeNull();
	});

	it('load() short-circuits when .ffc-profile-info is already present', async () => {
		panel().render(PROFILE_FIXTURE);
		const spy = mockAjax(() => ({}));
		panel().load();
		await flush();
		expect(spy).not.toHaveBeenCalled();
	});

	it('load() shows the error notice when the request fails', async () => {
		Object.assign(window.ffcDashboard.strings, { error: 'Error' });
		mockAjaxError({});
		panel().load();
		await flush();
		expect(document.getElementById('ffc-tabpanel-profile').innerHTML).toContain('Error');
	});

	it('load() appends viewAsUserId to the request URL when impersonating', async () => {
		window.ffcDashboard.viewAsUserId = 88;
		const spy = mockAjax(() => ({}));
		panel().load();
		await flush();
		expect(spy.mock.calls[0][0].url).toContain('?viewAsUserId=88');
	});

	it('reload() clears state, re-fetches (bypassing the cache guard), and renders', async () => {
		panel().render(PROFILE_FIXTURE);
		mockAjaxSuccess(PROFILE_FIXTURE);
		panel().reload();
		await flush();
		expect(panel().state).toEqual(PROFILE_FIXTURE);
		expect(document.querySelector('#ffc-tabpanel-profile .ffc-profile-info')).not.toBeNull();
	});

	it('reload() shows the error notice when the request fails', async () => {
		Object.assign(window.ffcDashboard.strings, { error: 'Error' });
		mockAjaxError({});
		panel().reload();
		await flush();
		expect(document.getElementById('ffc-tabpanel-profile').innerHTML).toContain('Error');
	});

	it('reload() appends viewAsUserId when impersonating', async () => {
		window.ffcDashboard.viewAsUserId = 5;
		const spy = mockAjax(() => ({}));
		panel().reload();
		await flush();
		expect(spy.mock.calls[0][0].url).toContain('?viewAsUserId=5');
	});
});

// ----------------------------------------------------------------------
// viewAsUserId propagation on save / password / privacy / notif
// ----------------------------------------------------------------------

describe('profile — viewAsUserId propagation', () => {
	beforeEach(() => {
		window.ffcDashboard.viewAsUserId = 12;
	});
	afterEach(() => {
		delete window.ffcDashboard.viewAsUserId;
	});

	it('saveProfile appends viewAsUserId', () => {
		panel().render(PROFILE_FIXTURE);
		window.$('.ffc-profile-edit-btn').trigger('click');
		const spy = mockAjax(() => ({}));
		window.$('.ffc-profile-save-btn').trigger('click');
		expect(spy.mock.calls[0][0].url).toContain('?viewAsUserId=12');
	});

	it('renders no password control while viewing as another account (#2246)', () => {
		panel().render(PROFILE_FIXTURE);
		expect(document.querySelector('.ffc-password-toggle-btn')).toBeNull();
		expect(document.querySelector('.ffc-password-form')).toBeNull();
		expect(document.getElementById('ffc-new-password')).toBeNull();
	});

	it('privacyRequest appends viewAsUserId', () => {
		panel().render(PROFILE_FIXTURE);
		const spy = mockAjax(() => ({}));
		window.$('.ffc-lgpd-export-btn').trigger('click');
		expect(spy.mock.calls[0][0].url).toContain('?viewAsUserId=12');
	});

	it('saveNotificationPreferences appends viewAsUserId', () => {
		panel().render(PROFILE_FIXTURE);
		const spy = mockAjax(() => ({}));
		window.$(document.querySelector('.ffc-notif-toggle')).trigger('change');
		expect(spy.mock.calls[0][0].url).toContain('?viewAsUserId=12');
	});
});

// ----------------------------------------------------------------------
// privacyRequest error branch + password-form toggle
// ----------------------------------------------------------------------

describe('profile — misc handler branches', () => {
	it('privacyRequest on error surfaces the server message', async () => {
		panel().render(PROFILE_FIXTURE);
		mockAjaxError({ responseJSON: { message: 'Privacy denied' } });
		window.$('.ffc-lgpd-export-btn').trigger('click');
		await flush();
		expect(document.querySelector('.ffc-lgpd-status').textContent).toBe('Privacy denied');
	});

	it('the password-toggle button slides the password form into view', () => {
		window.$.fx.off = true;
		panel().render(PROFILE_FIXTURE);
		// Hidden initially.
		expect(window.$('#ffc-tabpanel-profile .ffc-password-form').css('display')).toBe('none');
		window.$('.ffc-password-toggle-btn').trigger('click');
		expect(window.$('#ffc-tabpanel-profile .ffc-password-form').css('display')).not.toBe('none');
	});

	it('render schedules audienceJoin.load() when the helper is present', async () => {
		const loadSpy = vi.fn();
		window.FFCDashboard.audienceJoin = { load: loadSpy };
		panel().render(PROFILE_FIXTURE);
		await new Promise((r) => setTimeout(r, 1));
		expect(loadSpy).toHaveBeenCalled();
		delete window.FFCDashboard.audienceJoin;
	});
});

// ----------------------------------------------------------------------
// Attribute-context escaping (#837 S12)
//
// esc() encodes < > & but NOT the double quote that would break out of an HTML
// attribute value, so values interpolated into value="…" / style="…" must use
// escAttr. A quote in the user's own profile data must stay inside the attribute
// (self-XSS / defense-in-depth — same bug class as the #564 stored-XSS).
// ----------------------------------------------------------------------

describe('profile — attribute escaping (S12)', () => {
	it('encodes a double quote in display_name inside the edit-form value attribute', () => {
		const payload = 'a" onmouseover="alert(1)';
		panel().render({ ...PROFILE_FIXTURE, display_name: payload });
		window.$('.ffc-profile-edit-btn').trigger('click');
		const input = document.getElementById('ffc-edit-display-name');
		// Quote encoded → the full string round-trips as the value and the
		// injected onmouseover never becomes a real attribute.
		expect(input.value).toBe(payload);
		expect(input.hasAttribute('onmouseover')).toBe(false);
	});

	it('drops an audience-group color that is not hex instead of writing it into the style attribute', () => {
		const payload = 'red" onmouseover="alert(1)';
		panel().render({
			...PROFILE_FIXTURE,
			audience_groups: [ { name: 'Alpha', color: payload, text_color: '#000000' } ],
		});
		const chip = document.querySelector('#ffc-tabpanel-profile .ffc-profile-group-tag');
		expect(chip).not.toBeNull();
		// The group name still renders, nothing broke out of the attribute, and
		// the tag keeps the neutral pair `.ffc-pill-row-color` declares (#1826).
		expect(chip.textContent).toBe('Alpha');
		expect(chip.hasAttribute('onmouseover')).toBe(false);
		expect(chip.hasAttribute('style')).toBe(false);
	});
});

// ----------------------------------------------------------------------
// Palette, not literals (#1826)
// ----------------------------------------------------------------------

describe('profile — group tags and status lines follow the palette (#1826)', () => {
	it('paints a group tag with the colour pair the REST payload carries', () => {
		panel().render({
			...PROFILE_FIXTURE,
			audience_groups: [ { name: 'Pale', color: '#ffeb3b', text_color: '#000000' } ],
		});
		const chip = document.querySelector('#ffc-tabpanel-profile .ffc-profile-group-tag');
		expect(chip.classList.contains('ffc-pill-row-color')).toBe(true);
		expect(chip.getAttribute('style')).toBe('--ffc-badge-row-bg:#ffeb3b;--ffc-badge-row-text:#000000;');
		expect(chip.closest('.ffc-profile-groups')).not.toBeNull();
	});

	it('reports a password field error through the standard classes, never an inline colour', () => {
		panel().render(PROFILE_FIXTURE);
		window.$('.ffc-password-save-btn').trigger('click');
		const msg = document.querySelector('.ffc-password-form .ffc-field-message');
		expect(msg.classList.contains('is-error')).toBe(true);
		expect(msg.getAttribute('style') || '').not.toMatch(/color/);
		expect(document.getElementById('ffc-current-password').getAttribute('style') || '').not.toMatch(/color/);
	});

	it('swaps the tone class rather than stacking it', () => {
		panel().render(PROFILE_FIXTURE);
		const $status = window.$('.ffc-password-status');
		window.FFCDashboard.helpers.setStatus($status, 'Saving…', 'busy');
		window.FFCDashboard.helpers.setStatus($status, 'Done', 'success');
		expect($status[0].className).toBe('ffc-password-status ffc-status is-success');
	});

	it('leaves no colour literal or inline margin in the rendered profile', () => {
		panel().render({
			...PROFILE_FIXTURE,
			audience_groups: [ { name: 'G', color: '#123456', text_color: '#ffffff' } ],
		});
		const html = document.getElementById('ffc-tabpanel-profile').innerHTML;
		expect(html).not.toMatch(/(?<!--ffc-badge-row-(?:bg|text):)#[0-9a-fA-F]{3,6}\b/);
		expect(html).not.toMatch(/margin-(?:top|left)/);
	});
});

// ----------------------------------------------------------------------
// View as user (#1863): masked fields revealed one at a time, audited
// ----------------------------------------------------------------------

describe('profile — view as user', () => {
	const VIEWED = {
		...PROFILE_FIXTURE,
		email: 'm***@example.com',
		emails: ['m***@example.com'],
		phone: '** *****-**00',
		birth_date: '',
		birth_date_display: '**/**/****',
		pii_masked: true,
		can_reveal: true,
	};

	beforeEach(() => {
		window.ffcDashboard.strings.reveal = 'Reveal';
		window.ffcDashboard.strings.revealError = 'Unable to reveal this value.';
		window.ffcDashboard.strings.revealToEdit = 'Reveal the value to edit it.';
		window.ffcDashboard.viewAsUserId = 5;
	});

	afterEach(() => {
		window.ffcDashboard.viewAsUserId = 0;
	});

	it('draws a Reveal button beside each masked field and none on a payload that is not masked', () => {
		panel().render({ ...VIEWED });
		expect(window.$('.ffc-profile-reveal-btn').map((i, b) => window.$(b).data('field')).get()).toEqual(['email', 'cpf_rf', 'phone', 'birth_date']);

		panel().render({ ...PROFILE_FIXTURE });
		expect(window.$('.ffc-profile-reveal-btn').length).toBe(0);
	});

	it('draws no Reveal button for a viewer the policy keeps masked', () => {
		panel().render({ ...VIEWED, can_reveal: false });
		expect(window.$('.ffc-profile-reveal-btn').length).toBe(0);
	});

	it('posts the field to user/profile/reveal for the viewed user and shows the clear value', async () => {
		panel().render({ ...VIEWED });
		const spy = mockAjaxSuccess({ field: 'phone', value: '11 99999-0000' });

		window.$('.ffc-profile-reveal-btn[data-field="phone"]').trigger('click');
		await flush();

		const call = spy.mock.calls[0][0];
		expect(call.method).toBe('POST');
		expect(call.url).toContain('user/profile/reveal?viewAsUserId=5');
		expect(JSON.parse(call.data)).toEqual({ field: 'phone' });
		expect(document.getElementById('ffc-tabpanel-profile').textContent).toContain('11 99999-0000');
		expect(window.$('.ffc-profile-reveal-btn[data-field="phone"]').length).toBe(0);
		expect(window.$('.ffc-profile-reveal-btn[data-field="email"]').length).toBe(1);
	});

	it('reveals every linked email at once', async () => {
		panel().render({ ...VIEWED });
		mockAjaxSuccess({ field: 'email', value: 'maria@example.com', values: ['maria@example.com', 'm.silva@example.com'] });

		window.$('.ffc-profile-reveal-btn[data-field="email"]').trigger('click');
		await flush();

		expect(window.$('.ffc-email-list li').map((i, li) => window.$(li).text()).get()).toEqual(['maria@example.com', 'm.silva@example.com']);
	});

	it('draws no CPF/RF Reveal when the viewed account has no identifier (#1888)', () => {
		panel().render({ ...VIEWED, cpfs_masked: [], cpf_masked: 'Not found' });
		expect(window.$('.ffc-profile-reveal-btn[data-field="cpf_rf"]').length).toBe(0);
		expect(window.$('.ffc-profile-reveal-btn[data-field="email"]').length).toBe(1);
	});

	it('draws no CPF/RF Reveal on a payload that is not masked (#1888)', () => {
		panel().render({ ...PROFILE_FIXTURE, cpfs_masked: ['123.***.***-01', '765.***-1'] });
		expect(window.$('.ffc-cpf-list li').length).toBe(2);
		expect(window.$('.ffc-profile-reveal-btn[data-field="cpf_rf"]').length).toBe(0);
	});

	it('reveals every CPF/RF at once and shows them formatted (#1888)', async () => {
		panel().render({ ...VIEWED, cpfs_masked: ['123.***.***-01', '765.***-1'], cpf_masked: '123.***.***-01' });
		const spy = mockAjaxSuccess({ field: 'cpf_rf', value: '123.456.789-01', values: ['123.456.789-01', '765.432-1'] });

		window.$('.ffc-profile-reveal-btn[data-field="cpf_rf"]').trigger('click');
		await flush();

		expect(JSON.parse(spy.mock.calls[0][0].data)).toEqual({ field: 'cpf_rf' });
		expect(window.$('.ffc-cpf-list li').map((i, li) => window.$(li).text()).get()).toEqual(['123.456.789-01', '765.432-1']);
		expect(window.$('.ffc-profile-reveal-btn[data-field="cpf_rf"]').length).toBe(0);
		expect(window.$('.ffc-profile-reveal-btn[data-field="phone"]').length).toBe(1);
	});

	it('shows a single revealed CPF/RF as a plain value (#1888)', async () => {
		panel().render({ ...VIEWED });
		mockAjaxSuccess({ field: 'cpf_rf', value: '123.456.789-01', values: ['123.456.789-01'] });

		window.$('.ffc-profile-reveal-btn[data-field="cpf_rf"]').trigger('click');
		await flush();

		const text = document.getElementById('ffc-tabpanel-profile').textContent;
		expect(text).toContain('123.456.789-01');
		expect(text).not.toContain('123.***.***-**');
	});

	it('keeps a masked phone and birth date out of the save until they are revealed', () => {
		panel().render({ ...VIEWED });
		window.$('.ffc-profile-edit-btn').trigger('click');
		expect(document.getElementById('ffc-edit-phone').disabled).toBe(true);
		expect(document.getElementById('ffc-edit-birth-date').disabled).toBe(true);

		const spy = mockAjaxSuccess(VIEWED);
		window.$('.ffc-profile-save-btn').trigger('click');

		const payload = JSON.parse(spy.mock.calls[0][0].data);
		expect(payload).not.toHaveProperty('phone');
		expect(payload).not.toHaveProperty('birth_date');
		expect(payload.department).toBe('TI');
	});

	it('edits a field normally once it is revealed', async () => {
		panel().render({ ...VIEWED });
		mockAjaxSuccess({ field: 'birth_date', value: '1990-05-20', display: '20/05/1990' });
		window.$('.ffc-profile-reveal-btn[data-field="birth_date"]').trigger('click');
		await flush();
		vi.restoreAllMocks();

		window.$('.ffc-profile-edit-btn').trigger('click');
		const input = document.getElementById('ffc-edit-birth-date');
		expect(input.disabled).toBe(false);
		expect(input.value).toBe('1990-05-20');
	});

	it('offers Reveal inside the edit form and reopens it unlocked, keeping what was typed (#2246)', async () => {
		panel().render({ ...VIEWED });
		window.$('.ffc-profile-edit-btn').trigger('click');
		expect(window.$('.ffc-profile-edit-form .ffc-profile-edit-reveal-btn').map((i, b) => window.$(b).data('field')).get()).toEqual(['phone', 'birth_date']);

		document.getElementById('ffc-edit-department').value = 'Typed department';
		mockAjaxSuccess({ field: 'phone', value: '(11) 99999-0000' });
		window.$('.ffc-profile-edit-reveal-btn[data-field="phone"]').trigger('click');
		await flush();

		expect(document.querySelector('.ffc-profile-edit-form')).not.toBeNull();
		const phone = document.getElementById('ffc-edit-phone');
		expect(phone.disabled).toBe(false);
		expect(phone.value).toBe('(11) 99999-0000');
		expect(document.getElementById('ffc-edit-department').value).toBe('Typed department');
		expect(document.getElementById('ffc-edit-birth-date').disabled).toBe(true);
	});

	it('leaves an empty phone or birth date open to fill in, with nothing to reveal (#2246)', () => {
		panel().render({ ...VIEWED, phone: '', birth_date_display: '' });
		window.$('.ffc-profile-edit-btn').trigger('click');

		expect(document.getElementById('ffc-edit-phone').disabled).toBe(false);
		expect(document.getElementById('ffc-edit-birth-date').disabled).toBe(false);
		expect(window.$('.ffc-profile-edit-reveal-btn').length).toBe(0);
	});

	it('draws no edit-form Reveal for a viewer the policy keeps masked', () => {
		panel().render({ ...VIEWED, can_reveal: false });
		window.$('.ffc-profile-edit-btn').trigger('click');

		expect(document.getElementById('ffc-edit-phone').disabled).toBe(true);
		expect(window.$('.ffc-profile-edit-reveal-btn').length).toBe(0);
	});

	it('re-enables the button and alerts when the reveal fails', async () => {
		panel().render({ ...VIEWED });
		mockAjaxError({ status: 403, responseJSON: { message: 'No.' } });
		window.alert = vi.fn();

		window.$('.ffc-profile-reveal-btn[data-field="phone"]').trigger('click');
		await flush();

		expect(window.alert).toHaveBeenCalledWith('Unable to reveal this value.');
		expect(window.$('.ffc-profile-reveal-btn[data-field="phone"]').prop('disabled')).toBe(false);
	});
});

// ----------------------------------------------------------------------
// The owner's own profile (#1897): masked, with the same Reveal buttons
// ----------------------------------------------------------------------

describe('profile — the owner', () => {
	const OWN = {
		...PROFILE_FIXTURE,
		email: 'm***@example.com',
		emails: ['m***@example.com', 'm***@work.example'],
		cpfs_masked: ['123.***.***-01'],
		cpf_masked: '123.***.***-01',
		phone: '(**) *****-**00',
		birth_date: '',
		birth_date_display: '**/**/****',
		pii_masked: true,
		can_reveal: true,
	};

	beforeEach(() => {
		window.ffcDashboard.strings.reveal = 'Reveal';
		window.ffcDashboard.viewAsUserId = 0;
	});

	it('shows every personal field masked, each with a Reveal button', () => {
		panel().render({ ...OWN });

		expect(window.$('.ffc-profile-reveal-btn').map((i, b) => window.$(b).data('field')).get()).toEqual(['email', 'cpf_rf', 'phone', 'birth_date']);
		const text = document.getElementById('ffc-tabpanel-profile').textContent;
		expect(text).toContain('m***@example.com');
		expect(text).toContain('123.***.***-01');
		expect(text).toContain('(**) *****-**00');
		expect(text).toContain('**/**/****');
	});

	it('reveals through user/profile/reveal without naming an account', async () => {
		panel().render({ ...OWN });
		const spy = mockAjaxSuccess({ field: 'phone', value: '(11) 99999-0000' });

		window.$('.ffc-profile-reveal-btn[data-field="phone"]').trigger('click');
		await flush();

		const call = spy.mock.calls[0][0];
		expect(call.url).toBe('https://x.test/wp-json/ffc/v1/user/profile/reveal');
		expect(JSON.parse(call.data)).toEqual({ field: 'phone' });
		expect(document.getElementById('ffc-tabpanel-profile').textContent).toContain('(11) 99999-0000');
		expect(window.$('.ffc-profile-reveal-btn[data-field="phone"]').length).toBe(0);
	});
});
