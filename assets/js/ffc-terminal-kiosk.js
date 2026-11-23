/**
 * Check-in terminal page (#2744).
 *
 * Starts on "Waiting for pairing". The operator holds the pairing QR code to
 * the terminal's reader, which types the code into the focused field and
 * presses Enter, or someone types it. The terminal sends the code with an id
 * it generated for itself once, and keeps the token the site returns.
 *
 * Wrong codes are counted on the site; a lock is answered with HTTP 429 and
 * the seconds to wait, which the page counts down and remembers across a
 * reload. Reading presence codes arrives with #2745.
 *
 * Exposes window.FFCTerminal; the pure helpers are there for the tests.
 */
(function (root) {
    'use strict';

    var DEVICE_KEY = 'ffcTerminalDevice';
    var STATE_KEY = 'ffcTerminal';
    var LOCK_KEY = 'ffcTerminalLock';
    var ID_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

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
     * Start the page.
     *
     * @param {HTMLElement} el The page root.
     * @param {object} config `ffcTerminal`.
     * @param {object} env Clock, network, storage and crypto.
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

        function show(view) {
            var views = el.querySelectorAll('[data-ffc-terminal-view]');
            for (var i = 0; i < views.length; i++) {
                views[i].hidden = views[i].getAttribute('data-ffc-terminal-view') !== view;
            }
            if ('pairing' === view) {
                var input = part('code');
                input.value = '';
                input.focus();
            }
        }

        function device() {
            var id = store.get(DEVICE_KEY);
            if (id && /^[A-Za-z0-9_-]{16,64}$/.test(id)) {
                return id;
            }
            id = newDeviceId(env.crypto);
            return store.set(DEVICE_KEY, id) ? id : null;
        }

        function showPaired(state) {
            part('label').textContent = state.label || '';
            part('label').hidden = !state.label;
            part('events').textContent = String(strings.events || '%d').replace('%d', String((state.bookings || []).length));
            part('until').textContent = String(strings.until || '%s').replace('%s', state.expires_label || '');
            show('paired');
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
                part('countdown').textContent = String(strings.locked || '%s').replace('%s', clock(left));
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
                    showPaired(state);
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

        el.querySelector('[data-ffc-terminal-part="form"]').addEventListener('submit', function (event) {
            event.preventDefault();
            var code = cleanCode(part('code').value);
            if (code) {
                pair(code);
            }
        });

        var state = readState(store.get(STATE_KEY), now());
        if (state) {
            showPaired(state);
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

        return { pair: pair, state: function () { return readState(store.get(STATE_KEY), now()); } };
    }

    root.FFCTerminal = {
        cleanCode: cleanCode,
        clock: clock,
        newDeviceId: newDeviceId,
        readState: readState,
        start: start
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
                clearInterval: root.clearInterval.bind(root)
            });
        };
        if ('loading' === root.document.readyState) {
            root.document.addEventListener('DOMContentLoaded', boot);
        } else {
            boot();
        }
    }
}(typeof window !== 'undefined' ? window : this));
