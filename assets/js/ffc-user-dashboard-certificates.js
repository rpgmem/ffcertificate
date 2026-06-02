/**
 * FFC User Dashboard — Certificates panel
 *
 * @since 6.5.2
 */

(function ($) {
    'use strict';

    var helpers = FFCDashboard.helpers;

    FFCDashboard.panels.certificates = {
        state: null,

        load: function () {
            var $container = $('#tab-certificates');
            if ($container.length === 0) return;

            if (typeof ffcDashboard.canViewCertificates !== 'undefined' && !ffcDashboard.canViewCertificates) {
                $container.html('<div class="ffc-error">' + ffcDashboard.strings.noPermission + '</div>');
                return;
            }

            if (this.state !== null) return;

            $container.html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');

            var url = ffcDashboard.restUrl + 'user/certificates';
            if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

            var self = this;
            FFC.rest(url, { nonce: ffcDashboard.nonce })
                .then(function (response) {
                    self.state = response.certificates || [];
                    self.render(self.state, 1);
                })
                .catch(function () {
                    $container.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>');
                });
        },

        render: function (certificates, page) {
            var $container = $('#tab-certificates');
            page = page || 1;
            var pageSize = helpers.getPageSize();

            var filterHtml = helpers.buildFilterBar('certificates');
            var $existing = $container.find('.ffc-filter-bar');
            var fromVal = $existing.find('.ffc-filter-from').val() || '';
            var toVal = $existing.find('.ffc-filter-to').val() || '';
            var searchVal = $existing.find('.ffc-filter-search').val() || '';

            if (!certificates || certificates.length === 0) {
                $container.html(filterHtml + '<div class="ffc-empty-state"><p>' + ffcDashboard.strings.noCertificates + '</p></div>');
                return;
            }

            var filtered = certificates;
            if (fromVal || toVal || searchVal) {
                filtered = certificates.filter(function (c) {
                    if (fromVal && c.submission_date_raw < fromVal) return false;
                    if (toVal && c.submission_date_raw > toVal) return false;
                    if (searchVal) {
                        var hay = (c.form_title + ' ' + c.email + ' ' + c.auth_code).toLowerCase();
                        if (hay.indexOf(searchVal.toLowerCase()) === -1) return false;
                    }
                    return true;
                });
            }

            var start = (page - 1) * pageSize;
            var pageItems = filtered.slice(start, start + pageSize);

            var html = filterHtml;
            html += '<table class="ffc-certificates-table">';
            html += '<thead><tr>';
            html += '<th>' + ffcDashboard.strings.eventName + '</th>';
            html += '<th>' + ffcDashboard.strings.date + '</th>';
            html += '<th>' + ffcDashboard.strings.consent + '</th>';
            html += '<th>' + ffcDashboard.strings.email + '</th>';
            html += '<th>' + ffcDashboard.strings.code + '</th>';
            html += '<th>' + ffcDashboard.strings.actions + '</th>';
            html += '</tr></thead><tbody>';

            pageItems.forEach(function (cert) {
                html += '<tr>';
                html += '<td>' + helpers.esc(cert.form_title) + '</td>';
                html += '<td>' + cert.submission_date + '</td>';
                html += '<td><span class="' + (cert.consent_given ? 'consent-yes' : 'consent-no') + '">';
                html += (cert.consent_given ? ffcDashboard.strings.yes : ffcDashboard.strings.no);
                html += '</span></td>';
                html += '<td>' + helpers.esc(cert.email) + '</td>';
                html += '<td>' + helpers.esc(cert.auth_code) + '</td>';
                html += '<td>';
                if (cert.magic_link) {
                    html += '<a href="' + helpers.escAttr(cert.magic_link) + '" class="button ffc-btn-pdf" target="_blank" rel="noopener noreferrer">' + ffcDashboard.strings.downloadPdf + '</a>';
                }
                html += '</td>';
                html += '</tr>';
            });

            html += '</tbody></table>';
            html += helpers.buildPagination(filtered.length, page, 'certificates');

            $container.html(html);

            $container.find('.ffc-filter-from').val(fromVal);
            $container.find('.ffc-filter-to').val(toVal);
            $container.find('.ffc-filter-search').val(searchVal);
        }
    };

})(jQuery);
