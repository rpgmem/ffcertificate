/**
 * FFC Cache Actions — inline AJAX for the Settings → Cache buttons.
 *
 * The two buttons keep their nonced href as a no-JS fallback (the
 * legacy admin_init handler in Settings::handle_cache_actions still
 * runs); this module just intercepts the click, calls FFC.request,
 * and pops a toast via FFC.Admin.showNotification — no reload, no
 * lost scroll position, no lost tab state.
 *
 * @since 6.5.5
 */
(function ($) {
    'use strict';

    if (!window.FFC || !window.FFC.request || !window.FFC.Admin || typeof window.FFC.Admin.showNotification !== 'function') {
        return;
    }

    var strings = (window.ffcCacheActions && window.ffcCacheActions.strings) || {};

    function disable($btn) {
        $btn.data('ffc-prev-text', $btn.text());
        $btn.prop('disabled', true).addClass('disabled').text(strings.working || 'Working…');
    }

    function restore($btn) {
        var prev = $btn.data('ffc-prev-text');
        $btn.prop('disabled', false).removeClass('disabled');
        if (prev) { $btn.text(prev); }
    }

    function run(action, $btn) {
        disable($btn);
        // Each action has its own nonce; pull from the localized map.
        // FFC.request preserves data.nonce so this overrides the
        // (wrong-action) global FFC.config.nonce default.
        var nonces = (window.ffcCacheActions && window.ffcCacheActions.nonces) || {};
        var payload = {};
        if (nonces[action]) {
            payload.nonce = nonces[action];
        }
        window.FFC.request(action, payload)
            .then(function (data) {
                restore($btn);
                var msg = (data && data.message) ? data.message : (strings.success || 'Done.');
                window.FFC.Admin.showNotification(msg, 'success');
            })
            .catch(function (err) {
                restore($btn);
                var msg = (err && err.message) ? err.message : (strings.error || 'Action failed.');
                window.FFC.Admin.showNotification(msg, 'error');
            });
    }

    $(document).on('click', '.ffc-cache-warm-btn, .ffc-cache-clear-btn', function (e) {
        var $btn   = $(this);
        var action = $btn.data('ffc-action');
        if (!action) { return; }

        // Honour the legacy confirm() prompt for the destructive action.
        if ($btn.hasClass('ffc-cache-clear-btn')) {
            var confirmMsg = $btn.data('ffc-confirm') || strings.confirmClear || 'Clear all cache?';
            if (!window.confirm(confirmMsg)) {
                e.preventDefault();
                return;
            }
        }

        e.preventDefault();
        run(action, $btn);
    });
}(jQuery));
