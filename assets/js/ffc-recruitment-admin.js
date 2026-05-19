/**
 * Recruitment admin JS bundle.
 *
 * Exposes `window.ffcRecruitmentAdmin.fetch()` for inline scripts that
 * still live in PHP renderers, plus delegated handlers that pick up the
 * uniform markup contracts described below.
 *
 * Localized data lives on `window.ffcRecruitmentAdmin`:
 *   - restRoot: '…/wp-json/ffcertificate/v1/recruitment/'
 *   - nonce:    'XXXXXX' (wp_rest)
 *
 * Markup contracts handled here:
 *
 *   - `form[data-ffc-create-endpoint="<path>"]` — on submit, POST the
 *     form's FormData to `<restRoot><path>`. Reload on success
 *     (response carries an `id`); alert on failure with the server's
 *     message (or the raw body as a fallback).
 *
 *   - `input[data-ffc-color-endpoint="<entity>"][data-ffc-entity-id="<id>"]`
 *     — on `change`, PATCH `{ color: <new value> }` to
 *     `<restRoot><entity>/<id>` (PATCH via `X-HTTP-Method-Override`).
 *     Updates the input and any sibling `[data-ffc-color-hex]` label so
 *     the admin sees the canonical color the server stored.
 */
(function () {
    'use strict';

    if (typeof window.ffcRecruitmentAdmin !== 'object') {
        return;
    }

    /**
     * Thin fetch wrapper that injects the REST nonce + same-origin creds.
     *
     * @param {string} path  Path relative to the recruitment REST root.
     * @param {object} opts  Standard fetch options.
     * @returns {Promise<{status:number, body:any}>}
     */
    window.ffcRecruitmentAdmin.fetch = function (path, opts) {
        opts = opts || {};
        opts.headers = Object.assign(
            {},
            opts.headers || {},
            { 'X-WP-Nonce': window.ffcRecruitmentAdmin.nonce }
        );
        opts.credentials = 'same-origin';

        return fetch(window.ffcRecruitmentAdmin.restRoot + path, opts).then(function (r) {
            return r
                .json()
                .catch(function () {
                    return null;
                })
                .then(function (body) {
                    return { status: r.status, body: body };
                });
        });
    };

    /**
     * Delegated submit handler for `form[data-ffc-create-endpoint]`.
     *
     * The PHP renderer marks each "create X" form with the REST path
     * to post against (e.g. `data-ffc-create-endpoint="notices"`).
     * On a successful POST the page reloads so the new entity shows
     * up in the list table above the form.
     */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || typeof form.matches !== 'function') { return; }
        if (!form.matches('form[data-ffc-create-endpoint]')) { return; }

        event.preventDefault();

        var endpoint = form.getAttribute('data-ffc-create-endpoint');
        if (!endpoint) { return; }

        window.ffcRecruitmentAdmin.fetch(endpoint, {
            method: 'POST',
            body: new FormData(form)
        }).then(function (res) {
            var body = res && res.body;
            if (body && body.id) {
                window.location.reload();
                return;
            }
            window.alert((body && body.message) ? body.message : JSON.stringify(body));
        });
    });

    /**
     * Delegated change handler for color-picker inputs that auto-PATCH
     * their value to the REST endpoint identified by
     * `data-ffc-color-endpoint`. A sibling `[data-ffc-color-hex]`
     * element (if present) updates in place so admins see the canonical
     * color the server stored.
     *
     * Listening at `change` (not `input`) so we don't fire on every
     * drag step across the picker — only once the user commits.
     */
    document.addEventListener('change', function (event) {
        var input = event.target;
        if (!input || typeof input.matches !== 'function') { return; }
        if (!input.matches('input[data-ffc-color-endpoint][data-ffc-entity-id]')) { return; }

        var endpoint = input.getAttribute('data-ffc-color-endpoint');
        var entityId = parseInt(input.getAttribute('data-ffc-entity-id'), 10);
        if (!endpoint || !entityId) { return; }

        var fd = new FormData();
        fd.append('color', input.value);

        window.ffcRecruitmentAdmin.fetch(endpoint + '/' + entityId, {
            method: 'POST',
            headers: { 'X-HTTP-Method-Override': 'PATCH' },
            body: fd
        }).then(function (res) {
            var body = res && res.body;
            if (body && body.color) {
                input.value = body.color;
                var hex = input.parentNode
                    ? input.parentNode.querySelector('[data-ffc-color-hex]')
                    : null;
                if (hex) {
                    hex.textContent = body.color;
                }
                return;
            }
            window.alert((body && body.message) ? body.message : JSON.stringify(body));
        });
    });

    // -----------------------------------------------------------------
    // Confirm modal (replaces native confirm() for destructive actions)
    // -----------------------------------------------------------------
    //
    // Markup contract on the trigger element (either a form or an
    // anchor/button — both work):
    //
    //   data-ffc-confirm                    // boolean marker
    //   data-ffc-confirm-title="…"          // modal heading
    //   data-ffc-confirm-body="…"           // intro paragraph (plain text)
    //   data-ffc-confirm-consequences='["…","…"]'   // JSON array of bullets
    //   data-ffc-confirm-cta="Confirm"      // primary button label
    //   data-ffc-confirm-style="destructive|primary"  // optional, default 'primary'
    //   data-ffc-confirm-reason-label="…"   // optional — adds a required text input
    //   data-ffc-confirm-reason-name="…"    // optional — input name (defaults to "reason")
    //
    // On confirm we re-fire the original action. For a FORM we set a
    // sentinel attribute, call .submit() and skip the modal on the
    // second pass. For an ANCHOR we navigate to .href.

    var i18n = (window.ffcRecruitmentAdmin && window.ffcRecruitmentAdmin.confirmModalStrings) || {};

    function escText(value) {
        var div = document.createElement('div');
        div.textContent = String(value == null ? '' : value);
        return div.innerHTML;
    }

    function parseConsequences(raw) {
        if (!raw) { return []; }
        try {
            var parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function getModal() {
        var existing = document.getElementById('ffc-confirm-modal');
        if (existing) { return existing; }

        var closeLabel = i18n.closeLabel || 'Close';
        var cancelLabel = i18n.cancelLabel || 'Cancel';
        var overlay = document.createElement('div');
        overlay.id = 'ffc-confirm-modal';
        overlay.className = 'ffc-confirm-modal-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'ffc-confirm-modal-title');
        overlay.hidden = true;
        overlay.innerHTML =
            '<div class="ffc-confirm-modal" data-style="primary">' +
                '<div class="ffc-confirm-modal-header">' +
                    '<h2 id="ffc-confirm-modal-title"></h2>' +
                    '<button type="button" class="ffc-confirm-modal-close" aria-label="' + escText(closeLabel) + '">&times;</button>' +
                '</div>' +
                '<div class="ffc-confirm-modal-body"></div>' +
                '<div class="ffc-confirm-modal-footer">' +
                    '<button type="button" class="button ffc-confirm-modal-cancel">' + escText(cancelLabel) + '</button>' +
                    '<button type="button" class="button button-primary ffc-confirm-modal-confirm"></button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        return overlay;
    }

    var state = { trigger: null, onKeydown: null };

    function closeModal() {
        var modal = document.getElementById('ffc-confirm-modal');
        if (modal) { modal.hidden = true; }
        if (state.onKeydown) {
            document.removeEventListener('keydown', state.onKeydown);
            state.onKeydown = null;
        }
        var focusTarget = state.trigger;
        state.trigger = null;
        if (focusTarget && typeof focusTarget.focus === 'function') {
            try { focusTarget.focus(); } catch (e) { /* ignore */ }
        }
    }

    function fireTrigger(trigger, reasonValue, reasonName) {
        if (!trigger) { return; }

        // Inject the reason input into forms so the existing handler
        // picks it up via $_POST.
        if (reasonValue !== null && trigger.tagName === 'FORM') {
            var existing = trigger.querySelector('input[data-ffc-confirm-reason-input]');
            if (existing) { existing.parentNode.removeChild(existing); }
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = reasonName || 'reason';
            hidden.value = reasonValue;
            hidden.setAttribute('data-ffc-confirm-reason-input', '1');
            trigger.appendChild(hidden);
        }

        trigger.setAttribute('data-ffc-confirm-ok', '1');
        if (trigger.tagName === 'FORM') {
            // .submit() bypasses the submit event handler — exactly what
            // we want here to avoid re-entering the modal flow.
            trigger.submit();
        } else if (trigger.tagName === 'A') {
            var href = trigger.getAttribute('href');
            if (href) { window.location.href = href; }
        } else if (typeof trigger.click === 'function') {
            trigger.click();
        }
    }

    function openModal(trigger) {
        var modal = getModal();
        var dialog = modal.querySelector('.ffc-confirm-modal');
        var title = modal.querySelector('#ffc-confirm-modal-title');
        var body = modal.querySelector('.ffc-confirm-modal-body');
        var confirmBtn = modal.querySelector('.ffc-confirm-modal-confirm');
        var cancelBtn = modal.querySelector('.ffc-confirm-modal-cancel');
        var closeBtn = modal.querySelector('.ffc-confirm-modal-close');

        var titleText = trigger.getAttribute('data-ffc-confirm-title') || (i18n.defaultTitle || 'Confirm action');
        var bodyText = trigger.getAttribute('data-ffc-confirm-body') || '';
        var consequences = parseConsequences(trigger.getAttribute('data-ffc-confirm-consequences'));
        var ctaText = trigger.getAttribute('data-ffc-confirm-cta') || (i18n.defaultCta || 'Confirm');
        var style = trigger.getAttribute('data-ffc-confirm-style') === 'destructive' ? 'destructive' : 'primary';
        var reasonLabel = trigger.getAttribute('data-ffc-confirm-reason-label') || '';
        var reasonName = trigger.getAttribute('data-ffc-confirm-reason-name') || 'reason';

        title.textContent = titleText;
        dialog.setAttribute('data-style', style);
        confirmBtn.className = 'button ' + (style === 'destructive' ? 'ffc-confirm-modal-confirm' : 'button-primary ffc-confirm-modal-confirm');
        confirmBtn.textContent = ctaText;

        // Body content
        var html = '';
        if (bodyText) {
            html += '<p>' + escText(bodyText) + '</p>';
        }
        if (consequences.length > 0) {
            html += '<ul class="ffc-confirm-modal-consequences">';
            consequences.forEach(function (line) {
                html += '<li>' + escText(line) + '</li>';
            });
            html += '</ul>';
        }
        var reasonInput = null;
        if (reasonLabel) {
            var inputId = 'ffc-confirm-modal-reason-input';
            html += '<div class="ffc-confirm-modal-reason">'
                + '<label for="' + inputId + '">' + escText(reasonLabel) + '</label>'
                + '<input id="' + inputId + '" type="text" class="regular-text" autocomplete="off" />'
                + '</div>';
        }
        body.innerHTML = html;
        if (reasonLabel) {
            reasonInput = body.querySelector('#ffc-confirm-modal-reason-input');
        }

        // Reason-gated CTA: disable until non-empty.
        function syncCta() {
            if (reasonInput) {
                confirmBtn.disabled = reasonInput.value.trim().length === 0;
            } else {
                confirmBtn.disabled = false;
            }
        }
        syncCta();
        if (reasonInput) {
            reasonInput.oninput = syncCta;
        }

        // Wire handlers (replaceWith pattern keeps things clean across
        // repeated opens without piling up listeners).
        confirmBtn.onclick = function () {
            if (confirmBtn.disabled) { return; }
            var reasonValue = reasonInput ? reasonInput.value.trim() : null;
            var trig = state.trigger;
            closeModal();
            fireTrigger(trig, reasonValue, reasonName);
        };
        cancelBtn.onclick = closeModal;
        closeBtn.onclick = closeModal;
        modal.onclick = function (e) {
            if (e.target === modal) { closeModal(); }
        };

        state.trigger = trigger;
        state.onKeydown = function (e) {
            if (e.key === 'Escape') { closeModal(); }
        };
        document.addEventListener('keydown', state.onKeydown);

        modal.hidden = false;
        // Focus management: reason input if present, otherwise the
        // cancel button (safer default than auto-focusing the
        // destructive CTA).
        if (reasonInput) {
            reasonInput.focus();
        } else {
            cancelBtn.focus();
        }
    }

    function shouldIntercept(el) {
        if (!el || el.getAttribute('data-ffc-confirm-ok') === '1') { return false; }
        return el.hasAttribute('data-ffc-confirm');
    }

    // Submit interception — picks up any form carrying the marker.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || typeof form.matches !== 'function') { return; }
        if (!shouldIntercept(form)) { return; }
        event.preventDefault();
        openModal(form);
    }, true);

    // Click interception — anchors and standalone buttons.
    document.addEventListener('click', function (event) {
        var el = event.target;
        // Walk up to find the nearest [data-ffc-confirm] ancestor.
        while (el && el !== document.body) {
            if (el.hasAttribute && el.hasAttribute('data-ffc-confirm')) {
                break;
            }
            el = el.parentNode;
        }
        if (!el || el === document.body) { return; }
        if (!shouldIntercept(el)) { return; }
        // Forms are handled by submit; only intercept anchors / buttons.
        if (el.tagName === 'FORM') { return; }
        event.preventDefault();
        openModal(el);
    }, true);
}());

