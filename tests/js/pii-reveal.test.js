// Coverage for assets/js/ffc-pii-reveal.js — the shared masked-PII reveal
// handler (#739 §3.3). Drives the input case (submission edit page), the text
// case (appointment detail page), the default-type fallback, and the error
// path. FFC.request is mocked directly; the handler only depends on it
// returning a Promise.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function flush() {
	return Promise.resolve().then(() => Promise.resolve());
}

beforeEach(() => {
	document.body.innerHTML = '';
	window.ffcPiiReveal = { reveal_error: 'Unable to reveal this value.' };
	if (!window.FFC) {
		loadScript('assets/js/ffc-core.js');
	}
});

afterEach(() => {
	vi.restoreAllMocks();
});

async function load() {
	loadScript('assets/js/ffc-pii-reveal.js');
	await new Promise((r) => setTimeout(r, 0));
}

describe('ffc-pii-reveal', () => {
	it('reveals into an input and removes the button (submission)', async () => {
		document.body.innerHTML = `<table><tr><td>
			<input data-ffc-pii-field="cpf" value="***" />
			<button class="ffc-reveal-pii" data-field="cpf" data-type="submission" data-submission-id="5" data-nonce="n">Reveal</button>
		</td></tr></table>`;
		window.FFC.request = vi.fn(() => Promise.resolve({ field: 'cpf', value: '123.456.789-01' }));

		await load();
		window.$('.ffc-reveal-pii').trigger('click');
		await flush();

		expect(window.FFC.request).toHaveBeenCalledWith(
			'ffc_reveal_pii',
			{ submission_id: 5, field: 'cpf', type: 'submission' },
			{ nonce: 'n' }
		);
		expect(window.$('[data-ffc-pii-field="cpf"]').val()).toBe('123.456.789-01');
		expect(window.$('.ffc-reveal-pii').length).toBe(0);
	});

	it('reveals into a text node for an appointment field', async () => {
		document.body.innerHTML = `<table><tr><td>
			<span class="ffc-pii-value" data-field="email">m***@x.com</span>
			<button class="ffc-reveal-pii" data-field="email" data-type="appointment" data-submission-id="7" data-nonce="n2">Reveal</button>
		</td></tr></table>`;
		window.FFC.request = vi.fn(() => Promise.resolve({ field: 'email', value: 'me@x.com' }));

		await load();
		window.$('.ffc-reveal-pii').trigger('click');
		await flush();

		expect(window.FFC.request).toHaveBeenCalledWith(
			'ffc_reveal_pii',
			{ submission_id: 7, field: 'email', type: 'appointment' },
			{ nonce: 'n2' }
		);
		expect(window.$('.ffc-pii-value[data-field="email"]').text()).toBe('me@x.com');
		expect(window.$('.ffc-reveal-pii').length).toBe(0);
	});

	it('defaults type to submission when data-type is absent', async () => {
		document.body.innerHTML = `<table><tr><td>
			<span class="ffc-pii-value" data-field="rf">1*.***.***</span>
			<button class="ffc-reveal-pii" data-field="rf" data-submission-id="3" data-nonce="n3">Reveal</button>
		</td></tr></table>`;
		window.FFC.request = vi.fn(() => Promise.resolve({ field: 'rf', value: '12.345.678' }));

		await load();
		window.$('.ffc-reveal-pii').trigger('click');
		await flush();

		expect(window.FFC.request).toHaveBeenCalledWith(
			'ffc_reveal_pii',
			{ submission_id: 3, field: 'rf', type: 'submission' },
			{ nonce: 'n3' }
		);
	});

	it('re-enables the button and alerts on failure', async () => {
		document.body.innerHTML = `<table><tr><td>
			<span class="ffc-pii-value" data-field="cpf">***</span>
			<button class="ffc-reveal-pii" data-field="cpf" data-type="submission" data-submission-id="5" data-nonce="n">Reveal</button>
		</td></tr></table>`;
		window.FFC.request = vi.fn(() => Promise.reject(new Error('boom')));
		window.alert = vi.fn();

		await load();
		window.$('.ffc-reveal-pii').trigger('click');
		await flush();

		expect(window.alert).toHaveBeenCalledWith('boom');
		expect(window.$('.ffc-reveal-pii').prop('disabled')).toBe(false);
		expect(window.$('.ffc-reveal-pii').length).toBe(1);
	});
});
