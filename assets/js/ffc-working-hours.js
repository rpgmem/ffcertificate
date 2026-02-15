/**
 * Working Hours Field Component
 *
 * Shared JS for the working_hours custom field type.
 * Used in both the frontend reregistration form and the admin user profile.
 *
 * HTML contract:
 *   <input type="hidden" id="TARGET_ID" name="..." value='[JSON]'>
 *   <div class="ffc-working-hours" data-target="TARGET_ID">
 *       <table class="ffc-wh-table"><tbody>...</tbody></table>
 *       <button class="ffc-wh-add">+ Add Hours</button>
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

    function buildRow(day, start, end) {
        var opts = '';
        for (var i = 0; i < DAYS.length; i++) {
            var sel = (parseInt(DAYS[i].value, 10) === parseInt(day, 10)) ? ' selected' : '';
            opts += '<option value="' + DAYS[i].value + '"' + sel + '>' + DAYS[i].label + '</option>';
        }

        return '<tr>' +
            '<td><select class="ffc-wh-day">' + opts + '</select></td>' +
            '<td><input type="time" class="ffc-wh-start" value="' + (start || '09:00') + '"></td>' +
            '<td><input type="time" class="ffc-wh-end" value="' + (end || '17:00') + '"></td>' +
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
                day:   parseInt($row.find('.ffc-wh-day').val(), 10),
                start: $row.find('.ffc-wh-start').val() || '09:00',
                end:   $row.find('.ffc-wh-end').val() || '17:00'
            });
        });

        $hidden.val(JSON.stringify(entries));
    }

    // Add row
    $(document).on('click', '.ffc-wh-add', function (e) {
        e.preventDefault();
        var $wrapper = $(this).closest('.ffc-working-hours');
        $wrapper.find('.ffc-wh-table tbody').append(buildRow(1, '09:00', '17:00'));
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
    $(document).on('change', '.ffc-wh-day, .ffc-wh-start, .ffc-wh-end', function () {
        var $wrapper = $(this).closest('.ffc-working-hours');
        syncHidden($wrapper);
    });

})(jQuery);
