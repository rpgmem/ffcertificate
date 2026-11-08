/**
 * FFC User Dashboard — Messages panel (#2503)
 *
 * What the date-messages module sent the person: the current announcements
 * and the earlier messages, each with its own pager of three, and the two
 * message preferences beside them. The list is built by the server from the
 * rules as they are now; nothing is stored per person.
 *
 * @since 6.43.0
 */

(function ($) {
    'use strict';

    var helpers = FFCDashboard.helpers;

    var SECTION_ORDER = ['messagesCurrent', 'messagesEarlier'];

    function url() {
        var u = ffcDashboard.restUrl + 'user/messages';
        if (ffcDashboard.viewAsUserId) u += '?viewAsUserId=' + ffcDashboard.viewAsUserId;
        return u;
    }

    function sectionLabel(key) {
        var s = ffcDashboard.strings;
        return key === 'messagesCurrent' ? (s.messagesCurrent || 'Current') : (s.messagesEarlier || 'Earlier');
    }

    function sectionHint(key, days) {
        var s = ffcDashboard.strings;
        if (key === 'messagesCurrent') {
            return (s.messagesCurrentHint || 'Announcements sent in the last %d days').replace('%d', days);
        }
        return s.messagesEarlierHint || 'Date messages, and announcements past that window';
    }

    /**
     * One message: subject, kind, date and the Open / Close control; the
     * body is in the markup and hidden until opened.
     *
     * @param {Object}  message Item from the REST payload.
     * @param {boolean} open    Whether it is drawn open.
     * @returns {string}
     */
    function buildItem(message, open) {
        var s = ffcDashboard.strings;
        var bodyId = 'ffc-message-body-' + helpers.classSlug(message.key);
        var isAnnouncement = message.kind === 'announcement';
        var kind = isAnnouncement ? (s.kindAnnouncement || 'Announcement') : (s.kindDateMessage || 'Date message');

        var html = '<li class="ffc-message-item' + (open ? ' is-open' : '') + '" data-message="' + helpers.escAttr(message.key) + '">';
        html += '<div class="ffc-message-head">';
        html += '<div class="ffc-message-title">';
        html += '<span class="ffc-message-subject">' + helpers.esc(message.subject) + '</span> ';
        html += '<span class="ffc-message-kind ffc-message-kind-' + (isAnnouncement ? 'announcement' : 'dated') + '">' + helpers.esc(kind) + '</span>';
        html += '<span class="ffc-message-meta">' + helpers.esc(message.sent) + ' · ' + helpers.esc(s.alsoByEmail || 'Also sent by e-mail') + '</span>';
        html += '</div>';
        html += FFC.buttonHtml({
            label: open ? (s.closeMessage || 'Close') : (s.openMessage || 'Open'),
            category: 'neutral',
            icon: open ? 'chevron-up' : 'chevron-down',
            className: 'ffc-message-toggle',
            attrs: { 'aria-expanded': open ? 'true' : 'false', 'aria-controls': bodyId }
        });
        html += '</div>';
        // The body is the rule's own HTML, built and filtered (wp_kses_post)
        // by the server.
        html += '<div class="ffc-message-body" id="' + bodyId + '"' + (open ? '' : ' hidden') + '>' + (message.body || '') + '</div>';
        return html + '</li>';
    }

    function buildPreference(key, label, desc, on) {
        return '<label class="ffc-control-toggle ffc-control-toggle-mark ffc-message-pref">' +
            '<input type="checkbox" class="ffc-message-pref-toggle" data-key="' + helpers.escAttr(key) + '"' + (on ? ' checked' : '') + ' />' +
            '<span class="ffc-control-toggle-track" aria-hidden="true"></span>' +
            '<span class="ffc-control-toggle-label">' + helpers.esc(label) +
            '<small class="ffc-message-pref-desc">' + helpers.esc(desc) + '</small></span></label>';
    }

    function savePreferences() {
        var prefs = {};
        $('.ffc-message-pref-toggle').each(function () {
            prefs[$(this).data('key')] = $(this).is(':checked');
        });
        var $status = $('.ffc-message-pref-status');
        var profileUrl = ffcDashboard.restUrl + 'user/profile';
        if (ffcDashboard.viewAsUserId) profileUrl += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

        FFC.rest(profileUrl, { method: 'PUT', data: { preferences: prefs }, nonce: ffcDashboard.nonce })
            .then(function () {
                var panel = FFCDashboard.panels.messages;
                if (panel.state) $.extend(panel.state.preferences, prefs);
                helpers.setStatus($status, ffcDashboard.strings.notifSaved || 'Saved', 'success').show();
                setTimeout(function () { $status.fadeOut(); }, 2000);
            })
            .catch(function () {
                helpers.setStatus($status, ffcDashboard.strings.saveError || 'Error', 'error').show();
            });
    }

    FFCDashboard.panels.messages = {
        state: null,
        sectionPages: {},
        openKeys: {},
        pendingOpen: null,

        bindEvents: function () {
            var self = this;
            $(document).on('click', '.ffc-message-toggle', function (e) {
                e.preventDefault();
                self.toggle($(this).closest('.ffc-message-item'));
            });
            $(document).on('change', '.ffc-message-pref-toggle', function () { savePreferences(); });
            // "Read in Messages" on a notice at the top: the tab, with that
            // message open, without leaving the page.
            $(document).on('click', '[data-ffc-open-tab="messages"]', function (e) {
                var $tab = $('#ffc-tab-messages');
                if ($tab.length === 0) return;
                e.preventDefault();
                self.pendingOpen = String($(this).data('ffc-message') || '');
                if (self.state) self.openPending();
                FFCDashboard.activateTab($tab, true);
                $tab.trigger('focus');
            });
        },

        load: function () {
            if (this.state) {
                this.render(this.state);
                return;
            }
            var self = this;
            var $container = $('#ffc-tabpanel-messages');
            FFC.rest(url(), { nonce: ffcDashboard.nonce })
                .then(function (data) {
                    self.state = {
                        current: data.current || [],
                        earlier: data.earlier || [],
                        noticeDays: data.noticeDays || 7,
                        preferences: data.preferences || {}
                    };
                    self.openPending();
                    self.render(self.state);
                })
                .catch(function (err) {
                    $container.html('<div class="ffc-error">' + helpers.esc(helpers.errorMessage(err, ffcDashboard.strings.error || 'Error loading data')) + '</div>');
                });
        },

        // Open the message a notice pointed at, on the page that holds it.
        openPending: function () {
            if (!this.pendingOpen || !this.state) return;
            var key = this.pendingOpen;
            this.pendingOpen = null;
            var self = this;
            SECTION_ORDER.forEach(function (section) {
                var items = self.items(section);
                for (var i = 0; i < items.length; i++) {
                    if (items[i].key === key) {
                        self.openKeys[key] = true;
                        self.sectionPages[section] = Math.floor(i / helpers.sectionPageSize(section)) + 1;
                    }
                }
            });
            if ($('#ffc-tabpanel-messages .ffc-messages').length) this.render(this.state);
        },

        items: function (section) {
            if (!this.state) return [];
            return section === 'messagesCurrent' ? this.state.current : this.state.earlier;
        },

        toggle: function ($item) {
            var key = String($item.data('message'));
            var open = !$item.hasClass('is-open');
            this.openKeys[key] = open;
            var message = null;
            var self = this;
            SECTION_ORDER.forEach(function (section) {
                self.items(section).forEach(function (m) { if (m.key === key) message = m; });
            });
            if (!message) return;
            var $next = $(buildItem(message, open));
            $item.replaceWith($next);
            $next.find('.ffc-message-toggle').trigger('focus');
        },

        render: function (state) {
            var s = ffcDashboard.strings;
            var $container = $('#ffc-tabpanel-messages');
            var html = '<div class="ffc-messages">';

            html += '<div class="ffc-messages-list ffc-profile-section">';
            html += '<h3>' + helpers.esc(s.receivedMessages || 'Received messages') + '</h3>';
            if (state.current.length === 0 && state.earlier.length === 0) {
                html += '<p class="ffc-messages-empty"><span class="ffc-icon-email" aria-hidden="true"></span>' +
                    helpers.esc(s.noMessages || 'No messages yet. Date messages and announcements sent to you will show here.') + '</p>';
            } else {
                var self = this;
                SECTION_ORDER.forEach(function (key) {
                    html += self.buildSectionHtml(key);
                });
            }
            html += '</div>';

            var prefs = state.preferences || {};
            html += '<div class="ffc-messages-prefs ffc-profile-section">';
            html += '<h3>' + helpers.esc(s.messagePreferences || 'Message preferences') + '</h3>';
            html += '<div class="ffc-notif-list">';
            html += buildPreference('notify_date_messages', s.notifDateMessages || 'Date messages', s.notifDateMessagesDesc || 'Such as birthday greetings.', prefs.notify_date_messages !== false);
            html += buildPreference('notify_date_announcements', s.notifDateAnnouncements || 'Announcements from this site', s.notifDateAnnouncementsDesc || '', prefs.notify_date_announcements !== false);
            html += '<p class="ffc-notif-hint">' + helpers.esc(s.notifDateMessagesHint || 'The unsubscribe link at the end of these e-mails turns off the same preference.') + '</p>';
            html += '</div>';
            html += '<span class="ffc-message-pref-status ffc-status" role="status" aria-live="polite" hidden></span>';
            html += '</div>';

            html += '</div>';
            $container.html(html);
        },

        // One group at its current page: heading with count, a hint, the
        // items and the shared pager (#1836). The core's page handler
        // rebuilds only this section.
        buildSectionHtml: function (key) {
            var s = ffcDashboard.strings;
            var items = this.items(key);
            var size = helpers.sectionPageSize(key);
            var page = helpers.clampSectionPage(this.sectionPages[key] || 1, items.length, size);
            var start = (page - 1) * size;
            var self = this;

            var html = '<section class="ffc-dashboard-section ffc-messages-section" data-section="' + helpers.escAttr(key) + '">';
            html += helpers.buildSectionHeading(sectionLabel(key), items.length);
            html += '<p class="ffc-messages-hint">' + helpers.esc(sectionHint(key, this.state ? this.state.noticeDays : 7)) + '</p>';
            if (items.length === 0) {
                html += '<p class="ffc-messages-none">' + helpers.esc(key === 'messagesCurrent' ? (s.noCurrentMessages || 'No current announcements.') : (s.noEarlierMessages || 'No earlier messages.')) + '</p>';
            } else {
                html += '<ul class="ffc-message-list">';
                items.slice(start, start + size).forEach(function (message) {
                    html += buildItem(message, !!self.openKeys[message.key]);
                });
                html += '</ul>';
            }
            html += helpers.buildSectionPager(items.length, page, size, 'messages', key, sectionLabel(key));
            html += '</section>';
            return html;
        }
    };

})(jQuery);
