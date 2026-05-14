/**
 * FFC Submissions — inline bulk + per-row actions.
 *
 * Intercepts both:
 *   - The WP-list-table bulk form when the chosen action is one of
 *     `bulk_trash` / `bulk_restore` / `bulk_delete`.
 *   - The per-row Trash / Restore / Delete buttons.
 *
 * Both funnel into the same `ffc_submissions_bulk_action` endpoint.
 * On success the matching <tr> rows fade out and are removed; a toast
 * confirms the count via FFC.Admin.showNotification.
 *
 * `move_to_form` is intentionally left alone — its dedicated modal
 * flow handles conflict detection and target selection.
 *
 * The legacy admin_init handler stays as the no-JS fallback.
 *
 * @since 6.5.9
 */
(function ($) {
    'use strict';

    if (!window.FFC || !window.FFC.request) {
        return;
    }

    function cfg()     { return window.ffcSubmissionsBulk || {}; }
    function nonce()   { return cfg().nonce || ''; }
    function strings() { return cfg().strings || {}; }

    // Bulk-action select value → endpoint action name. Returns null
    // for anything else so we fall through to the native form submit
    // (e.g. move_to_form keeps its own modal flow).
    function normaliseBulkAction(value) {
        switch (value) {
            case 'bulk_trash':   return 'trash';
            case 'bulk_restore': return 'restore';
            case 'bulk_delete':  return 'delete';
            default:             return null;
        }
    }

    // Single-row action from a per-row button href.
    function parsePerRowAction(href) {
        var actionMatch = href.match(/[?&]action=(trash|restore|delete)\b/);
        var idMatch     = href.match(/[?&]submission_id=(\d+)/);
        if (!actionMatch || !idMatch) { return null; }
        return { action: actionMatch[1], id: parseInt(idMatch[1], 10) };
    }

    function findRowsByIds(ids) {
        var idSet = {};
        ids.forEach(function (id) { idSet[String(id)] = true; });
        return $('tr').filter(function () {
            var $cb = $(this).find('input[type="checkbox"][name="submission[]"]');
            return $cb.length > 0 && idSet[String($cb.val())] === true;
        });
    }

    function toast(message, type) {
        if (window.FFC.Admin && typeof window.FFC.Admin.showNotification === 'function') {
            window.FFC.Admin.showNotification(message, type || 'success');
        }
    }

    function dispatch(action, ids) {
        var $rows = findRowsByIds(ids);
        $rows.css('opacity', '0.55').find('input[type="checkbox"]').prop('disabled', true);

        return window.FFC.request('ffc_submissions_bulk_action', {
            action_name: action,
            ids:         ids,
            nonce:       nonce(),
        })
            .then(function (data) {
                $rows.fadeOut(220, function () { $(this).remove(); });
                var msg = (data && data.message) ? data.message : '';
                if (msg) { toast(msg, 'success'); }
            })
            .catch(function (err) {
                // Roll the visual state back so the admin can retry.
                $rows.css('opacity', '').find('input[type="checkbox"]').prop('disabled', false);
                var s = strings();
                var msg = (err && err.message) ? err.message : (s.error || 'Action failed.');
                toast(msg, 'error');
            });
    }

    // -----------------------------------------------------------------
    // Bulk form intercept
    // -----------------------------------------------------------------

    // The list-table renders TWO action selects (top + bottom of the
    // table) called `action` and `action2`. WP picks whichever is not
    // '-1'. We mirror that logic.
    function getBulkActionValue($form) {
        var top    = ($form.find('select[name="action"]').val()  || '-1');
        var bottom = ($form.find('select[name="action2"]').val() || '-1');
        if (top !== '-1' && top) { return top; }
        if (bottom !== '-1' && bottom) { return bottom; }
        return '';
    }

    $(document).on('submit', 'form', function (e) {
        var $form = $(this);
        // Only act on the Submissions list form — identified by the
        // hidden page input.
        if ($form.find('input[name="page"]').val() !== 'ffc-submissions') {
            return;
        }
        var rawAction = getBulkActionValue($form);
        var action    = normaliseBulkAction(rawAction);
        if (!action) { return; } // move_to_form etc. — let the form submit naturally.

        var ids = $form.find('input[type="checkbox"][name="submission[]"]:checked').map(function () {
            return parseInt($(this).val(), 10);
        }).get().filter(function (n) { return n > 0; });

        if (!ids.length) {
            // Mirror WP's "Select an action" + "Select rows" behaviour:
            // just let the form submit so the admin sees the native
            // notice. No-op.
            return;
        }

        if ('delete' === action) {
            var s = strings();
            var msg = s.confirmBulkDelete || 'Permanently delete the selected submissions?';
            if (!window.confirm(msg)) {
                e.preventDefault();
                return;
            }
        }

        e.preventDefault();
        dispatch(action, ids);
    });

    // -----------------------------------------------------------------
    // Per-row buttons (Trash / Restore / Delete in the actions column)
    // -----------------------------------------------------------------

    $(document).on('click', 'a.button-small', function (e) {
        var $a   = $(this);
        var href = $a.attr('href') || '';
        // Only intercept the trash/restore/delete links on the
        // Submissions page — they all carry submission_id + action.
        if (!/[?&]page=ffc-submissions\b/.test(href)) { return; }
        var parsed = parsePerRowAction(href);
        if (!parsed) { return; }

        // Delete carries data-confirm — honour it.
        if ('delete' === parsed.action) {
            var confirmMsg = $a.data('confirm') || (strings().confirmDelete || 'Permanently delete?');
            if (!window.confirm(confirmMsg)) {
                e.preventDefault();
                return;
            }
        }

        e.preventDefault();
        dispatch(parsed.action, [ parsed.id ]);
    });
}(jQuery));
