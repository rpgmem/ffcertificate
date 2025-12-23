jQuery(document).ready(function($) {

    // =========================================================================
    // 1. UPLOAD LOCAL FILE (FileReader)
    // =========================================================================
    $('#ffc_btn_import_html').on('click', function(e) {
        e.preventDefault();
        $('#ffc_import_html_file').trigger('click');
    });

    $('#ffc_import_html_file').on('change', function(e) {
        var file = e.target.files[0];
        if (!file) return;

        var reader = new FileReader();
        reader.onload = function(e) {
            $('#ffc_pdf_layout').val(e.target.result);
            $('#ffc_import_html_file').val('');
            if (window.ffc_admin_ajax && ffc_admin_ajax.strings.fileImported) {
                alert(ffc_admin_ajax.strings.fileImported);
            }
        };
        reader.onerror = function() { 
            alert(ffc_admin_ajax.strings.errorReadingFile || 'Error reading file'); 
        };
        reader.readAsText(file);
    });

    // =========================================================================
    // 2. LOAD SERVER TEMPLATE (AJAX)
    // =========================================================================
    $('#ffc_load_template_btn').on('click', function(e) {
        e.preventDefault();
        var filename = $('#ffc_template_select').val();
        var $btn = $(this);

        if (!filename) {
            alert(ffc_admin_ajax.strings.selectTemplate || 'Please select a template');
            return;
        }

        if (!confirm(ffc_admin_ajax.strings.confirmReplaceContent || 'This will replace current content. Continue?')) {
            return;
        }

        $btn.prop('disabled', true).text(ffc_admin_ajax.strings.loading || 'Loading...');

        $.ajax({
            url: ffc_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ffc_load_template',
                filename: filename,
                nonce: ffc_admin_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#ffc_pdf_layout').val(response.data);
                    alert(ffc_admin_ajax.strings.templateLoaded || 'Template loaded!');
                } else {
                    alert((ffc_admin_ajax.strings.error || 'Error: ') + response.data);
                }
            },
            error: function() {
                alert(ffc_admin_ajax.strings.connectionError || 'Connection error');
            },
            complete: function() {
                $btn.prop('disabled', false).text(ffc_admin_ajax.strings.loadTemplate || 'Load Template');
            }
        });
    });

    // =========================================================================
    // 3. MEDIA LIBRARY (BACKGROUND IMAGE)
    // =========================================================================
    var mediaUploader;
    $('#ffc_btn_media_lib').on('click', function(e) {
        e.preventDefault();
        if (mediaUploader) { mediaUploader.open(); return; }
        
        mediaUploader = wp.media({
            title: ffc_admin_ajax.strings.selectBackgroundImage || 'Select Background Image',
            button: { text: ffc_admin_ajax.strings.useImage || 'Use this image' },
            multiple: false
        });
        
        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            $('#ffc_bg_image_input').val(attachment.url);
        });
        
        mediaUploader.open();
    });

    // =========================================================================
    // 4. GENERATE RANDOM CODES (TICKETS)
    // =========================================================================
    $('#ffc_btn_generate_codes').on('click', function(e) {
        e.preventDefault();
        var qty = $('#ffc_qty_codes').val();
        var $btn = $(this);
        var $textarea = $('#ffc_generated_list');
        var $status = $('#ffc_gen_status');
        
        if(qty < 1) return;

        $btn.prop('disabled', true);
        $status.text(ffc_admin_ajax.strings.generating || 'Generating...');
        
        $.ajax({
            url: ffc_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ffc_generate_codes',
                qty: qty,
                nonce: ffc_admin_ajax.nonce
            },
            success: function(response) {
                if(response.success) {
                    var currentVal = $textarea.val();
                    var sep = (currentVal.length > 0 && !currentVal.endsWith('\n')) ? "\n" : "";
                    $textarea.val(currentVal + sep + response.data.codes);
                    $status.text(qty + ' ' + (ffc_admin_ajax.strings.codesGenerated || 'codes generated'));
                } else {
                    $status.text(ffc_admin_ajax.strings.errorGeneratingCodes || 'Error generating codes');
                }
            },
            complete: function() {
                $btn.prop('disabled', false);
            },
            error: function() {
                $status.text(ffc_admin_ajax.strings.connectionError || 'Connection error');
                $btn.prop('disabled', false);
            }
        });
    });

    // =========================================================================
    // 5. ADMIN DOWNLOAD LOGIC (Fixed for ffc-admin-pdf-btn)
    // =========================================================================
    $(document).on('click', '.ffc-admin-pdf-btn', function(e) { 
        e.preventDefault();
        
        const $btn = $(this);
        const subId = $btn.data('id');

        if ($btn.hasClass('ffc-btn-loading')) return;

        console.log("Click detected! Starting PDF for submission ID:", subId);

        $btn.addClass('ffc-btn-loading');

        $.ajax({
            url: ffc_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ffc_admin_get_pdf_data',
                submission_id: subId,
                nonce: ffc_admin_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    console.log("Data received, generating certificate...");
                    if (typeof window.generateCertificate === 'function') {
                        window.generateCertificate(response.data);
                    }
                } else {
                    alert((ffc_admin_ajax.strings.error || "Error: ") + (response.data ? response.data.message : 'Failed to retrieve data.'));
                    $btn.removeClass('ffc-btn-loading');
                }
            },
            error: function() {
                alert(ffc_admin_ajax.strings.connectionError || 'Connection error');
                $btn.removeClass('ffc-btn-loading');
            }
        });
    });

    // Remove loading state when finished
    $(document).on('ffc_pdf_done', function() {
        $('.ffc-admin-pdf-btn').removeClass('ffc-btn-loading');
    });

    // Remove loading state when PDF generation ends
    $(document).on('ffc_pdf_done', function() {
        $('.ffc-admin-download-btn').removeClass('ffc-btn-loading');
    });

    // =========================================================================
    // 6. FORM BUILDER
    // =========================================================================
    
    /**
     * Reindexes field names for correct PHP processing.
     */
    function ffc_reindex_fields() {
        $('#ffc-fields-container').children('.ffc-field-row').each(function(index) {
            $(this).find('input, select, textarea').each(function() {
                const name = $(this).attr('name');
                if (name) {
                    // Robust regex to swap index: ffc_fields[X][label] -> ffc_fields[index][label]
                    const newName = name.replace(/ffc_fields\[[^\]]*\]/, 'ffc_fields[' + index + ']');
                    $(this).attr('name', newName);
                }
            });
        });
    }

    // Initialize Sortable (Drag and Drop)
    if ($.fn.sortable) {
        $('#ffc-fields-container').sortable({
            handle: '.ffc-sort-handle',
            placeholder: 'ui-state-highlight',
            update: function() { ffc_reindex_fields(); }
        });
    }

    // ADD NEW FIELD 
    $('.ffc-add-field').on('click', function(e) {
        e.preventDefault();
        
        // Get HTML from the template div
        var templateHtml = $('.ffc-field-template').html();
        var $container = $('#ffc-fields-container');
        
        // Create jQuery element
        var $newRow = $(templateHtml);
        
        // Remove control classes and ensure visibility
        $newRow.removeClass('ffc-field-template ffc-hidden').show();
        
        // Reset internal fields
        $newRow.find('input, select, textarea').val('');
        $newRow.find('.ffc-field-type-selector').val('text');
        
        // Append to container
        $container.append($newRow);
        
        // Reindex for correct saving
        ffc_reindex_fields();
    });

    // REMOVE FIELD (Using delegation for new fields)
    $(document).on('click', '.ffc-remove-field', function(e) { 
        e.preventDefault();
        if (confirm(ffc_admin_ajax.strings.confirmDeleteField || 'Remove this field?')) {
            $(this).closest('.ffc-field-row').remove();
            ffc_reindex_fields(); 
        }
    });
    
    // SHOW/HIDE OPTIONS LOGIC (Delegation + Correct Selector)
    $(document).on('change', '.ffc-field-type-selector', function() {
        const selectedType = $(this).val();
        const $row = $(this).closest('.ffc-field-row');
        const $optionsContainer = $row.find('.ffc-options-field'); 
        
        if (selectedType === 'select' || selectedType === 'radio') {
            $optionsContainer.stop(true, true).fadeIn(200).removeClass('ffc-hidden');
        } else {
            $optionsContainer.hide().addClass('ffc-hidden');
        }
    });

    // Initialization: Apply visibility to fields already loaded from DB
    $('.ffc-field-type-selector').each(function() {
        $(this).trigger('change');
    });

    ffc_reindex_fields(); 
});