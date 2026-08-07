/**
 * Certificate-template edit-screen behaviours (admin).
 *
 * Two jobs, both driven by `window.ffcCertTemplateAdmin` (wp_localize_script):
 *
 * 1. Shipped defaults are read-only except the visibility toggle — so lock the
 *    core title field (its Publish/Update box is already removed server-side in
 *    `add_edit_metabox()`).
 * 2. Autosave the sidebar "Show in Load list" toggle over AJAX, so the flag
 *    persists without a Publish/Update button. Applies to every template (a
 *    live switch), not only defaults.
 *
 * Enqueued in the admin footer (jQuery dep), so the DOM is already present —
 * runs immediately and binds directly to the toggle. Selector-guarded, so it
 * no-ops on screens without the toggle.
 */
(function ($) {
	'use strict';

	var cfg = window.ffcCertTemplateAdmin || {};

	// 1. Lock the title on shipped defaults (name is not editable).
	if (cfg.isDefault) {
		$('#title').prop('readonly', true).attr('aria-readonly', 'true');
	}

	// 2. Autosave the visibility toggle.
	var postId = parseInt(cfg.postId, 10) || 0;
	var $toggle = $('#ffc_template_visible');
	if (!postId || !$toggle.length) {
		return;
	}

	var $status = $('#ffc_template_visible_status');

	$toggle.on('change', function () {
		var visible = $(this).is(':checked') ? '1' : '0';

		$status.removeClass('ffc-saved ffc-error').text(cfg.savingText || '');

		$.post(cfg.ajaxUrl, {
			action: cfg.toggleAction,
			nonce: cfg.nonce,
			post_id: postId,
			visible: visible
		})
			.done(function (res) {
				if (res && res.success) {
					$status.removeClass('ffc-error').addClass('ffc-saved').text(cfg.savedText || '');
				} else {
					$status.removeClass('ffc-saved').addClass('ffc-error').text(cfg.errorText || '');
				}
			})
			.fail(function () {
				$status.removeClass('ffc-saved').addClass('ffc-error').text(cfg.errorText || '');
			});
	});
})(jQuery);
