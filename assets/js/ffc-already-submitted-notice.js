/**
 * FFC "Already submitted" friendly notice
 *
 * Best-effort UX hint that warns the user when this device has already
 * submitted the form they're looking at. Pure client-side: reads
 * `localStorage.ffc_submitted_forms` (a list of form IDs we've seen
 * succeed) and, on a match, renders a dismissible notice above the
 * form pointing the user at the reprint flow.
 *
 * Why a soft notice and not a hard block:
 *
 *   - The server is the authoritative gate. v6.3.10 already lets the
 *     reprint flow bypass the per-device limit; users who want their
 *     existing certificate back fill the form and the server returns
 *     the same submission.
 *   - Cookies clear, browsers change, devices are shared. A client-
 *     side block would punish users on legitimate edge cases (kiosk
 *     at an event, OS reinstall, incognito mode).
 *   - This module is a heads-up, not a firewall. If localStorage
 *     misses, the worst case is the user fills the form once "for
 *     nothing" and the server's reprint detector handles it.
 *
 * Storage shape:
 *   localStorage.ffc_submitted_forms = JSON list of integer form IDs,
 *                                      capped at 50 (LRU on overflow).
 *   sessionStorage.ffc_already_submitted_dismissed_{formId} = '1' once
 *                                      the user dismissed the notice
 *                                      this session.
 *
 * @package FreeFormCertificate
 * @since   6.3.10
 */
(function ( $ ) {
	'use strict';

	var STORAGE_KEY            = 'ffc_submitted_forms';
	var DISMISSED_KEY_PREFIX   = 'ffc_already_submitted_dismissed_';
	var MAX_REMEMBERED_FORMS   = 50;

	function readSubmittedForms() {
		try {
			var raw = window.localStorage.getItem( STORAGE_KEY );
			if ( ! raw ) {
				return [];
			}
			var parsed = JSON.parse( raw );
			if ( ! Array.isArray( parsed ) ) {
				return [];
			}
			// Coerce to integers; drop anything that isn't a positive int.
			return parsed
				.map( function ( v ) { return parseInt( v, 10 ); } )
				.filter( function ( v ) { return v > 0; } );
		} catch ( e ) {
			return [];
		}
	}

	function rememberSubmittedForm( formId ) {
		try {
			var current = readSubmittedForms();
			if ( current.indexOf( formId ) !== -1 ) {
				// Already remembered — bump to most-recent position.
				current = current.filter( function ( v ) { return v !== formId; } );
			}
			current.push( formId );
			if ( current.length > MAX_REMEMBERED_FORMS ) {
				current = current.slice( -MAX_REMEMBERED_FORMS );
			}
			window.localStorage.setItem( STORAGE_KEY, JSON.stringify( current ) );
		} catch ( e ) {
			// Private mode / quota exceeded / disabled storage. Silent.
		}
	}

	function isDismissedThisSession( formId ) {
		try {
			return window.sessionStorage.getItem( DISMISSED_KEY_PREFIX + formId ) === '1';
		} catch ( e ) {
			return false;
		}
	}

	function markDismissedThisSession( formId ) {
		try {
			window.sessionStorage.setItem( DISMISSED_KEY_PREFIX + formId, '1' );
		} catch ( e ) {}
	}

	function maybeRenderNotice( $form ) {
		var formIdStr = $form.find( '[name="form_id"]' ).val();
		var formId    = parseInt( formIdStr, 10 );
		if ( ! ( formId > 0 ) ) {
			return;
		}
		if ( readSubmittedForms().indexOf( formId ) === -1 ) {
			return;
		}
		if ( isDismissedThisSession( formId ) ) {
			return;
		}

		var cfg = window.ffc_already_submitted || {};
		var s   = cfg.strings || {};
		var title    = s.title    || 'You may have already submitted this form';
		var body     = s.body     || 'We detected a previous submission from this device. If you lost your certificate, just fill in your CPF and submit — the system recognises it and returns the existing certificate.';
		var dismissText = s.dismiss || 'Got it';

		var notice  = document.createElement( 'div' );
		notice.className = 'ffc-already-submitted-notice';
		notice.setAttribute( 'role', 'status' );

		var icon    = document.createElement( 'div' );
		icon.className = 'ffc-already-submitted-icon';
		icon.setAttribute( 'aria-hidden', 'true' );
		icon.textContent = 'ℹ';

		var content = document.createElement( 'div' );
		content.className = 'ffc-already-submitted-content';

		var h = document.createElement( 'h3' );
		h.textContent = title;

		var p = document.createElement( 'p' );
		p.textContent = body;

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'ffc-already-submitted-dismiss';
		btn.textContent = dismissText;
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			markDismissedThisSession( formId );
			if ( notice.parentNode ) {
				notice.parentNode.removeChild( notice );
			}
		} );

		content.appendChild( h );
		content.appendChild( p );
		content.appendChild( btn );
		notice.appendChild( icon );
		notice.appendChild( content );

		// Insert above the form wrapper if present, otherwise above the form itself.
		var anchor = $form.closest( '.ffc-form-wrapper' );
		if ( anchor.length === 0 ) {
			anchor = $form;
		}
		anchor[ 0 ].parentNode.insertBefore( notice, anchor[ 0 ] );
	}

	function bindSubmissionSuccessTracker( $form ) {
		// Listen for the ffc_submit_form AJAX response. We can't override
		// the existing handler in ffc-frontend.js without coupling tightly,
		// so we tap jQuery's global ajaxComplete and filter by action
		// string. Read-only; never mutates the response.
		$( document ).on( 'ajaxComplete', function ( _evt, jqXHR, settings ) {
			if ( ! settings || typeof settings.data !== 'string' ) {
				return;
			}
			if ( settings.data.indexOf( 'action=ffc_submit_form' ) === -1 ) {
				return;
			}
			try {
				var data = jqXHR.responseJSON;
				if ( ! data || ! data.success ) {
					return;
				}
				var formIdStr = $form.find( '[name="form_id"]' ).val();
				var formId    = parseInt( formIdStr, 10 );
				if ( formId > 0 ) {
					rememberSubmittedForm( formId );
				}
			} catch ( e ) {}
		} );
	}

	$( document ).ready( function () {
		var $form = $( 'form.ffc-submission-form' ).first();
		if ( $form.length === 0 ) {
			return;
		}
		maybeRenderNotice( $form );
		bindSubmissionSuccessTracker( $form );
	} );
}( jQuery ));
