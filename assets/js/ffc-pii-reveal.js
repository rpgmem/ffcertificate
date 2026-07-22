/**
 * FFC PII Reveal
 *
 * Shared "Reveal" click handler for masked CPF / RF / email fields on the
 * certificate-submission and self-scheduling-appointment admin surfaces
 * (#739 §3.3). The plaintext is fetched on demand through the audited
 * `ffc_reveal_pii` endpoint so it never sits in the initial page HTML for the
 * `reveal` / `masked` tiers.
 *
 * Each reveal button carries:
 *   - data-field           cpf | rf | email
 *   - data-type            submission | appointment (defaults to submission)
 *   - data-submission-id   the record ID
 *   - data-nonce           the ffc_reveal_pii_nonce
 *
 * On success the value replaces either an `[data-ffc-pii-field]` input (the
 * submission edit page) or a `.ffc-pii-value[data-field]` text node (the
 * appointment detail page), and the button is removed.
 */
jQuery(document).ready(function ($) {
    'use strict';

    if (!window.FFC || !window.FFC.request) {
        return;
    }

    var strings = window.ffcPiiReveal || {};

    $('.ffc-reveal-pii').on('click', function (e) {
        e.preventDefault();

        var $btn = $(this);
        var field = $btn.data('field');
        var type = $btn.data('type') || 'submission';
        var $cell = $btn.closest('td');
        var $input = $cell.find('[data-ffc-pii-field="' + field + '"]');
        var $text = $cell.find('.ffc-pii-value[data-field="' + field + '"]');

        $btn.prop('disabled', true);

        window.FFC.request(
            'ffc_reveal_pii',
            { submission_id: $btn.data('submission-id'), field: field, type: type },
            { nonce: $btn.data('nonce') }
        )
            .then(function (data) {
                if (data && typeof data.value !== 'undefined') {
                    if ($input.length) {
                        $input.val(data.value);
                    } else if ($text.length) {
                        $text.text(data.value);
                    }
                    $btn.remove();
                } else {
                    $btn.prop('disabled', false);
                }
            })
            .catch(function (err) {
                $btn.prop('disabled', false);
                window.alert((err && err.message) || strings.reveal_error || 'Unable to reveal this value.');
            });
    });
});
