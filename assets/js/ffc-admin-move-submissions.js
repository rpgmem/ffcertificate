/* global jQuery, ffcMoveSubmissions */
/**
 * Move-to-form bulk action modal.
 *
 * Intercepts submission of the bulk-actions form on the FFC Submissions list
 * page when the chosen action is "move_to_form", presents a modal that asks
 * the operator to pick a target form, and re-submits with the chosen form id
 * as a hidden `move_to_form_id` field. Cancelling reverts the bulk action to
 * its placeholder so a stray click can't fire a previous selection.
 */
( function ( $ ) {
	'use strict';

	if ( typeof ffcMoveSubmissions === 'undefined' ) {
		return;
	}

	var TARGET_VALUE = 'move_to_form';
	var $body = $( document.body );
	var $modal = null;

	function buildModal() {
		var s = ffcMoveSubmissions.strings;
		var $backdrop = $( '<div class="ffc-move-modal-backdrop" aria-hidden="true"></div>' );
		var $dialog = $(
			'<div class="ffc-move-modal" role="dialog" aria-modal="true" aria-labelledby="ffc-move-modal-title"></div>'
		);

		$dialog.append(
			$( '<h2 id="ffc-move-modal-title" class="ffc-move-modal-title"></h2>' ).text( s.modalTitle )
		);
		$dialog.append( $( '<p class="ffc-move-modal-intro"></p>' ).text( s.modalIntro ) );

		var $field = $( '<p class="ffc-move-modal-field"></p>' );
		$field.append(
			$( '<label for="ffc-move-modal-select"></label>' ).text( s.targetLabel )
		);

		var $select = $( '<select id="ffc-move-modal-select"></select>' );
		$select.append(
			$( '<option value=""></option>' ).text( s.placeholder )
		);
		$.each( ffcMoveSubmissions.forms, function ( _, form ) {
			$select.append(
				$( '<option></option>' )
					.attr( 'value', form.id )
					.text( '#' + form.id + ' — ' + form.title )
			);
		} );
		$field.append( $select );
		$dialog.append( $field );

		var $error = $( '<p class="ffc-move-modal-error" role="alert" hidden></p>' );
		$dialog.append( $error );

		var $actions = $( '<p class="ffc-move-modal-actions"></p>' );
		$actions.append(
			$( '<button type="button" class="button ffc-move-modal-cancel"></button>' ).text( s.cancel )
		);
		$actions.append(
			$( '<button type="button" class="button button-primary ffc-move-modal-confirm"></button>' ).text(
				s.confirm
			)
		);
		$dialog.append( $actions );

		var $wrapper = $( '<div class="ffc-move-modal-wrapper"></div>' ).append( $backdrop ).append( $dialog );
		return {
			wrapper: $wrapper,
			select: $select,
			error: $error,
			confirm: $dialog.find( '.ffc-move-modal-confirm' ),
			cancel: $dialog.find( '.ffc-move-modal-cancel' ),
			backdrop: $backdrop,
		};
	}

	function showError( $error, message ) {
		if ( message ) {
			$error.text( message ).removeAttr( 'hidden' );
		} else {
			$error.text( '' ).attr( 'hidden', 'hidden' );
		}
	}

	function openModal( $form ) {
		if ( ! $modal ) {
			$modal = buildModal();
			$body.append( $modal.wrapper );

			$modal.cancel.on( 'click', closeModal );
			$modal.backdrop.on( 'click', closeModal );

			$modal.confirm.on( 'click', function () {
				var targetId = parseInt( $modal.select.val(), 10 );
				if ( ! targetId ) {
					showError( $modal.error, ffcMoveSubmissions.strings.noSelection );
					return;
				}

				var checked = $form.find( 'input[name="submission[]"]:checked' ).length;
				if ( ! checked ) {
					showError( $modal.error, ffcMoveSubmissions.strings.noRowsPicked );
					return;
				}

				$form.find( 'input[name="move_to_form_id"]' ).remove();
				$( '<input type="hidden" name="move_to_form_id" />' )
					.val( String( targetId ) )
					.appendTo( $form );

				closeModal();
				$form.off( 'submit.ffcMoveTarget' ).trigger( 'submit' );
			} );

			$( document ).on( 'keydown.ffcMoveSubmissions', function ( e ) {
				if ( $modal && $modal.wrapper.hasClass( 'is-open' ) && 'Escape' === e.key ) {
					closeModal();
				}
			} );
		}

		showError( $modal.error, '' );
		$modal.select.val( '' );
		$modal.wrapper.addClass( 'is-open' );
		$modal.select.trigger( 'focus' );
	}

	function closeModal() {
		if ( ! $modal ) {
			return;
		}
		$modal.wrapper.removeClass( 'is-open' );
	}

	$( function () {
		var $form = $( '#posts-filter, form#submissions-filter, form' )
			.filter( function () {
				return $( this ).find( 'select[name="action"], select[name="action2"]' ).length > 0
					&& $( this ).find( 'input[name="submission[]"]' ).length > 0;
			} )
			.first();

		if ( ! $form.length ) {
			return;
		}

		$form.on( 'submit.ffcMoveTarget', function ( event ) {
			var topAction = $form.find( 'select[name="action"]' ).val();
			var bottomAction = $form.find( 'select[name="action2"]' ).val();
			var resolved = topAction && '-1' !== topAction ? topAction : bottomAction;

			if ( resolved !== TARGET_VALUE ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();
			openModal( $form );
		} );
	} );
} )( jQuery );
