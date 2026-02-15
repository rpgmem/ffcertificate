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

        var fields = [];
        $('#ffc-custom-fields-list .ffc-custom-field-row').each(function () {
            var $row = $(this);
            var fieldId = $row.data('field-id');

            // Collect choices from textarea
            var choicesText = $row.find('.ffc-field-choices').val() || '';
            var choices = choicesText.split('\n').filter(function (c) { return c.trim() !== ''; });

            fields.push({
                id: fieldId,
                label: $row.find('.ffc-field-label').val(),
                key: $row.find('.ffc-field-key').val(),
                type: $row.find('.ffc-field-type').val(),
                is_required: $row.find('.ffc-field-required').is(':checked') ? 1 : 0,
                is_active: $row.find('.ffc-field-active').is(':checked') ? 1 : 0,
                choices: choices,
                help_text: $row.find('.ffc-field-help').val(),
                format: $row.find('.ffc-field-format').val(),
                custom_regex: $row.find('.ffc-field-regex').val(),
                custom_regex_message: $row.find('.ffc-field-regex-msg').val()
            });
        });

        $btn.prop('disabled', true);
        $status.text(ffcAudienceAdmin.strings.saving || 'Saving...').removeClass('ffc-status-error ffc-status-success');

        $.post(ffcAudienceAdmin.ajaxUrl, {
            action: 'ffc_save_custom_fields',
            nonce: ffcAudienceAdmin.adminNonce,
            audience_id: audienceId,
            fields: JSON.stringify(fields)
        })
        .done(function (response) {
            if (response.success) {
                $status.text(ffcAudienceAdmin.strings.saved || 'Saved!').addClass('ffc-status-success');
                // Reload page to show updated field IDs
                setTimeout(function () {
                    window.location.reload();
                }, 800);
            } else {
                $status.text(response.data.message || 'Error').addClass('ffc-status-error');
            }
        })
        .fail(function () {
            $status.text(ffcAudienceAdmin.strings.error || 'Error').addClass('ffc-status-error');
        })
        .always(function () {
            $btn.prop('disabled', false);
        });
    }

    /**
     * Delete a field (with confirmation)
     */
    function deleteField() {
        var $row = $(this).closest('.ffc-custom-field-row');
        var fieldId = $row.data('field-id');
        var isNew = String(fieldId).indexOf('new_') === 0;

        if (!confirm(ffcAudienceAdmin.strings.confirmDelete || 'Are you sure?')) {
            return;
        }

        if (isNew) {
            // New unsaved field — just remove from DOM
            $row.fadeOut(200, function () { $(this).remove(); });
            return;
        }

        // Existing field — delete via AJAX
        $.post(ffcAudienceAdmin.ajaxUrl, {
            action: 'ffc_delete_custom_field',
            nonce: ffcAudienceAdmin.adminNonce,
            field_id: fieldId
        })
        .done(function (response) {
            if (response.success) {
                $row.fadeOut(200, function () { $(this).remove(); });
            } else {
                alert(response.data.message || 'Error deleting field.');
            }
        })
        .fail(function () {
            alert(ffcAudienceAdmin.strings.error || 'Error');
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
        var isWorkingHours = (type === 'working_hours');
        $row.find('.ffc-field-options-container').toggle(isSelect);
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
