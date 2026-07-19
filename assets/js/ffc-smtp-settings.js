/**
 * SMTP Settings - Admin JavaScript
 * v4.0.0: "Enable email sending" is the master switch — when off, hide
 *         every email-related row and the SMTP configuration block
 *         instead of merely disabling them. Mode-radio handler stays
 *         but is suppressed while the master switch is off.
 */

jQuery(document).ready(function ($) {
	function emailsEnabled() {
		return $('#emails_enabled').is(':checked');
	}

	function modeIsCustom() {
		return $('input[name="ffc_settings[smtp_mode]"]:checked').val() === 'custom';
	}

	function applyVisibility() {
		var on = emailsEnabled();

		// Every per-context email toggle row + the Mode row.
		$('.ffc-email-option-row').toggle(on);

		// The SMTP server block + the Popular Providers card show only when
		// emails are on AND mode=custom.
		var showCustom = on && modeIsCustom();
		$('#smtp-options, #ffc-smtp-providers').each(function () {
			var $el = $(this);
			if (showCustom) {
				$el.removeClass('ffc-hidden').show();
			} else {
				$el.addClass('ffc-hidden').hide();
			}
		});
	}

	// Mode-radio toggle drives the smtp-options block + providers card too.
	$(document).on('change', 'input[name="ffc_settings[smtp_mode]"]', function () {
		if (!emailsEnabled()) {
			return; // master switch is off — nothing to reveal
		}
		var $targets = $('#smtp-options, #ffc-smtp-providers');
		if ($(this).val() === 'custom') {
			$targets.removeClass('ffc-hidden').slideDown(200);
		} else {
			$targets.slideUp(200, function () {
				$(this).addClass('ffc-hidden');
			});
		}
	});

	// Master switch.
	$(document).on('change', '#emails_enabled', applyVisibility);

	// Initial state on page load.
	applyVisibility();
});
