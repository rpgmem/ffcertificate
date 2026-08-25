/**
 * Email texts hub — per-email selector with on-demand TinyMCE (#976 B2).
 *
 * The hub renders every plugin email's subject/body in one form, but shows only
 * the email chosen in `#ffc-email-texts-select` and initializes its rich editor
 * lazily via `wp.editor.initialize` (removing the previous one with
 * `wp.editor.remove`). This keeps a single TinyMCE instance alive at a time
 * instead of booting all ~15 on page load.
 *
 * The tab opens with NO email selected: the picker sits on a placeholder option
 * and every editor is hidden, so an operator with ~15 emails to choose from
 * lands on a deliberate empty state rather than an arbitrary first email.
 *
 * TIMING — why the boot waits for DOM ready. `wp.editor.initialize()` needs the
 * TinyMCE RUNTIME (`window.tinymce` + the `tinyMCEPreInit` inline block), which
 * `wp_enqueue_editor()` prints from a later footer hook than the one that
 * prints enqueued scripts. The script's `editor` dependency only guarantees the
 * API object, not the runtime — so calling `initialize()` at parse time found
 * `wp.editor` present and `window.tinymce` missing, and silently produced no
 * rich editor (the reported "HTML shows as plain text until you change the
 * selection"). Every footer script has executed by DOM ready, so that is the
 * first point where the call is guaranteed to work. Declaring `wp-tinymce` as a
 * hard dependency would also order it correctly, but a site that dequeues that
 * handle would then drop THIS script entirely and break the picker outright —
 * a worse failure than the one being fixed.
 *
 * Degrades gracefully: without the block-editor API the textareas stay plain and
 * editable, and the selector still shows/hides the right one. Selector-guarded,
 * so it no-ops on screens without the picker.
 */
/* global wp, tinymce */
( function ( $ ) {
	'use strict';

	$( function () {
		var $select = $('#ffc-email-texts-select');
		if (!$select.length) {
			return;
		}

		var $empty = $('.ffc-email-texts-empty');
		var settings = (window.ffcEmailTexts && window.ffcEmailTexts.editorSettings) || {};
		var current = null;

		// Probed on every call rather than captured once: the editor API can
		// finish loading after this script parses, and a stale `false` would
		// leave every editor plain for the rest of the page's life.
		function hasEditorApi() {
			return !!(window.wp && wp.editor && typeof wp.editor.initialize === 'function');
		}

		function initEditor(id) {
			if (hasEditorApi()) {
				wp.editor.initialize(id, settings);
			}
		}

		// Only ever called with the live `current` id, which `show()` has already
		// confirmed is non-empty.
		function removeEditor(id) {
			// Sync the rich editor's content back into the textarea before tearing it
			// down, so a switch never drops unsaved edits.
			if (window.tinymce) {
				var ed = tinymce.get(id);
				if (ed) {
					ed.save();
				}
			}
			if (hasEditorApi() && typeof wp.editor.remove === 'function') {
				wp.editor.remove(id);
			}
		}

		// `id` may be '' — the placeholder option — which tears the current
		// editor down and returns the hub to its empty state.
		function show(id) {
			if (id === current) {
				return;
			}
			if (current) {
				removeEditor(current);
				$('#' + current + '_item').hide();
			}
			current = null;

			if (!id) {
				$empty.show();
				return;
			}

			$empty.hide();
			$('#' + id + '_item').show();
			initEditor(id);
			current = id;
		}

		// Nothing is selected on a fresh load. A browser CAN restore the previous
		// selection on a soft reload, though, which would leave the picker naming
		// an email while every editor stayed hidden — so honour whatever value the
		// select actually carries.
		show(String($select.val() || ''));

		$select.on('change', function () {
			show(String($(this).val() || ''));
		});

		// Flush the live TinyMCE into its textarea before the form posts.
		$select.closest('form').on('submit', function () {
			if (window.tinymce) {
				tinymce.triggerSave();
			}
		});
	});
}( jQuery ) );
