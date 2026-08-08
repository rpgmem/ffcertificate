// Tests for `assets/js/ffc-branding-media.js` — the generic Media Library
// picker for the Settings → General branding logo fields (#865 Phase 2).
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function reset() {
	document.body.innerHTML = '';
	delete window.wp;
	delete window.ffcBrandingMedia;
}

describe('ffc-branding-media.js', () => {
	beforeEach(() => {
		reset();
		document.body.innerHTML =
			'<input id="logo_gov" value="old">' +
			'<button class="ffc-media-select" data-ffc-media-target="#logo_gov">Select</button>' +
			'<button class="ffc-media-clear" data-ffc-media-target="#logo_gov">Clear</button>';
		loadScript('assets/js/ffc-branding-media.js');
	});

	afterEach(reset);

	it('clears the target input on Clear', () => {
		window.$('.ffc-media-clear').trigger('click');
		expect(document.querySelector('#logo_gov').value).toBe('');
	});

	it('opens wp.media and writes the selected URL into the target', () => {
		const selection = { first: () => ({ toJSON: () => ({ url: 'https://cdn/x.png' }) }) };
		const frame = {
			cb: null,
			on(evt, cb) { if (evt === 'select') { this.cb = cb; } },
			state: () => ({ get: () => selection }),
			open() { if (this.cb) { this.cb(); } },
		};
		window.wp = { media: vi.fn(() => frame) };

		window.$('.ffc-media-select').trigger('click');

		expect(window.wp.media).toHaveBeenCalled();
		expect(document.querySelector('#logo_gov').value).toBe('https://cdn/x.png');
	});

	it('is a no-op on Select when wp.media is unavailable', () => {
		window.$('.ffc-media-select').trigger('click');
		expect(document.querySelector('#logo_gov').value).toBe('old');
	});
});
