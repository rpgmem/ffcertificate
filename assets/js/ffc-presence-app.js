/**
 * Presence screen of the installable app (#2742).
 *
 * Activates this phone once, while online: a non-extractable P-256 key is
 * created with WebCrypto and kept in IndexedDB, and the site answers with an
 * attestation binding it to the account (#2741). From then on the screen
 * draws a presence code every 30 seconds, offline if need be: the attestation,
 * the chosen event, the moment, the event's ticket when the app has one, all
 * signed by the phone's key. The byte layout is the one
 * `AudiencePresenceCode` documents and verifies.
 *
 * The QR itself is drawn by the vendored Nayuki encoder (`qrcodegen`), in
 * alphanumeric mode, over Base45 text.
 *
 * Exposes window.FFCPresence; the pure helpers are there for the tests.
 */
(function (root) {
    'use strict';

    var PREFIX = 'FFP1';
    var ROTATION = 30;
    var ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';
    var DOMAIN_READING = 'FFC-PRESENCE-READING\u0000';
    var DOMAIN_ENROL = 'FFC-PRESENCE-ENROL\u0000';
    var DOMAIN_RENEW = 'FFC-PRESENCE-RENEW\u0000';
    var TARGETS = { audience_booking: 1 };
    var RENEW_BEFORE = 5 * 86400;
    var DB_NAME = 'ffc-presence';
    var STORE = 'state';

    /**
     * Base45 text of bytes (RFC 9285).
     *
     * @param {Uint8Array} bytes Bytes.
     * @return {string}
     */
    function base45(bytes) {
        var out = '';
        var i;
        for (i = 0; i + 1 < bytes.length; i += 2) {
            var n = bytes[i] * 256 + bytes[i + 1];
            out += ALPHABET[n % 45] + ALPHABET[Math.floor(n / 45) % 45] + ALPHABET[Math.floor(n / 2025)];
        }
        if (bytes.length % 2 === 1) {
            var last = bytes[bytes.length - 1];
            out += ALPHABET[last % 45] + ALPHABET[Math.floor(last / 45)];
        }
        return out;
    }

    /**
     * Base64url text of bytes, without padding.
     *
     * @param {Uint8Array} bytes Bytes.
     * @return {string}
     */
    function toB64url(bytes) {
        var binary = '';
        for (var i = 0; i < bytes.length; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary).replace(/\+/g, '-').split('/').join('_').replace(/=+$/, '');
    }

    /**
     * Bytes of base64url text; an empty array when it does not decode.
     *
     * @param {string} text Base64url.
     * @return {Uint8Array}
     */
    function fromB64url(text) {
        if (typeof text !== 'string' || !/^[A-Za-z0-9_-]*$/.test(text)) {
            return new Uint8Array(0);
        }
        var binary = atob(text.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((text.length + 3) % 4));
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes;
    }

    /**
     * Join byte arrays.
     *
     * @param {Array<Uint8Array>} parts Parts.
     * @return {Uint8Array}
     */
    function concat(parts) {
        var size = 0;
        parts.forEach(function (part) { size += part.length; });
        var out = new Uint8Array(size);
        var offset = 0;
        parts.forEach(function (part) {
            out.set(part, offset);
            offset += part.length;
        });
        return out;
    }

    /**
     * An unsigned big-endian integer.
     *
     * @param {number} value Value.
     * @param {number} size  Bytes (2 or 4).
     * @return {Uint8Array}
     */
    function uint(value, size) {
        var out = new Uint8Array(size);
        for (var i = size - 1; i >= 0; i--) {
            out[i] = value % 256;
            value = Math.floor(value / 256);
        }
        return out;
    }

    /**
     * ASCII text as bytes.
     *
     * @param {string} text Text.
     * @return {Uint8Array}
     */
    function ascii(text) {
        return new TextEncoder().encode(text);
    }

    /**
     * The signed body of a presence code, before the device signature.
     *
     * @param {Object} reading Reading.
     * @param {Uint8Array} reading.attestation Raw attestation.
     * @param {string} reading.targetType Attendance target type.
     * @param {number} reading.targetId Target id.
     * @param {number} reading.readAt Unix time.
     * @param {Uint8Array} [reading.ticket] Raw ticket.
     * @return {Uint8Array|null} Null for a target type the format has no byte for.
     */
    function codeBody(reading) {
        var type = TARGETS[reading.targetType];
        var ticket = reading.ticket || new Uint8Array(0);
        if (!type || ticket.length > 255) {
            return null;
        }
        return concat([
            new Uint8Array([1]),
            uint(reading.attestation.length, 2),
            reading.attestation,
            new Uint8Array([type]),
            uint(reading.targetId, 4),
            uint(reading.readAt, 4),
            new Uint8Array([ticket.length]),
            ticket
        ]);
    }

    /**
     * Sign bytes with the device key.
     *
     * @param {CryptoKey} key  Private key.
     * @param {Uint8Array} data Bytes.
     * @return {Promise<Uint8Array>} Raw `r || s`.
     */
    function sign(key, data) {
        return root.crypto.subtle.sign({ name: 'ECDSA', hash: 'SHA-256' }, key, data).then(function (signature) {
            return new Uint8Array(signature);
        });
    }

    /**
     * The text of a presence code.
     *
     * @param {Object} reading See codeBody().
     * @param {CryptoKey} key Device private key.
     * @return {Promise<string|null>}
     */
    function buildCode(reading, key) {
        var body = codeBody(reading);
        if (!body) {
            return Promise.resolve(null);
        }
        return sign(key, concat([ascii(DOMAIN_READING), body])).then(function (signature) {
            return PREFIX + base45(concat([body, signature]));
        });
    }

    /**
     * An SVG of a QR code for the text, through the vendored encoder.
     *
     * Alphanumeric mode at the low correction level: a phone screen is
     * clean, and the lowest level keeps the code as coarse as the payload
     * allows. Four modules of quiet zone, as the standard asks.
     *
     * @param {string} text Code text.
     * @return {{svg: string, version: number}|null}
     */
    function qrSvg(text) {
        var lib = root.qrcodegen;
        if (!lib || !lib.QrCode) {
            return null;
        }
        var segments = [lib.QrSegment.makeAlphanumeric(text)];
        var qr = lib.QrCode.encodeSegments(segments, lib.QrCode.Ecc.LOW);
        var border = 4;
        var size = qr.size + border * 2;
        var path = '';
        for (var y = 0; y < qr.size; y++) {
            for (var x = 0; x < qr.size; x++) {
                if (qr.getModule(x, y)) {
                    path += 'M' + (x + border) + ' ' + (y + border) + 'h1v1h-1z';
                }
            }
        }
        return {
            version: qr.version,
            svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + size + ' ' + size + '" shape-rendering="crispEdges" role="img" focusable="false">' +
                '<rect width="' + size + '" height="' + size + '" fill="#fff"/><path d="' + path + '" fill="#000"/></svg>'
        };
    }

    /**
     * A random device id the app keeps.
     *
     * @return {string}
     */
    function newDeviceId() {
        var bytes = new Uint8Array(18);
        root.crypto.getRandomValues(bytes);
        return toB64url(bytes);
    }

    /**
     * Storage over IndexedDB, which can hold a non-extractable CryptoKey.
     *
     * @return {{get: function(string): Promise, set: function(string, *): Promise}|null}
     */
    function idbStore() {
        if (!root.indexedDB) {
            return null;
        }
        var opened = null;
        function db() {
            if (!opened) {
                opened = new Promise(function (resolve, reject) {
                    var request = root.indexedDB.open(DB_NAME, 1);
                    request.onupgradeneeded = function () {
                        request.result.createObjectStore(STORE);
                    };
                    request.onsuccess = function () { resolve(request.result); };
                    request.onerror = function () { reject(request.error); };
                });
            }
            return opened;
        }
        function run(mode, action) {
            return db().then(function (handle) {
                return new Promise(function (resolve, reject) {
                    var request = action(handle.transaction(STORE, mode).objectStore(STORE));
                    request.onsuccess = function () { resolve(request.result); };
                    request.onerror = function () { reject(request.error); };
                });
            });
        }
        return {
            get: function (name) {
                return run('readonly', function (store) { return store.get(name); });
            },
            set: function (name, value) {
                return run('readwrite', function (store) { return store.put(value, name); });
            }
        };
    }

    /**
     * The screen's controller.
     *
     * @param {HTMLElement} el     Screen root.
     * @param {Object}      config Localized configuration.
     * @param {Object}      deps   Storage, fetch and clock, injectable for the tests.
     */
    function App(el, config, deps) {
        this.el = el;
        this.config = config;
        this.store = deps.store;
        this.fetch = deps.fetch;
        this.now = deps.now;
        this.state = {};
        this.timer = null;
    }

    App.prototype.part = function (name) {
        return this.el.querySelector('[data-ffc-presence-part="' + name + '"]');
    };

    App.prototype.say = function (message) {
        var status = this.part('status');
        if (status) {
            status.textContent = message || '';
        }
    };

    App.prototype.show = function (name) {
        var parts = this.el.querySelectorAll('[data-ffc-presence-part][data-ffc-presence-view]');
        Array.prototype.forEach.call(parts, function (part) {
            part.hidden = part.getAttribute('data-ffc-presence-view') !== name;
        });
    };

    App.prototype.request = function (method, path, body) {
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': this.config.nonce }
        };
        if (body) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }
        return this.fetch(this.config.rest + path, options).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) {
                    var error = new Error(data && data.message ? data.message : 'request failed');
                    error.code = data && data.code ? data.code : '';
                    throw error;
                }
                return data;
            });
        });
    };

    App.prototype.save = function () {
        return this.store.set('device', this.state);
    };

    App.prototype.start = function () {
        var self = this;
        if (!root.crypto || !root.crypto.subtle || !self.store) {
            self.show('unsupported');
            return Promise.resolve();
        }
        var activate = self.part('activate');
        if (activate) {
            activate.addEventListener('click', function () {
                self.enrol();
            });
        }
        var select = self.part('event');
        if (select) {
            select.addEventListener('change', function () {
                self.state.chosen = select.value;
                self.save();
                self.heading();
                self.draw();
            });
        }
        return self.load();
    };

    /**
     * Read the kept state and show the view it calls for. Runs at start and
     * again after an activation; the listeners are bound once, in start().
     *
     * @return {Promise}
     */
    App.prototype.load = function () {
        var self = this;
        var strings = self.config.strings;
        self.stop();
        return self.store.get('device').then(function (saved) {
            self.state = saved && saved.userId === self.config.userId ? saved : {};
            if (!self.state.attestation) {
                self.show('activate');
                return null;
            }
            self.show('code');
            self.listEvents();
            self.listPending();
            self.draw();
            self.timer = root.setInterval(function () { self.draw(); }, 1000);
            return self.refresh();
        }).catch(function () {
            self.say(strings.storage);
            self.show('unsupported');
        });
    };

    App.prototype.enrol = function () {
        var self = this;
        var strings = self.config.strings;
        var button = self.part('activate');
        if (button) {
            button.disabled = true;
        }
        self.say(strings.activating);
        var deviceId = self.state.deviceId || newDeviceId();
        var timestamp = Math.floor(self.now() / 1000);
        var keys;
        return root.crypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, false, ['sign']).then(function (pair) {
            keys = pair;
            return root.crypto.subtle.exportKey('raw', pair.publicKey);
        }).then(function (raw) {
            var publicKey = toB64url(new Uint8Array(raw));
            var statement = DOMAIN_ENROL + self.config.userId + '|' + deviceId + '|' + timestamp;
            return sign(keys.privateKey, ascii(statement)).then(function (proof) {
                return self.request('POST', 'enrol', {
                    device_id: deviceId,
                    public_key: publicKey,
                    timestamp: timestamp,
                    proof: toB64url(proof)
                });
            });
        }).then(function (answer) {
            self.state = {
                userId: self.config.userId,
                deviceId: deviceId,
                key: keys.privateKey,
                keyId: answer.key_id,
                attestation: answer.attestation,
                expires: answer.expires,
                name: answer.name,
                events: [],
                chosen: ''
            };
            return self.save();
        }).then(function () {
            self.say(strings.activated);
            return self.load();
        }).catch(function (error) {
            if (button) {
                button.disabled = false;
            }
            self.state.deviceId = deviceId;
            self.say(error && error.message ? error.message : strings.failed);
        });
    };

    App.prototype.refresh = function () {
        var self = this;
        var nowSeconds = Math.floor(self.now() / 1000);
        var renew = Promise.resolve();
        if (self.state.expires - nowSeconds < RENEW_BEFORE) {
            var statement = DOMAIN_RENEW + self.config.userId + '|' + self.state.keyId + '|' + nowSeconds;
            renew = sign(self.state.key, ascii(statement)).then(function (proof) {
                return self.request('POST', 'renew', {
                    key_id: self.state.keyId,
                    timestamp: nowSeconds,
                    proof: toB64url(proof)
                });
            }).then(function (answer) {
                self.state.attestation = answer.attestation;
                self.state.expires = answer.expires;
                self.state.name = answer.name;
            });
        }
        return renew.then(function () {
            return self.request('GET', 'events');
        }).then(function (answer) {
            self.state.events = answer.events || [];
            self.state.pending = answer.pending || [];
            self.listEvents();
            self.listPending();
            self.draw();
            return self.save();
        }).catch(function (error) {
            if (error && (error.code === 'ffc_presence_revoked' || error.code === 'ffc_presence_unknown_key')) {
                self.state = { userId: self.config.userId, deviceId: self.state.deviceId };
                self.stop();
                self.show('activate');
                self.say(error.message);
                return self.save();
            }
            // Offline or the site did not answer: keep what the app has.
            return null;
        });
    };

    App.prototype.stop = function () {
        if (this.timer) {
            root.clearInterval(this.timer);
            this.timer = null;
        }
    };

    App.prototype.current = function () {
        var events = this.state.events || [];
        var chosen = this.state.chosen;
        for (var i = 0; i < events.length; i++) {
            if (String(events[i].target_id) === String(chosen)) {
                return events[i];
            }
        }
        return events[0] || null;
    };

    App.prototype.listEvents = function () {
        var select = this.part('event');
        if (!select) {
            return;
        }
        var events = this.state.events || [];
        var current = this.current();
        select.textContent = '';
        events.forEach(function (event) {
            var option = document.createElement('option');
            option.value = String(event.target_id);
            option.textContent = [event.title, event.date, event.time].filter(Boolean).join(' · ');
            option.selected = current && current.target_id === event.target_id;
            select.appendChild(option);
        });
        var empty = this.part('empty');
        if (empty) {
            empty.hidden = events.length > 0;
        }
        select.hidden = events.length === 0;
        // The picker shows only when there is a choice to make (#2781).
        var picker = this.part('picker');
        if (picker) {
            picker.hidden = events.length < 2;
        }
        var card = this.part('card');
        if (card) {
            card.hidden = events.length === 0;
        }
        this.heading();
    };

    /**
     * The event the code is for, and when: the title on top, then its day,
     * hours and place (#2781).
     */
    App.prototype.heading = function () {
        var event = this.current();
        var title = this.part('title');
        var when = this.part('when');
        if (title) {
            title.textContent = event ? (event.title || '') : '';
            title.hidden = !event;
        }
        if (when) {
            when.textContent = event ? [event.date, event.time, event.place].filter(Boolean).join(' · ') : '';
            when.hidden = !event;
        }
    };

    /**
     * The items waiting for the person (#2749): a confirmation they may
     * dismiss, and a post-event form with its link. Dismissed keys are kept
     * with the device state, trimmed to the items the site still sends.
     */
    App.prototype.listPending = function () {
        var self = this;
        var list = self.part('pending');
        if (!list) {
            return;
        }
        var strings = self.config.strings;
        var pending = self.state.pending || [];
        var keys = pending.map(function (item) { return item.key; });
        self.state.dismissed = (self.state.dismissed || []).filter(function (key) {
            return keys.indexOf(key) !== -1;
        });
        var shown = pending.filter(function (item) {
            return self.state.dismissed.indexOf(item.key) === -1;
        });
        list.textContent = '';
        shown.forEach(function (item) {
            var li = document.createElement('li');
            li.className = 'ffc-presence-pending-item ffc-presence-pending-' + (item.type === 'form' ? 'form' : 'confirmed');
            var body = document.createElement('div');
            body.className = 'ffc-presence-pending-body';
            var text = document.createElement('strong');
            text.textContent = item.text || '';
            body.appendChild(text);
            if (item.detail) {
                var detail = document.createElement('small');
                detail.textContent = item.detail;
                body.appendChild(detail);
            }
            li.appendChild(body);
            if (item.type === 'form') {
                if (typeof item.url === 'string' && /^https?:\/{2}/.test(item.url)) {
                    var link = document.createElement('a');
                    link.className = 'ffc-btn ffc-btn-primary ffc-icon-award';
                    link.href = item.url;
                    link.textContent = strings.fillForm;
                    li.appendChild(link);
                }
            } else {
                var dismiss = document.createElement('button');
                dismiss.type = 'button';
                dismiss.className = 'ffc-btn ffc-btn-neutral ffc-btn-icon ffc-icon-cross';
                dismiss.setAttribute('aria-label', strings.dismiss);
                dismiss.title = strings.dismiss;
                dismiss.addEventListener('click', function () {
                    self.state.dismissed.push(item.key);
                    self.listPending();
                    self.save();
                });
                li.appendChild(dismiss);
            }
            list.appendChild(li);
        });
        list.hidden = shown.length === 0;
    };

    App.prototype.draw = function () {
        var self = this;
        var strings = self.config.strings;
        var nowSeconds = Math.floor(self.now() / 1000);
        var windowStart = nowSeconds - (nowSeconds % ROTATION);
        var left = ROTATION - (nowSeconds % ROTATION);
        var countdown = self.part('countdown');
        if (countdown) {
            // "New code in 18 s · Maria S.", under the bar that drains with
            // the rotation (#2781).
            countdown.textContent = [strings.next.replace('%d', String(left)), self.state.name || ''].filter(Boolean).join(' · ');
        }
        var progress = self.part('progress');
        if (progress) {
            progress.setAttribute('max', String(ROTATION));
            progress.setAttribute('value', String(left));
        }
        var event = self.current();
        var attendance = self.part('attendance');
        if (attendance) {
            attendance.textContent = event && event.attendance ? event.attendance : '';
            attendance.hidden = !(event && event.attendance);
        }
        var holder = self.part('qr');
        if (!event || !holder) {
            if (holder) {
                holder.textContent = '';
            }
            return Promise.resolve(null);
        }
        if (self.drawn === event.target_id + '@' + windowStart) {
            return Promise.resolve(null);
        }
        if (self.state.expires < nowSeconds) {
            holder.textContent = '';
            self.say(strings.expired);
            return Promise.resolve(null);
        }
        self.drawn = event.target_id + '@' + windowStart;
        return buildCode({
            attestation: fromB64url(self.state.attestation),
            targetType: event.target_type,
            targetId: event.target_id,
            readAt: windowStart,
            ticket: fromB64url(event.ticket)
        }, self.state.key).then(function (text) {
            var drawn = text ? qrSvg(text) : null;
            if (drawn) {
                // The SVG is built here from module coordinates and literals
                // only; no text from the code or the server enters it.
                holder.innerHTML = drawn.svg;
                holder.setAttribute('aria-label', strings.qrLabel);
            }
            return text;
        });
    };

    /**
     * Start the screen on every presence root in the document.
     *
     * @param {Object} [deps] Storage, fetch and clock.
     * @return {Array<App>}
     */
    function boot(deps) {
        var config = root.ffcPresence;
        if (!config) {
            return [];
        }
        deps = deps || {};
        var apps = [];
        var roots = document.querySelectorAll('[data-ffc-presence]');
        Array.prototype.forEach.call(roots, function (el) {
            var app = new App(el, config, {
                store: deps.store !== undefined ? deps.store : idbStore(),
                fetch: deps.fetch || root.fetch.bind(root),
                now: deps.now || Date.now
            });
            app.start();
            apps.push(app);
        });
        return apps;
    }

    root.FFCPresence = {
        PREFIX: PREFIX,
        ROTATION: ROTATION,
        base45: base45,
        toB64url: toB64url,
        fromB64url: fromB64url,
        codeBody: codeBody,
        buildCode: buildCode,
        qrSvg: qrSvg,
        idbStore: idbStore,
        App: App,
        boot: boot
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { boot(); });
    } else {
        boot();
    }
}(window));
