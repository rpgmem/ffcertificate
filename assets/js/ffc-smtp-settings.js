/**
 * SMTP Settings - Admin JavaScript
 * @since 3.1.0
 */

jQuery(document).ready(function($) {
    // Handle SMTP mode toggle
    $('input[name="ffc_settings[smtp_mode]"]').on('change', function() {
        if ($(this).val() === 'custom') {
            $('#smtp-options').removeClass('ffc-hidden').slideDown(200);
        } else {
            $('#smtp-options').slideUp(200, function() {
                $(this).addClass('ffc-hidden');
            });
        }
    });

    // Handle disable all emails toggle
    function toggleEmailOptions() {
        var disabled = $('#disable_all_emails').is(':checked');
        $('#smtp-mode-options input, #smtp-options input, #smtp-options select').prop('disabled', disabled);

        if (disabled) {
            $('#smtp-mode-options, #smtp-options').css('opacity', '0.5');
        } else {
            $('#smtp-mode-options, #smtp-options').css('opacity', '1');
        }
    }

    $('#disable_all_emails').on('change', toggleEmailOptions);

    // Run on page load
    toggleEmailOptions();
});
