/**
 * FFC PDF Generator - Standalone Module
 * 
 * Shared PDF generation logic for both frontend and admin
 * 
 * @version 2.9.3 - FIXED: Container visible during capture
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

        var title = $('<h3></h3>').css({
            'margin': '0 0 10px 0',
            'color': '#333',
            'font-size': '18px',
            'font-weight': '600'
        }).text('Generating PDF...');

        var message = $('<p></p>').css({
            'margin': '0',
            'color': '#666',
            'font-size': '14px',
            'line-height': '1.5'
        }).text('Please wait, this may take a few seconds...');

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
        console.log('[FFC PDF] Template length:', pdfData.template ? pdfData.template.length : 0);
        
        if (!checkPDFLibraries()) {
            alert('PDF libraries failed to load. Please refresh the page.');
            return;
        }

        const { jsPDF } = window.jspdf;
        showOverlay();

        var minDisplayTime = 800;
        var startTime = Date.now();

        // ✅ FIX: Create container IN VIEWPORT but hidden behind overlay
        var $tempContainer = $('<div class="ffc-pdf-temp-container"></div>').css({
            'position': 'fixed',
            'top': '0',           // ← Na viewport!
            'left': '0',          // ← Na viewport!
            'width': '1123px',
            'height': '794px',
            'overflow': 'hidden',
            'background': 'white',
            'z-index': '999998',  // ← Atrás do overlay (999999)
            'opacity': '0'        // ← Invisível mas renderizado
        }).appendTo('body');

        var processedHTML = pdfData.template || '';
        
        console.log('[FFC PDF] HTML preview:', processedHTML.substring(0, 200));

        var finalHTML = '<div class="ffc-pdf-wrapper" style="width:100%;height:100%;position:relative;">';
        
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
                    alert('Error: PDF container not found');
                    $tempContainer.remove();
                    hideOverlay();
                    return;
                }
                
                var a4WidthPx = 1123;
                var a4HeightPx = 794;
                
                console.log('[FFC PDF] === PDF Generation Started ===');
                console.log('[FFC PDF] Target:', a4WidthPx + 'x' + a4HeightPx + 'px');
                
                $(element).css({
                    'width': a4WidthPx + 'px',
                    'height': a4HeightPx + 'px',
                    'max-width': a4WidthPx + 'px',
                    'max-height': a4HeightPx + 'px',
                    'transform': 'scale(1)',
                    'overflow': 'hidden',
                    'box-sizing': 'border-box'
                });
                
                // ✅ Extra delay para garantir renderização
                setTimeout(function() {
                    console.log('[FFC PDF] Capturing with html2canvas...');
                    
                    html2canvas(element, {
                        scale: 2,
                        width: a4WidthPx,
                        height: a4HeightPx,
                        useCORS: true,
                        allowTaint: false,
                        logging: true,  // ← Debug ativado
                        backgroundColor: '#ffffff'
                    }).then(function(canvas) {
                        try {
                            console.log('[FFC PDF] Canvas:', canvas.width + 'x' + canvas.height + 'px');
                            
                            // ✅ Verificar se canvas tem conteúdo
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
                            
                            console.log('[FFC PDF] Canvas has content?', hasContent);
                            
                            if (!hasContent) {
                                console.warn('[FFC PDF] Canvas is blank! Check HTML/CSS.');
                            }
                            
                            var pdf = new jsPDF('landscape', 'mm', 'a4');
                            var pdfImgData = canvas.toDataURL('image/png', 1.0);
                            
                            pdf.addImage(pdfImgData, 'PNG', 0, 0, 297, 210);
                            pdf.save(filename || 'certificate.pdf');
                            
                            console.log('[FFC PDF] PDF saved:', filename);
                            console.log('[FFC PDF] === Complete ===');

                            $tempContainer.remove();
                            hideOverlay();
                        } catch (error) {
                            console.error('[FFC PDF] Error:', error);
                            alert('Error generating PDF');
                            $tempContainer.remove();
                            hideOverlay();
                        }
                    }).catch(function(error) {
                        console.error('[FFC PDF] html2canvas error:', error);
                        alert('Error: html2canvas failed');
                        $tempContainer.remove();
                        hideOverlay();
                    });
                }, 300); // ← 300ms para garantir renderização
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