/**
 * Signed QR verifier (#2639).
 *
 * Reads the signed payload a certificate's QR code carries in its fragment
 * (`#token=…&s=<payload>.<signature>`, both base64url) and checks the Ed25519
 * signature against the site's public keys with WebCrypto, so it works with
 * no server at all: on the verification page before the site answers, on a
 * saved copy of that page, and in the standalone verifier file.
 *
 * The signature covers the payload's bytes exactly as they travel, so nothing
 * is rebuilt or normalised here: the bytes PHP signed are the bytes checked.
 *
 * No dependency (not jQuery, not ffc-core): the standalone verifier inlines
 * this file as it is.
 *
 * Exposes window.FFCSignedQr.
 */
(function (root) {
    'use strict';

    /**
     * Bytes from base64url; null when the text is not base64url.
     *
     * @param {string} text Base64url, with or without padding.
     * @return {Uint8Array|null}
     */
    function fromB64url(text) {
        if (typeof text !== 'string' || !/^[A-Za-z0-9_-]*={0,2}$/.test(text)) {
            return null;
        }
        var b64 = text.replace(/-/g, '+').replace(/_/g, '/');
        while (b64.length % 4) {
            b64 += '=';
        }
        try {
            var bin = root.atob(b64);
            var out = new Uint8Array(bin.length);
            for (var i = 0; i < bin.length; i++) {
                out[i] = bin.charCodeAt(i);
            }
            return out;
        } catch (e) {
            return null;
        }
    }

    /**
     * Split a record on `|`, undoing the `\` escape of `|` and `\`.
     *
     * @param {string} text Record text.
     * @return {string[]}
     */
    function split(text) {
        var parts = [];
        var current = '';
        for (var i = 0; i < text.length; i++) {
            var ch = text.charAt(i);
            if (ch === '\\' && i + 1 < text.length) {
                current += text.charAt(i + 1);
                i++;
            } else if (ch === '|') {
                parts.push(current);
                current = '';
            } else {
                current += ch;
            }
        }
        parts.push(current);
        return parts;
    }

    /**
     * The fields a payload carries, by its version and length.
     *
     * @param {string} text Decoded payload.
     * @return {Object|null}
     */
    function fieldsOf(text) {
        var p = split(text);
        if (p[0] !== 'v1') {
            return null;
        }
        if (p.length === 8) {
            return { mode: 'full', key: p[1], code: p[2], date: p[3], name: p[4], course: p[5], hours: p[6], hash: p[7] };
        }
        if (p.length === 5) {
            return { mode: 'compact', key: p[1], code: p[2], date: p[3], hash: p[4] };
        }
        return null;
    }

    /**
     * The signed value from a link or a fragment, or ''.
     *
     * @param {string} text A full link, a `#…` fragment or the fragment's body.
     * @return {string}
     */
    function signedFrom(text) {
        if (typeof text !== 'string') {
            return '';
        }
        var at = text.indexOf('#');
        var fragment = at === -1 ? text : text.slice(at + 1);
        var params = new URLSearchParams(fragment);
        return params.get('s') || '';
    }

    /**
     * Whether this browser can check an Ed25519 signature.
     *
     * @return {boolean}
     */
    function supported() {
        return !!(root.crypto && root.crypto.subtle && typeof root.crypto.subtle.verify === 'function' && typeof root.TextDecoder === 'function');
    }

    /**
     * Check a signed value against the site's public keys.
     *
     * Resolves with { state, fields }, never rejects:
     * - valid: the signature holds; fields says what was signed.
     * - invalid: the signature does not hold under the key it names.
     * - unknown_key: the key it names is not one of the site's.
     * - malformed: not a signed value of a known version.
     * - unsupported: this browser has no Ed25519 in WebCrypto.
     *
     * @param {string} signed The `s` value: `<payload>.<signature>`.
     * @param {Object<string, string>} keys Key id → base64url public key.
     * @return {Promise<{state: string, fields: (Object|null)}>}
     */
    function verify(signed, keys) {
        var dot = typeof signed === 'string' ? signed.lastIndexOf('.') : -1;
        var payload = dot > 0 ? fromB64url(signed.slice(0, dot)) : null;
        var signature = dot > 0 ? fromB64url(signed.slice(dot + 1)) : null;
        var fields = null;
        if (payload && signature && signature.length === 64) {
            try {
                fields = fieldsOf(new root.TextDecoder('utf-8', { fatal: true }).decode(payload));
            } catch (e) {
                fields = null;
            }
        }
        if (!fields) {
            return Promise.resolve({ state: 'malformed', fields: null });
        }
        var publicKey = keys && Object.prototype.hasOwnProperty.call(keys, fields.key) ? fromB64url(keys[fields.key]) : null;
        if (!publicKey || publicKey.length !== 32) {
            return Promise.resolve({ state: 'unknown_key', fields: fields });
        }
        if (!supported()) {
            return Promise.resolve({ state: 'unsupported', fields: fields });
        }
        var subtle = root.crypto.subtle;
        return subtle.importKey('raw', publicKey, { name: 'Ed25519' }, false, ['verify'])
            .then(function (key) {
                return subtle.verify({ name: 'Ed25519' }, key, signature, payload);
            })
            .then(function (ok) {
                return { state: ok ? 'valid' : 'invalid', fields: fields };
            })
            .catch(function () {
                return { state: 'unsupported', fields: fields };
            });
    }

    function pad2(n) {
        return (n < 10 ? '0' : '') + n;
    }

    /**
     * A payload's `Y-m-d` date in the plugin's date format (#2676). The
     * payload carries the date canonical, so it is formatted here; this file
     * cannot reach ffc-core (the standalone verifier inlines it alone), so it
     * reads the date tokens of `FFC.formatDate` itself. Returns the input when
     * there is no format or it is not a `Y-m-d` date.
     *
     * @param {string} ymd Date as the payload carries it.
     * @param {Object} [dates] `DateFormatter::script_config()`.
     * @return {string}
     */
    function formatDate(ymd, dates) {
        var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(ymd || ''));
        var cfg = dates || {};
        if (!match || typeof cfg.date !== 'string' || cfg.date === '') {
            return ymd ? String(ymd) : '';
        }
        var y = +match[1];
        var m = +match[2];
        var d = +match[3];
        var weekday = new Date(y, m - 1, d).getDay();
        var pick = function (list, i, fallback) {
            return (Array.isArray(list) && list[i]) ? list[i] : fallback;
        };
        var out = '';
        for (var k = 0; k < cfg.date.length; k++) {
            var c = cfg.date.charAt(k);
            if (c === '\\') {
                k++;
                out += cfg.date.charAt(k);
                continue;
            }
            switch (c) {
                case 'd': out += pad2(d); break;
                case 'j': out += d; break;
                case 'D': out += pick(cfg.weekdaysShort, weekday, ''); break;
                case 'l': out += pick(cfg.weekdays, weekday, ''); break;
                case 'N': out += weekday === 0 ? 7 : weekday; break;
                case 'w': out += weekday; break;
                case 'm': out += pad2(m); break;
                case 'n': out += m; break;
                case 'F': out += pick(cfg.months, m - 1, pad2(m)); break;
                case 'M': out += pick(cfg.monthsShort, m - 1, pad2(m)); break;
                case 'Y': out += y; break;
                case 'y': out += String(y).slice(-2); break;
                default: out += c;
            }
        }
        return out;
    }

    /**
     * What to show for a result: a notice's tone, icon, title and lines.
     *
     * @param {{state: string, fields: (Object|null)}} result From verify().
     * @param {Object<string, string>} strings Translated strings.
     * @param {string} [currentHash] The record hash the site holds now, when it answered.
     * @param {Object} [dates] The plugin's date format, as `formatDate()` reads it.
     * @return {{tone: string, icon: string, title: string, lines: string[]}}
     */
    function describe(result, strings, currentHash, dates) {
        var s = strings || {};
        var f = result.fields || {};
        if (result.state === 'valid') {
            var lines = [
                s.code + ' ' + f.code,
                s.issued + ' ' + formatDate(f.date, dates)
            ];
            if (f.mode === 'full') {
                lines.push(s.name + ' ' + f.name);
                lines.push(s.course + ' ' + f.course);
                if (f.hours !== '') {
                    lines.push(s.workload + ' ' + f.hours);
                }
            }
            if (typeof currentHash === 'string' && currentHash !== '' && currentHash !== f.hash) {
                return { tone: 'warning', icon: 'warning', title: s.superseded, lines: [s.supersededText].concat(lines) };
            }
            return { tone: 'success', icon: 'shield', title: s.valid, lines: [s.validText].concat(lines) };
        }
        if (result.state === 'unsupported') {
            return { tone: 'info', icon: 'info', title: s.unsupported, lines: [s.unsupportedText] };
        }
        if (result.state === 'unknown_key') {
            return { tone: 'danger', icon: 'warning', title: s.invalid, lines: [s.unknownKeyText] };
        }
        return { tone: 'danger', icon: 'warning', title: s.invalid, lines: [s.invalidText] };
    }

    root.FFCSignedQr = {
        split: split,
        fieldsOf: fieldsOf,
        signedFrom: signedFrom,
        supported: supported,
        verify: verify,
        formatDate: formatDate,
        describe: describe
    };
})(typeof window !== 'undefined' ? window : this);
