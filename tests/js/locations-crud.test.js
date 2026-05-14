// Tests for assets/js/ffc-locations-crud.js — per-row inline AJAX for
// the geofence locations table in Settings → Geolocation. Shipped at
// 0% coverage in 6.5.4 (PR #197); the PHP endpoint behind it has
// PHPUnit coverage but the JS layer didn't.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'core',
		strings: { error: 'Generic error', connectionError: 'Connection error' },
	};
	// The script captures `window.ffcLocationsCrud` at module-load time,
	// so seed it BEFORE loading the script.
	window.ffcLocationsCrud = {
		nonces: { save: 'save-n', delete: 'del-n' },
		strings: {
			saving: 'Saving',
			saved: 'Saved',
			deleting: 'Deleting',
			error: 'Failed',
			confirmDelete: 'Delete?',
			deleteText: 'Delete',
		},
	};
	loadScript('assets/js/ffc-core.js');
	// Load the script-under-test once — all its delegated handlers attach
	// to $(document), so they survive document.body resets between tests.
	// Loading it per-test would stack N handlers and trigger N row inserts
	// per click of "Add location".
	loadScript('assets/js/ffc-locations-crud.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
});

afterEach(() => {
	vi.restoreAllMocks();
	vi.useRealTimers();
});

// No-op reload — keeps the existing call sites tidy without re-loading
// the script (which would stack delegated handlers).
async function reload() {
	await new Promise((r) => setTimeout(r, 0));
}

function mountTable(rows) {
	rows = rows || [];
	const rowHtml = rows
		.map(
			(r) => `
		<tr class="ffc-location-row" data-location-id="${r.id}">
			<td><input type="text" class="ffc-location-field" data-field="name" value="${r.name}"></td>
			<td><input type="number" class="ffc-location-field" data-field="lat" value="${r.lat}"></td>
			<td><input type="number" class="ffc-location-field" data-field="lng" value="${r.lng}"></td>
			<td><input type="number" class="ffc-location-field" data-field="radius" value="${r.radius}"></td>
			<td><input type="radio" name="ffc_location_default_gps" class="ffc-location-default-gps" value="${r.id}"${r.default_gps ? ' checked' : ''}></td>
			<td><input type="radio" name="ffc_location_default_ip" class="ffc-location-default-ip" value="${r.id}"${r.default_ip ? ' checked' : ''}></td>
			<td>
				<button type="button" class="button button-small ffc-location-delete">Delete</button>
				<span class="ffc-autosave-badge" hidden></span>
			</td>
		</tr>
	`,
		)
		.join('');
	document.body.innerHTML = `
		<table>
			<tbody>
				${rowHtml}
				<tr class="ffc-locations-none-row"><td colspan="7">none</td></tr>
			</tbody>
			<tfoot>
				<tr id="ffc-location-new-row">
					<td><input type="text" class="ffc-location-new-field" data-field="name" value=""></td>
					<td><input type="number" class="ffc-location-new-field" data-field="lat" value=""></td>
					<td><input type="number" class="ffc-location-new-field" data-field="lng" value=""></td>
					<td><input type="number" class="ffc-location-new-field" data-field="radius" value=""></td>
					<td colspan="2"><button type="button" id="ffc-location-add">Add</button></td>
					<td><span class="ffc-autosave-badge" hidden></span></td>
				</tr>
			</tfoot>
		</table>
	`;
}

// ----------------------------------------------------------------------
// Hard-bail when FFC.request is not present
// ----------------------------------------------------------------------

describe('locations-crud — load-time guard', () => {
	it('does nothing when window.FFC.request is missing', async () => {
		mountTable([{ id: 'loc_1', name: 'A', lat: 1, lng: 2, radius: 100, default_gps: false, default_ip: false }]);
		const original = window.FFC && window.FFC.request;
		window.FFC = window.FFC || {};
		window.FFC.request = undefined;
		await reload();

		// Clicking delete with no FFC.request → no error, no AJAX firing.
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => ({}));
		window.$('.ffc-location-delete').trigger('click');
		expect(postSpy).not.toHaveBeenCalled();

		window.FFC.request = original;
	});
});

// ----------------------------------------------------------------------
// saveRow — blur on a field
// ----------------------------------------------------------------------

describe('locations-crud — saveRow on blur', () => {
	it('POSTs ffc_location_save with the row payload and updates fields with canonical values', async () => {
		mountTable([{ id: 'loc_1', name: 'Old', lat: 1, lng: 2, radius: 100, default_gps: false, default_ip: false }]);
		await reload();

		const postSpy = vi.spyOn(window.$, 'post').mockImplementation((url, payload) => {
			const chain = { done: () => chain, fail: () => chain };
			chain.done = function (cb) {
				cb({
					success: true,
					data: { location: { id: 'loc_1', name: 'New (canonical)', lat: 1.5, lng: 2.5, radius: 200 }, is_new: false },
				});
				return chain;
			};
			return chain;
		});

		// Edit the name field + blur.
		window.$('.ffc-location-field[data-field="name"]').val('New').trigger('blur');
		await new Promise((r) => setTimeout(r, 0));

		expect(postSpy).toHaveBeenCalled();
		const [url, payload] = postSpy.mock.calls[0];
		expect(url).toBe('/wp-admin/admin-ajax.php');
		expect(payload).toMatchObject({
			action: 'ffc_location_save',
			nonce: 'save-n',
			id: 'loc_1',
			name: 'New',
		});

		// Server-canonical name reflected back into the row.
		expect(window.$('.ffc-location-field[data-field="name"]').val()).toBe('New (canonical)');
		expect(window.$('.ffc-location-field[data-field="lat"]').val()).toBe('1.5');
	});

	it('renders the saved badge on success', async () => {
		mountTable([{ id: 'loc_2', name: 'A', lat: 0, lng: 0, radius: 10, default_gps: false, default_ip: false }]);
		await reload();

		vi.spyOn(window.$, 'post').mockImplementation(() => {
			const chain = { done: () => chain, fail: () => chain };
			chain.done = function (cb) {
				cb({ success: true, data: { location: { id: 'loc_2', name: 'A' } } });
				return chain;
			};
			return chain;
		});

		window.$('.ffc-location-field[data-field="name"]').trigger('blur');
		await new Promise((r) => setTimeout(r, 0));

		const $badge = window.$('.ffc-location-row .ffc-autosave-badge').first();
		expect($badge.hasClass('ffc-autosave-badge--saved')).toBe(true);
		expect($badge.text()).toBe('Saved');
	});

	it('renders the error badge with the server message on protocol failure', async () => {
		mountTable([{ id: 'loc_3', name: 'A', lat: 0, lng: 0, radius: 10, default_gps: false, default_ip: false }]);
		await reload();

		vi.spyOn(window.$, 'post').mockImplementation(() => {
			const chain = { done: () => chain, fail: () => chain };
			chain.done = function (cb) {
				cb({ success: false, data: { message: 'Quota exceeded' } });
				return chain;
			};
			return chain;
		});

		window.$('.ffc-location-field[data-field="name"]').trigger('blur');
		await new Promise((r) => setTimeout(r, 0));

		const $badge = window.$('.ffc-location-row .ffc-autosave-badge').first();
		expect($badge.hasClass('ffc-autosave-badge--error')).toBe(true);
		expect($badge.text()).toBe('Quota exceeded');
	});

	it('triggers save when the default-GPS radio changes', async () => {
		mountTable([
			{ id: 'loc_a', name: 'A', lat: 0, lng: 0, radius: 10, default_gps: false, default_ip: false },
			{ id: 'loc_b', name: 'B', lat: 0, lng: 0, radius: 10, default_gps: false, default_ip: false },
		]);
		await reload();

		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => {
			const chain = { done: () => chain, fail: () => chain };
			chain.done = function (cb) {
				cb({ success: true, data: { location: {} } });
				return chain;
			};
			return chain;
		});

		window
			.$('.ffc-location-row[data-location-id="loc_b"] .ffc-location-default-gps')
			.prop('checked', true)
			.trigger('change');

		expect(postSpy).toHaveBeenCalled();
		expect(postSpy.mock.calls[0][1]).toMatchObject({
			id: 'loc_b',
			default_gps: '1',
		});
	});

	it('triggers save when the default-IP radio changes', async () => {
		mountTable([
			{ id: 'loc_a', name: 'A', lat: 0, lng: 0, radius: 10, default_gps: false, default_ip: false },
		]);
		await reload();

		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => {
			const chain = { done: () => chain, fail: () => chain };
			chain.done = function (cb) {
				cb({ success: true, data: { location: {} } });
				return chain;
			};
			return chain;
		});

		window.$('.ffc-location-default-ip').prop('checked', true).trigger('change');

		expect(postSpy.mock.calls[0][1].default_ip).toBe('1');
	});
});

// ----------------------------------------------------------------------
// deleteRow
// ----------------------------------------------------------------------

describe('locations-crud — deleteRow', () => {
	it('bails when the user declines the confirm', async () => {
		mountTable([{ id: 'loc_x', name: 'X', lat: 0, lng: 0, radius: 10, default_gps: false, default_ip: false }]);
		await reload();
		const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => ({}));

		window.$('.ffc-location-delete').trigger('click');

		expect(confirmSpy).toHaveBeenCalledWith('Delete?');
		expect(postSpy).not.toHaveBeenCalled();
		// Row still in the DOM.
		expect(window.$('.ffc-location-row').length).toBe(1);
	});

	it('on success: removes the row from the DOM', async () => {
		mountTable([{ id: 'loc_y', name: 'Y', lat: 0, lng: 0, radius: 10, default_gps: false, default_ip: false }]);
		await reload();
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.spyOn(window.$, 'post').mockImplementation(() => {
			const chain = { done: () => chain, fail: () => chain };
			chain.done = function (cb) { cb({ success: true, data: {} }); return chain; };
			return chain;
		});

		window.$('.ffc-location-delete').trigger('click');
		await new Promise((r) => setTimeout(r, 0));

		expect(window.$('.ffc-location-row').length).toBe(0);
	});

	it('on failure: shows the server error in the badge and keeps the row', async () => {
		mountTable([{ id: 'loc_z', name: 'Z', lat: 0, lng: 0, radius: 10, default_gps: false, default_ip: false }]);
		await reload();
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.spyOn(window.$, 'post').mockImplementation(() => {
			const chain = { done: () => chain, fail: () => chain };
			chain.done = function (cb) { cb({ success: false, data: { message: 'In use' } }); return chain; };
			return chain;
		});

		window.$('.ffc-location-delete').trigger('click');
		await new Promise((r) => setTimeout(r, 0));

		expect(window.$('.ffc-location-row').length).toBe(1);
		const $badge = window.$('.ffc-location-row .ffc-autosave-badge');
		expect($badge.hasClass('ffc-autosave-badge--error')).toBe(true);
		expect($badge.text()).toBe('In use');
	});
});

// ----------------------------------------------------------------------
// addRow
// ----------------------------------------------------------------------

describe('locations-crud — addRow', () => {
	it('bails when the name field is empty', async () => {
		mountTable([]);
		await reload();
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => ({}));

		window.$('#ffc-location-add').trigger('click');

		expect(postSpy).not.toHaveBeenCalled();
	});

	it('on success: appends a new row with the canonical record + clears the new-row inputs', async () => {
		mountTable([]);
		await reload();

		vi.spyOn(window.$, 'post').mockImplementation((url, payload) => {
			const chain = { done: () => chain, fail: () => chain };
			chain.done = function (cb) {
				cb({
					success: true,
					data: {
						location: { id: 'loc_added', name: 'A&B<>"', lat: 10, lng: 20, radius: 500 },
						is_new: true,
					},
				});
				return chain;
			};
			return chain;
		});

		window
			.$('.ffc-location-new-field[data-field="name"]')
			.val('A&B<>"');
		window
			.$('.ffc-location-new-field[data-field="lat"]')
			.val('10');
		window
			.$('.ffc-location-new-field[data-field="lng"]')
			.val('20');
		window
			.$('.ffc-location-new-field[data-field="radius"]')
			.val('500');
		window.$('#ffc-location-add').trigger('click');
		await new Promise((r) => setTimeout(r, 0));

		// New row appears with the server-assigned id.
		const $newRow = window.$('.ffc-location-row[data-location-id="loc_added"]');
		expect($newRow.length).toBe(1);
		// Server values reflected (name is HTML-escaped via input value, but
		// the raw value is preserved in the input.value).
		expect($newRow.find('.ffc-location-field[data-field="name"]').val()).toBe('A&B<>"');
		expect($newRow.find('.ffc-location-field[data-field="lat"]').val()).toBe('10');

		// New-row inputs cleared.
		expect(window.$('#ffc-location-new-row .ffc-location-new-field[data-field="name"]').val()).toBe('');
		expect(window.$('#ffc-location-new-row .ffc-location-new-field[data-field="radius"]').val()).toBe('');

		// "No locations" placeholder is dropped if it was present.
		expect(window.$('.ffc-locations-empty-row').length).toBe(0);
	});

	it('on failure: shows the server error in the new-row badge and does not append', async () => {
		mountTable([]);
		await reload();
		vi.spyOn(window.$, 'post').mockImplementation(() => {
			const chain = { done: () => chain, fail: () => chain };
			chain.done = function (cb) { cb({ success: false, data: { message: 'Bad lat' } }); return chain; };
			return chain;
		});

		window.$('.ffc-location-new-field[data-field="name"]').val('A');
		window.$('#ffc-location-add').trigger('click');
		await new Promise((r) => setTimeout(r, 0));

		// No new row appended.
		expect(window.$('.ffc-location-row').length).toBe(0);
		// Badge shows the server message.
		const $badge = window.$('#ffc-location-new-row .ffc-autosave-badge');
		expect($badge.hasClass('ffc-autosave-badge--error')).toBe(true);
		expect($badge.text()).toBe('Bad lat');
	});

	it('on response without data.location: shows the generic error', async () => {
		mountTable([]);
		await reload();
		vi.spyOn(window.$, 'post').mockImplementation(() => {
			const chain = { done: () => chain, fail: () => chain };
			chain.done = function (cb) { cb({ success: true, data: null }); return chain; };
			return chain;
		});

		window.$('.ffc-location-new-field[data-field="name"]').val('A');
		window.$('#ffc-location-add').trigger('click');
		await new Promise((r) => setTimeout(r, 0));

		const $badge = window.$('#ffc-location-new-row .ffc-autosave-badge');
		expect($badge.hasClass('ffc-autosave-badge--error')).toBe(true);
		expect($badge.text()).toBe('Failed');
	});
});
