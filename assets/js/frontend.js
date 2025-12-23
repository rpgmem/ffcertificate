// =========================================================================
// 1. GLOBAL PDF GENERATION FUNCTION
// =========================================================================
window.generateCertificate = function(data) {
    const { template, bg_image, form_title } = data;

    if (typeof html2canvas === 'undefined' || typeof window.jspdf === 'undefined') {
        alert("Error: PDF libraries not loaded.");
        return;
    }

    // 1. Create Overlay (Added pointer-events to block accidental clicks)
    const overlay = document.createElement('div');
    overlay.className = 'ffc-pdf-progress-overlay';
    overlay.style.pointerEvents = 'all'; 
    overlay.innerHTML = `
        <div class="ffc-progress-spinner"></div>
        <div id="ffc-prog-status" style="font-weight:bold;">Starting...</div>
    `;
    document.body.appendChild(overlay);

    const statusTxt = document.getElementById('ffc-prog-status');

    // 2. Prepare the stage (Added crossorigin attribute to prevent 'Tainted Canvas' errors)
    const wrapper = document.createElement('div');
    wrapper.className = 'ffc-pdf-temp-wrapper';
    wrapper.innerHTML = `
        <div class="ffc-pdf-stage" id="ffc-capture-target">
            ${bg_image ? `<img src="${bg_image}" class="ffc-pdf-bg-img" crossorigin="anonymous">` : ''}
            <div class="ffc-pdf-user-content">${template}</div>
        </div>
    `;
    document.body.appendChild(wrapper);

    // THE SECRET FOR MOBILE: 
    // We use a small delay (500ms) for the overlay to appear, 
    // ensuring the browser "breathes" before html2canvas starts processing.
    setTimeout(() => {
        if(statusTxt) statusTxt.innerText = "Processing image...";
        
        const target = document.querySelector('#ffc-capture-target');
        
        html2canvas(target, {
            scale: 2, 
            useCORS: true,
            allowTaint: true, // Backup for cross-domain images
            backgroundColor: "#ffffff",
            width: 1123,
            height: 794,
            logging: false // Disable logs for better performance
        }).then(canvas => {
            if(statusTxt) statusTxt.innerText = "Generating PDF...";
            
            const imgData = canvas.toDataURL('image/png');
            const { jsPDF } = window.jspdf;
            const pdf = new jsPDF({
                orientation: 'landscape',
                unit: 'px',
                format: [1123, 794]
            });

            pdf.addImage(imgData, 'PNG', 0, 0, 1123, 794);
            
            if(statusTxt) statusTxt.innerText = "Download started!";
            pdf.save(`${form_title || 'certificate'}.pdf`);
            
            setTimeout(() => {
                if(document.body.contains(wrapper)) document.body.removeChild(wrapper);
                if(document.body.contains(overlay)) document.body.removeChild(overlay);
                jQuery(document).trigger('ffc_pdf_done');
            }, 1000);

        }).catch(err => {
            console.error("FFC Error:", err);
            if(document.body.contains(overlay)) document.body.removeChild(overlay);
            if(document.body.contains(wrapper)) document.body.removeChild(wrapper);
            alert("Error generating PDF.");
            jQuery(document).trigger('ffc_pdf_done');
        });
    }, 500); 
};

// =========================================================================
// 2. FORM LOGIC (JQUERY)
// =========================================================================
jQuery(function($) {

    // Helper to refresh Captcha
    function refreshCaptcha($form, data) {
        if (data.refresh_captcha) {
            $form.find('label[for="ffc_captcha_ans"]').html(data.new_label);
            $form.find('input[name="ffc_captcha_hash"]').val(data.new_hash);
            $form.find('input[name="ffc_captcha_ans"]').val('');
        }
    }

    // --- A. INPUT MASKS ---
    $(document).on('input', 'input[name="ffc_auth_code"], .ffc-verify-input', function() {
        let v = $(this).val().toUpperCase().replace(/[^A-Z0-9]/g, ''); 
        if (v.length > 12) v = v.substring(0, 12);
        let parts = v.match(/.{1,4}/g);
        $(this).val(parts ? parts.join('-') : v);
    });

    $(document).on('input', 'input[name="cpf_rf"]', function() {
        let v = $(this).val().replace(/\D/g, ''); 
        if (v.length > 11) v = v.slice(0, 11);
        if (v.length <= 7) {
            v = v.replace(/(\d{3})(\d{3})(\d{1})/, '$1.$2-$3');
        } else {
            v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
        }
        $(this).val(v);
    });

    // --- B. CERTIFICATE VERIFICATION (AJAX) ---
    $(document).on('submit', '.ffc-verification-form', function(e) {
        e.preventDefault();
        const $form = $(this);
        const $btn = $form.find('button[type="submit"]');
        const $resultContainer = $form.closest('.ffc-verification-container').find('.ffc-verify-result');
        const rawCode = $form.find('input[name="ffc_auth_code"]').val();
        
        if(!rawCode) return;
        const cleanCode = rawCode.replace(/[^a-zA-Z0-9]/g, ''); 

        $btn.prop('disabled', true).text(ffc_ajax.strings.verifying);
        $resultContainer.fadeOut();
        
        $.ajax({
            url: ffc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ffc_verify_certificate',
                ffc_auth_code: cleanCode,
                ffc_captcha_ans: $form.find('input[name="ffc_captcha_ans"]').val(),
                ffc_captcha_hash: $form.find('input[name="ffc_captcha_hash"]').val(),
                nonce: ffc_ajax.nonce
            },
            success: function(response) {
                $btn.prop('disabled', false).text(ffc_ajax.strings.verify);
                if (response.success) {
                    $resultContainer.html(response.data.html).fadeIn();
                } else {
                    $resultContainer.html(`<div class="ffc-verify-error">${response.data.message}</div>`).fadeIn();
                    refreshCaptcha($form, response.data);
                }
            },
            error: function() {
                $btn.prop('disabled', false).text(ffc_ajax.strings.verify);
                alert(ffc_ajax.strings.connectionError);
            }
        });
    });

    // --- C. SUBMISSION AND GENERATION (AJAX) ---
    $(document).on('submit', '.ffc-submission-form', function(e) {
        e.preventDefault();
        const $form = $(this);
        const $btn = $form.find('button[type="submit"]');
        const $msg = $form.find('.ffc-message');
        
        const rawCPF = $form.find('input[name="cpf_rf"]').val() ? $form.find('input[name="cpf_rf"]').val().replace(/\D/g, '') : '';
        
        if (rawCPF && rawCPF.length !== 7 && rawCPF.length !== 11) {
            alert(ffc_ajax.strings.idMustHaveDigits);
            return false;
        }

        $btn.prop('disabled', true).text(ffc_ajax.strings.processing);
        $msg.removeClass('ffc-success ffc-error').hide();

        let formData = $form.serializeArray();
        formData.push({ name: 'action', value: 'ffc_submit_form' });
        formData.push({ name: 'nonce', value: ffc_ajax.nonce });

        $.ajax({
            url: ffc_ajax.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                $btn.prop('disabled', false).text(ffc_ajax.strings.submit);
                
                if (response.success) {
                    $msg.addClass('ffc-success').html(response.data.message).fadeIn();
                    if (response.data.pdf_data) {
                        $msg.append(`<p><small>${ffc_ajax.strings.generatingCertificate}</small></p>`);
                        window.generateCertificate(response.data.pdf_data);
                    }
                    $form[0].reset();
                    if(response.data.refresh_captcha) refreshCaptcha($form, response.data);
                } else {
                    $msg.addClass('ffc-error').html(response.data.message).fadeIn();
                    refreshCaptcha($form, response.data);
                }
            },
            error: function() {
                $btn.prop('disabled', false).text(ffc_ajax.strings.submit);
                alert(ffc_ajax.strings.connectionError);
            }
        });
    });
});