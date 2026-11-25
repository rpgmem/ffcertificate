/**
 * Check-in terminal service worker (#2783).
 *
 * Keeps the terminal's page shell so a paired tablet that reloads, restarts
 * its browser or wakes from a crash while the venue has no connection still
 * opens the reading screen. The pairing, keys, revocation list and queue
 * already live in the browser's storage, so the cached shell resumes at once.
 *
 * Served by the site as `/terminal/sw.js`, so its scope is `/terminal/` and
 * nothing else: never the site, never `/app/`. The site prepends the cache
 * name, which carries the plugin version (and the file time on a testes
 * site), so a new release starts a new cache and the old one is deleted on
 * activate.
 *
 * - The terminal page is network-first, kept on every answer, and served from
 *   the cache only when the network fails. Any other page under the scope,
 *   such as the install page (#2813), is left to the network.
 * - The sheets and scripts the page loaded are kept when the page sends their
 *   addresses, and served from the cache only when the network fails. Both
 *   ask the server to confirm the file (`cache: 'no-cache'`), never the
 *   browser's HTTP cache, which can hold an older file under the same
 *   `?ver=` (#2834).
 * - REST calls are never touched: they go to the network and fail as they
 *   would without a worker, which is what the page's own queue expects.
 * - Only the site's own pages may ask it to keep anything, and what it
 *   fetches always carries its own origin, never a host from the message
 *   (#2809).
 */
(function (sw) {
    'use strict';

    var PREFIX = 'ffc-terminal-';
    var CACHE = typeof sw.ffcTerminalCache === 'string' && 0 === sw.ffcTerminalCache.indexOf(PREFIX) ? sw.ffcTerminalCache : PREFIX + 'dev';

    function scope() {
        return sw.registration && sw.registration.scope ? String(sw.registration.scope) : '';
    }

    // A REST call, under the pretty base or the query form, is never cached.
    function isRest(url) {
        return -1 !== url.pathname.indexOf('/wp-json/') || url.searchParams.has('rest_route');
    }

    // Whether a navigation is the terminal page itself: the scope's own
    // path, with or without a query.
    function isShell(url) {
        var base = scope();
        if ('' === base) {
            return false;
        }
        return new URL(base).pathname === url.pathname;
    }

    function sameOrigin(url) {
        return url.origin === sw.location.origin;
    }

    // The address the page asked to keep, rebuilt on this worker's own
    // origin, or '' when it is another origin, not http(s) or REST. Only the
    // path and the query come from the message.
    function own(address) {
        var url;
        try {
            url = new URL(String(address), sw.location.href);
        } catch (e) {
            return '';
        }
        if (('http:' !== url.protocol && 'https:' !== url.protocol) || !sameOrigin(url) || isRest(url)) {
            return '';
        }
        return sw.location.origin + url.pathname + url.search;
    }

    function keepable(address) {
        return '' !== own(address);
    }

    function keep(urls) {
        var list = (Array.isArray(urls) ? urls : []).map(own).filter(function (address) {
            return '' !== address;
        });
        return sw.caches.open(CACHE).then(function (cache) {
            return Promise.all(list.map(function (address) {
                return sw.fetch(address, { credentials: 'same-origin', cache: 'no-cache' }).then(function (response) {
                    return response && response.ok ? cache.put(address, response) : null;
                }).catch(function () {
                    return null;
                });
            }));
        });
    }

    function clean() {
        return sw.caches.keys().then(function (names) {
            return Promise.all(names.filter(function (name) {
                return 0 === name.indexOf(PREFIX) && name !== CACHE;
            }).map(function (name) {
                return sw.caches.delete(name);
            }));
        });
    }

    // The document: network first, kept under the scope address, so every
    // way into the page (with or without a trailing query) finds it offline.
    function page(request) {
        var key = scope() || request.url;
        return sw.fetch(request).then(function (response) {
            if (response && response.ok) {
                var copy = response.clone();
                sw.caches.open(CACHE).then(function (cache) {
                    return cache.put(key, copy);
                });
            }
            return response;
        }).catch(function () {
            return sw.caches.open(CACHE).then(function (cache) {
                return cache.match(key);
            }).then(function (cached) {
                return cached || Response.error();
            });
        });
    }

    // A sheet or script: the server confirms it first (`no-cache`, a 304 when
    // it did not change), because on a site whose version does not move
    // between deploys the browser's own cache would hand back the old file
    // under the same address (#2834). The kept copy answers only offline.
    function asset(request) {
        return sw.fetch(request, { cache: 'no-cache' }).catch(function () {
            return sw.caches.open(CACHE).then(function (cache) {
                return cache.match(request);
            }).then(function (cached) {
                return cached || Response.error();
            });
        });
    }

    function route(request) {
        if (!request || 'GET' !== request.method) {
            return null;
        }
        var url = new URL(request.url);
        if (!sameOrigin(url) || isRest(url)) {
            return null;
        }
        if ('navigate' !== request.mode) {
            return asset(request);
        }
        // Only the terminal page is the shell. Another page under the scope,
        // such as the install page (#2813), goes to the network untouched,
        // so it never replaces the terminal's offline copy.
        return isShell(url) ? page(request) : null;
    }

    sw.addEventListener('install', function (event) {
        event.waitUntil(sw.skipWaiting());
    });

    sw.addEventListener('activate', function (event) {
        event.waitUntil(clean().then(function () {
            return sw.clients.claim();
        }));
    });

    // Only a page of this site may ask: a message from any other origin is
    // ignored.
    function trusted(event) {
        return !!event && event.origin === sw.location.origin;
    }

    sw.addEventListener('message', function (event) {
        if (!trusted(event)) {
            return;
        }
        var data = event.data || {};
        if ('ffc-terminal-keep' === data.type) {
            event.waitUntil(keep(data.urls));
        }
    });

    sw.addEventListener('fetch', function (event) {
        var answer = route(event.request);
        if (answer) {
            event.respondWith(answer);
        }
    });

    sw.FFCTerminalShell = { cache: CACHE, keep: keep, clean: clean, route: route, keepable: keepable, own: own, trusted: trusted };
}(self));
