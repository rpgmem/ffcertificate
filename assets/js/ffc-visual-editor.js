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
 * The properties panel (#2586) sits in the side column, outside the editor's
 * container: its controls carry no name, and every change is written into
 * the layout, so the form still posts the JSON alone.
 *
 * Refinements (#2588), built in-house rather than adopting a library: undo
 * and redo, alignment guides to other elements and the page, a selection of
 * several elements with align and distribute among them, a rotation handle,
 * a clipboard that carries elements between visual templates, and every
 * operation on the keyboard.
 *
 * @since 6.45.0
 */
(function (window, document) {
	'use strict';

	var GRID = 10;
	var MIN_SIZE = 1;
	// A frame's gradient holds two to five colours, as the server keeps.
	var GRADIENT_MIN = 2;
	var GRADIENT_MAX = 5;
	var MIN_WIDTH = 1280;
	var PAGES = {
		landscape: { width: 1123, height: 794 },
		portrait: { width: 794, height: 1123 }
	};
	var HANDLES = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
	/** How many steps undo keeps. */
	var HISTORY = 100;
	/** How close, in screen pixels, an edge comes before a guide takes it. */
	var GUIDE = 6;
	/** Where copied elements wait, so another template's editor can paste them. */
	var CLIP_KEY = 'ffcVisualEditorClipboard';
	/** The clipboard when the browser keeps no storage. */
	var memoryClip = '';

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
			return geometry.alignTo(box, { x: 0, y: 0, w: page.width, h: page.height }, mode);
		},

		/**
		 * A box aligned to a side or the middle of an area.
		 *
		 * @param {{x:number,y:number,w:number,h:number}} box
		 * @param {{x:number,y:number,w:number,h:number}} area
		 * @param {string} mode  left, center, right, top, middle or bottom.
		 * @return {{x:number,y:number}}
		 */
		alignTo: function (box, area, mode) {
			var x = box.x;
			var y = box.y;
			switch (mode) {
				case 'left': x = area.x; break;
				case 'center': x = area.x + (area.w - box.w) / 2; break;
				case 'right': x = area.x + area.w - box.w; break;
				case 'top': y = area.y; break;
				case 'middle': y = area.y + (area.h - box.h) / 2; break;
				case 'bottom': y = area.y + area.h - box.h; break;
			}
			return { x: Math.round(x * 100) / 100, y: Math.round(y * 100) / 100 };
		},

		/**
		 * The box two corners span, whichever way they were dragged (#2613).
		 *
		 * @return {{x:number,y:number,w:number,h:number}}
		 */
		span: function (x1, y1, x2, y2) {
			return { x: Math.min(x1, x2), y: Math.min(y1, y2), w: Math.abs(x2 - x1), h: Math.abs(y2 - y1) };
		},

		/**
		 * Whether two boxes overlap; touching edges do not.
		 *
		 * @return {boolean}
		 */
		overlaps: function (a, b) {
			return a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;
		},

		/**
		 * The box around several boxes.
		 *
		 * @param {Array<{x:number,y:number,w:number,h:number}>} boxes
		 * @return {{x:number,y:number,w:number,h:number}}
		 */
		bounds: function (boxes) {
			var left = Infinity;
			var top = Infinity;
			var right = -Infinity;
			var bottom = -Infinity;
			boxes.forEach(function (b) {
				left = Math.min(left, b.x);
				top = Math.min(top, b.y);
				right = Math.max(right, b.x + b.w);
				bottom = Math.max(bottom, b.y + b.h);
			});
			return boxes.length ? { x: left, y: top, w: right - left, h: bottom - top } : { x: 0, y: 0, w: 0, h: 0 };
		},

		/**
		 * Positions that space boxes evenly along an axis, the first and the
		 * last staying put; in the order the boxes were given.
		 *
		 * @param {Array<{x:number,y:number,w:number,h:number}>} boxes
		 * @param {string} axis  x or y.
		 * @return {Array<number>} The new start of each box on the axis.
		 */
		distribute: function (boxes, axis) {
			var size = axis === 'x' ? 'w' : 'h';
			var order = boxes.map(function (b, i) {
				return i;
			}).sort(function (a, b) {
				return boxes[a][axis] - boxes[b][axis];
			});
			var out = boxes.map(function (b) {
				return b[axis];
			});
			if (boxes.length < 3) {
				return out;
			}
			var first = boxes[order[0]];
			var last = boxes[order[order.length - 1]];
			var total = 0;
			order.forEach(function (i) {
				total += boxes[i][size];
			});
			var gap = (last[axis] + last[size] - first[axis] - total) / (order.length - 1);
			var at = first[axis];
			order.forEach(function (i) {
				out[i] = Math.round(at * 100) / 100;
				at += boxes[i][size] + gap;
			});
			return out;
		},

		/**
		 * The nudge that lines a box's edges or centre up with another box's,
		 * within a distance, and the lines to draw for it.
		 *
		 * @param {{x:number,y:number,w:number,h:number}} box
		 * @param {Array<{x:number,y:number,w:number,h:number}>} targets
		 * @param {number} threshold  In page pixels.
		 * @return {{dx:number,dy:number,x:Array<number>,y:Array<number>}}
		 */
		guides: function (box, targets, threshold) {
			var pick = function (own, others) {
				var best = null;
				own.forEach(function (o) {
					others.forEach(function (t) {
						var d = t - o;
						if (Math.abs(d) <= threshold && (best === null || Math.abs(d) < Math.abs(best.d))) {
							best = { d: d, at: t };
						}
					});
				});
				return best;
			};
			var lines = function (b, start, size) {
				return [b[start], b[start] + b[size] / 2, b[start] + b[size]];
			};
			var xs = [];
			var ys = [];
			targets.forEach(function (t) {
				xs = xs.concat(lines(t, 'x', 'w'));
				ys = ys.concat(lines(t, 'y', 'h'));
			});
			var bx = pick(lines(box, 'x', 'w'), xs);
			var by = pick(lines(box, 'y', 'h'), ys);
			return {
				dx: bx ? bx.d : 0,
				dy: by ? by.d : 0,
				x: bx ? [bx.at] : [],
				y: by ? [by.at] : []
			};
		},

		/**
		 * The rotation that points a box's top towards a point, in degrees
		 * clockwise from upright, rounded to a step.
		 *
		 * @param {number} cx  Centre.
		 * @param {number} cy
		 * @param {number} px  Pointer.
		 * @param {number} py
		 * @param {number} step
		 * @return {number}
		 */
		angle: function (cx, cy, px, py, step) {
			var deg = Math.atan2(py - cy, px - cx) * 180 / Math.PI + 90;
			deg = Math.round(deg / step) * step;
			while (deg > 180) {
				deg -= 360;
			}
			while (deg <= -180) {
				deg += 360;
			}
			return deg === 0 ? 0 : deg;
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
		},

		/**
		 * Whether a font stack's first family is on the site, and what prints
		 * when it is not: the next family of the stack the site has, or ''
		 * for the device's default.
		 *
		 * @param {string} stack
		 * @param {Array<string>} known  Lowercase family names the site has.
		 * @return {{missing:boolean,fallback:string}}
		 */
		fontState: function (stack, known) {
			var names = String(stack || '').split(',').map(function (name) {
				return name.replace(/["']/g, '').trim();
			}).filter(function (name) {
				return name !== '';
			});
			var has = function (name) {
				return (known || []).indexOf(name.toLowerCase()) !== -1;
			};
			if (!names.length || has(names[0])) {
				return { missing: false, fallback: '' };
			}
			for (var i = 1; i < names.length; i++) {
				if (has(names[i])) {
					return { missing: true, fallback: names[i] };
				}
			}
			return { missing: true, fallback: '' };
		},

		/**
		 * A value inserted into a text at a caret, and where the caret goes.
		 *
		 * @param {string} text
		 * @param {number} start
		 * @param {number} end
		 * @param {string} insert
		 * @return {{text:string,caret:number}}
		 */
		insertAt: function (text, start, end, insert) {
			text = String(text || '');
			start = Math.max(0, Math.min(start, text.length));
			end = Math.max(start, Math.min(end, text.length));
			return { text: text.slice(0, start) + insert + text.slice(end), caret: start + insert.length };
		}
	};

	/**
	 * What a property reads as when the element does not carry it: the
	 * server's defaults (#2584), so the panel never shows a value the PDF
	 * would not print.
	 */
	var PROP_DEFAULTS = {
		rotation: 0,
		font_family: '',
		font_size: 16,
		font_weight: 400,
		font_style: 'normal',
		color: '#000000',
		align: 'left',
		letter_spacing: 0,
		line_height: 1.2,
		text_transform: 'none',
		border_width: 1,
		border_style: 'solid',
		border_color: '#000000',
		radius: 0,
		margin: 0,
		text: '',
		token: '',
		html: ''
	};

	/** Properties that move or size the box, which a lock holds. */
	var GEOMETRY = ['x', 'y', 'w', 'h', 'rotation'];

	/**
	 * The CSS of a frame's gradient, or '' for none (#2609).
	 *
	 * @param {{angle:number, stops:Array<{color:string, at:number}>}|undefined} g
	 * @return {string}
	 */
	function gradientCss(g) {
		if (!g || !Array.isArray(g.stops) || g.stops.length < 2) {
			return '';
		}
		return 'linear-gradient(' + (Number(g.angle) || 0) + 'deg,' + g.stops.map(function (s) {
			return s.color + ' ' + (Number(s.at) || 0) + '%';
		}).join(',') + ')';
	}

	/**
	 * The text style an element shows on the canvas: the operator's font,
	 * size and colour, so the page reads as it will print.
	 *
	 * @param {Object} el
	 * @return {string}
	 */
	function textCss(el) {
		var css = [
			'font-size:' + (el.font_size || PROP_DEFAULTS.font_size) + 'px',
			'font-weight:' + (el.font_weight || PROP_DEFAULTS.font_weight),
			'font-style:' + (el.font_style || PROP_DEFAULTS.font_style),
			'color:' + (el.color || PROP_DEFAULTS.color),
			'text-align:' + (el.align || PROP_DEFAULTS.align),
			'letter-spacing:' + (el.letter_spacing || 0) + 'px',
			'line-height:' + (el.line_height || PROP_DEFAULTS.line_height),
			'text-transform:' + (el.text_transform || PROP_DEFAULTS.text_transform)
		];
		if (el.font_family) {
			css.push('font-family:' + String(el.font_family).replace(/[;{}<>]/g, ''));
		}
		return css.join(';');
	}

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
		this.selection = [];
		this.zoom = 'fit';
		this.snapOn = true;
		this.dirty = false;
		this.gesture = null;
		this.images = {};
		this.current = JSON.stringify(this.layout);
		this.past = [];
		this.future = [];
		this.lastKey = '';
		this.lastAt = 0;
		this.build();
		this.bindProperties();
		this.bindPreview();
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
			this.root.querySelectorAll('[data-ffc-ve-distribute]').forEach(function (button) {
				button.addEventListener('click', function () {
					self.distributeSelected(button.getAttribute('data-ffc-ve-distribute'));
				});
			});
			this.root.querySelectorAll('[data-ffc-ve-cmd]').forEach(function (button) {
				button.addEventListener('click', function () {
					self.command(button.getAttribute('data-ffc-ve-cmd'));
				});
			});
			this.status = this.root.querySelector('[data-ffc-ve-status]');
			var orientation = this.root.querySelector('[data-ffc-ve-orientation]');
			if (orientation) {
				orientation.value = this.layout.page.orientation;
				orientation.addEventListener('change', function () {
					self.layout.page.orientation = orientation.value === 'portrait' ? 'portrait' : 'landscape';
					self.changed('orientation');
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

			// Hand-edited JSON loads on change, and can be undone.
			this.textarea.addEventListener('change', function () {
				self.layout = Editor.parse(self.textarea.value);
				self.selectOnly(null);
				self.changed('json');
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

		/**
		 * After any change: record it for undo, keep the JSON current and
		 * redraw. Changes that share a key within a second (typing in one
		 * field) make one step.
		 *
		 * @param {string} [key]
		 */
		changed: function (key) {
			var next = JSON.stringify(this.layout);
			if (next !== this.current) {
				var now = Date.now();
				if (!(key && key === this.lastKey && now - this.lastAt < 1000)) {
					this.past.push(this.current);
					if (this.past.length > HISTORY) {
						this.past.shift();
					}
				}
				this.future = [];
				this.current = next;
				this.lastKey = key || '';
				this.lastAt = now;
			}
			this.dirty = true;
			this.sync();
			this.render();
		},

		/** Go one step back. */
		undo: function () {
			if (!this.past.length) {
				return false;
			}
			this.future.push(this.current);
			this.restore(this.past.pop());
			this.announce(this.s.undone || 'Undone');
			return true;
		},

		/** Go one step forward again. */
		redo: function () {
			if (!this.future.length) {
				return false;
			}
			this.past.push(this.current);
			this.restore(this.future.pop());
			this.announce(this.s.redone || 'Redone');
			return true;
		},

		/** Show a recorded state. */
		restore: function (json) {
			var self = this;
			this.current = json;
			this.lastKey = '';
			this.layout = Editor.parse(json);
			this.selection = this.selection.filter(function (id) {
				return self.indexOf(id) !== -1;
			});
			if (this.selection.indexOf(this.selected) === -1) {
				this.selected = this.selection.length ? this.selection[this.selection.length - 1] : null;
			}
			this.dirty = true;
			this.sync();
			this.render();
		},

		/* ---- selection ---- */

		/** Whether an element is selected. */
		isSelected: function (id) {
			return this.selection.indexOf(id) !== -1;
		},

		/** Select one element, or none. */
		selectOnly: function (id) {
			this.selected = id || null;
			this.selection = id ? [id] : [];
		},

		/** Add an element to the selection, or take it out. */
		toggleSelect: function (id) {
			var at = this.selection.indexOf(id);
			if (at === -1) {
				this.selection.push(id);
				this.selected = id;
			} else {
				this.selection.splice(at, 1);
				this.selected = this.selection.length ? this.selection[this.selection.length - 1] : null;
			}
		},

		/**
		 * The selected elements, in stacking order.
		 *
		 * @param {boolean} unlockedOnly
		 * @return {Array<Object>}
		 */
		selectedEls: function (unlockedOnly) {
			var self = this;
			return this.layout.elements.filter(function (el) {
				return self.isSelected(el.id) && !(unlockedOnly && el.locked);
			});
		},

		/** Say something to a screen reader. */
		announce: function (text) {
			if (this.status) {
				this.status.textContent = text;
			}
		},

		/** What the selection is, said aloud. */
		announceSelection: function () {
			var el = this.selectedElement();
			if (this.selection.length > 1) {
				this.announce(String(this.s.selectedMany || '%d elements selected').replace('%d', String(this.selection.length)));
			} else if (el) {
				this.announce(String(this.s.selectedOne || '%s selected').replace('%s', this.label(el)));
			} else {
				this.announce(this.s.selectedNone || '');
			}
		},

		/** An element's name: its type and what tells it apart. */
		label: function (el) {
			var detail = el.type === 'text' || el.type === 'field' || (el.type === 'image' && el.token) ? this.describe(el) : '';
			return (this.s.types && this.s.types[el.type] ? this.s.types[el.type] : el.type) + (detail ? ': ' + detail : '');
		},

		/* ---- clipboard (shared by every visual template of the site) ---- */

		/** Copy the selected elements. */
		copy: function () {
			var els = this.selectedEls(false);
			if (!els.length) {
				return false;
			}
			memoryClip = JSON.stringify(els);
			try {
				window.localStorage.setItem(CLIP_KEY, memoryClip);
			} catch (e) {
				// No storage: the copy stays on this page.
			}
			this.announce(String(this.s.copied || '%d copied').replace('%d', String(els.length)));
			return true;
		},

		/** Paste what was copied, here or in another visual template. */
		paste: function () {
			var json = '';
			try {
				json = window.localStorage.getItem(CLIP_KEY) || '';
			} catch (e) {
				json = '';
			}
			return this.addCopies(json || memoryClip, 'paste');
		},

		/** Duplicate the selected elements, leaving the clipboard alone. */
		duplicate: function () {
			return this.addCopies(JSON.stringify(this.selectedEls(false)), 'duplicate');
		},

		/**
		 * Add copies of elements, 10 px down and right, at the front, and
		 * select them.
		 *
		 * @param {string} json  An array of elements.
		 * @param {string} key   The history key.
		 * @return {boolean}
		 */
		addCopies: function (json, key) {
			var self = this;
			var items = [];
			try {
				items = JSON.parse(json || '[]');
			} catch (e) {
				items = [];
			}
			if (!Array.isArray(items)) {
				items = [];
			}
			var ids = [];
			items.forEach(function (item) {
				if (!item || typeof item !== 'object' || !Object.prototype.hasOwnProperty.call(DEFAULTS, item.type)) {
					return;
				}
				var el = JSON.parse(JSON.stringify(item));
				el.id = self.nextId(el.type);
				el.x = Math.round(((+el.x || 0) + 10) * 100) / 100;
				el.y = Math.round(((+el.y || 0) + 10) * 100) / 100;
				el.locked = false;
				self.layout.elements.push(el);
				ids.push(el.id);
			});
			if (!ids.length) {
				return false;
			}
			this.selection = ids;
			this.selected = ids[ids.length - 1];
			this.changed(key);
			this.announce(String(this.s.pasted || '%d added').replace('%d', String(ids.length)));
			return true;
		},

		/** Run a menu command. */
		command: function (name) {
			switch (name) {
				case 'undo': this.undo(); break;
				case 'redo': this.redo(); break;
				case 'copy': this.copy(); break;
				case 'paste': this.paste(); break;
				case 'duplicate': this.duplicate(); break;
			}
			this.sheet.focus();
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
			this.selectOnly(el.id);
			this.changed();
			this.sheet.focus();
		},

		/** Remove the selected elements that are not locked. */
		removeSelected: function () {
			var gone = this.selectedEls(true).map(function (el) {
				return el.id;
			});
			if (!gone.length) {
				return;
			}
			this.layout.elements = this.layout.elements.filter(function (el) {
				return gone.indexOf(el.id) === -1;
			});
			this.selectOnly(null);
			this.changed();
		},

		/**
		 * Align the selection: one element to the page, several to the box
		 * around them. Locked elements stay put.
		 */
		alignSelected: function (mode) {
			var els = this.selectedEls(true);
			if (!els.length) {
				return;
			}
			var area = els.length > 1 || this.selection.length > 1
				? geometry.bounds(this.selectedEls(false))
				: { x: 0, y: 0, w: this.page().width, h: this.page().height };
			els.forEach(function (el) {
				var at = geometry.alignTo(el, area, mode);
				el.x = at.x;
				el.y = at.y;
			});
			this.changed();
		},

		/** Space three or more selected elements evenly along an axis. */
		distributeSelected: function (axis) {
			var els = this.selectedEls(true);
			if (els.length < 3 || (axis !== 'x' && axis !== 'y')) {
				return;
			}
			var to = geometry.distribute(els, axis);
			els.forEach(function (el, i) {
				el[axis] = to[i];
			});
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
			this.layout = Editor.parse(this.saved);
			this.selectOnly(null);
			// Undo can bring the discarded work back.
			this.changed('discard');
			this.textarea.value = this.saved;
			this.dirty = false;
		},

		/* ---- pointer and keyboard ---- */

		pointerDown: function (event) {
			var handle = event.target.closest('[data-ffc-ve-handle]');
			var node = event.target.closest('[data-ffc-ve-id]');
			if (!node) {
				// On empty page a drag draws a selection rectangle; a click
				// without one clears the selection, as before (#2613).
				if (!event.shiftKey) {
					this.selectOnly(null);
				}
				if (event.button === 0) {
					this.startMarquee(event, this.selection.slice());
				}
				this.render();
				return;
			}
			var id = node.getAttribute('data-ffc-ve-id');
			var el = this.layout.elements[this.indexOf(id)];
			this.sheet.focus();
			// Shift adds to the selection or takes out; it starts no gesture.
			if (event.shiftKey) {
				this.toggleSelect(id);
				this.render();
				this.announceSelection();
				return;
			}
			if (this.isSelected(id)) {
				this.selected = id;
			} else {
				this.selectOnly(id);
			}
			// A locked element does not move, so a drag on it draws the
			// selection rectangle: a full-page frame leaves no empty page.
			if (el && el.locked && event.button === 0) {
				this.startMarquee(event, []);
				this.render();
				return;
			}
			if (!el || el.locked || event.button !== 0) {
				this.render();
				return;
			}
			event.preventDefault();
			var kind = handle ? handle.getAttribute('data-ffc-ve-handle') : '';
			var moving = kind ? [el] : this.selectedEls(true);
			this.gesture = {
				pointer: event.pointerId,
				handle: kind,
				x: event.clientX,
				y: event.clientY,
				start: { x: el.x, y: el.y, w: el.w, h: el.h },
				starts: moving.map(function (m) {
					return { id: m.id, x: m.x, y: m.y, w: m.w, h: m.h };
				}),
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
			if (g.marquee) {
				this.dragMarquee(g, this.pagePoint(event));
				return;
			}
			var el = this.layout.elements[this.indexOf(this.selected)];
			if (!el) {
				return;
			}
			var dx = (event.clientX - g.x) / g.zoom;
			var dy = (event.clientY - g.y) / g.zoom;
			g.moved = true;

			if (g.handle === 'rotate') {
				var rect = this.sheet.getBoundingClientRect();
				el.rotation = geometry.angle(
					el.x + el.w / 2,
					el.y + el.h / 2,
					(event.clientX - rect.left) / g.zoom,
					(event.clientY - rect.top) / g.zoom,
					event.shiftKey ? 15 : 1
				);
				this.place(el);
				return;
			}
			if (g.handle) {
				var box = geometry.resize(g.start, g.handle, dx, dy, this.snapOn);
				el.x = box.x;
				el.y = box.y;
				el.w = box.w;
				el.h = box.h;
				this.place(el);
				return;
			}

			// Move the selection as one box: a guide takes an edge or centre
			// within reach of another element or the page; otherwise the grid.
			var self = this;
			var group = geometry.bounds(g.starts);
			var moved = { x: group.x + dx, y: group.y + dy, w: group.w, h: group.h };
			var ids = g.starts.map(function (st) {
				return st.id;
			});
			var targets = this.layout.elements.filter(function (other) {
				return ids.indexOf(other.id) === -1;
			});
			targets.push({ x: 0, y: 0, w: this.page().width, h: this.page().height });
			var found = geometry.guides(moved, targets, GUIDE / g.zoom);
			var ox = found.x.length ? found.dx : geometry.snap(moved.x, this.snapOn) - moved.x;
			var oy = found.y.length ? found.dy : geometry.snap(moved.y, this.snapOn) - moved.y;
			g.starts.forEach(function (st) {
				var m = self.layout.elements[self.indexOf(st.id)];
				if (m) {
					m.x = Math.round((st.x + dx + ox) * 100) / 100;
					m.y = Math.round((st.y + dy + oy) * 100) / 100;
					self.place(m);
				}
			});
			this.drawGuides(found);
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
			this.drawGuides({ x: [], y: [] });
			if (g.marquee) {
				this.drawMarquee(null);
				this.render();
				if (g.moved) {
					this.announceSelection();
				}
				return;
			}
			if (g.moved) {
				this.changed();
			}
		},

		/**
		 * Start a selection rectangle at the pointer; `base` is what stays
		 * selected whatever it touches.
		 */
		startMarquee: function (event, base) {
			var at = this.pagePoint(event);
			this.gesture = { pointer: event.pointerId, marquee: true, x: at.x, y: at.y, base: base, moved: false };
			if (this.sheet.setPointerCapture) {
				this.sheet.setPointerCapture(event.pointerId);
			}
		},

		/** A pointer's place on the page, in page pixels. */
		pagePoint: function (event) {
			var rect = this.sheet.getBoundingClientRect();
			var zoom = this.scale() || 1;
			return { x: (event.clientX - rect.left) / zoom, y: (event.clientY - rect.top) / zoom };
		},

		/**
		 * Grow the selection rectangle to a point: the unlocked elements it
		 * touches are selected, after those Shift kept (#2613).
		 */
		dragMarquee: function (g, at) {
			var box = geometry.span(g.x, g.y, at.x, at.y);
			// A wobble of a click is not a drag.
			if (!g.moved && box.w < 3 && box.h < 3) {
				return;
			}
			g.moved = true;
			var picked = g.base.slice();
			this.layout.elements.forEach(function (el) {
				if (!el.locked && geometry.overlaps(el, box) && picked.indexOf(el.id) === -1) {
					picked.push(el.id);
				}
			});
			this.selection = picked;
			this.selected = picked.length ? picked[picked.length - 1] : null;
			this.render();
			this.drawMarquee(box);
		},

		/** Draw the selection rectangle, or take it away. */
		drawMarquee: function (box) {
			var mark = this.sheet.querySelector('.ffc-ve-marquee');
			if (!box) {
				if (mark) {
					mark.parentNode.removeChild(mark);
				}
				return;
			}
			if (!mark) {
				mark = document.createElement('div');
				mark.className = 'ffc-ve-marquee';
				mark.setAttribute('aria-hidden', 'true');
				this.sheet.appendChild(mark);
			}
			applyBox(mark, box);
		},

		/**
		 * The keyboard does everything the pointer does; see the shortcuts
		 * listed under the canvas.
		 */
		keyDown: function (event) {
			var mod = event.ctrlKey || event.metaKey;
			var key = event.key;
			var lower = typeof key === 'string' ? key.toLowerCase() : '';
			var handled = true;

			if (mod && lower === 'z') {
				if (event.shiftKey) {
					this.redo();
				} else {
					this.undo();
				}
			} else if (mod && lower === 'y') {
				this.redo();
			} else if (mod && lower === 'a') {
				this.selection = this.layout.elements.map(function (el) {
					return el.id;
				});
				this.selected = this.selection.length ? this.selection[this.selection.length - 1] : null;
				this.render();
				this.announceSelection();
			} else if (mod && lower === 'c') {
				this.copy();
			} else if (mod && lower === 'v') {
				this.paste();
			} else if (mod && lower === 'd') {
				this.duplicate();
			} else if (key === ']' || key === '[') {
				this.step(key === ']' ? 1 : -1);
			} else if (key === 'Escape') {
				this.selectOnly(null);
				this.render();
				this.announceSelection();
			} else {
				handled = this.keyEdit(event, mod, lower);
			}
			if (handled) {
				event.preventDefault();
			}
		},

		/**
		 * The keys that change the selected elements.
		 *
		 * @return {boolean} Whether the key was one of them.
		 */
		keyEdit: function (event, mod, lower) {
			var el = this.selectedElement();
			var steps = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
			var step = event.shiftKey ? 10 : 1;
			if (!el) {
				return !!steps[event.key];
			}
			if (steps[event.key] && mod) {
				// Bring forward or send backward.
				var index = this.indexOf(el.id);
				if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
					this.moveLayer(index, index + (event.key === 'ArrowUp' ? 1 : -1));
				}
				return true;
			}
			if (steps[event.key] && event.altKey) {
				// Resize the element from its right and bottom edges.
				if (!el.locked) {
					el.w = Math.max(MIN_SIZE, Math.round((el.w + steps[event.key][0] * step) * 100) / 100);
					el.h = Math.max(MIN_SIZE, Math.round((el.h + steps[event.key][1] * step) * 100) / 100);
					this.changed('resize:' + el.id);
				}
				return true;
			}
			if (steps[event.key]) {
				var moving = this.selectedEls(true);
				moving.forEach(function (m) {
					m.x = Math.round((m.x + steps[event.key][0] * step) * 100) / 100;
					m.y = Math.round((m.y + steps[event.key][1] * step) * 100) / 100;
				});
				if (moving.length) {
					this.changed('nudge');
				}
				return true;
			}
			if (event.key === 'Delete' || event.key === 'Backspace') {
				this.removeSelected();
				return true;
			}
			if (lower === 'r' && !mod) {
				// Rotate 15°, or 1° with Alt; Shift turns the other way.
				if (!el.locked) {
					var turn = (event.altKey ? 1 : 15) * (event.shiftKey ? -1 : 1);
					el.rotation = geometry.angle(0, 0, Math.sin(((+el.rotation || 0) + turn) * Math.PI / 180), -Math.cos(((+el.rotation || 0) + turn) * Math.PI / 180), 1);
					this.changed('rotate:' + el.id);
				}
				return true;
			}
			if (lower === 'l' && !mod) {
				this.toggleLock(el.id);
				return true;
			}
			return false;
		},

		/** Select the next or previous element, front to back. */
		step: function (direction) {
			var count = this.layout.elements.length;
			if (!count) {
				return;
			}
			var index = this.indexOf(this.selected);
			var next = index === -1 ? (direction > 0 ? count - 1 : 0) : (index - direction + count) % count;
			this.selectOnly(this.layout.elements[next].id);
			this.render();
			this.announceSelection();
		},

		/** Draw the guides a move lined up with, or clear them. */
		drawGuides: function (found) {
			var self = this;
			this.sheet.querySelectorAll('.ffc-ve-guide').forEach(function (line) {
				line.parentNode.removeChild(line);
			});
			found.x.forEach(function (x) {
				var line = document.createElement('span');
				line.className = 'ffc-ve-guide ffc-ve-guide-x';
				line.style.left = x + 'px';
				self.sheet.appendChild(line);
			});
			found.y.forEach(function (y) {
				var line = document.createElement('span');
				line.className = 'ffc-ve-guide ffc-ve-guide-y';
				line.style.top = y + 'px';
				self.sheet.appendChild(line);
			});
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
			this.drawBackdrop();

			var self = this;
			this.sheet.querySelectorAll('[data-ffc-ve-id]').forEach(function (node) {
				node.parentNode.removeChild(node);
			});
			this.layout.elements.forEach(function (el, index) {
				self.sheet.appendChild(self.drawElement(el, index));
			});
			this.renderLayers();
			this.renderProperties();
			var movable = this.selectedEls(true).length;
			this.root.querySelectorAll('[data-ffc-ve-align]').forEach(function (button) {
				button.disabled = !movable;
			});
			this.root.querySelectorAll('[data-ffc-ve-distribute]').forEach(function (button) {
				button.disabled = movable < 3;
			});
			var can = {
				undo: this.past.length > 0,
				redo: this.future.length > 0,
				copy: this.selection.length > 0,
				duplicate: this.selection.length > 0,
				paste: true
			};
			this.root.querySelectorAll('[data-ffc-ve-cmd]').forEach(function (button) {
				button.disabled = !can[button.getAttribute('data-ffc-ve-cmd')];
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
				+ (this.isSelected(el.id) ? ' is-selected' : '')
				+ (el.id === this.selected && this.selection.length > 1 ? ' is-primary' : '')
				+ (el.locked ? ' is-locked' : '');
			node.setAttribute('data-ffc-ve-id', el.id);
			node.style.zIndex = String(index + 1);
			node.style.transform = el.rotation ? 'rotate(' + el.rotation + 'deg)' : '';
			if (el.type === 'frame') {
				node.style.border = (el.border_width || 0) + 'px ' + (el.border_style || 'solid') + ' ' + (el.border_color || '#000000');
				node.style.borderRadius = (el.radius || 0) + 'px';
				node.style.background = el.fill || 'transparent';
				node.style.backgroundImage = gradientCss(el.gradient);
			}
			var label = document.createElement('span');
			label.className = 'ffc-ve-el-label';
			label.textContent = this.describe(el);
			if (el.type === 'text' || el.type === 'field') {
				label.style.cssText = textCss(el);
			}
			var picked = el.type === 'image' && !el.token
				? (el.attachment_id ? this.images[el.attachment_id] : (el.asset ? String(this.s.pluginUrl || '') + el.asset : ''))
				: '';
			if (el.type === 'qr' && this.s.pluginUrl) {
				// A sample code at its printed size, square in the box; the real
				// one, with the validation link, prints in the PDF (#2616).
				var sample = document.createElement('img');
				sample.className = 'ffc-ve-qr-sample';
				sample.src = String(this.s.pluginUrl) + 'assets/img/qr-sample.svg';
				sample.alt = '';
				sample.draggable = false;
				node.appendChild(sample);
				label.classList.add('screen-reader-text');
				node.appendChild(label);
			} else if (picked) {
				var img = document.createElement('img');
				// Not `ffc-ve-el-image`: that is the element's own type class,
				// and this rule turns pointer events off (#2608).
				img.className = 'ffc-ve-picture' + (el.fit === 'cover' ? ' is-cover' : '');
				img.src = picked;
				img.alt = '';
				// The element moves, not a copy of the picture the browser drags.
				img.draggable = false;
				node.appendChild(img);
			} else {
				node.appendChild(label);
			}
			if (el.id === this.selected && this.selection.length === 1 && !el.locked) {
				HANDLES.concat(['rotate']).forEach(function (h) {
					var grip = document.createElement('span');
					grip.className = 'ffc-ve-handle ffc-ve-handle-' + h;
					grip.setAttribute('data-ffc-ve-handle', h);
					node.appendChild(grip);
				});
			}
			applyBox(node, el);
			return node;
		},

		/** What an element shows on the canvas; the preview shows the print. */
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

		/**
		 * The template's page colour and background image under the
		 * elements, stretched to the page as the PDF draws it (#2612).
		 */
		drawBackdrop: function () {
			if (!this.backdrop) {
				this.backdrop = document.createElement('div');
				this.backdrop.className = 'ffc-ve-backdrop';
				this.backdrop.setAttribute('aria-hidden', 'true');
				var self = this;
				var field = document.getElementById('ffc_template_bg_image');
				if (field) {
					field.addEventListener('input', function () {
						self.drawBackdrop();
					});
				}
			}
			if (this.backdrop.parentNode !== this.sheet) {
				this.sheet.insertBefore(this.backdrop, this.sheet.firstChild);
			}
			var input = document.getElementById('ffc_template_bg_image');
			var url = input ? String(input.value || '').trim() : '';
			// A quote or a backslash would end the CSS string; a URL has neither.
			url = /^(https?:)?\/\/[^"\\\s]+$|^\/[^"\\\s]*$/.test(url) ? url : '';
			this.backdrop.style.setProperty('--ffc-ve-bg-image', url ? 'url("' + url + '")' : 'none');
			this.backdrop.style.setProperty('--ffc-ve-bg-color', /^#[0-9a-f]{6}$/i.test(this.s.bgColor || '') ? this.s.bgColor : 'transparent');
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
			li.className = 'ffc-ve-layer' + (this.isSelected(el.id) ? ' is-selected' : '');
			li.setAttribute('data-ffc-ve-row', String(row));
			li.draggable = true;
			li.addEventListener('dragstart', function (event) {
				event.dataTransfer.setData('text/plain', String(row));
			});

			var name = document.createElement('button');
			name.type = 'button';
			name.className = 'ffc-ve-layer-name';
			// The type, and what tells two of a kind apart: a text, a token.
			name.textContent = this.label(el);
			name.setAttribute('aria-pressed', this.isSelected(el.id) ? 'true' : 'false');
			name.addEventListener('click', function (event) {
				if (event.shiftKey) {
					self.toggleSelect(el.id);
				} else {
					self.selectOnly(el.id);
				}
				self.render();
				self.announceSelection();
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

	/* ---- properties panel (#2586) ---- */

	/** Whether a panel group serves an element type. */
	function serves(group, type) {
		var types = group ? (group.getAttribute('data-ffc-ve-for') || '').split(' ') : [];
		return !group || types.indexOf(type) !== -1;
	}

	Editor.prototype.selectedElement = function () {
		return this.layout.elements[this.indexOf(this.selected)] || null;
	};

	/** Wire the side column's controls to the selected element. */
	Editor.prototype.bindProperties = function () {
		var self = this;
		var panel = document.querySelector('[data-ffc-ve-properties]');
		this.props = panel;
		if (!panel) {
			return;
		}
		panel.querySelectorAll('[data-ffc-ve-prop]').forEach(function (input) {
			var events = input.tagName === 'SELECT' ? ['change'] : ['input', 'change'];
			events.forEach(function (name) {
				input.addEventListener(name, function () {
					self.setProperty(input);
				});
			});
		});
		var token = panel.querySelector('[data-ffc-ve-insert-token]');
		if (token) {
			token.addEventListener('change', function () {
				self.insertToken(token.value);
				token.value = '';
			});
		}
		var source = panel.querySelector('[data-ffc-ve-image-source]');
		if (source) {
			source.addEventListener('change', function () {
				var el = self.selectedElement();
				if (el && el.type === 'image') {
					el.token = source.value === 'signature' ? '{{signer:signature}}' : '';
					self.changed();
				}
			});
		}
		var noFill = panel.querySelector('[data-ffc-ve-no-fill]');
		if (noFill) {
			noFill.addEventListener('change', function () {
				var el = self.selectedElement();
				if (el && el.type === 'frame') {
					var fill = panel.querySelector('[data-ffc-ve-prop="fill"]');
					el.fill = noFill.checked ? '' : (fill && fill.value) || '#ffffff';
					self.changed();
				}
			});
		}
		this.bindGradient(panel);
		var choose = panel.querySelector('[data-ffc-ve-choose-image]');
		if (choose) {
			choose.addEventListener('click', function (event) {
				event.preventDefault();
				self.chooseImage();
			});
		}
	};

	/**
	 * Write one control's value into the selected element.
	 *
	 * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} input
	 */
	Editor.prototype.setProperty = function (input) {
		var el = this.selectedElement();
		var prop = input.getAttribute('data-ffc-ve-prop');
		if (!el || !serves(input.closest('[data-ffc-ve-for]'), el.type)) {
			return;
		}
		if (el.locked && GEOMETRY.indexOf(prop) !== -1) {
			return;
		}
		var value = input.value;
		if (input.hasAttribute('data-ffc-ve-number')) {
			// An emptied field leaves the property as it was.
			if (value === '' || isNaN(parseFloat(value))) {
				return;
			}
			value = parseFloat(value);
			if ((prop === 'w' || prop === 'h') && value < MIN_SIZE) {
				value = MIN_SIZE;
			}
		} else if (prop === 'font_weight') {
			value = parseInt(value, 10) || PROP_DEFAULTS.font_weight;
		}
		if (prop === 'fill' && this.props) {
			var noFill = this.props.querySelector('[data-ffc-ve-no-fill]');
			if (noFill) {
				noFill.checked = false;
			}
		}
		el[prop] = value;
		// Typing in one field makes one undo step.
		this.changed('prop:' + el.id + ':' + prop);
	};

	/** Put a tag at the caret of the selected text's content. */
	Editor.prototype.insertToken = function (tag) {
		var el = this.selectedElement();
		var area = this.props ? this.props.querySelector('[data-ffc-ve-prop="text"]') : null;
		if (!tag || !el || el.type !== 'text' || !area) {
			return;
		}
		var start = typeof area.selectionStart === 'number' ? area.selectionStart : area.value.length;
		var end = typeof area.selectionEnd === 'number' ? area.selectionEnd : start;
		var next = geometry.insertAt(area.value, start, end, tag);
		el.text = next.text;
		area.value = next.text;
		this.changed();
		area.focus();
		if (area.setSelectionRange) {
			area.setSelectionRange(next.caret, next.caret);
		}
	};

	/** Pick the selected image element's file from the Media Library. */
	Editor.prototype.chooseImage = function () {
		var self = this;
		var wp = window.wp;
		if (!wp || !wp.media) {
			return;
		}
		var frame = wp.media({
			title: this.s.chooseImage || 'Choose image',
			button: { text: this.s.useImage || 'Use this image' },
			library: { type: 'image' },
			multiple: false
		});
		frame.on('select', function () {
			var picked = frame.state().get('selection').first().toJSON();
			var el = self.selectedElement();
			if (!el || el.type !== 'image' || !picked || !picked.id) {
				return;
			}
			el.attachment_id = picked.id;
			el.token = '';
			el.asset = '';
			self.images[picked.id] = picked.url;
			self.changed();
		});
		frame.open();
	};

	/** Show the selected element's properties; leave the control being typed in alone. */
	Editor.prototype.renderProperties = function () {
		var panel = this.props;
		if (!panel) {
			return;
		}
		// Several selected: the panel edits one element at a time.
		var el = this.selection.length > 1 ? null : this.selectedElement();
		var empty = panel.querySelector('[data-ffc-ve-props-empty]');
		var form = panel.querySelector('[data-ffc-ve-props-form]');
		if (empty) {
			if (!empty.hasAttribute('data-ffc-ve-props-hint')) {
				empty.setAttribute('data-ffc-ve-props-hint', empty.textContent);
			}
			empty.textContent = this.selection.length > 1
				? String(this.s.selectedMany || '%d elements selected').replace('%d', String(this.selection.length)) + ' ' + (this.s.oneAtATime || '')
				: empty.getAttribute('data-ffc-ve-props-hint');
			empty.hidden = !!el;
		}
		if (form) {
			form.hidden = !el;
		}
		if (!el) {
			return;
		}
		var self = this;
		var title = panel.querySelector('[data-ffc-ve-props-title]');
		if (title) {
			title.textContent = (this.s.types && this.s.types[el.type] ? this.s.types[el.type] : el.type) + ' · ' + el.id;
		}
		panel.querySelectorAll('[data-ffc-ve-for]').forEach(function (group) {
			group.hidden = !serves(group, el.type);
		});
		panel.querySelectorAll('[data-ffc-ve-prop]').forEach(function (input) {
			if (!serves(input.closest('[data-ffc-ve-for]'), el.type)) {
				return;
			}
			var prop = input.getAttribute('data-ffc-ve-prop');
			input.disabled = !!el.locked && GEOMETRY.indexOf(prop) !== -1;
			if (input === document.activeElement) {
				return;
			}
			var value = Object.prototype.hasOwnProperty.call(el, prop) ? el[prop] : PROP_DEFAULTS[prop];
			if (prop === 'fit' && (value === undefined || value === null)) {
				value = el.type === 'image' ? 'contain' : 'none';
			}
			if (prop === 'fill') {
				value = el.fill || '#ffffff';
			}
			if (input.tagName === 'SELECT' && value !== undefined && value !== null) {
				self.ensureOption(input, String(value));
			}
			input.value = value === undefined || value === null ? '' : String(value);
		});
		var noFill = panel.querySelector('[data-ffc-ve-no-fill]');
		if (noFill && el.type === 'frame') {
			noFill.checked = !el.fill;
		}
		if (el.type === 'frame') {
			this.renderGradient(panel, el);
		}
		var source = panel.querySelector('[data-ffc-ve-image-source]');
		var media = panel.querySelector('[data-ffc-ve-image-media]');
		var name = panel.querySelector('[data-ffc-ve-image-name]');
		if (el.type === 'image') {
			if (source) {
				source.value = el.token ? 'signature' : 'media';
			}
			if (media) {
				media.hidden = !!el.token;
			}
			if (name) {
				name.textContent = el.attachment_id
					? String(this.s.imageChosen || 'Image #%d').replace('%d', String(el.attachment_id))
					: (el.asset ? el.asset.split('/').pop() : (this.s.noImage || ''));
			}
		}
		this.renderFontState(el);
	};

	/** The gradient controls of a frame (#2609). */
	Editor.prototype.bindGradient = function (panel) {
		var self = this;
		var frame = function () {
			var el = self.selectedElement();
			return el && el.type === 'frame' ? el : null;
		};
		var on = panel.querySelector('[data-ffc-ve-gradient-on]');
		if (on) {
			on.addEventListener('change', function () {
				var el = frame();
				if (!el) {
					return;
				}
				if (on.checked) {
					el.gradient = { angle: 180, stops: [{ color: el.fill || '#ffffff', at: 0 }, { color: '#000000', at: 100 }] };
				} else {
					delete el.gradient;
				}
				self.changed();
			});
		}
		var angle = panel.querySelector('[data-ffc-ve-gradient-angle]');
		if (angle) {
			angle.addEventListener('input', function () {
				var el = frame();
				var v = parseFloat(angle.value);
				if (el && el.gradient && !isNaN(v)) {
					el.gradient.angle = Math.max(0, Math.min(360, v));
					self.changed('prop:' + el.id + ':gradient');
				}
			});
		}
		panel.querySelectorAll('[data-ffc-ve-gradient-stop]').forEach(function (row) {
			var i = parseInt(row.getAttribute('data-ffc-ve-gradient-stop'), 10);
			var color = row.querySelector('[data-ffc-ve-gradient-color]');
			var at = row.querySelector('[data-ffc-ve-gradient-at]');
			var write = function (key, value) {
				var el = frame();
				if (el && el.gradient && el.gradient.stops[i]) {
					el.gradient.stops[i][key] = value;
					self.changed('prop:' + el.id + ':gradient');
				}
			};
			if (color) {
				color.addEventListener('input', function () { write('color', color.value); });
			}
			if (at) {
				at.addEventListener('input', function () {
					var v = parseFloat(at.value);
					if (!isNaN(v)) {
						write('at', Math.max(0, Math.min(100, v)));
					}
				});
			}
		});
		var add = panel.querySelector('[data-ffc-ve-gradient-add]');
		if (add) {
			add.addEventListener('click', function (event) {
				event.preventDefault();
				var el = frame();
				if (el && el.gradient && el.gradient.stops.length < GRADIENT_MAX) {
					var last = el.gradient.stops[el.gradient.stops.length - 1];
					el.gradient.stops.push({ color: last.color, at: 100 });
					self.changed();
				}
			});
		}
		var remove = panel.querySelector('[data-ffc-ve-gradient-remove]');
		if (remove) {
			remove.addEventListener('click', function (event) {
				event.preventDefault();
				var el = frame();
				if (el && el.gradient && el.gradient.stops.length > GRADIENT_MIN) {
					el.gradient.stops.pop();
					self.changed();
				}
			});
		}
	};

	/** Show a frame's gradient in its controls. */
	Editor.prototype.renderGradient = function (panel, el) {
		var g = el.gradient && Array.isArray(el.gradient.stops) ? el.gradient : null;
		var on = panel.querySelector('[data-ffc-ve-gradient-on]');
		var box = panel.querySelector('[data-ffc-ve-gradient]');
		if (on) {
			on.checked = !!g;
		}
		if (box) {
			box.hidden = !g;
		}
		if (!g) {
			return;
		}
		var angle = panel.querySelector('[data-ffc-ve-gradient-angle]');
		if (angle && angle !== document.activeElement) {
			angle.value = String(g.angle);
		}
		panel.querySelectorAll('[data-ffc-ve-gradient-stop]').forEach(function (row) {
			var stop = g.stops[parseInt(row.getAttribute('data-ffc-ve-gradient-stop'), 10)];
			row.hidden = !stop;
			if (!stop) {
				return;
			}
			var color = row.querySelector('[data-ffc-ve-gradient-color]');
			var at = row.querySelector('[data-ffc-ve-gradient-at]');
			if (color && color !== document.activeElement) {
				color.value = stop.color;
			}
			if (at && at !== document.activeElement) {
				at.value = String(stop.at);
			}
		});
		var add = panel.querySelector('[data-ffc-ve-gradient-add]');
		var remove = panel.querySelector('[data-ffc-ve-gradient-remove]');
		if (add) {
			add.disabled = g.stops.length >= GRADIENT_MAX;
		}
		if (remove) {
			remove.disabled = g.stops.length <= GRADIENT_MIN;
		}
	};

	/** A select keeps a value it does not list (a custom stack) as an extra option. */
	Editor.prototype.ensureOption = function (select, value) {
		for (var i = 0; i < select.options.length; i++) {
			if (select.options[i].value === value) {
				return;
			}
		}
		var option = document.createElement('option');
		option.value = value;
		option.textContent = value;
		select.appendChild(option);
	};

	/** Mark a font the site lacks, naming what prints instead (#2573). */
	Editor.prototype.renderFontState = function (el) {
		var note = this.props ? this.props.querySelector('[data-ffc-ve-font-missing]') : null;
		if (!note) {
			return;
		}
		var state = (el.type === 'text' || el.type === 'field')
			? geometry.fontState(el.font_family, this.s.knownFonts)
			: { missing: false, fallback: '' };
		note.hidden = !state.missing;
		note.textContent = state.missing
			? String(this.s.fontFallback || '%s').replace('%s', state.fallback || this.s.fontDefault || '')
			: '';
	};

	/* ---- preview (#2586) ---- */

	Editor.prototype.bindPreview = function () {
		var self = this;
		this.dialog = this.root.querySelector('.ffc-ve-preview');
		var open = this.root.querySelector('[data-ffc-ve-preview]');
		if (open) {
			open.addEventListener('click', function () {
				self.preview();
			});
		}
		var close = this.root.querySelector('[data-ffc-ve-preview-close]');
		if (close && this.dialog) {
			close.addEventListener('click', function () {
				self.closePreview();
			});
		}
	};

	Editor.prototype.closePreview = function () {
		if (this.dialog && this.dialog.open && this.dialog.close) {
			this.dialog.close();
		}
	};

	/**
	 * Render the unsaved layout on the server with sample data and draw it
	 * the way the PDF does.
	 *
	 * @return {Promise}
	 */
	Editor.prototype.preview = function () {
		var self = this;
		var dialog = this.dialog;
		if (!dialog) {
			return Promise.resolve(false);
		}
		var status = dialog.querySelector('[data-ffc-ve-preview-status]');
		var warnings = dialog.querySelector('[data-ffc-ve-preview-warnings]');
		var img = dialog.querySelector('.ffc-ve-preview-page');
		if (status) {
			status.textContent = this.s.previewLoading || '';
		}
		if (warnings) {
			warnings.textContent = '';
		}
		if (img) {
			img.hidden = true;
			img.removeAttribute('src');
		}
		if (dialog.showModal && !dialog.open) {
			dialog.showModal();
		}
		this.sync();
		var bg = document.getElementById('ffc_template_bg_image');
		var title = document.getElementById('title');
		var body = new window.URLSearchParams();
		body.append('action', this.s.previewAction || '');
		body.append('nonce', this.s.previewNonce || '');
		body.append('post_id', String(this.s.postId || 0));
		body.append('layout', this.textarea.value);
		body.append('bg_image', bg ? bg.value : '');
		body.append('title', title ? title.value : '');
		var fail = function (message) {
			if (status) {
				status.textContent = message || self.s.previewError || '';
			}
			return false;
		};
		return window.fetch(this.s.ajaxUrl || '', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (response) {
			return response.json();
		}).then(function (json) {
			if (!json || !json.success || !json.data) {
				return fail(json && json.data && json.data.message);
			}
			self.showWarnings(warnings, json.data);
			if (!window.FFCLayoutPreview) {
				return fail();
			}
			return window.FFCLayoutPreview.capture(json.data.html, json.data.width, json.data.height).then(function (src) {
				if (img) {
					img.src = src;
					img.hidden = false;
				}
				if (status) {
					status.textContent = '';
				}
				return true;
			}, function () {
				return fail();
			});
		}, function () {
			return fail();
		});
	};

	/** The required tags and the fonts the previewed layout lacks. */
	Editor.prototype.showWarnings = function (box, data) {
		if (!box) {
			return;
		}
		var add = function (template, list) {
			if (!list || !list.length) {
				return;
			}
			var p = document.createElement('p');
			p.className = 'ffc-ve-preview-warning ffc-icon-warning';
			p.textContent = String(template || '%s').replace('%s', list.join(', '));
			box.appendChild(p);
		};
		add(this.s.missingTags, data.missing);
		add(this.s.missingFonts, data.fonts);
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
