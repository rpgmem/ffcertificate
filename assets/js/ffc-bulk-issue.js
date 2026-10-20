/**
 * ffc-bulk-issue.js — the "Bulk issue" tab of the form editor (#1945, #1967).
 *
 * Posts the participants file, and the column mapping once it is shown, to
 * `ffc_bulk_issue_preview` and writes the step the server answers with. The
 * file is posted again on every step: the server keeps nothing between them.
 *
 * The start button posts the same file to `ffc_bulk_issue_start`, which stages
 * the job, then calls `ffc_bulk_issue_batch` until the server reports it done.
 * The progress line is the server's own text, so nothing here is translated.
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

	function buildRequest( panel, result, action, nonce ) {
		var body = new FormData();
		var file = panel.querySelector( 'input[type="file"]' );

		body.append( 'action', action );
		body.append( 'nonce', nonce );
		body.append( 'form_id', panel.getAttribute( 'data-form-id' ) );

		if ( file && file.files && file.files.length > 0 ) {
			body.append( 'ffc_bulk_file', file.files[ 0 ] );
		}

		// Columns and the issue-date choice the server showed on the previous step travel back with the file.
		result.querySelectorAll( 'select[name^="ffc_map["], [name^="ffc_issue_"]' ).forEach( function ( field ) {
			body.append( field.name, field.value );
		} );

		return body;
	}

	function post( body ) {
		return fetch( config.ajaxUrl, {
			method: 'POST',
			body: body,
			credentials: 'same-origin'
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function runBatches( jobId, result, button ) {
		var body = new FormData();

		body.append( 'action', 'ffc_bulk_issue_batch' );
		body.append( 'nonce', config.runNonce );
		body.append( 'job_id', jobId );

		post( body ).then( function ( json ) {
			var progress = result.querySelector( '.ffc-bulk-issue-progress' );

			if ( ! json || ! json.success ) {
				showError( result, json && json.data && json.data.message ? json.data.message : config.error );
				button.disabled = false;
				return;
			}

			if ( progress ) {
				progress.textContent = json.data.text;
			}

			if ( json.data.done ) {
				button.disabled = false;
				return;
			}

			runBatches( jobId, result, button );
		} ).catch( function () {
			showError( result, config.error );
			button.disabled = false;
		} );
	}

	function start( panel, result, button ) {
		button.disabled = true;

		post( buildRequest( panel, result, 'ffc_bulk_issue_start', config.runNonce ) ).then( function ( json ) {
			if ( json && json.success ) {
				runBatches( json.data.job_id, result, button );
				return;
			}

			showError( result, json && json.data && json.data.message ? json.data.message : config.error );
			button.disabled = false;
		} ).catch( function () {
			showError( result, config.error );
			button.disabled = false;
		} );
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

			post( buildRequest( panel, result, 'ffc_bulk_issue_preview', config.nonce ) )
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

		// The start button sits inside the result, which the preview rewrites.
		result.addEventListener( 'click', function ( event ) {
			var start_button = event.target.closest( '.ffc-bulk-issue-start' );

			if ( start_button && ! start_button.disabled ) {
				start( panel, result, start_button );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
