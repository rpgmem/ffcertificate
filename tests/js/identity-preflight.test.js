// Tests for the identity queue's correction preflight (#1397 sprint 4).
//
// The verdicts are the server's and are proved there, against the real repair.
// What this covers is the browser half: that an allowed correction says how
// much it rewrites, that a collision names the holder and links to them rather
// than offering a move the rule would refuse, and that every other refusal is
// the server's own sentence.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffcIdentityPreflight = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		action: 'ffc_identity_preflight_correction',
		nonce: 'preflight-nonce',
	};
	loadScript('assets/js/ffc-identity-preflight.js');
});

const MARKUP = `
<form id="repair-form">
	<input type="text" id="ffc-rf-abc" name="ffc_rf">
	<button type="button" class="ffc-identity-check"
		data-ffc-subject="abc" data-ffc-field="rf"
		data-ffc-value="ffc-rf-abc"
		data-ffc-verdict="ffc-check-abc">Check</button>
	<button type="submit">Correct</button>
	<div class="ffc-identity-verdict" id="ffc-check-abc"
		data-allowed="This correction rewrites %s records."
		data-consolidates="This correction rewrites %s records and consolidates them."
		data-holder="That number belongs to %1$s (#%2$s). If that is the same person, this is a merge."
		data-profile="/wp-admin/user-edit.php?user_id="
		data-open="Open that account"
		data-empty="Enter the number HR confirmed first."
		data-failed="The check could not be completed."></div>
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

beforeEach(() => {
	document.body.innerHTML = MARKUP;
	window.FFC.IdentityPreflight.boot();
});

afterEach(() => {
	vi.restoreAllMocks();
});

function check() {
	window.$('.ffc-identity-check').trigger('click');
}

describe('the correction preflight', () => {
	it('asks for a number before asking the server', () => {
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(chainFrom(null));

		check();

		expect(postSpy).not.toHaveBeenCalled();
		expect(window.$('#ffc-check-abc').text()).toContain('Enter the number HR confirmed first.');
	});

	it('sends the typed value, the subject and the field', () => {
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(chainFrom({
			success: true,
			data: { allowed: true, rows: 3, account: 398, consolidates: false },
		}));

		window.$('#ffc-rf-abc').val(' 1234561 ');
		check();

		expect(postSpy).toHaveBeenCalledTimes(1);
		expect(postSpy.mock.calls[0][1]).toMatchObject({
			action: 'ffc_identity_preflight_correction',
			nonce: 'preflight-nonce',
			subject: 'abc',
			field: 'rf',
			value: '1234561',
		});
	});

	it('says how much an allowed correction would rewrite', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom({
			success: true,
			data: { allowed: true, rows: 4, account: 398, consolidates: false },
		}));

		window.$('#ffc-rf-abc').val('1234561');
		check();

		const $region = window.$('#ffc-check-abc');
		expect($region.text()).toBe('This correction rewrites 4 records.');
		expect($region.find('.ffc-identity-verdict-ok').length).toBe(1);
	});

	it('distinguishes a consolidation from a plain rewrite', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom({
			success: true,
			data: { allowed: true, rows: 2, account: 398, consolidates: true },
		}));

		window.$('#ffc-rf-abc').val('1234561');
		check();

		expect(window.$('#ffc-check-abc').text()).toContain('consolidates');
	});

	it('names the holder on a collision and links to them, offering no move', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom({
			success: true,
			data: {
				allowed: false,
				code: 'ffc_identity_repair_collision',
				message: 'That value is already stored against another account.',
				holder: { id: 513, name: 'Clarice Fontes Miranda', email: 'c@example.org' },
			},
		}));

		window.$('#ffc-rf-abc').val('1234561');
		check();

		const $region = window.$('#ffc-check-abc');
		expect($region.text()).toContain('Clarice Fontes Miranda');
		expect($region.text()).toContain('#513');
		expect($region.find('.ffc-identity-verdict-warn').length).toBe(1);

		const $link = $region.find('a');
		expect($link.attr('href')).toBe('/wp-admin/user-edit.php?user_id=513');
		expect($link.text()).toBe('Open that account');
	});

	it('falls back to the account number when the holder has no display name', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom({
			success: true,
			data: {
				allowed: false,
				code: 'ffc_identity_repair_collision',
				message: 'Taken.',
				holder: { id: 77, name: '', email: '' },
			},
		}));

		window.$('#ffc-rf-abc').val('1234561');
		check();

		expect(window.$('#ffc-check-abc').text()).toContain('#77');
	});

	it('shows every other refusal in the server\'s own words', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom({
			success: true,
			data: {
				allowed: false,
				code: 'ffc_identity_repair_invalid',
				message: 'That number does not satisfy its own check digits.',
			},
		}));

		window.$('#ffc-rf-abc').val('1111111');
		check();

		const $region = window.$('#ffc-check-abc');
		expect($region.text()).toBe('That number does not satisfy its own check digits.');
		expect($region.find('.ffc-identity-verdict-bad').length).toBe(1);
	});

	it('says so when the request itself fails', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom(null));

		window.$('#ffc-rf-abc').val('1234561');
		check();

		expect(window.$('#ffc-check-abc').text()).toContain('The check could not be completed.');
	});

	it('re-enables the button whichever way the request ends', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom(null));

		window.$('#ffc-rf-abc').val('1234561');
		check();

		expect(window.$('.ffc-identity-check').prop('disabled')).toBe(false);
	});

	it('replaces the previous answer rather than stacking answers', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom({
			success: true,
			data: { allowed: true, rows: 1, account: 398, consolidates: false },
		}));

		window.$('#ffc-rf-abc').val('1234561');
		check();
		check();

		expect(window.$('#ffc-check-abc .ffc-identity-verdict-line').length).toBe(1);
	});

	it('booting twice does not double the handlers', () => {
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(chainFrom({
			success: true,
			data: { allowed: true, rows: 1, account: 398, consolidates: false },
		}));

		window.FFC.IdentityPreflight.boot();
		window.$('#ffc-rf-abc').val('1234561');
		check();

		expect(postSpy).toHaveBeenCalledTimes(1);
	});

	it('binds nothing when the screen prints no correction field', () => {
		document.body.innerHTML = '<div></div>';
		expect(window.FFC.IdentityPreflight.boot()).toBe(false);
	});
});
