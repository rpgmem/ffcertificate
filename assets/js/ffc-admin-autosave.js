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
     * Attach auto-save behaviour to a field.
     *
     * @param {jQuery} $field
     * @param {Object} config
     * @param {string} config.key                      Allowlisted setting key.
     * @param {Function} [config.transform]            Custom value extractor.
     * @param {number} [config.debounce=400]           Debounce window in ms.
     * @param {jQuery} [config.$badge]                 Pre-existing badge node.
     * @param {Object} [config.strings]                Custom strings.
     * @param {string} [config.strings.saving='Saving…']
     * @param {string} [config.strings.saved='Saved']
     * @param {string} [config.strings.error='Save failed']
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

        function performSave() {
            pendingTimer = null;
            setBadgeState($badge, 'saving', saving);
            var value = extractValue($field, config.transform);
            // Endpoint expects a nonce verified against `ffc_update_setting`.
            // The global FFC.config.nonce is created for a different
            // action (ffc_admin_pdf_nonce), so we pull the right one
            // from window.ffcAdminAutosave (localized by enqueue_autosave_infra).
            var payload = { key: config.key, value: value };
            var autosaveCfg = window.ffcAdminAutosave;
            if (autosaveCfg && autosaveCfg.nonce) {
                payload.nonce = autosaveCfg.nonce;
            }
            window.FFC.request('ffc_update_setting', payload)
                .then(function () {
                    setBadgeState($badge, 'saved', saved);
                    lingerTimer = setTimeout(function () { hideBadge($badge); }, SAVED_LINGER);
                })
                .catch(function (err) {
                    setBadgeState($badge, 'error', (err && err.message) ? err.message : errorTxt);
                });
        }

        // For multi-checkbox groups the change event must fire from any
        // sibling in the group, so attach the listener to the whole group.
        var $changeSource = config.$group && config.$group.length ? config.$group : $field;
        $changeSource.on('change.ffcAutoSave input.ffcAutoSave', scheduleSave);

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
     * Scan the DOM for inputs tagged with `data-ffc-autosave-key` and
     * wire each one to {@link autoSaveField}. Idempotent — fields that
     * have already been bound carry an `ffcAutoSaveBound` data flag and
     * are skipped on subsequent calls.
     */
    function bootAutoSaveFields() {
        var multiBound = {};
        $('[data-ffc-autosave-key]').each(function () {
            var $input = $(this);
            if ($input.data('ffcAutoSaveBound')) {
                return;
            }
            var key      = $input.data('ffc-autosave-key');
            var isMulti  = $input.attr('data-ffc-autosave-multi') !== undefined;

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

            var config = { key: key };
            var debounceAttr = $input.attr('data-ffc-autosave-debounce');
            if (debounceAttr && !isNaN(parseInt(debounceAttr, 10))) {
                config.debounce = parseInt(debounceAttr, 10);
            }
            if (isMulti) {
                var $group = $('[data-ffc-autosave-key="' + key + '"][data-ffc-autosave-multi]');
                $group.not($input).data('ffcAutoSaveBound', true);
                config.$group    = $group;
                config.transform = function () {
                    return collectMultiValues($group);
                };
            }
            window.FFC.Admin.autoSaveField($input, config);
        });
    }
    window.FFC.Admin.bootAutoSaveFields = bootAutoSaveFields;

    // Generic page-init — any admin page that enqueues this script
    // gets auto-wiring on document-ready. Tabs can also call
    // FFC.Admin.autoSaveField($field, …) directly for custom strings.
    $(bootAutoSaveFields);
}(jQuery));
