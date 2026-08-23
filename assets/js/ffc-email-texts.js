/**
 * Email texts hub — per-email selector with on-demand TinyMCE (#976 B2).
 *
 * The hub renders every plugin email's subject/body in one form, but shows only
 * the email chosen in `#ffc-email-texts-select` and initializes its rich editor
 * lazily via `wp.editor.initialize` (removing the previous one with
 * `wp.editor.remove`). This keeps a single TinyMCE instance alive at a time
 * instead of booting all ~15 on page load.
 *
 * Degrades gracefully: without the block-editor API the textareas stay plain and
 * editable, and the selector still shows/hides the right one. Selector-guarded,
 * so it no-ops on screens without the picker.
 */
/* global wp, tinymce */
( function ( $ ) {
	'use strict';

	var $select = $('#ffc-email-texts-select');
	if (!$select.length) {
		return;
	}

	var settings = (window.ffcEmailTexts && window.ffcEmailTexts.editorSettings) || {};
	var hasEditorApi =
		window.wp && wp.editor && typeof wp.editor.initialize === 'function';
	var current = null;

	function initEditor(id) {
		if (hasEditorApi) {
			wp.editor.initialize(id, settings);
		}
	}

	function removeEditor(id) {
		if (!id) {
			return;
		}
		// Sync the rich editor's content back into the textarea before tearing it
		// down, so a switch never drops unsaved edits.
		if (window.tinymce) {
			var ed = tinymce.get(id);
			if (ed) {
				ed.save();
			}
		}
		if (hasEditorApi && typeof wp.editor.remove === 'function') {
			wp.editor.remove(id);
		}
	}

	function show(id) {
		if (!id || id === current) {
			return;
		}
		if (current) {
			removeEditor(current);
			$('#' + current + '_item').hide();
		}
		$('#' + id + '_item').show();
		initEditor(id);
		current = id;
	}

	// Boot the first item: prefer the server-rendered visible one, else the
	// first item in the DOM, else the select's current value.
	var first =
		$('.ffc-email-body-hub__item:visible').first().attr('data-editor') ||
		$('.ffc-email-body-hub__item').first().attr('data-editor') ||
		String($select.val() || '');
	if (first) {
		$select.val(first);
		show(first);
	}

	$select.on('change', function () {
		show(String($(this).val() || ''));
	});

	// Flush the live TinyMCE into its textarea before the form posts.
	$select.closest('form').on('submit', function () {
		if (window.tinymce) {
			tinymce.triggerSave();
		}
	});
}( jQuery ) );
