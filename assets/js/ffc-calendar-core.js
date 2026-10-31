/**
 * FFC Calendar Core
 *
 * Shared calendar grid component used by both scheduling systems
 *
 * @since 4.5.0
 */

(function($) {
    'use strict';

    /**
     * Warn that a public function is deprecated and leaves in 6.45.0 (#2089).
     *
     * @param {string} name - Qualified function name.
     */
    function warnDeprecated(name) {
        if (window.console && typeof window.console.warn === 'function') {
            window.console.warn('FFC: ' + name + '() is deprecated since 6.43.0 and will be removed in 6.45.0.');
        }
    }

    /**
     * Text made safe for an attribute value.
     *
     * @param {*} text - Raw text.
     * @returns {string}
     */
    function escapeAttr(text) {
        return $('<span>').text(String(text)).html().replace(/"/g, '&quot;');
    }

    /**
     * Calendar Core Constructor
     *
     * @param {jQuery} $container - Container element
     * @param {Object} options - Configuration options
     */
    window.FFCCalendarCore = function($container, options) {
        // Validate container
        if (!$container || !$container.length) {
            console.error('FFCCalendarCore: Container element not found');
            return;
        }

        this.$container = $container;

        // Default options
        var defaults = {
            // Callbacks
            onDayClick: null,
            onMonthChange: null,
            getDayContent: null,
            getDayClasses: null,

            // Configuration
            showLegend: true,
            showFilters: false,
            showTodayButton: true,
            minDate: null, // null = no limit
            maxDate: null, // null = no limit
            disabledDays: [], // Array of weekday numbers (0=Sun, 6=Sat)
            enabledDates: [], // Custom mode (#941): allowlist of 'Y-m-d' — when non-empty, only these dates are bookable
            holidays: {}, // { 'YYYY-MM-DD': 'Holiday Name' }

            // Strings
            strings: {
                months: ['January', 'February', 'March', 'April', 'May', 'June',
                    'July', 'August', 'September', 'October', 'November', 'December'],
                weekdays: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
                today: 'Today',
                prevMonth: 'Previous month',
                nextMonth: 'Next month',
                holiday: 'Holiday',
                closed: 'Closed',
                available: 'Available',
                booked: 'Booked'
            },

            // Legend items
            legendItems: [
                { class: 'ffc-available', label: 'Available' },
                { class: 'ffc-booked', label: 'Booked' },
                { class: 'ffc-holiday', label: 'Holiday' },
                { class: 'ffc-closed', label: 'Closed' }
            ]
        };

        // Deep extend to properly merge nested objects like 'strings'.
        // legendItems is an array that should be replaced entirely, not merged by index.
        var legendOverride = options.legendItems || null;
        if (legendOverride) {
            delete options.legendItems;
        }
        this.options = $.extend(true, {}, defaults, options);
        if (legendOverride) {
            this.options.legendItems = legendOverride;
        }

        this.currentDate = new Date();
        this.selectedDate = null;
        this._lastRenderedYear = null;
        this._lastRenderedMonth = null;

        this.init();
    };

    FFCCalendarCore.prototype = {

        /**
         * Initialize the calendar
         */
        init: function() {
            this.render();
            this.bindEvents();
        },

        /**
         * Render the calendar structure
         */
        render: function() {
            var html = '<div class="ffc-calendar-core">';

            // Header
            html += '<div class="ffc-calendar-header">';
            html += '<div class="ffc-calendar-nav">';
            // The arrows are glyphs, so they carry a name; the month heading is
            // announced when it changes (#2244).
            html += '<button type="button" class="ffc-nav-btn ffc-prev-month" aria-label="' + escapeAttr(this.options.strings.prevMonth) + '"><span aria-hidden="true">&lsaquo;</span></button>';
            html += '<h2 class="ffc-current-month" aria-live="polite"></h2>';
            html += '<button type="button" class="ffc-nav-btn ffc-next-month" aria-label="' + escapeAttr(this.options.strings.nextMonth) + '"><span aria-hidden="true">&rsaquo;</span></button>';
            if (this.options.showTodayButton) {
                html += '<button type="button" class="ffc-nav-btn ffc-today-btn">' + this.options.strings.today + '</button>';
            }
            html += '</div>';

            // Filters placeholder (can be populated by extending code)
            if (this.options.showFilters) {
                html += '<div class="ffc-calendar-filters"></div>';
            }
            html += '</div>';

            // Calendar grid
            html += '<div class="ffc-calendar-grid">';

            // Weekday headers
            html += '<div class="ffc-calendar-weekdays">';
            for (var i = 0; i < 7; i++) {
                html += '<div class="ffc-weekday">' + this.options.strings.weekdays[i] + '</div>';
            }
            html += '</div>';

            // Days container
            html += '<div class="ffc-calendar-days"></div>';
            html += '</div>';

            // Legend
            if (this.options.showLegend) {
                html += '<div class="ffc-calendar-legend">';
                for (var j = 0; j < this.options.legendItems.length; j++) {
                    var item = this.options.legendItems[j];
                    html += '<div class="ffc-legend-item">';
                    html += '<span class="ffc-legend-dot ' + item.class + '"></span>';
                    html += '<span>' + item.label + '</span>';
                    html += '</div>';
                }
                html += '</div>';
            }

            html += '</div>';

            this.$container.html(html);
            this.$days = this.$container.find('.ffc-calendar-days');
            this.$month = this.$container.find('.ffc-current-month');

            this.renderDays();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            this.$container.on('click', '.ffc-prev-month', function() {
                self.prevMonth();
            });

            this.$container.on('click', '.ffc-next-month', function() {
                self.nextMonth();
            });

            this.$container.on('click', '.ffc-today-btn', function() {
                self.goToToday();
            });

            this.$container.on('click', '.ffc-day:not(.ffc-disabled):not(.ffc-other-month)', function() {
                var date = $(this).data('date');
                self.selectDate(date);

                if (typeof self.options.onDayClick === 'function') {
                    self.options.onDayClick(date, $(this));
                }
            });

            // Keyboard (#2244): Enter or Space picks the focused day, the
            // arrows move a day or a week, Home and End go to the month's
            // first and last day. One day holds the tab stop at a time.
            this.$container.on('keydown', '.ffc-day[data-ffc-focusable]', function(e) {
                var $day = $(this);
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    $day.trigger('click');
                    return;
                }
                var step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
                var $days = self.$days.find('.ffc-day[data-ffc-focusable]');
                var index = $days.index($day);
                var target = -1;
                if (typeof step === 'number') {
                    target = index + step;
                } else if (e.key === 'Home') {
                    target = 0;
                } else if (e.key === 'End') {
                    target = $days.length - 1;
                }
                if (target < 0 || target >= $days.length) {
                    return;
                }
                e.preventDefault();
                self.moveFocus($days.eq(index), $days.eq(target));
            });
        },

        /**
         * Hand the tab stop from one day to another and focus it.
         *
         * @param {jQuery} $from Day that holds the tab stop.
         * @param {jQuery} $to   Day that takes it.
         */
        moveFocus: function($from, $to) {
            $from.attr('tabindex', '-1');
            $to.attr('tabindex', '0').trigger('focus');
        },

        /**
         * Render calendar days
         */
        renderDays: function() {
            var year = this.currentDate.getFullYear();
            var month = this.currentDate.getMonth();

            // Update header
            this.$month.text(this.options.strings.months[month] + ' ' + year);

            // Get first and last day of month
            var firstDay = new Date(year, month, 1);
            var lastDay = new Date(year, month + 1, 0);
            var startDay = firstDay.getDay();
            var daysInMonth = lastDay.getDate();

            // Get previous month days
            var prevMonthLastDay = new Date(year, month, 0).getDate();

            // Build calendar HTML
            var html = '';
            var day = 1;
            var nextMonthDay = 1;
            var today = new Date();
            today.setHours(0, 0, 0, 0);

            for (var i = 0; i < 6; i++) {
                for (var j = 0; j < 7; j++) {
                    var cellDate, cellDay;
                    var classes = ['ffc-day'];
                    var isOtherMonth = false;

                    if (i === 0 && j < startDay) {
                        // Previous month
                        cellDay = prevMonthLastDay - startDay + j + 1;
                        cellDate = new Date(year, month - 1, cellDay);
                        classes.push('ffc-other-month');
                        isOtherMonth = true;
                    } else if (day > daysInMonth) {
                        // Next month
                        cellDay = nextMonthDay++;
                        cellDate = new Date(year, month + 1, cellDay);
                        classes.push('ffc-other-month');
                        isOtherMonth = true;
                    } else {
                        // Current month
                        cellDay = day++;
                        cellDate = new Date(year, month, cellDay);
                    }

                    var dateStr = this.formatDate(cellDate);

                    // Check if past
                    if (cellDate < today) {
                        classes.push('ffc-past');
                    }

                    // Check if today
                    if (cellDate.getTime() === today.getTime()) {
                        classes.push('ffc-today');
                    }

                    // Check min/max date
                    if (this.options.minDate && cellDate < this.options.minDate) {
                        classes.push('ffc-disabled');
                    }
                    if (this.options.maxDate && cellDate > this.options.maxDate) {
                        classes.push('ffc-disabled');
                    }

                    // Check disabled days (weekdays)
                    var weekday = cellDate.getDay();
                    var isClosed = false;
                    if (this.options.disabledDays.indexOf(weekday) !== -1) {
                        classes.push('ffc-closed');
                        classes.push('ffc-disabled');
                        isClosed = true;
                    }

                    // Custom mode (#941): only dates in the allowlist are bookable.
                    if (this.options.enabledDates && this.options.enabledDates.length &&
                        this.options.enabledDates.indexOf(dateStr) === -1) {
                        classes.push('ffc-closed');
                        classes.push('ffc-disabled');
                        isClosed = true;
                    }

                    // Check holidays
                    var isHoliday = this.options.holidays[dateStr];
                    if (isHoliday) {
                        classes.push('ffc-holiday');
                        classes.push('ffc-disabled');
                    }

                    // Check if selected
                    if (this.selectedDate === dateStr) {
                        classes.push('ffc-selected');
                    }

                    // Custom classes from callback
                    if (typeof this.options.getDayClasses === 'function' && !isOtherMonth) {
                        var customClasses = this.options.getDayClasses(dateStr, cellDate);
                        if (customClasses) {
                            classes = classes.concat(customClasses);
                        }
                    }

                    // Build day HTML. A day of this month is a button to a
                    // screen reader and to the keyboard, named by its full date;
                    // one that cannot be picked says so instead of hiding.
                    var a11y = '';
                    if (!isOtherMonth) {
                        var unavailable = classes.indexOf('ffc-disabled') !== -1;
                        a11y = ' role="button" data-ffc-focusable="1" tabindex="-1"' +
                            ' aria-label="' + escapeAttr(cellDay + ' ' + this.options.strings.months[cellDate.getMonth()] + ' ' + cellDate.getFullYear()) + '"' +
                            (unavailable ? ' aria-disabled="true"' : '') +
                            (this.selectedDate === dateStr ? ' aria-pressed="true"' : '');
                    }
                    html += '<div class="' + classes.join(' ') + '" data-date="' + dateStr + '"' + a11y + '>';
                    html += '<span class="ffc-day-number">' + cellDay + '</span>';

                    // Custom content from callback
                    html += '<div class="ffc-day-content">';
                    if (typeof this.options.getDayContent === 'function' && !isOtherMonth) {
                        html += this.options.getDayContent(dateStr, cellDate, isHoliday, isClosed);
                    } else if (isHoliday) {
                        html += '<span class="ffc-day-badge ffc-badge-holiday">' +
                            this.options.strings.holiday +
                            '</span>';
                    }
                    html += '</div>';

                    html += '</div>';
                }
            }

            this.$days.html(html);
            this.assignTabStop();

            // Fire onMonthChange only when year/month actually changed
            // Prevents infinite loop: onMonthChange → fetch → refresh → renderDays → onMonthChange
            if (typeof this.options.onMonthChange === 'function') {
                if (this._lastRenderedYear !== year || this._lastRenderedMonth !== month) {
                    this._lastRenderedYear = year;
                    this._lastRenderedMonth = month;
                    this.options.onMonthChange(year, month + 1);
                }
            }
        },

        /**
         * Give the month's tab stop to the selected day, else today, else the
         * first day that can be picked, else the first day of the month.
         */
        assignTabStop: function() {
            var $days = this.$days.find('.ffc-day[data-ffc-focusable]');
            var $stop = $days.filter('.ffc-selected').first();
            if (!$stop.length) { $stop = $days.filter('.ffc-today').first(); }
            if (!$stop.length) { $stop = $days.not('[aria-disabled]').first(); }
            if (!$stop.length) { $stop = $days.first(); }
            $stop.attr('tabindex', '0');
        },

        /**
         * Go to previous month
         */
        prevMonth: function() {
            this.currentDate.setMonth(this.currentDate.getMonth() - 1);
            this.renderDays();
        },

        /**
         * Go to next month
         */
        nextMonth: function() {
            this.currentDate.setMonth(this.currentDate.getMonth() + 1);
            this.renderDays();
        },

        /**
         * Go to today
         */
        goToToday: function() {
            this.currentDate = new Date();
            this.renderDays();
        },

        /**
         * Go to specific date
         *
         * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
         * @removal 6.45.0
         */
        goToDate: function(date) {
            warnDeprecated('FFCCalendarCore.goToDate');
            if (typeof date === 'string') {
                var parts = date.split('-');
                this.currentDate = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
            } else {
                this.currentDate = new Date(date);
            }
            this.renderDays();
        },

        /**
         * Select a date
         */
        selectDate: function(dateStr) {
            this.selectedDate = dateStr;
            this.$days.find('.ffc-day').removeClass('ffc-selected').removeAttr('aria-pressed');
            this.$days.find('.ffc-day[data-date="' + dateStr + '"]').addClass('ffc-selected').attr('aria-pressed', 'true');
        },

        /**
         * Clear selection
         *
         * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
         * @removal 6.45.0
         */
        clearSelection: function() {
            warnDeprecated('FFCCalendarCore.clearSelection');
            this.selectedDate = null;
            this.$days.find('.ffc-day').removeClass('ffc-selected');
        },

        /**
         * Update options
         *
         * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
         * @removal 6.45.0
         */
        setOptions: function(newOptions) {
            warnDeprecated('FFCCalendarCore.setOptions');
            this.options = $.extend(this.options, newOptions);
            this.renderDays();
        },

        /**
         * Update holidays
         *
         * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
         * @removal 6.45.0
         */
        setHolidays: function(holidays) {
            warnDeprecated('FFCCalendarCore.setHolidays');
            this.options.holidays = holidays;
            this.renderDays();
        },

        /**
         * Update disabled days
         *
         * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
         * @removal 6.45.0
         */
        setDisabledDays: function(days) {
            warnDeprecated('FFCCalendarCore.setDisabledDays');
            this.options.disabledDays = days;
            this.renderDays();
        },

        /**
         * Refresh calendar
         */
        refresh: function() {
            this.renderDays();
        },

        /**
         * Format date as YYYY-MM-DD
         */
        formatDate: function(date) {
            var year = date.getFullYear();
            var month = date.getMonth() + 1;
            var day = date.getDate();
            return year + '-' + (month < 10 ? '0' : '') + month + '-' + (day < 10 ? '0' : '') + day;
        },

        /**
         * Parse date string to Date object
         */
        parseDate: function(dateStr) {
            var parts = dateStr.split('-');
            return new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
        },

        /**
         * Get current year and month
         *
         * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
         * @removal 6.45.0
         */
        getCurrentMonth: function() {
            warnDeprecated('FFCCalendarCore.getCurrentMonth');
            return {
                year: this.currentDate.getFullYear(),
                month: this.currentDate.getMonth() + 1
            };
        },

        /**
         * Get filters container (for extending)
         *
         * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
         * @removal 6.45.0
         */
        getFiltersContainer: function() {
            warnDeprecated('FFCCalendarCore.getFiltersContainer');
            return this.$container.find('.ffc-calendar-filters');
        },

        /**
         * Destroy the calendar
         */
        destroy: function() {
            this.$container.off();
            this.$container.empty();
        }
    };

})(jQuery);
