/**
 * FFC Audience Calendar — shared core (config + state + init + helpers).
 *
 * Owns the `window.FFCAudience` singleton: the normalized `ffcAudience`
 * config, the shared calendar `state`, the init/bindEvents boot, modal
 * focus management, and the leaf formatting/lookup helpers. The calendar
 * grid, day-bookings, and booking-form flows live in sibling files that
 * depend on this handle and extend the namespace:
 *  - ffc-audience-calendar.js     → month grid, environment/audience selects, event list
 *  - ffc-audience-bookings.js     → day modal, day bookings list, cancel
 *  - ffc-audience-booking-form.js → booking modal, user search, conflicts, create
 *
 * @since 4.5.0
 * @package FreeFormCertificate\Audience
 */

(function($) {
    'use strict';

    // Ensure ffcAudience is defined with defaults
    if (typeof ffcAudience === 'undefined') {
        window.ffcAudience = {
            ajaxUrl: '/wp-admin/admin-ajax.php',
            restUrl: '/wp-json/ffc/v1/audience/',
            nonce: ''
        };
    }
    if (!ffcAudience.strings) {
        ffcAudience.strings = {};
    }
    // Convert WordPress locale (pt_BR) to BCP 47 format (pt-BR)
    if (ffcAudience.locale) {
        ffcAudience.locale = ffcAudience.locale.replace('_', '-');
    }
    // Default strings fallback
    var defaultStrings = {
        months: ['January', 'February', 'March', 'April', 'May', 'June',
                 'July', 'August', 'September', 'October', 'November', 'December'],
        loading: 'Loading...',
        error: 'An error occurred. Please try again.',
        noBookings: 'No bookings for this day.',
        noActiveBookings: 'No active bookings for this day.',
        bookingCreated: 'Booking created successfully!',
        bookingCancelled: 'Booking cancelled successfully.',
        confirmCancel: 'Are you sure you want to cancel this booking?',
        cancelReason: 'Please provide a reason for cancellation:',
        invalidTime: 'End time must be after start time.',
        selectAudience: 'Please select at least one audience.',
        selectUser: 'Please select at least one user.',
        descriptionRequired: 'Description is required (15-300 characters).',
        conflictWarning: 'Warning: Conflicts detected with existing bookings.',
        holiday: 'Holiday',
        closed: 'Closed',
        cancelled: 'Cancelled',
        cancel: 'Cancel',
        available: 'Available',
        booked: 'Booked',
        timeout: 'Request timed out. Please try again.',
        checkConflicts: 'Check Conflicts',
        booking: 'booking',
        bookings: 'bookings',
        createBooking: 'Create Booking',
        newBooking: 'New Booking',
        multipleAudiences: 'Multiple audiences',
        audienceSameDayWarning: 'This audience group already has a booking on this day:'
    };
    for (var key in defaultStrings) {
        if (!ffcAudience.strings[key]) {
            ffcAudience.strings[key] = defaultStrings[key];
        }
    }

    // Calendar state — shared across all flow modules via api.state.
    var state = {
        currentDate: new Date(),
        selectedSchedule: 0,
        selectedEnvironment: 0,
        config: {},
        bookings: {},
        holidays: {},
        selectedUsers: {},
        fetchId: 0
    };

    var api = window.FFCAudience = {
        state: state,

        /**
         * Initialize the calendar
         */
        init: function() {
            var $calendar = $('#ffc-audience-calendar');
            if (!$calendar.length) {
                return;
            }

            // Portal the modals to <body> so their position: fixed always
            // resolves against the viewport. Any ancestor with transform,
            // filter, perspective, will-change or contain creates a new
            // containing block for fixed-position descendants — block themes
            // routinely add such properties to entry-content / wp-block-*
            // wrappers, which traps the modal inside the post layout column
            // and shows it as a horizontal stripe instead of a full-screen
            // overlay.
            $('#ffc-booking-modal, #ffc-day-modal').appendTo('body');

            // Parse config from data attribute
            state.config = JSON.parse($calendar.attr('data-config') || '{}');
            state.selectedSchedule = state.config.scheduleId || 0;
            state.selectedEnvironment = state.config.environmentId || 0;

            // Initialize UI
            api.updateEnvironmentSelect();
            api.populateAudienceSelect();
            api.renderCalendar();
            api.bindEvents();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Navigation. We anchor to day-1 before adjusting the month so JS's
            // Date overflow (e.g. May 31 → setMonth(3) rolls forward to May 1
            // because April has only 30 days) doesn't strand the calendar on
            // the same month it started on.
            $('.ffc-prev-month').on('click', function() {
                state.currentDate.setDate(1);
                state.currentDate.setMonth(state.currentDate.getMonth() - 1);
                api.renderCalendar();
            });

            $('.ffc-next-month').on('click', function() {
                state.currentDate.setDate(1);
                state.currentDate.setMonth(state.currentDate.getMonth() + 1);
                api.renderCalendar();
            });

            $('.ffc-today-btn').on('click', function() {
                state.currentDate = new Date();
                api.renderCalendar();
            });

            // Filters
            $('#ffc-schedule-select').on('change', function() {
                state.selectedSchedule = parseInt($(this).val()) || 0;
                api.updateEnvironmentSelect();
                api.renderCalendar();
            });

            $('#ffc-environment-select').on('change', function() {
                state.selectedEnvironment = parseInt($(this).val()) || 0;
                api.renderCalendar();
            });

            // Day click - scoped to audience calendar only
            $('#ffc-audience-calendar').on('click', '.ffc-day:not(.ffc-past):not(.ffc-disabled):not(.ffc-other-month)', function() {
                var date = $(this).data('date');
                if (date) {
                    api.openDayModal(date);
                }
            });

            // Modal controls - scoped to audience modals only (direct binding, not delegation,
            // because .ffc-modal-content has stopPropagation which blocks delegated handlers)
            $('#ffc-booking-modal .ffc-modal-close, #ffc-booking-modal .ffc-modal-cancel, #ffc-day-modal .ffc-modal-close, #ffc-day-modal .ffc-modal-cancel').on('click', function() {
                api.closeModals();
            });
            $('#ffc-booking-modal > .ffc-modal-backdrop, #ffc-day-modal > .ffc-modal-backdrop').on('click', function() {
                api.closeModals();
            });

            // Close modals on Escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && ($('#ffc-booking-modal').is(':visible') || $('#ffc-day-modal').is(':visible'))) {
                    api.closeModals();
                }
            });

            // Show cancelled checkbox
            $('#ffc-show-cancelled').on('change', function() {
                var date = $('#ffc-day-modal').data('date');
                if (date) {
                    api.loadDayBookings(date);
                }
            });

            // All-day toggle
            $('#booking-all-day').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#booking-time-row').hide();
                    $('#booking-start-time').removeAttr('required').val('00:00');
                    $('#booking-end-time').removeAttr('required').val('23:59');
                } else {
                    $('#booking-time-row').show();
                    $('#booking-start-time').attr('required', 'required').val('');
                    $('#booking-end-time').attr('required', 'required').val('');
                }
            });

            // Booking type toggle
            $('#booking-type').on('change', function() {
                if ($(this).val() === 'audience') {
                    $('#audience-select-group').show();
                    $('#user-select-group').hide();
                } else {
                    $('#audience-select-group').hide();
                    $('#user-select-group').show();
                }
            });

            // Audience select: selecting a parent auto-selects all descendants
            (function() {
                // Collect all descendant IDs of an audience node recursively
                function getAllDescendantIds(node) {
                    var ids = [];
                    if (!node.children || node.children.length === 0) return ids;
                    node.children.forEach(function(c) {
                        ids.push(parseInt(c.id));
                        ids = ids.concat(getAllDescendantIds(c));
                    });
                    return ids;
                }

                // Walk the audience tree and collect every node that has children
                function collectParentNodes(nodes) {
                    var result = [];
                    (nodes || []).forEach(function(node) {
                        if (node.children && node.children.length > 0) {
                            result.push(node);
                            result = result.concat(collectParentNodes(node.children));
                        }
                    });
                    return result;
                }

                var prevSelected = [];
                $('#booking-audiences').on('change', function() {
                    var $sel = $(this);
                    var selected = ($sel.val() || []).map(function(v) { return parseInt(v); });
                    var audiences = state.config.audiences || [];
                    var newSelected = selected.slice();

                    var parentNodes = collectParentNodes(audiences);
                    parentNodes.forEach(function(node) {
                        var parentId = parseInt(node.id);
                        var descIds = getAllDescendantIds(node);
                        var parentNowSelected = selected.indexOf(parentId) !== -1;
                        var parentWasSelected = prevSelected.indexOf(parentId) !== -1;

                        if (parentNowSelected && !parentWasSelected) {
                            // Parent just selected — add all descendants
                            descIds.forEach(function(did) {
                                if (newSelected.indexOf(did) === -1) {
                                    newSelected.push(did);
                                }
                            });
                        } else if (!parentNowSelected && parentWasSelected) {
                            // Parent just deselected — remove all descendants
                            newSelected = newSelected.filter(function(id) {
                                return descIds.indexOf(id) === -1;
                            });
                        }
                    });

                    // Only update if selection changed to avoid infinite loop
                    if (newSelected.length !== selected.length || !newSelected.every(function(v) { return selected.indexOf(v) !== -1; })) {
                        $sel.val(newSelected.map(String));
                    }
                    prevSelected = ($sel.val() || []).map(function(v) { return parseInt(v); });
                });
            })();

            // Description character count
            $('#booking-description').on('input', function() {
                $('#desc-char-count').text($(this).val().length);
            });

            // User search
            var searchTimeout;
            $('#booking-user-search').on('input', function() {
                clearTimeout(searchTimeout);
                var query = $(this).val();
                if (query.length < 2) {
                    $('#booking-user-results').removeClass('active').empty();
                    return;
                }

                searchTimeout = setTimeout(function() {
                    api.searchUsers(query);
                }, 300);
            });

            // Select user from results
            $(document).on('click', '#booking-user-results .ffc-user-result', function() {
                var id = $(this).data('id');
                var name = $(this).data('name');
                state.selectedUsers[id] = name;
                api.updateSelectedUsers();
                $('#booking-user-results').removeClass('active').empty();
                $('#booking-user-search').val('');
            });

            // Remove selected user
            $(document).on('click', '#booking-selected-users .remove', function() {
                var id = $(this).data('id');
                delete state.selectedUsers[id];
                api.updateSelectedUsers();
            });

            // Check conflicts button
            $('#ffc-check-conflicts-btn').on('click', function() {
                api.checkConflicts();
            });

            // Create booking button
            $('#ffc-create-booking-btn').on('click', function() {
                api.createBooking();
            });

            // Soft conflict acknowledgment checkbox
            $('#ffc-conflict-acknowledge').on('change', function() {
                $('#ffc-create-booking-btn').prop('disabled', !$(this).is(':checked'));
            });

            // New booking from day modal
            $('#ffc-new-booking-btn').on('click', function() {
                var date = $('#ffc-day-modal').data('date');
                api.closeModals();
                api.openBookingModal(date);
            });

            // Prevent modal close on content click - scoped to audience modals only
            $('#ffc-booking-modal .ffc-modal-content, #ffc-day-modal .ffc-modal-content').on('click', function(e) {
                e.stopPropagation();
            });
        },

        /**
         * Trap focus within a modal for accessibility
         */
        trapFocus: function($modal) {
            $modal.off('keydown.focustrap').on('keydown.focustrap', function(e) {
                if (e.key !== 'Tab') return;
                var focusable = $modal.find('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])').filter(':visible');
                var first = focusable.first()[0];
                var last = focusable.last()[0];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            });
        },

        /**
         * Close all modals
         */
        closeModals: function() {
            $('#ffc-booking-modal, #ffc-day-modal').off('keydown.focustrap').hide();
            if (state._triggerElement) {
                state._triggerElement.focus();
                state._triggerElement = null;
            }
        },

        /**
         * Format date as YYYY-MM-DD
         */
        formatDate: function(date) {
            return date.getFullYear() + '-' + api.pad(date.getMonth() + 1) + '-' + api.pad(date.getDate());
        },

        /**
         * Parse date string (YYYY-MM-DD) to Date object in local timezone
         * This avoids timezone issues when using new Date(string) which interprets as UTC
         */
        parseDate: function(dateStr) {
            var parts = dateStr.split('-');
            return new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
        },

        /**
         * Format time (HH:MM:SS to HH:MM)
         */
        formatTime: function(time) {
            if (!time) return '';
            return time.substring(0, 5);
        },

        /**
         * Pad number with leading zero
         */
        pad: function(num) {
            return (num < 10 ? '0' : '') + num;
        },

        /**
         * Get custom booking label from schedule config, with global fallback.
         * @param {string} form - 'singular' or 'plural'
         */
        getBookingLabel: function(form) {
            var schedules = state.config.schedules || [];
            for (var i = 0; i < schedules.length; i++) {
                var s = schedules[i];
                if (form === 'singular' && s.bookingLabelSingular) return s.bookingLabelSingular;
                if (form === 'plural' && s.bookingLabelPlural) return s.bookingLabelPlural;
            }
            return form === 'singular' ? ffcAudience.strings.booking : ffcAudience.strings.bookings;
        },

        /**
         * Build a map of audienceId -> parentName from hierarchical config.
         */
        buildParentNameMap: function() {
            var map = {};
            function walk(nodes) {
                (nodes || []).forEach(function(node) {
                    if (!node.children || node.children.length === 0) return;
                    node.children.forEach(function(child) {
                        map[parseInt(child.id)] = node.name;
                    });
                    walk(node.children);
                });
            }
            walk(state.config.audiences || []);
            return map;
        },

        /**
         * Format audience name based on badge format setting.
         * 'parent_name' mode: "Parent: Child" for children, plain name for root.
         */
        formatAudienceName: function(audience, parentNameMap) {
            if (state.config.audienceBadgeFormat !== 'parent_name') {
                return audience.name;
            }
            var parentName = parentNameMap[parseInt(audience.id)];
            return parentName ? parentName + ': ' + audience.name : audience.name;
        },

        /**
         * Collapse audiences for display: when a parent + ALL its children
         * are present, show only the parent (visual only).
         */
        collapseParentAudiences: function(bookingAudiences) {
            if (!bookingAudiences || bookingAudiences.length === 0) return bookingAudiences;

            var ids = bookingAudiences.map(function(a) { return parseInt(a.id); });
            var removeIds = [];

            // Recursively collect all descendant IDs of a node
            function allDescIds(node) {
                var result = [];
                if (!node.children || node.children.length === 0) return result;
                node.children.forEach(function(c) {
                    result.push(parseInt(c.id));
                    result = result.concat(allDescIds(c));
                });
                return result;
            }

            function walk(nodes) {
                (nodes || []).forEach(function(node) {
                    if (!node.children || node.children.length === 0) return;
                    var parentId = parseInt(node.id);
                    if (ids.indexOf(parentId) === -1) { walk(node.children); return; }

                    var descIds = allDescIds(node);
                    var allPresent = descIds.every(function(did) { return ids.indexOf(did) !== -1; });
                    if (allPresent) {
                        removeIds = removeIds.concat(descIds);
                    } else {
                        walk(node.children);
                    }
                });
            }
            walk(state.config.audiences || []);

            if (removeIds.length === 0) return bookingAudiences;

            return bookingAudiences.filter(function(a) {
                return removeIds.indexOf(parseInt(a.id)) === -1;
            });
        },

        /**
         * Get environment color by ID
         */
        getEnvironmentColor: function(envId) {
            envId = parseInt(envId);
            var schedules = state.config.schedules || [];
            for (var i = 0; i < schedules.length; i++) {
                var envs = schedules[i].environments || [];
                for (var j = 0; j < envs.length; j++) {
                    if (parseInt(envs[j].id) === envId) {
                        return envs[j].color || '#3788d8';
                    }
                }
            }
            return '#3788d8';
        },

        /**
         * Get the environment label for a booking (finds the schedule that owns this environment)
         */
        getEnvironmentLabelForBooking: function(booking) {
            var envId = parseInt(booking.environment_id);
            var schedules = state.config.schedules || [];
            for (var i = 0; i < schedules.length; i++) {
                var envs = schedules[i].environments || [];
                for (var j = 0; j < envs.length; j++) {
                    if (parseInt(envs[j].id) === envId) {
                        return schedules[i].environmentLabel || (ffcAudience.strings || {}).environmentLabel || 'Environment';
                    }
                }
            }
            return (ffcAudience.strings || {}).environmentLabel || 'Environment';
        },

        /**
         * Escape HTML
         */
        escapeHtml: function(str) {
            if (!str) return '';
            return str.replace(/&/g, '&amp;')
                      .replace(/</g, '&lt;')
                      .replace(/>/g, '&gt;')
                      .replace(/"/g, '&quot;')
                      .replace(/'/g, '&#39;');
        }
    };

    // Initialize on document ready
    $(document).ready(api.init);

})(jQuery);
