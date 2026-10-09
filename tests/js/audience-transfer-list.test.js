// Shared audience picker (#1648), assets/js/ffc-audience-transfer-list.js.
// Moved out of the reregistration admin script when Date Messages needed the
// same picker; these tests moved with it.
import { describe, it, expect, vi } from 'vitest';
import { loadScript } from './helpers.js';

function flush() { return Promise.resolve().then(() => Promise.resolve()); }

async function reload() {
	loadScript('assets/js/ffc-audience-transfer-list.js');
	await new Promise((r) => setTimeout(r, 0));
}

describe('audience transfer list', () => {
	function mountTransfer(opts) {
		opts = opts || {};
		const audiences = opts.audiences || [
			{ id: 1, name: 'Group A', color: '#aaa' },
			{ id: 2, name: 'Group B', color: '#bbb' },
			{ id: 3, name: 'Group C', color: '#ccc' },
		];
		const selected = opts.selected || [];
		document.body.innerHTML = `
			<form>
				<div class="ffc-transfer-list"
					data-audiences='${JSON.stringify(audiences)}'
					data-selected='${JSON.stringify(selected)}'
					data-field-name="${opts.fieldName || 'rule[audience_ids][]'}"${opts.required === false ? '' : ' data-required="1"'}>
					<input type="text" class="ffc-transfer-search">
					<div class="ffc-transfer-available">
						<div class="ffc-transfer-items"></div>
					</div>
					<button type="button" class="ffc-transfer-add">→</button>
					<button type="button" class="ffc-transfer-add-all">»</button>
					<button type="button" class="ffc-transfer-remove">←</button>
					<button type="button" class="ffc-transfer-remove-all">«</button>
					<div class="ffc-transfer-selected">
						<div class="ffc-transfer-items"></div>
					</div>
					<div class="ffc-transfer-hidden-inputs"></div>
				</div>
				<div class="ffc-transfer-member-count"></div>
				<button type="submit">Save</button>
			</form>
		`;
	}

	it('initial render shows availables on the left and seeds selected hidden inputs', async () => {
		mountTransfer({ selected: [2] });
		await reload();

		const $sel = window.$('.ffc-transfer-selected .ffc-transfer-item');
		const $av = window.$('.ffc-transfer-available .ffc-transfer-item');
		expect($sel.length).toBe(1);
		expect($av.length).toBe(2);
		expect(window.$('.ffc-transfer-hidden-inputs input').length).toBe(1);
		expect(window.$('.ffc-transfer-hidden-inputs input').val()).toBe('2');
	});

	it('double-click on an available item moves it to selected', async () => {
		mountTransfer();
		await reload();
		window.$('.ffc-transfer-available .ffc-transfer-item[data-id="1"]').trigger('dblclick');
		await flush();
		expect(window.$('.ffc-transfer-selected .ffc-transfer-item[data-id="1"]').length).toBe(1);
	});

	it('double-click on a selected item moves it back to available', async () => {
		mountTransfer({ selected: [1, 2] });
		await reload();
		window.$('.ffc-transfer-selected .ffc-transfer-item[data-id="1"]').trigger('dblclick');
		await flush();
		expect(window.$('.ffc-transfer-selected .ffc-transfer-item[data-id="1"]').length).toBe(0);
		expect(window.$('.ffc-transfer-available .ffc-transfer-item[data-id="1"]').length).toBe(1);
	});

	it('arrow button "→" moves all highlighted availables to selected', async () => {
		mountTransfer();
		await reload();
		window.$('.ffc-transfer-available .ffc-transfer-item').addClass('ffc-transfer-highlight');
		window.$('.ffc-transfer-add').trigger('click');
		await flush();
		expect(window.$('.ffc-transfer-selected .ffc-transfer-item').length).toBe(3);
	});

	it('"add all" button moves every item to selected', async () => {
		mountTransfer();
		await reload();
		window.$('.ffc-transfer-add-all').trigger('click');
		await flush();
		expect(window.$('.ffc-transfer-selected .ffc-transfer-item').length).toBe(3);
	});

	it('"remove all" button clears the selected column', async () => {
		mountTransfer({ selected: [1, 2, 3] });
		await reload();
		window.$('.ffc-transfer-remove-all').trigger('click');
		await flush();
		expect(window.$('.ffc-transfer-selected .ffc-transfer-item').length).toBe(0);
	});

	it('search filter narrows the available list', async () => {
		mountTransfer();
		await reload();
		window.$('.ffc-transfer-search').val('group a').trigger('input');
		await flush();
		const labels = window.$('.ffc-transfer-available .ffc-transfer-label').map((_, el) => el.textContent).get();
		expect(labels.every((l) => l.toLowerCase().includes('group a'))).toBe(true);
	});

	it('selecting a parent cascades its children into the selected column', async () => {
		mountTransfer({
			audiences: [
				{ id: 10, name: 'Parent', color: '#000', children: [11, 12] },
				{ id: 11, name: 'Child 1', color: '#111', parent: 10 },
				{ id: 12, name: 'Child 2', color: '#222', parent: 10 },
			],
		});
		await reload();
		window.$('.ffc-transfer-available .ffc-transfer-item[data-id="10"]').trigger('dblclick');
		await flush();
		// Parent + both children land in selected.
		expect(window.$('.ffc-transfer-selected .ffc-transfer-item').length).toBe(3);
	});

	it('removing a selected parent cascades its children back to available', async () => {
		mountTransfer({
			audiences: [
				{ id: 10, name: 'Parent', color: '#000', children: [11, 12] },
				{ id: 11, name: 'Child 1', color: '#111', parent: 10 },
				{ id: 12, name: 'Child 2', color: '#222', parent: 10 },
			],
			selected: [10, 11, 12],
		});
		await reload();
		expect(window.$('.ffc-transfer-selected .ffc-transfer-item').length).toBe(3);

		// Double-click the parent in the selected column → removeAudience(10)
		// also splices its children (11, 12) out of selectedIds.
		window.$('.ffc-transfer-selected .ffc-transfer-item[data-id="10"]').trigger('dblclick');
		await flush();
		expect(window.$('.ffc-transfer-selected .ffc-transfer-item').length).toBe(0);
		expect(window.$('.ffc-transfer-available .ffc-transfer-item').length).toBe(3);
	});

	it('arrow button "←" moves all highlighted selected items back to available', async () => {
		mountTransfer({ selected: [1, 2, 3] });
		await reload();
		window.$('.ffc-transfer-selected .ffc-transfer-item').addClass('ffc-transfer-highlight');
		window.$('.ffc-transfer-remove').trigger('click');
		await flush();
		expect(window.$('.ffc-transfer-selected .ffc-transfer-item').length).toBe(0);
		expect(window.$('.ffc-transfer-available .ffc-transfer-item').length).toBe(3);
	});

	it('"←" tolerates a child already removed via its parent cascade', async () => {
		// Parent + children all selected and highlighted. Removing the parent
		// cascade-removes the children; the subsequent removeAudience(child)
		// then finds idx === -1 and short-circuits.
		mountTransfer({
			audiences: [
				{ id: 10, name: 'Parent', color: '#000', children: [11, 12] },
				{ id: 11, name: 'Child 1', color: '#111', parent: 10 },
				{ id: 12, name: 'Child 2', color: '#222', parent: 10 },
			],
			selected: [10, 11, 12],
		});
		await reload();
		window.$('.ffc-transfer-selected .ffc-transfer-item').addClass('ffc-transfer-highlight');
		window.$('.ffc-transfer-remove').trigger('click');
		await flush();
		expect(window.$('.ffc-transfer-selected .ffc-transfer-item').length).toBe(0);
		expect(window.$('.ffc-transfer-available .ffc-transfer-item').length).toBe(3);
	});

	it('form submit with no selection prevents default and pulses the error class', async () => {
		mountTransfer();
		await reload();
		const ev = window.$.Event('submit');
		window.$('form').trigger(ev);
		await flush();
		expect(ev.isDefaultPrevented()).toBe(true);
		expect(window.$('.ffc-transfer-selected').hasClass('ffc-transfer-error')).toBe(true);
	});

	it('clears the error pulse after the 2000ms timeout', async () => {
		mountTransfer();
		await reload();
		vi.useFakeTimers();
		window.$('form').trigger(window.$.Event('submit'));
		expect(window.$('.ffc-transfer-selected').hasClass('ffc-transfer-error')).toBe(true);
		vi.advanceTimersByTime(2000);
		expect(window.$('.ffc-transfer-selected').hasClass('ffc-transfer-error')).toBe(false);
		vi.useRealTimers();
	});

	it('clicking a transfer-item toggles the highlight class', async () => {
		mountTransfer();
		await reload();
		const $first = window.$('.ffc-transfer-available .ffc-transfer-item').first();
		$first.trigger('click');
		await flush();
		expect($first.hasClass('ffc-transfer-highlight')).toBe(true);
		$first.trigger('click');
		await flush();
		expect($first.hasClass('ffc-transfer-highlight')).toBe(false);
	});
	it('posts the hidden inputs under the field name the caller gave', async () => {
		mountTransfer({ selected: [1, 3], fieldName: 'rereg_audience_ids[]' });
		await reload();
		const names = window.$('.ffc-transfer-hidden-inputs input').map((_, el) => el.name).get();
		expect(names).toEqual(['rereg_audience_ids[]', 'rereg_audience_ids[]']);
	});

	it('lets an optional picker submit with nothing chosen', async () => {
		mountTransfer({ required: false });
		await reload();
		const ev = window.$.Event('submit');
		window.$('form').trigger(ev);
		expect(ev.isDefaultPrevented()).toBe(false);
	});

	it('cascades a whole subtree, grandchildren included, and indents by depth', async () => {
		mountTransfer({
			audiences: [
				{ id: 10, name: 'Root', color: '#000', parent: 0, depth: 0, children: [11] },
				{ id: 11, name: 'Child', color: '#111', parent: 10, depth: 1, children: [12] },
				{ id: 12, name: 'Grandchild', color: '#222', parent: 11, depth: 2, children: [] },
			],
		});
		await reload();
		expect(window.$('.ffc-transfer-available .ffc-transfer-item[data-id="12"]').hasClass('ffc-transfer-grandchild')).toBe(true);
		expect(window.$('.ffc-transfer-available .ffc-transfer-item[data-id="12"] .ffc-transfer-label').text()).toBe('— — Grandchild');

		window.$('.ffc-transfer-available .ffc-transfer-item[data-id="10"]').trigger('dblclick');
		await flush();
		expect(window.$('.ffc-transfer-selected .ffc-transfer-item').length).toBe(3);

		window.$('.ffc-transfer-selected .ffc-transfer-item[data-id="11"]').trigger('dblclick');
		await flush();
		expect(window.$('.ffc-transfer-selected .ffc-transfer-item').map((_, el) => el.getAttribute('data-id')).get()).toEqual(['10']);
	});

	it('announces every change with the chosen ids', async () => {
		mountTransfer();
		const seen = [];
		window.$(document).on('ffc:transfer-list-change.test', '.ffc-transfer-list', (e, ids) => { seen.push(ids); });
		await reload();
		window.$('.ffc-transfer-available .ffc-transfer-item[data-id="2"]').trigger('dblclick');
		await flush();
		window.$(document).off('.test');
		expect(seen[0]).toEqual([]);
		expect(seen[seen.length - 1]).toEqual([2]);
	});

	it('ignores malformed data attributes', async () => {
		document.body.innerHTML = '<form><div class="ffc-transfer-list" data-audiences="{oops" data-selected="nope"><div class="ffc-transfer-available"><div class="ffc-transfer-items"></div></div><div class="ffc-transfer-selected"><div class="ffc-transfer-items"></div></div><div class="ffc-transfer-hidden-inputs"></div></div></form>';
		await reload();
		expect(window.$('.ffc-transfer-item').length).toBe(0);
	});

	it('initialises a picker inserted later through initAll', async () => {
		document.body.innerHTML = '';
		await reload();
		mountTransfer({ selected: [1] });
		window.FFC.AudienceTransferList.initAll(document);
		expect(window.$('.ffc-transfer-selected .ffc-transfer-item').length).toBe(1);
	});
});
