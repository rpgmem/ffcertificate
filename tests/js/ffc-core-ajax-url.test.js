// FFC.config.ajaxUrl resolves on every read (#2600).
//
// On the public pages `ffc_ajax` is localized onto scripts that load after
// ffc-core, so a URL read when ffc-core ran fell back to the root path and
// every request 404'd on a WordPress installed in a subdirectory.
import { describe, it, expect, beforeEach } from 'vitest';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { loadScript } from './helpers.js';

beforeEach(() => {
	delete window.FFC;
	delete window.ffc_ajax;
	delete window.ffcCoreConfig;
	delete window.ajaxurl;
});

describe('FFC.config.ajaxUrl', () => {
	it('reads ffc_ajax localized after ffc-core ran', () => {
		loadScript('assets/js/ffc-core.js');
		window.ffc_ajax = { ajax_url: 'https://example.org/wp/wp-admin/admin-ajax.php' };
		expect(window.FFC.config.ajaxUrl).toBe('https://example.org/wp/wp-admin/admin-ajax.php');
	});

	it('falls back to ffcCoreConfig, then to core ajaxurl', () => {
		window.ffcCoreConfig = { ajaxUrl: 'https://example.org/sub/wp-admin/admin-ajax.php' };
		loadScript('assets/js/ffc-core.js');
		expect(window.FFC.config.ajaxUrl).toBe('https://example.org/sub/wp-admin/admin-ajax.php');
		delete window.ffcCoreConfig;
		window.ajaxurl = '/site/wp-admin/admin-ajax.php';
		expect(window.FFC.config.ajaxUrl).toBe('/site/wp-admin/admin-ajax.php');
	});

	it('keeps a URL assigned by a caller', () => {
		window.ffc_ajax = { ajax_url: '/a/wp-admin/admin-ajax.php' };
		loadScript('assets/js/ffc-core.js');
		window.FFC.config.ajaxUrl = '/custom.php';
		expect(window.FFC.config.ajaxUrl).toBe('/custom.php');
		window.FFC.config.ajaxUrl = '';
		expect(window.FFC.config.ajaxUrl).toBe('/a/wp-admin/admin-ajax.php');
	});

	it('FFC.request posts to the late-localized URL', async () => {
		loadScript('assets/js/ffc-core.js');
		window.ffc_ajax = { ajax_url: '/wp/wp-admin/admin-ajax.php', nonce: 'n' };
		let url = '';
		const orig = window.jQuery.post;
		window.jQuery.post = (u) => { url = u; return window.jQuery.Deferred().resolve({ success: true, data: {} }).promise(); };
		try {
			await window.FFC.request('ffc_x', {});
		} finally {
			window.jQuery.post = orig;
		}
		expect(url).toBe('/wp/wp-admin/admin-ajax.php');
	});

	it('no script hard-codes the root admin-ajax.php path', () => {
		const dir = join(process.cwd(), 'assets/js');
		const files = readdirSync(dir).filter((f) => f.endsWith('.js') && !f.endsWith('.min.js'));
		// An independent recount, so a scan that read fewer files fails.
		const recount = readdirSync(dir, { withFileTypes: true }).filter((e) => e.isFile() && /(?<!\.min)\.js$/.test(e.name));
		expect(files.length).toBe(recount.length);
		expect(files).toContain('ffc-core.js');
		const offenders = files.filter((f) => /['"]\/wp-admin\/admin-ajax\.php/.test(readFileSync(join(dir, f), 'utf8')));
		expect(offenders).toEqual([]);
	});
});
