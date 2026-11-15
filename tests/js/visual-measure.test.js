// The measured visual copy (#2606): reading a rendered page into a layout.
//
// jsdom has no layout, so each test lays the page out by hand: the rect and
// style readers are injected, as the module allows for exactly this.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

const BASE = {
	display: 'block', visibility: 'visible', transform: 'none', backgroundImage: 'none', backgroundColor: 'rgba(0, 0, 0, 0)',
	borderTopWidth: '0px', borderRightWidth: '0px', borderBottomWidth: '0px', borderLeftWidth: '0px',
	borderTopStyle: 'none', borderRightStyle: 'none', borderBottomStyle: 'none', borderLeftStyle: 'none',
	borderTopColor: 'rgb(0, 0, 0)', borderRightColor: 'rgb(0, 0, 0)', borderBottomColor: 'rgb(0, 0, 0)', borderLeftColor: 'rgb(0, 0, 0)',
	borderTopLeftRadius: '0px', paddingTop: '0px', paddingRight: '0px', paddingBottom: '0px', paddingLeft: '0px',
	fontFamily: 'Arial', fontSize: '16px', fontWeight: '400', fontStyle: 'normal', color: 'rgb(0, 0, 0)', letterSpacing: 'normal',
	textTransform: 'none', textDecorationLine: 'none', textShadow: 'none', verticalAlign: 'baseline', lineHeight: '20px',
	textAlign: 'start', whiteSpace: 'normal', wordSpacing: '0px', objectFit: 'fill'
};

/**
 * Lay a page out: each element carries `data-r="x,y,w,h"` and, optionally,
 * `data-s='{"cssProp":"value"}'`; inline tags inherit their parent's style.
 */
function env() {
	const styles = new Map();
	const style = (el) => {
		if (styles.has(el)) {
			return styles.get(el);
		}
		const inline = el.tagName === 'SPAN' || el.tagName === 'STRONG' || el.tagName === 'EM' || el.tagName === 'BR' || el.tagName === 'B';
		const parent = inline && el.parentElement ? style(el.parentElement) : BASE;
		const s = Object.assign({}, parent, inline ? { display: 'inline' } : {}, JSON.parse(el.getAttribute('data-s') || '{}'));
		styles.set(el, s);
		return s;
	};
	const rect = (el) => {
		const [x, y, w, h] = (el.getAttribute('data-r') || '0,0,0,0').split(',').map(Number);
		return { left: x, top: y, width: w, height: h };
	};
	// A run's box: the union of its elements' rects, or the text's own
	// `data-run` on the parent when the run is bare text.
	const runRect = (nodes) => {
		const els = nodes.filter((n) => n.nodeType === 1 && n.getAttribute('data-r'));
		const src = els.length ? els : [nodes[0].parentElement];
		const rs = src.map((el) => (el.getAttribute('data-run') || el.getAttribute('data-r')).split(',').map(Number));
		const left = Math.min(...rs.map((r) => r[0]));
		const top = Math.min(...rs.map((r) => r[1]));
		const right = Math.max(...rs.map((r) => r[0] + r[2]));
		const bottom = Math.max(...rs.map((r) => r[1] + r[3]));
		return { left, top, width: right - left, height: bottom - top, lines: 1 };
	};
	return { rect, style, runRect, lines: () => 1 };
}

function page(html) {
	const content = document.createElement('div');
	content.setAttribute('data-r', '0,0,1123,794');
	content.innerHTML = html;
	document.body.appendChild(content);
	return content;
}

beforeAll(() => {
	window.ffcVisualMeasure = { strings: {} };
	loadScript('assets/js/ffc-visual-measure.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
});

describe('FFCVisualMeasure.read', () => {
	it('turns text blocks into text and field elements with their computed style', () => {
		const content = page(
			'<p data-r="100,50,600,40" data-s=\'{"fontSize":"32px","fontWeight":"700","color":"rgb(138, 109, 29)","textAlign":"center","letterSpacing":"6px","textTransform":"uppercase","lineHeight":"40px","fontFamily":"\\"EB Garamond\\", serif"}\'>Certificate</p>'
			+ '<p class="ffc-fit" data-r="100,120,600,50" data-s=\'{"fontSize":"48px","lineHeight":"60px"}\'>{{name}}</p>'
			+ '<p data-r="100,200,600,40">Line one<br>  Line   two</p>'
		);
		const { layout, report } = window.FFCVisualMeasure.read(content, 'landscape', env());

		expect(layout.page.orientation).toBe('landscape');
		const [title, name, two] = layout.elements;
		expect(title).toMatchObject({ type: 'text', text: 'Certificate', x: 100, y: 50, w: 600, h: 40, font_size: 32, font_weight: 700, color: '#8a6d1d', align: 'center', letter_spacing: 6, text_transform: 'uppercase', line_height: 1.25, font_family: '"EB Garamond", serif', fit: 'none' });
		expect(name).toMatchObject({ type: 'field', token: '{{name}}', font_size: 48, line_height: 1.25, fit: 'line' });
		expect(two.text).toBe('Line one\nLine two');
		expect(report.map((r) => r.status)).toEqual(['converted', 'converted', 'converted']);
	});

	it('draws fills and borders as frames, one per side when the sides differ', () => {
		const content = page(
			'<div data-r="10,10,200,100" data-s=\'{"backgroundColor":"rgb(250, 250, 250)","borderTopWidth":"2px","borderRightWidth":"2px","borderBottomWidth":"2px","borderLeftWidth":"2px","borderTopStyle":"dashed","borderRightStyle":"dashed","borderBottomStyle":"dashed","borderLeftStyle":"dashed","borderTopColor":"rgb(1, 2, 3)","borderRightColor":"rgb(1, 2, 3)","borderBottomColor":"rgb(1, 2, 3)","borderLeftColor":"rgb(1, 2, 3)","borderTopLeftRadius":"8px"}\'></div>'
			+ '<p data-r="300,10,400,60" data-s=\'{"borderBottomWidth":"1px","borderBottomStyle":"solid","borderBottomColor":"rgb(201, 162, 39)","paddingBottom":"9px"}\'>Name</p>'
		);
		const { layout } = window.FFCVisualMeasure.read(content, 'landscape', env());

		expect(layout.elements[0]).toMatchObject({ type: 'frame', x: 10, y: 10, w: 200, h: 100, border_width: 2, border_style: 'dashed', border_color: '#010203', radius: 8, fill: '#fafafa' });
		// The bottom border alone is a 1 px strip at the box's foot.
		expect(layout.elements[1]).toMatchObject({ type: 'frame', x: 300, y: 69, w: 400, h: 1, fill: '#c9a227', border_width: 0 });
		// The text sits in the content box: padding and border are not text.
		expect(layout.elements[2]).toMatchObject({ type: 'text', text: 'Name', y: 10, h: 50 });
	});

	it('splits a block whose lines differ into one text per line', () => {
		const content = page('<p data-r="0,600,500,40" data-run="0,600,500,20" data-s=\'{"color":"rgb(62, 76, 89)"}\'>has completed<br><strong data-r="0,620,500,20" data-s=\'{"fontSize":"24px","fontWeight":"700"}\'>{{form_title}}</strong></p>');
		const { layout, report } = window.FFCVisualMeasure.read(content, 'landscape', env());

		expect(layout.elements.map((e) => e.type)).toEqual(['text', 'field']);
		expect(layout.elements[0]).toMatchObject({ text: 'has completed', color: '#3e4c59', y: 600, h: 20 });
		expect(layout.elements[1]).toMatchObject({ token: '{{form_title}}', font_size: 24, font_weight: 700, y: 620 });
		expect(report.every((r) => r.status === 'converted')).toBe(true);
	});

	it('merges the styles inside one line and says so', () => {
		const content = page('<p data-r="0,700,600,20">Verify with code <strong data-r="300,700,80,20" data-s=\'{"fontWeight":"700"}\'>{{auth_code}}</strong><br>next</p>');
		const { layout, report } = window.FFCVisualMeasure.read(content, 'landscape', env());

		expect(layout.elements[0]).toMatchObject({ type: 'text', text: 'Verify with code {{auth_code}}', font_weight: 400 });
		expect(report.filter((r) => r.status === 'dropped').map((r) => r.label)).toEqual(['Styles inside one line merged: Verify with code {{auth_code}}']);
	});

	it('keeps an underlined block as HTML, styles the save would keep', () => {
		const content = page('<p data-r="0,600,500,40" data-s=\'{"color":"rgb(62, 76, 89)","textDecorationLine":"underline"}\' style="color: rgb(62, 76, 89)">has <strong style="font-size: 18pt; color: #1b2a41">{{form_title}}</strong></p>');
		const { layout, report } = window.FFCVisualMeasure.read(content, 'landscape', env());

		const block = layout.elements[0];
		expect(block.type).toBe('html');
		expect(block.html).not.toContain('rgb(');
		// A <strong> takes no attributes once saved: its style rides a span inside.
		expect(block.html).toMatch(/<strong[^>]*><span style="font-size:\s*18pt;color:#1b2a41">\{\{form_title\}\}<\/span><\/strong>/);
		expect(block.html).not.toMatch(/<strong style=/);
		expect(report[0].status).toBe('kept');
	});

	it('reads the held QR code, a signature and other images', () => {
		const held = window.FFCVisualMeasure.holdQr('<p data-r="0,0,10,10">x</p>{{qr_code:size=50:margin=2}}');
		const content = page(held.replace('<div ', '<div data-r="536,700,50,50" ')
			+ '<img src="{{signer:signature}}" data-r="100,600,150,50" data-s=\'{"objectFit":"cover"}\'>'
			+ '<img src="https://example.test/logo.png" data-r="20,20,80,80">'
			+ '<img src="https://example.test/wp-content/plugins/ffcertificate/assets/img/certificate-defaults/sig.png?ver=1" data-r="20,200,80,40">');
		window.ffcVisualMeasure.pluginUrl = 'https://example.test/wp-content/plugins/ffcertificate/';
		const { layout } = window.FFCVisualMeasure.read(content, 'landscape', env());
		delete window.ffcVisualMeasure.pluginUrl;
		const types = layout.elements.map((e) => e.type);

		expect(types).toEqual(['text', 'qr', 'image', 'html', 'image']);
		// A plugin image is the plugin's file, not a Media Library copy.
		expect(layout.elements[4]).toMatchObject({ asset: 'assets/img/certificate-defaults/sig.png', attachment_id: 0, token: '' });
		expect(layout.elements[1]).toMatchObject({ x: 536, y: 700, w: 50, h: 50, margin: 2 });
		expect(layout.elements[2]).toMatchObject({ token: '{{signer:signature}}', fit: 'cover' });
		expect(layout.elements[3]).toMatchObject({ src: 'https://example.test/logo.png', fit: 'contain' });
		expect(layout.elements[3].html).toContain('<img src="https://example.test/logo.png"');
	});

	it('gives the page box its colour to the page and reports what it drops', () => {
		const content = page(
			'<div data-r="0,0,1123,794" data-s=\'{"backgroundColor":"rgb(253, 251, 245)"}\'>'
			+ '<p data-r="100,100,300,40" data-s=\'{"wordSpacing":"4.8px"}\'>João da Silva</p>'
			+ '<p data-r="2000,100,300,40">Off the page</p>'
			+ '<p data-r="100,200,300,40" data-s=\'{"textShadow":"1px 1px #000"}\'>Shadowed</p>'
			+ '</div>'
		);
		const result = window.FFCVisualMeasure.read(content, 'landscape', env());

		expect(result.bg_color).toBe('#fdfbf5');
		expect(result.layout.elements.some((e) => e.type === 'frame')).toBe(false);
		expect(result.layout.elements.map((e) => e.type)).toEqual(['text', 'html']);
		const dropped = result.report.filter((r) => r.status === 'dropped').map((r) => r.label);
		expect(dropped).toEqual(['Word spacing (4.8px): João da Silva', 'Off the page: Off the page']);
	});

	it('keeps a turned box whole, and keeps non-breaking spaces', () => {
		const content = page(
			'<div data-r="10,10,100,30" data-s=\'{"transform":"matrix(0, 1, -1, 0, 0, 0)"}\' style="position:absolute;left:10px;top:10px">Turned</div>'
			+ '<p data-r="10,100,400,30">Workload  ·  done</p>'
		);
		const { layout } = window.FFCVisualMeasure.read(content, 'landscape', env());

		expect(layout.elements[0].type).toBe('html');
		expect(layout.elements[0].html).toContain('position:static');
		expect(layout.elements[1].text).toBe('Workload  ·  done');
	});
});

describe('FFCVisualMeasure.read with its own readers', () => {
	it('measures runs and lines through ranges when no reader is given', () => {
		const content = page('<p data-r="0,700,600,60">Issued <em>here</em> · <span data-ffc-qr="{{qr_code:size=50}}" data-r="275,720,50,50"></span> · Verify <strong data-s=\'{"fontWeight":"700"}\'>{{auth_code}}</strong></p>');
		const realRange = document.createRange;
		document.createRange = () => ({
			selectNodeContents() {},
			setStartBefore() {},
			setEndAfter() {},
			getClientRects: () => [{ left: 10, top: 702, right: 90, bottom: 718, width: 80, height: 16 }, { left: 0, top: 650, right: 0, bottom: 666, width: 0, height: 16 }, { left: 90, top: 702, right: 130, bottom: 718, width: 40, height: 16 }],
		});
		const e = env();
		try {
			const { layout, report } = window.FFCVisualMeasure.read(content, 'landscape', { rect: e.rect, style: e.style });
			expect(layout.elements.map((x) => x.type)).toEqual(['text', 'qr', 'text']);
			expect(layout.elements[0]).toMatchObject({ text: 'Issued here ·', y: 700, h: 20 });
			expect(layout.elements[2].text).toBe('· Verify {{auth_code}}');
			expect(report.filter((r) => r.status === 'dropped').map((r) => r.label)).toEqual(['Styles inside one line merged: · Verify {{auth_code}}']);
		} finally {
			document.createRange = realRange;
		}
	});

	it('fills a box whose borders differ, and keeps text beside boxes whole', () => {
		const content = page(
			'<div data-r="0,0,100,50" data-s=\'{"backgroundColor":"rgb(1, 2, 3)","borderTopWidth":"1px","borderTopStyle":"solid","borderTopColor":"rgb(9, 9, 9)"}\'></div>'
			+ '<div data-r="0,100,300,80">loose words<div data-r="0,140,300,40">inner</div></div>'
			+ '<img src="x.png" data-r="5000,0,10,10">'
		);
		const { layout, report } = window.FFCVisualMeasure.read(content, 'landscape', env());
		expect(layout.elements[0]).toMatchObject({ type: 'frame', fill: '#010203', border_width: 0 });
		expect(layout.elements[1]).toMatchObject({ type: 'frame', h: 1, fill: '#090909' });
		expect(layout.elements[2].type).toBe('html');
		expect(report[2].status).toBe('kept');
		expect(layout.elements).toHaveLength(3);
	});

	it('tells a data tag from text', () => {
		const read = (text) => window.FFCVisualMeasure.read(page('<p data-r="0,0,100,20">' + text + '</p>'), 'landscape', env()).layout.elements[0].type;
		expect(read('{{issue_date:format=d/m/Y H:i}}')).toBe('field');
		expect(read('{{a:b}}')).toBe('field');
		expect(read('{{Not A Tag}}')).toBe('text');
		expect(read('{{name}} and more')).toBe('text');
		expect(read('{{' + 'a:size='.repeat(40) + '}}')).toBe('text');
	});
});

describe('FFCVisualMeasure.measure', () => {
	it('turns to portrait when the page box is taller than a landscape page', async () => {
		const real = window.HTMLElement.prototype.getBoundingClientRect;
		window.HTMLElement.prototype.getBoundingClientRect = function () {
			if (this.hasAttribute('data-tall')) {
				return { left: 0, top: 0, width: 794, height: 1123 };
			}
			return real.call(this);
		};
		try {
			const result = await window.FFCVisualMeasure.measure('<div data-tall="1"><p>{{name}}</p></div>');
			expect(result.layout.page.orientation).toBe('portrait');
			expect(document.querySelector('.ffc-pdf-wrapper')).toBeNull();
		} finally {
			window.HTMLElement.prototype.getBoundingClientRect = real;
		}
	});
});

describe('FFCVisualMeasure gradients', () => {
	it('reads a plain linear gradient and refuses what a frame cannot draw', () => {
		const read = window.FFCVisualMeasure.readGradient;
		expect(read('linear-gradient(rgb(11, 37, 69) 0%, rgb(19, 49, 92) 45%, rgb(47, 128, 237) 100%)')).toEqual({
			angle: 180,
			stops: [{ color: '#0b2545', at: 0 }, { color: '#13315c', at: 45 }, { color: '#2f80ed', at: 100 }],
		});
		expect(read('linear-gradient(to right, rgb(0, 0, 0), rgb(255, 255, 255), rgb(0, 0, 255))').stops.map((s) => s.at)).toEqual([0, 50, 100]);
		expect(read('linear-gradient(to right, rgb(0, 0, 0), rgb(255, 255, 255))').angle).toBe(90);
		expect(read('linear-gradient(-90deg, #000000 10%, #ffffff 90%)').angle).toBe(270);
		expect(read('radial-gradient(rgb(0, 0, 0), rgb(255, 255, 255))')).toBeNull();
		expect(read('linear-gradient(to top right, rgb(0, 0, 0), rgb(1, 1, 1))')).toBeNull();
		expect(read('linear-gradient(rgba(0, 0, 0, 0.5), rgb(1, 1, 1))')).toBeNull();
		expect(read('linear-gradient(rgb(0, 0, 0), rgb(1, 1, 1)), url("x.png")')).toBeNull();
		expect(read('linear-gradient(rgb(0, 0, 0))')).toBeNull();
		expect(read('linear-gradient(' + Array(6).fill('rgb(0, 0, 0)').join(', ') + ')')).toBeNull();
		expect(read('url("x.png")')).toBeNull();
	});

	it('draws a box with a gradient as a frame, its first colour the fallback', () => {
		const content = page(
			'<div data-r="0,0,34,794" data-s=\'{"backgroundImage":"linear-gradient(rgb(11, 37, 69) 0%, rgb(47, 128, 237) 100%)"}\'></div>'
			+ '<div data-r="100,0,200,100" data-s=\'{"backgroundImage":"linear-gradient(90deg, rgb(0, 0, 0), rgb(255, 255, 255))","backgroundColor":"rgb(9, 9, 9)","borderTopWidth":"1px","borderRightWidth":"1px","borderBottomWidth":"1px","borderLeftWidth":"1px","borderTopStyle":"solid","borderRightStyle":"solid","borderBottomStyle":"solid","borderLeftStyle":"solid"}\'></div>'
			+ '<div data-r="400,0,100,100" data-s=\'{"backgroundImage":"radial-gradient(rgb(0, 0, 0), rgb(255, 255, 255))"}\'></div>'
		);
		const { layout } = window.FFCVisualMeasure.read(content, 'landscape', env());
		expect(layout.elements.map((e) => e.type)).toEqual(['frame', 'frame', 'html']);
		expect(layout.elements[0]).toMatchObject({ fill: '#0b2545', border_width: 0, gradient: { angle: 180 } });
		expect(layout.elements[1]).toMatchObject({ fill: '#090909', border_width: 1, gradient: { angle: 90 } });
	});
});

describe('FFCVisualMeasure helpers', () => {
	it('holds and releases QR tags', () => {
		const held = window.FFCVisualMeasure.holdQr('a {{qr_code}} b');
		expect(held).toContain('data-ffc-qr="{{qr_code}}"');
		expect(held).toContain('width:200px;height:200px');
		expect(window.FFCVisualMeasure.releaseQr(held)).toBe('a {{qr_code}} b');
	});

	it('writes colours as hex and transparent as nothing', () => {
		expect(window.FFCVisualMeasure.hex('rgb(255, 0, 16)')).toBe('#ff0010');
		expect(window.FFCVisualMeasure.hex('rgba(0, 0, 0, 0)')).toBe('');
		expect(window.FFCVisualMeasure.hex('#ABCDEF')).toBe('#abcdef');
		expect(window.FFCVisualMeasure.hex('transparent')).toBe('');
	});
});

describe('the click', () => {
	it('falls back to the server importer when the reading fails', async () => {
		const assign = [];
		const original = window.location;
		delete window.location;
		window.location = { href: 'http://localhost/wp-admin/edit.php', assign: (u) => assign.push(u) };
		window.fetch = () => Promise.resolve({ json: () => Promise.resolve({ success: false, data: { message: 'no' } }) });
		document.body.innerHTML = '<a href="http://localhost/wp-admin/admin.php?action=ffc_visual_copy_cert_template&post=5&_wpnonce=abc" data-ffc-visual-measure="5">Create</a>';

		document.querySelector('a').click();
		await new Promise((r) => setTimeout(r, 20));

		expect(assign).toEqual(['http://localhost/wp-admin/admin.php?action=ffc_visual_copy_cert_template&post=5&_wpnonce=abc']);
		window.location = original;
	});

	it('saves the reading and opens the copy', async () => {
		const assign = [];
		const posts = [];
		const original = window.location;
		delete window.location;
		window.location = { href: 'http://localhost/wp-admin/edit.php', assign: (u) => assign.push(u) };
		window.fetch = (url, opts) => {
			const body = Object.fromEntries(opts.body.entries());
			posts.push(body);
			const data = 'ffc_visual_copy_source' === body.action ? { html: '<p>{{name}}</p>' } : { edit_url: 'post.php?post=77&action=edit' };
			return Promise.resolve({ json: () => Promise.resolve({ success: true, data }) });
		};
		document.body.innerHTML = '<a href="http://localhost/wp-admin/admin.php?action=ffc_visual_copy_cert_template&post=5&_wpnonce=abc" data-ffc-visual-measure="5">Create</a>';
		const said = [];
		window.FFC = { say: (m) => said.push(m) };

		document.querySelector('a').click();
		// A second click while the first runs does nothing.
		document.querySelector('a').click();
		await new Promise((r) => setTimeout(r, 50));
		delete window.FFC;
		expect(said).toEqual(['Reading the template…']);

		expect(posts.map((p) => p.action)).toEqual(['ffc_visual_copy_source', 'ffc_visual_copy_save']);
		expect(posts[1]).toMatchObject({ post: '5', _wpnonce: 'abc' });
		expect(JSON.parse(posts[1].layout).version).toBe(1);
		expect(assign).toEqual(['post.php?post=77&action=edit']);
		window.location = original;
	});
});
