// Deep coverage for assets/js/ffc-custom-fields-admin.js — drag-and-
// drop custom-field management on the audiences admin page.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// FFC.request — the migration target — wraps jQuery.post() in a Promise.
// Mock $.post and return a chain whose .done / .fail callback the
// FFC.request internals invoke.
function postChain(spec) {
	const chain = { done: () => chain, fail: () => chain };
	if (spec && 'done' in spec) chain.done = (cb) => { cb(spec.done); return chain; };
	if (spec && spec.fail) chain.fail = (cb) => { cb(spec.fail === true ? undefined : spec.fail); return chain; };
	return chain;
}

// Microtask flush so .then/.catch reactions run before assertions.
function flush() { return Promise.resolve().then(() => Promise.resolve()); }


beforeAll(() => {
	window.ffcAudienceAdmin = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		adminNonce: 'admin-n',
		strings: {
			saving: 'Saving',
			saved: 'Saved',
			error: 'Error',
			confirmDelete: 'Delete?',
			cannotDeleteStandard: 'Cannot delete standard',
		},
	};
	window.$.fn.sortable = function () { return this; };
	window.wp = window.wp || {};
	window.wp.template = function () {
		return function (vars) {
			return `
				<tr class="ffc-custom-field-row" data-field-id="new_${vars.index}" data-field-source="custom">
					<td><span class="ffc-field-handle">≡</span></td>
					<td><input type="text" class="ffc-field-label" value=""></td>
					<td><input type="text" class="ffc-field-key" value=""></td>
					<td><select class="ffc-field-type"><option value="text">text</option><option value="select">select</option><option value="working_hours">wh</option></select></td>
					<td><input type="text" class="ffc-field-group" value=""></td>
					<td><input type="checkbox" class="ffc-field-required"></td>
					<td><input type="checkbox" class="ffc-field-active" checked></td>
					<td><input type="checkbox" class="ffc-field-sensitive"></td>
					<td><input type="text" class="ffc-field-profile-key" value=""></td>
					<td><input type="text" class="ffc-field-mask" value=""></td>
					<td>
						<button type="button" class="ffc-field-toggle-details">…</button>
						<button type="button" class="ffc-field-delete">x</button>
					</td>
					<tr class="ffc-field-details-row" style="display:none">
						<td colspan="11">
							<div class="ffc-field-options-container"><textarea class="ffc-field-choices"></textarea></div>
							<textarea class="ffc-field-help"></textarea>
							<div class="ffc-field-detail-row">
								<select class="ffc-field-format"><option value="">-</option><option value="custom_regex">regex</option></select>
								<input type="text" class="ffc-field-regex" style="display:none">
								<input type="text" class="ffc-field-regex-msg" style="display:none">
							</div>
						</td>
					</tr>
				</tr>
			`;
		};
	};
});

beforeEach(async () => {
	// Production rendering uses two sibling <tr>s per field (header + details).
	// The script's .closest('.ffc-custom-field-row') walks up from the
	// header row only, so the details <tr> needs to be a separate child
	// of the header for `.find('.ffc-field-details-row')` to reach it
	// — wrap each field in a <tbody class="ffc-custom-field-row"> so the
	// .find() works through structurally valid HTML.
	document.body.innerHTML = `
		<div id="ffc-custom-fields-container" data-audience-id="7">
			<table id="ffc-custom-fields-list">
				<tbody class="ffc-custom-field-row" data-field-id="42" data-field-source="custom">
					<tr>
						<td><span class="ffc-field-handle">≡</span></td>
						<td><input type="text" class="ffc-field-label" value="Existing"></td>
						<td><input type="text" class="ffc-field-key" value="ext_key"></td>
						<td><select class="ffc-field-type"><option value="text" selected>text</option><option value="select">select</option><option value="working_hours">wh</option></select></td>
						<td><input type="text" class="ffc-field-group" value=""></td>
						<td><input type="checkbox" class="ffc-field-required" checked></td>
						<td><input type="checkbox" class="ffc-field-active" checked></td>
						<td><input type="checkbox" class="ffc-field-sensitive"></td>
						<td><input type="text" class="ffc-field-profile-key" value=""></td>
						<td><input type="text" class="ffc-field-mask" value=""></td>
						<td>
							<button type="button" class="ffc-field-toggle-details">…</button>
							<button type="button" class="ffc-field-delete">x</button>
						</td>
					</tr>
					<tr class="ffc-field-details-row" style="display:none">
						<td colspan="11">
							<div class="ffc-field-options-container" style="display:none"><textarea class="ffc-field-choices"></textarea></div>
							<textarea class="ffc-field-help"></textarea>
							<div class="ffc-field-detail-row">
								<select class="ffc-field-format"><option value="">-</option><option value="custom_regex">regex</option></select>
								<input type="text" class="ffc-field-regex" style="display:none">
								<input type="text" class="ffc-field-regex-msg" style="display:none">
							</div>
						</td>
					</tr>
				</tbody>
			</table>
			<button id="ffc-add-custom-field">Add</button>
			<button id="ffc-save-custom-fields">Save</button>
			<span id="ffc-custom-fields-status"></span>
		</div>
	`;
	window.$.fx.off = true;
	// Script binds directly on #ffc-add-custom-field + #ffc-save-custom-fields
	// at $(document).ready, so we must reload it AFTER the fixture mount.
	if (!window.FFC) { loadScript('assets/js/ffc-core.js'); }
	loadScript('assets/js/ffc-custom-fields-admin.js');
	// Wait for the $(document).ready microtask so direct binds attach.
	await new Promise((r) => setTimeout(r, 0));
});

afterEach(() => {
	// The IIFE binds delegated handlers on $(document) at every load —
	// without clearing them, each subsequent test would fire all prior
	// loads' handlers, leading to duplicate slideToggle / extra deletes /
	// stacked confirms. Reset the relevant delegates between tests.
	window.$(document).off('click', '.ffc-field-delete');
	window.$(document).off('click', '.ffc-field-toggle-details');
	window.$(document).off('change', '.ffc-field-type');
	window.$(document).off('change', '.ffc-field-format');
	window.$(document).off('change', '.ffc-field-active');
	vi.restoreAllMocks();
});

// ----------------------------------------------------------------------
// addNewField
// ----------------------------------------------------------------------

describe('custom-fields-admin — addNewField', () => {
	it('appends a row from the wp.template renderer with details visible', async () => {
		window.$('#ffc-add-custom-field').trigger('click');
		await flush();

		const rows = window.$('#ffc-custom-fields-list .ffc-custom-field-row');
		expect(rows.length).toBe(2);
		const $newRow = rows.last();
		expect($newRow.data('field-id')).toMatch(/^new_/);
		expect($newRow.find('.ffc-field-details-row').css('display')).not.toBe('none');
	});
});

// ----------------------------------------------------------------------
// saveFields
// ----------------------------------------------------------------------

describe('custom-fields-admin — saveFields', () => {
	it('bails when the container has no audience-id', async () => {
		window.$('#ffc-custom-fields-container').removeAttr('data-audience-id');
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => ({}));
		window.$('#ffc-save-custom-fields').trigger('click');
		await flush();
		expect(postSpy).not.toHaveBeenCalled();
	});

	it('POSTs ffc_save_custom_fields with the serialised field list', async () => {
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => ({
			done: () => ({
				fail: () => ({ always: () => ({}) }),
			}),
		}));

		// Tag the row choices to verify they reach the payload.
		window.$('.ffc-field-choices').val('apple\nbanana\n\nkiwi');

		window.$('#ffc-save-custom-fields').trigger('click');
		await flush();

		expect(postSpy).toHaveBeenCalled();
		const payload = postSpy.mock.calls[0][1];
		expect(payload.action).toBe('ffc_save_custom_fields');
		expect(payload.audience_id).toBe(7);
		const fields = JSON.parse(payload.fields);
		expect(fields).toHaveLength(1);
		expect(fields[0]).toMatchObject({
			id: 42, source: 'custom', sort_order: 0,
			label: 'Existing', key: 'ext_key',
			is_required: 1, is_active: 1, is_sensitive: 0,
		});
		expect(fields[0].choices).toEqual(['apple', 'banana', 'kiwi']);
	});

	it('collects acknowledgment HTML from the html container into the payload', async () => {
		// The acknowledgment block stores its notice HTML in a textarea inside
		// .ffc-field-html-container (a wp_editor in production).
		window.$('.ffc-field-details-row td').append(
			'<div class="ffc-field-html-container"><textarea class="ffc-field-html"></textarea></div>'
		);
		window.$('.ffc-field-html').val('<p>My notice</p>');

		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => ({
			done: () => ({ fail: () => ({ always: () => ({}) }) }),
		}));

		window.$('#ffc-save-custom-fields').trigger('click');
		await flush();

		const fields = JSON.parse(postSpy.mock.calls[0][1].fields);
		expect(fields[0].html).toBe('<p>My notice</p>');
	});

	it('on success: shows the saved status (page reload is fired but stubbed in test)', async () => {
		vi.useFakeTimers();
		// Stub reload so the test doesn't blow up jsdom navigation.
		const originalLocation = window.location;
		Object.defineProperty(window, 'location', {
			configurable: true,
			writable: true,
			value: { reload: vi.fn(), href: '/', pathname: '/' },
		});
		vi.spyOn(window.$, 'post').mockImplementation(() => ({
			done: function (cb) {
				cb({ success: true });
				return {
					fail: function () { return { always: function (alwaysCb) { alwaysCb(); return {}; } }; },
				};
			},
		}));

		window.$('#ffc-save-custom-fields').trigger('click');
		await flush();

		expect(window.$('#ffc-custom-fields-status').text()).toBe('Saved');
		expect(window.$('#ffc-custom-fields-status').hasClass('ffc-status-success')).toBe(true);

		// After 800ms timer, location.reload() fires.
		vi.advanceTimersByTime(800);
		expect(window.location.reload).toHaveBeenCalled();
		vi.useRealTimers();

		Object.defineProperty(window, 'location', {
			configurable: true,
			writable: true,
			value: originalLocation,
		});
	});

	it('on response.success=false: shows the server error in the status', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => ({
			done: function (cb) {
				cb({ success: false, data: { message: 'Bad key' } });
				return {
					fail: function () { return { always: function (alwaysCb) { alwaysCb(); return {}; } }; },
				};
			},
		}));

		window.$('#ffc-save-custom-fields').trigger('click');
		await flush();

		expect(window.$('#ffc-custom-fields-status').text()).toBe('Bad key');
		expect(window.$('#ffc-custom-fields-status').hasClass('ffc-status-error')).toBe(true);
	});

	it('on network failure: shows the localised error status', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => ({
			done: function () {
				return {
					fail: function (failCb) {
						failCb();
						return { always: function (alwaysCb) { alwaysCb(); return {}; } };
					},
				};
			},
		}));

		window.$('#ffc-save-custom-fields').trigger('click');
		await flush();

		expect(window.$('#ffc-custom-fields-status').text()).toBe('Error');
		expect(window.$('#ffc-custom-fields-status').hasClass('ffc-status-error')).toBe(true);
	});

	it('re-enables the save button after a failed save', async () => {
		// The migrated saveCustomFields chains a `.then` after `.catch`
		// (Promise-style .finally equivalent) to restore the disabled
		// state on both success + error paths. Drive the fail branch and
		// verify the button is re-enabled.
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: true }));

		window.$('#ffc-save-custom-fields').trigger('click');
		await flush();

		expect(window.$('#ffc-save-custom-fields').prop('disabled')).toBe(false);
	});
});

// ----------------------------------------------------------------------
// deleteField
// ----------------------------------------------------------------------

describe('custom-fields-admin — deleteField', () => {
	it('alerts and bails when the row is a standard field', async () => {
		window.$('.ffc-custom-field-row').data('field-source', 'standard');
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => ({}));

		window.$('.ffc-field-delete').trigger('click');
		await flush();

		expect(alertSpy).toHaveBeenCalledWith('Cannot delete standard');
		expect(postSpy).not.toHaveBeenCalled();
	});

	it('bails when the user declines the confirm', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(false);
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => ({}));

		window.$('.ffc-field-delete').trigger('click');
		await flush();

		expect(postSpy).not.toHaveBeenCalled();
		expect(window.$('.ffc-custom-field-row').length).toBe(1);
	});

	it('removes a new (unsaved) row from the DOM without AJAX', async () => {
		window.$('.ffc-custom-field-row').data('field-id', 'new_99');
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => ({}));

		window.$('.ffc-field-delete').trigger('click');
		await flush();

		expect(postSpy).not.toHaveBeenCalled();
		expect(window.$('.ffc-custom-field-row').length).toBe(0);
	});

	it('deletes an existing row via AJAX on success', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.spyOn(window.$, 'post').mockImplementation(() => ({
			done: function (cb) {
				cb({ success: true });
				return { fail: function () { return {}; } };
			},
		}));

		window.$('.ffc-field-delete').trigger('click');
		await flush();

		expect(window.$('.ffc-custom-field-row').length).toBe(0);
	});

	it('on AJAX response.success=false: alerts the server message', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.spyOn(window.$, 'post').mockImplementation(() => ({
			done: function (cb) {
				cb({ success: false, data: { message: 'In use' } });
				return { fail: function () { return {}; } };
			},
		}));
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		window.$('.ffc-field-delete').trigger('click');
		await flush();

		expect(alertSpy).toHaveBeenCalledWith('In use');
		expect(window.$('.ffc-custom-field-row').length).toBe(1);
	});

	it('on AJAX network failure: alerts the localised error', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.spyOn(window.$, 'post').mockImplementation(() => ({
			done: function () {
				return {
					fail: function (failCb) { failCb(); return {}; },
				};
			},
		}));
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		window.$('.ffc-field-delete').trigger('click');
		await flush();

		expect(alertSpy).toHaveBeenCalledWith('Error');
	});
});

// ----------------------------------------------------------------------
// toggleDetails + onFieldTypeChange + onFormatChange + onActiveToggle
// ----------------------------------------------------------------------

describe('custom-fields-admin — row UI handlers', () => {
	it('toggleDetails slides the details row up and down', async () => {
		// Currently hidden — toggling shows it.
		expect(window.$('.ffc-field-details-row').css('display')).toBe('none');
		window.$('.ffc-field-toggle-details').trigger('click');
		await flush();
		expect(window.$('.ffc-field-details-row').css('display')).not.toBe('none');
	});

	it('onFieldTypeChange shows options container only for select type', async () => {
		window.$('.ffc-field-type').val('select').trigger('change');
		await flush();
		expect(window.$('.ffc-field-options-container').css('display')).not.toBe('none');

		window.$('.ffc-field-type').val('text').trigger('change');
		await flush();
		expect(window.$('.ffc-field-options-container').css('display')).toBe('none');
	});

	it('onFieldTypeChange shows the html container only for acknowledgment type', async () => {
		window.$('.ffc-field-type').append('<option value="acknowledgment">ack</option>');
		window.$('.ffc-field-details-row td').append(
			'<div class="ffc-field-html-container" style="display:none"><textarea class="ffc-field-html"></textarea></div>'
		);

		window.$('.ffc-field-type').val('acknowledgment').trigger('change');
		await flush();
		expect(window.$('.ffc-field-html-container').css('display')).not.toBe('none');

		window.$('.ffc-field-type').val('text').trigger('change');
		await flush();
		expect(window.$('.ffc-field-html-container').css('display')).toBe('none');
	});

	it('onFieldTypeChange hides the format row for working_hours type', async () => {
		window.$('.ffc-field-type').val('working_hours').trigger('change');
		await flush();
		// The .ffc-field-detail-row containing .ffc-field-format hides.
		expect(window.$('.ffc-field-format').closest('.ffc-field-detail-row').css('display')).toBe('none');
	});

	it('onFormatChange shows regex inputs only when format=custom_regex', async () => {
		window.$('.ffc-field-format').val('custom_regex').trigger('change');
		await flush();
		expect(window.$('.ffc-field-regex').css('display')).not.toBe('none');
		expect(window.$('.ffc-field-regex-msg').css('display')).not.toBe('none');

		window.$('.ffc-field-format').val('').trigger('change');
		await flush();
		expect(window.$('.ffc-field-regex').css('display')).toBe('none');
	});

	it('onActiveToggle adds .ffc-field-inactive when unchecked', async () => {
		window.$('.ffc-field-active').prop('checked', false).trigger('change');
		await flush();
		expect(window.$('.ffc-custom-field-row').hasClass('ffc-field-inactive')).toBe(true);

		window.$('.ffc-field-active').prop('checked', true).trigger('change');
		await flush();
		expect(window.$('.ffc-custom-field-row').hasClass('ffc-field-inactive')).toBe(false);
	});
});

// ----------------------------------------------------------------------
// saveFields — tinymce flush + dependent_select groups branches
// ----------------------------------------------------------------------

describe('custom-fields-admin — saveFields extra branches', () => {
	it('flushes TinyMCE editors before reading the textareas', async () => {
		const triggerSave = vi.fn();
		window.tinymce = { triggerSave };
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true } }));
		const originalLocation = window.location;
		Object.defineProperty(window, 'location', {
			configurable: true, writable: true, value: { reload: vi.fn() },
		});

		window.$('#ffc-save-custom-fields').trigger('click');
		await flush();
		expect(triggerSave).toHaveBeenCalled();

		Object.defineProperty(window, 'location', {
			configurable: true, writable: true, value: originalLocation,
		});
		delete window.tinymce;
	});

	it('parses the dependent_select groups JSON from the synced hidden input', async () => {
		window.$('.ffc-field-details-row td').append(
			'<input type="hidden" class="ffc-ds-map-json" value=\'{"A":["x","y"]}\'>'
		);
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true } }));
		const originalLocation = window.location;
		Object.defineProperty(window, 'location', {
			configurable: true, writable: true, value: { reload: vi.fn() },
		});

		window.$('#ffc-save-custom-fields').trigger('click');
		await flush();
		const fields = JSON.parse(postSpy.mock.calls[0][1].fields);
		expect(fields[0].groups).toEqual({ A: ['x', 'y'] });

		Object.defineProperty(window, 'location', {
			configurable: true, writable: true, value: originalLocation,
		});
	});

	it('falls back to an empty object when the groups JSON is malformed', async () => {
		window.$('.ffc-field-details-row td').append(
			'<input type="hidden" class="ffc-ds-map-json" value="not-json{">'
		);
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true } }));
		const originalLocation = window.location;
		Object.defineProperty(window, 'location', {
			configurable: true, writable: true, value: { reload: vi.fn() },
		});

		window.$('#ffc-save-custom-fields').trigger('click');
		await flush();
		const fields = JSON.parse(postSpy.mock.calls[0][1].fields);
		expect(fields[0].groups).toEqual({});

		Object.defineProperty(window, 'location', {
			configurable: true, writable: true, value: originalLocation,
		});
	});
});

// ----------------------------------------------------------------------
// replicateFieldOptions — copy option lists to descendant audiences
// ----------------------------------------------------------------------

describe('custom-fields-admin — replicateFieldOptions', () => {
	function mount() {
		document.body.innerHTML = `
			<div id="ffc-custom-fields-container" data-audience-id="9">
				<button id="ffc-replicate-field-options">Replicate</button>
				<span id="ffc-custom-fields-status"></span>
			</div>
		`;
		window.$.fx.off = true;
		if (!window.FFC) { loadScript('assets/js/ffc-core.js'); }
		loadScript('assets/js/ffc-custom-fields-admin.js');
		return new Promise((r) => setTimeout(r, 0));
	}

	afterEach(() => {
		vi.restoreAllMocks();
	});

	it('bails when there is no audience-id', async () => {
		await mount();
		window.$('#ffc-custom-fields-container').removeAttr('data-audience-id');
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => ({}));
		window.$('#ffc-replicate-field-options').trigger('click');
		await flush();
		expect(postSpy).not.toHaveBeenCalled();
	});

	it('bails when the user declines the confirm', async () => {
		await mount();
		vi.spyOn(window, 'confirm').mockReturnValue(false);
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => ({}));
		window.$('#ffc-replicate-field-options').trigger('click');
		await flush();
		expect(postSpy).not.toHaveBeenCalled();
	});

	it('POSTs ffc_replicate_field_options and shows the success message on confirm', async () => {
		await mount();
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: { message: 'Replicated to 4 audiences' } } }));
		window.$('#ffc-replicate-field-options').trigger('click');
		await flush();
		expect(postSpy.mock.calls[0][1].action).toBe('ffc_replicate_field_options');
		expect(postSpy.mock.calls[0][1].audience_id).toBe(9);
		const $status = window.$('#ffc-custom-fields-status');
		expect($status.text()).toBe('Replicated to 4 audiences');
		expect($status.hasClass('ffc-status-success')).toBe(true);
		// Button re-enabled by the trailing .then.
		expect(window.$('#ffc-replicate-field-options').prop('disabled')).toBe(false);
	});

	it('shows the error status when replication fails', async () => {
		await mount();
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: false, data: { message: 'Boom' } } }));
		window.$('#ffc-replicate-field-options').trigger('click');
		await flush();
		const $status = window.$('#ffc-custom-fields-status');
		expect($status.text()).toBe('Boom');
		expect($status.hasClass('ffc-status-error')).toBe(true);
		expect(window.$('#ffc-replicate-field-options').prop('disabled')).toBe(false);
	});
});
