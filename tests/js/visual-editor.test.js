import { describe, it, expect, beforeEach, beforeAll } from 'vitest';
import { loadScript } from './helpers.js';

// The visual certificate editor's canvas (#2585): pure geometry, and the
// editor over a jsdom page. jsdom has no layout, so the fit zoom is pinned by
// choosing a fixed zoom where a test measures a gesture.

function markup(json) {
	document.body.innerHTML = `
		<form>
			<div class="ffc-visual-editor" data-ffc-visual-editor="layout" hidden>
				<p class="ffc-ve-gate" hidden>gate</p>
				<div class="ffc-ve-workspace">
					<button type="button" data-ffc-ve-insert="text">Text</button>
					<button type="button" data-ffc-ve-insert="qr">QR</button>
					<button type="button" data-ffc-ve-insert="script">Bad</button>
					<button type="button" data-ffc-ve-align="right">Right</button>
					<button type="button" data-ffc-ve-align="middle">Middle</button>
					<select data-ffc-ve-orientation><option value="landscape">L</option><option value="portrait">P</option></select>
					<select data-ffc-ve-zoom><option value="fit">Fit</option><option value="0.5">50</option><option value="1">100</option></select>
					<input type="checkbox" data-ffc-ve-snap>
					<button type="button" data-ffc-ve-discard>Discard</button>
					<ul class="ffc-ve-layers"></ul>
					<div class="ffc-ve-stage"><div class="ffc-ve-frame"><div class="ffc-ve-page" tabindex="0"></div></div></div>
				</div>
			</div>
			<textarea id="layout">${json}</textarea>
		</form>`;
}

const LAYOUT = JSON.stringify({
	version: 1,
	page: { orientation: 'landscape' },
	elements: [
		{ id: 'back', type: 'frame', x: 20, y: 20, w: 1083, h: 754, border_width: 2 },
		{ id: 'name', type: 'field', token: '{{name}}', x: 100, y: 300, w: 400, h: 50 },
		{ id: 'seal', type: 'qr', x: 40, y: 660, w: 90, h: 90, locked: true }
	]
});

function editor() {
	const [ed] = window.FFCVisualEditor.boot();
	return ed;
}

function layout() {
	return JSON.parse(document.getElementById('layout').value);
}

function pointer(target, type, x, y) {
	const event = new window.MouseEvent(type, { bubbles: true, clientX: x, clientY: y, button: 0 });
	Object.defineProperty(event, 'pointerId', { value: 1 });
	target.dispatchEvent(event);
}

describe('ffc-visual-editor.js', () => {
	beforeAll(() => {
		window.innerWidth = 1440;
		window.matchMedia = (q) => ({ matches: q === '(pointer: fine)', media: q, addEventListener() {}, removeEventListener() {} });
		loadScript('assets/js/ffc-core.js');
		loadScript('assets/js/ffc-visual-editor.js');
	});

	beforeEach(() => {
		window.innerWidth = 1440;
		markup(LAYOUT);
	});

	describe('geometry', () => {
		const g = () => window.FFCVisualEditor.geometry;

		it('snaps to the 10 px grid, or keeps hundredths', () => {
			expect(g().snap(14, true)).toBe(10);
			expect(g().snap(15, true)).toBe(20);
			expect(g().snap(14.567, false)).toBe(14.57);
		});

		it('moves by a delta on the grid', () => {
			expect(g().move({ x: 100, y: 100 }, 13, -27, true)).toEqual({ x: 110, y: 70 });
			expect(g().move({ x: 100, y: 100 }, 13, -27, false)).toEqual({ x: 113, y: 73 });
		});

		it('resizes from a handle, keeping the opposite side', () => {
			const start = { x: 100, y: 100, w: 200, h: 100 };
			expect(g().resize(start, 'se', 50, 20, false)).toEqual({ x: 100, y: 100, w: 250, h: 120 });
			expect(g().resize(start, 'nw', 50, 20, false)).toEqual({ x: 150, y: 120, w: 150, h: 80 });
			expect(g().resize(start, 'e', -500, 0, false)).toEqual({ x: 100, y: 100, w: 1, h: 100 }, 'never below one pixel');
			expect(g().resize(start, 'n', 0, 500, false)).toEqual({ x: 100, y: 199, w: 200, h: 1 });
			expect(g().resize(start, 's', 0, 13, true)).toEqual({ x: 100, y: 100, w: 200, h: 110 });
		});

		it('aligns a box to each side and the middle of the page', () => {
			const page = { width: 1123, height: 794 };
			const box = { x: 37, y: 41, w: 200, h: 100 };
			expect(g().align(box, page, 'left')).toEqual({ x: 0, y: 41 });
			expect(g().align(box, page, 'center')).toEqual({ x: 461.5, y: 41 });
			expect(g().align(box, page, 'right')).toEqual({ x: 923, y: 41 });
			expect(g().align(box, page, 'top')).toEqual({ x: 37, y: 0 });
			expect(g().align(box, page, 'middle')).toEqual({ x: 37, y: 347 });
			expect(g().align(box, page, 'bottom')).toEqual({ x: 37, y: 694 });
		});

		it('reorders a list and maps rows to stacking positions', () => {
			expect(g().reorder(['a', 'b', 'c'], 0, 2)).toEqual(['b', 'c', 'a']);
			expect(g().reorder(['a', 'b', 'c'], 5, 0)).toEqual(['a', 'b', 'c']);
			expect(g().layerIndex(0, 3)).toBe(2);
			expect(g().layerIndex(2, 3)).toBe(0);
		});

		it('fits the page in the space, never above 100 %', () => {
			expect(g().fitZoom(561.5, 1123)).toBe(0.5);
			expect(g().fitZoom(5000, 1123)).toBe(1);
			expect(g().fitZoom(0, 1123)).toBe(1);
		});

		it('edits only on a wide window with a fine pointer', () => {
			expect(g().canEdit(1280, true)).toBe(true);
			expect(g().canEdit(1279, true)).toBe(false);
			expect(g().canEdit(1920, false)).toBe(false);
		});
	});

	describe('the editor', () => {
		it('draws every element, stacked in list order, with the front first in the layers', () => {
			editor();
			const nodes = [...document.querySelectorAll('.ffc-ve-page [data-ffc-ve-id]')];
			expect(nodes.map((n) => n.getAttribute('data-ffc-ve-id'))).toEqual(['back', 'name', 'seal']);
			expect(nodes[1].style.left).toBe('100px');
			expect(nodes[1].style.zIndex).toBe('2');
			const rows = [...document.querySelectorAll('.ffc-ve-layer')].map((r) => r.textContent);
			expect(rows[0]).toContain('qr');
			expect(rows[2]).toContain('frame');
			expect(document.querySelector('.ffc-ve-layer:last-child').classList.contains('ffc-ve-layer-background')).toBe(true);
		});

		it('inserts an element centred on the grid, selected, and writes the JSON', () => {
			editor();
			document.querySelector('[data-ffc-ve-insert="qr"]').click();
			const added = layout().elements.at(-1);
			expect(added).toMatchObject({ type: 'qr', x: 510, y: 350, w: 100, h: 100 });
			document.querySelector('[data-ffc-ve-insert="script"]').click();
			expect(layout().elements).toHaveLength(4, 'an unknown type is not inserted');
		});

		it('drags and resizes in page pixels, whatever the zoom', () => {
			const ed = editor();
			ed.zoom = 0.5;
			ed.snapOn = false;
			ed.render();
			const node = () => document.querySelector('[data-ffc-ve-id="name"]');
			pointer(node(), 'pointerdown', 200, 200);
			pointer(node(), 'pointermove', 230, 210);
			pointer(node(), 'pointerup', 230, 210);
			expect(layout().elements[1]).toMatchObject({ x: 160, y: 320, w: 400, h: 50 });

			pointer(node().querySelector('[data-ffc-ve-handle="se"]'), 'pointerdown', 0, 0);
			pointer(node(), 'pointermove', 50, 25);
			pointer(node(), 'pointerup', 50, 25);
			expect(layout().elements[1]).toMatchObject({ x: 160, y: 320, w: 500, h: 100 });
		});

		it('does not move a locked layer', () => {
			editor();
			const qr = () => document.querySelector('[data-ffc-ve-id="seal"]');
			pointer(qr(), 'pointerdown', 50, 50);
			pointer(qr(), 'pointermove', 150, 150);
			pointer(qr(), 'pointerup', 150, 150);
			expect(qr().querySelector('[data-ffc-ve-handle]')).toBeNull();
			document.querySelector('.ffc-ve-page').dispatchEvent(new window.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
			expect(layout().elements[2].x).toBe(40);
			expect(document.querySelector('[data-ffc-ve-align="right"]').disabled).toBe(true);
		});

		it('nudges with the arrows, ten at a time with Shift, and deletes with Delete', () => {
			const ed = editor();
			ed.selected = 'name';
			const page = document.querySelector('.ffc-ve-page');
			page.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
			page.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'ArrowDown', shiftKey: true, bubbles: true }));
			expect(layout().elements[1]).toMatchObject({ x: 101, y: 310 });
			page.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Delete', bubbles: true }));
			expect(layout().elements.map((e) => e.id)).toEqual(['back', 'seal']);
		});

		it('aligns the selection to the page', () => {
			const ed = editor();
			ed.selected = 'name';
			ed.render();
			document.querySelector('[data-ffc-ve-align="right"]').click();
			document.querySelector('[data-ffc-ve-align="middle"]').click();
			expect(layout().elements[1]).toMatchObject({ x: 723, y: 372 });
		});

		it('reorders layers with the buttons and locks a layer', () => {
			editor();
			// Row 1 is "name"; bring it forward: it becomes the front.
			document.querySelectorAll('.ffc-ve-layer')[1].querySelector('.ffc-ve-up').click();
			expect(layout().elements.map((e) => e.id)).toEqual(['back', 'seal', 'name']);
			expect(document.querySelectorAll('.ffc-ve-layer')[0].querySelector('.ffc-ve-up').disabled).toBe(true);
			document.querySelectorAll('.ffc-ve-layer')[0].querySelector('.ffc-ve-lock').click();
			expect(layout().elements[2].locked).toBe(true);
		});

		it('changes the page orientation', () => {
			editor();
			const select = document.querySelector('[data-ffc-ve-orientation]');
			select.value = 'portrait';
			select.dispatchEvent(new window.Event('change'));
			expect(layout().page.orientation).toBe('portrait');
			expect(document.querySelector('.ffc-ve-page').style.width).toBe('794px');
		});

		it('discards back to the last save', () => {
			const ed = editor();
			document.querySelector('[data-ffc-ve-insert="text"]').click();
			expect(ed.dirty).toBe(true);
			ed.discard();
			expect(document.getElementById('layout').value).toBe(LAYOUT);
			expect(ed.layout.elements).toHaveLength(3);
			expect(ed.dirty).toBe(false);
		});

		it('says why it cannot edit on a narrow window and keeps the work', () => {
			const ed = editor();
			document.querySelector('[data-ffc-ve-insert="text"]').click();
			window.innerWidth = 1000;
			window.dispatchEvent(new window.Event('resize'));
			expect(document.querySelector('.ffc-ve-gate').hidden).toBe(false);
			expect(document.querySelector('.ffc-ve-workspace').hidden).toBe(true);
			window.innerWidth = 1440;
			window.dispatchEvent(new window.Event('resize'));
			expect(document.querySelector('.ffc-ve-workspace').hidden).toBe(false);
			expect(ed.layout.elements).toHaveLength(4);
		});

		it('loads hand-edited JSON and ignores what is not a layout', () => {
			const ed = editor();
			const textarea = document.getElementById('layout');
			textarea.value = '{"version":1,"elements":[{"type":"qr","id":"q","x":1,"y":2,"w":3,"h":4},{"type":"script"}]}';
			textarea.dispatchEvent(new window.Event('change'));
			expect(ed.layout.elements.map((e) => e.id)).toEqual(['q']);
			textarea.value = 'not json';
			textarea.dispatchEvent(new window.Event('change'));
			expect(ed.layout.elements).toEqual([]);
		});

		it('boots once per editor', () => {
			const first = editor();
			expect(editor()).toBe(first);
			expect(document.querySelector('.ffc-visual-editor').hidden).toBe(false);
		});
	});
});
