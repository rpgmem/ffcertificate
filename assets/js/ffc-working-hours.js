/**
 * Working Hours Field Component
 *
 * Shared JS for the working_hours custom field type.
 * Used in both the frontend reregistration form and the admin user profile.
 *
 * Each row: Day | Entry 1 (required) | Exit 1 | Entry 2 | Exit 2 (required)
 *
 * HTML contract:
 *   <input type="hidden" id="TARGET_ID" name="..." value='[JSON]'>
 *   <div class="ffc-working-hours" data-target="TARGET_ID">
 *       <table class="ffc-wh-table"><tbody>...</tbody></table>
 *       <button class="ffc-wh-add">+ Add Day</button>
 *   </div>
 *
 * @since 4.12.0
 * @package FreeFormCertificate
 */
(function ($) {
    'use strict';

    var DAYS = [
        { value: 0, label: 'Sunday' },
        { value: 1, label: 'Monday' },
        { value: 2, label: 'Tuesday' },
        { value: 3, label: 'Wednesday' },
        { value: 4, label: 'Thursday' },
        { value: 5, label: 'Friday' },
        { value: 6, label: 'Saturday' }
    ];

    // Use translated labels if available
    if (window.ffcWorkingHours && window.ffcWorkingHours.days) {
        DAYS = window.ffcWorkingHours.days;
    }

    function buildRow(day, entry1, exit1, entry2, exit2) {
        var opts = '';
        for (var i = 0; i < DAYS.length; i++) {
            var sel = (parseInt(DAYS[i].value, 10) === parseInt(day, 10)) ? ' selected' : '';
            opts += '<option value="' + DAYS[i].value + '"' + sel + '>' + DAYS[i].label + '</option>';
        }

        return '<tr>' +
            '<td><select class="ffc-wh-day">' + opts + '</select></td>' +
            '<td><input type="time" class="ffc-wh-entry1" value="' + (entry1 || '08:00') + '" required></td>' +
            '<td><input type="time" class="ffc-wh-exit1" value="' + (exit1 || '') + '"></td>' +
            '<td><input type="time" class="ffc-wh-entry2" value="' + (entry2 || '') + '"></td>' +
            '<td><input type="time" class="ffc-wh-exit2" value="' + (exit2 || '17:00') + '" required></td>' +
            '<td><button type="button" class="button button-small ffc-wh-remove">&times;</button></td>' +
            '</tr>';
    }

    function syncHidden($wrapper) {
        var targetId = $wrapper.data('target');
        var $hidden = $('#' + targetId);
        if (!$hidden.length) {
            // Fallback: target might be a name attribute
            $hidden = $('[name="' + targetId + '"]');
        }

        var entries = [];
        $wrapper.find('.ffc-wh-table tbody tr').each(function () {
            var $row = $(this);
            entries.push({
                day:    parseInt($row.find('.ffc-wh-day').val(), 10),
                entry1: $row.find('.ffc-wh-entry1').val() || '',
                exit1:  $row.find('.ffc-wh-exit1').val() || '',
                entry2: $row.find('.ffc-wh-entry2').val() || '',
                exit2:  $row.find('.ffc-wh-exit2').val() || ''
            });
        });

        $hidden.val(JSON.stringify(entries));
    }

    // Add row
    $(document).on('click', '.ffc-wh-add', function (e) {
        e.preventDefault();
        var $wrapper = $(this).closest('.ffc-working-hours');
        $wrapper.find('.ffc-wh-table tbody').append(buildRow(1, '08:00', '12:00', '13:00', '17:00'));
        syncHidden($wrapper);
    });

    // Remove row
    $(document).on('click', '.ffc-wh-remove', function (e) {
        e.preventDefault();
        var $wrapper = $(this).closest('.ffc-working-hours');
        $(this).closest('tr').remove();
        syncHidden($wrapper);
    });

    // Sync on any change
    $(document).on('change', '.ffc-wh-day, .ffc-wh-entry1, .ffc-wh-exit1, .ffc-wh-entry2, .ffc-wh-exit2', function () {
        var $wrapper = $(this).closest('.ffc-working-hours');
        syncHidden($wrapper);
    });

    /**
     * Rows that carry some time but not both required ones (#1128).
     *
     * A row with NO time at all is not incomplete — it is "I do not work this
     * day", and the server drops it. Only a half-filled row is a mistake.
     *
     * @param {jQuery} $wrapper The .ffc-working-hours container.
     * @return {Array} One entry per offending row: { $row, missing: [labels] }.
     */
    function incompleteRows($wrapper) {
        var found = [];

        $wrapper.find('.ffc-wh-table tbody tr').each(function () {
            var $row = $(this);
            // `$.trim` was removed in jQuery 4; native String.prototype.trim
            // is the replacement and works on every version WP has shipped.
            var entry1 = ($row.find('.ffc-wh-entry1').val() || '').trim();
            var exit1  = ($row.find('.ffc-wh-exit1').val() || '').trim();
            var entry2 = ($row.find('.ffc-wh-entry2').val() || '').trim();
            var exit2  = ($row.find('.ffc-wh-exit2').val() || '').trim();

            if (!entry1 && !exit1 && !entry2 && !exit2) {
                return;
            }

            var missing = [];
            if (!entry1) { missing.push(strings.entry1); }
            if (!exit2)  { missing.push(strings.exit2); }

            if (missing.length) {
                found.push({ $row: $row, missing: missing });
            }
        });

        return found;
    }

    var strings = $.extend({
        entry1:     'Entry 1',
        exit2:      'Exit 2',
        incomplete: 'This row has a time but is missing: %s. Fill it in, or clear the row to remove the day.'
    }, (window.ffcWorkingHours && window.ffcWorkingHours.strings) || {});

    /**
     * Block the submit on a half-filled row, instead of letting the server
     * decide what to discard (#1128).
     *
     * This is the half that can actually let the operator fix it: once the
     * form posts, the screen reloads from the database and whatever they typed
     * is gone, so "signal and let them correct" only means something *before*
     * the POST. The server-side sanitizer stays as the backstop for a direct
     * POST or a client with JS off — the browser is never the guard.
     *
     * `reportValidity()` is not usable here: the two time cells carry no
     * `name`, and `FFC.setRequiredWithin()` strips their `required` while a
     * section is collapsed, which is deliberate. So the message is placed on
     * the row itself, and any collapsed ancestor is opened first — otherwise
     * this reproduces the very defect #1117 set out to remove, pointing at a
     * control nobody can see.
     */
    $(document).on('submit', 'form', function (e) {
        var $form = $(this);
        var blocked = null;

        $form.find('.ffc-working-hours').each(function () {
            var $wrapper = $(this);
            var rows = incompleteRows($wrapper);

            $wrapper.find('.ffc-wh-row-error').remove();
            $wrapper.find('.ffc-wh-row-invalid').removeClass('ffc-wh-row-invalid');

            for (var i = 0; i < rows.length; i++) {
                rows[i].$row.addClass('ffc-wh-row-invalid');
                $('<tr class="ffc-wh-row-error"><td colspan="6"></td></tr>')
                    .find('td')
                    .text(strings.incomplete.replace('%s', rows[i].missing.join(', ')))
                    .end()
                    .insertAfter(rows[i].$row);

                if (!blocked) {
                    blocked = rows[i].$row;
                }
            }
        });

        if (!blocked) {
            return;
        }

        e.preventDefault();

        // Open any collapsed ancestor before pointing at the row.
        blocked.parents('.ffc-cf-section-body.collapsed').each(function () {
            var $body = $(this);
            $body.removeClass('collapsed');
            $('[data-target="' + $body.attr('id') + '"]').attr('aria-expanded', 'true');
        });

        if (blocked[0] && blocked[0].scrollIntoView) {
            blocked[0].scrollIntoView({ block: 'center' });
        }
        blocked.find('.ffc-wh-entry1, .ffc-wh-exit2').filter(function () {
            return !($(this).val() || '').trim();
        }).first().trigger('focus');
    });

})(jQuery);
