/**
 * FFC User Dashboard — Audience Self-Join subsection
 *
 * Joinable audience-groups tree rendered into the profile panel's
 * `#ffc-audience-join-section` container. Provides join/leave/leave-all
 * and switch actions that reload the profile panel on success.
 *
 * A tree may keep its members in at least one group (#1918): the server
 * marks that last group `leave_locked` and offers "Switch to this" on the
 * tree's other groups. "Leave all groups" keeps the first group joined in
 * each such tree; the panel says which, in a notice above the list.
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

    // The last payload rendered, read by "Leave all groups" and the switch.
    var lastData = null;
    var treesById = {};
    var namesById = {};
    // Lines of the notice "Leave all groups" leaves for the next render.
    var pendingKept = null;

    // "You stay in <group> because <audience> requires at least one group."
    function keptLines(kept) {
        var line = ffcDashboard.strings.leaveAllKeptLine || 'You stay in "%1$s" because "%2$s" requires at least one group.';
        return kept.map(function (k) {
            return line.replace('%1$s', k.group_name).replace('%2$s', k.tree_name);
        });
    }

    function keptNotice() {
        if (!pendingKept) return null;
        var notice = window.FFC.notice('info', 'info', pendingKept, ffcDashboard.strings.leaveAllKeptTitle || 'Some groups were kept', { role: 'status' });
        pendingKept = null;
        return notice;
    }

    function renderJoinableGroups(data) {
        var $section = $('#ffc-audience-join-section');
        var parents = data.parents || [];

        lastData = data;
        treesById = {};
        namesById = {};
        (data.trees || []).forEach(function (tree) { treesById[tree.id] = tree; });

        if (parents.length === 0) {
            $section.empty().append(keptNotice());
            return;
        }

        var s = ffcDashboard.strings;
        var html = '<h3>' + (s.joinGroups || 'Join Groups') + '</h3>';
        html += '<p class="ffc-audience-limit-msg">' +
            (s.joinGroupsDesc || 'Select up to {max} groups to participate in collective calendars.')
                .replace('{max}', data.max_groups) +
            '</p>';

        // Per-node model (#792): a node may carry BOTH a join/leave button
        // (its own `joinable`) AND a nested `children` list. Three shapes:
        //   - button only   → a bare join-item row (as before).
        //   - children only → a header row that expands the children (as before).
        //   - button + children → a join-item row whose actions include the
        //     accordion toggle, with the children list below it.
        // Join mirrors Leave: `create` with the login icon against `danger`
        // with the logout one. "Switch to this" replaces a group the user
        // holds in the same tree, in one request (#1918): offered when the
        // limit stops a plain Join, and when the user's only group of a tree
        // that must keep them cannot be left.
        function joinLeaveButton(node) {
            namesById[node.id] = node.name;
            if (node.is_member) {
                var leaveAttrs = { 'data-id': node.id };
                if (node.leave_locked) {
                    leaveAttrs['aria-describedby'] = 'ffc-audience-locked-' + node.id;
                }
                return window.FFC.buttonHtml({ label: s.leaveGroup || 'Leave', category: 'danger', icon: 'logout', className: 'ffc-audience-leave-btn', disabled: !!node.leave_locked, attrs: leaveAttrs });
            }
            var tree = treesById[node.tree_id] || {};
            var held = tree.held || [];
            var atLimit = data.joined_count >= data.max_groups;
            var canSwitch = held.length > 0 && (atLimit || (!!tree.require_membership && tree.total === 1));
            var out = '';
            if (!(atLimit && canSwitch)) {
                out += window.FFC.buttonHtml({
                    label: s.joinGroup || 'Join',
                    category: 'create',
                    icon: 'login',
                    className: 'ffc-audience-join-btn',
                    disabled: atLimit,
                    attrs: { 'data-id': node.id }
                });
            }
            if (canSwitch) {
                out += window.FFC.buttonHtml({
                    label: s.switchGroup || 'Switch to this',
                    category: 'caution',
                    icon: 'switch',
                    className: 'ffc-audience-switch-btn',
                    attrs: { 'data-id': node.id, 'data-tree': node.tree_id }
                });
            }
            return out;
        }

        // Why the Leave button above is disabled, tied to it by
        // aria-describedby.
        function lockedNote(node) {
            if (!node.is_member || !node.leave_locked) return '';
            return '<p class="ffc-audience-locked-note" id="ffc-audience-locked-' + node.id + '">' +
                esc(s.leaveLocked || 'This audience keeps its members in at least one group. Join or switch to another of its groups before leaving this one.') +
                '</p>';
        }

        // The dot takes the group's colour, an operator's choice, so it stays
        // inline; anything that is not a hex colour is dropped and the dot
        // keeps the sheet's own ground, the palette's primary (#1826).
        function dot(color) {
            return '<span class="ffc-audience-dot"' + (color ? ' style="background-color: ' + color + ';"' : '') + '></span>';
        }

        function renderNodes(nodes, depth, parentColor) {
            var out = '';
            nodes.forEach(function (node) {
                var color = /^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/.test(node.color || '') ? node.color : (parentColor || '');
                var hasChildren = node.children && node.children.length > 0;

                if (node.joinable && hasChildren) {
                    // Combined: own button + expandable children.
                    out += '<div class="ffc-audience-parent-group' + (depth > 0 ? ' ffc-audience-subgroup' : '') + '">';
                    out += '<div class="ffc-audience-join-item' + (node.is_member ? ' is-member' : '') + '" data-group-id="' + node.id + '">';
                    out += '<span class="ffc-audience-name">';
                    out += dot(color);
                    out += esc(node.name);
                    out += '</span>';
                    out += '<span class="ffc-audience-item-actions">';
                    out += joinLeaveButton(node);
                    out += '<button type="button" class="ffc-audience-accordion-toggle ffc-audience-inline-toggle" aria-expanded="false" aria-label="' + esc(s.showSubgroups || 'Show subgroups') + '">';
                    out += '<span class="ffc-audience-toggle-icon">+</span>';
                    out += '</button>';
                    out += '</span>';
                    out += '</div>';
                    out += lockedNote(node);
                    out += '<div class="ffc-audience-children-list ffc-audience-collapsed">';
                    out += renderNodes(node.children, depth + 1, color);
                    out += '</div></div>';
                } else if (hasChildren) {
                    // Header only: non-joinable parent kept visible for its children.
                    out += '<div class="ffc-audience-parent-group' + (depth > 0 ? ' ffc-audience-subgroup' : '') + '">';
                    out += '<button type="button" class="ffc-audience-parent-header ffc-audience-accordion-toggle" aria-expanded="false">';
                    out += dot(color);
                    out += '<span class="ffc-audience-header-name">' + esc(node.name) + '</span>';
                    out += '<span class="ffc-audience-toggle-icon">+</span>';
                    out += '</button>';
                    out += '<div class="ffc-audience-children-list ffc-audience-collapsed">';
                    out += renderNodes(node.children, depth + 1, color);
                    out += '</div></div>';
                } else if (node.joinable) {
                    // Button only: a bare join-item leaf.
                    out += '<div class="ffc-audience-join-item' + (node.is_member ? ' is-member' : '') + '" data-group-id="' + node.id + '">';
                    out += '<span class="ffc-audience-name">';
                    out += dot(color);
                    out += esc(node.name);
                    out += '</span>';
                    out += '<span class="ffc-audience-item-actions">' + joinLeaveButton(node) + '</span>';
                    out += '</div>';
                    out += lockedNote(node);
                }
            });
            return out;
        }

        html += renderNodes(parents, 0, null);

        $section.html(html);
        $section.prepend(keptNotice());

        $section.on('click', '.ffc-audience-accordion-toggle', function () {
            var $btn = $(this);
            // Robust across both header-only and combined shapes: the list is
            // always the direct child of the node's own parent-group wrapper.
            var $list = $btn.closest('.ffc-audience-parent-group').children('.ffc-audience-children-list').first();
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

        FFC.rest(url, { method: 'POST', data: { group_id: groupId }, nonce: ffcDashboard.nonce })
            .then(function (response) {
                // The group's bookings already made are now the member's
                // too (#2243): say so before the panel redraws.
                if (response && response.upcoming > 0 && response.message) {
                    alert(response.message);
                }
                FFCDashboard.panels.profile.reload();
            })
            .catch(function (err) {
                var msg = (err.xhr && err.xhr.responseJSON && err.xhr.responseJSON.message) || (ffcDashboard.strings.error || 'Error');
                alert(msg);
                $btn.prop('disabled', false).text(ffcDashboard.strings.joinGroup || 'Join');
            });
    }

    // Ask what leaving takes out of the list first (#2243); a failed
    // question still lets the person leave, with the plain confirmation.
    function leaveGroup(groupId) {
        var query = { group_id: groupId };
        if (ffcDashboard.viewAsUserId) query.viewAsUserId = ffcDashboard.viewAsUserId;

        FFC.rest(ffcDashboard.restUrl + 'user/audience-group/leave-impact', { method: 'GET', data: query, nonce: ffcDashboard.nonce })
            .then(function (response) { return (response && response.message) || ''; })
            .catch(function () { return ''; })
            .then(function (impact) {
                var question = ffcDashboard.strings.confirmLeaveGroup || 'Leave this group?';
                if (!confirm(impact ? impact + '\n\n' + question : question)) return;
                sendLeave(groupId);
            });
    }

    function sendLeave(groupId) {
        var $btn = $('.ffc-audience-leave-btn[data-id="' + groupId + '"]');
        $btn.prop('disabled', true).text(ffcDashboard.strings.saving || 'Saving...');

        var url = ffcDashboard.restUrl + 'user/audience-group/leave';
        if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

        FFC.rest(url, { method: 'POST', data: { group_id: groupId }, nonce: ffcDashboard.nonce })
            .then(function () { FFCDashboard.panels.profile.reload(); })
            .catch(function (err) {
                var msg = (err.xhr && err.xhr.responseJSON && err.xhr.responseJSON.message) || (ffcDashboard.strings.error || 'Error');
                alert(msg);
                $btn.prop('disabled', false).text(ffcDashboard.strings.leaveGroup || 'Leave');
            });
    }

    // Leave `from` and join `to` in one request (#1918).
    function switchGroup(fromId, toId) {
        var $btn = $('.ffc-audience-switch-btn[data-id="' + toId + '"], .ffc-audience-switch-confirm');
        $btn.prop('disabled', true);

        var url = ffcDashboard.restUrl + 'user/audience-group/switch';
        if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

        FFC.rest(url, { method: 'POST', data: { from_group_id: fromId, group_id: toId }, nonce: ffcDashboard.nonce })
            .then(function () { FFCDashboard.panels.profile.reload(); })
            .catch(function (err) {
                var msg = (err.xhr && err.xhr.responseJSON && err.xhr.responseJSON.message) || (ffcDashboard.strings.error || 'Error');
                alert(msg);
                $btn.prop('disabled', false);
            });
    }

    // One group held in the tree: confirm and switch. Several: ask which
    // one to replace, in a small picker under the target's row.
    function startSwitch($trigger) {
        var s = ffcDashboard.strings;
        var toId = parseInt($trigger.attr('data-id'), 10);
        var tree = treesById[parseInt($trigger.attr('data-tree'), 10)] || {};
        var held = tree.held || [];
        var toName = namesById[toId] || '';
        if (held.length === 0) return;

        if (held.length === 1) {
            var msg = (s.confirmSwitchGroup || 'Leave "%1$s" and join "%2$s"?')
                .replace('%1$s', held[0].name).replace('%2$s', toName);
            if (confirm(msg)) switchGroup(held[0].id, toId);
            return;
        }

        $('.ffc-audience-switch-picker').remove();
        var pickerId = 'ffc-audience-switch-from-' + toId;
        var html = '<div class="ffc-audience-switch-picker" role="group" data-id="' + toId + '">';
        html += '<label for="' + pickerId + '">' + esc((s.switchWhich || 'Replace which group with "%s"?').replace('%s', toName)) + '</label>';
        html += '<select id="' + pickerId + '" class="ffc-audience-switch-from">';
        held.forEach(function (g) {
            html += '<option value="' + g.id + '">' + esc(g.name) + '</option>';
        });
        html += '</select>';
        html += window.FFC.buttonHtml({ label: s.switchConfirm || 'Switch', category: 'caution', icon: 'switch', className: 'ffc-audience-switch-confirm', attrs: { 'data-id': toId } });
        html += window.FFC.buttonHtml({ label: s.cancel || 'Cancel', category: 'neutral', icon: 'cross', className: 'ffc-audience-switch-cancel' });
        html += '</div>';
        $trigger.closest('.ffc-audience-join-item').after(html);
        $('#' + pickerId).trigger('focus');
    }

    function leaveAllGroups() {
        var profile = FFCDashboard.panels.profile.state;
        var groups = profile && profile.audience_groups;
        var count = groups ? groups.length : 0;
        if (!count) return;

        var msg = (ffcDashboard.strings.confirmLeaveAllGroups || 'Are you sure you want to leave all %d group(s)? This action cannot be undone.')
            .replace('%d', count);
        // A tree that keeps its members stays at one group (#1918): say which
        // before asking.
        var keeps = (lastData && lastData.leave_all_keeps) || [];
        if (keeps.length > 0) {
            msg += '\n\n' + keptLines(keeps).join('\n');
        }
        if (!confirm(msg)) return;

        var $btn = $('.ffc-leave-all-groups-btn');
        $btn.prop('disabled', true).text(ffcDashboard.strings.saving || 'Saving...');

        var url = ffcDashboard.restUrl + 'user/audience-group/leave-all';
        if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

        FFC.rest(url, { method: 'POST', data: {}, nonce: ffcDashboard.nonce })
            .then(function (res) {
                var kept = (res && res.kept) || [];
                pendingKept = kept.length > 0 ? keptLines(kept) : null;
                FFCDashboard.panels.profile.reload();
            })
            .catch(function (err) {
                var m = (err.xhr && err.xhr.responseJSON && err.xhr.responseJSON.message) || (ffcDashboard.strings.error || 'Error');
                alert(m);
                $btn.prop('disabled', false).text(ffcDashboard.strings.leaveAllGroups || 'Leave all groups');
            });
    }

    FFCDashboard.audienceJoin = {
        load: function () {
            var $section = $('#ffc-audience-join-section');
            if ($section.length === 0) return;

            var url = ffcDashboard.restUrl + 'user/joinable-groups';
            if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

            FFC.rest(url, { nonce: ffcDashboard.nonce })
                .then(function (data) { renderJoinableGroups(data); })
                .catch(function () { $section.empty(); });
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
    $(document).on('click', '.ffc-audience-switch-btn', function (e) {
        e.preventDefault();
        startSwitch($(this));
    });
    $(document).on('click', '.ffc-audience-switch-confirm', function (e) {
        e.preventDefault();
        var $picker = $(this).closest('.ffc-audience-switch-picker');
        switchGroup(parseInt($picker.find('.ffc-audience-switch-from').val(), 10), parseInt($picker.attr('data-id'), 10));
    });
    $(document).on('click', '.ffc-audience-switch-cancel', function (e) {
        e.preventDefault();
        $(this).closest('.ffc-audience-switch-picker').remove();
    });
    $(document).on('click', '.ffc-leave-all-groups-btn', function (e) {
        e.preventDefault();
        leaveAllGroups();
    });

})(jQuery);
