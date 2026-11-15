import { describe, it, expect, beforeEach, beforeAll } from 'vitest';
import { loadScript } from './helpers.js';
import { readFileSync } from 'node:fs';

// The visual certificate editor's canvas (#2585) and its properties panel and
// preview (#2586): pure geometry, and the editor over a jsdom page. jsdom has no layout, so the fit zoom is pinned by
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
					<button type="button" data-ffc-ve-align="top">Top</button>
					<button type="button" data-ffc-ve-distribute="x">Distribute x</button>
					<button type="button" data-ffc-ve-distribute="y">Distribute y</button>
					<button type="button" data-ffc-ve-cmd="undo">Undo</button>
					<button type="button" data-ffc-ve-cmd="redo">Redo</button>
					<p data-ffc-ve-status></p>
					<select data-ffc-ve-orientation><option value="landscape">L</option><option value="portrait">P</option></select>
					<select data-ffc-ve-zoom><option value="fit">Fit</option><option value="0.5">50</option><option value="1">100</option></select>
					<input type="checkbox" data-ffc-ve-snap>
					<button type="button" data-ffc-ve-discard>Discard</button>
					<ul class="ffc-ve-layers"></ul>
					<div class="ffc-ve-stage"><div class="ffc-ve-frame"><div class="ffc-ve-page" tabindex="0"></div></div></div>
					<button type="button" data-ffc-ve-preview="1">Preview</button>
				</div>
				<dialog class="ffc-ve-preview">
					<button type="button" data-ffc-ve-preview-close="1">Close</button>
					<div data-ffc-ve-preview-warnings></div>
					<p data-ffc-ve-preview-status></p>
					<img class="ffc-ve-preview-page" alt="" hidden>
				</dialog>
			</div>
			<textarea id="layout">${json}</textarea>
			<input id="ffc_template_bg_image" value="https://example.test/bg.png">
			<input id="title" value="Draft">
			<div data-ffc-ve-properties>
				<p data-ffc-ve-props-empty>Select</p>
				<div data-ffc-ve-props-form hidden>
					<p data-ffc-ve-props-title></p>
					<input type="number" data-ffc-ve-prop="x" data-ffc-ve-number>
					<input type="number" data-ffc-ve-prop="w" data-ffc-ve-number>
					<fieldset data-ffc-ve-for="text">
						<textarea data-ffc-ve-prop="text"></textarea>
						<select data-ffc-ve-insert-token><option value=""></option><option value="{{auth_code}}">a</option></select>
					</fieldset>
					<fieldset data-ffc-ve-for="field"><input data-ffc-ve-prop="token"></fieldset>
					<fieldset data-ffc-ve-for="text field">
						<select data-ffc-ve-prop="font_family"><option value="">Default</option><option value="Georgia, serif">Georgia</option></select>
						<p data-ffc-ve-font-missing hidden></p>
						<input type="number" data-ffc-ve-prop="font_size" data-ffc-ve-number>
						<select data-ffc-ve-prop="font_weight"><option value="400">R</option><option value="700">B</option></select>
						<input type="color" data-ffc-ve-prop="color">
						<select data-ffc-ve-prop="fit"><option value="none">N</option><option value="line">L</option><option value="box">B</option></select>
					</fieldset>
					<fieldset data-ffc-ve-for="image">
						<select data-ffc-ve-image-source><option value="media">M</option><option value="signature">S</option></select>
						<p data-ffc-ve-image-media><button type="button" data-ffc-ve-choose-image="1">Choose</button><span data-ffc-ve-image-name></span></p>
						<select data-ffc-ve-prop="fit"><option value="contain">C</option><option value="cover">V</option></select>
					</fieldset>
					<fieldset data-ffc-ve-for="frame">
						<input type="color" data-ffc-ve-prop="fill">
						<input type="checkbox" data-ffc-ve-no-fill>
						<input type="checkbox" data-ffc-ve-gradient-on>
						<div data-ffc-ve-gradient hidden>
							<input type="number" data-ffc-ve-gradient-angle>
							${[0, 1, 2, 3, 4].map((i) => `<div data-ffc-ve-gradient-stop="${i}"><input type="color" data-ffc-ve-gradient-color><input type="number" data-ffc-ve-gradient-at></div>`).join('')}
							<button type="button" data-ffc-ve-gradient-add>Add</button>
							<button type="button" data-ffc-ve-gradient-remove>Remove</button>
						</div>
					</fieldset>
				</div>
			</div>
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
		window.ffcVisualEditor = {
			types: { text: 'Text', field: 'Data field', image: 'Image', frame: 'Frame', qr: 'QR code' },
			knownFonts: ['georgia', 'serif', 'arial'],
			fontFallback: 'Missing: prints in %s.',
			fontDefault: 'the default',
			missingTags: 'Requires %s.',
			missingFonts: 'Fonts: %s.',
			previewAction: 'ffc_preview_visual_layout',
			previewNonce: 'nonce-1',
			postId: 5,
			ajaxUrl: '/wp-admin/admin-ajax.php',
			previewError: 'Could not draw.',
			imageChosen: 'Image #%d',
			noImage: 'No image.'
		};
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
			expect(rows[0]).toContain('QR code');
			expect(rows[2]).toContain('Frame');
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
			// 15 screen px at 50 % is 30 page px; nothing to line up with there.
			pointer(node(), 'pointerdown', 200, 200);
			pointer(node(), 'pointermove', 215, 210);
			pointer(node(), 'pointerup', 215, 210);
			expect(layout().elements[1]).toMatchObject({ x: 130, y: 320, w: 400, h: 50 });

			pointer(node().querySelector('[data-ffc-ve-handle="se"]'), 'pointerdown', 0, 0);
			pointer(node(), 'pointermove', 50, 25);
			pointer(node(), 'pointerup', 50, 25);
			expect(layout().elements[1]).toMatchObject({ x: 130, y: 320, w: 500, h: 100 });
		});

		it('draws a sample QR code, square in the box, not a label (#2616)', () => {
			window.ffcVisualEditor.pluginUrl = 'https://example.test/wp-content/plugins/ffcertificate/';
			editor();
			const seal = document.querySelector('[data-ffc-ve-id="seal"]');
			const sample = seal.querySelector('img.ffc-ve-qr-sample');
			expect(sample.getAttribute('src')).toBe('https://example.test/wp-content/plugins/ffcertificate/assets/img/qr-sample.svg');
			expect(sample.draggable).toBe(false);
			expect(seal.querySelector('.ffc-ve-el-label').classList.contains('screen-reader-text')).toBe(true, 'the name stays for assistive technology');
			delete window.ffcVisualEditor.pluginUrl;

			// The file ships with the plugin and encodes the plugin's address.
			const svg = readFileSync(process.cwd() + '/assets/img/qr-sample.svg', 'utf8');
			expect(svg).toMatch(/^<svg xmlns="http:\/\/www\.w3\.org\/2000\/svg" viewBox="0 0 29 29"/);
			expect(svg).toContain('https://github.com/rpgmem/ffcertificate');
			expect(svg).not.toMatch(/<script|on\w+=/i);
		});

		it('does not move a locked layer', () => {
			editor();
			const qr = () => document.querySelector('[data-ffc-ve-id="seal"]');
			// A click selects it; a drag on it draws a rectangle (#2613) and
			// never moves it.
			pointer(qr(), 'pointerdown', 50, 50);
			pointer(qr(), 'pointermove', 51, 51);
			pointer(qr(), 'pointerup', 51, 51);
			expect(qr().querySelector('[data-ffc-ve-handle]')).toBeNull();
			document.querySelector('.ffc-ve-page').dispatchEvent(new window.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
			expect(layout().elements[2].x).toBe(40);
			expect(document.querySelector('[data-ffc-ve-align="right"]').disabled).toBe(true);
		});

		it('nudges with the arrows, ten at a time with Shift, and deletes with Delete', () => {
			const ed = editor();
			ed.selectOnly('name');
			const page = document.querySelector('.ffc-ve-page');
			page.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
			page.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'ArrowDown', shiftKey: true, bubbles: true }));
			expect(layout().elements[1]).toMatchObject({ x: 101, y: 310 });
			page.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Delete', bubbles: true }));
			expect(layout().elements.map((e) => e.id)).toEqual(['back', 'seal']);
		});

		it('aligns the selection to the page', () => {
			const ed = editor();
			ed.selectOnly('name');
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
	describe('properties and preview (#2586)', () => {
		const g = () => window.FFCVisualEditor.geometry;
		const panel = () => document.querySelector('[data-ffc-ve-properties]');
		const prop = (name, type) => panel().querySelector((type ? '[data-ffc-ve-for="' + type + '"] ' : '') + '[data-ffc-ve-prop="' + name + '"]');
		const type = (input, value) => {
			input.value = value;
			input.dispatchEvent(new window.Event(input.tagName === 'SELECT' ? 'change' : 'input'));
		};
		const select = (ed, id) => {
			ed.selected = id;
			ed.render();
		};

		it('says whether a font is on the site and what prints instead', () => {
			const known = ['georgia', 'serif'];
			expect(g().fontState('', known)).toEqual({ missing: false, fallback: '' });
			expect(g().fontState('Georgia, serif', known)).toEqual({ missing: false, fallback: '' });
			expect(g().fontState('"Cinzel", Georgia, serif', known)).toEqual({ missing: true, fallback: 'Georgia' });
			expect(g().fontState('Cinzel', known)).toEqual({ missing: true, fallback: '' });
		});

		it('inserts at a caret, replacing a selection', () => {
			expect(g().insertAt('Hello world', 6, 11, '{{name}}')).toEqual({ text: 'Hello {{name}}', caret: 14 });
			expect(g().insertAt('ab', 9, 9, 'X')).toEqual({ text: 'abX', caret: 3 });
		});

		it('shows the panel of the selected element, and only its groups', () => {
			const ed = editor();
			expect(panel().querySelector('[data-ffc-ve-props-empty]').hidden).toBe(false);
			select(ed, 'name');
			expect(panel().querySelector('[data-ffc-ve-props-form]').hidden).toBe(false);
			expect(panel().querySelector('[data-ffc-ve-props-title]').textContent).toBe('Data field · name');
			expect(panel().querySelector('[data-ffc-ve-for="field"]').hidden).toBe(false);
			expect(panel().querySelector('[data-ffc-ve-for="text field"]').hidden).toBe(false);
			expect(panel().querySelector('[data-ffc-ve-for="text"]').hidden).toBe(true);
			expect(panel().querySelector('[data-ffc-ve-for="image"]').hidden).toBe(true);
			expect(prop('x').value).toBe('100');
			expect(prop('token').value).toBe('{{name}}');
			expect(prop('font_size').value).toBe('16', 'the server default when the element carries none');
			expect(prop('fit', 'text field').value).toBe('none');
		});

		it('writes a change into the layout, and an emptied number leaves it', () => {
			const ed = editor();
			select(ed, 'name');
			type(prop('x'), '230');
			type(prop('font_size'), '30');
			type(prop('font_weight'), '700');
			type(prop('color'), '#123456');
			type(prop('fit', 'text field'), 'line');
			const name = layout().elements.find((e) => e.id === 'name');
			expect(name).toMatchObject({ x: 230, font_size: 30, font_weight: 700, color: '#123456', fit: 'line' });
			type(prop('x'), '');
			expect(layout().elements.find((e) => e.id === 'name').x).toBe(230);
			type(prop('w'), '-5');
			expect(layout().elements.find((e) => e.id === 'name').w).toBe(1, 'never below one pixel');
			// The canvas draws the text as it will print.
			expect(document.querySelector('[data-ffc-ve-id="name"] .ffc-ve-el-label').style.fontSize).toBe('30px');
		});

		it('holds a locked element in place but lets its style change', () => {
			const ed = editor();
			ed.layout.elements.push({ id: 'cap', type: 'text', text: 'Hi', x: 5, y: 5, w: 100, h: 20, locked: true });
			select(ed, 'cap');
			expect(prop('x').disabled).toBe(true);
			type(prop('x'), '400');
			expect(ed.layout.elements.find((e) => e.id === 'cap').x).toBe(5);
			type(prop('color'), '#ff0000');
			expect(ed.layout.elements.find((e) => e.id === 'cap').color).toBe('#ff0000');
		});

		it('inserts a tag at the caret of a text', () => {
			const ed = editor();
			ed.layout.elements.push({ id: 't', type: 'text', text: 'Code: ', x: 0, y: 0, w: 100, h: 20 });
			select(ed, 't');
			const area = prop('text');
			area.setSelectionRange(6, 6);
			const picker = panel().querySelector('[data-ffc-ve-insert-token]');
			picker.value = '{{auth_code}}';
			picker.dispatchEvent(new window.Event('change'));
			expect(layout().elements.find((e) => e.id === 't').text).toBe('Code: {{auth_code}}');
			expect(picker.value).toBe('');
		});

		it('switches an image to the signer’s signature, and a frame to no fill', () => {
			const ed = editor();
			ed.layout.elements.push({ id: 'img', type: 'image', attachment_id: 9, x: 0, y: 0, w: 100, h: 50 });
			select(ed, 'img');
			expect(panel().querySelector('[data-ffc-ve-image-name]').textContent).toBe('Image #9');
			const source = panel().querySelector('[data-ffc-ve-image-source]');
			source.value = 'signature';
			source.dispatchEvent(new window.Event('change'));
			expect(layout().elements.find((e) => e.id === 'img').token).toBe('{{signer:signature}}');
			expect(panel().querySelector('[data-ffc-ve-image-media]').hidden).toBe(true);
			type(prop('fit', 'image'), 'cover');
			expect(layout().elements.find((e) => e.id === 'img').fit).toBe('cover');

			select(ed, 'back');
			type(prop('fill'), '#eeeeee');
			expect(layout().elements.find((e) => e.id === 'back').fill).toBe('#eeeeee');
			const none = panel().querySelector('[data-ffc-ve-no-fill]');
			none.checked = true;
			none.dispatchEvent(new window.Event('change'));
			expect(layout().elements.find((e) => e.id === 'back').fill).toBe('');
		});

		it('draws the template\'s page colour and background under the elements (#2612)', () => {
			const field = document.getElementById('ffc_template_bg_image');
			field.value = 'https://example.test/wp-content/plugins/ffcertificate/assets/img/bg.png';
			window.ffcVisualEditor.bgColor = '#fdfbf5';
			editor();
			const backdrop = document.querySelector('.ffc-ve-page > .ffc-ve-backdrop');
			expect(backdrop).not.toBeNull();
			expect(backdrop.parentNode.firstElementChild).toBe(backdrop, 'under every element');
			expect(backdrop.style.getPropertyValue('--ffc-ve-bg-image')).toBe('url("https://example.test/wp-content/plugins/ffcertificate/assets/img/bg.png")');
			expect(backdrop.style.getPropertyValue('--ffc-ve-bg-color')).toBe('#fdfbf5');

			// The field is followed live, and a value that is not a URL draws nothing.
			field.value = 'x") ; background: red';
			field.dispatchEvent(new window.Event('input'));
			expect(backdrop.style.getPropertyValue('--ffc-ve-bg-image')).toBe('none');
			field.value = '/wp-content/uploads/b.jpg';
			field.dispatchEvent(new window.Event('input'));
			expect(backdrop.style.getPropertyValue('--ffc-ve-bg-image')).toBe('url("/wp-content/uploads/b.jpg")');
			expect(document.querySelectorAll('.ffc-ve-backdrop')).toHaveLength(1, 'one backdrop, redrawn');
			delete window.ffcVisualEditor.bgColor;
		});

		it('turns a frame\'s gradient on, edits its colours and turns it off', () => {
			const ed = editor();
			select(ed, 'back');
			const q = (s) => panel().querySelector(s);
			const back = () => layout().elements.find((e) => e.id === 'back');
			const fire = (input, value, kind) => {
				input.value = value;
				input.dispatchEvent(new window.Event(kind || 'input'));
			};
			const on = q('[data-ffc-ve-gradient-on]');
			on.checked = true;
			on.dispatchEvent(new window.Event('change'));
			expect(back().gradient.stops).toHaveLength(2);
			expect(q('[data-ffc-ve-gradient]').hidden).toBe(false);
			expect(q('[data-ffc-ve-gradient-stop="2"]').hidden).toBe(true);
			expect(q('[data-ffc-ve-gradient-remove]').disabled).toBe(true, 'two colours at least');

			fire(q('[data-ffc-ve-gradient-angle]'), '90');
			fire(q('[data-ffc-ve-gradient-angle]'), 'x');
			fire(q('[data-ffc-ve-gradient-stop="1"] [data-ffc-ve-gradient-color]'), '#2f80ed');
			fire(q('[data-ffc-ve-gradient-stop="1"] [data-ffc-ve-gradient-at]'), '140');
			expect(back().gradient).toMatchObject({ angle: 90, stops: [{ at: 0 }, { color: '#2f80ed', at: 100 }] });
			expect(document.querySelector('[data-ffc-ve-id="back"]').style.backgroundImage).toContain('linear-gradient(90deg');

			for (let i = 0; i < 4; i++) {
				q('[data-ffc-ve-gradient-add]').click();
			}
			expect(back().gradient.stops).toHaveLength(5, 'five colours at most');
			expect(q('[data-ffc-ve-gradient-add]').disabled).toBe(true);
			q('[data-ffc-ve-gradient-remove]').click();
			expect(back().gradient.stops).toHaveLength(4);

			on.checked = false;
			on.dispatchEvent(new window.Event('change'));
			expect(back().gradient).toBeUndefined();
			expect(q('[data-ffc-ve-gradient]').hidden).toBe(true);
			expect(document.querySelector('[data-ffc-ve-id="back"]').style.backgroundImage).toBe('');
		});

		it('marks a font the site lacks, naming the one that prints', () => {
			const ed = editor();
			ed.layout.elements.find((e) => e.id === 'name').font_family = '"Cinzel", Georgia, serif';
			select(ed, 'name');
			const note = panel().querySelector('[data-ffc-ve-font-missing]');
			expect(note.hidden).toBe(false);
			expect(note.textContent).toBe('Missing: prints in Georgia.');
			expect(prop('font_family').value).toBe('"Cinzel", Georgia, serif', 'an unlisted stack is kept as an option');
			type(prop('font_family'), 'Georgia, serif');
			expect(note.hidden).toBe(true);
		});

		it('previews the unsaved layout through the server and the PDF capture', async () => {
			const ed = editor();
			const posted = [];
			window.fetch = (url, opts) => {
				posted.push([url, new window.URLSearchParams(opts.body)]);
				return Promise.resolve({ json: () => Promise.resolve({ success: true, data: { html: '<div>page</div>', width: 1123, height: 794, missing: ['{{auth_code}}'], fonts: ['Cinzel'] } }) });
			};
			const captured = [];
			window.FFCLayoutPreview = { capture: (html, w, h) => { captured.push([html, w, h]); return Promise.resolve('data:image/png;base64,AAA'); } };

			expect(await ed.preview()).toBe(true);

			const [url, body] = posted[0];
			expect(url).toBe('/wp-admin/admin-ajax.php');
			expect(body.get('action')).toBe('ffc_preview_visual_layout');
			expect(body.get('nonce')).toBe('nonce-1');
			expect(body.get('post_id')).toBe('5');
			expect(body.get('bg_image')).toBe('https://example.test/bg.png');
			expect(body.get('title')).toBe('Draft');
			expect(JSON.parse(body.get('layout')).elements).toHaveLength(3);
			expect(captured[0]).toEqual(['<div>page</div>', 1123, 794]);
			const img = document.querySelector('.ffc-ve-preview-page');
			expect(img.hidden).toBe(false);
			expect(img.getAttribute('src')).toBe('data:image/png;base64,AAA');
			const warnings = Array.from(document.querySelectorAll('.ffc-ve-preview-warning')).map((p) => p.textContent);
			expect(warnings).toEqual(['Requires {{auth_code}}.', 'Fonts: Cinzel.']);
		});

		it('says why a preview could not be drawn', async () => {
			const ed = editor();
			window.fetch = () => Promise.resolve({ json: () => Promise.resolve({ success: false, data: { message: 'Element 1 has an unknown type "marquee".' } }) });
			expect(await ed.preview()).toBe(false);
			expect(document.querySelector('[data-ffc-ve-preview-status]').textContent).toBe('Element 1 has an unknown type "marquee".');
			window.fetch = () => Promise.reject(new Error('offline'));
			expect(await ed.preview()).toBe(false);
			expect(document.querySelector('[data-ffc-ve-preview-status]').textContent).toBe('Could not draw.');
		});
	});
	describe('the rest of the wiring', () => {
		it('zooms, toggles the grid, and keeps the JSON current on submit', () => {
			const ed = editor();
			const zoom = document.querySelector('[data-ffc-ve-zoom]');
			zoom.value = '0.5';
			zoom.dispatchEvent(new window.Event('change'));
			expect(ed.scale()).toBe(0.5);
			const snap = document.querySelector('[data-ffc-ve-snap]');
			snap.checked = false;
			snap.dispatchEvent(new window.Event('change'));
			expect(ed.snapOn).toBe(false);
			expect(document.querySelector('.ffc-ve-page').classList.contains('is-snapping')).toBe(false);

			ed.layout.elements[0].x = 77;
			ed.dirty = true;
			document.querySelector('form').dispatchEvent(new window.Event('submit'));
			expect(layout().elements[0].x).toBe(77);
			expect(ed.dirty).toBe(false);
		});

		it('guards unsaved work on leaving', () => {
			// Editors booted by earlier tests keep their window listeners, so
			// only the guarded case can be told apart here.
			const ed = editor();
			ed.dirty = true;
			const event = new window.Event('beforeunload', { cancelable: true });
			window.dispatchEvent(event);
			expect(event.defaultPrevented).toBe(true);
			ed.dirty = false;
		});

		it('deselects with Escape and with a click on the empty page', () => {
			const ed = editor();
			ed.selected = 'name';
			ed.render();
			const page = document.querySelector('.ffc-ve-page');
			page.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
			expect(ed.selected).toBe(null);
			ed.selected = 'name';
			pointer(page, 'pointerdown', 5, 5);
			expect(ed.selected).toBe(null);
		});

		it('selects from a layer name and restacks by dropping a row', () => {
			const ed = editor();
			document.querySelectorAll('.ffc-ve-layer-name')[1].click();
			expect(ed.selected).toBe('name');
			const rows = document.querySelectorAll('[data-ffc-ve-row]');
			const drop = new window.Event('drop', { bubbles: true, cancelable: true });
			drop.dataTransfer = { getData: () => '0' };
			rows[2].dispatchEvent(drop);
			// The front row (the QR code) went to the back.
			expect(layout().elements.map((e) => e.id)).toEqual(['seal', 'back', 'name']);
			const bad = new window.Event('drop', { bubbles: true, cancelable: true });
			bad.dataTransfer = { getData: () => 'x' };
			rows[0].dispatchEvent(bad);
			expect(layout().elements.map((e) => e.id)).toEqual(['seal', 'back', 'name']);
		});

		it('picks an image from the Media Library and draws it', () => {
			const ed = editor();
			ed.layout.elements.push({ id: 'img', type: 'image', attachment_id: 0, x: 0, y: 0, w: 100, h: 50, fit: 'cover' });
			ed.selected = 'img';
			ed.render();
			let onSelect = null;
			let opened = false;
			window.wp = {
				media: () => ({
					on: (name, fn) => { onSelect = fn; },
					open: () => { opened = true; },
					state: () => ({ get: () => ({ first: () => ({ toJSON: () => ({ id: 41, url: 'https://example.test/seal.png' }) }) }) })
				})
			};
			document.querySelector('[data-ffc-ve-choose-image]').click();
			expect(opened).toBe(true);
			onSelect();
			expect(layout().elements.find((e) => e.id === 'img').attachment_id).toBe(41);
			const img = document.querySelector('[data-ffc-ve-id="img"] .ffc-ve-picture');
			expect(img.getAttribute('src')).toBe('https://example.test/seal.png');
			// The picture's class is not the element's type class: the picture
			// lets pointer events through, the element must not (#2608).
			expect(img.parentElement.classList.contains('ffc-ve-el-image')).toBe(true);
			expect(img.className).not.toMatch(/\bffc-ve-el-/);
			expect(img.draggable).toBe(false);
			expect(img.classList.contains('is-cover')).toBe(true);
			expect(document.querySelector('[data-ffc-ve-image-name]').textContent).toBe('Image #41');
			delete window.wp;
			ed.chooseImage();
		});

		it('opens and closes the preview from its buttons', async () => {
			const ed = editor();
			const dialog = document.querySelector('.ffc-ve-preview');
			dialog.showModal = () => { dialog.open = true; };
			dialog.close = () => { dialog.open = false; };
			window.fetch = () => Promise.resolve({ json: () => Promise.resolve({ success: true, data: { html: '<div></div>', width: 1, height: 1, missing: [], fonts: [] } }) });
			window.FFCLayoutPreview = { capture: () => Promise.reject(new Error('canvas')) };
			document.querySelector('[data-ffc-ve-preview]').click();
			expect(dialog.open).toBe(true);
			expect(await ed.preview()).toBe(false);
			expect(document.querySelector('[data-ffc-ve-preview-status]').textContent).toBe('Could not draw.');
			delete window.FFCLayoutPreview;
			expect(await ed.preview()).toBe(false);
			document.querySelector('[data-ffc-ve-preview-close]').click();
			expect(dialog.open).toBe(false);
		});

		it('discards through the confirmation dialog only on yes', async () => {
			const ed = editor();
			ed.layout.elements[0].x = 999;
			const ask = window.FFC.confirmDialog;
			window.FFC.confirmDialog = () => Promise.resolve(false);
			expect(await ed.confirmDiscard()).toBe(false);
			expect(ed.layout.elements[0].x).toBe(999);
			window.FFC.confirmDialog = () => Promise.resolve(true);
			expect(await ed.confirmDiscard()).toBe(true);
			expect(ed.layout.elements[0].x).toBe(20);
			window.FFC.confirmDialog = ask;
		});
	});
	describe('refinements (#2588)', () => {
		const g = () => window.FFCVisualEditor.geometry;
		const key = (k, opts) => document.querySelector('.ffc-ve-page').dispatchEvent(new window.KeyboardEvent('keydown', Object.assign({ key: k, bubbles: true, cancelable: true }, opts || {})));
		const el = (id) => layout().elements.find((e) => e.id === id);

		it('aligns to an area, measures the box around boxes and spaces them evenly', () => {
			expect(g().alignTo({ x: 5, y: 5, w: 10, h: 10 }, { x: 100, y: 200, w: 50, h: 30 }, 'right')).toEqual({ x: 140, y: 5 });
			expect(g().bounds([{ x: 10, y: 20, w: 30, h: 5 }, { x: 0, y: 40, w: 5, h: 10 }])).toEqual({ x: 0, y: 20, w: 40, h: 30 });
			// First and last stay; the middle one moves so the gaps are equal.
			expect(g().distribute([{ x: 0, w: 10 }, { x: 90, w: 10 }, { x: 20, w: 20 }], 'x')).toEqual([0, 90, 40]);
			expect(g().distribute([{ y: 0, h: 10 }, { y: 50, h: 10 }], 'y')).toEqual([0, 50]);
		});

		it('finds the guide within reach and ignores what is too far', () => {
			const box = { x: 98, y: 300, w: 100, h: 20 };
			const found = g().guides(box, [{ x: 100, y: 0, w: 10, h: 10 }], 5);
			expect(found).toEqual({ dx: 2, dy: 0, x: [100], y: [] });
			expect(g().guides(box, [{ x: 300, y: 600, w: 10, h: 10 }], 5)).toEqual({ dx: 0, dy: 0, x: [], y: [] });
		});

		it('turns a pointer into a rotation, snapped to a step', () => {
			expect(g().angle(0, 0, 10, 0, 1)).toBe(90);
			expect(g().angle(0, 0, 0, 10, 1)).toBe(180);
			expect(g().angle(0, 0, -10, 0, 1)).toBe(-90);
			expect(g().angle(0, 0, 10, -9, 15)).toBe(45);
		});

		it('undoes and redoes, typing in one field making one step', () => {
			const ed = editor();
			ed.selectOnly('name');
			key('ArrowRight');
			key('ArrowRight');
			expect(el('name').x).toBe(102);
			expect(ed.undo()).toBe(true);
			expect(el('name').x).toBe(100, 'the two nudges in a row were one step');
			expect(ed.redo()).toBe(true);
			expect(el('name').x).toBe(102);
			key('z', { ctrlKey: true });
			expect(el('name').x).toBe(100);
			key('y', { ctrlKey: true });
			expect(el('name').x).toBe(102);
			key('Z', { ctrlKey: true, shiftKey: true });
			expect(el('name').x).toBe(102, 'nothing left to redo');
			expect(document.querySelector('[data-ffc-ve-status]').textContent).toBe('Redone');
		});

		it('brings discarded work back with undo', () => {
			const ed = editor();
			ed.selectOnly('name');
			key('ArrowDown', { shiftKey: true });
			ed.discard();
			expect(el('name').y).toBe(300);
			ed.undo();
			expect(el('name').y).toBe(310);
		});

		it('selects several, moves them together and keeps the locked one', () => {
			const ed = editor();
			ed.zoom = 1;
			ed.snapOn = false;
			ed.render();
			const node = (id) => document.querySelector('[data-ffc-ve-id="' + id + '"]');
			pointer(node('name'), 'pointerdown', 0, 0);
			const shift = new window.MouseEvent('pointerdown', { bubbles: true, clientX: 0, clientY: 0, button: 0, shiftKey: true });
			Object.defineProperty(shift, 'pointerId', { value: 1 });
			node('seal').dispatchEvent(shift);
			expect(ed.selection).toEqual(['name', 'seal']);
			expect(document.querySelector('[data-ffc-ve-props-empty]').textContent).toContain('2 elements selected');
			key('ArrowRight', { shiftKey: true });
			expect(el('name').x).toBe(110);
			expect(el('seal').x).toBe(40, 'the locked QR code stays put');
			// Shift-click again takes it out.
			node('seal').dispatchEvent(shift);
			expect(ed.selection).toEqual(['name']);
		});

		it('selects by dragging a rectangle over the page (#2613)', () => {
			const ed = editor();
			ed.zoom = 1;
			ed.layout.elements = [
				{ id: 'a', type: 'qr', x: 10, y: 10, w: 20, h: 20 },
				{ id: 'b', type: 'qr', x: 100, y: 100, w: 20, h: 20 },
				{ id: 'c', type: 'qr', x: 400, y: 400, w: 20, h: 20 },
				{ id: 'bg', type: 'frame', x: 0, y: 500, w: 1123, h: 294, locked: true },
				{ id: 'lock', type: 'qr', x: 50, y: 50, w: 20, h: 20, locked: true }
			];
			ed.render();
			const page = document.querySelector('.ffc-ve-page');
			pointer(page, 'pointerdown', 0, 0);
			pointer(page, 'pointermove', 150, 150);
			expect(document.querySelector('.ffc-ve-marquee')).not.toBeNull();
			pointer(page, 'pointerup', 150, 150);
			expect(ed.selection).toEqual(['a', 'b'], 'what it touches, locked ones left out');
			expect(document.querySelector('.ffc-ve-marquee')).toBeNull();

			// Shift keeps what was selected; dragging the other way works too.
			const shift = new window.MouseEvent('pointerdown', { bubbles: true, clientX: 450, clientY: 450, button: 0, shiftKey: true });
			Object.defineProperty(shift, 'pointerId', { value: 1 });
			page.dispatchEvent(shift);
			pointer(page, 'pointermove', 380, 380);
			pointer(page, 'pointerup', 380, 380);
			expect(ed.selection).toEqual(['a', 'b', 'c']);

			// A drag that starts on a locked element draws a rectangle too.
			pointer(document.querySelector('[data-ffc-ve-id="bg"]'), 'pointerdown', 430, 600);
			pointer(page, 'pointermove', 90, 90);
			pointer(page, 'pointerup', 90, 90);
			expect(ed.selection).toEqual(['b', 'c']);

			// A click on empty page, without a drag, clears the selection.
			pointer(page, 'pointerdown', 300, 20);
			pointer(page, 'pointermove', 301, 21);
			pointer(page, 'pointerup', 301, 21);
			expect(ed.selection).toEqual([]);
			const g = window.FFCVisualEditor.geometry;
			expect(g.span(10, 10, 0, 30)).toEqual({ x: 0, y: 10, w: 10, h: 20 });
			expect(g.overlaps({ x: 0, y: 0, w: 10, h: 10 }, { x: 10, y: 0, w: 5, h: 5 })).toBe(false, 'touching is not overlapping');
		});

		it('aligns several to each other and distributes three', () => {
			const ed = editor();
			ed.layout.elements = [
				{ id: 'a', type: 'qr', x: 10, y: 10, w: 20, h: 20 },
				{ id: 'b', type: 'qr', x: 100, y: 50, w: 20, h: 20 },
				{ id: 'c', type: 'qr', x: 300, y: 90, w: 20, h: 20 }
			];
			ed.selection = ['a', 'b', 'c'];
			ed.selected = 'c';
			ed.render();
			expect(document.querySelector('[data-ffc-ve-distribute="x"]').disabled).toBe(false);
			document.querySelector('[data-ffc-ve-align="top"]').click();
			expect(layout().elements.map((e) => e.y)).toEqual([10, 10, 10]);
			document.querySelector('[data-ffc-ve-distribute="x"]').click();
			expect(layout().elements.map((e) => e.x)).toEqual([10, 155, 300]);
		});

		it('draws a guide while dragging near another element, and clears it after', () => {
			const ed = editor();
			ed.zoom = 1;
			ed.snapOn = false;
			ed.render();
			const node = () => document.querySelector('[data-ffc-ve-id="name"]');
			pointer(node(), 'pointerdown', 0, 0);
			// The field's left edge comes 3 px from the frame's at 20.
			pointer(node(), 'pointermove', -83, 0);
			expect(document.querySelectorAll('.ffc-ve-guide-x')).toHaveLength(1);
			expect(el('name').x).toBe(100, 'the JSON is written on release');
			pointer(node(), 'pointerup', -83, 0);
			expect(el('name').x).toBe(20);
			expect(document.querySelectorAll('.ffc-ve-guide')).toHaveLength(0);
		});

		it('rotates with the handle and with the keyboard', () => {
			const ed = editor();
			ed.zoom = 1;
			ed.selectOnly('name');
			ed.render();
			const sheet = document.querySelector('.ffc-ve-page');
			sheet.getBoundingClientRect = () => ({ left: 0, top: 0, width: 1123, height: 794 });
			const handle = document.querySelector('[data-ffc-ve-id="name"] [data-ffc-ve-handle="rotate"]');
			expect(handle).not.toBeNull();
			pointer(handle, 'pointerdown', 300, 300);
			// The centre is (300, 325); the pointer to its right turns it 90°.
			pointer(sheet, 'pointermove', 400, 325);
			pointer(sheet, 'pointerup', 400, 325);
			expect(el('name').rotation).toBe(90);
			key('r');
			expect(el('name').rotation).toBe(105);
			key('R', { shiftKey: true, altKey: true });
			expect(el('name').rotation).toBe(104);
		});

		it('copies and pastes between editors, and duplicates', () => {
			const ed = editor();
			ed.selectOnly('name');
			key('c', { ctrlKey: true });
			expect(document.querySelector('[data-ffc-ve-status]').textContent).toBe('1 copied');
			// Another template's editor reads the same clipboard.
			markup(JSON.stringify({ version: 1, page: { orientation: 'landscape' }, elements: [] }));
			const other = editor();
			key('v', { ctrlKey: true });
			expect(layout().elements).toHaveLength(1);
			expect(layout().elements[0]).toMatchObject({ type: 'field', token: '{{name}}', x: 110, y: 310, locked: false });
			expect(other.selection).toEqual([layout().elements[0].id]);
			key('d', { ctrlKey: true });
			expect(layout().elements).toHaveLength(2);
			expect(layout().elements[1]).toMatchObject({ x: 120, y: 320 });
			document.querySelector('[data-ffc-ve-cmd="undo"]').click();
			expect(layout().elements).toHaveLength(1);
		});

		it('works the elements from the keyboard alone', () => {
			const ed = editor();
			key(']');
			expect(ed.selected).toBe('seal', 'the front element first');
			key(']');
			expect(ed.selected).toBe('name');
			key('[');
			expect(ed.selected).toBe('seal');
			key('l');
			expect(el('seal').locked).toBe(false);
			key('ArrowRight', { altKey: true, shiftKey: true });
			expect(el('seal').w).toBe(100);
			key('ArrowDown', { ctrlKey: true });
			expect(layout().elements.map((e) => e.id)).toEqual(['back', 'seal', 'name']);
			key('a', { ctrlKey: true });
			expect(ed.selection).toHaveLength(3);
			expect(document.querySelector('[data-ffc-ve-status]').textContent).toBe('3 elements selected');
			key('Escape');
			expect(ed.selection).toEqual([]);
			key('Delete');
			expect(layout().elements).toHaveLength(3);
		});
	});
});
