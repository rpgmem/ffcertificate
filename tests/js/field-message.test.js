// Tests for FFC.fieldMessage() / FFC.clearFieldMessage() in
// `assets/js/ffc-core.js` (#1844): the one shape every field message takes.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-core.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
	delete window.ffcCoreConfig;
});

const FFC = () => window.FFC;

describe('FFC.fieldMessage — error', () => {
	beforeEach(() => {
		document.body.innerHTML = '<div class="f"><input id="email" aria-describedby="email-hint"><p id="email-hint">Hint</p></div>';
	});

	it('puts the message right after the control, as an announced error', () => {
		const input = document.getElementById('email');
		const msg = FFC().fieldMessage(input, 'error', 'Invalid email.');

		expect(input.nextElementSibling).toBe(msg);
		expect(msg.id).toBe('ffc-msg-email');
		expect(msg.getAttribute('role')).toBe('alert');
		expect(msg.className).toBe('ffc-field-message is-error ffc-icon-error');
		expect(msg.textContent).toBe('Invalid email.');
	});

	it('sets the control state and aria-invalid', () => {
		const input = document.getElementById('email');
		FFC().fieldMessage(input, 'error', 'x');
		expect(input.classList.contains('is-invalid')).toBe(true);
		expect(input.getAttribute('aria-invalid')).toBe('true');
		expect(input.getAttribute('data-ffc-message-id')).toBe('ffc-msg-email');
	});

	it('APPENDS its id to aria-describedby, keeping the hint, and only once', () => {
		const input = document.getElementById('email');
		FFC().fieldMessage(input, 'error', 'one');
		FFC().fieldMessage(input, 'error', 'two');
		expect(input.getAttribute('aria-describedby')).toBe('email-hint ffc-msg-email');
		expect(document.querySelectorAll('.ffc-field-message').length).toBe(1);
		expect(document.querySelector('.ffc-field-message').textContent).toBe('two');
	});

	it('writes text, never markup', () => {
		const input = document.getElementById('email');
		const msg = FFC().fieldMessage(input, 'error', '<img src=x onerror="window.__pwned=1">');
		expect(msg.querySelector('img')).toBeNull();
		expect(msg.textContent).toBe('<img src=x onerror="window.__pwned=1">');
		expect(window.__pwned).toBeUndefined();
	});

	it('accepts a jQuery object or a selector', () => {
		FFC().fieldMessage('#email', 'error', 'x');
		expect(document.getElementById('email').classList.contains('is-invalid')).toBe(true);
		FFC().clearFieldMessage(window.$('#email'));
		expect(document.getElementById('email').classList.contains('is-invalid')).toBe(false);
	});

	it('does nothing for a selector that matches nothing', () => {
		expect(FFC().fieldMessage('#nope', 'error', 'x')).toBeNull();
		expect(document.querySelector('.ffc-field-message')).toBeNull();
	});
});

describe('FFC.fieldMessage — warning and valid', () => {
	beforeEach(() => {
		document.body.innerHTML = '<input id="n">';
	});

	it('a warning is polite, carries its own icon and no aria-invalid', () => {
		const input = document.getElementById('n');
		FFC().fieldMessage(input, 'error', 'bad');
		const msg = FFC().fieldMessage(input, 'warning', 'careful');
		expect(msg.getAttribute('role')).toBe('status');
		expect(msg.className).toBe('ffc-field-message is-warning ffc-icon-warning');
		expect(input.classList.contains('is-warning')).toBe(true);
		expect(input.classList.contains('is-invalid')).toBe(false);
		expect(input.hasAttribute('aria-invalid')).toBe(false);
	});

	it('valid removes the message and leaves only the success state', () => {
		const input = document.getElementById('n');
		FFC().fieldMessage(input, 'error', 'bad');
		expect(FFC().fieldMessage(input, 'valid')).toBeNull();
		expect(document.querySelector('.ffc-field-message')).toBeNull();
		expect(input.className).toBe('is-valid');
		expect(input.hasAttribute('aria-describedby')).toBe(false);
	});
});

describe('FFC.clearFieldMessage', () => {
	it('removes only its own id from aria-describedby', () => {
		document.body.innerHTML = '<input id="a" aria-describedby="hint other">';
		const input = document.getElementById('a');
		FFC().fieldMessage(input, 'error', 'x');
		expect(input.getAttribute('aria-describedby')).toBe('hint other ffc-msg-a');
		FFC().clearFieldMessage(input);
		expect(input.getAttribute('aria-describedby')).toBe('hint other');
		expect(input.hasAttribute('aria-invalid')).toBe(false);
		expect(input.hasAttribute('data-ffc-message-id')).toBe(false);
		expect(document.getElementById('ffc-msg-a')).toBeNull();
	});

	it('drops aria-describedby when the message was all it named', () => {
		document.body.innerHTML = '<input id="a">';
		const input = document.getElementById('a');
		FFC().fieldMessage(input, 'error', 'x');
		FFC().clearFieldMessage(input);
		expect(input.hasAttribute('aria-describedby')).toBe(false);
	});

	it('keeps a shared message until the last control leaves it', () => {
		document.body.innerHTML = '<input id="a"><input id="b">';
		FFC().fieldMessage(window.$('#a, #b'), 'error', 'x', { id: 'pair' });
		expect(document.getElementById('a').getAttribute('aria-describedby')).toBe('ffc-msg-pair');
		expect(document.getElementById('b').getAttribute('aria-describedby')).toBe('ffc-msg-pair');

		FFC().clearFieldMessage('#a');
		expect(document.getElementById('ffc-msg-pair')).not.toBeNull();
		FFC().clearFieldMessage('#b');
		expect(document.getElementById('ffc-msg-pair')).toBeNull();
	});

	it('is harmless on a control that never had a message', () => {
		document.body.innerHTML = '<input id="a" aria-describedby="hint">';
		FFC().clearFieldMessage('#a');
		expect(document.getElementById('a').getAttribute('aria-describedby')).toBe('hint');
	});
});

describe('FFC.fieldMessage — placement', () => {
	it('goes after the group of a checkbox, never between a box and its label', () => {
		document.body.innerHTML = '<fieldset><div class="ffc-radio-group"><label><input type="checkbox" name="g[]"> a</label></div><div class="foot"></div></fieldset>';
		const box = document.querySelector('input');
		const msg = FFC().fieldMessage(box, 'error', 'x');
		expect(msg.previousElementSibling.classList.contains('ffc-radio-group')).toBe(true);
		expect(msg.id).toBe('ffc-msg-g--');
	});

	it('goes after the label of a lone checkbox', () => {
		document.body.innerHTML = '<div><label class="l"><input type="checkbox" id="c"> I agree</label></div>';
		const msg = FFC().fieldMessage('#c', 'error', 'x');
		expect(msg.previousElementSibling.classList.contains('l')).toBe(true);
	});

	it('honours options.after and options.within', () => {
		document.body.innerHTML = '<div id="w"><input id="a"><span id="pair"></span></div><input id="b">';
		const after = FFC().fieldMessage('#a', 'error', 'x', { after: '#pair' });
		expect(document.getElementById('pair').nextElementSibling).toBe(after);
		const within = FFC().fieldMessage('#b', 'error', 'y', { within: document.getElementById('w') });
		expect(document.getElementById('w').lastElementChild).toBe(within);
	});

	it('reuses a message the server printed, with the same id, where it stands', () => {
		document.body.innerHTML = '<div><input id="x" class="is-invalid" aria-invalid="true" aria-describedby="ffc-msg-x" data-ffc-message-id="ffc-msg-x"><p>hint</p><span id="ffc-msg-x" class="ffc-field-message is-error ffc-icon-error">Old</span></div>';
		const msg = FFC().fieldMessage('#x', 'error', 'New');
		expect(document.querySelectorAll('.ffc-field-message').length).toBe(1);
		expect(msg.textContent).toBe('New');
		expect(msg.previousElementSibling.tagName).toBe('P');
		expect(msg.getAttribute('role')).toBe('alert');
		expect(document.getElementById('x').getAttribute('aria-describedby')).toBe('ffc-msg-x');
	});

	it('derives an id from the name, then from a counter, for id-less controls', () => {
		document.body.innerHTML = '<input name="fields[a b]"><input class="anon">';
		expect(FFC().fieldMessage('input[name]', 'error', 'x').id).toBe('ffc-msg-fields-a-b-');
		const anon = FFC().fieldMessage('.anon', 'error', 'y');
		expect(anon.id).toMatch(/^ffc-msg-field-\d+$/);
	});
});

describe('FFC.requiredMessage', () => {
	it('reads the localized string, with an English fallback', () => {
		expect(FFC().requiredMessage()).toBe('This field is required.');
		window.ffcCoreConfig = { strings: { fieldRequired: 'Required (localized).' } };
		expect(FFC().requiredMessage()).toBe('Required (localized).');
	});
});
