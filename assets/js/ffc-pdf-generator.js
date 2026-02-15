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
        console.log('[FFC PDF] Starting PDF generation...');
        console.log('[FFC PDF] Template length:', pdfData.html ? pdfData.html.length : 0);

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
        var isMultiPage = pdfData.type === 'ficha'; // Fichas support multi-page
        var containerW = isPortrait ? '794px' : '1123px';
        var containerH = isPortrait ? '1123px' : '794px';

        console.log('[FFC PDF] Orientation:', isPortrait ? 'portrait' : 'landscape');
        console.log('[FFC PDF] Multi-page:', isMultiPage);

        // Create container IN VIEWPORT but hidden behind overlay
        var containerCss = {
            'position': 'fixed',
            'top': '0',
            'left': '0',
            'width': containerW,
            'overflow': 'hidden',
            'background': 'white',
            'z-index': '999998',
            'opacity': '0'
        };

        // For multi-page: let content flow to natural height
        // For single-page: fixed A4 height
        if (isMultiPage) {
            containerCss['height'] = 'auto';
            containerCss['overflow'] = 'visible';
        } else {
            containerCss['height'] = containerH;
        }

        var $tempContainer = $('<div class="ffc-pdf-temp-container"></div>').css(containerCss).appendTo('body');

        var processedHTML = pdfData.html || '';

        console.log('[FFC PDF] HTML preview:', processedHTML.substring(0, 200));

        var wrapperHeight = isMultiPage ? 'auto' : '100%';
        var finalHTML = '<div class="ffc-pdf-wrapper" style="width:100%;height:' + wrapperHeight + ';position:relative;">';

        if (pdfData.bg_image) {
            finalHTML += '<img src="' + pdfData.bg_image + '" class="ffc-pdf-bg" style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;" crossorigin="anonymous">';
        }

        finalHTML += '<div class="ffc-pdf-content" style="position:relative;z-index:1;">' + processedHTML + '</div>';
        finalHTML += '</div>';

        $tempContainer.html(finalHTML);
        
        console.log('[FFC PDF] Container created and visible');

        var images = $tempContainer.find('img');
        var totalImages = images.length;
        var loadedImages = 0;
        var imageLoadTimeout;

        console.log('[FFC PDF] Waiting for ' + totalImages + ' images to load...');

        function checkAllImagesLoaded() {
            loadedImages++;
            console.log('[FFC PDF] Image loaded: ' + loadedImages + '/' + totalImages);
            
            if (loadedImages >= totalImages) {
                clearTimeout(imageLoadTimeout);
                console.log('[FFC PDF] All images loaded! Generating PDF...');
                generatePDF();
            }
        }

        function forceGeneratePDF() {
            console.log('[FFC PDF] Timeout. Generating with ' + loadedImages + '/' + totalImages + ' images.');
            generatePDF();
        }

        if (totalImages > 0) {
            imageLoadTimeout = setTimeout(forceGeneratePDF, 10000);
            
            images.each(function(index) {
                var img = this;
                var $img = $(img);
                var src = $img.attr('src');
                
                console.log('[FFC PDF] Image ' + (index + 1) + ':', src ? src.substring(0, 80) : 'no src');
                
                if (img.complete && img.naturalHeight > 0) {
                    console.log('[FFC PDF] Image ' + (index + 1) + ' already loaded');
                    checkAllImagesLoaded();
                } else {
                    $img.one('load', function() {
                        console.log('[FFC PDF] Image ' + (index + 1) + ' loaded');
                        checkAllImagesLoaded();
                    });
                    
                    $img.one('error', function() {
                        console.warn('[FFC PDF] Image ' + (index + 1) + ' failed:', src);
                        checkAllImagesLoaded();
                    });
                    
                    if (src && !src.startsWith('data:')) {
                        var tempSrc = img.src;
                        img.src = '';
                        img.src = tempSrc;
                    }
                }
            });
        } else {
            console.log('[FFC PDF] No images, generating immediately');
            generatePDF();
        }

        function generatePDF() {
            var elapsedTime = Date.now() - startTime;
            var remainingTime = Math.max(0, minDisplayTime - elapsedTime);
            
            console.log('[FFC PDF] Elapsed:', elapsedTime + 'ms', 'Remaining:', remainingTime + 'ms');
            
            setTimeout(function() {
                var element = $tempContainer.find('.ffc-pdf-wrapper')[0];
                
                if (!element) {
                    console.error('[FFC PDF] Wrapper not found!');
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

                console.log('[FFC PDF] === PDF Generation Started ===');

                // For multi-page: measure natural height; for single-page: use A4
                var captureHeight;
                if (isMultiPage) {
                    $(element).css({
                        'width': a4WidthPx + 'px',
                        'max-width': a4WidthPx + 'px',
                        'transform': 'scale(1)',
                        'box-sizing': 'border-box'
                    });
                    captureHeight = element.scrollHeight;
                    console.log('[FFC PDF] Content height:', captureHeight + 'px', '(' + Math.ceil(captureHeight / a4HeightPx) + ' pages)');
                } else {
                    $(element).css({
                        'width': a4WidthPx + 'px',
                        'height': a4HeightPx + 'px',
                        'max-width': a4WidthPx + 'px',
                        'max-height': a4HeightPx + 'px',
                        'transform': 'scale(1)',
                        'overflow': 'hidden',
                        'box-sizing': 'border-box'
                    });
                    captureHeight = a4HeightPx;
                }

                console.log('[FFC PDF] Target:', a4WidthPx + 'x' + captureHeight + 'px');

                // Extra delay to ensure rendering
                setTimeout(function() {
                    console.log('[FFC PDF] Capturing with html2canvas...');

                    html2canvas(element, {
                        scale: 2,
                        width: a4WidthPx,
                        height: captureHeight,
                        useCORS: true,
                        allowTaint: false,
                        logging: true,
                        backgroundColor: '#ffffff'
                    }).then(function(canvas) {
                        try {
                            console.log('[FFC PDF] Canvas:', canvas.width + 'x' + canvas.height + 'px');

                            var pdfOrientation = isPortrait ? 'portrait' : 'landscape';
                            var pdf = new jsPDF(pdfOrientation, 'mm', 'a4');
                            var pdfW = isPortrait ? 210 : 297;
                            var pdfH = isPortrait ? 297 : 210;
                            var scaleFactor = 2; // matches html2canvas scale

                            var pageHeightPx = a4HeightPx * scaleFactor;
                            var totalPages = Math.ceil(canvas.height / pageHeightPx);

                            console.log('[FFC PDF] Total pages:', totalPages);

                            for (var p = 0; p < totalPages; p++) {
                                if (p > 0) {
                                    pdf.addPage();
                                }

                                // Create a canvas for this page slice
                                var pageCanvas = document.createElement('canvas');
                                pageCanvas.width = canvas.width;
                                var sliceHeight = Math.min(pageHeightPx, canvas.height - p * pageHeightPx);
                                pageCanvas.height = sliceHeight;

                                var pageCtx = pageCanvas.getContext('2d');
                                pageCtx.fillStyle = '#ffffff';
                                pageCtx.fillRect(0, 0, pageCanvas.width, pageCanvas.height);
                                pageCtx.drawImage(
                                    canvas,
                                    0, p * pageHeightPx,            // source x, y
                                    canvas.width, sliceHeight,      // source w, h
                                    0, 0,                           // dest x, y
                                    canvas.width, sliceHeight       // dest w, h
                                );

                                var pageImgData = pageCanvas.toDataURL('image/png', 1.0);
                                // Scale the slice to the correct proportion on the PDF page
                                var sliceHmm = pdfH * (sliceHeight / pageHeightPx);
                                pdf.addImage(pageImgData, 'PNG', 0, 0, pdfW, sliceHmm);
                            }

                            pdf.save(filename || 'certificate.pdf');

                            console.log('[FFC PDF] PDF saved:', filename, '(' + totalPages + ' page(s))');
                            console.log('[FFC PDF] === Complete ===');

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

    // ✅ Export as function (legacy compatibility)
    window.ffcGeneratePDF = generateAndDownloadPDF;
    
    // ✅ Export as object (modern compatibility)
    window.ffcPdfGenerator = {
        generatePDF: generateAndDownloadPDF,
        checkLibraries: checkPDFLibraries
    };
    
    console.log('[FFC PDF] PDF Generator module loaded (FIXED)');

})(jQuery, window);