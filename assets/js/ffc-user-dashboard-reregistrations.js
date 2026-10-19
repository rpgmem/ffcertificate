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
            var $container = $('#ffc-tabpanel-reregistrations');
            if ($container.length === 0) return;

            if (typeof ffcDashboard.canViewReregistrations !== 'undefined' && !ffcDashboard.canViewReregistrations) {
                $container.empty().append(FFC.notice('danger', 'error', ffcDashboard.strings.noPermission, '', { role: 'alert' }));
                return;
            }

            if (this.state !== null) return;

            $container.html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');

            var url = ffcDashboard.restUrl + 'user/reregistrations';
            if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

            var self = this;
            FFC.rest(url, { nonce: ffcDashboard.nonce })
                .then(function (response) {
                    self.state = response.reregistrations || [];
                    self.render(self.state, 1);
                })
                .catch(function () {
                    $container.empty().append(FFC.notice('danger', 'error', ffcDashboard.strings.error, '', { role: 'alert' }));
                });
        },

        render: function (items, page) {
            var $container = $('#ffc-tabpanel-reregistrations');
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
                    if (currentSection !== '') html += '</tbody></table></section>';
                    currentSection = section;

                    var sectionLabel = section === 'active'
                        ? (s.active || 'Active')
                        : (s.completed || 'Completed');

                    // One <section> per state, spaced like the calendar tabs'
                    // (#1837): `#ffc-tabpanel-* h3` zeroes the heading's top
                    // margin with an id's specificity, so the space between
                    // two sections sits on the section.
                    html += '<section class="ffc-dashboard-section" data-section="' + section + '">';
                    html += '<h3>' + sectionLabel + '</h3>';
                    html += '<table class="ffc-reregistrations-table' + (section !== 'active' ? ' ffc-table-past' : '') + '">';
                    html += '<thead><tr>';
                    html += '<th>' + (s.reregistrationTitle || 'Campaign') + '</th>';
                    html += '<th>' + (s.period || 'Period') + '</th>';
                    html += '<th>' + (s.status || 'Status') + '</th>';
                    html += '<th>' + (s.submittedAt || 'Submitted') + '</th>';
                    html += '<th>' + (s.validationCode || 'Validation Code') + '</th>';
                    html += '<th>' + (s.actions || 'Actions') + '</th>';
                    html += '</tr></thead><tbody>';
                }

                var rowClass = section !== 'active' ? ' class="ffc-row-past"' : '';
                html += '<tr' + rowClass + '>';
                html += '<td>' + esc(item.title) + '</td>';
                html += '<td>' + esc(item.start_date_formatted) + ' &mdash; ' + esc(item.end_date_formatted) + '</td>';
                html += '<td><span class="ffc-pill ffc-dashboard-status-' + item.status + '">' + esc(item.status_label) + '</span></td>';
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
                    html += window.FFC.buttonHtml({
                        label: s.editReregistration || 'Edit',
                        category: 'neutral',
                        icon: 'edit',
                        className: 'ffc-btn-edit ffc-rereg-open-form',
                        attrs: { 'data-reregistration-id': item.reregistration_id }
                    }) + ' ';
                }
                if (item.can_download && item.magic_link) {
                    html += window.FFC.buttonHtml({
                        label: s.downloadRecord || 'Download Record',
                        category: 'neutral',
                        icon: 'download',
                        href: item.magic_link,
                        className: 'ffc-btn-pdf',
                        attrs: { target: '_blank', rel: 'noopener' }
                    });
                }
                html += '</td>';
                html += '</tr>';
            });

            if (currentSection !== '') html += '</tbody></table></section>';
            html += helpers.buildPagination(allOrdered.length, page, 'reregistrations');

            $container.html(html);

            $container.find('.ffc-filter-from').val(fromVal);
            $container.find('.ffc-filter-to').val(toVal);
            $container.find('.ffc-filter-search').val(searchVal);
        }
    };

})(jQuery);
