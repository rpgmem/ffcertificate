/**
 * FFC Admin - PDF Management Module
 * v3.1.0 - Extracted from ffc-admin.js
 *
 * Handles PDF template management, background images, and PDF downloads
 *
 * Dependencies:
 * - jQuery
 * - WordPress Media API (wp.media)
 * - FFC Core (for notifications via window.FFC.Admin.showNotification)
 * - window.ffcGeneratePDF() - Shared PDF generator module
 *
 * @since 3.1.0
 */

(function($, FFC) {
    'use strict';

    // ==========================================================================
    // TEMPLATE MANAGEMENT
    // ==========================================================================

    // Load Template button - Opens modal to select template
    $(document).on('click', '#ffc_load_template_btn', function(e) {
        e.preventDefault();
        console.log('[FFC] Load Template button clicked');

        // Lista de templates disponíveis (hardcoded por enquanto, pode vir de PHP depois)
        var templates = [
            { value: 'atestado_estagios.html', label: 'Atestado de Estágios' },
            { value: 'certificado_1.html', label: 'Certificado Modelo 1' },
            { value: 'certificado_2.html', label: 'Certificado Modelo 2' },
            { value: 'declaracao.html', label: 'Declaração' }
        ];

        // Get localized strings with fallbacks
        var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};
        var selectTemplateText = strings.selectTemplate || 'Select a Template';
        var cancelText = strings.cancel || 'Cancel';

        // Criar modal de seleção
        var modalHtml = '<div id="ffc-template-modal" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:999999;display:flex;align-items:center;justify-content:center;">';
        modalHtml += '<div style="background:#fff;padding:30px;border-radius:8px;max-width:500px;width:90%;box-shadow:0 4px 20px rgba(0,0,0,0.3);">';
        modalHtml += '<h2 style="margin:0 0 20px 0;font-size:20px;">' + selectTemplateText + '</h2>';
        modalHtml += '<div style="max-height:400px;overflow-y:auto;">';

        templates.forEach(function(template) {
            modalHtml += '<div class="ffc-template-option" data-file="' + template.value + '" style="padding:15px;margin:10px 0;border:2px solid #ddd;border-radius:4px;cursor:pointer;transition:all 0.2s;">';
            modalHtml += '<strong style="font-size:16px;">' + template.label + '</strong>';
            modalHtml += '<div style="color:#666;font-size:13px;margin-top:5px;">' + template.value + '</div>';
            modalHtml += '</div>';
        });

        modalHtml += '</div>';
        modalHtml += '<div style="margin-top:20px;text-align:right;">';
        modalHtml += '<button id="ffc-modal-cancel" class="button" style="margin-right:10px;">' + cancelText + '</button>';
        modalHtml += '</div>';
        modalHtml += '</div></div>';

        $('body').append(modalHtml);

        // Hover effect
        $('.ffc-template-option').hover(
            function() { $(this).css({'border-color': '#2271b1', 'background': '#f0f6fc'}); },
            function() { $(this).css({'border-color': '#ddd', 'background': 'transparent'}); }
        );

        // Cancel button
        $('#ffc-modal-cancel').on('click', function() {
            $('#ffc-template-modal').fadeOut(200, function() { $(this).remove(); });
        });

        // Click outside to close
        $('#ffc-template-modal').on('click', function(e) {
            if (e.target.id === 'ffc-template-modal') {
                $(this).fadeOut(200, function() { $(this).remove(); });
            }
        });

        // Template selection
        $('.ffc-template-option').on('click', function() {
            var templateFile = $(this).data('file');
            var templateName = $(this).find('strong').text();

            $('#ffc-template-modal').remove();

            // Get localized string or fallback to English
            var confirmMsg = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.confirmLoadTemplate)
                ? ffc_ajax.strings.confirmLoadTemplate.replace('%s', templateName)
                : 'Load "' + templateName + '"? This will replace your current certificate HTML.';

            if (!confirm(confirmMsg)) {
                return;
            }

            loadTemplateFile(templateFile, templateName);
        });
    });

    // Function to load template file via fetch
    function loadTemplateFile(filename, displayName) {
        console.log('[FFC] Loading template:', filename);

        var templateUrl = '/wp-content/plugins/wp-ffcertificate/html/' + filename;
        var showNotification = window.FFC.Admin.showNotification || function() {};
        var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};

        // Show loading notification
        var loadingText = strings.loadingTemplate || 'Loading template...';
        showNotification(loadingText, 'info', 0);

        fetch(templateUrl)
            .then(function(response) {
                console.log('[FFC] Fetch response status:', response.status);

                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }

                return response.text();
            })
            .then(function(htmlContent) {
                console.log('[FFC] Template loaded, length:', htmlContent.length);

                var $htmlField = $('#ffc_pdf_layout');

                if ($htmlField.length) {
                    $htmlField.val(htmlContent);
                    $htmlField.trigger('change');
                    var successTemplate = strings.templateLoadedSuccess || 'Template "%s" loaded successfully!';
                    var successMsg = successTemplate.replace('%s', displayName || filename);
                    showNotification('✓ ' + successMsg, 'success', 3000);
                    console.log('[FFC] ✓ Template loaded into #ffc_pdf_layout');
                } else {
                    var errorMsg = strings.htmlFieldNotFound || 'HTML field not found.';
                    showNotification('✗ ' + errorMsg, 'error');
                    console.error('[FFC] ✗ HTML field #ffc_pdf_layout not found');
                }
            })
            .catch(function(error) {
                console.error('[FFC] ✗ Fetch error:', error);

                var errorMsg = '';
                if (error.message.includes('404')) {
                    errorMsg = strings.templateFileNotFound || 'Template file not found. Check if file exists in html/ folder.';
                } else if (error.message.includes('403')) {
                    errorMsg = strings.accessDenied || 'Access denied. Check file permissions.';
                } else if (error.message.includes('Failed to fetch')) {
                    errorMsg = strings.networkError || 'Network error. Check your connection.';
                } else {
                    var errorTemplate = strings.errorLoadingTemplate || 'Error loading template: %s';
                    errorMsg = errorTemplate.replace('%s', error.message);
                }

                showNotification('✗ ' + errorMsg, 'error', 8000);
            });
    }

    // ==========================================================================
    // IMPORT HTML FILE
    // ==========================================================================

    // Import HTML file button
    $(document).on('click', '#ffc_btn_import_html', function(e) {
        e.preventDefault();
        console.log('[FFC] Import HTML clicked');
        var showNotification = window.FFC.Admin.showNotification || function() {};

        // Try to find file input
        var $fileInput = $('#ffc_import_html_file');
        if (!$fileInput.length) {
            $fileInput = $('input[type="file"][name*="import"]');
        }

        if ($fileInput.length) {
            console.log('[FFC] File input found, clicking...');
            $fileInput.click();
        } else {
            // Create file input on the fly
            console.log('[FFC] File input not found, creating temporary input');
            var $tempInput = $('<input type="file" accept=".html" style="display:none">');
            $('body').append($tempInput);

            $tempInput.on('change', function(e) {
                var file = e.target.files[0];
                if (!file) return;

                var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};

                if (file.type !== 'text/html' && !file.name.endsWith('.html')) {
                    var warningMsg = strings.selectHtmlFile || 'Please select an HTML file.';
                    showNotification(warningMsg, 'warning');
                    return;
                }

                var reader = new FileReader();
                reader.onload = function(e) {
                    var $htmlField = $('#ffc_pdf_layout');

                    if ($htmlField.length) {
                        $htmlField.val(e.target.result);
                        var successMsg = strings.htmlImportedSuccess || 'HTML imported successfully!';
                        showNotification(successMsg, 'success');
                        console.log('[FFC] HTML file imported to #ffc_pdf_layout');
                    } else {
                        var errorMsg = strings.htmlFieldNotFound || 'HTML field not found.';
                        showNotification('Error: ' + errorMsg, 'error');
                        console.error('[FFC] HTML field not found');
                    }

                    $tempInput.remove();
                };
                reader.readAsText(file);
            });

            $tempInput.click();
        }
    });

    // Also handle file input change (if it exists in HTML)
    $(document).on('change', '#ffc_import_html_file, input[type="file"][name*="import"]', function(e) {
        var file = e.target.files[0];

        if (!file) return;

        console.log('[FFC] File selected via change handler:', file.name);
        var showNotification = window.FFC.Admin.showNotification || function() {};
        var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};

        var reader = new FileReader();
        reader.onload = function(evt) {
            var $htmlField = $('#ffc_pdf_layout');

            if ($htmlField.length) {
                $htmlField.val(evt.target.result);
                console.log('[FFC] ✓ HTML imported to #ffc_pdf_layout');
                var successMsg = strings.htmlImportedSuccess || 'HTML imported successfully!';
                showNotification(successMsg, 'success');
            } else {
                console.error('[FFC] ✗ Field #ffc_pdf_layout not found');
                var errorMsg = strings.htmlTextareaNotFound || 'HTML textarea not found';
                showNotification('Error: ' + errorMsg, 'error');
            }
        };
        reader.onerror = function() {
            console.error('[FFC] Error reading file');
            var errorMsg = strings.errorReadingFile || 'Error reading file';
            showNotification(errorMsg, 'error');
        };
        reader.readAsText(file);

        // Reset input
        $(this).val('');
    });

    // ==========================================================================
    // MEDIA LIBRARY - Background Image
    // ==========================================================================

    var mediaUploader;

    $(document).on('click', '#ffc_btn_media_lib', function(e) {
        e.preventDefault();
        console.log('[FFC] Background Image clicked');
        var showNotification = window.FFC.Admin.showNotification || function() {};
        var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};

        // Check if wp.media is available
        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
            var errorMsg = strings.wpMediaNotAvailable || 'WordPress Media Library is not available. Please reload the page.';
            showNotification(errorMsg, 'error');
            console.error('[FFC] wp.media is not defined');
            return;
        }

        // If the uploader object has already been created, reopen the dialog
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }

        // Create the media uploader
        var titleText = strings.chooseBackgroundImage || 'Choose Background Image';
        var buttonText = strings.useThisImage || 'Use this image';

        mediaUploader = wp.media({
            title: titleText,
            button: {
                text: buttonText
            },
            multiple: false
        });

        // When an image is selected, run a callback
        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();

            // Try to find BG image URL field
            var $urlField = $('#ffc_bg_image_url');
            if (!$urlField.length) {
                $urlField = $('input[name*="bg_image"], input[name*="background"]').first();
            }

            if ($urlField.length) {
                $urlField.val(attachment.url);
            }

            // Try to find preview element
            var $preview = $('#ffc_bg_image_preview');
            if ($preview.length) {
                $preview.html('<img src="' + attachment.url + '" style="max-width: 200px; height: auto;">');
            }

            var successMsg = strings.backgroundImageSelected || 'Background image selected!';
            showNotification(successMsg, 'success');
            console.log('[FFC] Background image selected:', attachment.url);
        });

        mediaUploader.open();
    });

    // ==========================================================================
    // PUBLIC API - Export functions
    // ==========================================================================

    // Initialize FFC.Admin namespace if not exists
    window.FFC = window.FFC || {};
    window.FFC.Admin = window.FFC.Admin || {};
    window.FFC.Admin.PDF = {
        loadTemplate: loadTemplateFile
    };

    // Register module
    if (FFC.registerModule) {
        FFC.registerModule('Admin.PDF', '3.1.0');
    }

    console.log('[FFC Admin PDF] Module loaded v3.1.0');

})(jQuery, window.FFC);
