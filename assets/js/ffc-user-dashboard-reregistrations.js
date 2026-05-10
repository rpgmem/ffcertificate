/**
 * FFC User Dashboard — Reregistrations panel
 *
 * Reregistration campaigns the user can/has submitted. Sections: active /
 * completed. Filter bar, pagination.
 *
 * @since 6.5.2
 */

(function ($) {
    'use strict';

    var helpers = FFCDashboard.helpers;

    FFCDashboard.panels.reregistrations = {
        state: null,

        load: function () {
            var $container = $('#tab-reregistrations');
            if ($container.length === 0) return;

            if (typeof ffcDashboard.canViewReregistrations !== 'undefined' && !ffcDashboard.canViewReregistrations) {
                $container.html('<div class="ffc-error">' + ffcDashboard.strings.noPermission + '</div>');
                return;
            }

            if (this.state !== null) return;

            $container.html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');

            var url = ffcDashboard.restUrl + 'user/reregistrations';
            if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

            var self = this;
            $.ajax({
                url: url,
                method: 'GET',
                beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
                success: function (response) {
                    self.state = response.reregistrations || [];
                    self.render(self.state, 1);
                },
                error: function () {
                    $container.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>');
                }
            });
        },

        render: function (items, page) {
            var $container = $('#tab-reregistrations');
            page = page || 1;
            var pageSize = helpers.getPageSize();
            var s = ffcDashboard.strings;
            var esc = helpers.esc;

            var filterHtml = helpers.buildFilterBar('reregistrations');
            var $existing = $container.find('.ffc-filter-bar');
            var fromVal = $existing.find('.ffc-filter-from').val() || '';
            var toVal = $existing.find('.ffc-filter-to').val() || '';
            var searchVal = $existing.find('.ffc-filter-search').val() || '';

            if (!items || items.length === 0) {
                $container.html(filterHtml + '<div class="ffc-empty-state"><p>' + (s.noReregistrations || 'No reregistrations found.') + '</p></div>');
                return;
            }

            var filtered = items;
            if (fromVal || toVal || searchVal) {
                filtered = items.filter(function (r) {
                    if (fromVal && r.start_date < fromVal) return false;
                    if (toVal && r.end_date > toVal) return false;
                    if (searchVal) {
                        var hay = (r.title + ' ' + r.status_label + ' ' + (r.auth_code || '')).toLowerCase();
                        if (hay.indexOf(searchVal.toLowerCase()) === -1) return false;
                    }
                    return true;
                });
            }

            var active = filtered.filter(function (r) { return r.is_active; });
            var completed = filtered.filter(function (r) { return !r.is_active; });
            var allOrdered = [];
            active.forEach(function (r) { r._section = 'active'; allOrdered.push(r); });
            completed.forEach(function (r) { r._section = 'completed'; allOrdered.push(r); });

            var start = (page - 1) * pageSize;
            var pageItems = allOrdered.slice(start, start + pageSize);

            var html = filterHtml;
            var currentSection = '';

            pageItems.forEach(function (item) {
                var section = item._section;

                if (section !== currentSection) {
                    if (currentSection !== '') html += '</tbody></table>';
                    currentSection = section;

                    var sectionLabel = section === 'active'
                        ? (s.active || 'Active')
                        : (s.completed || 'Completed');

                    html += '<h3' + (section !== 'active' ? ' style="margin-top: 30px;"' : '') + '>' + sectionLabel + '</h3>';
                    html += '<table class="ffc-reregistrations-table' + (section !== 'active' ? ' past-reregistrations' : '') + '">';
                    html += '<thead><tr>';
                    html += '<th>' + (s.reregistrationTitle || 'Campaign') + '</th>';
                    html += '<th>' + (s.period || 'Period') + '</th>';
                    html += '<th>' + (s.status || 'Status') + '</th>';
                    html += '<th>' + (s.submittedAt || 'Submitted') + '</th>';
                    html += '<th>' + (s.validationCode || 'Validation Code') + '</th>';
                    html += '<th>' + (s.actions || 'Actions') + '</th>';
                    html += '</tr></thead><tbody>';
                }

                var rowClass = section !== 'active' ? ' class="past-row"' : '';
                html += '<tr' + rowClass + '>';
                html += '<td>' + esc(item.title) + '</td>';
                html += '<td>' + esc(item.start_date_formatted) + ' &mdash; ' + esc(item.end_date_formatted) + '</td>';
                html += '<td><span class="appointment-status status-' + item.status + '">' + esc(item.status_label) + '</span></td>';
                html += '<td>' + esc(item.submitted_at || '—') + '</td>';
                html += '<td>';
                if (item.auth_code) {
                    html += '<code class="ffc-auth-code">' + esc(item.auth_code) + '</code>';
                } else {
                    html += '—';
                }
                html += '</td>';
                html += '<td>';
                if (item.can_submit) {
                    html += '<button type="button" class="button ffc-btn-edit ffc-rereg-open-form" data-reregistration-id="' + item.reregistration_id + '">' + (s.editReregistration || 'Edit') + '</button> ';
                }
                if (item.can_download && item.magic_link) {
                    html += '<a href="' + esc(item.magic_link) + '" class="button ffc-btn-pdf" target="_blank" rel="noopener">' + (s.downloadFicha || 'Download Ficha') + '</a>';
                }
                html += '</td>';
                html += '</tr>';
            });

            if (currentSection !== '') html += '</tbody></table>';
            html += helpers.buildPagination(allOrdered.length, page, 'reregistrations');

            $container.html(html);

            $container.find('.ffc-filter-from').val(fromVal);
            $container.find('.ffc-filter-to').val(toVal);
            $container.find('.ffc-filter-search').val(searchVal);
        }
    };

})(jQuery);
