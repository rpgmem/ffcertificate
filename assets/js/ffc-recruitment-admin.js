/**
 * Recruitment admin JS bundle.
 *
 * Sprint A1 ships the skeleton + REST helper. Inline <script> blocks
 * scattered across the admin page (create-notice, create-adjutancy,
 * CSV import, attach/detach adjutancy) get migrated here in subsequent
 * sprints; for now they continue to work in-place and this bundle just
 * exposes the helper they'll use.
 *
 * Localized data lives on `window.ffcRecruitmentAdmin`:
 *   - restRoot: '…/wp-json/ffcertificate/v1/recruitment/'
 *   - nonce:    'XXXXXX' (wp_rest)
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
}());
