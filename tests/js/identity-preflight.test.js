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
	// THE REAL `setRequiredWithin`, NOT THE GUARD AROUND IT.
	//
	// The reveal calls `FFC.setRequiredWithin()` behind a `typeof` guard, so
	// without `ffc-core` loaded these cases would exercise the guard and prove
	// nothing about the attribute -- which is the half that decides whether the
	// form can be submitted at all (#1117).
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-identity-preflight.js');
});

const MARKUP = `
<form id="repair-form">
	<input type="text" id="ffc-rf-abc" name="ffc_rf">
	<button type="button" class="ffc-identity-check"
		data-ffc-subject="abc" data-ffc-field="rf"
		data-ffc-value="ffc-rf-abc"
		data-ffc-verdict="ffc-check-abc"
		data-ffc-ack="ffc-ack-abc">Check</button>
	<button type="submit">Correct</button>
	<label class="ffc-identity-ack" id="ffc-ack-abc" hidden>
		<input type="checkbox" name="ffc_acknowledged" value="1" data-ffc-required-off="">
		I have confirmed this number with HR.
	</label>
	<div class="ffc-identity-verdict" id="ffc-check-abc"
		data-allowed="This correction rewrites %s records."
		data-consolidates="This correction rewrites %s records and consolidates them."
		data-shared="Rewrites %1$s records; the number already belongs to %2$s (#%3$s). Not a merge — confirm below."
		data-holder="That number is on records naming no single other account — open %1$s (#%2$s)."
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

	// #1478: the value belonging to another account stopped being the refusal
	// and became a consequence the correction may produce. So `allowed` can
	// arrive WITH a holder, and reporting the row count alone there would be the
	// worst answer available: true, reassuring, and silent about the only thing
	// the operator needs before a write no use of this verb can undo.
	it('warns and names the holder when an ALLOWED correction shares a number', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom({
			success: true,
			data: {
				allowed: true,
				rows: 3,
				account: 398,
				consolidates: false,
				code: '',
				message: '',
				holder: { id: 513, name: 'Clarice Fontes Miranda', email: 'c@example.org' },
			},
		}));

		window.$('#ffc-rf-abc').val('1234561');
		check();

		const $region = window.$('#ffc-check-abc');
		expect($region.text()).toContain('Clarice Fontes Miranda');
		expect($region.text()).toContain('#513');
		expect($region.text()).toContain('3');

		// WARN AND NOT OK. The tone is the whole point: an `ok` line here is
		// what would let the operator confirm without reading.
		expect($region.find('.ffc-identity-verdict-warn').length).toBe(1);
		expect($region.find('.ffc-identity-verdict-ok').length).toBe(0);
		expect($region.find('a').attr('href')).toBe('/wp-admin/user-edit.php?user_id=513');
	});

	// The counterpart, without which a payload carrying a holder on every
	// verdict would pass the test above and warn on the common case.
	it('stays plain when an allowed correction shares nothing', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom({
			success: true,
			data: { allowed: true, rows: 3, account: 398, consolidates: false, holder: null },
		}));

		window.$('#ffc-rf-abc').val('1234561');
		check();

		const $region = window.$('#ffc-check-abc');
		expect($region.find('.ffc-identity-verdict-ok').length).toBe(1);
		expect($region.find('.ffc-identity-verdict-warn').length).toBe(0);
		expect($region.find('a').length).toBe(0);
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

// The acknowledgement the operator ticks before a correction that shares a
// number with another account (#1487).
//
// The box used to be rendered only by the server, for a named error code on a
// named finding -- and the repair handler's outcome carried neither, so it was
// never drawn on the form that refusal arrives from. It is now shipped hidden
// and revealed here, which also answers in one request what took a refusal and
// a re-render.
describe('the acknowledgement the preflight reveals', () => {
	const $box = () => window.$('#ffc-ack-abc');
	const $tick = () => window.$('#ffc-ack-abc input[type="checkbox"]');

	function answer(data) {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom({ success: true, data }));
		window.$('#ffc-rf-abc').val('1234561');
		check();
	}

	it('ships hidden, and not required, before anything is checked', () => {
		expect($box().prop('hidden')).toBe(true);

		// The point of the marker: a `required` control inside a hidden block
		// blocks the submit against something nobody can see, so the ordinary
		// correction -- which needs no acknowledgement -- must stay submittable.
		expect($tick().prop('required')).toBe(false);
		expect(document.getElementById('repair-form').checkValidity()).toBe(true);
	});

	it('appears when the confirmed value already belongs to another account', () => {
		answer({ allowed: true, rows: 2, account: 398, holder: { id: 6247, name: 'A. Person' } });

		expect($box().prop('hidden')).toBe(false);
		expect($tick().prop('required')).toBe(true);
	});

	it('is what the form then waits for, rather than submitting silently', () => {
		answer({ allowed: true, rows: 2, account: 398, holder: { id: 6247, name: 'A. Person' } });

		const form = document.getElementById('repair-form');
		expect(form.checkValidity()).toBe(false);

		$tick().prop('checked', true);
		expect(form.checkValidity()).toBe(true);
	});

	it('stays away on a plain allowed correction', () => {
		answer({ allowed: true, rows: 4, account: 398, consolidates: false });

		expect($box().prop('hidden')).toBe(true);
		expect($tick().prop('required')).toBe(false);
	});

	it('stays away on a refusal, which is not something to acknowledge', () => {
		answer({ allowed: false, message: 'That number does not satisfy its own check digits.' });

		expect($box().prop('hidden')).toBe(true);
		expect($tick().prop('required')).toBe(false);
	});

	// A HOLDER IS NOT THE TRIGGER; AN ALLOWED VERDICT CARRYING ONE IS.
	//
	// A refusal can name a holder too -- rows nobody owns, or more than one
	// other account -- and that one is not acknowledgeable: there is no write
	// the operator could authorise from here. Reading `data.holder` alone would
	// offer a box that cannot resolve anything, which is the shape this whole
	// issue was about in the other direction.
	it('stays away when a refusal names a holder', () => {
		answer({ allowed: false, message: 'Records naming no single other account.', holder: { id: 6247, name: 'A. Person' } });

		expect($box().prop('hidden')).toBe(true);
		expect($tick().prop('required')).toBe(false);
	});

	// THE HALF THAT MATTERS MORE THAN THE REVEAL.
	//
	// An operator who checks a shared number, ticks the box, then edits the
	// value and checks again must not be left carrying an acknowledgement read
	// for a different number. Hiding alone would leave it ticked and posted.
	it('unticks itself when a re-check no longer needs it', () => {
		answer({ allowed: true, rows: 2, account: 398, holder: { id: 6247, name: 'A. Person' } });
		$tick().prop('checked', true);
		expect($tick().prop('checked')).toBe(true);

		vi.restoreAllMocks();
		answer({ allowed: true, rows: 2, account: 398, consolidates: false });

		expect($box().prop('hidden')).toBe(true);
		expect($tick().prop('checked')).toBe(false);
	});

	it('does not throw on a form that ships no box', () => {
		window.$('#ffc-ack-abc').remove();

		expect(() => answer({
			allowed: true,
			rows: 2,
			account: 398,
			holder: { id: 6247, name: 'A. Person' },
		})).not.toThrow();
	});
});
