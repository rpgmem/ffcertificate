/**
 * Presence code verifier for the check-in terminal (#2745).
 *
 * The same checks as `AudiencePresenceCode::verify()` on the site, done in
 * the browser so a terminal can tell a good code from a bad one with no
 * connection: the site's signature on the attestation (and on the ticket,
 * when the code carries one), the phone's signature on the reading, the
 * revocation list and the code's age. The site checks everything again when
 * the terminal sends its queue, so this verifier decides what the terminal
 * shows and keeps, never what is recorded.
 *
 * Signatures are raw `r || s`, which is what WebCrypto's ECDSA expects, and
 * every key is a raw P-256 point. The byte layout is documented on the PHP
 * classes `AudiencePresenceCode`, `AudiencePresenceAttestation` and
 * `AudiencePresenceTicket`.
 *
 * Exposes window.FFCPresenceVerifier.
 */
(function (root) {
    'use strict';

    var PREFIX = 'FFP1';
    var ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';
    var ROTATION = 30;
    var SKEW = 60;
    var POINT_BYTES = 65;
    var SIGNATURE_BYTES = 64;
    var NAME_BYTES = 48;
    var TARGETS = { 1: 'audience_booking' };
    var encoder = new TextEncoder();
    var DOMAINS = {
        code: encoder.encode('FFC-PRESENCE-READING\0'),
        attestation: encoder.encode('FFC-PRESENCE-ATTESTATION\0'),
        ticket: encoder.encode('FFC-PRESENCE-TICKET\0')
    };

    /**
     * Base45 (RFC 9285) to bytes, or null when the text is not Base45.
     *
     * @param {string} text Text.
     * @return {Uint8Array|null}
     */
    function base45(text) {
        var out = [];
        for (var i = 0; i < text.length; i += 3) {
            var chunk = text.slice(i, i + 3);
            if (1 === chunk.length) {
                return null;
            }
            var n = 0;
            for (var j = chunk.length - 1; j >= 0; j--) {
                var value = ALPHABET.indexOf(chunk[j]);
                if (value < 0) {
                    return null;
                }
                n = n * 45 + value;
            }
            if (3 === chunk.length) {
                if (n > 65535) {
                    return null;
                }
                out.push(n >> 8, n & 255);
            } else {
                if (n > 255) {
                    return null;
                }
                out.push(n);
            }
        }
        return new Uint8Array(out);
    }

    /**
     * Bytes from base64url.
     *
     * @param {string} text Text.
     * @return {Uint8Array}
     */
    function fromB64url(text) {
        var b64 = String(text).replace(/-/g, '+').replace(/_/g, '/');
        while (b64.length % 4) {
            b64 += '=';
        }
        var bin = atob(b64);
        var out = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) {
            out[i] = bin.charCodeAt(i);
        }
        return out;
    }

    /**
     * Bytes to base64url, unpadded.
     *
     * @param {Uint8Array} bytes Bytes.
     * @return {string}
     */
    function toB64url(bytes) {
        var bin = '';
        for (var i = 0; i < bytes.length; i++) {
            bin += String.fromCharCode(bytes[i]);
        }
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function u32(bytes, offset) {
        return ((bytes[offset] << 24) >>> 0) + (bytes[offset + 1] << 16) + (bytes[offset + 2] << 8) + bytes[offset + 3];
    }

    function concat(a, b) {
        var out = new Uint8Array(a.length + b.length);
        out.set(a, 0);
        out.set(b, a.length);
        return out;
    }

    function ascii(bytes) {
        var out = '';
        for (var i = 0; i < bytes.length; i++) {
            out += String.fromCharCode(bytes[i]);
        }
        return out;
    }

    /**
     * The fields of a version 1 code, or null.
     *
     * @param {Uint8Array} raw Bytes.
     * @return {object|null}
     */
    function splitCode(raw) {
        if (raw.length < 3 || 1 !== raw[0]) {
            return null;
        }
        var size = (raw[1] << 8) + raw[2];
        var offset = 3 + size;
        if (size < 1 || raw.length < offset + 10) {
            return null;
        }
        var type = raw[offset];
        var ticketSize = raw[offset + 9];
        var end = offset + 10 + ticketSize;
        if (!TARGETS[type] || raw.length !== end + SIGNATURE_BYTES) {
            return null;
        }
        return {
            attestation: raw.subarray(3, offset),
            targetType: TARGETS[type],
            targetId: u32(raw, offset + 1),
            readAt: u32(raw, offset + 5),
            ticket: raw.subarray(offset + 10, end),
            body: raw.subarray(0, end),
            signature: raw.subarray(end)
        };
    }

    /**
     * The fields of a version 1 attestation, or null.
     *
     * @param {Uint8Array} raw Bytes.
     * @return {object|null}
     */
    function splitAttestation(raw) {
        if (raw.length < 2 || 1 !== raw[0]) {
            return null;
        }
        var kidLength = raw[1];
        var offset = 2 + kidLength;
        if (kidLength < 1 || kidLength > 16 || raw.length < offset + 4 + POINT_BYTES + 8 + 1) {
            return null;
        }
        var siteKey = ascii(raw.subarray(2, offset));
        var userId = u32(raw, offset);
        offset += 4;
        var point = raw.subarray(offset, offset + POINT_BYTES);
        offset += POINT_BYTES;
        var expires = u32(raw, offset + 4);
        offset += 8;
        var nameLength = raw[offset];
        var name = raw.subarray(offset + 1, offset + 1 + nameLength);
        offset += 1 + nameLength;
        if (nameLength > NAME_BYTES || raw.length !== offset + SIGNATURE_BYTES || !/^[A-Za-z0-9_-]+$/.test(siteKey)) {
            return null;
        }
        return {
            siteKey: siteKey,
            userId: userId,
            point: point,
            expires: expires,
            name: new TextDecoder().decode(name),
            body: raw.subarray(0, offset),
            signature: raw.subarray(offset)
        };
    }

    /**
     * The fields of a version 1 ticket, or null.
     *
     * @param {Uint8Array} raw Bytes.
     * @return {object|null}
     */
    function splitTicket(raw) {
        var kidLength = raw.length > 1 ? raw[1] : 0;
        if (2 + kidLength + 13 + SIGNATURE_BYTES !== raw.length || 1 !== raw[0] || kidLength < 1 || kidLength > 16) {
            return null;
        }
        var offset = 2 + kidLength;
        if (!TARGETS[raw[offset]]) {
            return null;
        }
        return {
            siteKey: ascii(raw.subarray(2, offset)),
            targetType: TARGETS[raw[offset]],
            targetId: u32(raw, offset + 1),
            userId: u32(raw, offset + 5),
            expires: u32(raw, offset + 9),
            body: raw.subarray(0, raw.length - SIGNATURE_BYTES),
            signature: raw.subarray(raw.length - SIGNATURE_BYTES)
        };
    }

    /**
     * Whether a raw signature verifies under a raw P-256 point.
     *
     * @param {SubtleCrypto} subtle WebCrypto.
     * @param {Uint8Array} point Public point.
     * @param {Uint8Array} signature Raw r || s.
     * @param {Uint8Array} message Message.
     * @return {Promise<boolean>}
     */
    function verifies(subtle, point, signature, message) {
        return subtle.importKey('raw', point, { name: 'ECDSA', namedCurve: 'P-256' }, false, ['verify'])
            .then(function (key) {
                return subtle.verify({ name: 'ECDSA', hash: 'SHA-256' }, key, signature, message);
            })
            .catch(function () { return false; });
    }

    /**
     * The id a device key is revoked under: base64url of the first eight
     * bytes of its SHA-256.
     *
     * @param {SubtleCrypto} subtle WebCrypto.
     * @param {Uint8Array} point Public point.
     * @return {Promise<string>}
     */
    function keyId(subtle, point) {
        return subtle.digest('SHA-256', point).then(function (hash) {
            return toB64url(new Uint8Array(hash).subarray(0, 8));
        });
    }

    function refuse(code) {
        return { ok: false, code: code };
    }

    /**
     * Verify a presence code.
     *
     * @param {string} text The text the reader typed.
     * @param {{now: number, keys: Object<string, string>, revoked: string[], subtle: SubtleCrypto}} options
     *     `now` in Unix seconds, `keys` the site's public keys by id (base64url
     *     points), `revoked` the revoked device key ids.
     * @return {Promise<object>} `{ok: true, userId, name, keyId, targetType, targetId, readAt, ticket}`
     *     or `{ok: false, code}` with the site's own error code.
     */
    function verify(text, options) {
        var subtle = options.subtle;
        var now = options.now;
        var keys = options.keys || {};
        var revoked = options.revoked || [];
        var clean = String(text || '').trim();
        if (0 !== clean.indexOf(PREFIX)) {
            return Promise.resolve(refuse('ffc_presence_code_malformed'));
        }
        var raw = base45(clean.slice(PREFIX.length));
        var code = raw ? splitCode(raw) : null;
        var attestation = code ? splitAttestation(code.attestation) : null;
        if (!code || !attestation) {
            return Promise.resolve(refuse(code ? 'ffc_presence_attestation_malformed' : 'ffc_presence_code_malformed'));
        }
        var siteKey = keys[attestation.siteKey];
        if (!siteKey) {
            return Promise.resolve(refuse('ffc_presence_attestation_signature'));
        }
        var ticket = null;

        return verifies(subtle, fromB64url(siteKey), attestation.signature, concat(DOMAINS.attestation, attestation.body))
            .then(function (ok) {
                if (!ok) {
                    throw refuse('ffc_presence_attestation_signature');
                }
                if (attestation.expires < now) {
                    throw refuse('ffc_presence_attestation_expired');
                }
                return verifies(subtle, attestation.point, code.signature, concat(DOMAINS.code, code.body));
            })
            .then(function (ok) {
                if (!ok) {
                    throw refuse('ffc_presence_code_signature');
                }
                return keyId(subtle, attestation.point);
            })
            .then(function (id) {
                attestation.keyId = id;
                if (revoked.indexOf(id) >= 0) {
                    throw refuse('ffc_presence_code_revoked');
                }
                if (code.readAt > now + SKEW) {
                    throw refuse('ffc_presence_code_future');
                }
                if (code.readAt < now - ROTATION - SKEW) {
                    throw refuse('ffc_presence_code_stale');
                }
                if (!code.ticket.length) {
                    return true;
                }
                ticket = splitTicket(code.ticket);
                if (!ticket) {
                    throw refuse('ffc_presence_ticket_malformed');
                }
                var ticketKey = keys[ticket.siteKey];
                if (!ticketKey) {
                    throw refuse('ffc_presence_ticket_signature');
                }
                return verifies(subtle, fromB64url(ticketKey), ticket.signature, concat(DOMAINS.ticket, ticket.body));
            })
            .then(function (ok) {
                if (!ok) {
                    throw refuse('ffc_presence_ticket_signature');
                }
                if (ticket) {
                    if (ticket.expires < now) {
                        throw refuse('ffc_presence_ticket_expired');
                    }
                    if (ticket.userId !== attestation.userId || ticket.targetType !== code.targetType || ticket.targetId !== code.targetId) {
                        throw refuse('ffc_presence_ticket_mismatch');
                    }
                }
                return {
                    ok: true,
                    userId: attestation.userId,
                    name: attestation.name,
                    keyId: attestation.keyId,
                    targetType: code.targetType,
                    targetId: code.targetId,
                    readAt: code.readAt,
                    ticket: !!ticket
                };
            })
            .catch(function (error) {
                return error && false === error.ok ? error : refuse('ffc_presence_code_malformed');
            });
    }

    root.FFCPresenceVerifier = {
        PREFIX: PREFIX,
        base45: base45,
        verify: verify
    };
}(typeof window !== 'undefined' ? window : this));
