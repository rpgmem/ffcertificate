/**
 * FFC visual certificate editor — the page canvas (#2585, item D2 of #1951).
 *
 * Edits the layout JSON of a visual template (#2584) in place: the JSON
 * stays in its textarea, which this script keeps current, so the template's
 * Save posts it and the server validates and renders it as before.
 *
 * Written on native pointer events, with no library. The page is drawn at
 * its own size in page pixels and scaled for the zoom, so a gesture divides
 * its movement by the zoom and page coordinates never depend on the view.
 *
 * Editing needs a window of at least 1280 CSS px and a fine pointer; below
 * that the editor says why and keeps the work, and comes back when the
 * window grows again.
 *
 * @since 6.45.0
 */
(function (window, document) {
	'use strict';

	var GRID = 10;
	var MIN_SIZE = 1;
	var MIN_WIDTH = 1280;
	var PAGES = {
		landscape: { width: 1123, height: 794 },
		portrait: { width: 794, height: 1123 }
	};
	var HANDLES = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];

	/**
	 * Pure geometry, in page pixels. Exported for the tests.
	 */
	var geometry = {
		/**
		 * A value on the grid, or rounded to hundredths.
		 *
		 * @param {number} value
		 * @param {boolean} on
		 * @return {number}
		 */
		snap: function (value, on) {
			return on ? Math.round(value / GRID) * GRID : Math.round(value * 100) / 100;
		},

		/**
		 * Where a box goes when dragged by a delta.
		 *
		 * @param {{x:number,y:number}} start
		 * @param {number} dx
		 * @param {number} dy
		 * @param {boolean} snapOn
		 * @return {{x:number,y:number}}
		 */
		move: function (start, dx, dy, snapOn) {
			return { x: geometry.snap(start.x + dx, snapOn), y: geometry.snap(start.y + dy, snapOn) };
		},

		/**
		 * A box resized from one handle; the opposite side stays put.
		 *
		 * @param {{x:number,y:number,w:number,h:number}} start
		 * @param {string} handle  n, ne, e, se, s, sw, w or nw.
		 * @param {number} dx
		 * @param {number} dy
		 * @param {boolean} snapOn
		 * @return {{x:number,y:number,w:number,h:number}}
		 */
		resize: function (start, handle, dx, dy, snapOn) {
			var left = start.x;
			var top = start.y;
			var right = start.x + start.w;
			var bottom = start.y + start.h;
			if (handle.indexOf('w') !== -1) {
				left = Math.min(geometry.snap(start.x + dx, snapOn), right - MIN_SIZE);
			}
			if (handle.indexOf('e') !== -1) {
				right = Math.max(geometry.snap(right + dx, snapOn), left + MIN_SIZE);
			}
			if (handle.indexOf('n') !== -1) {
				top = Math.min(geometry.snap(start.y + dy, snapOn), bottom - MIN_SIZE);
			}
			if (handle.indexOf('s') !== -1) {
				bottom = Math.max(geometry.snap(bottom + dy, snapOn), top + MIN_SIZE);
			}
			return { x: left, y: top, w: right - left, h: bottom - top };
		},

		/**
		 * A box aligned to a side or the middle of the page.
		 *
		 * @param {{x:number,y:number,w:number,h:number}} box
		 * @param {{width:number,height:number}} page
		 * @param {string} mode  left, center, right, top, middle or bottom.
		 * @return {{x:number,y:number}}
		 */
		align: function (box, page, mode) {
			var x = box.x;
			var y = box.y;
			switch (mode) {
				case 'left': x = 0; break;
				case 'center': x = (page.width - box.w) / 2; break;
				case 'right': x = page.width - box.w; break;
				case 'top': y = 0; break;
				case 'middle': y = (page.height - box.h) / 2; break;
				case 'bottom': y = page.height - box.h; break;
			}
			return { x: Math.round(x * 100) / 100, y: Math.round(y * 100) / 100 };
		},

		/**
		 * A list with one item moved, as a new list.
		 *
		 * @param {Array} list
		 * @param {number} from
		 * @param {number} to
		 * @return {Array}
		 */
		reorder: function (list, from, to) {
			var copy = list.slice();
			if (from < 0 || from >= copy.length || to < 0 || to >= copy.length) {
				return copy;
			}
			copy.splice(to, 0, copy.splice(from, 1)[0]);
			return copy;
		},

		/**
		 * The stacking position (0 = back) of the layer shown at a row of
		 * the list, where the first row is the front.
		 *
		 * @param {number} row
		 * @param {number} count
		 * @return {number}
		 */
		layerIndex: function (row, count) {
			return count - 1 - row;
		},

		/**
		 * The zoom that fits a page in a width, never above 1.
		 *
		 * @param {number} available
		 * @param {number} pageWidth
		 * @return {number}
		 */
		fitZoom: function (available, pageWidth) {
			if (!(available > 0)) {
				return 1;
			}
			return Math.min(1, Math.floor((available / pageWidth) * 100) / 100);
		},

		/**
		 * Whether the window can edit: wide enough, with a fine pointer.
		 *
		 * @param {number} width
		 * @param {boolean} finePointer
		 * @return {boolean}
		 */
		canEdit: function (width, finePointer) {
			return width >= MIN_WIDTH && !!finePointer;
		}
	};

	/**
	 * A node's position and size from an element's box: a run-time value.
	 *
	 * @param {HTMLElement} node
	 * @param {{x:number,y:number,w:number,h:number}} box
	 */
	function applyBox(node, box) {
		node.style.left = box.x + 'px';
		node.style.top = box.y + 'px';
		node.style.width = box.w + 'px';
		node.style.height = box.h + 'px';
	}

	/** What a newly inserted element of each type starts as. */
	var DEFAULTS = {
		text: { w: 400, h: 50, text: '', font_size: 24 },
		field: { w: 400, h: 50, token: '{{name}}', font_size: 24 },
		image: { w: 200, h: 120, attachment_id: 0, token: '', fit: 'contain' },
		qr: { w: 100, h: 100, margin: 0 },
		frame: { w: 600, h: 400, border_width: 2, border_style: 'solid', border_color: '#000000', radius: 0, fill: '' },
		html: { w: 400, h: 100, html: '' }
	};

	/**
	 * @param {HTMLElement} root      The editor's container.
	 * @param {HTMLTextAreaElement} textarea  The layout JSON the form posts.
	 * @param {Object} strings        Translated labels.
	 * @constructor
	 */
	function Editor(root, textarea, strings) {
		this.root = root;
		this.textarea = textarea;
		this.s = strings || {};
		this.saved = textarea.value;
		this.layout = Editor.parse(textarea.value);
		this.selected = null;
		this.zoom = 'fit';
		this.snapOn = true;
		this.dirty = false;
		this.gesture = null;
		this.build();
		this.render();
		this.gate();
	}

	/**
	 * A layout from JSON, or an empty one.
	 *
	 * @param {string} json
	 * @return {Object}
	 */
	Editor.parse = function (json) {
		var data = null;
		try {
			data = JSON.parse(json);
		} catch (e) {
			data = null;
		}
		if (!data || typeof data !== 'object' || !Array.isArray(data.elements)) {
			data = { version: 1, page: { orientation: 'landscape' }, elements: [] };
		}
		data.page = data.page && typeof data.page === 'object' ? data.page : {};
		data.page.orientation = data.page.orientation === 'portrait' ? 'portrait' : 'landscape';
		data.elements = data.elements.filter(function (el) {
			return el && typeof el === 'object' && Object.prototype.hasOwnProperty.call(DEFAULTS, el.type);
		});
		return data;
	};

	Editor.prototype = {
		/** The page size in page pixels. */
		page: function () {
			return PAGES[this.layout.page.orientation];
		},

		/** The zoom in use. */
		scale: function () {
			if (this.zoom !== 'fit') {
				return this.zoom;
			}
			return geometry.fitZoom(this.stage.clientWidth - 32, this.page().width);
		},

		/** Build the fixed parts of the editor once. */
		build: function () {
			var self = this;
			this.workspace = this.root.querySelector('.ffc-ve-workspace');
			this.stage = this.root.querySelector('.ffc-ve-stage');
			this.sheet = this.root.querySelector('.ffc-ve-page');
			this.layers = this.root.querySelector('.ffc-ve-layers');
			this.gateNote = this.root.querySelector('.ffc-ve-gate');

			this.root.querySelectorAll('[data-ffc-ve-insert]').forEach(function (button) {
				button.addEventListener('click', function () {
					self.insert(button.getAttribute('data-ffc-ve-insert'));
				});
			});
			this.root.querySelectorAll('[data-ffc-ve-align]').forEach(function (button) {
				button.addEventListener('click', function () {
					self.alignSelected(button.getAttribute('data-ffc-ve-align'));
				});
			});
			var orientation = this.root.querySelector('[data-ffc-ve-orientation]');
			if (orientation) {
				orientation.value = this.layout.page.orientation;
				orientation.addEventListener('change', function () {
					self.layout.page.orientation = orientation.value === 'portrait' ? 'portrait' : 'landscape';
					self.changed();
				});
			}
			var zoom = this.root.querySelector('[data-ffc-ve-zoom]');
			if (zoom) {
				zoom.addEventListener('change', function () {
					self.zoom = zoom.value === 'fit' ? 'fit' : parseFloat(zoom.value) || 1;
					self.render();
				});
			}
			var snap = this.root.querySelector('[data-ffc-ve-snap]');
			if (snap) {
				snap.checked = this.snapOn;
				snap.addEventListener('change', function () {
					self.snapOn = snap.checked;
					self.render();
				});
			}
			var discard = this.root.querySelector('[data-ffc-ve-discard]');
			if (discard) {
				discard.addEventListener('click', function () {
					self.confirmDiscard();
				});
			}

			this.sheet.addEventListener('pointerdown', function (event) {
				self.pointerDown(event);
			});
			this.sheet.addEventListener('pointermove', function (event) {
				self.pointerMove(event);
			});
			this.sheet.addEventListener('pointerup', function (event) {
				self.pointerUp(event);
			});
			this.sheet.addEventListener('pointercancel', function (event) {
				self.pointerUp(event);
			});
			this.sheet.addEventListener('keydown', function (event) {
				self.keyDown(event);
			});

			// Hand-edited JSON (until the properties panel) loads on change.
			this.textarea.addEventListener('change', function () {
				self.layout = Editor.parse(self.textarea.value);
				self.selected = null;
				self.dirty = true;
				self.render();
			});
			var form = this.textarea.form;
			if (form) {
				form.addEventListener('submit', function () {
					self.sync();
					self.dirty = false;
				});
			}
			window.addEventListener('beforeunload', function (event) {
				if (self.dirty) {
					event.preventDefault();
					event.returnValue = '';
				}
			});
			window.addEventListener('resize', function () {
				self.gate();
				self.render();
			});
		},

		/** Show the editor, or say why it cannot edit here; the work stays. */
		gate: function () {
			var fine = !window.matchMedia || window.matchMedia('(pointer: fine)').matches;
			var ok = geometry.canEdit(window.innerWidth, fine);
			this.root.classList.toggle('is-gated', !ok);
			if (this.gateNote) {
				this.gateNote.hidden = ok;
			}
			if (this.workspace) {
				this.workspace.hidden = !ok;
			}
			return ok;
		},

		/** Write the layout into the textarea the form posts. */
		sync: function () {
			this.textarea.value = JSON.stringify(this.layout, null, 2);
		},

		/** After any change: keep the JSON current and redraw. */
		changed: function () {
			this.dirty = true;
			this.sync();
			this.render();
		},

		/** The index of an element by id, or -1. */
		indexOf: function (id) {
			for (var i = 0; i < this.layout.elements.length; i++) {
				if (this.layout.elements[i].id === id) {
					return i;
				}
			}
			return -1;
		},

		/** A fresh element id. */
		nextId: function (type) {
			var n = this.layout.elements.length + 1;
			while (this.indexOf(type + '-' + n) !== -1) {
				n++;
			}
			return type + '-' + n;
		},

		/**
		 * Add an element of a type, centred on the page and at the front.
		 *
		 * @param {string} type
		 */
		insert: function (type) {
			if (!Object.prototype.hasOwnProperty.call(DEFAULTS, type)) {
				return;
			}
			var page = this.page();
			var el = { id: this.nextId(type), type: type, rotation: 0, locked: false };
			Object.keys(DEFAULTS[type]).forEach(function (key) {
				el[key] = DEFAULTS[type][key];
			});
			if (type === 'text') {
				el.text = this.s.newText || 'Text';
			}
			var at = geometry.align(el, page, 'center');
			el.x = geometry.snap(at.x, this.snapOn);
			el.y = geometry.snap(geometry.align(el, page, 'middle').y, this.snapOn);
			this.layout.elements.push(el);
			this.selected = el.id;
			this.changed();
			this.sheet.focus();
		},

		/** Remove the selected element. */
		removeSelected: function () {
			var index = this.indexOf(this.selected);
			if (index === -1) {
				return;
			}
			this.layout.elements.splice(index, 1);
			this.selected = null;
			this.changed();
		},

		/** Align the selected element to the page. */
		alignSelected: function (mode) {
			var el = this.layout.elements[this.indexOf(this.selected)];
			if (!el || el.locked) {
				return;
			}
			var at = geometry.align(el, this.page(), mode);
			el.x = at.x;
			el.y = at.y;
			this.changed();
		},

		/** Move a layer from one stacking position to another. */
		moveLayer: function (from, to) {
			this.layout.elements = geometry.reorder(this.layout.elements, from, to);
			this.changed();
		},

		/** Lock or unlock a layer. */
		toggleLock: function (id) {
			var el = this.layout.elements[this.indexOf(id)];
			if (el) {
				el.locked = !el.locked;
				this.changed();
			}
		},

		/** Ask, then go back to the last save. */
		confirmDiscard: function () {
			var self = this;
			var ask = window.FFC && window.FFC.confirmDialog
				? window.FFC.confirmDialog({
					title: self.s.discardTitle || 'Discard changes?',
					message: self.s.discardMessage || '',
					confirmLabel: self.s.discard || 'Discard changes',
					category: 'caution',
					icon: 'restore'
				})
				: Promise.resolve(true);
			return ask.then(function (yes) {
				if (yes) {
					self.discard();
				}
				return !!yes;
			});
		},

		/** Back to the layout as last saved. */
		discard: function () {
			this.textarea.value = this.saved;
			this.layout = Editor.parse(this.saved);
			this.selected = null;
			this.dirty = false;
			this.render();
		},

		/* ---- pointer and keyboard ---- */

		pointerDown: function (event) {
			var handle = event.target.closest('[data-ffc-ve-handle]');
			var node = event.target.closest('[data-ffc-ve-id]');
			if (!node) {
				this.selected = null;
				this.render();
				return;
			}
			var id = node.getAttribute('data-ffc-ve-id');
			var el = this.layout.elements[this.indexOf(id)];
			this.selected = id;
			this.sheet.focus();
			if (!el || el.locked || event.button !== 0) {
				this.render();
				return;
			}
			event.preventDefault();
			this.gesture = {
				pointer: event.pointerId,
				handle: handle ? handle.getAttribute('data-ffc-ve-handle') : '',
				x: event.clientX,
				y: event.clientY,
				start: { x: el.x, y: el.y, w: el.w, h: el.h },
				zoom: this.scale(),
				moved: false
			};
			if (this.sheet.setPointerCapture) {
				this.sheet.setPointerCapture(event.pointerId);
			}
			this.render();
		},

		pointerMove: function (event) {
			var g = this.gesture;
			if (!g || g.pointer !== event.pointerId) {
				return;
			}
			var el = this.layout.elements[this.indexOf(this.selected)];
			if (!el) {
				return;
			}
			var dx = (event.clientX - g.x) / g.zoom;
			var dy = (event.clientY - g.y) / g.zoom;
			var box = g.handle
				? geometry.resize(g.start, g.handle, dx, dy, this.snapOn)
				: geometry.move(g.start, dx, dy, this.snapOn);
			el.x = box.x;
			el.y = box.y;
			if (g.handle) {
				el.w = box.w;
				el.h = box.h;
			}
			g.moved = true;
			this.place(el);
		},

		pointerUp: function (event) {
			var g = this.gesture;
			if (!g || g.pointer !== event.pointerId) {
				return;
			}
			this.gesture = null;
			if (this.sheet.releasePointerCapture) {
				this.sheet.releasePointerCapture(event.pointerId);
			}
			if (g.moved) {
				this.changed();
			}
		},

		keyDown: function (event) {
			var el = this.layout.elements[this.indexOf(this.selected)];
			if (!el) {
				return;
			}
			var steps = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
			if (steps[event.key]) {
				event.preventDefault();
				if (el.locked) {
					return;
				}
				var step = event.shiftKey ? 10 : 1;
				el.x = Math.round((el.x + steps[event.key][0] * step) * 100) / 100;
				el.y = Math.round((el.y + steps[event.key][1] * step) * 100) / 100;
				this.changed();
			} else if ((event.key === 'Delete' || event.key === 'Backspace') && !el.locked) {
				event.preventDefault();
				this.removeSelected();
			} else if (event.key === 'Escape') {
				this.selected = null;
				this.render();
			}
		},

		/* ---- drawing ---- */

		render: function () {
			var page = this.page();
			var zoom = this.scale();
			this.sheet.style.width = page.width + 'px';
			this.sheet.style.height = page.height + 'px';
			this.sheet.style.transform = 'scale(' + zoom + ')';
			this.sheet.parentNode.style.width = Math.round(page.width * zoom) + 'px';
			this.sheet.parentNode.style.height = Math.round(page.height * zoom) + 'px';
			this.sheet.classList.toggle('is-snapping', this.snapOn);

			var self = this;
			this.sheet.querySelectorAll('[data-ffc-ve-id]').forEach(function (node) {
				node.parentNode.removeChild(node);
			});
			this.layout.elements.forEach(function (el, index) {
				self.sheet.appendChild(self.drawElement(el, index));
			});
			this.renderLayers();
			this.root.querySelectorAll('[data-ffc-ve-align]').forEach(function (button) {
				var el = self.layout.elements[self.indexOf(self.selected)];
				button.disabled = !el || !!el.locked;
			});
		},

		/** Position one element's node from its box, during a gesture. */
		place: function (el) {
			var node = this.sheet.querySelector('[data-ffc-ve-id="' + el.id + '"]');
			if (node) {
				applyBox(node, el);
			}
		},

		drawElement: function (el, index) {
			var node = document.createElement('div');
			node.className = 'ffc-ve-el ffc-ve-el-' + el.type
				+ (el.id === this.selected ? ' is-selected' : '')
				+ (el.locked ? ' is-locked' : '');
			node.setAttribute('data-ffc-ve-id', el.id);
			node.style.zIndex = String(index + 1);
			node.style.transform = el.rotation ? 'rotate(' + el.rotation + 'deg)' : '';
			if (el.type === 'frame') {
				node.style.border = (el.border_width || 0) + 'px ' + (el.border_style || 'solid') + ' ' + (el.border_color || '#000000');
				node.style.borderRadius = (el.radius || 0) + 'px';
			}
			var label = document.createElement('span');
			label.className = 'ffc-ve-el-label';
			label.textContent = this.describe(el);
			node.appendChild(label);
			if (el.id === this.selected && !el.locked) {
				HANDLES.forEach(function (h) {
					var grip = document.createElement('span');
					grip.className = 'ffc-ve-handle ffc-ve-handle-' + h;
					grip.setAttribute('data-ffc-ve-handle', h);
					node.appendChild(grip);
				});
			}
			applyBox(node, el);
			return node;
		},

		/** What an element shows on the canvas until the preview (D3). */
		describe: function (el) {
			switch (el.type) {
				case 'text': return el.text || '';
				case 'field': return el.token || '';
				case 'image': return el.token ? (this.s.signature || 'Signature') : (this.s.image || 'Image');
				case 'qr': return this.s.qr || 'QR code';
				case 'frame': return '';
				default: return this.s.html || 'HTML block';
			}
		},

		renderLayers: function () {
			if (!this.layers) {
				return;
			}
			var self = this;
			var count = this.layout.elements.length;
			this.layers.textContent = '';
			for (var row = 0; row < count; row++) {
				this.layers.appendChild(this.layerRow(this.layout.elements[geometry.layerIndex(row, count)], row, count));
			}
			var bg = document.createElement('li');
			bg.className = 'ffc-ve-layer ffc-ve-layer-background';
			bg.textContent = this.s.background || 'Background';
			this.layers.appendChild(bg);

			this.layers.ondragover = function (event) {
				event.preventDefault();
			};
			this.layers.ondrop = function (event) {
				event.preventDefault();
				var from = parseInt(event.dataTransfer.getData('text/plain'), 10);
				var target = event.target.closest('[data-ffc-ve-row]');
				if (isNaN(from) || !target) {
					return;
				}
				var to = parseInt(target.getAttribute('data-ffc-ve-row'), 10);
				self.moveLayer(geometry.layerIndex(from, count), geometry.layerIndex(to, count));
			};
		},

		layerRow: function (el, row, count) {
			var self = this;
			var li = document.createElement('li');
			li.className = 'ffc-ve-layer' + (el.id === this.selected ? ' is-selected' : '');
			li.setAttribute('data-ffc-ve-row', String(row));
			li.draggable = true;
			li.addEventListener('dragstart', function (event) {
				event.dataTransfer.setData('text/plain', String(row));
			});

			var name = document.createElement('button');
			name.type = 'button';
			name.className = 'ffc-ve-layer-name';
			// The type, and what tells two of a kind apart: a text, a token.
			var detail = el.type === 'text' || el.type === 'field' || (el.type === 'image' && el.token) ? this.describe(el) : '';
			name.textContent = (this.s.types && this.s.types[el.type] ? this.s.types[el.type] : el.type)
				+ (detail ? ': ' + detail : '');
			name.addEventListener('click', function () {
				self.selected = el.id;
				self.render();
			});
			li.appendChild(name);

			var core = window.FFC;
			var make = function (opts) {
				return core && core.button ? core.button(opts) : null;
			};
			var up = make({ label: this.s.moveUp || 'Bring forward', icon: 'chevron-up', iconOnly: true, className: 'ffc-ve-up', disabled: row === 0 });
			var down = make({ label: this.s.moveDown || 'Send backward', icon: 'chevron-down', iconOnly: true, className: 'ffc-ve-down', disabled: row === count - 1 });
			var lock = make({
				label: el.locked ? (this.s.unlock || 'Unlock') : (this.s.lock || 'Lock'),
				icon: el.locked ? 'lock' : 'unlock',
				iconOnly: true,
				className: 'ffc-ve-lock',
				attrs: { 'aria-pressed': el.locked ? 'true' : 'false' }
			});
			var index = geometry.layerIndex(row, count);
			if (up) {
				up.addEventListener('click', function () {
					self.moveLayer(index, index + 1);
				});
				li.appendChild(up);
			}
			if (down) {
				down.addEventListener('click', function () {
					self.moveLayer(index, index - 1);
				});
				li.appendChild(down);
			}
			if (lock) {
				lock.addEventListener('click', function () {
					self.toggleLock(el.id);
				});
				li.appendChild(lock);
			}
			return li;
		}
	};

	/**
	 * Start every editor on the page.
	 *
	 * @return {Array<Editor>}
	 */
	function boot() {
		var strings = window.ffcVisualEditor || {};
		var editors = [];
		document.querySelectorAll('[data-ffc-visual-editor]').forEach(function (root) {
			if (root.ffcVisualEditor) {
				editors.push(root.ffcVisualEditor);
				return;
			}
			var textarea = document.getElementById(root.getAttribute('data-ffc-visual-editor'));
			if (!textarea) {
				return;
			}
			root.hidden = false;
			root.ffcVisualEditor = new Editor(root, textarea, strings);
			editors.push(root.ffcVisualEditor);
		});
		return editors;
	}

	window.FFCVisualEditor = { geometry: geometry, Editor: Editor, boot: boot };

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})(window, document);
