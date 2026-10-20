/**
 * ffc-bulk-issue.js — the "Bulk issue" tab of the form editor (#1945, #1967).
 *
 * Posts the participants file, and the column mapping once it is shown, to
 * `ffc_bulk_issue_preview` and writes the step the server answers with. The
 * file is posted again on every step: the server keeps nothing between them.
 */
( function () {
	'use strict';

	var config = window.ffcBulkIssue || {};

	function showError( result, message ) {
		var notice = document.createElement( 'div' );

		notice.className = 'notice notice-error inline';
		notice.innerHTML = '<p></p>';
		notice.querySelector( 'p' ).textContent = message;
		result.innerHTML = '';
		result.appendChild( notice );
	}

	function buildRequest( panel, result ) {
		var body = new FormData();
		var file = panel.querySelector( 'input[type="file"]' );

		body.append( 'action', 'ffc_bulk_issue_preview' );
		body.append( 'nonce', config.nonce );
		body.append( 'form_id', panel.getAttribute( 'data-form-id' ) );

		if ( file && file.files && file.files.length > 0 ) {
			body.append( 'ffc_bulk_file', file.files[ 0 ] );
		}

		// Columns the server showed on the previous step travel back with the file.
		result.querySelectorAll( 'select[name^="ffc_map["]' ).forEach( function ( select ) {
			body.append( select.name, select.value );
		} );

		return body;
	}

	function init() {
		var panel = document.getElementById( 'ffc-bulk-issue' );

		if ( ! panel || ! config.ajaxUrl ) {
			return;
		}

		var button = panel.querySelector( 'button' );
		var result = panel.querySelector( '.ffc-bulk-issue-result' );

		if ( ! button || ! result ) {
			return;
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;

			fetch( config.ajaxUrl, {
				method: 'POST',
				body: buildRequest( panel, result ),
				credentials: 'same-origin'
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					if ( json && json.success ) {
						result.innerHTML = json.data.html;
						button.textContent = json.data.label;
						return;
					}

					showError( result, json && json.data && json.data.message ? json.data.message : config.error );
				} )
				.catch( function () {
					showError( result, config.error );
				} )
				.then( function () {
					button.disabled = false;
				} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
