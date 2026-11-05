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
 *
 * The button is gone once its value is shown, so the focus it held moves
 * to the value, and a polite region created at load says what happened. A
 * failure is a status line beside the button, never a browser alert (#2344).
 */
jQuery(document).ready(function ($) {
    'use strict';

    if (!window.FFC || !window.FFC.request) {
        return;
    }

    var strings = window.ffcPiiReveal || {};

    // Created at load and filled later: a region inserted already holding
    // its text is not announced by every screen reader.
    var live = document.createElement('div');
    live.className = 'screen-reader-text ffc-pii-reveal-live';
    live.setAttribute('role', 'status');
    live.setAttribute('aria-live', 'polite');
    document.body.appendChild(live);

    /**
     * Say a sentence through the polite region.
     *
     * @param {string} text The sentence.
     */
    function announce(text) {
        live.textContent = '';
        window.setTimeout(function () {
            live.textContent = text;
        }, 50);
    }

    /**
     * Show a failure beside a button, replacing an earlier one.
     *
     * @param {jQuery} $btn The button that failed.
     * @param {Error}  err  The failure.
     */
    function showError($btn, err) {
        var $line = $btn.next('.ffc-pii-reveal-error');
        if (!$line.length) {
            $line = $('<span class="ffc-pii-reveal-error" role="alert"></span>');
            $btn.after($line);
        }
        window.FFC.status($line, 'error', (err && err.message) || strings.reveal_error || 'Unable to reveal this value.');
    }

    /**
     * Drop a failure line left beside a button.
     *
     * @param {jQuery} $btn The button.
     */
    function clearError($btn) {
        $btn.next('.ffc-pii-reveal-error').remove();
    }

    /**
     * Put a revealed value where the button's masked value is, and drop
     * the button.
     *
     * @param {jQuery} $btn  The reveal button.
     * @param {string} value The clear value.
     * @return {jQuery} What now shows the value, to take the focus.
     */
    function applyValue($btn, value) {
        var field = $btn.data('field');
        var $row = $btn.closest('.ffc-pii-row');
        var $field = $btn.closest('td, dd, .ffc-edit-field, .ffc-linked-user-display');
        var $cell = $row.length ? $row : $field;
        var $input = $cell.find('[data-ffc-pii-field="' + field + '"]');
        var $text = $cell.find('.ffc-pii-value[data-field="' + field + '"]');
        var $target = $();

        if ($input.length) {
            $input.val(value);
            if ($input.is('[data-ffc-pii-editable]')) {
                $input.prop('disabled', false);
                $field.find('.ffc-pii-reveal-hint').remove();
            }
            $target = $input.first();
        } else if ($text.length) {
            $text.text(value);
            $target = $text.first();
        }
        clearError($btn);
        $btn.remove();
        return $target;
    }

    /**
     * Give the focus to a revealed value. A disabled input cannot hold it,
     * and a text node takes it through `tabindex="-1"`.
     *
     * @param {jQuery} $target What shows the value.
     */
    function focusValue($target) {
        if (!$target.length || $target.is(':disabled')) {
            return;
        }
        if (!$target.is('input, textarea, select, button, a[href]')) {
            $target.attr('tabindex', '-1');
        }
        $target.trigger('focus');
    }

    $(document).off('click.ffcPiiReveal').on('click.ffcPiiReveal', '.ffc-reveal-pii', function (e) {
        e.preventDefault();

        var $btn = $(this);
        var hadFocus = document.activeElement === this;

        $btn.prop('disabled', true);

        window.FFC.request(
            'ffc_reveal_pii',
            { submission_id: $btn.data('submission-id'), field: $btn.data('field'), type: $btn.data('type') || 'submission' },
            { nonce: $btn.data('nonce') }
        )
            .then(function (data) {
                if (data && typeof data.value !== 'undefined') {
                    var $target = applyValue($btn, data.value);
                    // An editable input takes the focus whatever held it:
                    // revealing it is how the person starts to edit it.
                    if (hadFocus || $target.is('[data-ffc-pii-editable]')) {
                        focusValue($target);
                    }
                    announce(strings.revealed || 'Value revealed.');
                } else {
                    $btn.prop('disabled', false);
                }
            })
            .catch(function (err) {
                $btn.prop('disabled', false);
                showError($btn, err);
                if (hadFocus) {
                    $btn.trigger('focus');
                }
            });
    });

    $(document).off('click.ffcPiiRevealPage').on('click.ffcPiiRevealPage', '.ffc-reveal-pii-page', function (e) {
        e.preventDefault();

        var $page = $(this);
        var hadFocus = document.activeElement === this;
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
                var $first = $();
                var shown = 0;
                values.forEach(function (entry) {
                    $buttons.filter(function () {
                        var $btn = $(this);
                        return ($btn.data('type') || 'submission') === entry.type
                            && parseInt($btn.data('submission-id'), 10) === entry.id
                            && String($btn.data('field')) === entry.field;
                    }).each(function () {
                        var $target = applyValue($(this), entry.value);
                        shown += 1;
                        if (!$first.length) {
                            $first = $target;
                        }
                    });
                });
                clearError($page);
                if ($('.ffc-reveal-pii').length) {
                    $page.prop('disabled', false);
                    if (hadFocus) {
                        $page.trigger('focus');
                    }
                } else {
                    $page.remove();
                    if (hadFocus) {
                        focusValue($first);
                    }
                }
                announce((strings.page_revealed || 'Values revealed: %d.').replace('%d', shown));
            })
            .catch(function (err) {
                $page.prop('disabled', false);
                showError($page, err);
                if (hadFocus) {
                    $page.trigger('focus');
                }
            });
    });
});
