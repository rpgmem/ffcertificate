/**
 * Dismiss handler for the v6.3.2 device-threshold upgrade admin notice.
 *
 * Reads the AJAX action + nonce from the notice's data-attributes and posts
 * the dismissal when WordPress's built-in `.notice-dismiss` button is clicked.
 * No server-side interpolation — everything comes from the DOM and the global
 * `ajaxurl` that WordPress defines on every admin page.
 */
(function () {
	var notice = document.querySelector( '.ffc-device-threshold-notice' );
	if ( ! notice ) {
		return;
	}
	notice.addEventListener( 'click', function ( e ) {
		if ( ! e.target.classList.contains( 'notice-dismiss' ) ) {
			return;
		}
		var action = notice.getAttribute( 'data-ffc-action' );
		var nonce = notice.getAttribute( 'data-ffc-nonce' );
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
}());
