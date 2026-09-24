// Tests for the identity queue's merge preview (#1397 sprint 5).
//
// The numbers are the server's and are proved there, against the real merge.
// What this covers is the browser half: that nothing is previewed before a
// survivor is chosen, that changing the choice clears a preview taken against
// the other one, and that a store holding nothing is still printed.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffcIdentityMergePreview = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		action: 'ffc_identity_preview_merge',
		nonce: 'merge-preview-nonce',
	};
	loadScript('assets/js/ffc-identity-merge-preview.js');
});

const MARKUP = `
<form class="ffc-identity-merge-one">
	<label><input type="radio" name="ffc_keep" class="ffc-identity-keep" value="398"> A</label>
	<label><input type="radio" name="ffc_keep" class="ffc-identity-keep" value="513"> B</label>
	<button type="button" class="ffc-identity-preview"
		data-ffc-a="398" data-ffc-b="513"
		data-ffc-region="ffc-merge-preview-abc"
		data-ffc-ack="ffc-merge-ack-abc"
		data-ffc-go="ffc-merge-go-abc">Show what would move</button>
	<div class="ffc-identity-preview-region" id="ffc-merge-preview-abc"
		data-heading="%1$s keeps the records. %2$s is emptied."
		data-total="%s records move."
		data-store="%1$s in %2$s"
		data-gains="The surviving login also gains the %s it did not hold."
		data-holds="%1$s holds %2$s records today."
		data-choose="Choose which login keeps the records first."
		data-failed="The preview could not be completed."></div>
	<input type="checkbox" id="ffc-merge-ack-abc">
	<button type="submit" id="ffc-merge-go-abc">Merge this pair</button>
</form>`;

/**
 * A `$.post`-shaped chain resolving with one response.
 *
 * @param {Object|null} response
 * @returns {Function}
 */
function chainFrom(response) {
	return function () {
		const chain = {
			done(cb) { if (response) { cb(response); } return chain; },
			fail(cb) { if (!response) { cb(); } return chain; },
			always(cb) { cb(); return chain; },
		};
		return chain;
	};
}

const ANSWER = {
	success: true,
	data: {
		allowed: true,
		survivor: { id: 398, name: 'Clarice Fontes Miranda', records: 1 },
		absorbed: { id: 513, name: 'Clarice F. Miranda Rocha', records: 5 },
		total: 5,
		stores: [
			{ store: 'ffc_submissions', records: 3 },
			{ store: 'ffc_self_scheduling_appointments', records: 2 },
			{ store: 'ffc_recruitment_candidate', records: 0 },
		],
		gains: ['RF'],
		matched: ['CPF'],
	},
};

beforeEach(() => {
	document.body.innerHTML = MARKUP;
	window.FFC.IdentityMergePreview.boot();
});

afterEach(() => {
	vi.restoreAllMocks();
});

function preview() {
	window.$('.ffc-identity-preview').trigger('click');
}

describe('the merge preview', () => {
	it('asks for a survivor before asking the server', () => {
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(chainFrom(ANSWER));

		preview();

		expect(postSpy).not.toHaveBeenCalled();
		expect(window.$('#ffc-merge-preview-abc').text())
			.toContain('Choose which login keeps the records first.');
	});

	it('sends the survivor and derives the absorbed account from the pair', () => {
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(chainFrom(ANSWER));

		window.$('.ffc-identity-keep[value="513"]').prop('checked', true);
		preview();

		expect(postSpy.mock.calls[0][1]).toMatchObject({
			action: 'ffc_identity_preview_merge',
			nonce: 'merge-preview-nonce',
			survivor: '513',
			absorbed: '398',
		});
	});

	it('names who stays, who empties, and how many records move', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom(ANSWER));

		window.$('.ffc-identity-keep[value="398"]').prop('checked', true);
		preview();

		const text = window.$('#ffc-merge-preview-abc').text();
		expect(text).toContain('Clarice Fontes Miranda keeps the records.');
		expect(text).toContain('Clarice F. Miranda Rocha is emptied.');
		expect(text).toContain('5 records move.');
	});

	it('prints a store holding nothing rather than dropping it', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom(ANSWER));

		window.$('.ffc-identity-keep[value="398"]').prop('checked', true);
		preview();

		const items = window.$('#ffc-merge-preview-abc .ffc-identity-preview-stores li')
			.map(function () { return window.$(this).text(); }).get();

		expect(items).toEqual([
			'3 in ffc_submissions',
			'2 in ffc_self_scheduling_appointments',
			'0 in ffc_recruitment_candidate',
		]);
	});

	it('names what the surviving login gains and how much each side holds', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom(ANSWER));

		window.$('.ffc-identity-keep[value="398"]').prop('checked', true);
		preview();

		const text = window.$('#ffc-merge-preview-abc').text();
		expect(text).toContain('gains the RF');
		expect(text).toContain('Clarice Fontes Miranda holds 1 records today.');
		expect(text).toContain('Clarice F. Miranda Rocha holds 5 records today.');
	});

	it('clears a preview when the survivor changes, since it no longer applies', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom(ANSWER));

		window.$('.ffc-identity-keep[value="398"]').prop('checked', true);
		preview();
		expect(window.$('#ffc-merge-preview-abc').children().length).toBeGreaterThan(0);

		window.$('.ffc-identity-keep[value="513"]').prop('checked', true).trigger('change');

		expect(window.$('#ffc-merge-preview-abc').children().length).toBe(0);
	});

	it('shows a refusal in the server\'s own words', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom({
			success: true,
			data: {
				allowed: false,
				code: 'ffc_identity_merge_conflict',
				message: 'Those accounts hold different values for: CPF.',
			},
		}));

		window.$('.ffc-identity-keep[value="398"]').prop('checked', true);
		preview();

		const $region = window.$('#ffc-merge-preview-abc');
		expect($region.text()).toBe('Those accounts hold different values for: CPF.');
		expect($region.find('.ffc-identity-preview-bad').length).toBe(1);
	});

	it('says so when the request itself fails, and re-enables the button', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom(null));

		window.$('.ffc-identity-keep[value="398"]').prop('checked', true);
		preview();

		expect(window.$('#ffc-merge-preview-abc').text()).toContain('The preview could not be completed.');
		expect(window.$('.ffc-identity-preview').prop('disabled')).toBe(false);
	});

	it('replaces the previous answer rather than stacking answers', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom(ANSWER));

		window.$('.ffc-identity-keep[value="398"]').prop('checked', true);
		preview();
		preview();

		expect(window.$('#ffc-merge-preview-abc .ffc-identity-preview-head').length).toBe(1);
	});

	it('booting twice does not double the handlers', () => {
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(chainFrom(ANSWER));

		window.FFC.IdentityMergePreview.boot();
		window.$('.ffc-identity-keep[value="398"]').prop('checked', true);
		preview();

		expect(postSpy).toHaveBeenCalledTimes(1);
	});

	it('binds nothing when the screen prints no pair', () => {
		document.body.innerHTML = '<div></div>';
		expect(window.FFC.IdentityMergePreview.boot()).toBe(false);
	});
});
