/**
 * Custom Fields Admin - Drag-and-drop field management for audiences
 *
 * @since 4.11.0
 * @package FreeFormCertificate
 */
(function ($) {
    'use strict';

    var newFieldIndex = 0;

    /**
     * Initialize when DOM is ready
     */
    $(function () {
        initSortable();
        bindEvents();
    });

    /**
     * Initialize jQuery UI Sortable on the fields list
     */
    function initSortable() {
        $('#ffc-custom-fields-list').sortable({
            handle: '.ffc-field-handle',
            placeholder: 'ffc-sortable-placeholder',
            opacity: 0.7,
            cursor: 'move',
            tolerance: 'pointer'
        });
    }

    /**
     * Bind all event handlers
     */
    function bindEvents() {
        // Add new field
        $('#ffc-add-custom-field').on('click', addNewField);

        // Save all fields
        $('#ffc-save-custom-fields').on('click', saveFields);

        // Replicate option lists to descendant audiences
        $('#ffc-replicate-field-options').on('click', replicateFieldOptions);

        // Delete field
        $(document).on('click', '.ffc-field-delete', deleteField);

        // Toggle details
        $(document).on('click', '.ffc-field-toggle-details', toggleDetails);

        // Toggle options/regex visibility based on type/format
        $(document).on('change', '.ffc-field-type', onFieldTypeChange);
        $(document).on('change', '.ffc-field-format', onFormatChange);

        // Toggle row inactive style
        $(document).on('change', '.ffc-field-active', onActiveToggle);
    }

    /**
     * Add a new empty field row from template
     */
    function addNewField() {
        newFieldIndex++;
        var template = wp.template('ffc-custom-field-row');
        var html = template({ index: newFieldIndex });
        $('#ffc-custom-fields-list').append(html);

        // Show details by default for new fields
        var $row = $('#ffc-custom-fields-list .ffc-custom-field-row:last');
        $row.find('.ffc-field-details-row').show();

        // Refresh sortable
        $('#ffc-custom-fields-list').sortable('refresh');
    }

    /**
     * Collect all fields data and save via AJAX
     */
    function saveFields() {
        var $btn = $('#ffc-save-custom-fields');
        var $status = $('#ffc-custom-fields-status');
        var audienceId = $('#ffc-custom-fields-container').data('audience-id');

        if (!audienceId) {
            return;
        }

        // Flush any TinyMCE (wp_editor) instances back to their textareas so
        // the acknowledgment HTML we read below reflects unsaved edits.
        if (window.tinymce && typeof window.tinymce.triggerSave === 'function') {
            window.tinymce.triggerSave();
        }

        var fields = [];
        $('#ffc-custom-fields-list .ffc-custom-field-row').each(function (idx) {
            var $row = $(this);
            var fieldId = $row.data('field-id');
            var source  = $row.data('field-source') || 'custom';

            // Collect choices from textarea
            var choicesText = $row.find('.ffc-field-choices').val() || '';
            var choices = choicesText.split('\n').filter(function (c) { return c.trim() !== ''; });

            // Collect dependent_select groups from the synced hidden input
            // (kept in sync by ffc-divisao-setor-editor.js). null for rows
            // without a groups editor — the server ignores non-arrays.
            var groups = null;
            var $dsMap = $row.find('.ffc-ds-map-json');
            if ($dsMap.length) {
                try { groups = JSON.parse($dsMap.val() || '{}'); } catch (e) { groups = {}; }
            }

            fields.push({
                id: fieldId,
                source: source,
                sort_order: idx,
                label: $row.find('.ffc-field-label').val(),
                key: $row.find('.ffc-field-key').val(),
                type: $row.find('.ffc-field-type').val(),
                group: $row.find('.ffc-field-group').val() || '',
                is_required: $row.find('.ffc-field-required').is(':checked') ? 1 : 0,
                is_active: $row.find('.ffc-field-active').is(':checked') ? 1 : 0,
                is_sensitive: $row.find('.ffc-field-sensitive').is(':checked') ? 1 : 0,
                profile_key: $row.find('.ffc-field-profile-key').val() || '',
                mask: $row.find('.ffc-field-mask').val() || '',
                choices: choices,
                groups: groups,
                html: $row.find('.ffc-field-html-container textarea').val() || '',
                help_text: $row.find('.ffc-field-help').val(),
                format: $row.find('.ffc-field-format').val(),
                custom_regex: $row.find('.ffc-field-regex').val(),
                custom_regex_message: $row.find('.ffc-field-regex-msg').val()
            });
        });

        $btn.prop('disabled', true);
        $status.text(ffcAudienceAdmin.strings.saving || 'Saving...').removeClass('ffc-status-error ffc-status-success');

        FFC.request(
            'ffc_save_custom_fields',
            { audience_id: audienceId, fields: JSON.stringify(fields) },
            { nonce: ffcAudienceAdmin.adminNonce, ajaxUrl: ffcAudienceAdmin.ajaxUrl }
        )
            .then(function () {
                $status.text(ffcAudienceAdmin.strings.saved || 'Saved!').addClass('ffc-status-success');
                // Reload page to show updated field IDs
                setTimeout(function () { window.location.reload(); }, 800);
            })
            .catch(function (err) {
                var msg = (err && err.fromServer && err.message) || ffcAudienceAdmin.strings.error || 'Error';
                $status.text(msg).addClass('ffc-status-error');
            })
            .then(function () {
                // .finally equivalent — always re-enable the button.
                $btn.prop('disabled', false);
            });
    }

    /**
     * Replicate this audience's standard-field option lists to all
     * descendant audiences (children + grandchildren), with confirmation.
     */
    function replicateFieldOptions() {
        var $btn = $('#ffc-replicate-field-options');
        var $status = $('#ffc-custom-fields-status');
        var audienceId = $('#ffc-custom-fields-container').data('audience-id');

        if (!audienceId) {
            return;
        }

        var confirmMsg = (ffcAudienceAdmin.strings && ffcAudienceAdmin.strings.confirmReplicate)
            || 'Copy this audience\'s option lists to all child and grandchild audiences? This overwrites their current lists.';
        if (!window.confirm(confirmMsg)) {
            return;
        }

        $btn.prop('disabled', true);
        $status.text(ffcAudienceAdmin.strings.saving || 'Saving...').removeClass('ffc-status-error ffc-status-success');

        FFC.request(
            'ffc_replicate_field_options',
            { audience_id: audienceId },
            { nonce: ffcAudienceAdmin.adminNonce, ajaxUrl: ffcAudienceAdmin.ajaxUrl }
        )
            .then(function (data) {
                var msg = (data && data.message) || ffcAudienceAdmin.strings.saved || 'Saved!';
                $status.text(msg).addClass('ffc-status-success');
            })
            .catch(function (err) {
                var msg = (err && err.fromServer && err.message) || ffcAudienceAdmin.strings.error || 'Error';
                $status.text(msg).addClass('ffc-status-error');
            })
            .then(function () {
                $btn.prop('disabled', false);
            });
    }

    /**
     * Delete a field (with confirmation)
     */
    function deleteField() {
        var $row = $(this).closest('.ffc-custom-field-row');
        var fieldId = $row.data('field-id');
        var source = $row.data('field-source') || 'custom';
        var isNew = String(fieldId).indexOf('new_') === 0;

        if (source === 'standard') {
            alert(ffcAudienceAdmin.strings.cannotDeleteStandard || 'Standard fields cannot be deleted. Deactivate instead.');
            return;
        }

        if (!confirm(ffcAudienceAdmin.strings.confirmDelete || 'Are you sure?')) {
            return;
        }

        if (isNew) {
            // New unsaved field — just remove from DOM
            $row.fadeOut(200, function () { $(this).remove(); });
            return;
        }

        // Existing field — delete via AJAX
        FFC.request(
            'ffc_delete_custom_field',
            { field_id: fieldId },
            { nonce: ffcAudienceAdmin.adminNonce, ajaxUrl: ffcAudienceAdmin.ajaxUrl }
        )
            .then(function () {
                $row.fadeOut(200, function () { $(this).remove(); });
            })
            .catch(function (err) {
                var msg = (err && err.fromServer && err.message)
                    || ffcAudienceAdmin.strings.error
                    || 'Error';
                alert(msg);
            });
    }

    /**
     * Toggle the details row visibility
     */
    function toggleDetails() {
        var $row = $(this).closest('.ffc-custom-field-row');
        $row.find('.ffc-field-details-row').slideToggle(200);
    }

    /**
     * Show/hide options textarea based on field type
     */
    function onFieldTypeChange() {
        var $row = $(this).closest('.ffc-custom-field-row');
        var type = $(this).val();
        var isSelect = (type === 'select');
        var isDependent = (type === 'dependent_select');
        var isWorkingHours = (type === 'working_hours');
        var isAcknowledgment = (type === 'acknowledgment');
        $row.find('.ffc-field-options-container').toggle(isSelect);
        $row.find('.ffc-field-groups-container').toggle(isDependent);
        $row.find('.ffc-field-html-container').toggle(isAcknowledgment);
        // Hide format validation for types that don't support it
        $row.find('.ffc-field-format').closest('.ffc-field-detail-row').toggle(!isWorkingHours);
    }

    /**
     * Show/hide regex inputs based on format selection
     */
    function onFormatChange() {
        var $row = $(this).closest('.ffc-custom-field-row');
        var format = $(this).val();
        $row.find('.ffc-field-regex, .ffc-field-regex-msg').toggle(format === 'custom_regex');
    }

    /**
     * Toggle inactive visual state
     */
    function onActiveToggle() {
        var $row = $(this).closest('.ffc-custom-field-row');
        $row.toggleClass('ffc-field-inactive', !$(this).is(':checked'));
    }

})(jQuery);
