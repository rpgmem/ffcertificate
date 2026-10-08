/**
 * FFC — collapsible sections (`.ffc-section`, #1614).
 *
 * Two behaviours for the `<details>` sections `AdminUI::section_open()`
 * prints:
 *
 * - A chip bound to a toggle (`data-ffc-section-master="<id>"`) follows it
 *   live, so a closed section still says whether its feature is on.
 * - A control the browser rejects on submit opens every section around it.
 *   A closed `<details>` cannot be focused into, so without this the browser
 *   refuses the submit and shows the operator nothing.
 *
 * @since 6.35.0
 */
( function () {
	'use strict';

	/**
	 * Point a chip at its master's current state.
	 *
	 * @param {HTMLElement} chip
	 * @param {HTMLInputElement} master
	 */
	function paint( chip, master ) {
		var on = !! master.checked;
		chip.classList.toggle( 'is-on', on );
		chip.classList.toggle( 'is-off', ! on );
		chip.textContent = on ? ( chip.getAttribute( 'data-on' ) || '' ) : ( chip.getAttribute( 'data-off' ) || '' );
	}

	/**
	 * Wire every chip under the root to its master toggle.
	 *
	 * @param {ParentNode} root
	 */
	function bindChips( root ) {
		root.querySelectorAll( '[data-ffc-section-master]' ).forEach( function ( chip ) {
			if ( chip.getAttribute( 'data-ffc-section-bound' ) ) {
				return;
			}
			var master = document.getElementById( chip.getAttribute( 'data-ffc-section-master' ) );
			if ( ! master ) {
				return;
			}
			chip.setAttribute( 'data-ffc-section-bound', '1' );
			master.addEventListener( 'change', function () {
				paint( chip, master );
			} );
			paint( chip, master );
		} );
	}

	/**
	 * Open every section that contains the element.
	 *
	 * @param {Element} el
	 */
	function reveal( el ) {
		var node = el.closest ? el.closest( 'details.ffc-section' ) : null;
		while ( node ) {
			node.open = true;
			node = node.parentElement ? node.parentElement.closest( 'details.ffc-section' ) : null;
		}
	}

	function init( root ) {
		bindChips( root || document );
	}

	// Capture phase: `invalid` does not bubble.
	document.addEventListener( 'invalid', function ( e ) {
		if ( e.target && e.target.nodeType === 1 ) {
			reveal( e.target );
		}
	}, true );

	window.FFC = window.FFC || {};
	window.FFC.AdminSections = { init: init, reveal: reveal };

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			init( document );
		} );
	} else {
		init( document );
	}
}() );
