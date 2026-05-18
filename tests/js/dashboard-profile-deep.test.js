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
	window.ffcDashboard.logoutUrl = 'https://x.test/logout';

	const dash = document.getElementById('ffc-dashboard');
	if (dash && ! document.getElementById('tab-profile')) {
		const el = document.createElement('div');
		el.id = 'tab-profile';
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
	document.getElementById('tab-profile').innerHTML = '';
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
		const form = document.querySelector('#tab-profile .ffc-profile-edit-form');
		expect(form).not.toBeNull();
		expect(document.getElementById('ffc-edit-display-name').value).toBe('Maria Silva');
		expect(document.getElementById('ffc-edit-phone').value).toBe('11 99999-0000');
		expect(document.getElementById('ffc-edit-department').value).toBe('TI');
		expect(document.getElementById('ffc-edit-organization').value).toBe('ACME');
	});

	it("does nothing when state is null (defensive — shouldn't happen at runtime)", () => {
		panel().state = null;
		document.getElementById('tab-profile').innerHTML = '<button class="ffc-profile-edit-btn">Edit</button>';
		window.$('.ffc-profile-edit-btn').trigger('click');
		expect(document.querySelector('#tab-profile .ffc-profile-edit-form')).toBeNull();
	});

	it('cancel button re-renders the read view from the preserved state', () => {
		panel().render(PROFILE_FIXTURE);
		window.$('.ffc-profile-edit-btn').trigger('click');
		expect(document.querySelector('#tab-profile .ffc-profile-edit-form')).not.toBeNull();
		window.$('.ffc-profile-cancel-btn').trigger('click');
		// Read view is back.
		expect(document.querySelector('#tab-profile .ffc-profile-info')).not.toBeNull();
		expect(document.querySelector('#tab-profile .ffc-profile-edit-form')).toBeNull();
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
		expect(payload.phone).toBe('11 99999-0000');
		expect(payload.department).toBe('TI');
		expect(payload.organization).toBe('ACME');
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
		expect(document.querySelector('#tab-profile .ffc-profile-info')).not.toBeNull();
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
	});

	function setPasswords(current, neu, conf) {
		document.getElementById('ffc-current-password').value = current;
		document.getElementById('ffc-new-password').value = neu;
		document.getElementById('ffc-confirm-password').value = conf;
	}

	it('rejects when any field is blank (no AJAX call)', () => {
		const spy = mockAjax(() => ({}));
		setPasswords('', '', '');
		window.$('.ffc-password-save-btn').trigger('click');
		expect(spy).not.toHaveBeenCalled();
		expect(document.querySelector('.ffc-password-status').textContent).toBe('All fields required');
	});

	it('rejects when new + confirm mismatch', () => {
		const spy = mockAjax(() => ({}));
		setPasswords('old', 'abcdefgh', 'abcdefgi');
		window.$('.ffc-password-save-btn').trigger('click');
		expect(spy).not.toHaveBeenCalled();
		expect(document.querySelector('.ffc-password-status').textContent).toBe('Passwords do not match');
	});

	it('rejects when new password is shorter than 8 chars', () => {
		const spy = mockAjax(() => ({}));
		setPasswords('old', 'short', 'short');
		window.$('.ffc-password-save-btn').trigger('click');
		expect(spy).not.toHaveBeenCalled();
		expect(document.querySelector('.ffc-password-status').textContent).toBe('Min 8 characters');
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
			document.getElementById('tab-profile').insertAdjacentHTML(
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
			document.getElementById('tab-profile').insertAdjacentHTML(
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
			document.getElementById('tab-profile').insertAdjacentHTML(
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
			document.getElementById('tab-profile').insertAdjacentHTML(
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
});
