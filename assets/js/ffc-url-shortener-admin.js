/**
 * URL Shortener Admin JavaScript
 *
 * Handles: copy to clipboard, QR download (PNG/SVG), create short URL,
 * regenerate, and toast notifications.
 *
 * @since 5.1.0
 */
(function ($) {
    'use strict';

    var settings = window.ffcUrlShortener || {};

    /**
     * Show a brief toast notification.
     */
    function showToast(message, type) {
        var $toast = $('<div class="ffc-shorturl-toast ffc-shorturl-toast--' + (type || 'success') + '">')
            .text(message)
            .appendTo('body');
        setTimeout(function () {
            $toast.addClass('ffc-shorturl-toast--visible');
        }, 10);
        setTimeout(function () {
            $toast.removeClass('ffc-shorturl-toast--visible');
            setTimeout(function () { $toast.remove(); }, 300);
        }, 2000);
    }

    /**
     * Copy text to clipboard.
     */
    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                showToast(settings.i18n ? settings.i18n.copied : 'Copied!');
            }).catch(function () {
                fallbackCopy(text);
            });
        } else {
            fallbackCopy(text);
        }
    }

    function fallbackCopy(text) {
        var $temp = $('<input>').val(text).appendTo('body').select();
        try {
            document.execCommand('copy');
            showToast(settings.i18n ? settings.i18n.copied : 'Copied!');
        } catch (e) {
            showToast(settings.i18n ? settings.i18n.copyFailed : 'Copy failed', 'error');
        }
        $temp.remove();
    }

    /**
     * Trigger a file download from base64 data.
     */
    function downloadBase64(base64Data, filename, mime) {
        var byteChars = atob(base64Data);
        var byteNumbers = new Array(byteChars.length);
        for (var i = 0; i < byteChars.length; i++) {
            byteNumbers[i] = byteChars.charCodeAt(i);
        }
        var byteArray = new Uint8Array(byteNumbers);
        var blob = new Blob([byteArray], { type: mime });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    $(document).ready(function () {

        // --- Copy short URL (meta box) ---
        $(document).on('click', '.ffc-copy-shorturl', function (e) {
            e.preventDefault();
            copyToClipboard($(this).data('url'));
        });

        // --- Copy short URL from admin table ---
        $(document).on('click', '.ffc-shorturl-code', function () {
            copyToClipboard($(this).data('url'));
        });

        // --- Download QR PNG/SVG ---
        $(document).on('click', '.ffc-download-qr', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var format = $btn.data('format');
            var code = $btn.data('code');
            var action = format === 'svg' ? 'ffc_download_qr_svg' : 'ffc_download_qr_png';

            $btn.prop('disabled', true);

            $.post(settings.ajaxUrl, {
                action: action,
                nonce: settings.nonce,
                code: code
            }, function (response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    downloadBase64(response.data.data, response.data.filename, response.data.mime);
                } else {
                    showToast(response.data ? response.data.message : 'Error', 'error');
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                showToast(settings.i18n ? settings.i18n.error : 'Error', 'error');
            });
        });

        // --- Regenerate short URL ---
        $(document).on('click', '.ffc-regenerate-shorturl', function (e) {
            e.preventDefault();
            var confirmMsg = settings.i18n ? settings.i18n.confirm : 'Generate a new short code?';
            if (!confirm(confirmMsg)) return;

            var $btn = $(this);
            var postId = $btn.data('post-id');

            $btn.prop('disabled', true);

            $.post(settings.ajaxUrl, {
                action: 'ffc_regenerate_short_url',
                nonce: settings.nonce,
                post_id: postId
            }, function (response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    showToast(settings.i18n ? settings.i18n.regenerated : 'Regenerated!');
                    // Reload to update meta box
                    window.location.reload();
                } else {
                    showToast(response.data ? response.data.message : 'Error', 'error');
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                showToast(settings.i18n ? settings.i18n.error : 'Error', 'error');
            });
        });

        // --- Create short URL (admin page form) ---
        $('#ffc-create-short-url').on('submit', function (e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            var $result = $('#ffc-shorturl-result');
            var targetUrl = $('#ffc-shorturl-target').val();
            var title = $('#ffc-shorturl-title').val();
            var nonce = $form.find('#ffc_short_url_nonce').val();

            $btn.prop('disabled', true);

            $.post(settings.ajaxUrl || (window.ajaxurl || '/wp-admin/admin-ajax.php'), {
                action: 'ffc_create_short_url',
                nonce: nonce,
                target_url: targetUrl,
                title: title
            }, function (response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    var shortUrl = response.data.short_url;
                    $result.html(
                        '<strong>' + shortUrl + '</strong> ' +
                        '<button type="button" class="button button-small ffc-copy-shorturl" data-url="' + shortUrl + '">' +
                        'Copy</button>'
                    ).show();
                    // Clear form
                    $('#ffc-shorturl-target').val('');
                    $('#ffc-shorturl-title').val('');
                    // Reload table after a brief delay
                    setTimeout(function () { window.location.reload(); }, 1500);
                } else {
                    $result.html('<span style="color:#dc3232;">' + (response.data ? response.data.message : 'Error') + '</span>').show();
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                $result.html('<span style="color:#dc3232;">Request failed</span>').show();
            });
        });
    });

})(jQuery);
