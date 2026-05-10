/**
 * FFC User Dashboard — Audience Self-Join subsection
 *
 * Joinable audience-groups tree rendered into the profile panel's
 * `#ffc-audience-join-section` container. Provides join/leave/leave-all
 * actions that reload the profile panel on success.
 *
 * Not a full panel (no tab, no pagination). Exposed at
 * `FFCDashboard.audienceJoin.load()` and called by the profile panel's
 * render after the section container exists in the DOM.
 *
 * @since 6.5.2
 */

(function ($) {
    'use strict';

    var esc = FFCDashboard.helpers.esc;

    function renderJoinableGroups(data) {
        var $section = $('#ffc-audience-join-section');
        var parents = data.parents || [];

        if (parents.length === 0) {
            $section.empty();
            return;
        }

        var s = ffcDashboard.strings;
        var html = '<h3>' + (s.joinGroups || 'Join Groups') + '</h3>';
        html += '<p class="ffc-audience-limit-msg">' +
            (s.joinGroupsDesc || 'Select up to {max} groups to participate in collective calendars.')
                .replace('{max}', data.max_groups) +
            '</p>';

        function renderNodes(nodes, depth, parentColor) {
            var out = '';
            nodes.forEach(function (node) {
                if (node.children && node.children.length > 0) {
                    out += '<div class="ffc-audience-parent-group' + (depth > 0 ? ' ffc-audience-subgroup' : '') + '">';
                    out += '<button type="button" class="ffc-audience-parent-header ffc-audience-accordion-toggle" aria-expanded="false">';
                    out += '<span class="ffc-audience-dot" style="background-color: ' + (node.color || parentColor || '#2271b1') + ';"></span>';
                    out += '<span class="ffc-audience-header-name">' + esc(node.name) + '</span>';
                    out += '<span class="ffc-audience-toggle-icon">+</span>';
                    out += '</button>';
                    out += '<div class="ffc-audience-children-list ffc-audience-collapsed">';
                    out += renderNodes(node.children, depth + 1, node.color || parentColor);
                    out += '</div></div>';
                } else {
                    out += '<div class="ffc-audience-join-item' + (node.is_member ? ' is-member' : '') + '" data-group-id="' + node.id + '">';
                    out += '<span class="ffc-audience-name">';
                    out += '<span class="ffc-audience-dot" style="background-color: ' + (node.color || parentColor || '#2271b1') + ';"></span>';
                    out += esc(node.name);
                    out += '</span>';

                    if (node.is_member) {
                        out += '<button type="button" class="button ffc-audience-leave-btn" data-id="' + node.id + '">' + (s.leaveGroup || 'Leave') + '</button>';
                    } else {
                        var disabled = (data.joined_count >= data.max_groups) ? ' disabled' : '';
                        out += '<button type="button" class="button button-primary ffc-audience-join-btn" data-id="' + node.id + '"' + disabled + '>' + (s.joinGroup || 'Join') + '</button>';
                    }
                    out += '</div>';
                }
            });
            return out;
        }

        html += renderNodes(parents, 0, null);

        $section.html(html);

        $section.on('click', '.ffc-audience-accordion-toggle', function () {
            var $btn = $(this);
            var $list = $btn.next('.ffc-audience-children-list');
            var expanded = $btn.attr('aria-expanded') === 'true';

            $btn.attr('aria-expanded', !expanded);
            $btn.find('.ffc-audience-toggle-icon').text(expanded ? '+' : '−');
            $list.toggleClass('ffc-audience-collapsed');
        });
    }

    function joinGroup(groupId) {
        var $btn = $('.ffc-audience-join-btn[data-id="' + groupId + '"]');
        $btn.prop('disabled', true).text(ffcDashboard.strings.saving || 'Saving...');

        var url = ffcDashboard.restUrl + 'user/audience-group/join';
        if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

        $.ajax({
            url: url,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ group_id: groupId }),
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
            success: function () { FFCDashboard.panels.profile.reload(); },
            error: function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || (ffcDashboard.strings.error || 'Error');
                alert(msg);
                $btn.prop('disabled', false).text(ffcDashboard.strings.joinGroup || 'Join');
            }
        });
    }

    function leaveGroup(groupId) {
        if (!confirm(ffcDashboard.strings.confirmLeaveGroup || 'Leave this group?')) return;

        var $btn = $('.ffc-audience-leave-btn[data-id="' + groupId + '"]');
        $btn.prop('disabled', true).text(ffcDashboard.strings.saving || 'Saving...');

        var url = ffcDashboard.restUrl + 'user/audience-group/leave';
        if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

        $.ajax({
            url: url,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ group_id: groupId }),
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
            success: function () { FFCDashboard.panels.profile.reload(); },
            error: function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || (ffcDashboard.strings.error || 'Error');
                alert(msg);
                $btn.prop('disabled', false).text(ffcDashboard.strings.leaveGroup || 'Leave');
            }
        });
    }

    function leaveAllGroups() {
        var profile = FFCDashboard.panels.profile.state;
        var groups = profile && profile.audience_groups;
        var count = groups ? groups.length : 0;
        if (!count) return;

        var msg = (ffcDashboard.strings.confirmLeaveAllGroups || 'Are you sure you want to leave all %d group(s)? This action cannot be undone.')
            .replace('%d', count);
        if (!confirm(msg)) return;

        var $btn = $('.ffc-leave-all-groups-btn');
        $btn.prop('disabled', true).text(ffcDashboard.strings.saving || 'Saving...');

        var url = ffcDashboard.restUrl + 'user/audience-group/leave-all';
        if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

        $.ajax({
            url: url,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({}),
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
            success: function () { FFCDashboard.panels.profile.reload(); },
            error: function (xhr) {
                var m = (xhr.responseJSON && xhr.responseJSON.message) || (ffcDashboard.strings.error || 'Error');
                alert(m);
                $btn.prop('disabled', false).text(ffcDashboard.strings.leaveAllGroups || 'Leave all groups');
            }
        });
    }

    FFCDashboard.audienceJoin = {
        load: function () {
            var $section = $('#ffc-audience-join-section');
            if ($section.length === 0) return;

            var url = ffcDashboard.restUrl + 'user/joinable-groups';
            if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

            $.ajax({
                url: url,
                method: 'GET',
                beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
                success: function (data) { renderJoinableGroups(data); },
                error: function () { $section.empty(); }
            });
        }
    };

    // Self-bind action handlers at parse time (delegated; safe before DOM ready).
    $(document).on('click', '.ffc-audience-join-btn', function (e) {
        e.preventDefault();
        joinGroup($(this).data('id'));
    });
    $(document).on('click', '.ffc-audience-leave-btn', function (e) {
        e.preventDefault();
        leaveGroup($(this).data('id'));
    });
    $(document).on('click', '.ffc-leave-all-groups-btn', function (e) {
        e.preventDefault();
        leaveAllGroups();
    });

})(jQuery);
