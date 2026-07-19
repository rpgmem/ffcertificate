/**
 * FFC — sticky/collapsible Quick Navigation TOC on the Documentation
 * settings tab (page=ffc-settings&tab=documentation).
 *
 * Progressive enhancement: the markup already produces a sticky TOC via
 * `position: sticky` (see ffc-admin-settings.css). This script adds the
 * auto-collapse behaviour the sticky position alone can't drive — an
 * IntersectionObserver on a sentinel placed just above the TOC card
 * toggles `.is-collapsed` based on whether the user has scrolled past
 * the TOC's original position:
 *
 *   - sentinel intersects viewport → user is near the top → expanded.
 *   - sentinel out of viewport → user scrolled past → collapsed strip.
 *
 * A click on the collapsed strip (anywhere except an anchor) toggles
 * the manual override; an anchor click jumps to the section and
 * collapses the strip again so the next scroll event re-anchors the
 * IO-driven state. Falls back to the always-expanded sticky TOC when
 * IntersectionObserver isn't available (old browsers / `noscript`).
 *
 * @since 6.7.x
 */
( function () {
	'use strict';

	function init() {
		var toc      = document.querySelector( '.ffc-doc-toc' );
		var sentinel = document.querySelector( '.ffc-doc-toc-sentinel' );
		if ( ! toc || ! sentinel || ! ( 'IntersectionObserver' in window ) ) {
			return;
		}

		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					toc.classList.toggle( 'is-collapsed', ! entry.isIntersecting );
				} );
			},
			{ threshold: 0 }
		);
		observer.observe( sentinel );

		toc.addEventListener( 'click', function ( event ) {
			// Let the tree behave natively: expanding/collapsing a branch
			// (<summary>) or focusing the search field must NOT collapse the
			// whole Quick-Navigation card.
			if ( event.target.closest( 'summary' ) || event.target.closest( 'input' ) ) {
				return;
			}
			// Anchor click: navigate normally and ensure the strip
			// re-collapses (the IO will then resync on the next scroll).
			if ( event.target.closest( 'a' ) ) {
				toc.classList.add( 'is-collapsed' );
				return;
			}
			// A click on the card chrome (e.g. the title) toggles the strip —
			// used when the user wants to peek at the list mid-page.
			toc.classList.toggle( 'is-collapsed' );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
