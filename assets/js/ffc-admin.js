/**
 * Free Form Certificate - Admin JavaScript  
 * v3.0.1: FINAL - All features working
 */

(function($) {
    'use strict';

    console.log('[FFC Admin] Initializing v3.0.1 FINAL...');
    
    // ==========================================================================
    // NOTIFICATION SYSTEM - Replace alerts with inline messages
    // ==========================================================================
    
    function showNotification(message, type, duration) {
        type = type || 'info';
        duration = duration || 5000;
        
        $('.ffc-admin-notification').remove();
        
        var icons = {success: 'yes-alt', error: 'dismiss', warning: 'warning', info: 'info'};
        var colors = {success: 'notice-success', error: 'notice-error', warning: 'notice-warning', info: 'notice-info'};
        
        var $notif = $('<div class="ffc-admin-notification notice ' + colors[type] + ' is-dismissible">' +
            '<p><span class="dashicons dashicons-' + icons[type] + '"></span> ' + message + '</p>' +
            '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button>' +
            '</div>');
        
        if ($('.wrap > h1').length) {
            $('.wrap > h1').after($notif);
        } else {
            $('#wpbody-content').prepend($notif);
        }
        
        $notif.find('.notice-dismiss').on('click', function() {
            $notif.fadeOut(200, function() { $(this).remove(); });
        });
        
        if (duration > 0) {
            setTimeout(function() {
                $notif.fadeOut(200, function() { $(this).remove(); });
            }, duration);
        }
    }

    // ==========================================================================
    // FIELD BUILDER - Add/Remove Fields
    // ==========================================================================
    
    var fieldCounter = 0;
    var fieldTypes = [
        { value: 'text', label: 'Text Field' },
        { value: 'email', label: 'Email' },
        { value: 'number', label: 'Number' },
        { value: 'textarea', label: 'Textarea' },
        { value: 'select', label: 'Dropdown Select' },
        { value: 'checkbox', label: 'Checkbox' },
        { value: 'radio', label: 'Radio Buttons' },
        { value: 'date', label: 'Date' }
    ];

    // Initialize Form Builder
    function initFormBuilder() {
        console.log('[FFC] Initializing Form Builder...');
        
        if ($('#ffc-fields-container').length === 0) {
            console.log('[FFC] Fields container not found');
            return;
        }

        // Count existing fields
        fieldCounter = $('#ffc-fields-container .ffc-field-row').length;
        console.log('[FFC] Found', fieldCounter, 'existing fields');

        // Add Field button - Show dropdown menu
        $(document).on('click', '.ffc-add-field', function(e) {
            e.preventDefault();
            console.log('[FFC] Add Field clicked');
            
            showFieldTypeMenu($(this));
        });

        // Remove Field button
        $(document).on('click', '.ffc-remove-field', function(e) {
            e.preventDefault();
            
            if (confirm('Remove this field?')) {
                $(this).closest('.ffc-field-row').fadeOut(300, function() {
                    $(this).remove();
                    updateFieldsJSON();
                    console.log('[FFC] Field removed');
                });
            }
        });

        // Update JSON when fields change
        $('#ffc-fields-container').on('change input', 'input, select, textarea', function() {
            updateFieldsJSON();
        });

        // Make fields sortable
        if ($.fn.sortable) {
            $('#ffc-fields-container').sortable({
                handle: '.ffc-sort-handle',
                placeholder: 'ffc-field-placeholder',
                update: function() {
                    updateFieldsJSON();
                    console.log('[FFC] Fields reordered');
                }
            });
        }
    }

    // Show field type dropdown menu
    function showFieldTypeMenu($button) {
        // Remove existing menu
        $('.ffc-field-type-menu').remove();

        // Create menu
        var $menu = $('<div class="ffc-field-type-menu"></div>');
        $menu.append('<div class="ffc-menu-header">Choose Field Type:</div>');
        
        var $list = $('<ul></ul>');
        fieldTypes.forEach(function(type) {
            var $item = $('<li data-type="' + type.value + '">' + type.label + '</li>');
            $item.on('click', function() {
                addFieldToBuilder(type.value);
                $menu.remove();
            });
            $list.append($item);
        });
        
        $menu.append($list);
        
        // Position menu below button
        var btnOffset = $button.offset();
        var btnHeight = $button.outerHeight();
        
        $menu.css({
            position: 'absolute',
            top: (btnOffset.top + btnHeight + 5) + 'px',
            left: btnOffset.left + 'px',
            zIndex: 99999,
            background: '#fff',
            border: '1px solid #ccc',
            borderRadius: '4px',
            boxShadow: '0 2px 8px rgba(0,0,0,0.15)',
            minWidth: '200px'
        });
        
        $('body').append($menu);
        
        // Close menu when clicking outside
        setTimeout(function() {
            $(document).one('click', function() {
                $menu.remove();
            });
        }, 100);
        
        console.log('[FFC] Field type menu shown');
    }

    // Add field to builder
    function addFieldToBuilder(fieldType) {
        fieldCounter++;
        
        var fieldHtml = '<div class="ffc-field-row" data-index="' + fieldCounter + '">';
        fieldHtml += '  <div class="ffc-field-row-header">';
        fieldHtml += '    <span class="ffc-sort-handle">';
        fieldHtml += '      <span class="dashicons dashicons-menu"></span>';
        fieldHtml += '      <span class="ffc-field-title"><strong>' + fieldType.toUpperCase() + '</strong></span>';
        fieldHtml += '    </span>';
        fieldHtml += '    <button type="button" class="button button-link-delete ffc-remove-field">Remove</button>';
        fieldHtml += '  </div>';
        fieldHtml += '  <div class="ffc-field-row-body">';
        fieldHtml += '    <table class="form-table">';
        fieldHtml += '      <tr>';
        fieldHtml += '        <th><label>Field Type:</label></th>';
        fieldHtml += '        <td><select class="ffc-field-type" name="ffc_fields[' + fieldCounter + '][type]">';
        
        fieldTypes.forEach(function(type) {
            var selected = type.value === fieldType ? ' selected' : '';
            fieldHtml += '<option value="' + type.value + '"' + selected + '>' + type.label + '</option>';
        });
        
        fieldHtml += '        </select></td>';
        fieldHtml += '      </tr>';
        fieldHtml += '      <tr>';
        fieldHtml += '        <th><label>Label:</label></th>';
        fieldHtml += '        <td><input type="text" class="ffc-field-label regular-text" name="ffc_fields[' + fieldCounter + '][label]" placeholder="Field Label"></td>';
        fieldHtml += '      </tr>';
        fieldHtml += '      <tr>';
        fieldHtml += '        <th><label>Name (variable):</label></th>';
        fieldHtml += '        <td><input type="text" class="ffc-field-name regular-text" name="ffc_fields[' + fieldCounter + '][name]" placeholder="field_name"></td>';
        fieldHtml += '      </tr>';
        fieldHtml += '      <tr>';
        fieldHtml += '        <th><label>Required:</label></th>';
        fieldHtml += '        <td><input type="checkbox" class="ffc-field-required" name="ffc_fields[' + fieldCounter + '][required]" value="1"></td>';
        fieldHtml += '      </tr>';
        
        // Additional options for select/radio/checkbox
        if (fieldType === 'select' || fieldType === 'radio' || fieldType === 'checkbox') {
            fieldHtml += '      <tr>';
            fieldHtml += '        <th><label>Options:</label></th>';
            fieldHtml += '        <td><textarea class="ffc-field-options large-text" name="ffc_fields[' + fieldCounter + '][options]" rows="3" placeholder="Separate with commas"></textarea></td>';
            fieldHtml += '      </tr>';
        }
        
        fieldHtml += '    </table>';
        fieldHtml += '  </div>';
        fieldHtml += '</div>';
        
        $('#ffc-fields-container').append(fieldHtml);
        updateFieldsJSON();
        
        console.log('[FFC] Field added:', fieldType);
    }

    // Update hidden JSON field with current fields
    function updateFieldsJSON() {
        var fields = [];
        
        $('#ffc-fields-container .ffc-field-row').each(function() {
            var $row = $(this);
            var field = {
                type: $row.find('.ffc-field-type').val(),
                label: $row.find('.ffc-field-label').val(),
                name: $row.find('.ffc-field-name').val(),
                required: $row.find('.ffc-field-required').is(':checked'),
                options: $row.find('.ffc-field-options').val()
            };
            fields.push(field);
        });
        
        // Find the JSON field (try different possible IDs/names)
        var $jsonField = $('#ffc-form-fields-json');
        if (!$jsonField.length) {
            $jsonField = $('input[name="ffc_form_fields"], textarea[name="ffc_form_fields"]');
        }
        
        if ($jsonField.length) {
            $jsonField.val(JSON.stringify(fields));
            console.log('[FFC] Fields JSON updated:', fields.length, 'fields');
        } else {
            console.warn('[FFC] JSON field not found');
        }
    }

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
        
        // Criar modal de seleção
        var modalHtml = '<div id="ffc-template-modal" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:999999;display:flex;align-items:center;justify-content:center;">';
        modalHtml += '<div style="background:#fff;padding:30px;border-radius:8px;max-width:500px;width:90%;box-shadow:0 4px 20px rgba(0,0,0,0.3);">';
        modalHtml += '<h2 style="margin:0 0 20px 0;font-size:20px;">Select a Template</h2>';
        modalHtml += '<div style="max-height:400px;overflow-y:auto;">';
        
        templates.forEach(function(template) {
            modalHtml += '<div class="ffc-template-option" data-file="' + template.value + '" style="padding:15px;margin:10px 0;border:2px solid #ddd;border-radius:4px;cursor:pointer;transition:all 0.2s;">';
            modalHtml += '<strong style="font-size:16px;">' + template.label + '</strong>';
            modalHtml += '<div style="color:#666;font-size:13px;margin-top:5px;">' + template.value + '</div>';
            modalHtml += '</div>';
        });
        
        modalHtml += '</div>';
        modalHtml += '<div style="margin-top:20px;text-align:right;">';
        modalHtml += '<button id="ffc-modal-cancel" class="button" style="margin-right:10px;">Cancel</button>';
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
            
            if (!confirm('Load "' + templateName + '"? This will replace your current certificate HTML.')) {
                return;
            }
            
            loadTemplateFile(templateFile, templateName);
        });
    });
    
    // Function to load template file via fetch
    function loadTemplateFile(filename, displayName) {
        console.log('[FFC] Loading template:', filename);
        
        var templateUrl = '/wp-content/plugins/wp-ffcertificate/html/' + filename;
        
        // Show loading notification
        showNotification('Loading template...', 'info', 0);
        
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
                    showNotification('✓ Template "' + (displayName || filename) + '" loaded successfully!', 'success', 3000);
                    console.log('[FFC] ✓ Template loaded into #ffc_pdf_layout');
                } else {
                    showNotification('✗ HTML field not found.', 'error');
                    console.error('[FFC] ✗ HTML field #ffc_pdf_layout not found');
                }
            })
            .catch(function(error) {
                console.error('[FFC] ✗ Fetch error:', error);
                
                var errorMsg = '';
                if (error.message.includes('404')) {
                    errorMsg = 'Template file not found. Check if file exists in html/ folder.';
                } else if (error.message.includes('403')) {
                    errorMsg = 'Access denied. Check file permissions.';
                } else if (error.message.includes('Failed to fetch')) {
                    errorMsg = 'Network error. Check your connection.';
                } else {
                    errorMsg = 'Error loading template: ' + error.message;
                }
                
                showNotification('✗ ' + errorMsg, 'error', 8000);
            });
    }

    // Import HTML file
    $(document).on('click', '#ffc_btn_import_html', function(e) {
        e.preventDefault();
        console.log('[FFC] Import HTML clicked');
        
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
                
                if (file.type !== 'text/html' && !file.name.endsWith('.html')) {
                    showNotification('Please select an HTML file.', 'warning');
                    return;
                }
                
                var reader = new FileReader();
                reader.onload = function(e) {
                    // ✅ Use the CORRECT ID: #ffc_pdf_layout
                    var $htmlField = $('#ffc_pdf_layout');
                    
                    if ($htmlField.length) {
                        $htmlField.val(e.target.result);
                        showNotification('HTML imported successfully!', 'success');
                        console.log('[FFC] HTML file imported to #ffc_pdf_layout');
                    } else {
                        showNotification('Error: HTML field not found.', 'error');
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
        
        var reader = new FileReader();
        reader.onload = function(evt) {
            // ✅ Use the CORRECT ID: #ffc_pdf_layout
            var $htmlField = $('#ffc_pdf_layout');
            
            if ($htmlField.length) {
                $htmlField.val(evt.target.result);
                console.log('[FFC] ✓ HTML imported to #ffc_pdf_layout');
                showNotification('HTML imported successfully!', 'success');
            } else {
                console.error('[FFC] ✗ Field #ffc_pdf_layout not found');
                showNotification('Error: HTML textarea not found', 'error');
            }
        };
        reader.onerror = function() {
            console.error('[FFC] Error reading file');
            showNotification('Error reading file', 'error');
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
        
        // Check if wp.media is available
        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
            showNotification('WordPress Media Library is not available. Please reload the page.', 'error');
            console.error('[FFC] wp.media is not defined');
            return;
        }
        
        // If the uploader object has already been created, reopen the dialog
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }
        
        // Create the media uploader
        mediaUploader = wp.media({
            title: 'Choose Background Image',
            button: {
                text: 'Use this image'
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
            
            showNotification('Background image selected!', 'success');
            console.log('[FFC] Background image selected:', attachment.url);
        });
        
        mediaUploader.open();
    });

    // ==========================================================================
    // GENERATE TICKETS
    // ==========================================================================
    
    $(document).on('click', '#ffc_btn_generate_codes', function(e) {
        e.preventDefault();
        console.log('[FFC] Generate Tickets clicked');
        
        // ✅ LER do campo HTML ao invés de prompt
        var quantity = $('#ffc_qty_codes').val();
        
        if (!quantity || isNaN(quantity) || quantity < 1) {
            // ✅ Mostrar erro inline ao invés de alert
            $('#ffc_gen_status').text('Please enter a valid number.').css('color', 'red');
            $('#ffc_qty_codes').focus();
            return;
        }
        
        var $btn = $(this);
        var $status = $('#ffc_gen_status');
        var originalText = $btn.text();
        
        $btn.prop('disabled', true).text('Generating...');
        $status.text('Generating tickets...').css('color', '#999');
        
        // Use nonce from ffc_ajax (localized by class-ffc-admin.php)
        var nonce = (typeof ffc_ajax !== 'undefined') ? ffc_ajax.nonce : '';
        
        console.log('[FFC] Using nonce for tickets:', nonce ? nonce.substring(0, 10) + '...' : 'NOT FOUND');
        
        // AJAX to generate tickets - use ORIGINAL handler
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ffc_generate_codes',
                qty: quantity,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    // Use the correct field ID: #ffc_generated_list
                    var $codesField = $('#ffc_generated_list');
                    
                    if ($codesField.length) {
                        var currentCodes = $codesField.val();
                        var newCodes = currentCodes ? currentCodes + '\n' + response.data.codes : response.data.codes;
                        $codesField.val(newCodes);
                        console.log('[FFC] Codes added to field: ffc_generated_list');
                        
                        // ✅ Mensagem inline ao invés de alert
                        $status.text('✓ ' + quantity + ' tickets generated successfully!').css('color', 'green');
                        
                        // Limpar mensagem após 5 segundos
                        setTimeout(function() {
                            $status.text('');
                        }, 5000);
                    } else {
                        console.warn('[FFC] Generated codes field not found');
                        $status.text('✗ Error: codes field not found').css('color', 'red');
                    }
                } else {
                    $status.text('✗ Error: ' + (response.data || 'Unknown error')).css('color', 'red');
                }
                
                $btn.prop('disabled', false).text(originalText);
            },
            error: function(xhr) {
                console.error('[FFC] AJAX error:', xhr.status, xhr.statusText, xhr.responseText);
                
                var errorMsg = '✗ ';
                if (xhr.status === 403) {
                    errorMsg += 'Permission denied. Please reload the page.';
                } else if (xhr.status === 400) {
                    errorMsg += 'Bad request. Check console.';
                } else {
                    errorMsg += 'Server error (Status: ' + xhr.status + ')';
                }
                
                $status.text(errorMsg).css('color', 'red');
                $btn.prop('disabled', false).text(originalText);
            }
        });
    });

    // ==========================================================================
    // PDF DOWNLOAD FROM SUBMISSIONS LIST
    // ==========================================================================
    
    /**
     * Download PDF from submissions list
     * Uses same logic as frontend reprint (centralized filename generation)
     */
    $(document).on('click', '.ffc-admin-download-pdf', function(e) {
        e.preventDefault();
        console.log('[FFC Admin] PDF download button clicked');
        
        var $btn = $(this);
        var submissionId = $btn.data('submission-id');
        
        if (!submissionId) {
            console.error('[FFC Admin] No submission ID');
            alert('Error: No submission ID found');
            return;
        }
        
        console.log('[FFC Admin] Fetching PDF data for submission:', submissionId);
        
        var originalText = $btn.text();
        $btn.prop('disabled', true).text('Loading...');
        
        // Fetch PDF data via AJAX
        $.ajax({
            url: ffc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ffc_admin_get_pdf_data',  // ✅ CORRETO
                submission_id: submissionId,
                nonce: ffc_ajax.nonce
            },
            success: function(response) {
                console.log('[FFC Admin] PDF data response:', response);
                
                if (response.success && response.data) {
                    var pdfData = response.data;
                    
                    // ✅ v2.9.15: Use filename from PHP (includes form name + auth code)
                    var filename = pdfData.filename || 'certificate.pdf';
                    
                    console.log('[FFC Admin] Generating PDF:', filename);
                    
                    // ✅ Use shared PDF generator module
                    if (typeof window.ffcGeneratePDF === 'function') {
                        window.ffcGeneratePDF(pdfData, filename);
                        $btn.prop('disabled', false).text(originalText);
                    } else {
                        console.error('[FFC Admin] PDF generator not loaded');
                        alert(ffc_ajax.strings.pdfLibrariesFailed || 'PDF generation not available');
                        $btn.prop('disabled', false).text(originalText);
                    }
                } else {
                    var errorMsg = response.data && response.data.message 
                        ? response.data.message 
                        : 'Error loading PDF data';
                    console.error('[FFC Admin] Error:', errorMsg);
                    alert(errorMsg);
                    $btn.prop('disabled', false).text(originalText);
                }
            },
            error: function(xhr, status, error) {
                console.error('[FFC Admin] AJAX error:', xhr.status, xhr.statusText);
                alert('Error: ' + (xhr.status === 0 ? 'Network error' : 'Server error (' + xhr.status + ')'));
                $btn.prop('disabled', false).text(originalText);
            }
        });
    });

    // ==========================================================================
    // INITIALIZE ON DOCUMENT READY
    // ==========================================================================
    
    $(document).ready(function() {
        console.log('[FFC Admin] Document ready');
        
        // Initialize form builder if on edit page
        if ($('#ffc-fields-container').length) {
            initFormBuilder();
        }
        
        console.log('[FFC Admin] Initialization complete');
    });

    /**
     * Migration Manager Dropdown Controller
     * v2.1.0
     * 
     * Controla abertura/fechamento do dropdown de migrações
     */

    jQuery(document).ready(function($) {
        
        // Criar overlay se não existir
        if (!$('#ffc-migrations-overlay').length) {
            $('body').append('<div id="ffc-migrations-overlay" class="ffc-migrations-overlay"></div>');
        }
        
        var $btn = $('#ffc-migrations-btn');
        var $menu = $('#ffc-migrations-menu');
        var $overlay = $('#ffc-migrations-overlay');
        
        if (!$btn.length || !$menu.length) {
            return; // Elementos não encontrados
        }
        
        /**
         * Abrir menu
         */
        function openMenu() {
            // Fechar outros dropdowns do WordPress
            $('.ffc-migrations-menu').not($menu).removeClass('ffc-visible');
            
            // Mostrar overlay
            $overlay.addClass('ffc-visible');
            
            // Mostrar menu
            $menu.addClass('ffc-visible');
            
            // Debug
            console.log('Migration menu opened');
        }
        
        /**
         * Fechar menu
         */
        function closeMenu() {
            $menu.removeClass('ffc-visible');
            $overlay.removeClass('ffc-visible');
            
            // Debug
            console.log('Migration menu closed');
        }
        
        /**
         * Toggle menu
         */
        function toggleMenu(e) {
            e.preventDefault();
            e.stopPropagation();
            
            if ($menu.hasClass('ffc-visible')) {
                closeMenu();
            } else {
                openMenu();
            }
        }
        
        // Click no botão
        $btn.on('click', toggleMenu);
        
        // Click no overlay fecha menu
        $overlay.on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            closeMenu();
        });
        
        // Click fora do menu fecha (fallback)
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.ffc-migrations-dropdown').length) {
                closeMenu();
            }
        });
        
        // ESC fecha menu
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                if ($menu.hasClass('ffc-visible')) {
                    closeMenu();
                }
            }
        });
        
        // Prevenir que clicks dentro do menu o fechem
        $menu.on('click', function(e) {
            e.stopPropagation();
        });
        
        // Debug: log quando carregado
        console.log('FFC Migration Dropdown initialized', {
            button: $btn.length,
            menu: $menu.length,
            overlay: $overlay.length
        });
        
        // ✅ v2.10.0: Show/hide restriction fields based on checkboxes
        function toggleRestrictionField(checkbox, fieldId) {
            if ($(checkbox).is(':checked')) {
                $(fieldId).slideDown();
            } else {
                $(fieldId).slideUp();
            }
        }
        
        // Password field
        $('#ffc_restriction_password').on('change', function() {
            toggleRestrictionField(this, '#ffc_password_field');
        }).trigger('change');
        
        // Allowlist field
        $('#ffc_restriction_allowlist').on('change', function() {
            toggleRestrictionField(this, '#ffc_allowlist_field');
        }).trigger('change');
        
        // Denylist field
        $('#ffc_restriction_denylist').on('change', function() {
            toggleRestrictionField(this, '#ffc_denylist_field');
        }).trigger('change');
        
        // Ticket field
        $('#ffc_restriction_ticket').on('change', function() {
            toggleRestrictionField(this, '#ffc_ticket_field');
        }).trigger('change');
        
        console.log('[FFC Admin] Restriction field toggles initialized');
    });

})(jQuery);