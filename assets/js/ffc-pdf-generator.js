/**
 * FFC PDF Generator - Standalone Module
 *
 * Shared PDF generation logic for both frontend and admin
 *
 * @version 2.10.0 - Support portrait/landscape orientation via pdfData.orientation
 */

(function($, window) {
    'use strict';

    function checkPDFLibraries() {
        if (typeof html2canvas === 'undefined') {
            console.error('[FFC PDF] html2canvas library not loaded');
            return false;
        }
        if (typeof window.jspdf === 'undefined' || typeof window.jspdf.jsPDF === 'undefined') {
            console.error('[FFC PDF] jsPDF library not loaded');
            return false;
        }
        return true;
    }

    function showOverlay() {
        if ($('#ffc-pdf-overlay').length > 0) {
            return;
        }

        var overlay = $('<div id="ffc-pdf-overlay"></div>').css({
            'position': 'fixed',
            'top': '0',
            'left': '0',
            'width': '100%',
            'height': '100%',
            'background': 'rgba(0, 0, 0, 0.8)',
            'z-index': '999999',
            'display': 'flex',
            'align-items': 'center',
            'justify-content': 'center'
        });

        var content = $('<div></div>').css({
            'background': 'white',
            'padding': '40px',
            'border-radius': '8px',
            'text-align': 'center',
            'max-width': '400px',
            'box-shadow': '0 4px 20px rgba(0,0,0,0.3)'
        });

        var spinner = $('<div class="ffc-spinner"></div>').css({
            'border': '4px solid #f3f3f3',
            'border-top': '4px solid #2271b1',
            'border-radius': '50%',
            'width': '50px',
            'height': '50px',
            'margin': '0 auto 20px',
            'animation': 'ffc-spin 1s linear infinite'
        });

        var titleText = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.generatingPdf)
            ? ffc_ajax.strings.generatingPdf
            : (typeof ffcCalendar !== 'undefined' && ffcCalendar.strings && ffcCalendar.strings.generatingPdf)
            ? ffcCalendar.strings.generatingPdf
            : (typeof ffcReceiptData !== 'undefined' && ffcReceiptData.strings && ffcReceiptData.strings.generatingPdf)
            ? ffcReceiptData.strings.generatingPdf
            : 'Generating PDF...';

        var title = $('<h3></h3>').css({
            'margin': '0 0 10px 0',
            'color': '#333',
            'font-size': '18px',
            'font-weight': '600'
        }).text(titleText);

        var messageText = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.pleaseWait)
            ? ffc_ajax.strings.pleaseWait
            : (typeof ffcCalendar !== 'undefined' && ffcCalendar.strings && ffcCalendar.strings.pleaseWait)
            ? ffcCalendar.strings.pleaseWait
            : (typeof ffcReceiptData !== 'undefined' && ffcReceiptData.strings && ffcReceiptData.strings.pleaseWait)
            ? ffcReceiptData.strings.pleaseWait
            : 'Please wait, this may take a few seconds...';

        var message = $('<p></p>').css({
            'margin': '0',
            'color': '#666',
            'font-size': '14px',
            'line-height': '1.5'
        }).text(messageText);

        content.append(spinner).append(title).append(message);
        overlay.append(content);
        $('body').append(overlay);

        if (!$('#ffc-spinner-animation').length) {
            $('<style id="ffc-spinner-animation">@keyframes ffc-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>').appendTo('head');
        }
    }

    function hideOverlay() {
        $('#ffc-pdf-overlay').fadeOut(300, function() {
            $(this).remove();
        });
    }

    function generateAndDownloadPDF(pdfData, filename) {
        if (!checkPDFLibraries()) {
            var errorMsg = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.pdfLibrariesFailed)
                ? ffc_ajax.strings.pdfLibrariesFailed
                : 'PDF libraries failed to load. Please refresh the page.';
            alert(errorMsg);
            return;
        }

        // 6.3.6 — pre-open the destination tab BEFORE doing any async work,
        // while the user-gesture token is still alive. Opening it later
        // (after html2canvas's Promise resolves, ~1-3 s later) gets silently
        // popup-blocked on browsers with strict policies, which is the #1
        // cause of "download fails with no error" reports. We later swap
        // this window's location to the generated blob URL — those browsers
        // allow that on a window the page already owns.
        //
        // Browsers that need this dance:
        //   • iOS Safari & iPadOS Safari (popup blocker on by default).
        //     iPadOS reports "Macintosh" in the UA but exposes
        //     maxTouchPoints > 1, hence the OR branch.
        //   • Samsung Internet on Android (~25% market share in some
        //     regions; Chromium engine but its own download / popup shell
        //     refuses post-async window.open() the same way iOS Safari does).
        //   • Android WebView — when the visitor opens the page from inside
        //     Facebook / Instagram / WhatsApp / TikTok the browser is a
        //     stripped WebView with the same popup-blocking behaviour.
        //
        // Mac Safari, Chrome (desktop or Android), Firefox, and Edge all
        // honour <a download> via pdf.save() correctly — they fall through
        // to the else branch below.
        var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) ||
                    (/Macintosh/.test(navigator.userAgent) && navigator.maxTouchPoints > 1);
        var isSamsungInternet = /SamsungBrowser/i.test(navigator.userAgent);
        var isAndroidWebView = /\bwv\)/.test(navigator.userAgent) || /; wv;/.test(navigator.userAgent);
        var needsPreOpen = isIOS || isSamsungInternet || isAndroidWebView;
        var pdfWindow = null;
        if (needsPreOpen) {
            pdfWindow = window.open('about:blank', '_blank');
            if (pdfWindow) {
                try {
                    var generatingTitle = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.pdfGeneratingTab)
                        ? ffc_ajax.strings.pdfGeneratingTab
                        : 'Generating your certificate…';
                    var generatingHint = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.pdfGeneratingTabHint)
                        ? ffc_ajax.strings.pdfGeneratingTabHint
                        : 'Please do not close this tab. The PDF will appear automatically in a few seconds.';
                    pdfWindow.document.title = generatingTitle;
                    pdfWindow.document.body.style.cssText = 'font-family:-apple-system,BlinkMacSystemFont,sans-serif;text-align:center;padding:40px 20px;color:#333;';
                    pdfWindow.document.body.innerHTML =
                        '<h2 style="font-weight:600;margin:0 0 12px">' + generatingTitle + '</h2>' +
                        '<p>' + generatingHint + '</p>';
                } catch (e) {
                    // Some browsers throw when writing into about:blank in odd
                    // sandboxing scenarios. The tab still works as a target;
                    // we just don't paint a preloader.
                }
            }
        }

        const { jsPDF } = window.jspdf;
        showOverlay();

        var minDisplayTime = 800;
        var startTime = Date.now();

        // Orientation: portrait or landscape (default)
        var isPortrait = pdfData.orientation === 'portrait';
        var containerW = isPortrait ? '794px' : '1123px';
        var containerH = isPortrait ? '1123px' : '794px';

        var $tempContainer = $('<div class="ffc-pdf-temp-container no-lazyload skip-lazy"></div>').css({
            'position': 'fixed',
            'top': '0',
            'left': '0',
            'width': containerW,
            'height': containerH,
            'overflow': 'hidden',
            'background': 'white',
            'z-index': '999998',
            'opacity': '0'
        }).appendTo('body');

        var processedHTML = pdfData.html || '';

        var finalHTML = '<div class="ffc-pdf-wrapper" style="width:100%;height:100%;position:relative;">';

        if (pdfData.bg_image) {
            finalHTML += '<img src="' + pdfData.bg_image + '" class="ffc-pdf-bg skip-lazy no-lazyload" loading="eager" style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;" crossorigin="anonymous">';
        }

        finalHTML += '<div class="ffc-pdf-content" style="position:relative;z-index:1;">' + processedHTML + '</div>';
        finalHTML += '</div>';

        $tempContainer.html(finalHTML);

        // Protect images from lazy-loading plugins (LiteSpeed Cache, WP Rocket, etc.)
        // These plugins use MutationObserver and may replace src with data-src immediately
        $tempContainer.find('img').each(function() {
            var $img = $(this);
            var src = $img.attr('src');

            // Store original src as backup before any plugin can modify it
            if (src) {
                $img.attr('data-ffc-original-src', src);
            }

            // Add all known anti-lazy-loading attributes
            $img.attr({
                'loading': 'eager',
                'decoding': 'sync',
                'data-no-lazy': '1',
                'data-skip-lazy': '1',
                'data-exclude': 'true'
            }).addClass('skip-lazy no-lazyload perfmatters-lazy-skip');
        });

        var images = $tempContainer.find('img');
        var totalImages = images.length;
        var loadedImages = 0;
        var imageLoadTimeout;

        function checkAllImagesLoaded() {
            loadedImages++;

            if (loadedImages >= totalImages) {
                clearTimeout(imageLoadTimeout);
                generatePDF();
            }
        }

        function forceGeneratePDF() {
            generatePDF();
        }

        if (totalImages > 0) {
            imageLoadTimeout = setTimeout(forceGeneratePDF, 10000);

            images.each(function() {
                var img = this;
                var $img = $(img);
                var src = $img.attr('src');

                // For data URIs, use decode() API to ensure image is ready
                if (src && src.startsWith('data:')) {
                    if (typeof img.decode === 'function') {
                        img.decode().then(function() {
                            checkAllImagesLoaded();
                        }).catch(function() {
                            console.warn('[FFC PDF] Data URI decode failed');
                            checkAllImagesLoaded();
                        });
                    } else if (img.complete && img.naturalHeight > 0) {
                        checkAllImagesLoaded();
                    } else {
                        $img.one('load', checkAllImagesLoaded);
                        $img.one('error', function() {
                            console.warn('[FFC PDF] Data URI image failed to load');
                            checkAllImagesLoaded();
                        });
                    }
                } else if (img.complete && img.naturalHeight > 0) {
                    checkAllImagesLoaded();
                } else {
                    $img.one('load', function() {
                        checkAllImagesLoaded();
                    });

                    $img.one('error', function() {
                        console.warn('[FFC PDF] Image failed to load:', src);
                        checkAllImagesLoaded();
                    });

                    if (src) {
                        var tempSrc = img.src;
                        img.src = '';
                        img.src = tempSrc;
                    }
                }
            });
        } else {
            generatePDF();
        }

        function generatePDF() {
            var elapsedTime = Date.now() - startTime;
            var remainingTime = Math.max(0, minDisplayTime - elapsedTime);

            setTimeout(function() {
                var element = $tempContainer.find('.ffc-pdf-wrapper')[0];

                if (!element) {
                    console.error('[FFC PDF] Wrapper not found');
                    var errorMsg = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.pdfContainerNotFound)
                        ? ffc_ajax.strings.pdfContainerNotFound
                        : 'Error: PDF container not found';
                    alert(errorMsg);
                    $tempContainer.remove();
                    hideOverlay();
                    return;
                }

                var a4WidthPx = isPortrait ? 794 : 1123;
                var a4HeightPx = isPortrait ? 1123 : 794;

                $(element).css({
                    'width': a4WidthPx + 'px',
                    'height': a4HeightPx + 'px',
                    'max-width': a4WidthPx + 'px',
                    'max-height': a4HeightPx + 'px',
                    'transform': 'scale(1)',
                    'overflow': 'hidden',
                    'box-sizing': 'border-box'
                });

                setTimeout(function() {
                    // Make container visible for html2canvas (it respects computed opacity)
                    // Container is safely behind the overlay (z-index 999998 vs 999999)
                    $tempContainer.css('opacity', '1');

                    // Restore img src that may have been hijacked by lazy-loading plugins
                    $tempContainer.find('img').each(function() {
                        var $img = $(this);
                        var currentSrc = $img.attr('src') || '';
                        var originalSrc = $img.attr('data-ffc-original-src');

                        // If src was replaced by a lazy-loading plugin, restore it
                        if (originalSrc && currentSrc !== originalSrc) {
                            $img.attr('src', originalSrc);
                        }

                        // Also check common lazy-loading data attributes
                        if (!currentSrc || currentSrc === 'about:blank' || currentSrc === 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7') {
                            var lazySrc = $img.attr('data-src') || $img.attr('data-lazy-src') || $img.attr('data-original') || originalSrc;
                            if (lazySrc) {
                                $img.attr('src', lazySrc);
                            }
                        }
                    });

                    // Reduce canvas scale on mobile to prevent memory
                    // exhaustion on low-end devices (scale 2 produces a
                    // ~14 MP image that can exceed 100 MB of RAM).
                    var isMobile = /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
                    var canvasScale = isMobile ? 1.5 : 2;

                    html2canvas(element, {
                        scale: canvasScale,
                        width: a4WidthPx,
                        height: a4HeightPx,
                        useCORS: true,
                        allowTaint: false,
                        logging: false,
                        backgroundColor: '#ffffff'
                    }).then(function(canvas) {
                        try {
                            var ctx = canvas.getContext('2d');
                            var imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                            var hasContent = false;

                            for (var i = 0; i < imgData.data.length; i += 4) {
                                var r = imgData.data[i];
                                var g = imgData.data[i + 1];
                                var b = imgData.data[i + 2];

                                if (r !== 255 || g !== 255 || b !== 255) {
                                    hasContent = true;
                                    break;
                                }
                            }

                            if (!hasContent) {
                                console.warn('[FFC PDF] Canvas is blank — check HTML/CSS.');
                                var blankMsg = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.pdfBlankWarning)
                                    ? ffc_ajax.strings.pdfBlankWarning
                                    : 'Warning: the generated PDF appears to be blank. Please try again.';
                                alert(blankMsg);
                                $tempContainer.remove();
                                hideOverlay();
                                return;
                            }

                            var pdfOrientation = isPortrait ? 'portrait' : 'landscape';
                            var pdf = new jsPDF(pdfOrientation, 'mm', 'a4');
                            var pdfImgData = canvas.toDataURL('image/png', 1.0);
                            var pdfW = isPortrait ? 210 : 297;
                            var pdfH = isPortrait ? 297 : 210;

                            pdf.addImage(pdfImgData, 'PNG', 0, 0, pdfW, pdfH);

                            // 6.3.6 — browsers in the needsPreOpen set got
                            // the placeholder window opened synchronously at
                            // the top of this function (still inside the
                            // user-gesture window). We just point it at the
                            // blob URL here. If the popup was blocked or
                            // closed, fall back to a manual-tap link in the
                            // overlay (a fresh user click reopens the
                            // gesture window).
                            //
                            // Mac Safari 14+, Chrome (desktop or Android),
                            // Firefox and Edge all honour <a download> via
                            // pdf.save() correctly, so the legacy "open in
                            // new tab on any Safari" detection (popup-prone
                            // on macOS) is gone.
                            //
                            // 6.6.2 (Sprint 2) — we still call pdf.save()
                            // on the desktop branch (it's what triggers the
                            // browser's native download UI), but we also
                            // hand the blob URL to the overlay so the user
                            // gets a manual "didn't download?" link as
                            // backup. Cases this catches:
                            //   • Firefox strict-tracking or sandbox config
                            //     swallowing the download silently;
                            //   • download-blocking extensions;
                            //   • per-site permission set to "don't ask,
                            //     don't download" with no notification UI.
                            var blobUrlForFallback = null;
                            if (needsPreOpen) {
                                var blobUrl = pdf.output('bloburl');
                                blobUrlForFallback = blobUrl;
                                if (pdfWindow && !pdfWindow.closed) {
                                    try {
                                        pdfWindow.location.href = blobUrl;
                                    } catch (navErr) {
                                        // Some embedded WebViews refuse the swap.
                                        // Treat as popup blocked.
                                        pdfWindow = null;
                                    }
                                }
                            } else {
                                blobUrlForFallback = pdf.output('bloburl');
                                pdf.save(filename || 'certificate.pdf');
                            }

                            $tempContainer.remove();

                            var popupBlocked = needsPreOpen && (! pdfWindow || pdfWindow.closed);
                            if (popupBlocked) {
                                showManualDownloadFallback(blobUrlForFallback, filename);
                                return;
                            }

                            // Show brief success feedback with platform-appropriate
                            // guidance, then auto-dismiss.
                            var successMsg;
                            if (isIOS) {
                                // Placeholder-tab path on iOS Safari → Safari share sheet.
                                successMsg = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.pdfOpenedIOS)
                                    ? ffc_ajax.strings.pdfOpenedIOS
                                    : 'PDF opened in a new tab. Tap the share icon to save or print.';
                            } else if (needsPreOpen) {
                                // Placeholder-tab path on Samsung Internet / Android WebView →
                                // PDF rendered inline in the new tab; user shares/saves from there.
                                successMsg = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.pdfOpenedAndroidTab)
                                    ? ffc_ajax.strings.pdfOpenedAndroidTab
                                    : 'PDF opened in a new tab. Use the menu to save or share.';
                            } else if (/Android/i.test(navigator.userAgent)) {
                                // pdf.save() path on Android Chrome / Firefox → file in Downloads.
                                successMsg = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.pdfSavedAndroid)
                                    ? ffc_ajax.strings.pdfSavedAndroid
                                    : 'PDF saved! Check your Downloads folder.';
                            } else {
                                // pdf.save() path on desktop browsers.
                                successMsg = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.pdfDownloaded)
                                    ? ffc_ajax.strings.pdfDownloaded
                                    : 'PDF downloaded successfully.';
                            }

                            $('#ffc-pdf-overlay').find('h3').text(successMsg);
                            $('#ffc-pdf-overlay').find('p').hide();
                            $('#ffc-pdf-overlay').find('.ffc-spinner').hide();

                            // 6.6.2 (Sprint 2) — manual "didn't download?"
                            // link for the desktop / Android Chrome branch.
                            // The needsPreOpen branches already handle their
                            // own fallback via the placeholder tab; only the
                            // pdf.save() callers need this safety net.
                            if (!needsPreOpen && blobUrlForFallback) {
                                showDesktopFallbackLink(blobUrlForFallback, filename);
                            }

                            // Keep the overlay visible long enough for the
                            // user to notice + click the fallback link if
                            // the browser ate the download (2s was too short
                            // on slow renders; 6s is the sweet spot per
                            // Sprint 2 manual testing).
                            setTimeout(hideOverlay, 6000);
                        } catch (error) {
                            console.error('[FFC PDF] Error:', error);
                            closePlaceholderTabIfAny();
                            var errorMsg = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.errorGeneratingPdf)
                                ? ffc_ajax.strings.errorGeneratingPdf
                                : 'Error generating PDF';
                            alert(errorMsg);
                            $tempContainer.remove();
                            hideOverlay();
                        }
                    }).catch(function(error) {
                        console.error('[FFC PDF] html2canvas error:', error);
                        closePlaceholderTabIfAny();
                        var errorMsg = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.html2canvasFailed)
                            ? ffc_ajax.strings.html2canvasFailed
                            : 'Error: html2canvas failed';
                        alert(errorMsg);
                        $tempContainer.remove();
                        hideOverlay();
                    });
                }, 300);
            }, remainingTime);
        }

        /**
         * Close the placeholder tab opened at the top of this function in
         * any error path so we don't leave the user with a stuck
         * "Generating…" tab. No-op when there's no placeholder (popup
         * blocker fired on the original open call).
         */
        function closePlaceholderTabIfAny() {
            if (pdfWindow && ! pdfWindow.closed) {
                try { pdfWindow.close(); } catch (e) { /* swallow */ }
            }
        }

        /**
         * 6.3.6 — Render a manual-tap link in the in-page overlay when
         * the iOS placeholder tab couldn't be used (popup blocked, closed
         * by user, or refused the location swap). The user's tap on the
         * link establishes a fresh user gesture so iOS Safari opens the
         * blob URL without further intervention.
         *
         * @param {string} blobUrl  Blob URL produced by jsPDF.
         * @param {string} fname    Suggested filename.
         */
        function showManualDownloadFallback(blobUrl, fname) {
            var $overlay = $('#ffc-pdf-overlay');
            if (! $overlay.length || ! blobUrl) {
                return;
            }
            var ctaLabel = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.pdfManualOpenIOS)
                ? ffc_ajax.strings.pdfManualOpenIOS
                : 'Tap to open the PDF';
            var hint = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.pdfManualHintIOS)
                ? ffc_ajax.strings.pdfManualHintIOS
                : 'Pop-ups are blocked in this browser. Tap the button below to open the PDF — then use the Safari share icon to save or print.';
            $overlay.find('.ffc-spinner').hide();
            $overlay.find('h3').text(ctaLabel);
            var $cta = $('<a>')
                .attr('href', blobUrl)
                .attr('target', '_blank')
                .attr('rel', 'noopener')
                .attr('download', fname || 'certificate.pdf')
                .addClass('ffc-pdf-fallback-btn')
                .text(ctaLabel)
                .css({
                    'display': 'inline-block',
                    'margin': '20px auto 8px',
                    'padding': '14px 28px',
                    'background': '#0073aa',
                    'color': '#fff',
                    'text-decoration': 'none',
                    'border-radius': '6px',
                    'font-weight': '600',
                    'font-size': '16px'
                });
            // Tapping the fallback link IS a fresh user gesture — Safari
            // opens the blob URL fine. Dismiss the overlay on that tap.
            $cta.on('click', function () {
                setTimeout(hideOverlay, 250);
            });
            $overlay.find('p').empty().text(hint);
            $overlay.find('p').after($cta);
        }

        /**
         * 6.6.2 (Sprint 2) — secondary "didn't download?" link for the
         * desktop / Android Chrome branch. Unlike showManualDownloadFallback,
         * the primary download already fired via pdf.save(); this is a
         * safety net for the silent-fail case (extensions, strict
         * download policies). Renders a small inline link under the
         * success message rather than replacing the headline, so happy-path
         * users barely notice it.
         *
         * @param {string} blobUrl  Blob URL produced by jsPDF.
         * @param {string} fname    Suggested filename.
         */
        function showDesktopFallbackLink(blobUrl, fname) {
            var $overlay = $('#ffc-pdf-overlay');
            if (!$overlay.length || !blobUrl) {
                return;
            }
            var hintText = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.pdfDesktopFallbackHint)
                ? ffc_ajax.strings.pdfDesktopFallbackHint
                : "Didn't download? Click here to open the PDF in a new tab.";
            var $link = $('<a>')
                .attr('href', blobUrl)
                .attr('target', '_blank')
                .attr('rel', 'noopener')
                .attr('download', fname || 'certificate.pdf')
                .addClass('ffc-pdf-desktop-fallback')
                .text(hintText)
                .css({
                    'display': 'inline-block',
                    'margin-top': '16px',
                    'font-size': '14px',
                    'color': '#0073aa',
                    'text-decoration': 'underline'
                });
            // Don't overwrite headline / spinner state — append below.
            $overlay.find('.ffc-pdf-desktop-fallback').remove();
            $overlay.find('h3').after($link);
        }
    }

    window.ffcGeneratePDF = generateAndDownloadPDF;

    window.ffcPdfGenerator = {
        generatePDF: generateAndDownloadPDF,
        checkLibraries: checkPDFLibraries
    };

})(jQuery, window);
