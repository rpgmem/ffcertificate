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

})(jQuery);
