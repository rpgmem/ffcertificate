/**
 * Audience transfer list (#1648)
 *
 * The two-column audience picker printed by AudienceTransferList::render().
 * Each `.ffc-transfer-list` reads its audiences, its chosen ids, the name of
 * the hidden inputs to post and whether a choice is required from its data
 * attributes. Every change is announced as `ffc:transfer-list-change` on the
 * wrapper with the chosen ids, so a screen can react to it (the reregistration
 * campaign counts the members) without this script knowing about it.
 *
 * Choosing an audience brings its whole subtree; removing it takes the
 * subtree away.
 *
 * @since 6.35.0
 */
(function ($) {
    'use strict';

    function parse(raw) {
        try {
            var value = JSON.parse(raw || '[]');
            return Array.isArray(value) ? value : [];
        } catch (e) {
            return [];
        }
    }

    function init(wrap) {
        var $wrap = $(wrap);
        if ($wrap.data('ffcTransferList')) {
            return;
        }
        $wrap.data('ffcTransferList', true);

        var allAudiences = parse($wrap.attr('data-audiences'));
        var selectedIds = parse($wrap.attr('data-selected')).map(function (id) { return parseInt(id, 10); });
        var fieldName = $wrap.attr('data-field-name') || 'audience_ids[]';
        var required = '1' === $wrap.attr('data-required');
        var byId = {};
        allAudiences.forEach(function (a) { byId[a.id] = a; });

        var $available = $wrap.find('.ffc-transfer-available .ffc-transfer-items');
        var $selected = $wrap.find('.ffc-transfer-selected .ffc-transfer-items');
        var $hidden = $wrap.find('.ffc-transfer-hidden-inputs');
        var $search = $wrap.find('.ffc-transfer-search');

        function subtree(id) {
            var ids = [id];
            var a = byId[id];
            (a && a.children ? a.children : []).forEach(function (childId) {
                ids = ids.concat(subtree(childId));
            });
            return ids;
        }

        function render() {
            var filter = ($search.val() || '').toLowerCase();
            $available.empty();
            $selected.empty();
            $hidden.empty();

            allAudiences.forEach(function (a) {
                var inSelected = selectedIds.indexOf(a.id) !== -1;
                var depth = parseInt(a.depth, 10) || (a.parent ? 1 : 0);
                var cls = 'ffc-transfer-item' + (depth > 0 ? ' ffc-transfer-child' : '') + (depth > 1 ? ' ffc-transfer-grandchild' : '');
                var label = new Array(depth + 1).join('— ') + a.name;

                // Built through text()/attr() so a stored name or colour is
                // escaped (CodeQL js/xss-through-dom).
                var $dot = $('<span>', { 'class': 'ffc-color-dot' });
                if (/^#[0-9a-fA-F]{3,8}$/.test(a.color || '')) {
                    $dot.css('background', a.color);
                }
                var $item = $('<div>', { 'class': cls, 'data-id': a.id })
                    .append($dot)
                    .append(document.createTextNode(' '))
                    .append($('<span>', { 'class': 'ffc-transfer-label' }).text(label));

                if (inSelected) {
                    $selected.append($item);
                    $hidden.append($('<input>', { type: 'hidden', name: fieldName, value: a.id }));
                } else if (!filter || String(a.name).toLowerCase().indexOf(filter) !== -1) {
                    $available.append($item);
                }
            });

            $wrap.trigger('ffc:transfer-list-change', [selectedIds.slice()]);
        }

        function add(id) {
            subtree(id).forEach(function (sub) {
                if (selectedIds.indexOf(sub) === -1) {
                    selectedIds.push(sub);
                }
            });
        }

        function remove(id) {
            subtree(id).forEach(function (sub) {
                var idx = selectedIds.indexOf(sub);
                if (idx !== -1) {
                    selectedIds.splice(idx, 1);
                }
            });
        }

        $wrap.on('click', '.ffc-transfer-item', function () {
            $(this).toggleClass('ffc-transfer-highlight');
        });
        $available.on('dblclick', '.ffc-transfer-item', function () {
            add(parseInt($(this).data('id'), 10));
            render();
        });
        $selected.on('dblclick', '.ffc-transfer-item', function () {
            remove(parseInt($(this).data('id'), 10));
            render();
        });
        $wrap.find('.ffc-transfer-add').on('click', function () {
            $available.find('.ffc-transfer-highlight').each(function () {
                add(parseInt($(this).data('id'), 10));
            });
            render();
        });
        $wrap.find('.ffc-transfer-add-all').on('click', function () {
            allAudiences.forEach(function (a) { add(a.id); });
            render();
        });
        $wrap.find('.ffc-transfer-remove').on('click', function () {
            $selected.find('.ffc-transfer-highlight').each(function () {
                remove(parseInt($(this).data('id'), 10));
            });
            render();
        });
        $wrap.find('.ffc-transfer-remove-all').on('click', function () {
            selectedIds = [];
            render();
        });
        $search.on('input', render);

        if (required) {
            $wrap.closest('form').on('submit', function (e) {
                if (!selectedIds.length) {
                    e.preventDefault();
                    $wrap.find('.ffc-transfer-selected').addClass('ffc-transfer-error');
                    setTimeout(function () {
                        $wrap.find('.ffc-transfer-selected').removeClass('ffc-transfer-error');
                    }, 2000);
                }
            });
        }

        render();
    }

    function initAll(root) {
        $(root || document).find('.ffc-transfer-list').each(function () { init(this); });
    }

    window.FFC = window.FFC || {};
    window.FFC.AudienceTransferList = { init: init, initAll: initAll };

    $(function () { initAll(document); });
})(jQuery);
