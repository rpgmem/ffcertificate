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
            // The cancellation and waitlist rows get their initial hidden
            // state from PHP, not from a handler, so nothing would have
            // stripped their `required` before the first save (#1117).
            this.syncRequired();
        },

        /**
         * Keep `required` on the fields the current configuration shows.
         *
         * Four blocks here are hidden by JS, and a hidden `required` field
         * blocks the submit against a control the operator cannot see. This
         * is not only about the fields #1117 added: the working-hours rows
         * have carried `required` on day/start/end since 4.1.0 inside
         * `.ffc-regular-only`, so a stored row with an empty time already
         * jammed the save of any calendar switched to custom mode.
         */
        syncRequired: function() {
            if (!window.FFC || !window.FFC.setRequiredWithin) {
                return;
            }
            var custom = $('.ffc-schedule-type-radio:checked').val() === 'custom';
            window.FFC.setRequiredWithin($('.ffc-regular-only'), !custom);
            window.FFC.setRequiredWithin($('.ffc-custom-only'), custom);
            window.FFC.setRequiredWithin($('.ffc-cancellation-hours'), $('#allow_cancellation').is(':checked'));
            window.FFC.setRequiredWithin($('.ffc-waitlist-capacity'), $('#waitlist_enabled').is(':checked'));
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
            FFCCalendarEditor.syncRequired();
        },

        /**
         * Add a new custom block row (#941).
         */
        addCustomSlot: function(e) {
            e.preventDefault();
            var index = this.slotCounter++;
            var editorStrings = (typeof ffcSelfSchedulingEditor !== 'undefined' && ffcSelfSchedulingEditor.strings) ? ffcSelfSchedulingEditor.strings : {};
            var removeButton = window.FFC.buttonHtml({ label: editorStrings.remove || 'Remove', category: 'danger', icon: 'delete', className: 'ffc-remove-slot' });
            var n = 'ffc_self_scheduling_custom_slots[' + index + ']';
            var rowHtml =
                '<tr>' +
                '<td><input type="date" name="' + n + '[date]" /></td>' +
                '<td><input type="time" name="' + n + '[start]" /></td>' +
                '<td><input type="time" name="' + n + '[end]" /></td>' +
                '<td><input type="number" name="' + n + '[capacity]" value="1" min="1" max="10000" required /></td>' +
                '<td><input type="text" name="' + n + '[label]" class="regular-text" /></td>' +
                '<td>' + removeButton + '</td>' +
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
            const removeButton = window.FFC.buttonHtml({ label: editorStrings.remove || 'Remove', category: 'danger', icon: 'delete', className: 'ffc-remove-hour' });

            let optionsHtml = '';
            for (let dayIdx = 0; dayIdx < 7; dayIdx++) {
                const label = localizedDays[dayIdx] || defaultDayNames[dayIdx];
                optionsHtml += '<option value="' + dayIdx + '">' + $('<div>').text(label).html() + '</option>';
            }

            const rowHtml = `
                <tr>
                    <td>
                        <select name="ffc_self_scheduling_working_hours[${index}][day]" required>
                            ${optionsHtml}
                        </select>
                    </td>
                    <td>
                        <input type="time" name="ffc_self_scheduling_working_hours[${index}][start]" value="09:00" required />
                    </td>
                    <td>
                        <input type="time" name="ffc_self_scheduling_working_hours[${index}][end]" value="17:00" required />
                    </td>
                    <td>
                        ${removeButton}
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
            FFCCalendarEditor.syncRequired();
        },

        /**
         * Toggle waitlist capacity field visibility (#941 phase 2)
         */
        toggleWaitlistCapacity: function() {
            const isChecked = $(this).is(':checked');
            $('.ffc-waitlist-capacity').toggle(isChecked);
            FFCCalendarEditor.syncRequired();
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
         * Handle appointment cleanup buttons (#1872).
         *
         * Each button opens the cleanup dialog, which states what goes and
         * how many; the request carries that count so the server can refuse
         * a run whose number moved since the page loaded.
         */
        initCleanup: function() {
            const self = this;
            $(document).on('click', '.ffc-cleanup-btn', function() {
                const $btn = $(this);
                const action = String($btn.data('action'));
                const calendarId = $btn.data('calendar-id');
                const count = parseInt($btn.data('count'), 10) || 0;
                const strings = typeof ffcSelfSchedulingEditor !== 'undefined' ? ffcSelfSchedulingEditor.strings : {};
                const title = $btn.closest('.ffc-cleanup-appointments').attr('data-calendar-title') || '';

                self.cleanupConfirm({
                    action: action,
                    count: count,
                    calendarTitle: title,
                    notify: String($btn.data('notify')) !== '0',
                    strings: strings.cleanup || {}
                }).then(function(agreed) {
                    if (!agreed) {
                        return;
                    }
                    const busy = action === 'future' ? (strings.cancelling || 'Cancelling...') : (strings.deleting || 'Deleting...');
                    $btn.prop('disabled', true).text(busy);

                    FFC.request(
                        'ffc_cleanup_appointments',
                        { calendar_id: calendarId, cleanup_action: action, expected: count },
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
            });
        },

        /**
         * The cleanup confirmation dialog (#1872).
         *
         * It is the plugin's modal (`.ffc-shortcode.ffc-modal`, appended to
         * `<body>`), stating exactly what the action reaches. "Delete all"
         * and "Cancel future" enable their button only once the person types
         * the count; the other two confirm without typing. Cancel, Escape and
         * the backdrop answer at once; focus starts on the field (or on the
         * Cancel button), stays inside the dialog, and returns where it was.
         *
         * @param {Object}  options
         * @param {string}  options.action        cancelled | old | future | all.
         * @param {number}  options.count         How many records the action reaches.
         * @param {string}  options.calendarTitle The calendar's title.
         * @param {boolean} options.notify        Whether the calendar sends the cancellation e-mail.
         * @param {Object}  options.strings       `ffcSelfSchedulingEditor.strings.cleanup`.
         * @return {Promise<boolean>} True when the person confirmed.
         */
        cleanupConfirm: function(options) {
            const opts = options || {};
            const all = opts.strings || {};
            const words = all[opts.action] || {};
            const count = parseInt(opts.count, 10) || 0;
            const isFuture = opts.action === 'future';
            const silent = isFuture && opts.notify === false;
            const typed = typeof words.type === 'string' && words.type !== '';
            const format = function(template, fallback) {
                return String(template || fallback)
                    .replace(/%1\$d|%d/g, String(count))
                    .replace(/%2\$s|%s/g, String(opts.calendarTitle || ''));
            };

            const uid = 'ffc-cleanup-' + Date.now().toString(36);
            const previous = document.activeElement;

            const dialog = document.createElement('div');
            dialog.className = 'ffc-shortcode ffc-modal ffc-cleanup-dialog ' + (isFuture ? 'ffc-cleanup-dialog-caution' : 'ffc-cleanup-dialog-danger');
            dialog.setAttribute('role', 'alertdialog');
            dialog.setAttribute('aria-modal', 'true');
            dialog.setAttribute('aria-labelledby', uid + '-title');
            dialog.setAttribute('aria-describedby', uid + '-body');

            const backdrop = document.createElement('div');
            backdrop.className = 'ffc-modal-backdrop';

            const content = document.createElement('div');
            content.className = 'ffc-modal-content';

            const header = document.createElement('div');
            header.className = 'ffc-modal-header';
            const heading = document.createElement('h3');
            heading.id = uid + '-title';
            const mark = document.createElement('span');
            mark.className = 'ffc-icon-warning ' + (isFuture ? 'ffc-icon-tone-warning' : 'ffc-icon-tone-danger');
            mark.setAttribute('aria-hidden', 'true');
            heading.appendChild(mark);
            heading.appendChild(document.createTextNode(format(words.title, 'Clean up appointments')));
            header.appendChild(heading);

            const body = document.createElement('div');
            body.className = 'ffc-modal-body';
            const summary = document.createElement('p');
            summary.id = uid + '-body';
            summary.textContent = format(silent ? words.bodySilent : words.body, '%1$d appointment(s) will be affected.');
            body.appendChild(summary);

            let input = null;
            if (typed) {
                const field = document.createElement('div');
                field.className = 'ffc-form-group ffc-cleanup-confirm-field';
                const label = document.createElement('label');
                label.setAttribute('for', uid + '-input');
                label.textContent = format(words.type, 'To confirm, type %d');
                input = document.createElement('input');
                input.type = 'text';
                input.id = uid + '-input';
                input.className = 'ffc-cleanup-confirm-input';
                input.setAttribute('inputmode', 'numeric');
                input.setAttribute('autocomplete', 'off');
                input.setAttribute('data-ffc-cleanup', 'typed');
                field.appendChild(label);
                field.appendChild(input);
                body.appendChild(field);
            }

            const footer = document.createElement('div');
            footer.className = 'ffc-modal-footer';
            // The confirm button takes the category of the button that opened
            // the dialog: cancelling future bookings keeps the records.
            const cancel = FFC.button({
                label: isFuture ? (all.goBack || 'Go back') : (all.cancel || 'Cancel'),
                category: 'neutral',
                icon: isFuture ? 'chevron-left' : 'cross',
                attrs: { 'data-ffc-cleanup': 'cancel' }
            });
            const confirmBtn = FFC.button({
                label: format(silent ? words.confirmSilent : words.confirm, 'Confirm (%d)'),
                category: isFuture ? 'caution' : 'danger',
                icon: isFuture ? 'cross' : 'delete',
                attrs: { 'data-ffc-cleanup': 'confirm' }
            });
            footer.appendChild(cancel);
            footer.appendChild(confirmBtn);

            content.appendChild(header);
            content.appendChild(body);
            content.appendChild(footer);
            dialog.appendChild(backdrop);
            dialog.appendChild(content);
            document.body.appendChild(dialog);

            return new Promise(function(resolve) {
                let done = false;

                function matches() {
                    return !input || input.value.trim() === String(count);
                }

                function sync() {
                    const ok = matches();
                    confirmBtn.disabled = !ok;
                    confirmBtn.setAttribute('aria-disabled', ok ? 'false' : 'true');
                }

                function finish(answer) {
                    if (done) {
                        return;
                    }
                    done = true;
                    document.removeEventListener('keydown', onKey, true);
                    if (dialog.parentNode) {
                        dialog.parentNode.removeChild(dialog);
                    }
                    if (previous && typeof previous.focus === 'function') {
                        previous.focus();
                    }
                    resolve(answer);
                }

                function onKey(e) {
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        e.stopPropagation();
                        finish(false);
                        return;
                    }
                    if (e.key === 'Enter' && input && document.activeElement === input) {
                        e.preventDefault();
                        if (matches()) {
                            finish(true);
                        }
                        return;
                    }
                    if (e.key !== 'Tab') {
                        return;
                    }
                    const focusable = [input, cancel, confirmBtn].filter(function(el) { return el && !el.disabled; });
                    const first = focusable[0];
                    const last = focusable[focusable.length - 1];
                    if (focusable.indexOf(document.activeElement) === -1) {
                        e.preventDefault();
                        first.focus();
                    } else if (e.shiftKey && document.activeElement === first) {
                        e.preventDefault();
                        last.focus();
                    } else if (!e.shiftKey && document.activeElement === last) {
                        e.preventDefault();
                        first.focus();
                    }
                }

                cancel.addEventListener('click', function() { finish(false); });
                backdrop.addEventListener('click', function() { finish(false); });
                confirmBtn.addEventListener('click', function() {
                    if (matches()) {
                        finish(true);
                    }
                });
                if (input) {
                    input.addEventListener('input', sync);
                }
                document.addEventListener('keydown', onKey, true);

                sync();
                (input || cancel).focus();
            });
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        FFCCalendarEditor.init();
        FFCCalendarEditor.initCleanup();
    });

})(jQuery);
