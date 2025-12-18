jQuery(document).ready(function($) {

    // =========================================================================
    // 0. UI INTERACTIONS & TABS
    // =========================================================================
    
    // Sistema de Abas (Sincronizado com o HTML do class-ffc-cpt.php)
    $('.ffc-tabs-nav li').on('click', function() {
        var tab_id = $(this).attr('data-tab');

        // Remove classe ativa de todas as abas
        $('.ffc-tabs-nav li').removeClass('nav-tab-active');
        
        // Adiciona classe ativa na clicada
        $(this).addClass('nav-tab-active');

        // Esconde todos os conteúdos e mostra o alvo
        $('.ffc-metabox-tab-content').hide();
        $("#" + tab_id).show();
    });

    // =========================================================================
    // 1. UPLOAD LOCAL FILE (FileReader) - Opcional se você tiver o botão no HTML
    // =========================================================================
    if ($('#ffc_btn_import_html').length > 0) {
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
                alert(ffc_admin_ajax.strings.fileImported || 'File Imported!');
            };
            reader.onerror = function() { alert('Error reading file'); };
            reader.readAsText(file);
        });
    }

    // =========================================================================
    // 2. MEDIA LIBRARY (BACKGROUND IMAGE)
    // =========================================================================
    var mediaUploader;
    
    // Botão genérico para upload (caso usemos classe) ou input específico
    $('#ffc_background_image').on('click', function(e) {
        // Opcional: permitir clicar no input para abrir a mídia, ou criar um botão ao lado
        // Por enquanto, vamos assumir que existe um botão ou o usuário clica no input se quiser
    });

    // Adicione um botão "Select Image" no PHP se quiser, ou use este seletor genérico
    // Para simplificar, vamos criar um comportamento: Double Click no input abre a mídia
    $('#ffc_background_image').on('dblclick', function(e){
        e.preventDefault();
        open_media_uploader($(this));
    });

    function open_media_uploader($targetInput) {
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }
        
        mediaUploader = wp.media.frames.file_frame = wp.media({
            title: ffc_admin_ajax.strings.selectBackgroundImage || 'Select Background Image',
            button: { text: ffc_admin_ajax.strings.useImage || 'Use Image' },
            library: { type: 'image' }, 
            multiple: false
        });
        
        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            $targetInput.val(attachment.url);
        });
        
        mediaUploader.open();
    }

    // =========================================================================
    // 3. GENERATE RANDOM CODES (TICKETS)
    // =========================================================================
    // Nota: Precisa adicionar este botão no PHP se quiser usar essa funcionalidade
    // Atualmente o PHP tem apenas a textarea. Se adicionar um botão com id 'ffc_generate_tickets':
    $('#ffc_generate_tickets').on('click', function(e) {
        e.preventDefault();
        
        var qty = 50; 
        var $btn = $(this);
        var $textarea = $('#ffc_generated_codes_list'); // ID corrigido conforme PHP
        
        $btn.prop('disabled', true).text('Generating...');
        
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
                } else {
                    alert('Error generating codes');
                }
            },
            complete: function() {
                $btn.prop('disabled', false).text('Generate 50 Tickets');
            }
        });
    });

    // =========================================================================
    // 4. FORM BUILDER (Repeater, Sortable, Reindex)
    // =========================================================================
    
    // Reindexa os inputs (name="ffc_fields[0][...]", ffc_fields[1][...])
    function ffc_reindex_fields() {
        $('#ffc-fields-container').children('.ffc-field-row').each(function(index) {
            $(this).find('input, select, textarea').each(function() {
                const name = $(this).attr('name');
                if (name) {
                    // Substitui o índice dentro do primeiro colchete
                    const newName = name.replace(/ffc_fields\[\d+|{{index}}\]/, 'ffc_fields[' + index + ']');
                    $(this).attr('name', newName);
                }
            });
        });
    }

    // Inicializa Sortable (Drag & Drop)
    if ($.fn.sortable) {
        $('#ffc-fields-container').sortable({
            handle: '.ffc-sort-handle',
            placeholder: 'ffc-sortable-placeholder',
            axis: 'y',
            opacity: 0.7,
            update: function(event, ui) { 
                ffc_reindex_fields(); 
            }
        });
    }

    // Adicionar Novo Campo
    $('.ffc-add-field').on('click', function(e) {
        e.preventDefault();
        
        // 1. Clona o template oculto (definido no final da metabox PHP)
        // Nota: O seletor deve pegar especificamente a row template
        const $template = $('.ffc-field-template');
        const $newRow = $template.clone();
        
        // 2. Remove classes de template e mostra
        $newRow.removeClass('ffc-field-template').css('display', 'flex'); // flex pois o CSS usa flexbox
        
        // 3. Limpa valores
        $newRow.find('input:not([type="checkbox"]), select, textarea').val(''); 
        $newRow.find('input[type="checkbox"]').prop('checked', false);
        $newRow.find('.ffc-field-type-select').val('text'); 
        $newRow.find('.ffc-options-field').hide();
        
        // 4. Insere na lista
        $('#ffc-fields-container').append($newRow);
        
        // 5. Reindexa
        ffc_reindex_fields(); 
    });

    // Remover Campo
    $('#ffc-fields-container').on('click', '.ffc-remove-field', function(e) { 
        e.preventDefault();
        if (confirm(ffc_admin_ajax.strings.confirmDeleteField || 'Remove field?')) {
            $(this).closest('.ffc-field-row').fadeOut(300, function(){
                $(this).remove();
                ffc_reindex_fields(); 
            });
        }
    });
    
    // Mudança de Tipo de Campo (Mostrar/Esconder Opções)
    $('#ffc-fields-container').on('change', '.ffc-field-type-select', function() {
        const selectedType = $(this).val();
        const $row = $(this).closest('.ffc-field-row');
        const $optionsContainer = $row.find('.ffc-options-field'); 
        
        if (selectedType === 'select' || selectedType === 'radio') {
            $optionsContainer.show(); // Compact row, usa show/hide ou display flex
        } else {
            $optionsContainer.hide();
        }
    });

    // --- INICIALIZAÇÃO ---
    // Garante índices corretos
    ffc_reindex_fields(); 
    
    // Dispara verificação visual dos selects já salvos
    $('.ffc-field-type-select').trigger('change');

});