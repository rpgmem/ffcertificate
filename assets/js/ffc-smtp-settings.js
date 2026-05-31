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

		// The SMTP server block shows only when emails are on AND mode=custom.
		var $opts = $('#smtp-options');
		if (on && modeIsCustom()) {
			$opts.removeClass('ffc-hidden').show();
		} else {
			$opts.addClass('ffc-hidden').hide();
		}
	}

	// Mode-radio toggle drives the smtp-options block too.
	$(document).on('change', 'input[name="ffc_settings[smtp_mode]"]', function () {
		if (!emailsEnabled()) {
			return; // master switch is off — nothing to reveal
		}
		if ($(this).val() === 'custom') {
			$('#smtp-options').removeClass('ffc-hidden').slideDown(200);
		} else {
			$('#smtp-options').slideUp(200, function () {
				$(this).addClass('ffc-hidden');
			});
		}
	});

	// Master switch.
	$(document).on('change', '#emails_enabled', applyVisibility);

	// Initial state on page load.
	applyVisibility();
});
