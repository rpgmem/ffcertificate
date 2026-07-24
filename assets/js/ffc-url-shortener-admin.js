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

        // --- Batched CSV export (#772) ---
        // The button drives the unified ffc_export_* dispatcher via the shared
        // window.FFCBatchedExport driver, carrying the current search/status
        // filters. Export order is id-DESC (a stable keyset), not the on-screen
        // sort.
        $('#ffc-shorturl-export-btn').on('click', function () {
            if (!window.FFCBatchedExport) {
                return;
            }
            var $btn      = $(this);
            var $progress = $('#ffc-shorturl-export-progress');
            var i18n      = settings.i18n || {};
            var nonce     = settings.exportNonce || '';
            if (!nonce) {
                return;
            }

            var originalText = $btn.text();
            $btn.prop('disabled', true).text(i18n.exportPreparing || 'Preparing…');
            $progress.show().text('');

            var exportingTpl = i18n.exportProgress || 'Exporting %1$d/%2$d…';
            var total = 0;

            window.FFCBatchedExport.run({
                type: 'url_shortener',
                ajaxUrl: settings.ajaxUrl,
                nonce: nonce,
                startData: {
                    s: $btn.data('s') || '',
                    status: $btn.data('status') || 'all'
                },
                callbacks: {
                    onStart: function (t) { total = t; },
                    onProgress: function (processed) {
                        $progress.text(exportingTpl.replace('%1$d', processed).replace('%2$d', total));
                    },
                    onComplete: function (downloadUrl, ctx) {
                        var $iframe = $('<iframe>', { src: downloadUrl }).css({ display: 'none' }).appendTo('body');
                        setTimeout(function () {
                            $btn.prop('disabled', false).text(originalText);
                            $progress.text('✓ ' + ctx.processed + '/' + total + ' — ' + (i18n.exportDone || 'Done!'));
                            setTimeout(function () { $progress.fadeOut(); }, 5000);
                            $iframe.remove();
                        }, 2000);
                    },
                    onError: function (err) {
                        $btn.prop('disabled', false).text(originalText);
                        $progress.text((err && err.fromServer && err.message) || (i18n.error || 'An error occurred.'));
                    }
                }
            });
        });

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
            var postId = $btn.data('post-id');
            var action = format === 'svg' ? 'ffc_download_qr_svg' : 'ffc_download_qr_png';

            var payload = {};
            if (postId) {
                payload.post_id = postId;
            } else {
                payload.code = code;
            }

            $btn.prop('disabled', true);

            FFC.request(action, payload, { nonce: settings.nonce, ajaxUrl: settings.ajaxUrl })
                .then(function (data) {
                    $btn.prop('disabled', false);
                    downloadBase64(data.data, data.filename, data.mime);
                })
                .catch(function (err) {
                    $btn.prop('disabled', false);
                    var msg = (err && err.fromServer && err.message)
                        || (settings.i18n ? settings.i18n.error : 'Error');
                    showToast(msg, 'error');
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

            FFC.request(
                'ffc_regenerate_short_url',
                { post_id: postId },
                { nonce: settings.nonce, ajaxUrl: settings.ajaxUrl }
            )
                .then(function () {
                    $btn.prop('disabled', false);
                    showToast(settings.i18n ? settings.i18n.regenerated : 'Regenerated!');
                    window.location.reload();
                })
                .catch(function (err) {
                    $btn.prop('disabled', false);
                    var msg = (err && err.fromServer && err.message)
                        || (settings.i18n ? settings.i18n.error : 'Error');
                    showToast(msg, 'error');
                });
        });

        // --- QR Code Modal ---
        $(document).on('click', '.ffc-show-qr-modal', function (e) {
            e.preventDefault();
            var code = $(this).data('code');
            var url = $(this).data('url');
            var title = $(this).data('title');
            var $modal = $('#ffc-qr-modal');

            // Populate modal
            $modal.find('.ffc-qr-modal__title').text(title);
            $modal.find('.ffc-qr-modal__url').text(url);
            $modal.find('.ffc-copy-shorturl').data('url', url);
            $modal.find('.ffc-download-qr').data('code', code);

            // Reset state
            $modal.find('.ffc-qr-modal__img').hide();
            $modal.find('.ffc-qr-modal__spinner').show();
            $modal.show();

            // Fetch QR Code via AJAX
            FFC.request(
                'ffc_download_qr_png',
                { code: code },
                { nonce: settings.nonce, ajaxUrl: settings.ajaxUrl }
            )
                .then(function (data) {
                    $modal.find('.ffc-qr-modal__spinner').hide();
                    $modal.find('.ffc-qr-modal__img')
                        .attr('src', 'data:image/png;base64,' + data.data)
                        .show();
                })
                .catch(function (err) {
                    $modal.find('.ffc-qr-modal__spinner').hide();
                    var i18n = settings.i18n || {};
                    if (err && err.fromServer) {
                        $modal.find('.ffc-qr-modal__preview')
                            .html('<p style="color:#dc3232;"></p>')
                            .find('p').text(err.message || i18n.error || 'Error');
                    } else {
                        $modal.find('.ffc-qr-modal__preview')
                            .html('<p style="color:#dc3232;"></p>')
                            .find('p').text(i18n.qrLoadFailed || 'Failed to load QR Code');
                    }
                });
        });

        // Close modal
        $(document).on('click', '.ffc-qr-modal__close, .ffc-qr-modal__backdrop', function () {
            $('#ffc-qr-modal').hide();
        });
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') {
                $('#ffc-qr-modal').hide();
            }
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

            FFC.request(
                'ffc_create_short_url',
                { target_url: targetUrl, title: title },
                { nonce: nonce, ajaxUrl: settings.ajaxUrl || (window.ajaxurl || '/wp-admin/admin-ajax.php') }
            )
                .then(function (data) {
                    $btn.prop('disabled', false);
                    var shortUrl = data.short_url;
                    var i18n = settings.i18n || {};
                    var copyLabel = i18n.copy || 'Copy';
                    var $strong = $('<strong>').text(shortUrl);
                    var $copyBtn = $('<button type="button" class="button button-small ffc-copy-shorturl">')
                        .attr('data-url', shortUrl)
                        .text(copyLabel);
                    $result.empty().append($strong).append(' ').append($copyBtn).show();
                    // Clear form
                    $('#ffc-shorturl-target').val('');
                    $('#ffc-shorturl-title').val('');
                    // Reload table after a brief delay
                    setTimeout(function () { window.location.reload(); }, 1500);
                })
                .catch(function (err) {
                    $btn.prop('disabled', false);
                    var i18n = settings.i18n || {};
                    var $span = $('<span style="color:#dc3232;">');
                    if (err && err.fromServer) {
                        $span.text(err.message || i18n.error || 'Error');
                    } else {
                        $span.text(i18n.requestFailed || 'Request failed');
                    }
                    $result.empty().append($span).show();
                });
        });
    });

})(jQuery);
