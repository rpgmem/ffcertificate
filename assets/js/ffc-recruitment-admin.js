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
}());
