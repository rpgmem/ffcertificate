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
}());
