// Keyboard movement in the Settings section list (#2338): arrows step
// between the tab links and wrap, Home and End jump to the ends, the group
// labels are never landed on and the "Go to" links are skipped.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-settings-nav.js');
});

beforeEach(() => {
	document.body.innerHTML = `
		<nav><ul class="ffc-settings-tabs__list">
			<li class="ffc-settings-tabs__group"><span class="ffc-settings-tabs__group-label">General</span></li>
			<li><a href="#a" id="a" class="ffc-settings-tabs__tab">A</a></li>
			<li><a href="#b" id="b" class="ffc-settings-tabs__tab is-active">B</a></li>
			<li class="ffc-settings-tabs__group"><span class="ffc-settings-tabs__group-label">Go to</span></li>
			<li><a href="#m" id="m" class="ffc-settings-tabs__tab ffc-settings-tabs__tab--module">M</a></li>
			<li class="ffc-settings-tabs__group"><span class="ffc-settings-tabs__group-label">System</span></li>
			<li><a href="#c" id="c" class="ffc-settings-tabs__tab">C</a></li>
		</ul></nav>`;
	window.FFCSettingsNav.init();
});

function press(id, key) {
	const el = document.getElementById(id);
	el.focus();
	const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
	el.dispatchEvent(event);
	return event;
}

describe('the Settings section list', () => {
	it('steps with the arrows, skipping the "Go to" link', () => {
		press('b', 'ArrowDown');
		expect(document.activeElement.id).toBe('c');
		press('c', 'ArrowUp');
		expect(document.activeElement.id).toBe('b');
		press('a', 'ArrowRight');
		expect(document.activeElement.id).toBe('b');
		press('b', 'ArrowLeft');
		expect(document.activeElement.id).toBe('a');
	});

	it('wraps at both ends', () => {
		press('c', 'ArrowDown');
		expect(document.activeElement.id).toBe('a');
		press('a', 'ArrowUp');
		expect(document.activeElement.id).toBe('c');
	});

	it('jumps with Home and End', () => {
		press('b', 'End');
		expect(document.activeElement.id).toBe('c');
		press('c', 'Home');
		expect(document.activeElement.id).toBe('a');
	});

	it('leaves other keys, and the "Go to" link, to the browser', () => {
		expect(press('a', 'Enter').defaultPrevented).toBe(false);
		expect(press('m', 'ArrowDown').defaultPrevented).toBe(false);
		expect(document.activeElement.id).toBe('m');
	});

	it('binds once', () => {
		window.FFCSettingsNav.init();
		press('a', 'ArrowDown');
		expect(document.activeElement.id).toBe('b');
	});
});
