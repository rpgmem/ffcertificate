/**
 * Recruitment — candidate edit page interactions.
 *
 * Extracted from the two inline <script> blocks in
 * class-ffc-recruitment-candidate-edit-page.php (frontend-audit Item 3 —
 * PHP fragmentation: move inline JS out to assets/js/). Config (REST roots,
 * nonce, i18n labels) arrives via wp_localize_script as
 * window.ffcRecruitmentCandidateEdit.
 *
 * Two document-delegated click handlers (inert when their buttons aren't on
 * the page, so loading on every recruitment screen is harmless):
 *  - PII reveal/hide (.ffc-pii-reveal-btn) → POST …/candidates/{id}/reveal-pii
 *  - Adjutancy swap   (.ffc-adjutancy-swap-btn) → PATCH …/classifications/{id}/adjutancy
 */
(function () {
    'use strict';

    var cfg     = window.ffcRecruitmentCandidateEdit || {};
    var strings = cfg.strings || {};

    // ── PII reveal / hide (issue: masked sensitive fields) ──────────────
    document.addEventListener('click', function (ev) {
        var btn = ev.target;
        if (!btn || !btn.classList || !btn.classList.contains('ffc-pii-reveal-btn')) {
            return;
        }
        ev.preventDefault();

        var cid   = btn.getAttribute('data-ffc-pii-candidate');
        var field = btn.getAttribute('data-ffc-pii-field');
        var ph    = btn.parentNode.querySelector('.ffc-pii-placeholder[data-ffc-pii-field="' + field + '"]');
        if (!cid || !field || !ph) {
            return;
        }

        // Hide path — restore the saved placeholder text and reset the label.
        if (btn.getAttribute('data-ffc-pii-revealed') === '1') {
            ph.textContent = btn.getAttribute('data-ffc-pii-mask') || '';
            btn.textContent = strings.reveal || 'Reveal';
            btn.removeAttribute('data-ffc-pii-revealed');
            return;
        }

        btn.disabled = true;
        var fd = new FormData();
        fd.append('field', field);

        fetch(cfg.revealRoot + cid + '/reveal-pii', {
            method: 'POST',
            headers: { 'X-WP-Nonce': cfg.nonce },
            body: fd,
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json().then(function (d) { return { status: r.status, body: d }; });
        }).then(function (o) {
            btn.disabled = false;
            if (o.status >= 200 && o.status < 300 && o.body && typeof o.body.value === 'string' && ph.tagName === 'INPUT') {
                // A masked form field (the General email, #1863): it takes
                // the clear value, its name and focus, so it is edited and
                // posted only after a reveal. There is no hide path for it.
                ph.value = o.body.value;
                ph.name = ph.getAttribute('data-ffc-pii-name') || '';
                ph.type = 'email';
                ph.disabled = false;
                btn.parentNode.removeChild(btn);
                ph.focus();
            } else if (o.status >= 200 && o.status < 300 && o.body && typeof o.body.value === 'string') {
                btn.setAttribute('data-ffc-pii-mask', ph.textContent);
                ph.textContent = o.body.value;
                btn.textContent = strings.hide || 'Hide';
                btn.setAttribute('data-ffc-pii-revealed', '1');
            } else {
                var msg = (o.body && o.body.message) ? o.body.message : (strings.error || 'Error');
                ph.textContent = '[' + msg + ']';
            }
        }).catch(function () {
            btn.disabled = false;
            ph.textContent = '[' + (strings.error || 'Error') + ']';
        });
    });

    // ── Adjutancy swap (#331) ───────────────────────────────────────────
    document.addEventListener('click', function (ev) {
        var btn = ev.target;
        if (!btn || !btn.classList || !btn.classList.contains('ffc-adjutancy-swap-btn')) {
            return;
        }
        ev.preventDefault();

        var wrap = btn.closest('.ffc-adjutancy-swap');
        if (!wrap) {
            return;
        }
        var cid = wrap.getAttribute('data-ffc-cls-id');
        var sel = wrap.querySelector('.ffc-adjutancy-swap-select');
        var msg = wrap.querySelector('.ffc-adjutancy-swap-msg');
        if (!cid || !sel || !msg) {
            return;
        }
        window.FFC.status(msg, '', '');
        btn.disabled = true;

        fetch(cfg.classRoot + encodeURIComponent(cid) + '/adjutancy', {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            body: JSON.stringify({ adjutancy_id: parseInt(sel.value, 10) })
        }).then(function (r) {
            return r.json().then(function (b) { return { ok: r.ok, body: b }; });
        }).then(function (res) {
            btn.disabled = false;
            if (res.ok && res.body && res.body.success) {
                window.FFC.status(msg, 'success', strings.saved || 'Saved');
            } else {
                window.FFC.status(msg, 'error', (strings.error || 'Error') + ': ' + ((res.body && res.body.message) || ''));
            }
        }).catch(function () {
            btn.disabled = false;
            window.FFC.status(msg, 'error', strings.error || 'Error');
        });
    });

    // ── Phone mask (#1920) ───────────────────────────────────────────────
    // The shared phone mask, as on the dashboard and the user screen. A
    // stored number that fits it is shown in its shape; one with more than
    // eleven digits (an international number saved before the mask) is shown
    // as stored, and typing into it reshapes it to the mask.
    var phone = document.getElementById('ffc-cand-phone');
    if (phone && window.FFC && window.FFC.Fields && window.jQuery) {
        if (phone.value && phone.value.replace(/\D/g, '').length <= 11) {
            phone.value = window.FFC.Fields.masks.phone(phone.value);
        }
        window.FFC.Fields.initMasks(window.jQuery(phone).parent());
    }

})();
