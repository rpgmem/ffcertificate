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
         * Initialize editor
         */
        init: function() {
            this.bindEvents();
            this.initRowCounter();
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

            // Toggle allowed roles visibility
            $(document).on('change', '#require_login', this.toggleAllowedRoles);
        },

        /**
         * Add a new working hour row
         */
        addWorkingHour: function(e) {
            e.preventDefault();

            const $list = $('#ffc-working-hours-list');
            const index = this.rowCounter++;

            const daysOfWeek = [
                { value: 0, label: 'Sunday' },
                { value: 1, label: 'Monday' },
                { value: 2, label: 'Tuesday' },
                { value: 3, label: 'Wednesday' },
                { value: 4, label: 'Thursday' },
                { value: 5, label: 'Friday' },
                { value: 6, label: 'Saturday' }
            ];

            let optionsHtml = '';
            daysOfWeek.forEach(function(day) {
                optionsHtml += '<option value="' + day.value + '">' + day.label + '</option>';
            });

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
                        <button type="button" class="button ffc-remove-hour">Remove</button>
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
                alert('You must have at least one working hour configured.');
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
         * Toggle allowed roles field visibility
         */
        toggleAllowedRoles: function() {
            const isChecked = $(this).is(':checked');
            $('.ffc-allowed-roles').toggle(isChecked);
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

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'ffc_cleanup_appointments',
                        calendar_id: calendarId,
                        cleanup_action: action,
                        nonce: $('#ffc_cleanup_appointments_nonce').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
                        } else {
                            alert(response.data.message || strings.errorDeleting || 'Error deleting appointments');
                            $btn.prop('disabled', false);
                        }
                    },
                    error: function() {
                        alert(strings.errorServer || 'Error communicating with server');
                        $btn.prop('disabled', false);
                    }
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
