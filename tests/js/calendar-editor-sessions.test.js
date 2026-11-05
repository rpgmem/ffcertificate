// The Sessions tab of the personal calendar editor (#2405): a row is added
// focused and without an id, a row is removed, and the rows reorder by
// keyboard through the shared Move up / Move down pair.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

const fixture = `
	<input type="hidden" name="ffc_self_scheduling_sessions_present" value="1">
	<table>
		<tbody id="ffc-sessions-list">
			<tr class="ffc-session-row">
				<td><input type="hidden" name="ffc_self_scheduling_sessions[0][id]" value="4"><input type="text" name="ffc_self_scheduling_sessions[0][name]" value="Room A"></td>
				<td><span class="ffc-session-order"></span></td>
				<td><button type="button" class="ffc-remove-session">Remove</button></td>
			</tr>
			<tr class="ffc-session-row">
				<td><input type="hidden" name="ffc_self_scheduling_sessions[1][id]" value="7"><input type="text" name="ffc_self_scheduling_sessions[1][name]" value="Room B"></td>
				<td><span class="ffc-session-order"></span></td>
				<td><button type="button" class="ffc-remove-session">Remove</button></td>
			</tr>
		</tbody>
	</table>
	<button type="button" id="ffc-add-session">Add Session</button>
`;

const names = () => Array.from(document.querySelectorAll('#ffc-sessions-list input[type="text"]')).map((el) => el.value);

beforeAll(async () => {
	window.ffcSelfSchedulingEditor = { strings: { remove: 'Remove', sessionName: 'Session name' } };
	window.ffc_ajax = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'n', strings: {} };
	window.ffcCoreConfig = { strings: { moveUp: 'Move up', moveDown: 'Move down' } };
	document.body.innerHTML = fixture;
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-calendar-editor.js');
	await new Promise((r) => setTimeout(r, 0));
});

beforeEach(async () => {
	document.getElementById('ffc-sessions-list').outerHTML = fixture.match(/<tbody[\s\S]*<\/tbody>/)[0];
	await new Promise((r) => setTimeout(r, 0));
});

describe('the Sessions tab (#2405)', () => {
	it('adds a named, focused row with no id, after the others', () => {
		document.getElementById('ffc-add-session').click();

		const rows = document.querySelectorAll('#ffc-sessions-list tr');
		expect(rows.length).toBe(3);
		const added = rows[2];
		expect(added.querySelector('input[type="hidden"]').value).toBe('');
		const field = added.querySelector('input[type="text"]');
		expect(field.getAttribute('aria-label')).toBe('Session name');
		expect(field.required).toBe(true);
		expect(document.activeElement).toBe(field);
		expect(added.querySelector('.ffc-remove-session')).not.toBeNull();
		// Its name never collides with a server-rendered row's index.
		const indexes = Array.from(rows).map((r) => r.querySelector('input[type="text"]').name);
		expect(new Set(indexes).size).toBe(3);
	});

	it('removes a row', () => {
		document.querySelector('#ffc-sessions-list .ffc-remove-session').click();

		expect(names()).toEqual(['Room B']);
	});

	it('moves a row by keyboard, the rows being the order the save reads', () => {
		const down = document.querySelector('#ffc-sessions-list tr .ffc-reorder-down');
		expect(down).not.toBeNull();
		down.click();

		expect(names()).toEqual(['Room B', 'Room A']);
	});
});
