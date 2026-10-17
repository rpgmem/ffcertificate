/**
 * FFC PII Reveal
 *
 * Shared "Reveal" click handler for masked personal data on the admin
 * screens (#739 §3.3, #1815, #1863). The plaintext is fetched on demand
 * through the audited `ffc_reveal_pii` endpoint so it never sits in the
 * initial page HTML.
 *
 * Each reveal button carries:
 *   - data-field           cpf | rf | email | ip | account_email | phone |
 *                          name | cf_<id>
 *   - data-type            the record domain (defaults to submission)
 *   - data-submission-id   the record ID
 *   - data-nonce           the ffc_reveal_pii_nonce
 *
 * On success the value replaces either an `[data-ffc-pii-field]` input (the
 * submission edit page) or a `.ffc-pii-value[data-field]` text node (the
 * lists and detail views), and the button is removed. The value is looked
 * up in the button's own `.ffc-pii-row` first, so two displays of one field
 * on a screen (the submission IP, under System Information and under the
 * consent) are revealed one at a time (#1760). An input marked
 * `data-ffc-pii-editable` (the submission email) is enabled once it holds the
 * clear value, so it is edited and posted only after a reveal (#1655).
 *
 * A list page also draws one "Reveal this page" button
 * (`.ffc-reveal-pii-page`, #1863). It collects every reveal button on the
 * screen and asks `ffc_reveal_pii_page` for all of them at once, which writes
 * one audit row per record domain naming the records revealed.
 *
 * Clicks are delegated from the document, so a row inserted after load
 * (a calendar's user-access row returned by its "add user" AJAX handler,
 * already masked by the server) reveals like the rest (#1824).
 */
jQuery(document).ready(function ($) {
    'use strict';

    if (!window.FFC || !window.FFC.request) {
        return;
    }

    var strings = window.ffcPiiReveal || {};

    /**
     * Put a revealed value where the button's masked value is, and drop
     * the button.
     *
     * @param {jQuery} $btn  The reveal button.
     * @param {string} value The clear value.
     */
    function applyValue($btn, value) {
        var field = $btn.data('field');
        var $row = $btn.closest('.ffc-pii-row');
        var $field = $btn.closest('td, dd, .ffc-edit-field');
        var $cell = $row.length ? $row : $field;
        var $input = $cell.find('[data-ffc-pii-field="' + field + '"]');
        var $text = $cell.find('.ffc-pii-value[data-field="' + field + '"]');

        if ($input.length) {
            $input.val(value);
            if ($input.is('[data-ffc-pii-editable]')) {
                $input.prop('disabled', false).trigger('focus');
                $field.find('.ffc-pii-reveal-hint').remove();
            }
        } else if ($text.length) {
            $text.text(value);
        }
        $btn.remove();
    }

    $(document).off('click.ffcPiiReveal').on('click.ffcPiiReveal', '.ffc-reveal-pii', function (e) {
        e.preventDefault();

        var $btn = $(this);

        $btn.prop('disabled', true);

        window.FFC.request(
            'ffc_reveal_pii',
            { submission_id: $btn.data('submission-id'), field: $btn.data('field'), type: $btn.data('type') || 'submission' },
            { nonce: $btn.data('nonce') }
        )
            .then(function (data) {
                if (data && typeof data.value !== 'undefined') {
                    applyValue($btn, data.value);
                } else {
                    $btn.prop('disabled', false);
                }
            })
            .catch(function (err) {
                $btn.prop('disabled', false);
                window.alert((err && err.message) || strings.reveal_error || 'Unable to reveal this value.');
            });
    });

    $(document).off('click.ffcPiiRevealPage').on('click.ffcPiiRevealPage', '.ffc-reveal-pii-page', function (e) {
        e.preventDefault();

        var $page = $(this);
        var $buttons = $('.ffc-reveal-pii').not(':disabled');
        var items = [];

        $buttons.each(function () {
            var $btn = $(this);
            items.push({
                type: $btn.data('type') || 'submission',
                id: parseInt($btn.data('submission-id'), 10) || 0,
                field: String($btn.data('field') || '')
            });
        });

        if (!items.length) {
            $page.remove();
            return;
        }

        $page.prop('disabled', true);

        window.FFC.request(
            'ffc_reveal_pii_page',
            { items: JSON.stringify(items) },
            { nonce: $page.data('nonce') }
        )
            .then(function (data) {
                var values = (data && data.values) || [];
                values.forEach(function (entry) {
                    $buttons.filter(function () {
                        var $btn = $(this);
                        return ($btn.data('type') || 'submission') === entry.type
                            && parseInt($btn.data('submission-id'), 10) === entry.id
                            && String($btn.data('field')) === entry.field;
                    }).each(function () {
                        applyValue($(this), entry.value);
                    });
                });
                if ($('.ffc-reveal-pii').length) {
                    $page.prop('disabled', false);
                } else {
                    $page.remove();
                }
            })
            .catch(function (err) {
                $page.prop('disabled', false);
                window.alert((err && err.message) || strings.reveal_error || 'Unable to reveal this value.');
            });
    });
});
