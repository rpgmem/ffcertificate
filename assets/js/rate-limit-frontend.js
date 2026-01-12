/* Rate Limit Frontend */
(function($) {
    'use strict';
    
    window.FFCRateLimit = {
        blocked: false,
        waitSeconds: 0,
        countdownInterval: null,
        
        show: function(message, waitSeconds) {
            this.blocked = true;
            this.waitSeconds = waitSeconds;
            
            var $form = $('.ffc-form');
            var $btn = $form.find('button[type="submit"]');
            
            $form.prepend('<div class="ffc-rate-limit-notice"><div class="ffc-rate-limit-icon">⏱️</div><div class="ffc-rate-limit-message">' + message + ' <strong id="ffc-countdown">0:00</strong></div></div>');
            
            $btn.prop('disabled', true).html('Aguarde... (<span id="ffc-countdown-btn">0:00</span>)');
            
            this.startCountdown();
        },
        
        startCountdown: function() {
            var self = this;
            var remaining = this.waitSeconds;
            
            var updateDisplay = function() {
                if (remaining <= 0) {
                    self.enable();
                    return;
                }
                
                var mins = Math.floor(remaining / 60);
                var secs = remaining % 60;
                var display = mins + ':' + (secs < 10 ? '0' : '') + secs;
                
                $('#ffc-countdown').text(display);
                $('#ffc-countdown-btn').text(display);
                
                remaining--;
                setTimeout(updateDisplay, 1000);
            };
            
            updateDisplay();
        },
        
        enable: function() {
            this.blocked = false;
            $('.ffc-rate-limit-notice').remove();
            $('.ffc-form button[type="submit"]').prop('disabled', false).text('Enviar');
        }
    };
})(jQuery);