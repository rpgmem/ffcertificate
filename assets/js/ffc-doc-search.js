/**
 * FFC — Documentation tab in-page search / filter.
 *
 * Filters the section cards and the Quick-Navigation links by the text typed
 * into #ffc-doc-search. Progressive enhancement, no dependencies: section
 * cards are the ones carrying an anchored <h3 id> (so the intro card and the
 * Quick-Navigation card itself are never hidden). An empty query restores all.
 *
 * @since 6.14.0
 */
( function () {
	'use strict';

	function init() {
		var input = document.getElementById( 'ffc-doc-search' );
		if ( ! input ) {
			return;
		}

		var cards = Array.prototype.filter.call(
			document.querySelectorAll( '.card' ),
			function ( card ) {
				return !! card.querySelector( 'h3[id]' );
			}
		);
		var navItems = document.querySelectorAll(
			'.ffc-doc-toc-list li:not(.ffc-doc-toc-section)'
		);

		function apply() {
			var q = input.value.trim().toLowerCase();

			cards.forEach( function ( card ) {
				var hit = '' === q || card.textContent.toLowerCase().indexOf( q ) !== -1;
				card.style.display = hit ? '' : 'none';
			} );

			Array.prototype.forEach.call( navItems, function ( li ) {
				var hit = '' === q || li.textContent.toLowerCase().indexOf( q ) !== -1;
				li.style.display = hit ? '' : 'none';
			} );
		}

		input.addEventListener( 'input', apply );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
