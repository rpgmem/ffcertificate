/**
 * FFC User Dashboard — Certificates panel
 *
 * @since 6.5.2
 */

(function ($) {
    'use strict';

    var helpers = FFCDashboard.helpers;

    /**
     * Each certificate's e-mail arrives masked (#1897). The owner reveals
     * their own without an audit row; in "view as user" the reveal is
     * audited. The value revealed replaces the mask in the cached list, so
     * the search filter and the next page see it too.
     */
    function revealEmail(id, $btn) {
        var panel = FFCDashboard.panels.certificates;
        var url = ffcDashboard.restUrl + 'user/certificates/reveal';
        if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

        $btn.prop('disabled', true);
        return FFC.rest(url, { method: 'POST', data: { id: id }, nonce: ffcDashboard.nonce })
            .then(function (response) {
                (panel.state || []).forEach(function (cert) {
                    if (cert.id === id) {
                        cert.email = response.value;
                        cert.email_revealed = true;
                    }
                });
                panel.render(panel.state, panel.page);
            })
            .catch(function () {
                $btn.prop('disabled', false);
                window.alert(ffcDashboard.strings.revealError || 'Unable to reveal this value.');
            });
    }

    FFCDashboard.panels.certificates = {
        state: null,
        canReveal: false,
        page: 1,

        bindEvents: function () {
            $(document).on('click', '.ffc-certificate-reveal-btn', function (e) {
                e.preventDefault();
                revealEmail(parseInt($(this).data('id'), 10), $(this));
            });
        },

        load: function () {
            var $container = $('#ffc-tabpanel-certificates');
            if ($container.length === 0) return;

            if (typeof ffcDashboard.canViewCertificates !== 'undefined' && !ffcDashboard.canViewCertificates) {
                $container.empty().append(FFC.notice('danger', 'error', ffcDashboard.strings.noPermission, '', { role: 'alert' }));
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
                    self.canReveal = !!response.can_reveal;
                    self.render(self.state, 1);
                })
                .catch(function () {
                    $container.empty().append(FFC.notice('danger', 'error', ffcDashboard.strings.error, '', { role: 'alert' }));
                });
        },

        render: function (certificates, page) {
            var $container = $('#ffc-tabpanel-certificates');
            page = page || 1;
            this.page = page;
            var canReveal = this.canReveal;
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
                html += '<td><span class="' + (cert.consent_given ? 'ffc-consent-yes' : 'ffc-consent-no') + '">';
                html += (cert.consent_given ? ffcDashboard.strings.yes : ffcDashboard.strings.no);
                html += '</span></td>';
                html += '<td>' + helpers.esc(cert.email);
                if (cert.email && !cert.email_revealed && canReveal) {
                    html += ' ' + window.FFC.buttonHtml({
                        label: ffcDashboard.strings.reveal || 'Reveal',
                        category: 'neutral',
                        icon: 'eye',
                        className: 'ffc-certificate-reveal-btn',
                        attrs: { 'data-id': cert.id }
                    });
                }
                html += '</td>';
                html += '<td>' + helpers.esc(cert.auth_code) + '</td>';
                html += '<td>';
                if (cert.magic_link) {
                    html += window.FFC.buttonHtml({
                        label: ffcDashboard.strings.downloadPdf,
                        category: 'neutral',
                        icon: 'download',
                        href: cert.magic_link,
                        className: 'ffc-btn-pdf',
                        attrs: { target: '_blank', rel: 'noopener noreferrer' }
                    });
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
