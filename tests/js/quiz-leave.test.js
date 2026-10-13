// Tests for `assets/js/ffc-quiz-leave.js` (#1731): leaving the window
// clears the scored answers, counts the exit and opens the dialog.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

function page(questions) {
	document.body.innerHTML = `
		<div class="ffc-shortcode">
			<form class="ffc-submission-form" id="ffc-form-element-7" data-ffc-quiz-leave='${JSON.stringify(questions)}'>
				<input type="hidden" name="form_id" value="7">
				<input type="hidden" name="ffc_quiz_focus_losses" value="0" class="ffc-quiz-focus-losses">
				<input type="text" name="name" id="name" value="Maria da Silva">
				<label><input type="radio" name="q1" value="A"> A</label>
				<label><input type="radio" name="q1" value="B"> B</label>
				<label><input type="checkbox" name="q2[]" value="X"> X</label>
				<select name="q3"><option value="">Select...</option><option value="Y">Y</option></select>
				<textarea name="q4"></textarea>
				<div class="ffc-modal ffc-quiz-leave-overlay" role="alertdialog" hidden>
					<button type="button" class="ffc-quiz-leave-restart">Start again</button>
				</div>
			</form>
		</div>`;
	return document.querySelector('form');
}

let visibility = 'visible';
function setVisibility(state) {
	visibility = state;
	document.dispatchEvent(new Event('visibilitychange'));
}

beforeAll(() => {
	Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => visibility });
	loadScript('assets/js/ffc-quiz-leave.js');
});

beforeEach(() => {
	visibility = 'visible';
	window.sessionStorage.clear();
});

function answer(form) {
	form.querySelector('[name="q1"][value="B"]').checked = true;
	form.querySelector('[name="q2[]"]').checked = true;
	form.querySelector('[name="q3"]').value = 'Y';
	form.querySelector('[name="q4"]').value = 'An answer';
	form.querySelector('[name="q4"]').dispatchEvent(new Event('input', { bubbles: true }));
}

describe('leaving a reset quiz', () => {
	it('clears the scored answers, keeps the rest, counts the exit and opens the dialog', () => {
		const form = page(['q1', 'q2', 'q3', 'q4']);
		window.ffcQuizLeave.watch(form);
		answer(form);

		setVisibility('hidden');
		expect(form.querySelector('.ffc-quiz-focus-losses').value).toBe('1');
		expect(form.querySelector('.ffc-quiz-leave-overlay').hidden).toBe(true);

		setVisibility('visible');
		expect(form.querySelector('[name="q1"][value="B"]').checked).toBe(false);
		expect(form.querySelector('[name="q2[]"]').checked).toBe(false);
		expect(form.querySelector('[name="q3"]').value).toBe('');
		expect(form.querySelector('[name="q4"]').value).toBe('');
		expect(form.querySelector('[name="name"]').value).toBe('Maria da Silva');

		const overlay = form.querySelector('.ffc-quiz-leave-overlay');
		expect(overlay.hidden).toBe(false);
		expect(document.activeElement).toBe(overlay.querySelector('.ffc-quiz-leave-restart'));

		overlay.querySelector('.ffc-quiz-leave-restart').click();
		expect(overlay.hidden).toBe(true);
	});

	it('does nothing before the participant starts', () => {
		const form = page(['q1']);
		window.ffcQuizLeave.watch(form);

		setVisibility('hidden');
		setVisibility('visible');

		expect(form.querySelector('.ffc-quiz-focus-losses').value).toBe('0');
		expect(form.querySelector('.ffc-quiz-leave-overlay').hidden).toBe(true);
	});

	it('keeps the count across a reload of the page', () => {
		const form = page(['q1']);
		window.ffcQuizLeave.watch(form);
		answer(form);
		setVisibility('hidden');
		expect(window.sessionStorage.getItem('ffc_quiz_leaves_7')).toBe('1');

		// A reload is a new document: a fresh form reads the stored count.
		const reloaded = page(['q1']);
		reloaded.querySelector('.ffc-quiz-focus-losses').value = '0';
		window.ffcQuizLeave.watch(reloaded);
		expect(reloaded.querySelector('.ffc-quiz-focus-losses').value).toBe('1');
	});

	it('stops watching a form that is gone and forgets its count', () => {
		const form = page(['q1']);
		window.ffcQuizLeave.watch(form);
		answer(form);
		setVisibility('hidden');
		document.body.innerHTML = '';

		setVisibility('visible');
		setVisibility('hidden');
		expect(window.sessionStorage.getItem('ffc_quiz_leaves_7')).toBeNull();
	});

	it('binds a form once', () => {
		const form = page(['q1']);
		expect(window.ffcQuizLeave.watch(form)).toBeTypeOf('function');
		expect(window.ffcQuizLeave.watch(form)).toBeNull();
	});

	it('reads a broken question list as none', () => {
		const form = page([]);
		form.setAttribute('data-ffc-quiz-leave', 'not json');
		window.ffcQuizLeave.watch(form);
		answer(form);
		setVisibility('hidden');
		setVisibility('visible');
		expect(form.querySelector('[name="q4"]').value).toBe('An answer');
		expect(form.querySelector('.ffc-quiz-leave-overlay').hidden).toBe(false);
	});

	it('init finds the forms that ask for it', () => {
		const form = page(['q1']);
		window.ffcQuizLeave.init();
		expect(form.getAttribute('data-ffc-quiz-leave-bound')).toBe('1');
	});
});
