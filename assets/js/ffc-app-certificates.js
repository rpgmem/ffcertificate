/**
 * Certificates screen of the installable app (#2781).
 *
 * Reads `user/certificates`, the route the dashboard reads, and draws the
 * newest certificate as a featured card and the rest as compact rows. The
 * search filters by course or code as the person types.
 *
 * @since 6.50.0
 */
(function (root) {
    'use strict';

    /**
     * Fill %s placeholders in order.
     *
     * @param {string} text Template.
     * @return {string}
     */
    function format(text) {
        var args = Array.prototype.slice.call(arguments, 1);
        var next = 0;
        return String(text).replace(/%s/g, function () {
            var value = args[next++];
            return undefined === value ? '' : String(value);
        });
    }

    /**
     * An element with a class and text.
     *
     * @param {string} tag       Tag.
     * @param {string} className Class.
     * @param {string} [text]    Text.
     * @return {HTMLElement}
     */
    function withClass(tag, className, text) {
        var node = root.document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (undefined !== text) {
            node.textContent = text;
        }
        return node;
    }

    function Screen(node, config, deps) {
        this.node = node;
        this.config = config;
        this.fetch = deps.fetch;
        this.now = deps.now;
        this.share = deps.share;
        this.copy = deps.copy;
        this.items = [];
    }

    Screen.prototype.part = function (name) {
        return this.node.querySelector('[data-ffc-app-part="' + name + '"]');
    };

    Screen.prototype.say = function (text) {
        var status = this.part('status');
        if (status) {
            status.textContent = text || '';
        }
    };

    Screen.prototype.start = function () {
        var self = this;
        var strings = self.config.strings;
        var search = self.part('search');
        if (search) {
            search.addEventListener('input', function () {
                self.draw();
            });
        }
        self.say(strings.loading);
        return Promise.resolve().then(function () {
            return self.fetch(self.config.rest, {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': self.config.nonce }
            });
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('request failed');
            }
            return response.json();
        }).then(function (data) {
            self.items = data && Array.isArray(data.certificates) ? data.certificates : [];
            self.say('');
            self.draw();
        }).catch(function () {
            self.say(strings.failed);
        });
    };

    Screen.prototype.matching = function () {
        var search = this.part('search');
        var query = search ? search.value.trim().toLowerCase() : '';
        if (!query) {
            return this.items;
        }
        var compact = query.replace(/[^a-z0-9]/g, '');
        return this.items.filter(function (item) {
            var title = String(item.form_title || '').toLowerCase();
            var code = String(item.auth_code || '').toLowerCase().replace(/[^a-z0-9]/g, '');
            return title.indexOf(query) !== -1 || ('' !== compact && code.indexOf(compact) !== -1);
        });
    };

    Screen.prototype.draw = function () {
        var strings = this.config.strings;
        var featured = this.part('featured');
        var list = this.part('list');
        if (!featured || !list) {
            return;
        }
        featured.textContent = '';
        list.textContent = '';
        if (!this.items.length) {
            this.say(strings.empty);
            return;
        }
        var items = this.matching();
        this.say(items.length ? '' : strings.noMatch);
        if (!items.length) {
            return;
        }
        featured.appendChild(this.card(items[0]));
        for (var i = 1; i < items.length; i++) {
            list.appendChild(this.row(items[i]));
        }
    };

    Screen.prototype.isNew = function (item) {
        var issued = Number(item.submission_date_raw) || 0;
        return issued > 0 && (this.now() / 1000) - issued < this.config.newDays * 86400;
    };

    Screen.prototype.card = function (item) {
        var self = this;
        var strings = self.config.strings;
        var card = withClass('article', 'ffc-app-certificate-card');
        var top = withClass('p', 'ffc-app-certificate-meta');
        top.appendChild(withClass('span', 'ffc-app-certificate-status ffc-app-certificate-status-valid', strings.valid));
        if (self.isNew(item)) {
            top.appendChild(withClass('span', 'ffc-app-certificate-badge', strings.new));
        }
        top.appendChild(withClass('span', '', format(strings.issued, item.submission_date || '')));
        card.appendChild(top);
        card.appendChild(withClass('h2', 'ffc-app-certificate-title', item.form_title || ''));
        if (item.auth_code) {
            card.appendChild(withClass('p', 'ffc-app-certificate-code', format(strings.code, item.auth_code)));
        }
        var actions = withClass('div', 'ffc-app-certificate-actions');
        if (item.pdf_url) {
            var open = withClass('a', 'ffc-btn ffc-btn-primary ffc-icon-download', strings.open);
            open.href = item.pdf_url;
            open.target = '_blank';
            open.rel = 'noopener';
            actions.appendChild(open);
            var share = withClass('button', 'ffc-btn ffc-btn-neutral ffc-icon-share', strings.share);
            share.type = 'button';
            share.addEventListener('click', function () {
                self.shareLink(item);
            });
            actions.appendChild(share);
        } else {
            actions.appendChild(withClass('p', 'ffc-app-certificate-note', strings.noAccess));
        }
        card.appendChild(actions);
        return card;
    };

    Screen.prototype.row = function (item) {
        var strings = this.config.strings;
        var row = withClass('li', 'ffc-app-certificate-row');
        var body = withClass(item.pdf_url ? 'a' : 'div', 'ffc-app-certificate-link');
        if (item.pdf_url) {
            body.href = item.pdf_url;
            body.target = '_blank';
            body.rel = 'noopener';
        }
        var text = withClass('span', 'ffc-app-certificate-text');
        text.appendChild(withClass('span', 'ffc-app-certificate-name', item.form_title || ''));
        text.appendChild(withClass('span', 'ffc-app-certificate-when', [item.submission_date, item.auth_code ? format(strings.code, item.auth_code) : ''].filter(Boolean).join(' · ')));
        body.appendChild(text);
        if (item.pdf_url) {
            body.appendChild(withClass('span', 'ffc-app-certificate-chevron ffc-icon-chevron-right'));
        }
        row.appendChild(body);
        return row;
    };

    Screen.prototype.shareLink = function (item) {
        var self = this;
        var strings = self.config.strings;
        if (self.share) {
            return Promise.resolve().then(function () {
                return self.share({ title: item.form_title || '', url: item.pdf_url });
            }).catch(function () {
                return null;
            });
        }
        if (self.copy) {
            return Promise.resolve().then(function () {
                return self.copy(item.pdf_url);
            }).then(function () {
                self.say(strings.copied);
            }).catch(function () {
                self.say(strings.noShare);
            });
        }
        self.say(strings.noShare);
        return Promise.resolve();
    };

    /**
     * Start every Certificates screen on the page.
     *
     * @param {Object} [deps] Test seams: fetch, now, share, copy.
     * @return {Screen[]}
     */
    function boot(deps) {
        var config = root.ffcAppCertificates;
        if (!config) {
            return [];
        }
        var nav = root.navigator || {};
        deps = deps || {};
        var options = {
            fetch: deps.fetch || root.fetch.bind(root),
            now: deps.now || Date.now,
            share: undefined !== deps.share ? deps.share : (nav.share ? nav.share.bind(nav) : null),
            copy: undefined !== deps.copy ? deps.copy : (nav.clipboard ? nav.clipboard.writeText.bind(nav.clipboard) : null)
        };
        return Array.prototype.map.call(root.document.querySelectorAll('[data-ffc-app-certificates]'), function (node) {
            var screen = new Screen(node, config, options);
            screen.start();
            return screen;
        });
    }

    root.FFCAppCertificates = { boot: boot };

    if ('loading' === root.document.readyState) {
        root.document.addEventListener('DOMContentLoaded', function () { boot(); });
    } else {
        boot();
    }
}(window));
