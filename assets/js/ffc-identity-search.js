/**
 * Account-search dialog for the identity queue.
 *
 * Reads the agreement rule BEFORE the operator commits instead of after the
 * refusal (#1397 sprint 3). Every verdict it shows comes from the server, and
 * on the server it comes from the same two methods the write calls — this file
 * decides nothing about who may receive records; it renders what was decided.
 *
 * It writes the chosen account into the move form's existing number field
 * rather than replacing it, so the form posts what it always posted and the
 * screen still works with this script absent.
 *
 * @since 6.28.4
 */
(function ($) {
    'use strict';

    var cfg = window.ffcIdentitySearch || {};
    var strings = cfg.strings || {};

    var DEBOUNCE = 300; // ms — long enough that typing a name is one request.

    var $dialog;
    var $sub;
    var $suggestion;
    var $query;
    var $results;
    var $confirm;

    var opener = null;   // The .ffc-identity-find button that opened it.
    var picked = null;   // The account chosen inside the dialog, not yet applied.
    var request = null;  // The in-flight search, so a slow one cannot overwrite a fast one.
    var timer = null;

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
     * One account row. Refused candidates are rendered disabled AND carry the
     * reason, because a control that cannot be used without saying why reads
     * as a bug rather than as an answer.
     *
     * @param {Object} account
     * @returns {jQuery}
     */
    function row(account) {
        var $row = $('<label/>', {
            'class': 'ffc-identity-dialog-result' + (account.allowed ? '' : ' ffc-identity-dialog-result-refused')
        });

        $('<input/>', {
            type: 'radio',
            name: 'ffc_identity_destination',
            value: account.id,
            disabled: !account.allowed
        }).appendTo($row);

        var $who = $('<span/>', { 'class': 'ffc-identity-dialog-who' });
        $('<strong/>').text(account.name || ('#' + account.id)).appendTo($who);
        $('<span/>', { 'class': 'ffc-identity-dialog-meta' })
            .text('#' + account.id + (account.email ? ' · ' + account.email : ''))
            .appendTo($who);
        $who.appendTo($row);

        $('<span/>', {
            'class': 'ffc-identity-dialog-verdict ' + (account.allowed
                ? 'ffc-identity-dialog-verdict-ok'
                : 'ffc-identity-dialog-verdict-refused'),
            title: account.reason || ''
        }).text(account.label || '').appendTo($row);

        if (!account.allowed && account.reason) {
            $('<span/>', { 'class': 'screen-reader-text' }).text(account.reason).appendTo($row);
        }

        $row.on('change', 'input', function () {
            picked = account;
            $confirm.prop('disabled', false);
        });

        return $row;
    }

    /**
     * Render the suggestion region — an account, or the plain statement that
     * there is none. An empty region would read as "not looked at yet".
     *
     * @param {Object|null} account
     */
    function renderSuggestion(account) {
        $suggestion.empty();

        if (!account) {
            $suggestion
                .removeClass('ffc-identity-dialog-suggestion-has')
                .append($('<p/>', { 'class': 'description' }).text($suggestion.data('none') || ''));
            return;
        }

        $suggestion
            .addClass('ffc-identity-dialog-suggestion-has')
            .append($('<span/>', { 'class': 'ffc-identity-dialog-suggestion-head' })
                .text($suggestion.data('head') || ''))
            .append(row(account));
    }

    /**
     * Ask the server. One request at a time; a superseded one is abandoned
     * rather than allowed to paint over a newer answer.
     */
    function search() {
        if (request) {
            request.abort();
            request = null;
        }

        var term = String($query.val() || '').trim();

        if (!term) {
            $results.empty();
            return;
        }

        $results.empty().append($('<p/>', { 'class': 'description' }).text(strings.searching || ''));

        request = $.post(cfg.ajaxUrl, {
            action: cfg.action,
            nonce: cfg.nonce,
            subject: opener ? opener.data('ffcSubject') : '',
            field: opener ? opener.data('ffcField') : '',
            q: term
        }).done(function (response) {
            if (!response || !response.success) {
                fail(response);
                return;
            }

            paint(response.data);
        }).fail(function (xhr, status) {
            if ('abort' === status) {
                return;
            }

            fail(xhr && xhr.responseJSON);
        }).always(function () {
            request = null;
        });
    }

    /**
     * Show a refusal in place of the list. A refusal that does not depend on
     * the destination makes every candidate equally wrong, so offering them
     * would be worse than saying so.
     *
     * @param {Object} response
     */
    function fail(response) {
        var message = (response && response.data && response.data.message) || strings.failed || '';

        $results.empty().append(
            $('<div/>', { 'class': 'notice notice-error inline' }).append($('<p/>').text(message))
        );
    }

    /**
     * @param {Object} data
     */
    function paint(data) {
        picked = null;
        $confirm.prop('disabled', true);
        $results.empty();

        var accounts = (data && data.accounts) || [];

        if (!accounts.length) {
            $results.append($('<p/>', { 'class': 'description' }).text(strings.noResults || ''));
            return;
        }

        $results.append($('<div/>', { 'class': 'ffc-identity-dialog-count' })
            .text(format(strings.found || '', [accounts.length])));

        accounts.forEach(function (account) {
            $results.append(row(account));
        });

        if (data.truncated) {
            $results.append($('<p/>', { 'class': 'description' })
                .text($results.data('truncated') || ''));
        }
    }

    /**
     * Open the dialog for one move form.
     *
     * @param {jQuery} $button
     */
    function open($button) {
        opener = $button;
        picked = null;

        $sub.text('');
        $results.empty();
        $suggestion.empty();
        $query.val('');
        $confirm.prop('disabled', true);
        $dialog.prop('hidden', false);
        $query.trigger('focus');

        $.post(cfg.ajaxUrl, {
            action: cfg.action,
            nonce: cfg.nonce,
            subject: $button.data('ffcSubject'),
            field: $button.data('ffcField'),
            q: ''
        }).done(function (response) {
            if (!response || !response.success) {
                fail(response);
                return;
            }

            $sub.text(format(strings.subtitle || '', [
                response.data.records,
                response.data.prefix,
                String(response.data.field || '').toUpperCase()
            ]));

            renderSuggestion(response.data.suggestion || null);
        }).fail(function (xhr) {
            fail(xhr && xhr.responseJSON);
        });
    }

    function close() {
        $dialog.prop('hidden', true);

        if (opener) {
            opener.trigger('focus');
        }

        opener = null;
        picked = null;
    }

    /**
     * Apply the choice to the move form that opened the dialog, and bar the
     * split: two destinations for one set of records is a mistake the form
     * should not be able to express.
     */
    function apply() {
        if (!picked || !opener) {
            return;
        }

        var $input = $('#' + opener.data('ffcInput'));
        var $submit = $('#' + opener.data('ffcSubmit'));
        var $chosen = opener.closest('form').find('.ffc-identity-chosen');
        var $split = $('#' + opener.data('ffcSplit'));

        $input.val(picked.id);
        $submit.text(format(strings.move || '', [picked.id]));
        $chosen.text(format(strings.chosen || '', [picked.name || ('#' + picked.id), picked.id]))
            .prop('hidden', false);

        if ($split.length) {
            // `disabled` and not merely hidden: a hidden `required` control
            // blocks the submit against something nobody can see, and only
            // `disabled` bars a control from constraint validation (#1114).
            $split.find('input, button').prop('disabled', true);
            $split.find('.ffc-identity-split-barred').prop('hidden', false);
        }

        close();
    }

    /**
     * Bind the dialog to whatever is in the document now.
     *
     * Exported and idempotent, for the reason `bootAutoSaveFields()` is: a
     * screen that inserts move forms later has a supported answer, and calling
     * it twice must not double every handler. The namespace on each binding is
     * what makes the second call replace rather than add.
     *
     * @returns {boolean} Whether there was a dialog to bind.
     */
    function boot() {
        $dialog = $('#ffc-identity-dialog');

        if (!$dialog.length || !cfg.ajaxUrl) {
            return false;
        }

        $sub = $('#ffc-identity-dialog-sub');
        $suggestion = $('#ffc-identity-dialog-suggestion');
        $query = $('#ffc-identity-dialog-q');
        $results = $('#ffc-identity-dialog-results');
        $confirm = $('#ffc-identity-dialog-confirm');

        $(document).off('.ffcIdentitySearch')
            .on('click.ffcIdentitySearch', '.ffc-identity-find', function (event) {
                event.preventDefault();
                open($(this));
            })
            .on('keydown.ffcIdentitySearch', function (event) {
                if (27 === event.which && !$dialog.prop('hidden')) {
                    close();
                }
            });

        $dialog.off('.ffcIdentitySearch')
            .on('click.ffcIdentitySearch', '[data-ffc-dialog-dismiss]', function (event) {
                event.preventDefault();
                close();
            });

        $confirm.off('.ffcIdentitySearch').on('click.ffcIdentitySearch', function (event) {
            event.preventDefault();
            apply();
        });

        $query.off('.ffcIdentitySearch').on('input.ffcIdentitySearch', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(search, DEBOUNCE);
        });

        return true;
    }

    window.FFC = window.FFC || {};
    window.FFC.IdentitySearch = { boot: boot };

    $(boot);
}(jQuery));
