/**
 * Calendar screen of the installable app (#2781).
 *
 * Reads the dashboard's `user/appointments` and `user/audience-bookings`
 * routes and draws one day card per booking: today highlighted, the status
 * and attendance as chips, and the next step inline (the presence code for
 * an event, the post-event form, the appointment receipt). What already
 * took place folds into one collapsed group.
 *
 * @since 6.50.0
 */
(function (root) {
    'use strict';

    function format(text) {
        var args = Array.prototype.slice.call(arguments, 1);
        var next = 0;
        return String(text).replace(/%[sd]/g, function () {
            var value = args[next++];
            return undefined === value ? '' : String(value);
        });
    }

    /**
     * An element with a class and text.
     *
     * @param {string} tag       Tag.
     * @param {string} className Class.
     * @param {string} [text]    Text.
     * @return {HTMLElement}
     */
    function withClass(tag, className, text) {
        var node = root.document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (undefined !== text) {
            node.textContent = text;
        }
        return node;
    }

    /**
     * The local calendar date of a 'Y-m-d' string, never shifted by a
     * timezone: these are wall-clock dates.
     *
     * @param {string} ymd Date.
     * @return {Date|null}
     */
    function wallDate(ymd) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(ymd || ''));
        return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : null;
    }

    /**
     * One shape for both kinds of booking.
     *
     * @param {Object} row  Route row.
     * @param {string} kind `appointment` or `audience`.
     * @return {Object|null}
     */
    function normalise(row, kind) {
        if ('cancelled' === row.status) {
            return null;
        }
        if ('appointment' === kind) {
            return {
                kind: kind,
                date: String(row.appointment_date_raw || ''),
                last: String(row.appointment_date_raw || ''),
                title: [row.calendar_title, row.session_name].filter(Boolean).join(' — '),
                when: [row.start_time && row.end_time ? row.start_time + ' – ' + row.end_time : row.start_time].filter(Boolean).join(''),
                status: row.status,
                past: !!row.is_past,
                receipt: row.receipt_url || '',
                attendance: null,
                form: null
            };
        }
        return {
            kind: kind,
            date: String(row.booking_date_raw || ''),
            last: String(row.last_date_raw || row.booking_date_raw || ''),
            title: row.description || row.schedule_name || row.environment_name || '',
            when: [row.time_label, row.environment_name].filter(Boolean).join(' · '),
            status: row.status,
            past: !!row.is_past,
            receipt: '',
            attendance: row.attendance || null,
            form: row.post_event && row.post_event.url ? row.post_event : null
        };
    }

    function Screen(node, config, deps) {
        this.node = node;
        this.config = config;
        this.fetch = deps.fetch;
        this.presenceUrl = deps.presenceUrl;
        this.items = [];
    }

    Screen.prototype.part = function (name) {
        return this.node.querySelector('[data-ffc-app-part="' + name + '"]');
    };

    Screen.prototype.say = function (text) {
        var status = this.part('status');
        if (status) {
            status.textContent = text || '';
        }
    };

    Screen.prototype.view = function (name) {
        Array.prototype.forEach.call(this.node.querySelectorAll('[data-ffc-app-view]'), function (tab) {
            tab.setAttribute('aria-selected', tab.getAttribute('data-ffc-app-view') === name ? 'true' : 'false');
        });
        Array.prototype.forEach.call(this.node.querySelectorAll('[data-ffc-app-panel]'), function (panel) {
            panel.hidden = panel.getAttribute('data-ffc-app-panel') !== name;
        });
    };

    Screen.prototype.get = function (url) {
        var self = this;
        return Promise.resolve().then(function () {
            return self.fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': self.config.nonce } });
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('request failed');
            }
            return response.json();
        });
    };

    Screen.prototype.start = function () {
        var self = this;
        var strings = self.config.strings;
        Array.prototype.forEach.call(self.node.querySelectorAll('[data-ffc-app-view]'), function (tab) {
            tab.addEventListener('click', function () {
                self.view(tab.getAttribute('data-ffc-app-view'));
            });
        });
        var fresh = self.part('new');
        if (fresh) {
            fresh.addEventListener('click', function () {
                self.view('book');
            });
        }
        self.drawBook();
        self.say(strings.loading);
        var routes = self.config.routes || {};
        var loads = [];
        if (routes.appointments) {
            loads.push(self.get(routes.appointments).then(function (data) {
                return (data && data.appointments || []).map(function (row) { return normalise(row, 'appointment'); });
            }));
        }
        if (routes.audience) {
            loads.push(self.get(routes.audience).then(function (data) {
                return (data && data.bookings || []).map(function (row) { return normalise(row, 'audience'); });
            }));
        }
        return Promise.all(loads).then(function (lists) {
            self.items = [].concat.apply([], lists).filter(Boolean);
            self.say('');
            self.draw();
        }).catch(function () {
            self.say(strings.failed);
        });
    };

    Screen.prototype.draw = function () {
        var strings = this.config.strings;
        var list = this.part('list');
        var pastList = this.part('past-list');
        var past = this.part('past');
        if (!list || !pastList || !past) {
            return;
        }
        list.textContent = '';
        pastList.textContent = '';
        var upcoming = this.items.filter(function (item) { return !item.past; }).sort(function (a, b) {
            return a.date < b.date ? -1 : (a.date > b.date ? 1 : 0);
        });
        var held = this.items.filter(function (item) { return item.past; }).sort(function (a, b) {
            return a.date > b.date ? -1 : (a.date < b.date ? 1 : 0);
        });
        var self = this;
        held.forEach(function (item) { pastList.appendChild(self.card(item)); });
        past.hidden = !held.length;
        var label = this.part('past-label');
        if (label) {
            label.textContent = format(strings.past, held.length);
        }
        upcoming.forEach(function (item) { list.appendChild(self.card(item)); });
        this.heading(upcoming[0] ? upcoming[0].date : this.config.today);
        if (!upcoming.length) {
            this.say(strings.empty);
        }
    };

    Screen.prototype.heading = function (ymd) {
        var month = this.part('month');
        var date = wallDate(ymd);
        var names = (this.config.dates && this.config.dates.months) || [];
        if (month && date) {
            month.textContent = (names[date.getMonth()] || '') + ' ' + date.getFullYear();
        }
    };

    Screen.prototype.chip = function (text, tone) {
        return withClass('span', 'ffc-app-agenda-chip ffc-app-agenda-chip-' + tone, text);
    };

    Screen.prototype.card = function (item) {
        var strings = this.config.strings;
        var dates = this.config.dates || {};
        var date = wallDate(item.date);
        var today = item.date <= this.config.today && this.config.today <= item.last;
        var card = withClass('li', today ? 'ffc-app-agenda-card ffc-app-agenda-today' : 'ffc-app-agenda-card');
        var day = withClass('div', 'ffc-app-agenda-day');
        day.appendChild(withClass('span', 'ffc-app-agenda-weekday', date ? String((dates.weekdaysShort || [])[date.getDay()] || '') : ''));
        day.appendChild(withClass('span', 'ffc-app-agenda-number', date ? String(date.getDate()) : ''));
        card.appendChild(day);

        var body = withClass('div', 'ffc-app-agenda-body');
        var chips = withClass('p', 'ffc-app-agenda-chips');
        if (today) {
            chips.appendChild(this.chip(strings.today, 'accent'));
        }
        if (item.attendance && item.attendance.met) {
            chips.appendChild(this.chip(item.attendance.detail || item.attendance.label || '', 'success'));
        } else if ('pending' === item.status) {
            chips.appendChild(this.chip(strings.pending, 'warning'));
        } else if (!item.past) {
            chips.appendChild(this.chip('audience' === item.kind ? strings.enrolled : strings.confirmed, 'info'));
        }
        body.appendChild(chips);
        body.appendChild(withClass('h3', 'ffc-app-agenda-title', item.title));
        if (item.when) {
            body.appendChild(withClass('p', 'ffc-app-agenda-when', item.when));
        }
        var actions = withClass('p', 'ffc-app-agenda-actions');
        if ('audience' === item.kind && !item.past && this.config.presence && this.presenceUrl) {
            var qr = withClass('a', 'ffc-app-agenda-action ffc-icon-qr', strings.presence);
            qr.href = this.presenceUrl;
            actions.appendChild(qr);
        }
        if (item.form) {
            var form = withClass('a', 'ffc-app-agenda-action ffc-icon-edit', format(strings.form, item.form.closes_label || ''));
            form.href = item.form.url;
            actions.appendChild(form);
        }
        if (item.receipt && !item.past) {
            var receipt = withClass('a', 'ffc-app-agenda-action ffc-icon-file', strings.receipt);
            receipt.href = item.receipt;
            actions.appendChild(receipt);
        }
        if (actions.childNodes.length) {
            body.appendChild(actions);
        }
        card.appendChild(body);
        return card;
    };

    Screen.prototype.drawBook = function () {
        var list = this.part('book');
        if (!list) {
            return;
        }
        list.textContent = '';
        var book = this.config.book || [];
        if (!book.length) {
            list.appendChild(withClass('li', 'ffc-app-agenda-book-empty', this.config.strings.noBook));
            return;
        }
        book.forEach(function (calendar) {
            var row = withClass('li', 'ffc-app-agenda-book-row');
            var link = withClass('a', 'ffc-app-agenda-book-link', calendar.title);
            link.href = calendar.url;
            link.appendChild(withClass('span', 'ffc-app-agenda-chevron ffc-icon-chevron-right'));
            row.appendChild(link);
            list.appendChild(row);
        });
    };

    /**
     * Start every Calendar screen on the page.
     *
     * @param {Object} [deps] Test seams: fetch, presenceUrl.
     * @return {Screen[]}
     */
    function boot(deps) {
        var config = root.ffcAppAgenda;
        if (!config) {
            return [];
        }
        deps = deps || {};
        var options = {
            fetch: deps.fetch || root.fetch.bind(root),
            // The app opens a screen at …/s/<id>/, so the Presence screen
            // sits beside this one; nothing here needs the app's prefix.
            presenceUrl: undefined !== deps.presenceUrl ? deps.presenceUrl : new root.URL('../presence/', root.location.href).href
        };
        return Array.prototype.map.call(root.document.querySelectorAll('[data-ffc-app-agenda]'), function (node) {
            var screen = new Screen(node, config, options);
            screen.start();
            return screen;
        });
    }

    root.FFCAppAgenda = { boot: boot, wallDate: wallDate };

    if ('loading' === root.document.readyState) {
        root.document.addEventListener('DOMContentLoaded', function () { boot(); });
    } else {
        boot();
    }
}(window));
