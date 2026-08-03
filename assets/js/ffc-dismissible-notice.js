/**
 * Generic persistent-dismiss handler for FFC admin notices (#839 S7a).
 *
 * Any notice carrying the `ffc-js-dismiss-notice` class plus `data-ffc-action`
 * and `data-ffc-nonce` attributes gets its dismissal POSTed to admin-ajax when
 * WordPress's built-in `.notice-dismiss` button is clicked, so the notice stays
 * hidden across page loads. No server-side interpolation — everything comes
 * from the DOM and the global `ajaxurl` WordPress defines on every admin page.
 */
(function () {
	var notices = document.querySelectorAll( '.ffc-js-dismiss-notice' );
	if ( ! notices.length ) {
		return;
	}

	Array.prototype.forEach.call( notices, function ( notice ) {
		notice.addEventListener( 'click', function ( e ) {
			if ( ! e.target.classList.contains( 'notice-dismiss' ) ) {
				return;
			}
			var action = notice.getAttribute( 'data-ffc-action' );
			var nonce = notice.getAttribute( 'data-ffc-nonce' );
			if ( ! action || ! nonce ) {
				return;
			}
			var body = 'action=' + encodeURIComponent( action ) + '&_ajax_nonce=' + encodeURIComponent( nonce );
			if ( typeof window.fetch === 'function' ) {
				window.fetch( ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body
				} );
			} else if ( window.XMLHttpRequest ) {
				var xhr = new XMLHttpRequest();
				xhr.open( 'POST', ajaxurl, true );
				xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
				xhr.send( body );
			}
		} );
	} );
}());
