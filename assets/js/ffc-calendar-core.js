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
            holidays: {}, // { 'YYYY-MM-DD': 'Holiday Name' }

            // Strings
            strings: {
                months: ['January', 'February', 'March', 'April', 'May', 'June',
                    'July', 'August', 'September', 'October', 'November', 'December'],
                weekdays: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
                today: 'Today',
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
            html += '<button type="button" class="ffc-nav-btn ffc-prev-month">&lsaquo;</button>';
            html += '<h2 class="ffc-current-month"></h2>';
            html += '<button type="button" class="ffc-nav-btn ffc-next-month">&rsaquo;</button>';
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
                    if (this.options.disabledDays.indexOf(weekday) !== -1) {
                        classes.push('ffc-closed');
                        classes.push('ffc-disabled');
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

                    // Build day HTML
                    html += '<div class="' + classes.join(' ') + '" data-date="' + dateStr + '">';
                    html += '<span class="ffc-day-number">' + cellDay + '</span>';

                    // Custom content from callback
                    html += '<div class="ffc-day-content">';
                    if (typeof this.options.getDayContent === 'function' && !isOtherMonth) {
                        html += this.options.getDayContent(dateStr, cellDate, isHoliday);
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
         */
        goToDate: function(date) {
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
            this.$days.find('.ffc-day').removeClass('ffc-selected');
            this.$days.find('.ffc-day[data-date="' + dateStr + '"]').addClass('ffc-selected');
        },

        /**
         * Clear selection
         */
        clearSelection: function() {
            this.selectedDate = null;
            this.$days.find('.ffc-day').removeClass('ffc-selected');
        },

        /**
         * Update options
         */
        setOptions: function(newOptions) {
            this.options = $.extend(this.options, newOptions);
            this.renderDays();
        },

        /**
         * Update holidays
         */
        setHolidays: function(holidays) {
            this.options.holidays = holidays;
            this.renderDays();
        },

        /**
         * Update disabled days
         */
        setDisabledDays: function(days) {
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
         */
        getCurrentMonth: function() {
            return {
                year: this.currentDate.getFullYear(),
                month: this.currentDate.getMonth() + 1
            };
        },

        /**
         * Get filters container (for extending)
         */
        getFiltersContainer: function() {
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
