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
 * - The document is network-first, kept on every answer, and served from the
 *   cache only when the network fails.
 * - The sheets and scripts the page loaded are kept when the page sends their
 *   addresses, and served from the cache only when the network fails.
 * - REST calls are never touched: they go to the network and fail as they
 *   would without a worker, which is what the page's own queue expects.
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

    function sameOrigin(url) {
        return url.origin === sw.location.origin;
    }

    // The addresses the page asked to keep: same origin, not REST.
    function keepable(address) {
        var url;
        try {
            url = new URL(address, sw.location.href);
        } catch (e) {
            return false;
        }
        return ('http:' === url.protocol || 'https:' === url.protocol) && sameOrigin(url) && !isRest(url);
    }

    function keep(urls) {
        var list = (Array.isArray(urls) ? urls : []).filter(keepable);
        return sw.caches.open(CACHE).then(function (cache) {
            return Promise.all(list.map(function (address) {
                return sw.fetch(address, { credentials: 'same-origin' }).then(function (response) {
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

    function asset(request) {
        return sw.fetch(request).catch(function () {
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
        return 'navigate' === request.mode ? page(request) : asset(request);
    }

    sw.addEventListener('install', function (event) {
        event.waitUntil(sw.skipWaiting());
    });

    sw.addEventListener('activate', function (event) {
        event.waitUntil(clean().then(function () {
            return sw.clients.claim();
        }));
    });

    sw.addEventListener('message', function (event) {
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

    sw.FFCTerminalShell = { cache: CACHE, keep: keep, clean: clean, route: route, keepable: keepable };
}(self));
