// =========================================================================
// 1. FUNÇÃO GLOBAL DE GERAÇÃO DE PDF
// =========================================================================
window.generateCertificate = function(pdfData) {
    if (typeof html2canvas === 'undefined' || typeof window.jspdf === 'undefined') {
        alert(ffc_ajax.strings.pdfLibrariesFailed); return;
    }

    // Configuração A4 Paisagem (Pixels)
    const A4_WIDTH_PX = 1123; 
    const A4_HEIGHT_PX = 794;

    // Função interna para processar o texto do HTML
    function replacePlaceholders(template, data) {
        let finalHtml = template;
        const submission = data.submission || {};
        
        console.log("=== PROCESSING PDF DATA ===", submission);

        for (const key in submission) {
            if (submission.hasOwnProperty(key)) {
                let originalVal = String(submission[key]).trim();
                let safeVal = originalVal;
                let lowerKey = key.toLowerCase().trim();

                // --- A. AUTHENTICATION CODE FORMATTING ---
                // Format: AAAA-BBBB-CCCC
                if (['auth_code', 'codigo_autenticidade', 'codigo', 'code'].includes(lowerKey)) {
                    let clean = safeVal.replace(/[^A-Z0-9]/gi, '').toUpperCase();
                    if (clean.length > 0) {
                        let parts = clean.match(/.{1,4}/g); 
                        if (parts) {
                            safeVal = parts.join('-'); 
                        }
                    }
                }

                // --- B. VISUAL FORMATTING CPF / RF (7 or 11 Digits) ---
                // Looks for common document keys
                if (['cpf_rf', 'cpf', 'rf', 'documento', 'doc', 'id'].includes(lowerKey)) {
                    let nums = safeVal.replace(/\D/g, ''); // Remove non-numbers
                    
                    if (nums.length === 11) {
                        // CPF: 000.000.000-00
                        safeVal = nums.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');
                        console.log(`> Applying CPF mask on [${key}]: ${originalVal} -> ${safeVal}`);
                    } 
                    else if (nums.length === 7) {
                        // RF: 000.000-0
                        safeVal = nums.replace(/^(\d{3})(\d{3})(\d{1})$/, '$1.$2-$3');
                        console.log(`> Applying RF mask on [${key}]: ${originalVal} -> ${safeVal}`);
                    }
                }

                // --- C. HTML SANITIZATION (Security) ---
                safeVal = safeVal
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
                
                // REPLACEMENT: Looks for {{variable}} ignoring case and spaces
                const regex = new RegExp('{{\\s*' + key + '\\s*}}', 'gi');
                finalHtml = finalHtml.replace(regex, safeVal);
                
                // EXTRA FIX: If template has typo {{cfp_rf}} instead of {{cpf_rf}}
                if (lowerKey === 'cpf_rf') {
                     finalHtml = finalHtml.replace(/{{\s*cfp_rf\s*}}/gi, safeVal);
                }
            }
        }
        
        // Tags Globais do Sistema
        const dateNow = new Date().toLocaleDateString('pt-BR');
        finalHtml = finalHtml.replace(/{{\s*form_title\s*}}/gi, data.form_title || 'Certificate');
        finalHtml = finalHtml.replace(/{{\s*current_date\s*}}/gi, dateNow);
        
        return finalHtml;
    }

    // 1. Cria Container Temporário fora da tela
    const $tempContainer = jQuery('<div>').css({
        position: 'absolute', top: '-9999px', left: '-9999px',
        width: A4_WIDTH_PX + 'px', height: A4_HEIGHT_PX + 'px',
        overflow: 'hidden', background: '#fff'
    }).appendTo('body');

    // 2. Insere o HTML processado
    const htmlContent = replacePlaceholders(pdfData.template, pdfData);
    $tempContainer.html(htmlContent);

    // 3. Aplica Imagem de Fundo (se houver)
    if (pdfData.bg_image) {
        $tempContainer.prepend('<div style="position:absolute; top:0; left:0; width:100%; height:100%; background:url('+pdfData.bg_image+') no-repeat center center; background-size:cover; z-index:-1;"></div>');
    }

    // 4. Gera a Imagem e depois o PDF
    const { jsPDF } = window.jspdf;
    
    // Pequeno delay (500ms) para garantir carregamento de elementos visuais
    setTimeout(function() {
        html2canvas($tempContainer[0], { 
            scale: 2, // Melhora qualidade
            useCORS: true, 
            logging: false,
            width: A4_WIDTH_PX, 
            height: A4_HEIGHT_PX
        }).then(canvas => {
            const imgData = canvas.toDataURL('image/jpeg', 0.95); 
            const pdf = new jsPDF({ orientation: 'l', unit: 'px', format: [A4_WIDTH_PX, A4_HEIGHT_PX] });
            
            pdf.addImage(imgData, 'JPEG', 0, 0, A4_WIDTH_PX, A4_HEIGHT_PX);
            
            // Define nome do arquivo
            const cleanTitle = (pdfData.form_title || 'certificate').replace(/[^a-z0-9]/gi, '_').toLowerCase();
            const codeSuffix = (pdfData.submission && pdfData.submission.auth_code) ? pdfData.submission.auth_code : Date.now();
            
            pdf.save(cleanTitle + '_' + codeSuffix + '.pdf');
            
            // Limpeza
            $tempContainer.remove();
            
        }).catch(err => {
            console.error('PDF Error:', err);
            alert(ffc_ajax.strings.pdfGenerationError);
            $tempContainer.remove();
        });
    }, 500);
};

// =========================================================================
// 2. LÓGICA DO FORMULÁRIO (JQUERY)
// =========================================================================
jQuery(function($) {

    // --- A. MÁSCARAS DE INPUT (Digitação) ---
    
    // Auth Code Mask
    $(document).on('input', 'input[name="ffc_auth_code"], input[name="auth_code_check"]', function(e) {
        let input = e.target;
        let value = input.value.toUpperCase().replace(/[^A-Z0-9]/g, ''); 
        
        if (value.length > 12) value = value.substring(0, 12);

        let formatted = '';
        if (value.length > 8) {
            formatted = value.substring(0, 4) + '-' + value.substring(4, 8) + '-' + value.substring(8, 12);
        } else if (value.length > 4) {
            formatted = value.substring(0, 4) + '-' + value.substring(4, 8);
        } else {
            formatted = value;
        }
        input.value = formatted;
    });

    // CPF/RF Visual Mask (apenas visual durante digitação)
    $(document).on('input', 'input[name="cpf_rf"]', function(e) {
        let v = $(this).val().replace(/\D/g, '');
        if (v.length > 11) v = v.slice(0, 11);

        if (v.length <= 7) {
            // Padrão RF
            v = v.replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2-$3');
        } else {
            // Padrão CPF
            v = v.replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/^(\d{3})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3-$4');
        }
        $(this).val(v);
    });

    // --- B. VERIFICAÇÃO DE CERTIFICADO (AJAX) ---
    // .off() previne que o evento seja registrado múltiplas vezes
    $(document).off('submit', '.ffc-verification-form').on('submit', '.ffc-verification-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn  = $form.find('button[type="submit"]');
        var $resultDiv = $form.closest('.ffc-verification-container').find('.ffc-verify-result');
        
        var rawCode = $form.find('input[name="auth_code_check"], input[name="ffc_auth_code"]').val();
        if(!rawCode) { alert(ffc_ajax.strings.enterCode); return; }

        var cleanCode = rawCode.replace(/[^a-zA-Z0-9]/g, ''); 

        $btn.prop('disabled', true).text(ffc_ajax.strings.verifying);
        $resultDiv.html('').hide();

        $.ajax({
            url: (typeof ffc_ajax !== 'undefined') ? ffc_ajax.ajax_url : '/wp-admin/admin-ajax.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ffc_verify_certificate',
                ffc_auth_code: cleanCode,
                nonce: (typeof ffc_ajax !== 'undefined' ? ffc_ajax.nonce : ''),
                ffc_honeypot_trap: $form.find('input[name="ffc_honeypot_trap"]').val(),
                ffc_captcha_ans:   $form.find('input[name="ffc_captcha_ans"]').val(),
                ffc_captcha_hash:  $form.find('input[name="ffc_captcha_hash"]').val()
            },
            success: function(response) {
                $btn.prop('disabled', false).text(ffc_ajax.strings.verify);
                if(response.success) {
                    $resultDiv.html(response.data.html).fadeIn();
                } else {
                    $resultDiv.html('<div class="ffc-verify-error" style="padding:15px; background:#f8d7da; color:#721c24; margin-top:10px;">' + response.data.message + '</div>').fadeIn();
                    
                    if (response.data && response.data.refresh_captcha) {
                        $form.find('label[for="ffc_captcha_ans"]').html(response.data.new_label);
                        $form.find('input[name="ffc_captcha_hash"]').val(response.data.new_hash);
                        $form.find('#ffc_captcha_ans').val('');
                    }
                }
            },
            error: function() {
                $btn.prop('disabled', false).text(ffc_ajax.strings.verify);
                alert(ffc_ajax.strings.connectionError);
            }
        });
    });

    // --- C. SUBMISSÃO E GERAÇÃO (AJAX) ---
    // .off() previne o DOWNLOAD DUPLO
    $(document).off('submit', '.ffc-submission-form').on('submit', '.ffc-submission-form', function(e) {
        e.preventDefault();
        const $form = $(this);
        const $btn  = $form.find('button[type="submit"]');
        const $msg  = $form.find('.ffc-message');
        
        // Validação de Tamanho do CPF (Apenas 7 ou 11)
        const $cpfInput = $form.find('input[name="cpf_rf"]');
        if ($cpfInput.length > 0) {
            const rawVal = $cpfInput.val().replace(/\D/g, '');
            if (rawVal.length !== 7 && rawVal.length !== 11) {
                alert(ffc_ajax.strings.idMustHaveDigits);
                $cpfInput.trigger('focus');
                return false;
            }
        }

        const originalBtnText = $btn.text();
        $btn.prop('disabled', true).text(ffc_ajax.strings.processing);
        $msg.removeClass('ffc-success ffc-error').hide();

        let formData = $form.serializeArray();

        // Remove máscara visual antes de enviar para o banco
        formData.forEach(function(field) {
            if (field.name === 'auth_code' || field.name === 'codigo') {
                field.value = field.value.replace(/[^a-zA-Z0-9]/g, '');
            }
        });

        formData.push({ name: 'action', value: 'ffc_submit_form' });
        formData.push({ name: 'nonce', value: (typeof ffc_ajax !== 'undefined' ? ffc_ajax.nonce : '') });

        $.ajax({
            url: (typeof ffc_ajax !== 'undefined') ? ffc_ajax.ajax_url : '/wp-admin/admin-ajax.php',
            type: 'POST',
            data: formData, dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false).text(originalBtnText);

                if (response.success) {
                    $msg.addClass('ffc-success').html(response.data.message).show();
                    
                    if (response.data.pdf_data) {
                        $msg.append('<div style="color:#666; margin-top:5px;">⏳ ' + ffc_ajax.strings.generatingCertificate + '</div>');
                        
                        setTimeout(function(){ 
                            window.generateCertificate(response.data.pdf_data); 
                        }, 500);
                    }
                    $form[0].reset(); 
                } else {
                    $msg.addClass('ffc-error').html(response.data.message).show();
                    
                    if (response.data && response.data.refresh_captcha) {
                        $form.find('label[for="ffc_captcha_ans"]').html(response.data.new_label);
                        $form.find('input[name="ffc_captcha_hash"]').val(response.data.new_hash);
                        $form.find('#ffc_captcha_ans').val('');
                    }
                }
            },
            error: function() {
                $btn.prop('disabled', false).text(originalBtnText);
                $msg.addClass('ffc-error').html(ffc_ajax.strings.connectionError).show();
            }
        });
    });
});