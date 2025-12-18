jQuery(document).ready(function($) {

    // =========================================================================
    // 1. PDF DOWNLOAD NO ADMIN (Lista de Submissões)
    // =========================================================================
    $(document).on('click', '.ffc-admin-pdf-btn', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var id = $btn.data('id');
        var originalText = $btn.text();
        
        if(typeof ffc_admin_ajax === 'undefined') {
            alert('Erro crítico: Variáveis AJAX de Admin não carregadas.');
            return;
        }

        $btn.text('⏳').prop('disabled', true);

        $.ajax({
            url: ffc_admin_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ffc_admin_get_pdf_data',
                submission_id: id,
                nonce: ffc_admin_ajax.nonce
            },
            success: function(response) {
                if(response.success) {
                    if (typeof generateCertificate === 'function') {
                        setTimeout(function() {
                            generateCertificate(response.data);
                        }, 100);
                    } else {
                        alert('Erro: Biblioteca de geração de PDF (frontend.js) não foi carregada.');
                    }
                } else {
                    alert('Erro ao buscar dados: ' + (response.data || 'Falha desconhecida'));
                }
            },
            error: function(xhr, status, error) {
                console.error(error);
                alert('Erro de conexão.');
            },
            complete: function() {
                setTimeout(function(){
                    $btn.text(originalText).prop('disabled', false);
                }, 2000);
            }
        });
    });

    // =========================================================================
    // 2. LÓGICA DE TABS (Metaboxes)
    // =========================================================================
    $('.ffc-metabox-tabs .ffc-tabs-nav li').on('click', function() {
        const tabId = $(this).data('tab');
        $('.ffc-metabox-tabs .ffc-tabs-nav li').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.ffc-metabox-tab-content').hide();
        $('#' + tabId).show();
    });

    // =========================================================================
    // 3. LÓGICA DO FORM BUILDER
    // =========================================================================
    function ffc_reindex_fields() {
        $('#ffc-fields-container').children('.ffc-field-row:not(.ffc-field-template)').each(function(index) {
            $(this).find('input, select, textarea').each(function() {
                const name = $(this).attr('name');
                if (name) {
                    const newName = name.replace(/ffc_fields\[.*?\]/, 'ffc_fields[' + index + ']');
                    $(this).attr('name', newName);
                }
            });
        });
    }

    if ($.fn.sortable) {
        $('#ffc-fields-container').sortable({
            handle: '.ffc-sort-handle',
            update: function(event, ui) { ffc_reindex_fields(); }
        });
    }

    $('.ffc-add-field').on('click', function(e) {
        e.preventDefault();
        const $template = $('.ffc-field-template');
        const $newRow = $template.clone();
        $newRow.removeClass('ffc-field-template').show();
        $newRow.find('input, select').val(''); 
        $newRow.find('.ffc-field-type-select').val('text');
        $newRow.find('input[type="checkbox"]').prop('checked', false);
        $newRow.find('.ffc-options-field').hide();
        $('#ffc-fields-container').append($newRow);
        ffc_reindex_fields(); 
    });

    $('#ffc-fields-container').on('click', '.ffc-remove-field', function(e) { 
        e.preventDefault();
        if (confirm('Are you sure?')) {
            $(this).closest('.ffc-field-row').remove();
            ffc_reindex_fields(); 
        }
    });
    
    $('#ffc-fields-container').on('change', '.ffc-field-type-select', function() {
        const selectedType = $(this).val();
        const $optionsContainer = $(this).closest('.ffc-field-row').find('.ffc-options-field'); 
        if (selectedType === 'select' || selectedType === 'radio') {
            $optionsContainer.show();
        } else {
            $optionsContainer.hide();
        }
    });

    // Inicialização do Builder
    ffc_reindex_fields(); 
    $('.ffc-field-type-select').trigger('change');

    // =========================================================================
    // 4. IMPORTAR TEMPLATE HTML (AJAX)
    // =========================================================================
    $('#ffc_load_template_btn').on('click', function(e) {
        e.preventDefault();
        
        const filename = $('#ffc_template_select').val();
        if (!filename) {
            alert('Por favor, selecione um arquivo de template.');
            return;
        }

        if (!confirm('Tem certeza? Isso substituirá todo o conteúdo atual do editor HTML.')) {
            return;
        }

        const $btn = $(this);
        $btn.prop('disabled', true).text('Carregando...');

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
                    alert('Template carregado com sucesso!');
                } else {
                    alert('Erro: ' + response.data);
                }
            },
            error: function() {
                alert('Erro de conexão.');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Carregar HTML');
            }
        });
    });

    // =========================================================================
    // 5. GERADOR DE CÓDIGOS (Auth Code Generator)
    // =========================================================================
    $('#ffc_btn_generate_codes').on('click', function(e) {
        e.preventDefault();
        
        var qty = $('#ffc_gen_qty').val();
        var $btn = $(this);
        var $textarea = $('#ffc_allowed_list');
        
        $btn.prop('disabled', true).text('Gerando...');
        
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
                    if(currentVal.length > 0) currentVal += "\n";
                    $textarea.val(currentVal + response.data);
                } else {
                    alert('Erro ao gerar.');
                }
            },
            complete: function() {
                $btn.prop('disabled', false).text('Gerar Códigos');
            }
        });
    });

});