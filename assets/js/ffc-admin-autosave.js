/**
 * FFC.Admin.autoSaveField — debounced inline-save widget for admin fields.
 *
 * Wires a debounced change handler that calls the `ffc_update_setting`
 * AJAX endpoint (via FFC.request) and surfaces a "Saving…" / "Saved" /
 * "Error" badge next to the field. Intended for atomic boolean toggles
 * (admin_bypass_*, future feature flags) and similar side-effect-free
 * settings — anything where partial save can't create inconsistency.
 *
 * Usage:
 *   FFC.Admin.autoSaveField($('#admin_bypass_geo'), {
 *       key: 'admin_bypass_geo',
 *   });
 *
 * @since 6.5.4
 */
(function ($) {
    'use strict';

    if (!window.FFC || !window.FFC.Admin) {
        return;
    }

    var BADGE_CLASS  = 'ffc-autosave-badge';
    var SAVED_LINGER = 1800; // ms — how long the "Saved" badge stays before fading.

    /**
     * Resolve a value out of a jQuery field. Bool by default; admins can
     * pass a custom transform for richer fields (e.g. text/number).
     *
     * @param {jQuery}   $field
     * @param {Function} [transform]
     * @returns {string|string[]}
     */
    function extractValue($field, transform) {
        if (typeof transform === 'function') {
            return transform($field);
        }
        // Checkbox — boolean as '1' / '0'.
        if ($field.is(':checkbox')) {
            return $field.is(':checked') ? '1' : '0';
        }
        // Radio group — send the checked member's value (e.g. a log-level
        // picker), not a boolean. Falls back to the field's own value.
        if ($field.is(':radio')) {
            var radioName = $field.attr('name');
            if (radioName) {
                return $('input[name="' + radioName + '"]:checked').val();
            }
            return $field.val();
        }
        return $field.val();
    }

    /**
     * Collect checked values from every checkbox in a sibling group
     * (shares `data-ffc-autosave-key` + has `data-ffc-autosave-multi`).
     * Used by the rate-limit "Signals collected" toggles where the
     * server stores `string[]` and the UI is N independent checkboxes.
     *
     * @param {jQuery} $group
     * @returns {string[]}
     */
    function collectMultiValues($group) {
        var vals = [];
        $group.filter(':checked').each(function () {
            vals.push($(this).val());
        });
        return vals;
    }

    /**
     * Inject / locate the badge container near $field. Returns the
     * badge jQuery node.
     */
    function ensureBadge($field, $explicit) {
        if ($explicit && $explicit.length) {
            return $explicit;
        }
        // `.ffc-toggle` wraps `<input>` + `<span.ffc-toggle-track>` + label
        // text. Injecting the badge between the input and the track kills
        // the `input:checked + .ffc-toggle-track` CSS rule that recolors
        // the track on toggle-on — so the toggle visually stays "off"
        // even after the save succeeds. Anchor the badge AFTER the
        // wrapping label instead so the track stays adjacent.
        var $anchor = $field.closest('.ffc-toggle');
        if (!$anchor.length) {
            $anchor = $field;
        }
        var $existing = $anchor.next('.' + BADGE_CLASS);
        if ($existing.length) {
            return $existing;
        }
        var $badge = $('<span class="' + BADGE_CLASS + '" aria-live="polite" hidden></span>');
        $anchor.after($badge);
        return $badge;
    }

    /**
     * Show one of three badge states: 'saving' | 'saved' | 'error'.
     *
     * @param {jQuery}  $badge
     * @param {string}  state
     * @param {string}  [text]
     */
    function setBadgeState($badge, state, text) {
        $badge
            .removeClass(BADGE_CLASS + '--saving ' + BADGE_CLASS + '--saved ' + BADGE_CLASS + '--error')
            .addClass(BADGE_CLASS + '--' + state)
            .text(text || '')
            .removeAttr('hidden');
    }

    function hideBadge($badge) {
        $badge.attr('hidden', 'hidden').text('');
    }

    /**
     * Announce a successful save on `document` as `ffc:setting-saved`.
     *
     * A setting that changes the page it is edited on has to repaint, and this
     * widget must not know which settings those are — so it states the fact and
     * lets an interested script act. Today that is `ffc-dark-mode.js`, which
     * without it wrote the option and left the page on its old theme until the
     * next load (both directions — a missing repaint that read as a failed save).
     *
     * CustomEvent is IE-only-absent, and this is an admin script behind jQuery;
     * the guard is for a test environment without it, not for a browser.
     *
     * @param {string}          key   The allowlisted setting key that was saved.
     * @param {string|string[]} value The value that reached the server.
     */
    function announceSaved(key, value) {
        if (typeof window.CustomEvent !== 'function') {
            return;
        }
        document.dispatchEvent(new window.CustomEvent('ffc:setting-saved', {
            detail: { key: key, value: value },
        }));
    }

    /**
     * Attach auto-save behaviour to a field.
     *
     * The endpoint is a parameter rather than a constant because two of
     * them autosave admin fields: `ffc_update_setting` writes WP options
     * under a global capability, `ffc_update_form_meta` writes per-post
     * meta gated on `edit_post` of that exact form. They stay separate
     * server-side on purpose — the capability check is the whole point —
     * but nothing about *this* side differs, which is why the form-meta
     * path used to be a second implementation and no longer is (#1116).
     *
     * @param {jQuery} $field
     * @param {Object} config
     * @param {string} config.key                      Allowlisted setting key.
     * @param {string} [config.action='ffc_update_setting'] AJAX action to POST to.
     * @param {Object} [config.payload]                Extra fields merged into the request.
     * @param {Object} [config.request]                FFC.request options ({nonce, ajaxUrl}).
     * @param {Function} [config.transform]            Custom value extractor.
     * @param {number} [config.debounce=400]           Debounce window in ms.
     * @param {jQuery} [config.$badge]                 Pre-existing badge node.
     * @param {Object} [config.strings]                Custom strings.
     * @param {string} [config.strings.saving='Saving…']
     * @param {string} [config.strings.saved='Saved']
     * @param {string} [config.strings.error='Save failed']
     * @param {string} [config.strings.invalid='Enter a valid value']
     * @returns {Object} {destroy: fn}
     */
    function autoSaveField($field, config) {
        config = config || {};
        if (!config.key) {
            if (window.console) {
                window.console.warn('FFC.Admin.autoSaveField: missing config.key');
            }
            return { destroy: function () {} };
        }
        var strings  = config.strings || {};
        var saving   = strings.saving || 'Saving…';
        var saved    = strings.saved  || 'Saved';
        var errorTxt = strings.error  || 'Save failed';
        var invalidTxt = strings.invalid || 'Enter a valid value';
        var debounceMs = typeof config.debounce === 'number' ? config.debounce : 400;
        var $badge   = ensureBadge($field, config.$badge);

        var pendingTimer = null;
        var lingerTimer  = null;

        function scheduleSave() {
            if (pendingTimer) {
                clearTimeout(pendingTimer);
            }
            if (lingerTimer) {
                clearTimeout(lingerTimer);
                lingerTimer = null;
            }
            pendingTimer = setTimeout(performSave, debounceMs);
        }

        /**
         * Refuse to save a field the browser itself considers invalid.
         *
         * This widget saves on `input`, so without the check a number field
         * cleared on the way to retyping it was saved as an empty string the
         * moment it was emptied — and `min`, `max`, `step` and `required` were
         * decorative here, enforced only on a submit this path never does
         * (#1114). A checkbox has no constraints, so this is a no-op for every
         * toggle; it bites on numbers and on the URL fields.
         *
         * It was written twice while the form-meta path was its own handler.
         * That is what #1116 converged: one copy now covers both endpoints.
         *
         * @returns {boolean} True when the field may be saved.
         */
        function isSaveable() {
            var el = $field[0];
            if (!el || typeof el.checkValidity !== 'function' || el.checkValidity()) {
                return true;
            }
            setBadgeState($badge, 'error', (el.validationMessage || invalidTxt));
            if (typeof el.reportValidity === 'function') {
                el.reportValidity();
            }
            return false;
        }

        function performSave() {
            pendingTimer = null;
            if (!isSaveable()) {
                return;
            }
            setBadgeState($badge, 'saving', saving);
            var value = extractValue($field, config.transform);
            // Each endpoint verifies its own nonce, and neither accepts the
            // global FFC.config.nonce (created for `ffc_admin_pdf_nonce`).
            // The settings path carries its nonce inside the payload, from
            // window.ffcAdminAutosave (localized by enqueue_autosave_infra);
            // the form-meta path passes nonce + ajaxUrl as FFC.request
            // options, where an explicit options.nonce wins. Both land the
            // same field on the wire.
            var payload = $.extend({ key: config.key, value: value }, config.payload || {});
            var autosaveCfg = window.ffcAdminAutosave;
            if (!config.request && autosaveCfg && autosaveCfg.nonce) {
                payload.nonce = autosaveCfg.nonce;
            }
            window.FFC.request(config.action || 'ffc_update_setting', payload, config.request)
                .then(function () {
                    setBadgeState($badge, 'saved', saved);
                    lingerTimer = setTimeout(function () { hideBadge($badge); }, SAVED_LINGER);
                    announceSaved(config.key, value);
                })
                .catch(function (err) {
                    setBadgeState($badge, 'error', (err && err.message) ? err.message : errorTxt);
                });
        }

        /**
         * Change handler. When `config.confirmOff` is set and the field is a
         * checkbox being turned OFF, prompt for confirmation first; declining
         * reverts the toggle and cancels the save. Used by consequential toggles
         * (e.g. disabling a plugin module) so an accidental click can't silently
         * take a feature offline.
         */
        function onChange() {
            if (config.confirmOff && $field.is(':checkbox') && !$field.is(':checked')) {
                if (!window.confirm(config.confirmOff)) {
                    $field.prop('checked', true);
                    return;
                }
            }
            scheduleSave();
        }

        // For multi-checkbox groups the change event must fire from any
        // sibling in the group, so attach the listener to the whole group.
        var $changeSource = config.$group && config.$group.length ? config.$group : $field;
        // Confirm-on-disable toggles bind to `change` only — a checkbox fires
        // both `input` and `change`, and a double-bound confirm would prompt
        // twice per click.
        var changeEvents = config.confirmOff ? 'change.ffcAutoSave' : 'change.ffcAutoSave input.ffcAutoSave';
        $changeSource.on(changeEvents, onChange);

        return {
            destroy: function () {
                $changeSource.off('change.ffcAutoSave input.ffcAutoSave');
                if (pendingTimer) { clearTimeout(pendingTimer); }
                if (lingerTimer)  { clearTimeout(lingerTimer); }
                $badge.remove();
            },
        };
    }

    window.FFC.Admin.autoSaveField = autoSaveField;

    /**
     * Configuration for the per-post-meta variant, or null when this
     * screen has none.
     *
     * The form editor localizes `ffcFormMetaAutosave` with the post id,
     * the nonce for `ffc_update_form_meta` and its own ajaxUrl. Absent
     * any of those there is nothing to save against, so the fields stay
     * unbound rather than POSTing into a rejection.
     *
     * @returns {Object|null}
     */
    function formMetaBase() {
        var cfg = window.ffcFormMetaAutosave;
        if (!cfg || !cfg.ajaxUrl || !cfg.postId) {
            return null;
        }
        return {
            action:  cfg.action || 'ffc_update_form_meta',
            payload: { post_id: cfg.postId },
            request: { nonce: cfg.nonce, ajaxUrl: cfg.ajaxUrl },
            strings: cfg.strings || {},
        };
    }

    /**
     * Configuration for the settings variant.
     *
     * Only strings — the nonce is read at save time from the same
     * localized object, and the action is this widget's default.
     *
     * @returns {Object}
     */
    function settingsBase() {
        var cfg = window.ffcAdminAutosave;
        return { strings: (cfg && cfg.strings) || {} };
    }

    /**
     * Wire every field carrying `attr` to {@link autoSaveField}.
     *
     * Idempotent — fields that have already been bound carry an
     * `ffcAutoSaveBound` data flag and are skipped on subsequent calls.
     *
     * @param {string}      attr    Attribute that marks a field, e.g. `data-ffc-autosave-key`.
     * @param {Object|null} base    Endpoint config merged into every field's config.
     */
    function bootVariant(attr, base) {
        var multiBound = {};
        $('[' + attr + ']').each(function () {
            var $input = $(this);
            if ($input.data('ffcAutoSaveBound')) {
                return;
            }
            var key      = $input.attr(attr);
            var isMulti  = $input.attr('data-ffc-autosave-multi') !== undefined;
            if (!key) {
                return;
            }

            // Multi-checkbox group — only the first occurrence per key
            // becomes the "anchor" (carries the badge, drives the AJAX
            // call). Siblings still get marked bound so this loop skips
            // them on the next pass, but their changes are funneled
            // through the anchor's change handler via config.$group.
            if (isMulti) {
                if (multiBound[key]) {
                    $input.data('ffcAutoSaveBound', true);
                    return;
                }
                multiBound[key] = true;
            }
            $input.data('ffcAutoSaveBound', true);

            var config = $.extend({ key: key }, base || {});
            var debounceAttr = $input.attr('data-ffc-autosave-debounce');
            if (debounceAttr && !isNaN(parseInt(debounceAttr, 10))) {
                config.debounce = parseInt(debounceAttr, 10);
            }
            var confirmOff = $input.attr('data-ffc-confirm-off');
            if (confirmOff) {
                config.confirmOff = confirmOff;
            }
            if (isMulti) {
                var $group = $('[' + attr + '="' + key + '"][data-ffc-autosave-multi]');
                $group.not($input).data('ffcAutoSaveBound', true);
                config.$group    = $group;
                config.transform = function () {
                    return collectMultiValues($group);
                };
            }
            window.FFC.Admin.autoSaveField($input, config);
        });
    }

    /**
     * Scan the DOM for autosave-tagged inputs and wire each one.
     *
     * Two attributes, one widget (#1116): `data-ffc-autosave-key` saves a
     * WP option through `ffc_update_setting`, `data-ffc-autosave-form-key`
     * saves per-post meta through `ffc_update_form_meta`. They were two
     * independent implementations until the validity guard of #1114 had to
     * be written twice to reach both.
     *
     * The scan runs at document-ready rather than delegating on `document`,
     * because every field of both kinds is server-rendered in the page it
     * belongs to. Call this again after inserting an autosave field into
     * the DOM — it skips what it already bound.
     */
    function bootAutoSaveFields() {
        bootVariant('data-ffc-autosave-key', settingsBase());
        var formMeta = formMetaBase();
        if (formMeta) {
            bootVariant('data-ffc-autosave-form-key', formMeta);
        }
    }
    window.FFC.Admin.bootAutoSaveFields = bootAutoSaveFields;

    // Generic page-init — any admin page that enqueues this script
    // gets auto-wiring on document-ready. Tabs can also call
    // FFC.Admin.autoSaveField($field, …) directly for custom strings.
    $(bootAutoSaveFields);
}(jQuery));
