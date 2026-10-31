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

    /**
     * Fetches one submission's answers into the row beneath it (#2227).
     * Personal data arrives masked; Reveal asks again with `reveal=1`,
     * which the owner gets without an audit row and an administrator in
     * "view as user" gets audited.
     */
    function loadAnswers(id, reveal, $row) {
        var s = ffcDashboard.strings;
        var url = ffcDashboard.restUrl + 'user/reregistrations/' + id + '/answers';
        var query = [];
        if (reveal) query.push('reveal=1');
        if (ffcDashboard.viewAsUserId) query.push('viewAsUserId=' + ffcDashboard.viewAsUserId);
        if (query.length) url += '?' + query.join('&');

        var $cell = $row.find('.ffc-rereg-answers');
        if (!reveal) {
            $cell.html('<div class="ffc-loading" role="status">' + s.loading + '</div>');
        }

        return FFC.rest(url, { nonce: ffcDashboard.nonce })
            .then(function (response) {
                $cell.html(renderAnswers(id, response));
            })
            .catch(function () {
                $cell.empty().append(FFC.notice('danger', 'error', s.error, '', { role: 'alert' }));
            });
    }

    function renderAnswers(id, data) {
        var s = ffcDashboard.strings;
        var esc = helpers.esc;
        var html = '';

        if (data.can_reveal) {
            html += '<p class="ffc-rereg-answers-actions">' + window.FFC.buttonHtml({
                label: s.reveal || 'Reveal',
                category: 'neutral',
                icon: 'eye',
                className: 'ffc-rereg-answers-reveal',
                attrs: { 'data-submission-id': id }
            }) + '</p>';
        }

        html += '<dl class="ffc-rereg-answers-list">';
        (data.rows || []).forEach(function (row) {
            html += '<dt>' + esc(row.label) + '</dt>';
            html += '<dd>' + (row.value !== '' ? esc(row.value) : '&mdash;') + '</dd>';
        });
        html += '</dl>';
        return html;
    }

    /**
     * Downloads one approved submission's Record (#2242). The data comes
     * from the logged-in REST route, never from a public link, and the PDF
     * is built here. Serves the table's button and the banner's.
     */
    function downloadRecord($btn) {
        var s = ffcDashboard.strings;
        var id = parseInt($btn.data('submission-id'), 10);
        var url = ffcDashboard.restUrl + 'user/reregistrations/' + id + '/record';
        if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

        $btn.addClass('ffc-btn-loading').prop('disabled', true);

        return FFC.rest(url, { nonce: ffcDashboard.nonce })
            .then(function (response) {
                var pdf = response && response.pdf_data;
                if (!pdf || typeof window.ffcGeneratePDF !== 'function') {
                    throw new Error('record');
                }
                window.ffcGeneratePDF(pdf, pdf.filename || 'record.pdf');
            })
            .catch(function () {
                $btn.after(FFC.notice('danger', 'error', s.error, '', { role: 'alert' }));
            })
            .then(function () {
                $btn.removeClass('ffc-btn-loading').prop('disabled', false);
            });
    }

    $(document).on('click', '.ffc-rereg-download-record', function (e) {
        e.preventDefault();
        downloadRecord($(this));
    });

    FFCDashboard.panels.reregistrations = {
        state: null,

        bindEvents: function () {
            $(document).on('click', '.ffc-rereg-answers-toggle', function (e) {
                e.preventDefault();
                var $btn = $(this);
                var id = parseInt($btn.data('submission-id'), 10);
                var $tr = $btn.closest('tr');
                var $next = $tr.next('.ffc-rereg-answers-row');

                if ($next.length) {
                    $next.remove();
                    $btn.attr('aria-expanded', 'false');
                    return;
                }

                var $row = $('<tr class="ffc-rereg-answers-row"><td class="ffc-rereg-answers" colspan="6"></td></tr>');
                $tr.after($row);
                $btn.attr('aria-expanded', 'true');
                loadAnswers(id, false, $row);
            });

            $(document).on('click', '.ffc-rereg-answers-reveal', function (e) {
                e.preventDefault();
                var $btn = $(this);
                $btn.prop('disabled', true);
                loadAnswers(parseInt($btn.data('submission-id'), 10), true, $btn.closest('tr'));
            });
        },

        load: function () {
            var $container = $('#ffc-tabpanel-reregistrations');
            if ($container.length === 0) return;

            if (typeof ffcDashboard.canViewReregistrations !== 'undefined' && !ffcDashboard.canViewReregistrations) {
                $container.empty().append(FFC.notice('danger', 'error', ffcDashboard.strings.noPermission, '', { role: 'alert' }));
                return;
            }

            if (this.state !== null) return;

            $container.html('<div class="ffc-loading" role="status">' + ffcDashboard.strings.loading + '</div>');

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
            if (filtered.length === 0) html += helpers.buildNoResults('reregistrations');
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
                html += '<td><span class="ffc-pill ffc-dashboard-status-' + helpers.classSlug(item.status) + '">' + esc(item.status_label) + '</span></td>';
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
                if (item.can_view_answers) {
                    html += window.FFC.buttonHtml({
                        label: s.viewAnswers || 'View answers',
                        category: 'neutral',
                        icon: 'eye',
                        className: 'ffc-rereg-answers-toggle',
                        attrs: { 'data-submission-id': item.submission_id, 'aria-expanded': 'false' }
                    }) + ' ';
                }
                if (item.can_download) {
                    html += window.FFC.buttonHtml({
                        label: s.downloadRecord || 'Download Record',
                        category: 'neutral',
                        icon: 'download',
                        className: 'ffc-btn-pdf ffc-rereg-download-record',
                        attrs: { 'data-submission-id': item.submission_id }
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
