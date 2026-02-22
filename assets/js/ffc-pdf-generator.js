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
            : 'Generating PDF...';

        var title = $('<h3></h3>').css({
            'margin': '0 0 10px 0',
            'color': '#333',
            'font-size': '18px',
            'font-weight': '600'
        }).text(titleText);

        var messageText = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.pleaseWait)
            ? ffc_ajax.strings.pleaseWait
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

        // Ensure all images opt out of lazy loading (prevents plugins from interfering)
        $tempContainer.find('img').attr({
            'loading': 'eager',
            'decoding': 'sync'
        }).addClass('skip-lazy no-lazyload');

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
                    html2canvas(element, {
                        scale: 2,
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
                            }

                            var pdfOrientation = isPortrait ? 'portrait' : 'landscape';
                            var pdf = new jsPDF(pdfOrientation, 'mm', 'a4');
                            var pdfImgData = canvas.toDataURL('image/png', 1.0);
                            var pdfW = isPortrait ? 210 : 297;
                            var pdfH = isPortrait ? 297 : 210;

                            pdf.addImage(pdfImgData, 'PNG', 0, 0, pdfW, pdfH);
                            pdf.save(filename || 'certificate.pdf');

                            $tempContainer.remove();
                            hideOverlay();
                        } catch (error) {
                            console.error('[FFC PDF] Error:', error);
                            var errorMsg = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.errorGeneratingPdf)
                                ? ffc_ajax.strings.errorGeneratingPdf
                                : 'Error generating PDF';
                            alert(errorMsg);
                            $tempContainer.remove();
                            hideOverlay();
                        }
                    }).catch(function(error) {
                        console.error('[FFC PDF] html2canvas error:', error);
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
    }

    window.ffcGeneratePDF = generateAndDownloadPDF;

    window.ffcPdfGenerator = {
        generatePDF: generateAndDownloadPDF,
        checkLibraries: checkPDFLibraries
    };

})(jQuery, window);
