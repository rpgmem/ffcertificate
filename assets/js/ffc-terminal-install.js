/**
 * Check-in terminal install (#2813).
 *
 * Two pieces, both in a browser tab only:
 * - the banner on `/terminal/` (`[data-ffc-terminal-install-banner]`), which
 *   leads to the install page and stays closed for 30 days once closed;
 * - the install page (`[data-ffc-terminal-install-page]`), which shows the one
 *   state that fits the browser: the browser's own install dialog where it
 *   offers one, the Share steps on iPad and iPhone, "Open the terminal" once
 *   installed (inside the installed terminal too), or how to install
 *   elsewhere.
 *
 * Its words are in the markup, so it needs no localized data.
 */
(function (root) {
    'use strict';

    var DAY = 24 * 60 * 60 * 1000;
    var QUIET_DAYS = 30;
    var BANNER_KEY = 'ffc-terminal-install-banner';
    var WAIT_MS = 3000;

    /**
     * Whether the page runs as the installed terminal.
     *
     * @param {Window} win The window.
     * @return {boolean} Whether it is installed and open.
     */
    function isStandalone(win) {
        var media = 'function' === typeof win.matchMedia;
        var installed = media && (win.matchMedia('(display-mode: standalone)').matches || win.matchMedia('(display-mode: fullscreen)').matches);
        return !!installed || !!(win.navigator && true === win.navigator.standalone);
    }

    /**
     * Where Share is for this iOS browser, or null off iOS.
     *
     * `bottom` is Safari on an iPhone; `top` is any browser on an iPad (Safari
     * keeps Share beside the address there) and Chrome, Edge or Firefox on an
     * iPhone with iOS 16.4 or later, which add web apps through their own
     * Share menu; `old` is another browser on an earlier iOS, where only
     * Safari can. An iPad asking for the desktop site reports no version and
     * is taken as recent.
     *
     * @param {Navigator} nav The navigator.
     * @return {string|null} `bottom`, `top`, `old` or null.
     */
    function iosShare(nav) {
        var ua = String((nav && nav.userAgent) || '');
        var ipad = /iPad/.test(ua) || ('MacIntel' === nav.platform && nav.maxTouchPoints > 1);
        var iphone = /iPhone|iPod/.test(ua);
        if (!ipad && !iphone) {
            return null;
        }
        var safari = /Safari\/\d/.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
        if (iphone && safari) {
            return 'bottom';
        }
        if (!safari) {
            var version = /OS (\d+)_(\d+)/.exec(ua);
            if (version) {
                var major = parseInt(version[1], 10);
                var minor = parseInt(version[2], 10);
                if (major < 16 || (16 === major && minor < 4)) {
                    return 'old';
                }
            }
        }
        return 'top';
    }

    function read(storage, key) {
        try {
            return storage ? storage.getItem(key) : null;
        } catch (e) {
            return null;
        }
    }

    function write(storage, key, value) {
        try {
            if (storage) {
                storage.setItem(key, value);
            }
        } catch (e) {
            // Private mode or full storage: the banner simply shows again.
        }
    }

    /**
     * Whether the banner was closed less than 30 days ago.
     *
     * @param {Storage|null} storage Local storage.
     * @param {number}       now     Current time, ms.
     * @return {boolean} Whether to keep it hidden.
     */
    function isQuiet(storage, now) {
        var when = parseInt(read(storage, BANNER_KEY), 10);
        return !isNaN(when) && now - when < QUIET_DAYS * DAY;
    }

    function storageOf(win) {
        try {
            return win.localStorage;
        } catch (e) {
            return null;
        }
    }

    /**
     * Wire the banner on the terminal page.
     *
     * @param {HTMLElement} banner The banner.
     * @param {Window}      win    The window.
     * @param {Function}    now    Returns the current time, ms.
     * @return {boolean} Whether it shows.
     */
    function banner(banner, win, now) {
        var storage = storageOf(win);
        var close = banner.querySelector('[data-ffc-terminal-install-dismiss]');
        if (close) {
            close.addEventListener('click', function () {
                banner.hidden = true;
                write(storage, BANNER_KEY, String(now()));
            });
        }
        banner.hidden = isStandalone(win) || isQuiet(storage, now());
        return !banner.hidden;
    }

    /**
     * Wire the install page: show the one state that fits this browser.
     *
     * @param {HTMLElement} page The `[data-ffc-terminal-install-page]` element.
     * @param {Window}      win  The window.
     * @return {string} The state shown first.
     */
    function page(page, win) {
        var nav = win.navigator || {};
        var panels = page.querySelectorAll('[data-state]');
        var status = page.querySelector('[data-ffc-terminal-install-status]');
        var current = '';
        var show = function (state) {
            current = state;
            Array.prototype.forEach.call(panels, function (panel) {
                panel.hidden = panel.getAttribute('data-state') !== state;
            });
        };

        // Inside the installed terminal there is nothing to install: the
        // state's own link, printed by the server, opens the terminal.
        if (isStandalone(win)) {
            show('installed');
            return current;
        }

        Array.prototype.forEach.call(page.querySelectorAll('[data-ffc-terminal-install-copy]'), function (button) {
            button.addEventListener('click', function () {
                var field = button.closest('[data-state]').querySelector('[data-ffc-terminal-install-address]');
                var say = function (key) {
                    if (status) {
                        status.textContent = page.getAttribute(key) || '';
                    }
                };
                var fallback = function () {
                    field.focus();
                    field.select();
                    say('data-copy-failed');
                };
                if (nav.clipboard && 'function' === typeof nav.clipboard.writeText) {
                    nav.clipboard.writeText(field.value).then(function () {
                        say('data-copied');
                    }, fallback);
                } else {
                    fallback();
                }
            });
        });

        var ios = iosShare(nav);
        if (ios) {
            show('ios-' + ios);
            return current;
        }

        var deferred = null;
        var settled = false;
        var settle = function (state) {
            settled = true;
            show(state);
        };
        show('wait');

        win.addEventListener('beforeinstallprompt', function (event) {
            event.preventDefault();
            deferred = event;
            settle('prompt');
        });
        win.addEventListener('appinstalled', function () {
            deferred = null;
            settle('installed');
        });
        var go = page.querySelector('[data-ffc-terminal-install-go]');
        if (go) {
            go.addEventListener('click', function () {
                if (!deferred) {
                    return;
                }
                var event = deferred;
                deferred = null;
                event.prompt();
                event.userChoice.then(function (choice) {
                    if (choice && 'accepted' === choice.outcome) {
                        settle('installed');
                    }
                });
            });
        }
        // The manifest names the terminal itself, so a browser that supports
        // this answers whether it is already on the device.
        if ('function' === typeof nav.getInstalledRelatedApps) {
            nav.getInstalledRelatedApps().then(function (apps) {
                if (apps && apps.length > 0 && !deferred) {
                    settle('installed');
                }
            }, function () {});
        }
        // A browser that knows the install event but has not fired it yet
        // (Chrome waits for its own checks, and fires nothing when one
        // fails) still installs from its menu; the event, if it comes later,
        // still switches to the button (#2818).
        win.setTimeout(function () {
            if (!settled) {
                settle('onbeforeinstallprompt' in win ? 'menu' : 'unsupported');
            }
        }, WAIT_MS);
        return current;
    }

    /**
     * Wire every piece in a document.
     *
     * @param {Document} doc The document.
     * @param {Window}   win The window.
     */
    function init(doc, win) {
        var now = function () {
            return Date.now();
        };
        Array.prototype.forEach.call(doc.querySelectorAll('[data-ffc-terminal-install-banner]'), function (el) {
            banner(el, win, now);
        });
        Array.prototype.forEach.call(doc.querySelectorAll('[data-ffc-terminal-install-page]'), function (el) {
            page(el, win);
        });
    }

    root.FFCTerminalInstall = {
        isStandalone: isStandalone,
        iosShare: iosShare,
        isQuiet: isQuiet,
        banner: banner,
        page: page,
        init: init,
        WAIT_MS: WAIT_MS,
        BANNER_KEY: BANNER_KEY
    };

    // The script loads in the footer, after the markup; beforeinstallprompt
    // can fire early, so wire at once rather than on load.
    if (root.document && !root.FFCTerminalInstallManual) {
        init(root.document, root);
    }
}(typeof window !== 'undefined' ? window : this));
