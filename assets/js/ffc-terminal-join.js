/**
 * The terminal's on-site sign-up code (#2968).
 *
 * The terminal makes an ECDSA P-256 key pair in its browser once, keeps it
 * in IndexedDB with the secret half not extractable, and gives the site only
 * the public half. With it the terminal signs a code that names itself, the
 * event it checks in and the moment, with no connection; a phone that reads
 * the code opens the site, which checks the signature against the key it
 * holds for that terminal (`AudienceTerminalJoinCode`).
 *
 * The bytes, version 1: version (1) | terminal id (4) | target type (1) |
 * target id (4) | issue time (4) | signature (64, raw r || s), all
 * big-endian, sent as base64url in the `ffc_join` query argument. The
 * signature covers `FFC-TERMINAL-JOIN\0` followed by the first 14 bytes.
 *
 * Exposes window.FFCTerminalJoin; every helper takes what it needs, so the
 * tests give it their own WebCrypto and IndexedDB.
 */
(function (root) {
    'use strict';

    var VERSION = 1;
    var DOMAIN = 'FFC-TERMINAL-JOIN\u0000';
    var ROTATION = 30;
    var DB = 'ffc-terminal';
    var STORE = 'keys';
    var KEY_ID = 'join';
    var QUERY_VAR = 'ffc_join';
    var ALGORITHM = { name: 'ECDSA', namedCurve: 'P-256' };
    var SIGN = { name: 'ECDSA', hash: 'SHA-256' };
    // Target types by their byte, as `AudiencePresenceCode::TARGETS` lists them.
    var TYPES = { audience_booking: 1, certificate_form: 2 };

    /**
     * The type byte and id of a queue key: a bare id is an audience booking,
     * anything else is `type:id`.
     *
     * @param {number|string} key Target key.
     * @return {{type: number, id: number}|null}
     */
    function target(key) {
        var text = String(key);
        var match = /^(\d+)$/.exec(text);
        if (match) {
            return { type: TYPES.audience_booking, id: Number(match[1]) };
        }
        match = /^([a-z_]+):(\d+)$/.exec(text);
        if (match && TYPES[match[1]]) {
            return { type: TYPES[match[1]], id: Number(match[2]) };
        }
        return null;
    }

    /**
     * The 14 signed bytes.
     *
     * @param {number} terminalId Terminal id.
     * @param {number} type       Target type byte.
     * @param {number} targetId   Target id.
     * @param {number} issuedAt   Unix time.
     * @return {Uint8Array}
     */
    function body(terminalId, type, targetId, issuedAt) {
        var bytes = new Uint8Array(14);
        var view = new DataView(bytes.buffer);
        view.setUint8(0, VERSION);
        view.setUint32(1, terminalId >>> 0);
        view.setUint8(5, type);
        view.setUint32(6, targetId >>> 0);
        view.setUint32(10, issuedAt >>> 0);
        return bytes;
    }

    /**
     * The domain followed by the body: what the signature covers.
     *
     * @param {Uint8Array} signed The body.
     * @return {Uint8Array}
     */
    function message(signed) {
        var out = new Uint8Array(DOMAIN.length + signed.length);
        for (var i = 0; i < DOMAIN.length; i++) {
            out[i] = DOMAIN.charCodeAt(i);
        }
        out.set(signed, DOMAIN.length);
        return out;
    }

    /**
     * Base64url of bytes, without padding.
     *
     * @param {Uint8Array} bytes Bytes.
     * @return {string}
     */
    function b64url(bytes) {
        var text = '';
        for (var i = 0; i < bytes.length; i++) {
            text += String.fromCharCode(bytes[i]);
        }
        return root.btoa(text).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    /**
     * Hex of bytes.
     *
     * @param {Uint8Array} bytes Bytes.
     * @return {string}
     */
    function hex(bytes) {
        var out = '';
        for (var i = 0; i < bytes.length; i++) {
            out += (bytes[i] < 16 ? '0' : '') + bytes[i].toString(16);
        }
        return out;
    }

    /**
     * The fingerprint the site gives a registered key: the first 16 hex
     * digits of the SHA-256 of the raw point.
     *
     * @param {SubtleCrypto} subtle WebCrypto.
     * @param {Uint8Array}   raw    Raw public point.
     * @return {Promise<string>}
     */
    function fingerprint(subtle, raw) {
        return subtle.digest('SHA-256', raw).then(function (digest) {
            return hex(new Uint8Array(digest)).slice(0, 16);
        });
    }

    /**
     * One IndexedDB request as a promise.
     *
     * @param {IDBRequest} request Request.
     * @return {Promise<*>}
     */
    function settle(request) {
        return new Promise(function (resolve, reject) {
            request.onsuccess = function () { resolve(request.result); };
            request.onerror = function () { reject(request.error); };
        });
    }

    /**
     * The terminal's key store.
     *
     * @param {IDBFactory} indexedDB IndexedDB.
     * @return {Promise<IDBDatabase>}
     */
    function open(indexedDB) {
        var request = indexedDB.open(DB, 1);
        request.onupgradeneeded = function () {
            if (!request.result.objectStoreNames.contains(STORE)) {
                request.result.createObjectStore(STORE);
            }
        };
        return settle(request);
    }

    /**
     * The terminal's key pair, made the first time: the secret half cannot
     * be exported, so it never leaves this browser.
     *
     * @param {IDBFactory}   indexedDB IndexedDB.
     * @param {SubtleCrypto} subtle    WebCrypto.
     * @return {Promise<{privateKey: CryptoKey, raw: Uint8Array}>}
     */
    function key(indexedDB, subtle) {
        return open(indexedDB).then(function (db) {
            return settle(db.transaction(STORE, 'readonly').objectStore(STORE).get(KEY_ID)).then(function (kept) {
                if (kept && kept.privateKey && kept.raw) {
                    db.close();
                    return { privateKey: kept.privateKey, raw: new Uint8Array(kept.raw) };
                }
                return subtle.generateKey(ALGORITHM, false, ['sign']).then(function (pair) {
                    return subtle.exportKey('raw', pair.publicKey).then(function (raw) {
                        var made = { privateKey: pair.privateKey, raw: new Uint8Array(raw) };
                        return settle(db.transaction(STORE, 'readwrite').objectStore(STORE).put({ privateKey: made.privateKey, raw: raw }, KEY_ID)).then(function () {
                            db.close();
                            return made;
                        });
                    });
                });
            });
        });
    }

    /**
     * A signed code.
     *
     * @param {SubtleCrypto} subtle     WebCrypto.
     * @param {CryptoKey}    privateKey The terminal's secret key.
     * @param {number}       terminalId Terminal id.
     * @param {number|string} targetKey The queue key of the event checking in.
     * @param {number}       issuedAt   Unix time, by the site's clock.
     * @return {Promise<string|null>} Base64url, or null for a key no code can name.
     */
    function code(subtle, privateKey, terminalId, targetKey, issuedAt) {
        var named = target(targetKey);
        if (!named || !(terminalId > 0)) {
            return Promise.resolve(null);
        }
        var signed = body(terminalId, named.type, named.id, issuedAt);
        return subtle.sign(SIGN, privateKey, message(signed)).then(function (signature) {
            var out = new Uint8Array(signed.length + signature.byteLength);
            out.set(signed, 0);
            out.set(new Uint8Array(signature), signed.length);
            return b64url(out);
        });
    }

    /**
     * The address a phone opens.
     *
     * @param {string} base The site's address.
     * @param {string} text The code.
     * @return {string}
     */
    function url(base, text) {
        return base + (-1 === base.indexOf('?') ? '?' : '&') + QUERY_VAR + '=' + text;
    }

    /**
     * An SVG of a QR code for an address, through the vendored encoder, at
     * the medium correction level: a tablet screen read across a table.
     *
     * @param {object} lib  `qrcodegen`.
     * @param {string} text The address.
     * @return {string} SVG markup, or '' without the encoder.
     */
    function qrSvg(lib, text) {
        if (!lib || !lib.QrCode) {
            return '';
        }
        var qr = lib.QrCode.encodeText(text, lib.QrCode.Ecc.MEDIUM);
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
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + size + ' ' + size + '" shape-rendering="crispEdges" focusable="false" aria-hidden="true">' +
            '<rect width="' + size + '" height="' + size + '" fill="#fff"/><path d="' + path + '" fill="#000"/></svg>';
    }

    root.FFCTerminalJoin = {
        ROTATION: ROTATION,
        body: body,
        b64url: b64url,
        code: code,
        fingerprint: fingerprint,
        key: key,
        message: message,
        qrSvg: qrSvg,
        target: target,
        url: url
    };
}(typeof window !== 'undefined' ? window : this));
