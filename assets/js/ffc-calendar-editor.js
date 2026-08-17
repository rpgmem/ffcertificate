/**
 * Calendar Editor JavaScript
 * Handles adding/removing working hours in the calendar editor
 *
 * @since 4.1.0
 */

(function($) {
    'use strict';

    const FFCCalendarEditor = {

        /**
         * Counter for working hours rows
         */
        rowCounter: 0,

        /**
         * Counter for custom block rows (#941)
         */
        slotCounter: 0,

        /**
         * Initialize editor
         */
        init: function() {
            this.bindEvents();
            this.initRowCounter();
            this.slotCounter = $('#ffc-custom-slots-list tr').length;
            this.applyMode();
        },

        /**
         * Initialize row counter based on existing rows
         */
        initRowCounter: function() {
            const existingRows = $('#ffc-working-hours-list tr').length;
            this.rowCounter = existingRows;
        },

        /**
         * Bind event listeners
         */
        bindEvents: function() {
            // Add working hour
            $(document).on('click', '#ffc-add-working-hour', this.addWorkingHour.bind(this));

            // Remove working hour
            $(document).on('click', '.ffc-remove-hour', this.removeWorkingHour.bind(this));

            // Toggle cancellation hours visibility
            $(document).on('change', '#allow_cancellation', this.toggleCancellationHours);

            // Toggle waitlist capacity field visibility (#941 phase 2)
            $(document).on('change', '#waitlist_enabled', this.toggleWaitlistCapacity);

            // Toggle scheduling visibility based on visibility setting
            $(document).on('change', '#ffc_visibility', this.toggleSchedulingVisibility);

            // Custom scheduling mode (#941): show/hide the regular vs custom
            // sections and add/remove block rows.
            $(document).on('change', '.ffc-schedule-type-radio', this.applyMode);
            $(document).on('click', '#ffc-add-custom-slot', this.addCustomSlot.bind(this));
            $(document).on('click', '.ffc-remove-slot', this.removeCustomSlot);
        },

        /**
         * Show the section that matches the selected scheduling mode (#941).
         */
        applyMode: function() {
            var mode = $('.ffc-schedule-type-radio:checked').val() || 'regular';
            if (mode === 'custom') {
                $('.ffc-regular-only').hide();
                $('.ffc-custom-only').show();
            } else {
                $('.ffc-regular-only').show();
                $('.ffc-custom-only').hide();
            }
        },

        /**
         * Add a new custom block row (#941).
         */
        addCustomSlot: function(e) {
            e.preventDefault();
            var index = this.slotCounter++;
            var editorStrings = (typeof ffcSelfSchedulingEditor !== 'undefined' && ffcSelfSchedulingEditor.strings) ? ffcSelfSchedulingEditor.strings : {};
            var removeLabel = $('<div>').text(editorStrings.remove || 'Remove').html();
            var n = 'ffc_self_scheduling_custom_slots[' + index + ']';
            var rowHtml =
                '<tr>' +
                '<td><input type="date" name="' + n + '[date]" /></td>' +
                '<td><input type="time" name="' + n + '[start]" /></td>' +
                '<td><input type="time" name="' + n + '[end]" /></td>' +
                '<td><input type="number" name="' + n + '[capacity]" value="1" min="1" max="10000" /></td>' +
                '<td><input type="text" name="' + n + '[label]" class="regular-text" /></td>' +
                '<td><button type="button" class="button ffc-remove-slot">' + removeLabel + '</button></td>' +
                '</tr>';
            $('#ffc-custom-slots-list').append(rowHtml);
        },

        /**
         * Remove a custom block row (#941). Custom calendars may legitimately
         * have zero blocks, so there is no "keep the last row" guard.
         */
        removeCustomSlot: function(e) {
            e.preventDefault();
            $(e.currentTarget).closest('tr').fadeOut(200, function() {
                $(this).remove();
            });
        },

        /**
         * Add a new working hour row
         */
        addWorkingHour: function(e) {
            e.preventDefault();

            const $list = $('#ffc-working-hours-list');
            const index = this.rowCounter++;

            const editorStrings = (typeof ffcSelfSchedulingEditor !== 'undefined' && ffcSelfSchedulingEditor.strings) ? ffcSelfSchedulingEditor.strings : {};
            const defaultDayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            const localizedDays = Array.isArray(editorStrings.daysOfWeek) ? editorStrings.daysOfWeek : defaultDayNames;
            const removeLabel = editorStrings.remove || 'Remove';

            let optionsHtml = '';
            for (let dayIdx = 0; dayIdx < 7; dayIdx++) {
                const label = localizedDays[dayIdx] || defaultDayNames[dayIdx];
                optionsHtml += '<option value="' + dayIdx + '">' + $('<div>').text(label).html() + '</option>';
            }

            const rowHtml = `
                <tr>
                    <td>
                        <select name="ffc_calendar_working_hours[${index}][day]" required>
                            ${optionsHtml}
                        </select>
                    </td>
                    <td>
                        <input type="time" name="ffc_calendar_working_hours[${index}][start]" value="09:00" required />
                    </td>
                    <td>
                        <input type="time" name="ffc_calendar_working_hours[${index}][end]" value="17:00" required />
                    </td>
                    <td>
                        <button type="button" class="button ffc-remove-hour">${$('<div>').text(removeLabel).html()}</button>
                    </td>
                </tr>
            `;

            $list.append(rowHtml);
        },

        /**
         * Remove a working hour row
         */
        removeWorkingHour: function(e) {
            e.preventDefault();

            if (!confirm(ffcSelfSchedulingEditor.strings.confirmDelete)) {
                return;
            }

            const $row = $(e.currentTarget).closest('tr');

            // Prevent removing the last row
            if ($('#ffc-working-hours-list tr').length <= 1) {
                var editorStrings = (typeof ffcSelfSchedulingEditor !== 'undefined' && ffcSelfSchedulingEditor.strings) ? ffcSelfSchedulingEditor.strings : {};
                alert(editorStrings.lastWorkingHour || 'You must have at least one working hour configured.');
                return;
            }

            $row.fadeOut(300, function() {
                $(this).remove();
            });
        },

        /**
         * Toggle cancellation hours field visibility
         */
        toggleCancellationHours: function() {
            const isChecked = $(this).is(':checked');
            $('.ffc-cancellation-hours').toggle(isChecked);
        },

        /**
         * Toggle waitlist capacity field visibility (#941 phase 2)
         */
        toggleWaitlistCapacity: function() {
            const isChecked = $(this).is(':checked');
            $('.ffc-waitlist-capacity').toggle(isChecked);
        },

        /**
         * Toggle scheduling visibility based on visibility setting
         */
        toggleSchedulingVisibility: function() {
            const $visibility = $('#ffc_visibility');
            const $scheduling = $('#ffc_scheduling_visibility');
            const $desc = $('#ffc-scheduling-desc');
            const isPrivate = $visibility.val() === 'private';

            if (isPrivate) {
                $scheduling.val('private').prop('disabled', true);
                // Add hidden input to ensure value is submitted
                if (!$scheduling.next('input[type="hidden"]').length) {
                    $scheduling.after('<input type="hidden" name="ffc_self_scheduling_config[scheduling_visibility]" value="private" />');
                }
                $desc.text(ffcSelfSchedulingEditor.strings.schedulingForced || 'Forced to Private because Visibility is Private.');
            } else {
                $scheduling.prop('disabled', false);
                $scheduling.next('input[type="hidden"]').remove();
                $desc.text(ffcSelfSchedulingEditor.strings.schedulingDesc || 'Public: anyone can book. Private: only logged-in users can book.');
            }
        },

        /**
         * Handle appointment cleanup buttons
         */
        initCleanup: function() {
            $(document).on('click', '.ffc-cleanup-btn', function() {
                const $btn = $(this);
                const action = $btn.data('action');
                const calendarId = $btn.data('calendar-id');
                const strings = typeof ffcSelfSchedulingEditor !== 'undefined' ? ffcSelfSchedulingEditor.strings : {};

                let confirmMessage = strings.confirmCleanup || 'Are you sure you want to delete these appointments? This action cannot be undone.';

                if (action === 'all') {
                    confirmMessage = strings.confirmCleanupAll || 'Are you sure you want to delete ALL appointments? This will permanently remove all appointment data and cannot be undone!';
                }

                if (!confirm(confirmMessage)) {
                    return;
                }

                $btn.prop('disabled', true).text(strings.deleting || 'Deleting...');

                FFC.request(
                    'ffc_cleanup_appointments',
                    { calendar_id: calendarId, cleanup_action: action },
                    { nonce: $('#ffc_cleanup_appointments_nonce').val() }
                )
                    .then(function (data) {
                        alert(data.message);
                        location.reload();
                    })
                    .catch(function (err) {
                        if (err && err.fromServer) {
                            alert(err.message || strings.errorDeleting || 'Error deleting appointments');
                        } else {
                            alert(strings.errorServer || 'Error communicating with server');
                        }
                        $btn.prop('disabled', false);
                    });
            });
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        FFCCalendarEditor.init();
        FFCCalendarEditor.initCleanup();
    });

})(jQuery);
