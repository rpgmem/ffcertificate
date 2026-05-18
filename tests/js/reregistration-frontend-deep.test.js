// Sprint C — deep coverage for `assets/js/ffc-reregistration-frontend.js` (507 LOC).
//
// The existing `csv-and-rereg-frontend.test.js` covers the load-side
// smoke + a single open-form delegate. This file drives the full
// loadForm → initForm → handler flow:
//
//   - loadForm AJAX (success / failure / network error), panel auto-
//     creation, prepend to #ffc-user-dashboard.
//   - Input masks (cpf / phone / cep / rf / number / cin).
//   - Blur validation: required, cpf, email, phone, custom_regex.
//   - Divisão → Setor cascade (valid map, malformed JSON, no map).
//   - Acúmulo de Cargos toggle (show/hide).
//   - Dependent selects (parent/child population, malformed JSON).
//   - Working hours (add row, remove row, sync).
//   - Save draft: success / failure / network error.
//   - Submit: validation gate, AJAX success replaces form, AJAX failure
//     with server errors, network error.
//   - Cancel button slides the panel up.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// FFC.request — the migration target — wraps jQuery.post() in a Promise.
// Mock $.post and return a chain whose .done / .fail callback the
// FFC.request internals invoke.
function postChain(spec) {
	const chain = { done: () => chain, fail: () => chain };
	if (spec && 'done' in spec) chain.done = (cb) => { cb(spec.done); return chain; };
	if (spec && spec.fail) chain.fail = (cb) => { cb(spec.fail === true ? undefined : spec.fail); return chain; };
	return chain;
}

// Microtask flush so .then/.catch reactions run before assertions.
function flush() { return Promise.resolve().then(() => Promise.resolve()); }


beforeAll(async () => {
	window.ffcReregistration = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		restUrl: '/wp-json/ffc/v1/',
		nonce: 'r-nonce',
		strings: {
			loading: 'Loading…',
			saving: 'Saving…',
			saveDraft: 'Save Draft',
			draftSaved: 'Draft saved.',
			errorSaving: 'Error saving.',
			submit: 'Send',
			submitting: 'Sending…',
			submitted: 'Submission received!',
			errorSubmitting: 'Error sending.',
			fixErrors: 'Please fix the errors below.',
			errorLoading: 'Error loading form.',
			required: 'Required.',
			invalidCpf: 'Invalid CPF.',
			invalidEmail: 'Invalid email.',
			invalidPhone: 'Invalid phone.',
			invalidFormat: 'Invalid format.',
			selectDivisao: 'Select Division',
			selectSetor: 'Select Sector',
			select: 'Select',
			acumuloShowValue: 'Hold',
			sunday: 'Sun',
			monday: 'Mon',
			tuesday: 'Tue',
			wednesday: 'Wed',
			thursday: 'Thu',
			friday: 'Fri',
			saturday: 'Sat',
		},
	};
	if (!window.FFC) { loadScript('assets/js/ffc-core.js'); }
	loadScript('assets/js/ffc-reregistration-frontend.js');
	await new Promise((r) => setTimeout(r, 0));
});

beforeEach(() => {
	document.body.innerHTML = '';
	window.$.fx.off = true;
});

afterEach(() => {
	vi.restoreAllMocks();
});

// ----------------------------------------------------------------------
// loadForm — banner → AJAX
// ----------------------------------------------------------------------

describe('rereg.loadForm', () => {
	async function setupBanner() {
		document.body.innerHTML = `
			<div id="ffc-user-dashboard"></div>
			<button class="ffc-rereg-open-form" data-reregistration-id="7">Open</button>
		`;
	}

	it('creates the panel and prepends it to #ffc-user-dashboard if missing', async () => {
		await setupBanner();
		// $.post returns a thenable-ish; use the proper chain shape.
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		window.$('.ffc-rereg-open-form').trigger('click');
		await flush();

		const $panel = window.$('#ffc-rereg-form-panel');
		expect($panel.length).toBe(1);
		expect($panel.parent().attr('id')).toBe('ffc-user-dashboard');
		expect($panel.text()).toContain('Loading…');
	});

	it('falls back to appending the panel to body when no dashboard exists', async () => {
		document.body.innerHTML = `<button class="ffc-rereg-open-form" data-reregistration-id="7">Open</button>`;
		const chain = { fail: () => chain };
		vi.spyOn(window.$, 'post').mockImplementation(() => chain);

		window.$('.ffc-rereg-open-form').trigger('click');
		await flush();

		const $panel = window.$('#ffc-rereg-form-panel');
		expect($panel.length).toBe(1);
		expect($panel.parent().is('body')).toBe(true);
	});

	it('writes the returned HTML into the panel on AJAX success', async () => {
		await setupBanner();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: true,
				data: {
					html: '<form id="ffc-rereg-form"><input name="x" /></form>',
				},
			} }));

		window.$('.ffc-rereg-open-form').trigger('click');
		await flush();

		expect(window.$('#ffc-rereg-form-panel #ffc-rereg-form').length).toBe(1);
	});

	it('shows the error message on response.success=false', async () => {
		await setupBanner();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: false, data: { message: 'Not found' } } }));

		window.$('.ffc-rereg-open-form').trigger('click');
		await flush();

		expect(window.$('#ffc-rereg-form-panel .ffc-error').text()).toContain('Not found');
	});

	it('falls back to the localised error string when response.data is empty', async () => {
		await setupBanner();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: false } }));

		window.$('.ffc-rereg-open-form').trigger('click');
		await flush();

		expect(window.$('#ffc-rereg-form-panel .ffc-error').text()).toContain('Error loading form.');
	});

	it('shows the error on network failure', async () => {
		await setupBanner();
		vi.spyOn(window.$, "post").mockImplementation(() => postChain({ fail: true }));

		window.$('.ffc-rereg-open-form').trigger('click');
		await flush();

		expect(window.$('#ffc-rereg-form-panel .ffc-error').text()).toContain('Error loading form.');
	});
});

// ----------------------------------------------------------------------
// Input masks
// ----------------------------------------------------------------------

describe('rereg input masks', () => {
	async function mountWithForm(formHtml) {
		document.body.innerHTML = `
			<div id="ffc-user-dashboard"></div>
			<button class="ffc-rereg-open-form" data-reregistration-id="9">Open</button>
		`;
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: { html: formHtml } } }));
		window.$('.ffc-rereg-open-form').trigger('click');
		await flush();
	}

	it('applies CPF mask XXX.XXX.XXX-XX', async () => {
		await mountWithForm('<form id="ffc-rereg-form"><input data-mask="cpf" name="cpf"></form>');
		const el = document.querySelector('[data-mask="cpf"]');
		el.value = '12345678909';
		window.$(el).trigger('input');
		await flush();
		expect(el.value).toBe('123.456.789-09');
	});

	it('applies phone mask (XX) XXXXX-XXXX', async () => {
		await mountWithForm('<form id="ffc-rereg-form"><input data-mask="phone" name="ph"></form>');
		const el = document.querySelector('[data-mask="phone"]');
		el.value = '11987654321';
		window.$(el).trigger('input');
		await flush();
		expect(el.value).toBe('(11) 98765-4321');
	});

	it('applies CEP mask XXXXX-XXX', async () => {
		await mountWithForm('<form id="ffc-rereg-form"><input data-mask="cep" name="cep"></form>');
		const el = document.querySelector('[data-mask="cep"]');
		el.value = '04567000';
		window.$(el).trigger('input');
		await flush();
		expect(el.value).toBe('04567-000');
	});

	it('applies RF mask XXX.XXX-X', async () => {
		await mountWithForm('<form id="ffc-rereg-form"><input data-mask="rf" name="rf"></form>');
		const el = document.querySelector('[data-mask="rf"]');
		el.value = '1234567';
		window.$(el).trigger('input');
		await flush();
		expect(el.value).toBe('123.456-7');
	});

	it('applies number-only mask', async () => {
		await mountWithForm('<form id="ffc-rereg-form"><input data-mask="number" name="n"></form>');
		const el = document.querySelector('[data-mask="number"]');
		el.value = 'abc123def';
		window.$(el).trigger('input');
		await flush();
		expect(el.value).toBe('123');
	});

	it('applies CIN mask XX.XXX.XXX-X', async () => {
		await mountWithForm('<form id="ffc-rereg-form"><input data-mask="cin" name="cin"></form>');
		const el = document.querySelector('[data-mask="cin"]');
		el.value = '123456789';
		window.$(el).trigger('input');
		await flush();
		expect(el.value).toBe('12.345.678-9');
	});
});

// ----------------------------------------------------------------------
// Blur validation
// ----------------------------------------------------------------------

describe('rereg blur validation', () => {
	async function mountForm(fieldHtml) {
		document.body.innerHTML = `
			<button class="ffc-rereg-open-form" data-reregistration-id="9"></button>
		`;
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: true,
				data: { html: `<form id="ffc-rereg-form">${fieldHtml}</form>` },
			} }));
		window.$('.ffc-rereg-open-form').trigger('click');
		await flush();
	}

	it('flags required-empty fields with .has-error', async () => {
		await mountForm(`
			<div class="ffc-rereg-field">
				<input name="x" required />
				<span class="ffc-field-error"></span>
			</div>
		`);
		const el = document.querySelector('[name="x"]');
		window.$(el).trigger('blur');
		await flush();

		expect(window.$(el).closest('.ffc-rereg-field').hasClass('has-error')).toBe(true);
		expect(window.$('.ffc-field-error').text()).toBe('Required.');
	});

	it('flags invalid CPF and clears on valid CPF', async () => {
		await mountForm(`
			<div class="ffc-rereg-field" data-format="cpf">
				<input name="cpf" />
				<span class="ffc-field-error"></span>
			</div>
		`);
		const el = document.querySelector('[name="cpf"]');

		window.$(el).val('123.456.789-00').trigger('blur');
		await flush();
		expect(window.$('.ffc-rereg-field').hasClass('has-error')).toBe(true);

		// Known-valid CPF.
		window.$(el).val('529.982.247-25').trigger('blur');
		await flush();
		expect(window.$('.ffc-rereg-field').hasClass('has-error')).toBe(false);
	});

	it('flags invalid email', async () => {
		await mountForm(`
			<div class="ffc-rereg-field" data-format="email">
				<input name="email" value="not-an-email" />
				<span class="ffc-field-error"></span>
			</div>
		`);
		window.$('[name="email"]').trigger('blur');
		await flush();
		expect(window.$('.ffc-field-error').text()).toBe('Invalid email.');
	});

	it('flags invalid phone', async () => {
		await mountForm(`
			<div class="ffc-rereg-field" data-format="phone">
				<input name="phone" value="123" />
				<span class="ffc-field-error"></span>
			</div>
		`);
		window.$('[name="phone"]').trigger('blur');
		await flush();
		expect(window.$('.ffc-field-error').text()).toBe('Invalid phone.');
	});

	it('flags custom_regex mismatch with the localised message', async () => {
		await mountForm(`
			<div class="ffc-rereg-field" data-format="custom_regex" data-regex="^[A-Z]{3}$" data-regex-msg="Three caps.">
				<input name="code" value="ab" />
				<span class="ffc-field-error"></span>
			</div>
		`);
		window.$('[name="code"]').trigger('blur');
		await flush();
		expect(window.$('.ffc-field-error').text()).toBe('Three caps.');
	});

	it('skips silently when the data-regex itself is invalid', async () => {
		await mountForm(`
			<div class="ffc-rereg-field" data-format="custom_regex" data-regex="[invalid">
				<input name="code" value="abc" />
				<span class="ffc-field-error"></span>
			</div>
		`);
		// Should not throw.
		expect(() => window.$('[name="code"]').trigger('blur')).not.toThrow();
		await flush();
		expect(window.$('.ffc-field-error').text()).toBe('');
	});
});

// ----------------------------------------------------------------------
// Divisão → Setor cascade
// ----------------------------------------------------------------------

describe('rereg divisão→setor cascade', () => {
	async function mountCascade(mapJson) {
		document.body.innerHTML = `
			<button class="ffc-rereg-open-form" data-reregistration-id="9"></button>
		`;
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: true,
				data: {
					html: `
						<form id="ffc-rereg-form">
							<select id="ffc_rereg_divisao">
								<option value="">--</option>
								<option value="A">A</option>
								<option value="B">B</option>
							</select>
							<select id="ffc_rereg_setor"></select>
							<script id="ffc-divisao-setor-map" type="application/json">${mapJson}</script>
						</form>
					`,
				},
			} }));
		window.$('.ffc-rereg-open-form').trigger('click');
		await flush();
	}

	it('populates setor when divisão changes', async () => {
		await mountCascade(JSON.stringify({ A: ['A1', 'A2'], B: ['B1'] }));
		window.$('#ffc_rereg_divisao').val('A').trigger('change');
		await flush();

		const opts = window.$('#ffc_rereg_setor option').map((_, el) => el.textContent).get();
		expect(opts).toEqual(['Select Sector', 'A1', 'A2']);
	});

	it('shows the placeholder when divisão has no children', async () => {
		await mountCascade(JSON.stringify({ A: ['A1'] }));
		window.$('#ffc_rereg_divisao').val('B').trigger('change');
		await flush();

		const opts = window.$('#ffc_rereg_setor option').map((_, el) => el.textContent).get();
		expect(opts).toEqual(['Select Division']);
	});

	it('bails silently on malformed JSON', async () => {
		// mountCascade is async; calling it with malformed JSON must not
		// reject (the IIFE catches and degrades silently).
		await mountCascade('{ invalid');
	});
});

// ----------------------------------------------------------------------
// Acúmulo de Cargos toggle
// ----------------------------------------------------------------------

describe('rereg acumulo toggle', () => {
	async function mountAcumulo() {
		document.body.innerHTML = `
			<button class="ffc-rereg-open-form" data-reregistration-id="9"></button>
		`;
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: true,
				data: {
					html: `
						<form id="ffc-rereg-form">
							<select id="ffc_rereg_acumulo">
								<option value="">--</option>
								<option value="Hold">Hold</option>
								<option value="None">None</option>
							</select>
							<div class="ffc-rereg-acumulo-fields" style="display:none">extra fields</div>
						</form>
					`,
				},
			} }));
		window.$('.ffc-rereg-open-form').trigger('click');
		await flush();
	}

	it('shows the extra fields when "Hold" is selected', async () => {
		await mountAcumulo();
		window.$('#ffc_rereg_acumulo').val('Hold').trigger('change');
		await flush();

		expect(window.$('.ffc-rereg-acumulo-fields').css('display')).not.toBe('none');
	});

	it('hides them when a non-hold value is selected', async () => {
		await mountAcumulo();
		window.$('#ffc_rereg_acumulo').val('Hold').trigger('change');
		await flush();
		window.$('#ffc_rereg_acumulo').val('None').trigger('change');
		await flush();

		expect(window.$('.ffc-rereg-acumulo-fields').css('display')).toBe('none');
	});
});

// ----------------------------------------------------------------------
// Dependent selects
// ----------------------------------------------------------------------

describe('rereg dependent selects', () => {
	async function mountDependent(groupsJson) {
		document.body.innerHTML = `
			<button class="ffc-rereg-open-form" data-reregistration-id="9"></button>
		`;
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: true,
				data: {
					html: `
						<form id="ffc-rereg-form">
							<input type="hidden" id="dep-hidden" name="fields[dep]" />
							<div class="ffc-dependent-select" data-target="dep-hidden">
								<select class="ffc-dep-parent">
									<option value="">--</option>
									<option value="A">A</option>
								</select>
								<select class="ffc-dep-child"></select>
								<script class="ffc-dep-groups" type="application/json">${groupsJson}</script>
							</div>
						</form>
					`,
				},
			} }));
		window.$('.ffc-rereg-open-form').trigger('click');
		await flush();
	}

	it('populates child options when parent changes and writes the hidden JSON', async () => {
		await mountDependent(JSON.stringify({ A: ['A1', 'A2'] }));
		window.$('.ffc-dep-parent').val('A').trigger('change');
		await flush();

		const opts = window.$('.ffc-dep-child option').map((_, el) => el.textContent).get();
		expect(opts).toEqual(['Select', 'A1', 'A2']);

		// Hidden JSON reflects parent + child (child still empty).
		const hidden = JSON.parse(window.$('#dep-hidden').val());
		expect(hidden).toEqual({ parent: 'A', child: '' });
	});

	it('writes parent + child to the hidden input on child change', async () => {
		await mountDependent(JSON.stringify({ A: ['A1', 'A2'] }));
		window.$('.ffc-dep-parent').val('A').trigger('change');
		await flush();
		window.$('.ffc-dep-child').val('A2').trigger('change');
		await flush();

		const hidden = JSON.parse(window.$('#dep-hidden').val());
		expect(hidden).toEqual({ parent: 'A', child: 'A2' });
	});

	it('bails silently when groups JSON is malformed', async () => {
		await mountDependent('{');
	});
});

// ----------------------------------------------------------------------
// Save draft
// ----------------------------------------------------------------------

describe('rereg save draft', () => {
	async function mountForm() {
		document.body.innerHTML = `
			<button class="ffc-rereg-open-form" data-reregistration-id="9"></button>
		`;
		vi.spyOn(window.$, 'post').mockImplementation((url, payload) => {
			if (payload.action === 'ffc_get_reregistration_form') {
				return postChain({ done: {
					success: true,
					data: {
						html: `
							<form id="ffc-rereg-form">
								<input type="hidden" name="reregistration_id" value="9" />
								<input name="fields[name]" value="Alice" />
								<input type="checkbox" name="fields[opt_in]" checked />
								<span class="ffc-rereg-status"></span>
								<button type="button" class="ffc-rereg-draft-btn">Save Draft</button>
							</form>
						`,
					},
				} });
			}
			return postChain({});
		});
		window.$('.ffc-rereg-open-form').trigger('click');
		await flush();
	}

	it('POSTs the field data and shows success', async () => {
		await mountForm();
		// Re-mock for the next $.post call (draft save).
		vi.restoreAllMocks();
		const chain = { fail: () => chain };
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true } }));

		window.$('.ffc-rereg-draft-btn').trigger('click');
		await flush();

		expect(postSpy).toHaveBeenCalled();
		const payload = postSpy.mock.calls[0][1];
		expect(payload.action).toBe('ffc_save_reregistration_draft');
		expect(payload.fields).toEqual({ name: 'Alice', opt_in: '1' });
		expect(window.$('.ffc-rereg-status').text()).toBe('Draft saved.');
	});

	it('shows the error message on response.success=false', async () => {
		await mountForm();
		vi.restoreAllMocks();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: false, data: { message: 'Disk full' } } }));

		window.$('.ffc-rereg-draft-btn').trigger('click');
		await flush();

		expect(window.$('.ffc-rereg-status').text()).toBe('Disk full');
		expect(window.$('.ffc-rereg-status').hasClass('ffc-status-err')).toBe(true);
	});

	it('shows the error on network failure', async () => {
		await mountForm();
		vi.restoreAllMocks();
		vi.spyOn(window.$, "post").mockImplementation(() => postChain({ fail: true }));

		window.$('.ffc-rereg-draft-btn').trigger('click');
		await flush();

		expect(window.$('.ffc-rereg-status').text()).toBe('Error saving.');
	});
});

// ----------------------------------------------------------------------
// Submit
// ----------------------------------------------------------------------

describe('rereg submit', () => {
	async function mountValidForm() {
		document.body.innerHTML = `
			<button class="ffc-rereg-open-form" data-reregistration-id="9"></button>
			<div class="ffc-rereg-banner" data-reregistration-id="9">Banner</div>
		`;
		vi.spyOn(window.$, 'post').mockImplementation((url, payload) => {
			if (payload.action === 'ffc_get_reregistration_form') {
				return postChain({ done: {
					success: true,
					data: {
						html: `
							<form id="ffc-rereg-form">
								<input type="hidden" name="reregistration_id" value="9" />
								<input name="fields[name]" value="Alice" />
								<span class="ffc-rereg-status"></span>
								<button type="submit" class="ffc-rereg-submit-btn">Send</button>
							</form>
						`,
					},
				} });
			}
			return postChain({});
		});
		window.$('.ffc-rereg-open-form').trigger('click');
		await flush();
	}

	async function mountFormWithRequired() {
		document.body.innerHTML = `
			<button class="ffc-rereg-open-form" data-reregistration-id="9"></button>
		`;
		vi.spyOn(window.$, 'post').mockImplementation((url, payload) => {
			if (payload.action === 'ffc_get_reregistration_form') {
				return postChain({ done: {
					success: true,
					data: {
						html: `
							<form id="ffc-rereg-form">
								<div class="ffc-rereg-field">
									<input name="fields[name]" required />
									<span class="ffc-field-error"></span>
								</div>
								<span class="ffc-rereg-status"></span>
								<button type="submit" class="ffc-rereg-submit-btn">Send</button>
							</form>
						`,
					},
				} });
			}
			return postChain({});
		});
		window.$('.ffc-rereg-open-form').trigger('click');
		await flush();
	}

	it('blocks submission when a required field is empty', async () => {
		// jQuery's `:visible` selector reads layout (offsetWidth/Height),
		// which jsdom doesn't implement, so every element is :hidden by
		// default and the required-field guard would walk an empty set.
		// Override the pseudo to "everything is visible" while this test
		// runs.
		const originalVisible = window.$.expr.pseudos.visible;
		window.$.expr.pseudos.visible = () => true;
		try {
			await mountFormWithRequired();
			vi.restoreAllMocks();
			const chain = { fail: () => chain };
			const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => chain);

			window.$('#ffc-rereg-form').trigger('submit');
			await flush();

			expect(postSpy).not.toHaveBeenCalled();
			expect(window.$('.ffc-rereg-status').text()).toBe('Please fix the errors below.');
			expect(window.$('.ffc-rereg-field').hasClass('has-error')).toBe(true);
		} finally {
			window.$.expr.pseudos.visible = originalVisible;
		}
	});

	it('replaces the form with the success notice and hides the banner', async () => {
		await mountValidForm();
		vi.restoreAllMocks();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: { message: 'Submitted OK!' } } }));

		window.$('#ffc-rereg-form').trigger('submit');
		await flush();

		expect(window.$('#ffc-rereg-form').length).toBe(0);
		expect(window.$('.ffc-dashboard-notice').text()).toBe('Submitted OK!');
		// Banner gets slideUp; with fx.off=true display is none.
		expect(window.$('.ffc-rereg-banner').css('display')).toBe('none');
	});

	it('renders server-side errors on response.success=false with errors map', async () => {
		await mountValidForm();
		// Inject a target field for the server error mapping.
		window.$('#ffc-rereg-form').prepend(`
			<div class="ffc-rereg-field">
				<input name="fields[email]" />
				<span class="ffc-field-error"></span>
			</div>
		`);
		vi.restoreAllMocks();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: false,
				data: {
					message: 'Validation failed.',
					errors: { 'fields[email]': 'Already taken.' },
				},
			} }));

		window.$('#ffc-rereg-form').trigger('submit');
		await flush();

		expect(window.$('.ffc-rereg-status').text()).toBe('Validation failed.');
		const $email = window.$('input[name="fields[email]"]');
		expect($email.closest('.ffc-rereg-field').hasClass('has-error')).toBe(true);
		expect($email.closest('.ffc-rereg-field').find('.ffc-field-error').text()).toBe('Already taken.');
	});

	it('shows the network-error message and re-enables the submit button', async () => {
		await mountValidForm();
		vi.restoreAllMocks();
		vi.spyOn(window.$, "post").mockImplementation(() => postChain({ fail: true }));

		window.$('#ffc-rereg-form').trigger('submit');
		await flush();

		expect(window.$('.ffc-rereg-status').text()).toBe('Error sending.');
		expect(window.$('.ffc-rereg-submit-btn').prop('disabled')).toBe(false);
	});
});

// ----------------------------------------------------------------------
// Cancel
// ----------------------------------------------------------------------

describe('rereg cancel', () => {
	it('slides up the panel and empties it', async () => {
		document.body.innerHTML = `
			<button class="ffc-rereg-open-form" data-reregistration-id="9"></button>
		`;
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: true,
				data: {
					html: '<form id="ffc-rereg-form"><button type="button" class="ffc-rereg-cancel-btn">Cancel</button></form>',
				},
			} }));
		window.$('.ffc-rereg-open-form').trigger('click');
		await flush();

		expect(window.$('#ffc-rereg-form-panel').css('display')).not.toBe('none');

		window.$('.ffc-rereg-cancel-btn').trigger('click');
		await flush();

		// slideUp + empty inside the callback. fx.off=true makes both
		// synchronous, so the panel ends hidden and empty.
		expect(window.$('#ffc-rereg-form-panel').css('display')).toBe('none');
		expect(window.$('#ffc-rereg-form-panel').children().length).toBe(0);
	});
});

// ----------------------------------------------------------------------
// Working hours add/remove inside the rereg form
// ----------------------------------------------------------------------

describe('rereg working hours', () => {
	async function mountWH() {
		document.body.innerHTML = `
			<button class="ffc-rereg-open-form" data-reregistration-id="9"></button>
		`;
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: true,
				data: {
					html: `
						<form id="ffc-rereg-form">
							<input type="hidden" id="wh-hidden" name="fields[wh]" />
							<div class="ffc-working-hours" data-target="wh-hidden">
								<table><tbody></tbody></table>
								<button type="button" class="ffc-wh-add">+</button>
							</div>
						</form>
					`,
				},
			} }));
		window.$('.ffc-rereg-open-form').trigger('click');
		await flush();
	}

	it('add row creates a Sunday row and syncs the hidden JSON', async () => {
		await mountWH();
		window.$('.ffc-wh-add').trigger('click');
		await flush();

		expect(window.$('.ffc-working-hours tbody tr').length).toBe(1);
		const hidden = JSON.parse(window.$('#wh-hidden').val());
		expect(hidden).toHaveLength(1);
		expect(hidden[0].day).toBe(0);
	});

	it('remove row drops the row and re-syncs', async () => {
		await mountWH();
		window.$('.ffc-wh-add').trigger('click');
		await flush();
		window.$('.ffc-wh-add').trigger('click');
		await flush();
		expect(window.$('.ffc-working-hours tbody tr').length).toBe(2);

		window.$('.ffc-wh-remove').first().trigger('click');
		await flush();

		expect(window.$('.ffc-working-hours tbody tr').length).toBe(1);
		const hidden = JSON.parse(window.$('#wh-hidden').val());
		expect(hidden).toHaveLength(1);
	});
});
