// Tests for `assets/js/ffc-screenshots.js`: the screenshot viewer in the
// "View details" modal (#1716).
import { beforeEach, describe, expect, it } from 'vitest';
import { loadScript } from './helpers.js';

const modal = `
	<div id="section-screenshots"><ol>
		<li><a href="https://img/1.png"><img src="https://img/1.png" alt="The log"></a><p>The log</p></li>
		<li><a href="https://img/2.png"><img src="https://img/2.png" alt="Retention"></a><p>Retention</p></li>
		<li><a href="https://img/3.png"><img src="https://img/3.png" alt="Accounts"></a><p>Accounts</p></li>
		<li><p>A caption with no image</p></li>
	</ol></div>
	<div id="section-description"><a href="https://example.org/"><img src="x.png" alt=""></a></div>`;

function load( html, labels ) {
	document.body.innerHTML = html;
	document.documentElement.className = '';
	window.ffcScreenshots = labels;
	loadScript( 'assets/js/ffc-screenshots.js' );
}

function viewer() {
	const list = document.querySelectorAll( '.ffc-shots' );
	return list[ list.length - 1 ];
}

function click( el, init = {} ) {
	const event = new MouseEvent( 'click', { bubbles: true, cancelable: true, button: 0, ...init } );
	el.dispatchEvent( event );
	return event;
}

function key( name, init = {} ) {
	const event = new KeyboardEvent( 'keydown', { key: name, bubbles: true, cancelable: true, ...init } );
	( document.activeElement || viewer() ).dispatchEvent( event );
	return event;
}

function pointer( type, target, id, x, y ) {
	const event = new MouseEvent( type, { bubbles: true, clientX: x, clientY: y } );
	Object.defineProperty( event, 'pointerId', { value: id } );
	target.dispatchEvent( event );
}

function shown() {
	const root = viewer();
	return {
		open: !! root && ! root.hidden,
		src: root.querySelector( '.ffc-shots-img' ).getAttribute( 'src' ),
		caption: root.querySelector( '.ffc-shots-caption' ).textContent,
		count: root.querySelector( '.ffc-shots-count' ).textContent,
		transform: root.querySelector( '.ffc-shots-img' ).style.transform,
	};
}

function links() {
	return document.querySelectorAll( '#section-screenshots a' );
}

describe( 'opening', () => {
	beforeEach( () => load( modal ) );

	it( 'opens the clicked screenshot in the overlay instead of following the link', () => {
		const event = click( links()[ 1 ] );

		expect( event.defaultPrevented ).toBe( true );
		expect( shown() ).toMatchObject( { open: true, src: 'https://img/2.png', caption: 'Retention', count: '2 of 3' } );
		expect( document.documentElement.classList.contains( 'ffc-shots-open' ) ).toBe( true );
		expect( document.activeElement.classList.contains( 'ffc-shots-close' ) ).toBe( true );
	} );

	it( 'leaves a modifier click alone, so a new tab is still one ctrl-click away', () => {
		const event = click( links()[ 0 ], { ctrlKey: true } );

		expect( event.defaultPrevented ).toBe( false );
		expect( viewer() ).toBeUndefined();
	} );

	it( 'ignores links outside the Screenshots tab', () => {
		const event = click( document.querySelector( '#section-description a' ) );

		expect( event.defaultPrevented ).toBe( false );
	} );

	it( 'uses the localized labels', () => {
		load( modal, { close: 'Fechar', counter: '%1$s de %2$s' } );
		click( links()[ 0 ] );

		expect( viewer().querySelector( '.ffc-shots-close' ).getAttribute( 'aria-label' ) ).toBe( 'Fechar' );
		expect( shown().count ).toBe( '1 de 3' );
	} );
} );

describe( 'moving between screenshots', () => {
	beforeEach( () => {
		load( modal );
		click( links()[ 0 ] );
	} );

	it( 'steps with the buttons and wraps at both ends', () => {
		viewer().querySelector( '.ffc-shots-previous' ).click();
		expect( shown().src ).toBe( 'https://img/3.png' );

		viewer().querySelector( '.ffc-shots-next' ).click();
		expect( shown().src ).toBe( 'https://img/1.png' );
	} );

	it( 'steps with the arrow keys', () => {
		key( 'ArrowRight' );
		expect( shown().count ).toBe( '2 of 3' );

		key( 'ArrowLeft' );
		expect( shown().count ).toBe( '1 of 3' );
	} );

	it( 'steps with a horizontal swipe at the fitted size', () => {
		const stage = viewer().querySelector( '.ffc-shots-stage' );
		pointer( 'pointerdown', stage, 1, 300, 100 );
		pointer( 'pointermove', stage, 1, 200, 100 );
		pointer( 'pointerup', stage, 1, 200, 100 );

		expect( shown().src ).toBe( 'https://img/2.png' );
	} );

	it( 'resets the zoom when it moves', () => {
		key( '+' );
		key( 'ArrowRight' );

		expect( shown().transform ).toContain( 'scale(1)' );
	} );
} );

describe( 'zoom', () => {
	beforeEach( () => {
		load( modal );
		click( links()[ 0 ] );
	} );

	it( 'zooms with the buttons and keys, within its bounds', () => {
		const out = viewer().querySelector( '.ffc-shots-zoom-out' );
		expect( out.disabled ).toBe( true );

		viewer().querySelector( '.ffc-shots-zoom-in' ).click();
		expect( shown().transform ).toContain( 'scale(1.25)' );
		expect( viewer().classList.contains( 'is-zoomed' ) ).toBe( true );

		for ( let i = 0; i < 20; i++ ) {
			key( '+' );
		}
		expect( shown().transform ).toContain( 'scale(4)' );
		expect( viewer().querySelector( '.ffc-shots-zoom-in' ).disabled ).toBe( true );

		key( '0' );
		expect( shown().transform ).toContain( 'scale(1)' );
	} );

	it( 'toggles with a plain click on the image', () => {
		const img = viewer().querySelector( '.ffc-shots-img' );
		pointer( 'pointerdown', img, 1, 50, 50 );
		pointer( 'pointerup', img, 1, 50, 50 );
		expect( shown().transform ).toContain( 'scale(2)' );

		pointer( 'pointerdown', img, 1, 50, 50 );
		pointer( 'pointerup', img, 1, 50, 50 );
		expect( shown().transform ).toContain( 'scale(1)' );
	} );

	it( 'zooms with the wheel', () => {
		const stage = viewer().querySelector( '.ffc-shots-stage' );
		stage.dispatchEvent( new WheelEvent( 'wheel', { deltaY: -100, cancelable: true } ) );
		expect( shown().transform ).toContain( 'scale(1.25)' );

		stage.dispatchEvent( new WheelEvent( 'wheel', { deltaY: 100, cancelable: true } ) );
		expect( shown().transform ).toContain( 'scale(1)' );
	} );

	it( 'drags an enlarged image instead of changing screenshot', () => {
		key( '+' );
		const stage = viewer().querySelector( '.ffc-shots-stage' );
		pointer( 'pointerdown', stage, 1, 300, 100 );
		pointer( 'pointermove', stage, 1, 200, 130 );
		pointer( 'pointerup', stage, 1, 200, 130 );

		expect( shown().src ).toBe( 'https://img/1.png' );
		expect( shown().transform ).toContain( 'translate(-100px, 30px)' );
	} );

	it( 'pinches with two pointers', () => {
		const stage = viewer().querySelector( '.ffc-shots-stage' );
		pointer( 'pointerdown', stage, 1, 100, 100 );
		pointer( 'pointerdown', stage, 2, 200, 100 );
		pointer( 'pointermove', stage, 2, 300, 100 );

		expect( shown().transform ).toContain( 'scale(2)' );

		pointer( 'pointerup', stage, 2, 300, 100 );
		pointer( 'pointerup', stage, 1, 100, 100 );
		expect( shown().src ).toBe( 'https://img/1.png' );
	} );
} );

describe( 'closing', () => {
	beforeEach( () => {
		load( modal );
		click( links()[ 2 ] );
	} );

	it( 'closes on Escape and returns focus to the thumbnail', () => {
		key( 'Escape' );

		expect( viewer().hidden ).toBe( true );
		expect( document.activeElement ).toBe( links()[ 2 ] );
		expect( document.documentElement.classList.contains( 'ffc-shots-open' ) ).toBe( false );
	} );

	it( 'closes with the button and on the backdrop, not on the image', () => {
		click( viewer().querySelector( '.ffc-shots-img' ) );
		expect( viewer().hidden ).toBe( false );

		click( viewer() );
		expect( viewer().hidden ).toBe( true );

		click( links()[ 0 ] );
		viewer().querySelector( '.ffc-shots-close' ).click();
		expect( viewer().hidden ).toBe( true );
	} );

	it( 'reuses one overlay across openings', () => {
		key( 'Escape' );
		click( links()[ 0 ] );

		expect( document.querySelectorAll( '.ffc-shots' ) ).toHaveLength( 1 );
	} );
} );

describe( 'focus', () => {
	beforeEach( () => {
		load( modal );
		click( links()[ 0 ] );
	} );

	it( 'keeps Tab inside the dialog', () => {
		const buttons = Array.from( viewer().querySelectorAll( 'button' ) ).filter( ( b ) => ! b.hidden && ! b.disabled );
		const first = buttons[ 0 ];
		const last = buttons[ buttons.length - 1 ];

		last.focus();
		expect( key( 'Tab' ).defaultPrevented ).toBe( true );
		expect( document.activeElement ).toBe( first );

		first.focus();
		expect( key( 'Tab', { shiftKey: true } ).defaultPrevented ).toBe( true );
		expect( document.activeElement ).toBe( last );
	} );

	it( 'ignores keys it does not handle', () => {
		expect( key( 'a' ).defaultPrevented ).toBe( false );
	} );
} );

describe( 'a single screenshot', () => {
	it( 'hides the arrows', () => {
		load( '<div id="section-screenshots"><a href="https://img/1.png"><img alt="Only"></a></div>' );
		click( links()[ 0 ] );

		expect( viewer().querySelector( '.ffc-shots-previous' ).hidden ).toBe( true );
		expect( viewer().querySelector( '.ffc-shots-next' ).hidden ).toBe( true );
	} );
} );
