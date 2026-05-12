// Render tests for the Profile panel (assets/js/ffc-user-dashboard-profile.js).
//
// The panel renders the user's profile read view + the action buttons
// (edit, change password) + the audience-join section placeholder. The
// password change form / LGPD export buttons are exercised by their own
// handlers — tested separately if/when needed. This file focuses on the
// `render(profile)` path that builds the DOM from the profile state.
//
// Sprint B of #168.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { installDashboardFixtures, loadDashboardCore, loadPanel } from './dashboard-fixtures.js';

beforeAll(() => {
	installDashboardFixtures();
	Object.assign(window.ffcDashboard.strings, {
		name: 'Name',
		linkedEmails: 'Emails',
		cpfRf: 'CPF/RF',
		phone: 'Phone:',
		department: 'Department:',
		organization: 'Organization:',
		notesLabel: 'Notes:',
		audienceGroups: 'Groups',
		memberSince: 'Member since:',
		editProfile: 'Edit Profile',
		changePassword: 'Change Password',
		leaveAllGroups: 'Leave all groups',
		logout: 'Log out',
		preferences: 'Preferences',
		notifications: 'Notifications',
		lgpd: 'Privacy',
	});
	window.ffcDashboard.logoutUrl = 'https://x.test/logout';

	// Inject the panel's tab container.
	const dash = document.getElementById('ffc-dashboard');
	if (dash && ! document.getElementById('tab-profile')) {
		const el = document.createElement('div');
		el.id = 'tab-profile';
		el.className = 'ffc-tab-content';
		dash.appendChild(el);
	}
	loadDashboardCore();
	loadPanel('profile');
});

beforeEach(() => {
	document.getElementById('tab-profile').innerHTML = '';
});

const panel = () => window.FFCDashboard.panels.profile;

function makeProfile(over = {}) {
	return Object.assign({
		display_name: 'Maria Silva',
		names: ['Maria Silva'],
		email: 'maria@example.com',
		emails: ['maria@example.com'],
		cpf_masked: '123.***.***-**',
		cpfs_masked: ['123.***.***-**'],
		phone: '(11) 99999-0000',
		department: 'Engenharia',
		organization: 'ACME',
		notes: '',
		audience_groups: [],
		member_since: '01/01/2025',
		preferences: {},
	}, over);
}

describe('FFCDashboard.panels.profile.render', () => {
	it('renders the profile container', () => {
		panel().render(makeProfile());
		expect(document.querySelector('#tab-profile .ffc-profile-info')).not.toBeNull();
	});

	it('renders the logout link when ffcDashboard.logoutUrl is set', () => {
		panel().render(makeProfile());
		const logout = document.querySelector('#tab-profile .ffc-logout-link');
		expect(logout).not.toBeNull();
		expect(logout.getAttribute('href')).toBe('https://x.test/logout');
	});

	it('renders core fields with their localised labels', () => {
		panel().render(makeProfile({
			phone: '11 99999-0000',
			department: 'TI',
			organization: 'ACME',
		}));
		const text = document.querySelector('#tab-profile').textContent;
		expect(text).toContain('Phone:');
		expect(text).toContain('11 99999-0000');
		expect(text).toContain('Department:');
		expect(text).toContain('TI');
		expect(text).toContain('Organization:');
		expect(text).toContain('ACME');
	});

	it('renders the Notes block only when notes is non-empty', () => {
		panel().render(makeProfile({ notes: '' }));
		expect(document.querySelector('#tab-profile').textContent).not.toContain('Notes:');

		panel().render(makeProfile({ notes: 'Important note' }));
		expect(document.querySelector('#tab-profile').textContent).toContain('Notes:');
		expect(document.querySelector('#tab-profile').textContent).toContain('Important note');
	});

	it('renders audience-group chips when audience_groups has items', () => {
		panel().render(makeProfile({
			audience_groups: [
				{ name: 'Alpha', color: '#aa0000' },
				{ name: 'Beta',  color: '#00aa00' },
			],
		}));
		const container = document.querySelector('#tab-profile');
		expect(container.textContent).toContain('Alpha');
		expect(container.textContent).toContain('Beta');
		// "Leave all groups" button only when there are groups.
		expect(document.querySelector('#tab-profile .ffc-leave-all-groups-btn')).not.toBeNull();
	});

	it('omits the "Leave all groups" button when audience_groups is empty', () => {
		panel().render(makeProfile({ audience_groups: [] }));
		expect(document.querySelector('#tab-profile .ffc-leave-all-groups-btn')).toBeNull();
	});

	it('renders the Edit Profile and Change Password buttons', () => {
		panel().render(makeProfile());
		expect(document.querySelector('#tab-profile .ffc-profile-edit-btn')).not.toBeNull();
		expect(document.querySelector('#tab-profile .ffc-password-toggle-btn')).not.toBeNull();
	});

	it('renders the audience-join section placeholder', () => {
		panel().render(makeProfile());
		expect(document.querySelector('#tab-profile #ffc-audience-join-section')).not.toBeNull();
	});

	it('stores the profile on the panel state', () => {
		const profile = makeProfile({ display_name: 'João' });
		panel().render(profile);
		expect(panel().state).toBe(profile);
	});
});
