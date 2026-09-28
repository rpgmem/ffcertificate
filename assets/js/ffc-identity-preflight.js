/**
 * "What would this number do?" for the identity queue (#1397 sprint 4).
 *
 * The correction field takes a number HR confirmed, and until now the only
 * way to learn what it would do was to do it. The most useful answer is the
 * one that is not a failure: the number already belongs to another account,
 * which means these records are probably theirs and moving them is a verb
 * this screen already has.
 *
 * Every verdict here comes from the server, and on the server from the
 * repair's own checks minus the write. This file decides nothing; it renders.
 *
 * The typed value travels browser -> server, which is what a correction is.
 * Nothing stored comes back: the response carries an account, a name, a row
 * count and the refusal's own sentence.
 *
 * @since 6.28.4
 */
(function ($) {
    'use strict';

    var cfg = window.ffcIdentityPreflight || {};

    /**
     * Fill a %s / %1$s template the way `sprintf` would for our few cases.
     *
     * @param {string} template
     * @param {Array}  args
     * @returns {string}
     */
    function format(template, args) {
        var out = String(template || '');

        args.forEach(function (value, index) {
            out = out
                .split('%' + (index + 1) + '$s').join(value)
                .replace('%s', value);
        });

        return out;
    }

    /**
     * Show one line in a finding's verdict region.
     *
     * @param {jQuery} $region
     * @param {string} text
     * @param {string} tone `ok`, `warn` or `bad`.
     * @returns {jQuery} The region, emptied and repainted.
     */
    function say($region, text, tone) {
        return $region.empty().append(
            $('<p/>', { 'class': 'ffc-identity-verdict-line ffc-identity-verdict-' + tone }).text(text)
        );
    }

    /**
     * Append the "open that account" link for whoever holds the value.
     *
     * @param {jQuery} $region
     * @param {Object} holder
     */
    function linkTo($region, holder) {
        $region.append(
            $('<p/>', { 'class': 'ffc-identity-verdict-line' }).append(
                $('<a/>', {
                    href: String($region.data('profile') || '') + encodeURIComponent(holder.id),
                    text: $region.data('open') || ''
                })
            )
        );
    }

    /**
     * Show or hide the acknowledgement this form ships hidden.
     *
     * THE BOX IS REVEALED BY THE ANSWER, NOT BY THE TYPING.
     *
     * The preflight already asks whether the typed value belongs to another
     * account, so the operator has read what the write will do by the time the
     * box appears -- which is the condition the acknowledgement exists to
     * satisfy, met in one request instead of a refusal and a re-render.
     *
     * Hiding on every other verdict is the half that matters more. An operator
     * who checks a shared number, ticks the box and then edits the value would
     * otherwise carry a ticked acknowledgement into a correction it was never
     * read for. So the box is emptied as well as hidden, and the server
     * re-decides whether it was needed at all.
     *
     * `required` travels with visibility through `FFC.setRequiredWithin()`: a
     * required control inside a hidden block blocks the submit against
     * something nobody can see (#1117). The markup ships the marker rather
     * than the attribute, so there is no window at load where it does.
     *
     * @param {jQuery}  $button The Check button, which names its box.
     * @param {boolean} on      Whether the acknowledgement is being asked for.
     */
    function acknowledgement($button, on) {
        var $box = $('#' + $button.data('ffcAck'));

        if (!$box.length) {
            return;
        }

        if (!on) {
            $box.find('input[type="checkbox"]').prop('checked', false);
        }

        $box.prop('hidden', !on);

        if (window.FFC && typeof window.FFC.setRequiredWithin === 'function') {
            window.FFC.setRequiredWithin($box, on);
        }
    }

    /**
     * Paint one answer.
     *
     * AN ALLOWED VERDICT CAN CARRY A HOLDER, AND THEN IT IS NOT PLAIN `ok`
     * (#1478).
     *
     * A value that already belongs to another account used to be the refusal
     * here, and this said so: `this is a merge rather than a correction`. It is
     * not a merge — no record moves and no account is absorbed — so the server
     * allows it once the operator says they mean it. What it produces is two
     * accounts holding one number, which is the shared-identifier finding where
     * the merge is then decided.
     *
     * So the allowed branch has to check for a holder BEFORE reporting `ok`.
     * Reporting the row count alone would be the worst of the three answers
     * available: true, reassuring, and silent about the only thing the operator
     * needs before confirming a write no use of this verb can undo.
     *
     * A refusal still carries a holder in one case — rows nobody owns, or more
     * than one other account — and that one keeps the server's own sentence.
     *
     * @param {jQuery} $region
     * @param {Object} data
     * @param {jQuery} $button The Check button, which names the box to reveal.
     */
    function paint($region, data, $button) {
        acknowledgement($button, !!(data.allowed && data.holder));

        if (data.allowed && data.holder) {
            say(
                $region,
                format(
                    $region.data('shared') || '',
                    [data.rows, data.holder.name || ('#' + data.holder.id), data.holder.id]
                ),
                'warn'
            );
            linkTo($region, data.holder);
            return;
        }

        if (data.allowed) {
            say(
                $region,
                format(
                    $region.data(data.consolidates ? 'consolidates' : 'allowed') || '',
                    [data.rows]
                ),
                'ok'
            );
            return;
        }

        if (!data.holder) {
            say($region, data.message, 'bad');
            return;
        }

        say(
            $region,
            format($region.data('holder') || '', [data.holder.name || ('#' + data.holder.id), data.holder.id]),
            'warn'
        );

        linkTo($region, data.holder);
    }

    /**
     * Ask what the typed number would do.
     *
     * @param {jQuery} $button
     */
    function check($button) {
        var $region = $('#' + $button.data('ffcVerdict'));
        var $value = $('#' + $button.data('ffcValue'));
        var typed = String($value.val() || '').trim();

        if (!$region.length) {
            return;
        }

        if (!typed) {
            say($region, $region.data('empty') || '', 'bad');
            $value.trigger('focus');
            return;
        }

        $button.prop('disabled', true);

        $.post(cfg.ajaxUrl, {
            action: cfg.action,
            nonce: cfg.nonce,
            subject: $button.data('ffcSubject'),
            field: $button.data('ffcField'),
            value: typed
        }).done(function (response) {
            if (!response || !response.success || !response.data) {
                say($region, $region.data('failed') || '', 'bad');
                return;
            }

            paint($region, response.data, $button);
        }).fail(function () {
            say($region, $region.data('failed') || '', 'bad');
        }).always(function () {
            $button.prop('disabled', false);
        });
    }

    /**
     * Bind to whatever is in the document now. Idempotent, like its sibling:
     * the namespace is what makes a second call replace rather than add.
     *
     * @returns {boolean} Whether there was anything to bind.
     */
    function boot() {
        if (!cfg.ajaxUrl || !$('.ffc-identity-check').length) {
            return false;
        }

        $(document).off('.ffcIdentityPreflight')
            .on('click.ffcIdentityPreflight', '.ffc-identity-check', function (event) {
                event.preventDefault();
                check($(this));
            });

        return true;
    }

    window.FFC = window.FFC || {};
    window.FFC.IdentityPreflight = { boot: boot };

    $(boot);
}(jQuery));
