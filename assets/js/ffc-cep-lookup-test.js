/**
 * Settings → General: the address lookup's Test button (#2234).
 *
 * Asks the server to query both providers for the typed CEP, without the
 * cache, and lists what each one answered -- which is how an administrator
 * learns whether the host reaches them.
 *
 * @since 6.43.0
 * @package FreeFormCertificate
 */
(function ($) {
    'use strict';

    var C = window.ffcCepLookupTest || {};
    var S = C.strings || {};
    var NAMES = { viacep: 'ViaCEP', brasilapi: 'BrasilAPI' };

    function esc(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function statusLabel(status) {
        if (status === 'found') {
            return S.found || 'Answered';
        }
        if (status === 'not_found') {
            return S.notFound || 'CEP not found';
        }
        return S.unavailable || 'Unreachable';
    }

    function addressLine(address) {
        if (!address) {
            return '';
        }
        var place = [address.street, address.neighborhood].filter(Boolean).join(', ');
        var city = [address.city, address.uf].filter(Boolean).join('/');
        return [place, city].filter(Boolean).join(', ');
    }

    function render($out, results) {
        var html = '<table class="widefat striped ffc-cep-lookup-test-table"><thead><tr>' +
            '<th scope="col">' + esc(S.provider || 'Provider') + '</th>' +
            '<th scope="col">' + esc(S.status || 'Status') + '</th>' +
            '<th scope="col">' + esc(S.address || 'Address returned') + '</th>' +
            '</tr></thead><tbody>';
        results.forEach(function (result) {
            html += '<tr data-status="' + esc(result.status) + '">' +
                '<td>' + esc(NAMES[result.provider] || result.provider) + '</td>' +
                '<td>' + esc(statusLabel(result.status)) + '</td>' +
                '<td>' + esc(addressLine(result.address)) + '</td>' +
                '</tr>';
        });
        $out.html(html + '</tbody></table>');
    }

    function run() {
        var $btn = $('#ffc-cep-lookup-test-btn');
        var $out = $('#ffc-cep-lookup-test-result');
        var cep = ($('#ffc-cep-lookup-test-cep').val() || '').trim();

        $btn.prop('disabled', true).addClass('ffc-btn-loading');
        $out.text(S.testing || 'Testing…');

        $.post(C.url, { action: C.action, nonce: C.nonce, cep: cep })
            .done(function (response) {
                if (response && response.success && response.data && response.data.results) {
                    render($out, response.data.results);
                } else {
                    $out.text((response && response.data && response.data.message) || S.error || 'The test could not run.');
                }
            })
            .fail(function (xhr) {
                var message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
                $out.text(message || S.error || 'The test could not run.');
            })
            .always(function () {
                $btn.prop('disabled', false).removeClass('ffc-btn-loading');
            });
    }

    $(document).on('click', '#ffc-cep-lookup-test-btn', function (e) {
        e.preventDefault();
        run();
    });
    $(document).on('keydown', '#ffc-cep-lookup-test-cep', function (e) {
        // Enter tests instead of submitting the settings form.
        if (e.key === 'Enter') {
            e.preventDefault();
            run();
        }
    });

    window.FFC = window.FFC || {};
    window.FFC.CepLookupTest = { run: run, render: render };

})(jQuery);
