/**
 * Check-in terminal page (#2744, #2745).
 *
 * Starts on "Waiting for pairing". The operator holds the pairing QR code to
 * the terminal's reader, which types the code into the focused field and
 * presses Enter, or someone types it. The terminal sends the code with an id
 * it generated for itself once, and keeps the token the site returns.
 *
 * Wrong codes are counted on the site; a lock is answered with HTTP 429 and
 * the seconds to wait, which the page counts down and remembers across a
 * reload.
 *
 * Once paired, the reader types each presence code into the reading field
 * and the site answers with the result (#2745), which the page writes into
 * a status region (recorded) or an alert region (refused) and clears a few
 * seconds later. An operator code opens the operator menu instead, which
 * locks again after two minutes unused.
 *
 * Offline, the page checks the code itself with `FFCPresenceVerifier`, the
 * site's keys from the pairing and the revocation list it refreshes every
 * ten minutes, and keeps the reading in a queue with the moment it read it.
 * The queue is sent every 30 seconds, when the connection returns, and from
 * the operator menu; the site checks every reading again as of its own
 * moment, so the queue decides nothing on its own.
 *
 * Exposes window.FFCTerminal; the pure helpers are there for the tests.
 */
(function (root) {
    'use strict';

    var DEVICE_KEY = 'ffcTerminalDevice';
    var STATE_KEY = 'ffcTerminal';
    var LOCK_KEY = 'ffcTerminalLock';
    var ID_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    var OPERATOR_PREFIX = 'FFO1';
    var RESULT_MS = 5000;
    var UNLOCK_MS = 30000;
    var MENU_MS = 120000;
    var EXPIRY_CHECK_MS = 30000;
    var QUEUE_KEY = 'ffcTerminalQueue';
    var REVOKED_KEY = 'ffcTerminalRevoked';
    var SYNC_MS = 30000;
    var REVOCATIONS_MS = 600000;
    var SYNC_BATCH = 50;
    var QUEUE_MAX = 2000;
    var CAMERA_SCAN_MS = 250;
    var CAMERA_REPEAT_MS = 5000;

    /**
     * A storage that may refuse: a private window or blocked site data.
     *
     * @param {Storage|null} storage Storage.
     * @return {{get: function(string): string|null, set: function(string, string): boolean, remove: function(string): void}}
     */
    function safeStorage(storage) {
        return {
            get: function (key) {
                try {
                    return storage ? storage.getItem(key) : null;
                } catch (e) {
                    return null;
                }
            },
            set: function (key, value) {
                try {
                    storage.setItem(key, value);
                    return storage.getItem(key) === value;
                } catch (e) {
                    return false;
                }
            },
            remove: function (key) {
                try {
                    storage.removeItem(key);
                } catch (e) {
                    // Nothing to remove from a storage that refuses.
                }
            }
        };
    }

    /**
     * A random device id the site keys its lockout on.
     *
     * @param {Crypto} crypto WebCrypto.
     * @return {string} 32 characters of [A-Za-z0-9_-].
     */
    function newDeviceId(crypto) {
        var bytes = new Uint8Array(32);
        crypto.getRandomValues(bytes);
        var out = '';
        for (var i = 0; i < bytes.length; i++) {
            out += ID_ALPHABET[bytes[i] & 63];
        }
        return out;
    }

    /**
     * A pairing code as read or typed: what is left once a reader's stray
     * characters are dropped.
     *
     * @param {string} raw Raw text.
     * @return {string}
     */
    function cleanCode(raw) {
        return String(raw || '').toUpperCase().replace(/[^A-Z0-9-]/g, '').slice(0, 32);
    }

    /**
     * A code as the reader typed it, minus the spaces and line end around
     * it. Base45 never ends in a space, so trimming cannot cut a code.
     *
     * @param {string} raw Raw text.
     * @return {string}
     */
    function cleanReading(raw) {
        return String(raw || '').trim().toUpperCase();
    }

    /**
     * Seconds as m:ss.
     *
     * @param {number} seconds Seconds.
     * @return {string}
     */
    function clock(seconds) {
        var s = Math.max(0, Math.ceil(seconds));
        var m = Math.floor(s / 60);
        var r = s % 60;
        return m + ':' + (r < 10 ? '0' : '') + r;
    }

    /**
     * A wait as h:mm:ss, or m:ss under an hour.
     *
     * @param {number} seconds Seconds.
     * @return {string}
     */
    function duration(seconds) {
        var s = Math.max(0, Math.ceil(seconds));
        if (s < 3600) {
            return clock(s);
        }
        var h = Math.floor(s / 3600);
        var rest = clock(s % 3600);
        return h + ':' + (rest.length < 5 ? '0' : '') + rest;
    }

    /**
     * A moment as the terminal's clock shows it, HH:MM in the device's time.
     *
     * @param {number} ms Milliseconds since the epoch.
     * @return {string}
     */
    function wallClock(ms) {
        var date = new Date(ms);
        var h = date.getHours();
        var m = date.getMinutes();
        return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
    }

    /**
     * Fill a translated pattern: %s / %d in order, or %1$s, %2$d by place.
     *
     * @param {string} pattern Pattern.
     * @param {Array} values Values.
     * @return {string}
     */
    function fill(pattern, values) {
        var i = 0;
        return String(pattern || '').replace(/%(\d+\$)?[sd]/g, function (match, place) {
            var index = place ? parseInt(place, 10) - 1 : i++;
            return undefined === values[index] ? '' : String(values[index]);
        });
    }

    /**
     * The stored pairing, if it is still in force.
     *
     * @param {string|null} json Stored JSON.
     * @param {number} now Unix time.
     * @return {object|null}
     */
    function readState(json, now) {
        if (!json) {
            return null;
        }
        try {
            var state = JSON.parse(json);
            if (state && typeof state.token === 'string' && /^[a-f0-9]{64}$/.test(state.token) && Number(state.expires_at) > now) {
                return state;
            }
        } catch (e) {
            return null;
        }
        return null;
    }

    /**
     * What the terminal is checking in at a moment (#2746): the event whose
     * window holds that moment, preferring one an operator opened, and the
     * next one to open.
     *
     * @param {Array<object>} schedule The site's schedule, soonest first.
     * @param {number} at Unix time.
     * @return {{current: object|null, next: object|null}}
     */
    function whatNow(schedule, at) {
        var current = null;
        var next = null;
        (schedule || []).forEach(function (entry) {
            if (entry.opens <= at && at < entry.closes) {
                if (!current || (entry.open && !current.open)) {
                    current = entry;
                }
            } else if (entry.opens > at && !next) {
                next = entry;
            }
        });
        return { current: current, next: next };
    }

    /**
     * Start the page.
     *
     * @param {HTMLElement} el The page root.
     * @param {object} config `ffcTerminal`.
     * @param {object} env Clock, network, storage, crypto and timers.
     * @return {object} The page's controls, for the tests.
     */
    function start(el, config, env) {
        var strings = config.strings || {};
        var store = safeStorage(env.storage);
        var now = function () { return Math.floor(env.now() / 1000); };
        var part = function (name) { return el.querySelector('[data-ffc-terminal-part="' + name + '"]'); };
        var timer = null;

        function say(text) {
            part('status').textContent = text || '';
        }

        function fail(text) {
            var error = part('error');
            error.textContent = text || '';
            error.hidden = !text;
        }

        var screen = '';

        // One screen at a time (#2780). The tone colours the whole display:
        // green for a reading recorded, red for one refused, amber for a
        // paused pairing, the neutral dark ground otherwise.
        function show(view, tone) {
            screen = view;
            var views = el.querySelectorAll('[data-ffc-terminal-view]');
            for (var i = 0; i < views.length; i++) {
                views[i].hidden = views[i].getAttribute('data-ffc-terminal-view') !== view;
            }
            el.setAttribute('data-ffc-terminal-tone', tone || ('locked' === view ? 'warning' : 'neutral'));
            el.setAttribute('data-ffc-terminal-screen', view);
            part('foot').hidden = !('ready' === view || 'between' === view);
            if ('pairing' === view || 'locked' === view) {
                head(strings.terminalTitle, '', strings.notPaired);
            }
            if ('pairing' === view) {
                var input = part('code');
                input.value = '';
                input.focus();
            }
            tickClock();
        }

        function head(title, sub, note) {
            part('head-title').textContent = title || strings.terminalTitle || '';
            part('head-sub').textContent = sub || '';
            part('head-sub').hidden = !sub;
            part('head-note').textContent = note || '';
            part('head-note').hidden = !note;
        }

        function tickClock() {
            part('clock').textContent = 'pairing' === screen || 'locked' === screen ? '' : wallClock(env.now());
        }

        function device() {
            var id = store.get(DEVICE_KEY);
            if (id && /^[A-Za-z0-9_-]{16,64}$/.test(id)) {
                return id;
            }
            id = newDeviceId(env.crypto);
            return store.set(DEVICE_KEY, id) ? id : null;
        }

        var resultTimer = null;
        var menuTimer = null;
        var menuCode = '';

        // What the paired terminal shows between readings (#2746, #2780):
        // the event it checks in now, or the next one with a countdown to its
        // check-in, or that nothing is left.
        function plan(state) {
            return whatNow(state.schedule, now());
        }

        function counter(entry) {
            if (!entry || !entry.present && !entry.enrolled) {
                return '';
            }
            return entry.enrolled > 0
                ? fill(strings.present, [entry.present || 0, entry.enrolled])
                : fill(strings.presentOnly, [entry.present || 0]);
        }

        function showEvent(state) {
            var at = plan(state);
            if (at.current) {
                var sub = at.current.open
                    ? fill(strings.checkinUntil, [at.current.closes_label || ''])
                    : strings.notOpen;
                head(at.current.title, [at.current.place, sub].filter(Boolean).join(' · '), counter(at.current));
                return 'ready';
            }
            if (at.next) {
                head(state.label || strings.terminalTitle, '', '');
                part('next-title').textContent = at.next.title || '';
                part('next-where').textContent = [at.next.place, at.next.hours].filter(Boolean).join(' · ');
                part('next-opens').textContent = fill(strings.opensIn, [at.next.opens_time || at.next.opens_label || '']);
                part('next-countdown').textContent = duration(at.next.opens - now());
                return 'between';
            }
            head(state.label || strings.terminalTitle, state.schedule ? strings.noEvents : '', '');
            return 'ready';
        }

        function showReady(state) {
            var view = showEvent(state);
            part('label').textContent = state.label || '';
            part('label').hidden = !state.label;
            part('events').textContent = String(strings.events || '%d').replace('%d', String((state.bookings || []).length));
            part('until').textContent = String(strings.until || '%s').replace('%s', state.expires_label || '');
            show(view);
            updateQueue();
            focusReading();
            keepAwake();
            if ('ready' === view) {
                autoCamera();
            }
        }

        // A screen showing between readings follows the clock: the countdown
        // to the next check-in, and the switch to reading when it opens.
        function followPlan() {
            var state = current();
            if (!state || ('ready' !== screen && 'between' !== screen)) {
                return;
            }
            var view = showEvent(state);
            if (view !== screen) {
                showReady(state);
            }
        }

        function focusReading() {
            var input = part('reading');
            input.value = '';
            input.focus();
        }

        var resultTick = null;

        function clearResult() {
            if (resultTimer) {
                env.clearTimeout(resultTimer);
                resultTimer = null;
            }
            if (resultTick) {
                env.clearInterval(resultTick);
                resultTick = null;
            }
            part('result-ok').textContent = '';
            part('result-refused').textContent = '';
        }

        function line(parent, text) {
            if (!text) {
                return null;
            }
            var p = el.ownerDocument.createElement('p');
            p.textContent = text;
            parent.appendChild(p);
            return p;
        }

        function lineClass(p, name) {
            if (p) {
                p.className = name;
            }
            return p;
        }

        function resultTitle(body) {
            var titles = strings.titles || {};
            if ('recorded' === body.status) {
                return strings.recordedTitle;
            }
            if ('already' === body.status) {
                return strings.alreadyTitle;
            }
            if ('queued' === body.status) {
                return strings.queuedTitle;
            }
            return titles[body.code] || strings.refusedTitle;
        }

        // A reading's result fills the screen (#2780): green when recorded,
        // red when refused, with a bar counting down to the next person. The
        // reading field keeps the focus, so the next code read cuts it short.
        function showResult(body) {
            clearResult();
            var ok = 'recorded' === body.status || 'already' === body.status || 'queued' === body.status;
            var region = part(ok ? 'result-ok' : 'result-refused');
            var title = line(region, resultTitle(body));
            if (title) {
                title.className = 'ffc-terminal-result-title';
            }
            if (ok) {
                var who = line(region, body.name || '');
                if (who) {
                    who.className = 'ffc-terminal-result-name';
                }
                if (body.open) {
                    var badge = line(region, strings.openBadge);
                    if (badge) {
                        badge.className = 'ffc-terminal-chip ffc-icon-user-plus';
                        badge.setAttribute('data-ffc-terminal-part', 'result-open');
                    }
                }
                var when = body.time || wallClock(env.now());
                var detail = 'recorded' === body.status
                    ? (body.open ? strings.openDetail : strings.recordedDetail)
                    : (body.message || '');
                lineClass(line(region, [when, detail].filter(Boolean).join(' · ')), 'ffc-terminal-result-detail');
            } else {
                lineClass(line(region, body.message || strings.failed), 'ffc-terminal-result-detail');
                lineClass(line(region, strings.help), 'ffc-terminal-result-help');
            }
            var tone = !ok ? 'refused' : ('queued' === body.status ? 'saved' : 'ok');
            show('result', tone);
            var started = env.now();
            var bar = part('result-bar');
            var fresh = bar.cloneNode(false);
            bar.parentNode.replaceChild(fresh, bar);
            var wait = function () {
                var left = Math.max(0, RESULT_MS - (env.now() - started));
                part('result-wait').textContent = fill(strings.nextPerson, [Math.ceil(left / 1000)]);
            };
            wait();
            resultTick = env.setInterval(wait, 250);
            resultTimer = env.setTimeout(function () {
                clearResult();
                var state = current();
                if (state && 'result' === screen) {
                    showReady(state);
                }
            }, RESULT_MS);
        }

        function expire() {
            if (camera && camera.stream) {
                stopCamera();
            }
            store.remove(STATE_KEY);
            store.remove(QUEUE_KEY);
            store.remove(REVOKED_KEY);
            closeMenu();
            wakeNote('');
            releaseAwake();
            fail(strings.expired);
            show('pairing');
        }

        function current() {
            return readState(store.get(STATE_KEY), now());
        }

        // Keep the screen on (#2775) ----------------------------------------
        //
        // While the terminal is paired and has an event now or next, it asks
        // the browser to keep the screen on, so the tablet does not dim at
        // the entrance. The browser drops the lock whenever the page is
        // hidden, so it is asked again when the page shows. Without the API,
        // or when the browser refuses, nothing else changes: the operator
        // menu says the screen may dim.

        var awake = { sentinel: null, pending: false, note: '' };

        function wakeNote(text) {
            awake.note = text || '';
            var note = part('wake-note');
            if (note) {
                note.textContent = awake.note;
                note.hidden = !awake.note;
            }
        }

        function wantsAwake() {
            var state = current();
            if (!state) {
                return false;
            }
            if (!state.schedule) {
                return (state.bookings || []).length > 0;
            }
            var plan = whatNow(state.schedule, now());
            return !!(plan.current || plan.next);
        }

        function releaseAwake() {
            var sentinel = awake.sentinel;
            awake.sentinel = null;
            if (!sentinel) {
                return Promise.resolve(false);
            }
            return Promise.resolve(sentinel.release ? sentinel.release() : null).then(function () { return false; }, function () { return false; });
        }

        function keepAwake() {
            if (!wantsAwake()) {
                wakeNote('');
                return releaseAwake();
            }
            if (env.visible && !env.visible()) {
                return Promise.resolve(false);
            }
            if (!env.wakeLock || !env.wakeLock.request) {
                wakeNote(strings.wakeUnsupported);
                return Promise.resolve(false);
            }
            if ((awake.sentinel && !awake.sentinel.released) || awake.pending) {
                return Promise.resolve(!!awake.sentinel);
            }
            awake.pending = true;
            return Promise.resolve(env.wakeLock.request('screen')).then(function (sentinel) {
                awake.pending = false;
                awake.sentinel = sentinel || null;
                if (sentinel && sentinel.addEventListener) {
                    sentinel.addEventListener('release', function () {
                        if (awake.sentinel === sentinel) {
                            awake.sentinel = null;
                        }
                    });
                }
                wakeNote('');
                return !!sentinel;
            }, function () {
                awake.pending = false;
                wakeNote(strings.wakeRefused);
                return false;
            });
        }

        function url(path) {
            return 0 === path.indexOf('presence/') ? (config.presence || '') + path.slice(9) : config.rest + path;
        }

        function terminalRequest(method, path, body) {
            var state = current();
            if (!state) {
                expire();
                return Promise.resolve(null);
            }
            var options = {
                method: method,
                // The queue's size rides on every request, so the site can
                // say which terminal still has readings to send (#2748).
                headers: { 'X-FFC-Terminal': state.token, 'X-FFC-Terminal-Queue': String(queue().length) },
                credentials: 'omit'
            };
            if (undefined !== body) {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(body);
            }
            return env.fetch(url(path), options).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (data) {
                    if (401 === response.status) {
                        expire();
                        return null;
                    }
                    return { status: response.status, body: data || {} };
                });
            }, function () {
                return { status: 0, body: { status: 'refused', message: strings.failed } };
            });
        }

        function online() {
            return !env.online || env.online();
        }

        function read(code) {
            if (!online()) {
                return readOffline(code);
            }
            return terminalRequest('POST', 'readings', { code: code }).then(function (result) {
                if (!result) {
                    return null;
                }
                if (0 === result.status) {
                    return readOffline(code);
                }
                showResult(200 === result.status ? result.body : { status: 'refused', code: result.body.code || '', message: result.body.message || strings.failed });
                focusReading();
                return result.body;
            });
        }

        function queue() {
            try {
                var list = JSON.parse(store.get(QUEUE_KEY) || '[]');
                return Array.isArray(list) ? list : [];
            } catch (e) {
                return [];
            }
        }

        function saveQueue(list) {
            if (!list.length) {
                store.remove(QUEUE_KEY);
                return true;
            }
            return store.set(QUEUE_KEY, JSON.stringify(list));
        }

        function revoked() {
            try {
                var data = JSON.parse(store.get(REVOKED_KEY) || '{}');
                return Array.isArray(data.revoked) ? data.revoked : [];
            } catch (e) {
                return [];
            }
        }

        function day(seconds) {
            var date = new Date(seconds * 1000);
            return date.getFullYear() + '-' + (date.getMonth() + 1) + '-' + date.getDate();
        }

        function refusal(code) {
            var messages = strings.offline || {};
            return { status: 'refused', code: messages[code] ? code : 'invalid', message: messages[code] || messages.invalid || strings.failed };
        }

        function readOffline(code) {
            var state = current();
            if (!state) {
                expire();
                return Promise.resolve(null);
            }
            var at = now();
            var done = function (body) {
                showResult(body);
                focusReading();
                updateQueue();
                return body;
            };
            // Without WebCrypto (an old browser, or a page not served over
            // HTTPS) nothing can be checked here, so nothing is kept.
            if (!env.verifier || !env.subtle) {
                return Promise.resolve(done({ status: 'refused', message: strings.failed }));
            }
            return env.verifier.verify(code, { now: at, keys: state.keys || {}, revoked: revoked(), subtle: env.subtle }).then(function (reading) {
                if (!reading.ok) {
                    return done(refusal(reading.code));
                }
                if ('audience_booking' !== reading.targetType || (state.bookings || []).indexOf(reading.targetId) < 0) {
                    return done(refusal('ffc_terminal_other_event'));
                }
                var list = queue();
                var key = reading.userId + ':' + reading.targetId + ':' + day(at);
                for (var i = 0; i < list.length; i++) {
                    if (list[i].key === key) {
                        return done({ status: 'already', name: reading.name, message: strings.alreadyQueued });
                    }
                }
                if (list.length >= QUEUE_MAX) {
                    return done(refusal('full'));
                }
                list.push({ id: newDeviceId(env.crypto).slice(0, 24), code: code, at: at, key: key });
                if (!saveQueue(list)) {
                    return done({ status: 'refused', message: strings.storage });
                }
                return done({ status: 'queued', name: reading.name, message: strings.queued });
            });
        }

        var syncing = null;

        function sync() {
            if (syncing) {
                return syncing;
            }
            var list = queue();
            if (!list.length || !current()) {
                updateQueue();
                return Promise.resolve(0);
            }
            if (!online()) {
                updateQueue();
                return Promise.resolve(-1);
            }
            var batch = list.slice(0, SYNC_BATCH);
            syncing = terminalRequest('POST', 'sync', {
                readings: batch.map(function (item) { return { id: item.id, code: item.code, at: item.at }; })
            }).then(function (result) {
                syncing = null;
                if (!result || 200 !== result.status) {
                    updateQueue();
                    return -1;
                }
                var answered = result.body.results || {};
                var rest = queue().filter(function (item) { return !Object.prototype.hasOwnProperty.call(answered, item.id); });
                saveQueue(rest);
                updateQueue();
                var sent = list.length - rest.length;
                return rest.length && sent > 0 ? sync().then(function (more) { return sent + Math.max(0, more); }) : sent;
            });
            return syncing;
        }

        function updateQueue() {
            var count = queue().length;
            var row = part('queue');
            if (row) {
                row.textContent = String(strings.queue || '%d').replace('%d', String(count));
            }
            // The footer says whether the terminal reaches the site and what
            // it still holds (#2780).
            var conn = part('conn');
            if (conn) {
                var up = online();
                var text = up
                    ? (count ? fill(strings.connQueue, [count]) : strings.connIdle)
                    : fill(strings.connOffline, [count]);
                if (camera && camera.stream) {
                    text += ' · ' + strings.connCamera;
                }
                conn.textContent = text;
                conn.className = 'ffc-terminal-conn ' + (up ? 'is-online' : 'is-offline');
            }
            return count;
        }

        function refreshRevocations() {
            if (!current() || !online()) {
                return Promise.resolve(false);
            }
            return terminalRequest('GET', 'presence/revocations').then(function (result) {
                if (!result || 200 !== result.status) {
                    return false;
                }
                store.set(REVOKED_KEY, JSON.stringify({ revoked: result.body.revoked || [], generated: result.body.generated || now() }));
                var state = current();
                if (state && result.body.keys) {
                    state.keys = result.body.keys;
                    store.set(STATE_KEY, JSON.stringify(state));
                }
                return true;
            });
        }

        function refreshSchedule() {
            if (!current() || !online()) {
                return Promise.resolve(false);
            }
            return terminalRequest('GET', 'self').then(function (result) {
                var state = current();
                if (!result || 200 !== result.status || !state) {
                    return false;
                }
                state.label = result.body.label || '';
                state.bookings = result.body.bookings || [];
                state.schedule = result.body.schedule || [];
                if (result.body.expires_at) {
                    state.expires_at = result.body.expires_at;
                    state.expires_label = result.body.expires_label || '';
                }
                store.set(STATE_KEY, JSON.stringify(state));
                part('label').textContent = state.label;
                part('label').hidden = !state.label;
                part('events').textContent = String(strings.events || '%d').replace('%d', String(state.bookings.length));
                part('until').textContent = String(strings.until || '%s').replace('%s', state.expires_label || '');
                followPlan();
                return true;
            });
        }

        function armMenu() {
            if (menuTimer) {
                env.clearTimeout(menuTimer);
            }
            menuTimer = env.setTimeout(closeMenu, MENU_MS);
        }

        function closeMenu() {
            if (menuTimer) {
                env.clearTimeout(menuTimer);
                menuTimer = null;
            }
            menuCode = '';
            part('menu-confirm').hidden = true;
            part('menu-ask').hidden = false;
            var state = current();
            if (state && !el.querySelector('[data-ffc-terminal-view="menu"]').hidden) {
                showReady(state);
            }
        }

        // Device check (#2747) ------------------------------------------------
        //
        // What this browser offers the terminal, shown in the operator menu so
        // a new tablet can be tried and reported without developer tools. It
        // reads the capabilities themselves, never infers them from the
        // browser's name; the name is shown only so the report says which
        // browser it was.

        function deviceCheck() {
            var list = part('device-check');
            if (!list) {
                return Promise.resolve(null);
            }
            var check = strings.check || {};
            return cameraSupport().then(function (support) {
                var camera = 'ok' === support
                    ? (config.camera ? check.cameraOn : check.cameraOff)
                    : ('insecure' === support ? check.cameraInsecure : check.cameraUnsupported);
                var wake = !env.wakeLock || !env.wakeLock.request
                    ? check.wakeUnsupported
                    : (awake.sentinel ? check.wakeActive : check.wakeAvailable);
                var rows = [
                    [check.secureLabel, env.secure ? check.yes : check.no],
                    [check.cameraLabel, camera],
                    [check.wakeLabel, wake],
                    [check.browserLabel, env.userAgent || '']
                ];
                list.textContent = '';
                rows.forEach(function (row) {
                    var dt = el.ownerDocument.createElement('dt');
                    var dd = el.ownerDocument.createElement('dd');
                    dt.textContent = row[0] || '';
                    dd.textContent = row[1] || '';
                    list.appendChild(dt);
                    list.appendChild(dd);
                });
                return rows;
            });
        }

        // The operator area (#2780): the lock in the footer asks for the
        // operator QR code, and goes back to reading on its own after 30 s.
        // An operator code read on any screen opens the menu directly.
        var unlockTimer = null;

        function stopUnlockWait() {
            if (unlockTimer) {
                env.clearInterval(unlockTimer);
                unlockTimer = null;
            }
        }

        function askUnlock() {
            stopUnlockWait();
            clearResult();
            var started = env.now();
            var wait = function () {
                var left = UNLOCK_MS - (env.now() - started);
                if (left <= 0) {
                    leaveUnlock();
                    return;
                }
                part('unlock-wait').textContent = fill(strings.unlockWait, [Math.ceil(left / 1000)]);
            };
            show('unlock');
            wait();
            unlockTimer = env.setInterval(wait, 1000);
            focusReading();
        }

        function leaveUnlock() {
            stopUnlockWait();
            var state = current();
            if (state && 'unlock' === screen) {
                showReady(state);
            }
        }

        function unlock(code) {
            return terminalRequest('POST', 'unlock', { code: code }).then(function (result) {
                if (!result) {
                    return null;
                }
                if (200 !== result.status) {
                    stopUnlockWait();
                    showResult({ status: 'refused', code: result.body.code || '', message: result.body.message || strings.failed });
                    focusReading();
                    return null;
                }
                clearResult();
                stopUnlockWait();
                menuCode = code;
                part('operator').textContent = String(strings.operator || '%s').replace('%s', result.body.name || '');
                updateQueue();
                deviceCheck();
                show('menu');
                part('menu-close').focus();
                armMenu();
                return result.body;
            });
        }

        function endSelf() {
            fail('');
            var code = menuCode;
            return sync().then(function () {
                return terminalRequest('DELETE', 'self', { code: code });
            }).then(function (result) {
                if (!result) {
                    return false;
                }
                if (200 !== result.status) {
                    fail(result.body.message || strings.failed);
                    armMenu();
                    return false;
                }
                store.remove(STATE_KEY);
                store.remove(QUEUE_KEY);
                store.remove(REVOKED_KEY);
                closeMenu();
                wakeNote('');
                releaseAwake();
                say(strings.ended);
                show('pairing');
                return true;
            });
        }

        function lockUntil(until) {
            if (timer) {
                env.clearInterval(timer);
                timer = null;
            }
            var tick = function () {
                var left = until - now();
                if (left <= 0) {
                    env.clearInterval(timer);
                    timer = null;
                    store.remove(LOCK_KEY);
                    say('');
                    show('pairing');
                    return;
                }
                part('countdown').textContent = clock(left);
            };
            show('locked');
            tick();
            if (until > now()) {
                timer = env.setInterval(tick, 1000);
            }
        }

        function pair(code) {
            var id = device();
            if (!id) {
                fail(strings.storage);
                return Promise.resolve(null);
            }
            fail('');
            say(strings.pairing);
            return env.fetch(config.rest + 'pair', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'omit',
                body: JSON.stringify({ code: code, device: id })
            }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (body) {
                    return { status: response.status, body: body || {} };
                });
            }).then(function (result) {
                if (201 === result.status && result.body.token) {
                    var state = {
                        token: result.body.token,
                        terminal_id: result.body.terminal_id,
                        label: result.body.label || '',
                        bookings: result.body.bookings || [],
                        expires_at: result.body.expires_at,
                        expires_label: result.body.expires_label || '',
                        keys: result.body.keys || {}
                    };
                    if (!store.set(STATE_KEY, JSON.stringify(state))) {
                        say('');
                        fail(strings.storage);
                        return null;
                    }
                    say(strings.paired);
                    showReady(state);
                    refreshRevocations();
                    refreshSchedule();
                    return state;
                }
                say('');
                if (429 === result.status) {
                    var until = now() + Math.max(1, Number(result.body.retry_after) || 60);
                    store.set(LOCK_KEY, String(until));
                    lockUntil(until);
                    return null;
                }
                fail(result.body.message || strings.failed);
                show('pairing');
                return null;
            }, function () {
                say('');
                fail(strings.failed);
                return null;
            });
        }

        part('form').addEventListener('submit', function (event) {
            event.preventDefault();
            var code = cleanCode(part('code').value);
            if (code) {
                pair(code);
            }
        });

        function take(raw) {
            var code = cleanReading(raw);
            if (!code) {
                return null;
            }
            return 0 === code.indexOf(OPERATOR_PREFIX) ? unlock(code) : read(code);
        }

        part('reading-form').addEventListener('submit', function (event) {
            event.preventDefault();
            var raw = part('reading').value;
            part('reading').value = '';
            take(raw);
        });

        // Camera (#2747) ------------------------------------------------------
        //
        // Only where the browser reads QR codes by itself (BarcodeDetector),
        // in a secure context, and where the site allows it. The decision is
        // made from what the browser offers, never from its name.

        var camera = { stream: null, detector: null, timer: null, last: '', lastAt: 0, tried: false };
        var cameraStrings = strings.camera || {};

        function cameraNote(text) {
            part('camera-note').textContent = text || '';
        }

        function cameraSupport() {
            if (!env.secure) {
                return Promise.resolve('insecure');
            }
            if (!env.BarcodeDetector || !env.mediaDevices || !env.mediaDevices.getUserMedia) {
                return Promise.resolve('unsupported');
            }
            var formats = env.BarcodeDetector.getSupportedFormats ? env.BarcodeDetector.getSupportedFormats() : Promise.resolve(['qr_code']);
            return Promise.resolve(formats).then(function (list) {
                return (list || []).indexOf('qr_code') >= 0 ? 'ok' : 'unsupported';
            }, function () {
                return 'unsupported';
            });
        }

        function cameraLayout(on) {
            part('viewfinder').hidden = !on;
            part('art').hidden = on;
            part('camera-tips').hidden = !on;
            part('ready-title').textContent = on ? strings.cameraTitle : strings.readyTitle;
            part('ready-lead').textContent = on ? strings.cameraLead : strings.readyLead;
            el.querySelector('.ffc-terminal-ready').classList.toggle('has-camera', on);
            updateQueue();
        }

        function stopCamera() {
            if (camera.timer) {
                env.clearTimeout(camera.timer);
                camera.timer = null;
            }
            if (camera.stream) {
                camera.stream.getTracks().forEach(function (track) { track.stop(); });
                camera.stream = null;
            }
            var video = part('camera-video');
            video.srcObject = null;
            cameraLayout(false);
            part('camera-stop').hidden = true;
            part('camera-start').hidden = false;
            cameraNote(cameraStrings.ready);
        }

        function scan() {
            camera.timer = null;
            if (!camera.stream || !camera.detector) {
                return Promise.resolve(null);
            }
            return Promise.resolve(camera.detector.detect(part('camera-video'))).then(function (codes) {
                var raw = codes && codes.length ? String(codes[0].rawValue || '') : '';
                var at = env.now();
                var taken = null;
                if (raw && (raw !== camera.last || at - camera.lastAt >= CAMERA_REPEAT_MS)) {
                    camera.last = raw;
                    camera.lastAt = at;
                    taken = take(raw);
                }
                return taken;
            }, function () {
                return null;
            }).then(function (taken) {
                if (camera.stream) {
                    camera.timer = env.setTimeout(scan, CAMERA_SCAN_MS);
                }
                return taken;
            });
        }

        function startCamera() {
            part('camera-start').disabled = true;
            return env.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false }).then(function (stream) {
                camera.stream = stream;
                camera.detector = new env.BarcodeDetector({ formats: ['qr_code'] });
                var video = part('camera-video');
                video.srcObject = stream;
                cameraLayout(true);
                part('camera-start').hidden = true;
                part('camera-start').disabled = false;
                part('camera-stop').hidden = false;
                cameraNote(cameraStrings.reading);
                return Promise.resolve(video.play ? video.play() : null).catch(function () { return null; }).then(function () {
                    camera.timer = env.setTimeout(scan, CAMERA_SCAN_MS);
                    return true;
                });
            }, function (error) {
                part('camera-start').disabled = false;
                cameraNote(error && 'NotAllowedError' === error.name ? cameraStrings.denied : cameraStrings.failed);
                return false;
            });
        }

        // With the camera allowed and supported, the ready screen reads with
        // it from the start (#2780); the button stays for a refused or failed
        // start, which the browser only retries on a gesture.
        function autoCamera() {
            if (!config.camera || camera.stream || camera.tried) {
                return Promise.resolve(false);
            }
            return cameraReady.then(function (support) {
                if ('ok' !== support || camera.stream || camera.tried || 'ready' !== screen) {
                    return false;
                }
                camera.tried = true;
                return startCamera();
            });
        }

        var cameraReady = Promise.resolve(null);
        if (config.camera) {
            part('camera').hidden = false;
            part('camera-start').addEventListener('click', startCamera);
            part('camera-stop').addEventListener('click', stopCamera);
            cameraReady = cameraSupport().then(function (support) {
                part('camera-start').disabled = 'ok' !== support;
                cameraNote('ok' === support ? cameraStrings.ready : cameraStrings[support]);
                return support;
            });
        }

        part('menu-close').addEventListener('click', closeMenu);
        part('lock').addEventListener('click', askUnlock);
        part('unlock-cancel').addEventListener('click', leaveUnlock);
        part('menu-end').addEventListener('click', function () {
            part('menu-ask').hidden = true;
            part('menu-confirm').hidden = false;
            part('menu-keep').focus();
            armMenu();
        });
        part('menu-keep').addEventListener('click', function () {
            part('menu-confirm').hidden = true;
            part('menu-ask').hidden = false;
            part('menu-end').focus();
            armMenu();
        });
        part('menu-confirm-end').addEventListener('click', endSelf);
        part('menu-sync').addEventListener('click', function () {
            armMenu();
            sync().then(function (sent) {
                say(sent < 0 ? strings.failed : String(strings.sent || '%d').replace('%d', String(Math.max(0, sent))));
            });
        });

        // The pairing ends on its own two hours after the last event; the
        // page notices without waiting for the next reading.
        env.setInterval(function () {
            if (store.get(STATE_KEY) && !current()) {
                expire();
            }
        }, EXPIRY_CHECK_MS);

        env.setInterval(sync, SYNC_MS);
        // The clock in the header, and the countdown to the next check-in.
        env.setInterval(function () {
            tickClock();
            if ('between' === screen) {
                followPlan();
            }
        }, 1000);
        // The schedule follows a change made in the app or the admin, and
        // the event shown follows the clock between two answers.
        env.setInterval(function () {
            refreshSchedule();
            followPlan();
            keepAwake();
        }, SYNC_MS);
        if (env.onVisible) {
            env.onVisible(keepAwake);
        }
        env.setInterval(refreshRevocations, REVOCATIONS_MS);
        if (env.onOnline) {
            env.onOnline(function () {
                sync();
                refreshRevocations();
                refreshSchedule();
            });
        }

        var state = current();
        if (state) {
            showReady(state);
            refreshRevocations();
            refreshSchedule();
            sync();
        } else {
            if (store.get(STATE_KEY)) {
                store.remove(STATE_KEY);
                fail(strings.expired);
            }
            var locked = Number(store.get(LOCK_KEY)) || 0;
            if (locked > now()) {
                lockUntil(locked);
            } else {
                store.remove(LOCK_KEY);
                show('pairing');
            }
        }

        return { pair: pair, read: read, unlock: unlock, endSelf: endSelf, closeMenu: closeMenu, state: current, sync: sync, queue: queue, refreshRevocations: refreshRevocations, refreshSchedule: refreshSchedule, keepAwake: keepAwake, deviceCheck: deviceCheck, askUnlock: askUnlock, screen: function () { return screen; }, camera: { ready: cameraReady, start: startCamera, stop: stopCamera, scan: scan } };
    }

    root.FFCTerminal = {
        cleanCode: cleanCode,
        cleanReading: cleanReading,
        clock: clock,
        duration: duration,
        fill: fill,
        newDeviceId: newDeviceId,
        wallClock: wallClock,
        readState: readState,
        start: start,
        whatNow: whatNow
    };

    if (root.document && root.ffcTerminal) {
        var boot = function () {
            var el = root.document.querySelector('[data-ffc-terminal]');
            if (!el) {
                return;
            }
            start(el, root.ffcTerminal, {
                now: function () { return Date.now(); },
                fetch: root.fetch.bind(root),
                storage: (function () { try { return root.localStorage; } catch (e) { return null; } })(),
                crypto: root.crypto,
                setInterval: root.setInterval.bind(root),
                clearInterval: root.clearInterval.bind(root),
                setTimeout: root.setTimeout.bind(root),
                clearTimeout: root.clearTimeout.bind(root),
                subtle: root.crypto && root.crypto.subtle,
                verifier: root.FFCPresenceVerifier,
                online: function () { return false !== root.navigator.onLine; },
                secure: !!root.isSecureContext,
                BarcodeDetector: root.BarcodeDetector,
                mediaDevices: root.navigator.mediaDevices,
                onOnline: function (fn) { root.addEventListener('online', fn); },
                wakeLock: root.navigator.wakeLock,
                userAgent: root.navigator.userAgent,
                visible: function () { return 'visible' === root.document.visibilityState; },
                onVisible: function (fn) {
                    root.document.addEventListener('visibilitychange', function () {
                        if ('visible' === root.document.visibilityState) {
                            fn();
                        }
                    });
                }
            });
        };
        if ('loading' === root.document.readyState) {
            root.document.addEventListener('DOMContentLoaded', boot);
        } else {
            boot();
        }
    }
}(typeof window !== 'undefined' ? window : this));
