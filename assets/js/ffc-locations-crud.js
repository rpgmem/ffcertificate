/**
 * Per-row inline CRUD for the geofence locations table in
 * Settings → Geolocation. Saves edits without a full page reload via
 * the ffc_location_save / ffc_location_delete AJAX endpoints.
 *
 * Per-row save is triggered by:
 *   - blur on any .ffc-location-field input that's now non-empty.
 *   - change on the default GPS / default IP radios.
 *
 * Delete: click on .ffc-location-delete → confirm() → AJAX delete →
 * row is removed from the DOM.
 *
 * Add: explicit "Add location" button reads the .ffc-location-new-field
 * inputs, posts ffc_location_save with no id, appends a new row using
 * the response payload, clears the form fields.
 *
 * @since 6.5.4
 */
(function ($) {
    'use strict';

    if (!window.FFC || typeof FFC.request !== 'function') {
        return;
    }

    var settings = window.ffcLocationsCrud || {};
    var nonces = settings.nonces || {};
    var strings = settings.strings || {};

    function badge($row) {
        return $row.find('.ffc-autosave-badge').first();
    }

    function setBadge($badge, state, text) {
        $badge
            .removeClass('ffc-autosave-badge--saving ffc-autosave-badge--saved ffc-autosave-badge--error')
            .addClass('ffc-autosave-badge--' + state)
            .text(text || '')
            .removeAttr('hidden');
    }

    function hideBadgeLater($badge) {
        setTimeout(function () { $badge.attr('hidden', 'hidden').text(''); }, 1800);
    }

    function rowPayload($row) {
        var data = {};
        $row.find('.ffc-location-field').each(function () {
            data[$(this).data('field')] = $(this).val();
        });
        data.default_gps = $row.find('.ffc-location-default-gps').is(':checked') ? '1' : '0';
        data.default_ip = $row.find('.ffc-location-default-ip').is(':checked') ? '1' : '0';
        return data;
    }

    function saveRow($row) {
        var id = $row.data('location-id');
        if (!id) { return; }
        var payload = rowPayload($row);
        payload.id = id;

        var $b = badge($row);
        setBadge($b, 'saving', strings.saving || 'Saving…');
        FFC.request('ffc_location_save', payload, { nonce: nonces.save })
            .then(function (data) {
                // Server may have coerced values (e.g. radius minimums);
                // reflect them in the row so the admin sees the canonical
                // value persisted.
                if (data && data.location) {
                    $row.find('.ffc-location-field').each(function () {
                        var key = $(this).data('field');
                        if (data.location[key] !== undefined) {
                            $(this).val(data.location[key]);
                        }
                    });
                }
                setBadge($b, 'saved', strings.saved || 'Saved');
                hideBadgeLater($b);
            })
            .catch(function (err) {
                setBadge($b, 'error', err && err.message ? err.message : (strings.error || 'Save failed'));
            });
    }

    function deleteRow($row) {
        var id = $row.data('location-id');
        if (!id) { return; }
        if (!window.confirm(strings.confirmDelete || 'Delete this location?')) {
            return;
        }
        var $b = badge($row);
        setBadge($b, 'saving', strings.deleting || 'Deleting…');
        FFC.request('ffc_location_delete', { id: id }, { nonce: nonces.delete })
            .then(function () { $row.remove(); })
            .catch(function (err) {
                setBadge($b, 'error', err && err.message ? err.message : (strings.error || 'Delete failed'));
            });
    }

    function addRow() {
        var $newRow = $('#ffc-location-new-row');
        var payload = {};
        var hasName = false;
        $newRow.find('.ffc-location-new-field').each(function () {
            var key = $(this).data('field');
            var val = $(this).val();
            payload[key] = val;
            if ('name' === key && val) { hasName = true; }
        });
        if (!hasName) {
            return;
        }

        var $b = badge($newRow);
        setBadge($b, 'saving', strings.saving || 'Saving…');
        FFC.request('ffc_location_save', payload, { nonce: nonces.save })
            .then(function (data) {
                if (!data || !data.location) {
                    setBadge($b, 'error', strings.error || 'Save failed');
                    return;
                }
                appendNewRow(data.location);
                $newRow.find('.ffc-location-new-field').val('');
                setBadge($b, 'saved', strings.saved || 'Saved');
                hideBadgeLater($b);
            })
            .catch(function (err) {
                setBadge($b, 'error', err && err.message ? err.message : (strings.error || 'Save failed'));
            });
    }

    function appendNewRow(loc) {
        var id = loc.id;
        // Build the row HTML mirroring the server-side render — kept
        // intentionally small. We only emit the structural attributes
        // the per-row save/delete handlers depend on; the rest matches
        // what the loaded page would have produced.
        var nm = String(loc.name || '');
        var lat = String(loc.lat == null ? '' : loc.lat);
        var lng = String(loc.lng == null ? '' : loc.lng);
        var radius = String(loc.radius == null ? '' : loc.radius);
        var deleteText = strings.deleteText || 'Delete';

        var $row = $(
            '<tr class="ffc-location-row" data-location-id="' + escAttr(id) + '">' +
                '<td>' +
                    '<input type="text" name="ffc_locations[' + escAttr(id) + '][name]" value="' + escAttr(nm) + '" class="regular-text ffc-location-field" data-field="name" required>' +
                '</td>' +
                '<td><input type="number" name="ffc_locations[' + escAttr(id) + '][lat]" value="' + escAttr(lat) + '" step="any" min="-90" max="90" style="width: 120px;" class="ffc-location-field" data-field="lat" required></td>' +
                '<td><input type="number" name="ffc_locations[' + escAttr(id) + '][lng]" value="' + escAttr(lng) + '" step="any" min="-180" max="180" style="width: 120px;" class="ffc-location-field" data-field="lng" required></td>' +
                '<td><input type="number" name="ffc_locations[' + escAttr(id) + '][radius]" value="' + escAttr(radius) + '" step="any" min="1" style="width: 100px;" class="ffc-location-field" data-field="radius" required></td>' +
                '<td><input type="radio" name="ffc_location_default_gps" class="ffc-location-default-gps" value="' + escAttr(id) + '"></td>' +
                '<td><input type="radio" name="ffc_location_default_ip" class="ffc-location-default-ip" value="' + escAttr(id) + '"></td>' +
                '<td>' +
                    '<button type="button" class="button button-small ffc-location-delete">' + escHtml(deleteText) + '</button>' +
                    '<span class="ffc-autosave-badge" hidden></span>' +
                '</td>' +
            '</tr>'
        );
        // Drop the "No locations yet" placeholder if present.
        $('tr.ffc-locations-empty-row').remove();
        $('.ffc-locations-none-row').before($row);
    }

    function escAttr(v) {
        return String(v).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function escHtml(v) {
        return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    $(function () {
        // Per-row edits: save on blur of any of the four numeric/text fields.
        $(document).on('blur', '.ffc-location-row .ffc-location-field', function () {
            saveRow($(this).closest('.ffc-location-row'));
        });
        // Default-radio change saves the now-current owner of the flag.
        $(document).on('change', '.ffc-location-row .ffc-location-default-gps, .ffc-location-row .ffc-location-default-ip', function () {
            saveRow($(this).closest('.ffc-location-row'));
        });
        // Delete.
        $(document).on('click', '.ffc-location-delete', function () {
            deleteRow($(this).closest('.ffc-location-row'));
        });
        // Add.
        $(document).on('click', '#ffc-location-add', function () {
            addRow();
        });
    });
}(jQuery));
