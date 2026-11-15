/**
 * Visual copy by measurement (#2606).
 *
 * Renders an HTML certificate template inside the PDF generator's own wrapper,
 * the way it prints (`ffc-pdf-core` and the installed fonts are on the page),
 * reads where each box, text block and image landed, and turns them into a
 * visual layout: frames for borders and fills, text and field elements for
 * text, images, the QR code, and HTML blocks for what a typed element cannot
 * draw. The server validates the layout and saves it as a new template; the
 * original is not written.
 *
 * The server importer (#2587) stays the path without JavaScript: the link
 * keeps its address, and this script only takes the click over.
 */
(function (window, document) {
	'use strict';

	var PAGE = { landscape: { w: 1123, h: 794 }, portrait: { w: 794, h: 1123 } };
	var QR_TOKEN = /\{\{qr_code(?::[^}]*)?\}\}/g;
	var FIELD_TOKEN = /^\{\{[a-z0-9_]+(?::[a-z0-9_]+)*(?::(?:format|size|margin)=[^{}<>"]{0,60})*\}\}$/;
	var SIGNATURE = '{{signer:signature}}';
	// Inline tags a text block may hold and stay one block.
	var INLINE = { B: 1, STRONG: 1, I: 1, EM: 1, SPAN: 1, A: 1, BR: 1, SMALL: 1, U: 1, SUB: 1, SUP: 1, FONT: 1, MARK: 1, ABBR: 1, CODE: 1 };
	var ALIGN = { start: 'left', left: 'left', center: 'center', '-webkit-center': 'center', right: 'right', end: 'right', justify: 'justify' };
	var TRANSFORM = { none: 1, uppercase: 1, lowercase: 1, capitalize: 1 };
	var STATUS = { converted: 'converted', kept: 'kept', dropped: 'dropped' };

	function config() {
		return window.ffcVisualMeasure || {};
	}

	function t(key, fallback) {
		var s = config().strings || {};
		return s[key] || fallback;
	}

	function round(v) {
		return Math.round(v * 10) / 10;
	}

	function px(v) {
		var n = parseFloat(v);
		return isFinite(n) ? n : 0;
	}

	/**
	 * `rgb()` / `rgba()` as `#rrggbb`; '' when transparent.
	 *
	 * @param {string} value Computed colour.
	 * @return {string}
	 */
	function hex(value) {
		var m = /rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,\s/]+([\d.]+%?))?\s*\)/.exec(value || '');
		if (!m) {
			return /^#[0-9a-f]{6}$/i.test(value || '') ? value.toLowerCase() : '';
		}
		if (m[4] !== undefined && parseFloat(m[4]) === 0) {
			return '';
		}
		return '#' + [m[1], m[2], m[3]].map(function (c) {
			var h = Math.max(0, Math.min(255, Math.round(parseFloat(c)))).toString(16);
			return h.length === 1 ? '0' + h : h;
		}).join('');
	}

	/**
	 * The template with each QR tag held at its printed size: the generator
	 * prints a block-level image, so the lines around it fall where they print.
	 *
	 * @param {string} html Template HTML.
	 * @return {string}
	 */
	function holdQr(html) {
		return String(html || '').replace(QR_TOKEN, function (token) {
			var size = /size=(\d+)/.exec(token);
			var n = size ? Math.max(10, Math.min(1000, parseInt(size[1], 10))) : 200;
			// A div: the PDF sheet forces every span inline.
			var hold = document.createElement('div');
			hold.setAttribute('data-ffc-qr', token);
			hold.setAttribute('style', 'display:block;width:' + n + 'px;height:' + n + 'px;margin:0 auto;');
			return hold.outerHTML;
		});
	}

	/**
	 * HTML with the held QR tags written back as tags.
	 *
	 * @param {string} html Markup read from the page.
	 * @return {string}
	 */
	function releaseQr(html) {
		var box = document.createElement('div');
		box.innerHTML = html;
		Array.prototype.forEach.call(box.querySelectorAll('[data-ffc-qr]'), function (el) {
			el.parentNode.replaceChild(document.createTextNode(el.getAttribute('data-ffc-qr')), el);
		});
		return box.innerHTML;
	}

	/**
	 * A measurer over the page: positions relative to it, styles as computed.
	 * Both readers are injectable so a test can lay the page out by hand.
	 *
	 * @param {Element} page The page element.
	 * @param {{rect?: Function, style?: Function}} env Readers.
	 */
	function Measurer(page, env) {
		env = env || {};
		this.page = page;
		this.rectOf = env.rect || function (el) { return el.getBoundingClientRect(); };
		this.styleOf = env.style || function (el) { return window.getComputedStyle(el); };
		this.lineCount = env.lines || function (el) {
			var range = document.createRange();
			range.selectNodeContents(el);
			var tops = {};
			Array.prototype.forEach.call(range.getClientRects(), function (r) {
				if (r.width > 0) {
					tops[Math.round(r.top)] = 1;
				}
			});
			return Math.max(1, Object.keys(tops).length);
		};
		// The box a run of nodes draws: a line, or a few, of one block.
		this.runRect = env.runRect || function (nodes) {
			var range = document.createRange();
			range.setStartBefore(nodes[0]);
			range.setEndAfter(nodes[nodes.length - 1]);
			// Only boxes that draw something: a collapsed space at the start of
			// a run sits where the previous line ended, before a QR code.
			var tops = {};
			var box = null;
			Array.prototype.forEach.call(range.getClientRects(), function (c) {
				if (c.width <= 0) {
					return;
				}
				tops[Math.round(c.top)] = 1;
				box = box
					? { left: Math.min(box.left, c.left), top: Math.min(box.top, c.top), right: Math.max(box.right, c.right), bottom: Math.max(box.bottom, c.bottom) }
					: { left: c.left, top: c.top, right: c.right, bottom: c.bottom };
			});
			box = box || { left: 0, top: 0, right: 0, bottom: 0 };
			return { left: box.left, top: box.top, width: box.right - box.left, height: box.bottom - box.top, lines: Math.max(1, Object.keys(tops).length) };
		};
		var origin = this.rectOf(page);
		this.ox = origin.left;
		this.oy = origin.top;
		this.width = origin.width;
		this.height = origin.height;
		this.elements = [];
		this.report = [];
	}

	Measurer.prototype.box = function (el) {
		var r = this.rectOf(el);
		return { x: round(r.left - this.ox), y: round(r.top - this.oy), w: round(r.width), h: round(r.height) };
	};

	/** The content box: the border box less borders and padding. */
	Measurer.prototype.contentBox = function (el, cs) {
		var b = this.box(el);
		var l = px(cs.borderLeftWidth) + px(cs.paddingLeft);
		var r = px(cs.borderRightWidth) + px(cs.paddingRight);
		var tp = px(cs.borderTopWidth) + px(cs.paddingTop);
		var bt = px(cs.borderBottomWidth) + px(cs.paddingBottom);
		return { x: round(b.x + l), y: round(b.y + tp), w: round(Math.max(1, b.w - l - r)), h: round(Math.max(1, b.h - tp - bt)) };
	};

	Measurer.prototype.onPage = function (b) {
		return b.w > 0 && b.h > 0 && b.x < this.width && b.y < this.height && b.x + b.w > 0 && b.y + b.h > 0;
	};

	Measurer.prototype.add = function (element, status, label) {
		element.id = 'el-' + (this.elements.length + 1);
		this.elements.push(element);
		this.report.push({ status: status, label: label });
	};

	/** Fills and borders of a box become frames: one, or one per side. */
	Measurer.prototype.frames = function (el, cs) {
		var b = this.box(el);
		if (!this.onPage(b)) {
			return;
		}
		var fill = el === this.skipFill ? '' : hex(cs.backgroundColor);
		var sides = ['Top', 'Right', 'Bottom', 'Left'].map(function (side) {
			var w = cs['border' + side + 'Style'] === 'none' || cs['border' + side + 'Style'] === 'hidden' ? 0 : px(cs['border' + side + 'Width']);
			return { side: side, w: w, color: hex(cs['border' + side + 'Color']), style: cs['border' + side + 'Style'] };
		}).filter(function (s) { return s.w > 0 && s.color; });
		var radius = px(cs.borderTopLeftRadius);
		var uniform = sides.length === 4 && sides.every(function (s) { return s.w === sides[0].w && s.color === sides[0].color && s.style === sides[0].style; });
		var style = uniform && /^(solid|dashed|dotted|double)$/.test(sides[0].style) ? sides[0].style : 'solid';

		if (uniform) {
			this.add({ type: 'frame', x: b.x, y: b.y, w: b.w, h: b.h, border_width: sides[0].w, border_style: style, border_color: sides[0].color, radius: radius, fill: fill }, STATUS.converted, t('frame', 'Frame'));
			return;
		}
		if (fill) {
			this.add({ type: 'frame', x: b.x, y: b.y, w: b.w, h: b.h, border_width: 0, border_style: 'solid', border_color: fill, radius: radius, fill: fill }, STATUS.converted, t('frame', 'Frame'));
		}
		// A border on some sides only: each is a thin filled strip.
		sides.forEach(function (s) {
			var strip = {
				Top: { x: b.x, y: b.y, w: b.w, h: s.w },
				Bottom: { x: b.x, y: round(b.y + b.h - s.w), w: b.w, h: s.w },
				Left: { x: b.x, y: b.y, w: s.w, h: b.h },
				Right: { x: round(b.x + b.w - s.w), y: b.y, w: s.w, h: b.h }
			}[s.side];
			this.add({ type: 'frame', x: strip.x, y: strip.y, w: strip.w, h: strip.h, border_width: 0, border_style: 'solid', border_color: s.color, radius: 0, fill: s.color }, STATUS.converted, t('line', 'Line'));
		}, this);
	};

	/** Whether every descendant is an inline tag (no image, no held QR). */
	Measurer.prototype.isTextBlock = function (el) {
		var self = this;
		var all = el.querySelectorAll('*');
		for (var i = 0; i < all.length; i++) {
			var d = all[i];
			if (d.hasAttribute('data-ffc-qr')) {
				continue;
			}
			if (!INLINE[d.tagName]) {
				return false;
			}
			var display = self.styleOf(d).display;
			if (display && display !== 'inline' && display !== 'contents' && d.tagName !== 'BR') {
				return false;
			}
		}
		return /\S/.test(el.textContent || '');
	};

	/** The text a block reads: whitespace collapsed, a `<br>` a new line. */
	function blockText(el, pre) {
		var out = '';
		(function walk(node) {
			Array.prototype.forEach.call(node.childNodes, function (n) {
				if (n.nodeType === 3) {
					// Collapse as CSS does: a non-breaking space is kept.
					out += pre ? n.nodeValue : n.nodeValue.replace(/[ \t\n\r\f]+/g, ' ');
				} else if (n.nodeType === 1) {
					if (n.tagName === 'BR') {
						out += '\n';
					} else {
						walk(n);
					}
				}
			});
		})(el);
		return out.split('\n').map(function (line) { return pre ? line : line.replace(/^[ \t\r\f]+|[ \t\r\f]+$/g, ''); }).join('\n').replace(/^\n+|\n+$/g, '');
	}

	var TEXT_KEYS = ['fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'color', 'letterSpacing', 'textTransform', 'textDecorationLine', 'verticalAlign'];

	/** Whether an inline descendant draws its text differently from the block. */
	Measurer.prototype.isMixed = function (el, cs) {
		var self = this;
		return Array.prototype.some.call(el.querySelectorAll('*'), function (d) {
			if (d.tagName === 'BR' || !/\S/.test(d.textContent || '')) {
				return false;
			}
			var ds = self.styleOf(d);
			return TEXT_KEYS.some(function (k) { return (ds[k] || '') !== (cs[k] || ''); });
		});
	};

	/** The text properties of a block, from its computed style. */
	Measurer.prototype.textProps = function (el, cs, box) {
		var size = px(cs.fontSize) || 16;
		var lh = cs.lineHeight === 'normal' || !px(cs.lineHeight)
			? box.h / this.lineCount(el) / size
			: px(cs.lineHeight) / size;
		var fit = el.closest('.ffc-fit-box') ? 'box' : (el.closest('.ffc-fit') ? 'line' : 'none');
		return {
			font_family: cs.fontFamily || '',
			font_size: round(size),
			font_weight: parseInt(cs.fontWeight, 10) || (cs.fontWeight === 'bold' ? 700 : 400),
			font_style: /italic|oblique/.test(cs.fontStyle) ? 'italic' : 'normal',
			color: hex(cs.color) || '#000000',
			align: ALIGN[cs.textAlign] || 'left',
			letter_spacing: cs.letterSpacing === 'normal' ? 0 : round(px(cs.letterSpacing)),
			line_height: Math.round(Math.max(0.5, Math.min(5, lh)) * 1000) / 1000,
			text_transform: TRANSFORM[cs.textTransform] ? cs.textTransform : 'none',
			fit: fit
		};
	};

	/** The text declarations an HTML block carries from where it stood. */
	function textStyle(cs) {
		return [
			'font-family:' + cs.fontFamily,
			'font-size:' + cs.fontSize,
			'font-weight:' + cs.fontWeight,
			'font-style:' + cs.fontStyle,
			'color:' + (hex(cs.color) || '#000000'),
			'line-height:' + cs.lineHeight,
			'text-align:' + cs.textAlign,
			'letter-spacing:' + cs.letterSpacing,
			'text-transform:' + cs.textTransform
		].join(';').replace(/"/g, '\'');
	}

	// Tags the layout policy lets carry a style; any other keeps its style on a
	// span inside it, or the save would strip it (`<strong>` takes none).
	var STYLED = { SPAN: 1, DIV: 1, P: 1, IMG: 1, TABLE: 1, TR: 1, TD: 1, TH: 1, HR: 1, H1: 1, H2: 1, H3: 1, H4: 1, H5: 1, H6: 1, UL: 1, OL: 1, LI: 1 };

	/** An element's own declarations, colours as hex (the save drops `rgb()`). */
	function ownStyle(node) {
		var out = [];
		for (var i = 0; i < node.style.length; i++) {
			var prop = node.style[i];
			var val = node.style.getPropertyValue(prop);
			if (/color$/.test(prop)) {
				val = hex(val);
				if (!val) {
					continue;
				}
			}
			out.push(prop + ':' + val + (node.style.getPropertyPriority(prop) ? ' !important' : ''));
		}
		return out.join(';').replace(/"/g, '\'');
	}

	/**
	 * Markup an HTML block can keep through the save.
	 *
	 * @param {Element} el The block whose content is kept.
	 * @return {string}
	 */
	function keepable(el) {
		var clone = el.cloneNode(true);
		Array.prototype.forEach.call(clone.querySelectorAll('[style]'), function (n) {
			var s = ownStyle(n);
			if (STYLED[n.tagName] || n.hasAttribute('data-ffc-qr')) {
				n.setAttribute('style', s);
				return;
			}
			n.removeAttribute('style');
			if (s) {
				var span = document.createElement('span');
				span.setAttribute('style', s);
				while (n.firstChild) {
					span.appendChild(n.firstChild);
				}
				n.appendChild(span);
			}
		});
		return clone.innerHTML;
	}

	function snippet(text) {
		text = String(text || '').replace(/\s+/g, ' ').trim();
		return text.length > 60 ? text.slice(0, 57) + '…' : text;
	}

	Measurer.prototype.html = function (el, cs, box, inner, reason) {
		if (!this.onPage(box)) {
			return;
		}
		var html = '<div style="' + textStyle(cs) + '">' + releaseQr(inner) + '</div>';
		this.add({ type: 'html', x: box.x, y: box.y, w: box.w, h: box.h, html: html }, STATUS.kept, t('html', 'HTML block') + ': ' + (reason ? reason + ' — ' : '') + snippet(releaseQr(inner).replace(/<[^>]*>/g, ' ')));
	};

	/** One text or field element, from its text, style and box. */
	Measurer.prototype.addText = function (text, props, box) {
		var element = { x: box.x, y: box.y, w: box.w, h: box.h };
		if (FIELD_TOKEN.test(text)) {
			element.type = 'field';
			element.token = text;
			this.add(Object.assign(element, props), STATUS.converted, t('field', 'Data field') + ': ' + text);
			return;
		}
		element.type = 'text';
		element.text = text;
		this.add(Object.assign(element, props), STATUS.converted, t('text', 'Text') + ': ' + snippet(text));
	};

	/** The element whose style a run of nodes draws with, or null when it mixes. */
	Measurer.prototype.runStyle = function (nodes) {
		var self = this;
		var texts = [];
		nodes.forEach(function (n) {
			if (n.nodeType === 3) {
				if (/\S/.test(n.nodeValue)) {
					texts.push(n.parentNode);
				}
				return;
			}
			if (n.nodeType === 1 && /\S/.test(n.textContent || '')) {
				var walker = document.createTreeWalker(n, 4);
				var node;
				while ((node = walker.nextNode())) {
					if (/\S/.test(node.nodeValue)) {
						texts.push(node.parentNode);
					}
				}
			}
		});
		if (!texts.length) {
			return null;
		}
		var first = self.styleOf(texts[0]);
		var same = texts.every(function (owner) {
			var s = self.styleOf(owner);
			return TEXT_KEYS.every(function (k) { return (s[k] || '') === (first[k] || ''); });
		});
		return same ? texts[0] : false;
	};

	/**
	 * A text block, one element per line it breaks into: at each `<br>` and
	 * around the QR code. A line keeps the style it is drawn in; a line that
	 * mixes styles takes the block's, and the report says so (#2606).
	 */
	Measurer.prototype.text = function (el, cs) {
		var box = this.contentBox(el, cs);
		if (!this.onPage(box)) {
			this.report.push({ status: STATUS.dropped, label: t('offPage', 'Off the page') + ': ' + snippet(el.textContent) });
			return;
		}
		// An underline or a shadow is not something a text element draws: the
		// block keeps its look as HTML.
		if ((cs.textDecorationLine && cs.textDecorationLine !== 'none') || (cs.textShadow && cs.textShadow !== 'none')) {
			this.html(el, cs, box, keepable(el), t('decoration', 'underline or shadow'));
			return;
		}
		// Word spacing has no property: the text keeps its place, a little
		// tighter, and the report says so.
		if (cs.wordSpacing && cs.wordSpacing !== 'normal' && px(cs.wordSpacing) !== 0) {
			this.report.push({ status: STATUS.dropped, label: t('wordSpacing', 'Word spacing') + ' (' + cs.wordSpacing + '): ' + snippet(el.textContent) });
		}
		var pre = /^pre/.test(cs.whiteSpace || '');

		// One style and no QR code: the whole block is one element.
		if (!el.querySelector('[data-ffc-qr]') && !this.isMixed(el, cs)) {
			this.addText(blockText(el, pre), this.textProps(el, cs, box), box);
			return;
		}

		// Otherwise, a run of nodes between breaks is one line.
		var runs = [];
		var run = [];
		var self = this;
		(function split(parent) {
			Array.prototype.forEach.call(parent.childNodes, function (n) {
				if (n.nodeType === 1 && (n.tagName === 'BR' || n.hasAttribute('data-ffc-qr'))) {
					runs.push(run);
					run = [];
					if (n.hasAttribute('data-ffc-qr')) {
						runs.push(n);
					}
					return;
				}
				// A break inside an inline tag splits there too.
				if (n.nodeType === 1 && n.querySelector('br, [data-ffc-qr]')) {
					split(n);
					return;
				}
				run.push(n);
			});
		})(el);
		runs.push(run);

		runs.forEach(function (r) {
			if (!Array.isArray(r)) {
				self.visit(r);
				return;
			}
			var holder = document.createElement('div');
			r.forEach(function (n) { holder.appendChild(n.cloneNode(true)); });
			var text = blockText(holder, pre);
			if (!/\S/.test(text)) {
				return;
			}
			var owner = self.runStyle(r);
			var ocs = owner ? self.styleOf(owner) : cs;
			if (false === owner) {
				self.report.push({ status: STATUS.dropped, label: t('merged', 'Styles inside one line merged') + ': ' + snippet(text) });
			}
			var rr = self.runRect(r);
			var size = px(ocs.fontSize) || 16;
			var lh = ocs.lineHeight === 'normal' || !px(ocs.lineHeight) ? rr.height / rr.lines : px(ocs.lineHeight);
			// The line box, not the glyphs: the text sits in it as it did.
			var top = rr.top - self.oy - (lh * rr.lines - rr.height) / 2;
			var lineBox = { x: box.x, y: round(top), w: box.w, h: round(Math.max(1, lh * rr.lines)) };
			var props = self.textProps(owner || el, ocs, lineBox);
			props.line_height = Math.round(Math.max(0.5, Math.min(5, lh / size)) * 1000) / 1000;
			props.align = ALIGN[cs.textAlign] || 'left';
			self.addText(text, props, lineBox);
		});
	};

	Measurer.prototype.image = function (el, cs) {
		var box = this.contentBox(el, cs);
		if (!this.onPage(box)) {
			return;
		}
		var src = el.getAttribute('src') || '';
		var fit = cs.objectFit === 'cover' ? 'cover' : 'contain';
		if (src === SIGNATURE) {
			this.add({ type: 'image', x: box.x, y: box.y, w: box.w, h: box.h, attachment_id: 0, token: SIGNATURE, fit: fit }, STATUS.converted, t('signature', 'Signature'));
			return;
		}
		// An image shipped with the plugin is an image element of its own: the
		// default models' signatures (#2606).
		var slug = String(config().pluginUrl || '').replace(/\/+$/, '').split('/').pop();
		var asset = slug ? new RegExp('/' + slug.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '/(assets/img/[^?#]+)').exec(src) : null;
		if (asset) {
			this.add({ type: 'image', x: box.x, y: box.y, w: box.w, h: box.h, attachment_id: 0, token: '', asset: asset[1], fit: fit }, STATUS.converted, t('image', 'Image') + ': ' + snippet(asset[1].split('/').pop()));
			return;
		}
		// The server swaps it for a Media Library image when it knows the file,
		// and keeps the HTML block otherwise.
		this.add({
			type: 'html',
			x: box.x, y: box.y, w: box.w, h: box.h,
			src: src,
			fit: fit,
			html: '<img src="' + src.replace(/"/g, '&quot;') + '" alt="" style="width:100%;height:100%;object-fit:' + fit + '">'
		}, STATUS.kept, t('image', 'Image') + ': ' + snippet(src.split('/').pop()));
	};

	Measurer.prototype.visit = function (el) {
		var cs = this.styleOf(el);
		if (cs.display === 'none' || cs.visibility === 'hidden') {
			return;
		}
		if (el.hasAttribute('data-ffc-qr')) {
			var q = this.box(el);
			var margin = /margin=(\d+)/.exec(el.getAttribute('data-ffc-qr'));
			this.add({ type: 'qr', x: q.x, y: q.y, w: q.w, h: q.h, margin: margin ? parseInt(margin[1], 10) : 0 }, STATUS.converted, t('qr', 'QR code'));
			return;
		}
		if (el.tagName === 'IMG') {
			this.image(el, cs);
			return;
		}
		// A turned box or a background image is not something a typed element
		// draws: the box stays HTML, whole.
		if ((cs.transform && cs.transform !== 'none') || (cs.backgroundImage && cs.backgroundImage !== 'none')) {
			var holder = document.createElement('div');
			var clone = holder.appendChild(el.cloneNode(true));
			['left', 'top', 'right', 'bottom'].forEach(function (p) { clone.style[p] = ''; });
			clone.style.position = 'static';
			clone.style.margin = '0';
			this.html(el, this.styleOf(el.parentNode), this.box(el), keepable(holder), cs.transform !== 'none' ? t('rotated', 'rotated') : t('background', 'background image'));
			return;
		}

		this.frames(el, cs);

		if (this.isTextBlock(el)) {
			this.text(el, cs);
			return;
		}
		// Text beside boxes in one element: kept whole.
		var loose = Array.prototype.some.call(el.childNodes, function (n) { return n.nodeType === 3 && /\S/.test(n.nodeValue); });
		if (loose) {
			this.html(el, cs, this.contentBox(el, cs), keepable(el), t('mixed', 'mixed text styles'));
			return;
		}
		Array.prototype.forEach.call(el.children, this.visit, this);
	};

	/**
	 * Read a rendered page into a layout and a report.
	 *
	 * @param {Element} content The page's content layer.
	 * @param {string}  orientation 'landscape' or 'portrait'.
	 * @param {Object}  env Optional readers (tests).
	 * @return {{layout: Object, report: Array}}
	 */
	function read(content, orientation, env) {
		var m = new Measurer(content, env);
		var bgColor = '';
		// The page's own root box gives its colour to the page: a full-page
		// frame would cover the template's background image.
		var root = content.children.length === 1 ? content.children[0] : null;
		if (root) {
			var b = m.box(root);
			if (Math.abs(b.w - m.width) <= 2 && Math.abs(b.h - m.height) <= 2) {
				var rs = m.styleOf(root);
				bgColor = hex(rs.backgroundColor);
				if (bgColor) {
					m.report.push({ status: STATUS.converted, label: t('pageColour', 'Page colour') + ': ' + bgColor });
				}
				m.skipFill = root;
			}
		}
		Array.prototype.forEach.call(content.children, m.visit, m);
		return {
			layout: { version: 1, page: { orientation: orientation }, elements: m.elements },
			report: m.report,
			bg_color: bgColor
		};
	}

	function waitForImages(root) {
		return Promise.all(Array.prototype.map.call(root.querySelectorAll('img'), function (img) {
			return img.complete ? null : new Promise(function (done) {
				img.addEventListener('load', done);
				img.addEventListener('error', done);
			});
		}));
	}

	/**
	 * Render the template the way the PDF does and read it.
	 *
	 * @param {string} html Template HTML.
	 * @return {Promise<{layout: Object, report: Array}>}
	 */
	function measure(html) {
		var held = holdQr(html);
		function render(orientation) {
			var size = PAGE[orientation];
			var host = document.createElement('div');
			host.setAttribute('aria-hidden', 'true');
			host.setAttribute('style', 'position:fixed;left:0;top:0;z-index:-1;pointer-events:none;overflow:hidden;width:' + size.w + 'px;height:' + size.h + 'px;');
			host.innerHTML = '<div class="ffc-pdf-wrapper" style="position:relative;width:' + size.w + 'px;height:' + size.h + 'px;"><div class="ffc-pdf-content"></div></div>';
			// The template's stored HTML, sanitised on save; the preview capture
			// renders the same, and the server sanitises the layout again.
			host.querySelector('.ffc-pdf-content').innerHTML = held;
			document.body.appendChild(host);
			return host;
		}

		var host = render('landscape');
		return waitForImages(host)
			.then(function () { return document.fonts ? document.fonts.ready : null; })
			.then(function () {
				var content = host.querySelector('.ffc-pdf-content');
				var first = content.firstElementChild;
				var orientation = 'landscape';
				if (first) {
					var r = first.getBoundingClientRect();
					if (r.height > r.width && r.height > PAGE.landscape.h) {
						orientation = 'portrait';
					}
				}
				if (orientation === 'portrait') {
					host.parentNode.removeChild(host);
					host = render('portrait');
					content = host.querySelector('.ffc-pdf-content');
				}
				var result = read(content, orientation);
				host.parentNode.removeChild(host);
				return result;
			}, function (error) {
				if (host.parentNode) {
					host.parentNode.removeChild(host);
				}
				throw error;
			});
	}

	function post(data) {
		var body = new window.FormData();
		Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
		return window.fetch(config().ajaxUrl || window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (!json || !json.success) {
					throw new Error((json && json.data && json.data.message) || 'failed');
				}
				return json.data;
			});
	}

	/**
	 * Take a "Create visual version" click over: measure, save, open the copy.
	 * Anything that fails falls back to the link, the server importer.
	 *
	 * @param {Event} event Click.
	 */
	function onClick(event) {
		var link = event.target.closest('[data-ffc-visual-measure]');
		if (!link || link.getAttribute('aria-busy') === 'true') {
			return;
		}
		event.preventDefault();
		var url = new window.URL(link.href, window.location.href);
		var postId = link.getAttribute('data-ffc-visual-measure');
		var nonce = url.searchParams.get('_wpnonce') || '';
		link.setAttribute('aria-busy', 'true');
		if (window.FFC && window.FFC.say) {
			window.FFC.say(t('busy', 'Reading the template…'));
		}

		post({ action: 'ffc_visual_copy_source', post: postId, _wpnonce: nonce })
			.then(function (data) { return measure(data.html); })
			.then(function (result) {
				return post({
					action: 'ffc_visual_copy_save',
					post: postId,
					_wpnonce: nonce,
					layout: JSON.stringify(result.layout),
					report: JSON.stringify(result.report),
					bg_color: result.bg_color
				});
			})
			.then(function (data) {
				window.location.assign(data.edit_url);
			})
			.catch(function () {
				link.removeAttribute('aria-busy');
				window.location.assign(link.href);
			});
	}

	window.FFCVisualMeasure = { measure: measure, read: read, holdQr: holdQr, releaseQr: releaseQr, hex: hex };

	if (!window.__ffcVisualMeasureBound) {
		window.__ffcVisualMeasureBound = true;
		document.addEventListener('click', onClick);
	}
})(window, document);
