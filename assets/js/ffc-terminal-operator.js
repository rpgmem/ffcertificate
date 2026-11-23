/**
 * The operator's terminal screens in the app (#2744).
 *
 * One script for the three screens the template draws, chosen by the root's
 * `data-ffc-terminal-operator`:
 *
 * - `pair`: tick the events, name the terminal, generate a one-time pairing
 *   code and show it as a QR code with its countdown;
 * - `terminals`: the operator's active terminals, each with End behind an
 *   inline confirmation;
 * - `qr`: the operator's own unlock code, renewed before it expires.
 *
 * Every date arrives formatted by the site. The QR is drawn by the presence
 * screen's encoder (`FFCPresence.qrSvg`).
 *
 * Exposes window.FFCTerminalOperator; `start` takes its clock and network so
 * the tests can drive it.
 */
(function (root) {
    'use strict';

    /** Seconds before an operator code expires at which a new one is asked for. */
    var RENEW_BEFORE = 20;

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
     * Replace %s / %d, in order, and %1$s / %2$d by position, as the
     * translated strings carry them.
     *
     * @param {string} text Text.
     * @param {Array} values Values.
     * @return {string}
     */
    function fill(text, values) {
        var i = 0;
        return String(text || '').replace(/%(?:(\d+)\$)?[sd]/g, function (match, position) {
            var v = position ? values[parseInt(position, 10) - 1] : values[i++];
            return undefined === v ? '' : String(v);
        });
    }

    /**
     * Start one screen.
     *
     * @param {HTMLElement} el The screen root.
     * @param {object} config `ffcTerminalOperator`.
     * @param {object} env {now, fetch, setInterval, clearInterval, setTimeout, clearTimeout, qrSvg}.
     * @return {object} The screen's controls, for the tests.
     */
    function start(el, config, env) {
        var strings = config.strings || {};
        var mode = el.getAttribute('data-ffc-terminal-operator');
        var now = function () { return Math.floor(env.now() / 1000); };
        var part = function (name) { return el.querySelector('[data-ffc-op-part="' + name + '"]'); };
        var ticker = null;
        var renew = null;

        function say(text) {
            var status = part('status');
            if (status) {
                status.textContent = text || '';
            }
        }

        function fail(text) {
            var error = part('error');
            if (error) {
                error.textContent = text || '';
                error.hidden = !text;
            }
        }

        function request(method, path, body) {
            var options = {
                method: method,
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': config.nonce }
            };
            if (undefined !== body) {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(body);
            }
            return env.fetch(config.rest + path, options).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (data) {
                    return { ok: response.ok, status: response.status, body: data || {} };
                });
            }, function () {
                return { ok: false, status: 0, body: {} };
            });
        }

        function problem(result) {
            return (result.body && result.body.message) || strings.failed;
        }

        function drawQr(text) {
            var holder = part('qr');
            var drawn = env.qrSvg ? env.qrSvg(text) : null;
            holder.innerHTML = drawn ? drawn.svg : '';
            var svg = holder.querySelector('svg');
            if (svg) {
                svg.setAttribute('aria-label', strings.qrLabel || '');
            }
        }

        function countdown(until, template, done) {
            if (ticker) {
                env.clearInterval(ticker);
                ticker = null;
            }
            var tick = function () {
                var left = until - now();
                part('countdown').textContent = left > 0 ? fill(template, [clock(left)]) : (strings.expiredCode || '');
                if (left <= 0) {
                    env.clearInterval(ticker);
                    ticker = null;
                    if (done) {
                        done();
                    }
                }
            };
            ticker = env.setInterval(tick, 1000);
            tick();
        }

        // Pair terminal ------------------------------------------------------

        function loadEvents() {
            say(strings.loading);
            return request('GET', 'bookings').then(function (result) {
                say('');
                var list = part('events');
                list.textContent = '';
                if (!result.ok) {
                    fail(problem(result));
                    return [];
                }
                var bookings = result.body.bookings || [];
                part('events-empty').hidden = bookings.length > 0;
                bookings.forEach(function (booking) {
                    var label = el.ownerDocument.createElement('label');
                    label.className = 'ffc-terminal-operator-event';
                    var check = el.ownerDocument.createElement('input');
                    check.type = 'checkbox';
                    check.value = String(booking.id);
                    check.disabled = !!booking.full;
                    var text = el.ownerDocument.createElement('span');
                    var title = el.ownerDocument.createElement('strong');
                    title.textContent = booking.description || '';
                    text.appendChild(title);
                    var detail = el.ownerDocument.createElement('span');
                    detail.className = 'description';
                    detail.textContent = [booking.when, booking.place, booking.full ? strings.full : fill(strings.held, [booking.terminals || 0])]
                        .filter(function (v) { return !!v; }).join(' · ');
                    text.appendChild(detail);
                    label.appendChild(check);
                    label.appendChild(text);
                    list.appendChild(label);
                });
                return bookings;
            });
        }

        function chosen() {
            var boxes = part('events').querySelectorAll('input[type="checkbox"]:checked');
            return Array.prototype.map.call(boxes, function (b) { return parseInt(b.value, 10); });
        }

        function generate() {
            var ids = chosen();
            if (!ids.length) {
                fail(strings.pickEvent);
                return Promise.resolve(null);
            }
            if (ids.length > (config.maxBookings || 10)) {
                fail(fill(strings.tooMany, [config.maxBookings || 10]));
                return Promise.resolve(null);
            }
            fail('');
            say(strings.generating);
            var button = part('generate');
            button.disabled = true;
            return request('POST', 'pairings', { bookings: ids, label: part('label').value || '' }).then(function (result) {
                button.disabled = false;
                say('');
                if (!result.ok || !result.body.code) {
                    fail(problem(result));
                    return null;
                }
                part('form').hidden = true;
                part('result').hidden = false;
                part('code').textContent = result.body.code;
                drawQr(result.body.code);
                countdown(Number(result.body.expires_at) || now(), strings.codeExpires);
                return result.body;
            });
        }

        function again() {
            if (ticker) {
                env.clearInterval(ticker);
                ticker = null;
            }
            part('result').hidden = true;
            part('form').hidden = false;
            part('qr').innerHTML = '';
            part('code').textContent = '';
            return loadEvents();
        }

        // My terminals -------------------------------------------------------

        function row(terminal) {
            var fragment = part('row').content.cloneNode(true);
            var item = fragment.querySelector('li');
            var get = function (name) { return item.querySelector('[data-ffc-op-item="' + name + '"]'); };
            item.setAttribute('data-terminal-id', String(terminal.id));
            get('label').textContent = terminal.label || fill(strings.unnamed, [terminal.id]);
            get('events').textContent = fill(strings.events, [(terminal.bookings || []).length]);
            get('seen').textContent = terminal.last_seen_label ? fill(strings.seen, [terminal.last_seen_label]) : strings.neverSeen;
            get('until').textContent = fill(strings.until, [terminal.expires_label || '']);
            get('end').addEventListener('click', function () {
                get('ask').hidden = true;
                get('confirm').hidden = false;
                get('keep').focus();
            });
            get('keep').addEventListener('click', function () {
                get('confirm').hidden = true;
                get('ask').hidden = false;
                get('end').focus();
            });
            get('confirm-end').addEventListener('click', function () {
                end(terminal.id, item, get('confirm-end'));
            });
            return item;
        }

        function loadTerminals() {
            say(strings.loading);
            return request('GET', 'terminals').then(function (result) {
                say('');
                var list = part('list');
                list.textContent = '';
                if (!result.ok) {
                    fail(problem(result));
                    return [];
                }
                var terminals = result.body.terminals || [];
                part('list-empty').hidden = terminals.length > 0;
                var limits = result.body.limits || {};
                part('summary').textContent = fill(result.body.all ? strings.summaryAll : strings.summary, [terminals.length, limits.site || 0, limits.booking || 0]);
                terminals.forEach(function (terminal) {
                    list.appendChild(row(terminal));
                });
                return terminals;
            });
        }

        function end(id, item, button) {
            fail('');
            button.disabled = true;
            return request('DELETE', 'terminals/' + id).then(function (result) {
                button.disabled = false;
                if (!result.ok) {
                    fail(problem(result));
                    return false;
                }
                item.parentNode.removeChild(item);
                say(strings.ended);
                part('list-empty').hidden = part('list').children.length > 0;
                return true;
            });
        }

        // Operator QR --------------------------------------------------------

        function operatorCode() {
            if (renew) {
                env.clearTimeout(renew);
                renew = null;
            }
            return request('POST', 'operator-code', {}).then(function (result) {
                if (!result.ok || !result.body.code) {
                    part('qr').innerHTML = '';
                    part('countdown').textContent = '';
                    fail(problem(result));
                    return null;
                }
                fail('');
                drawQr(result.body.code);
                var until = Number(result.body.expires_at) || now();
                countdown(until, strings.operatorExpires);
                renew = env.setTimeout(operatorCode, Math.max(1, until - now() - RENEW_BEFORE) * 1000);
                return result.body;
            });
        }

        var ready;
        if ('pair' === mode) {
            part('form').addEventListener('submit', function (event) {
                event.preventDefault();
                generate();
            });
            part('again').addEventListener('click', function () {
                again();
            });
            ready = loadEvents();
        } else if ('terminals' === mode) {
            ready = loadTerminals();
        } else {
            ready = operatorCode();
        }

        return {
            ready: ready,
            generate: generate,
            again: again,
            reload: loadTerminals,
            operatorCode: operatorCode
        };
    }

    root.FFCTerminalOperator = {
        clock: clock,
        fill: fill,
        start: start
    };

    if (root.document && root.ffcTerminalOperator) {
        var boot = function () {
            var roots = root.document.querySelectorAll('[data-ffc-terminal-operator]');
            Array.prototype.forEach.call(roots, function (el) {
                start(el, root.ffcTerminalOperator, {
                    now: function () { return Date.now(); },
                    fetch: root.fetch.bind(root),
                    setInterval: root.setInterval.bind(root),
                    clearInterval: root.clearInterval.bind(root),
                    setTimeout: root.setTimeout.bind(root),
                    clearTimeout: root.clearTimeout.bind(root),
                    qrSvg: root.FFCPresence && root.FFCPresence.qrSvg
                });
            });
        };
        if ('loading' === root.document.readyState) {
            root.document.addEventListener('DOMContentLoaded', boot);
        } else {
            boot();
        }
    }
}(typeof window !== 'undefined' ? window : this));
