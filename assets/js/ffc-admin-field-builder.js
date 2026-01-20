/**
 * FFC Admin - Field Builder Module
 * v3.1.0 - Extracted from ffc-admin.js
 *
 * Handles form field creation, editing, and management in the admin area
 *
 * Dependencies:
 * - jQuery
 * - jQuery UI Sortable
 * - FFC Core (for notifications via window.FFC.Admin.showNotification)
 *
 * @since 3.1.0
 */

(function($, FFC) {
    'use strict';

    // ==========================================================================
    // FIELD BUILDER - Add/Remove Fields
    // ==========================================================================

    var fieldCounter = 0;

    // Get localized strings with fallbacks
    function getFieldTypes() {
        var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};
        return [
            { value: 'text', label: strings.textField || 'Text Field' },
            { value: 'email', label: strings.email || 'Email' },
            { value: 'number', label: strings.number || 'Number' },
            { value: 'textarea', label: strings.textarea || 'Textarea' },
            { value: 'select', label: strings.dropdownSelect || 'Dropdown Select' },
            { value: 'checkbox', label: strings.checkbox || 'Checkbox' },
            { value: 'radio', label: strings.radioButtons || 'Radio Buttons' },
            { value: 'date', label: strings.date || 'Date' }
        ];
    }

    var fieldTypes = getFieldTypes();

    // Initialize Form Builder
    function initFormBuilder() {
        console.log('[FFC] Initializing Form Builder...');

        if ($('#ffc-fields-container').length === 0) {
            console.log('[FFC] Fields container not found');
            return;
        }

        // Count existing fields
        fieldCounter = $('#ffc-fields-container .ffc-field-row').length;
        console.log('[FFC] Found', fieldCounter, 'existing fields');

        // Add Field button - Show dropdown menu
        $(document).on('click', '.ffc-add-field', function(e) {
            e.preventDefault();
            console.log('[FFC] Add Field clicked');

            showFieldTypeMenu($(this));
        });

        // Remove Field button
        $(document).on('click', '.ffc-remove-field', function(e) {
            e.preventDefault();

            // Get localized string or fallback to English
            var confirmMsg = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.confirmDeleteField)
                ? ffc_ajax.strings.confirmDeleteField
                : 'Remove this field?';

            if (confirm(confirmMsg)) {
                $(this).closest('.ffc-field-row').fadeOut(300, function() {
                    $(this).remove();
                    updateFieldsJSON();
                    console.log('[FFC] Field removed');
                });
            }
        });

        // Update JSON when fields change
        $('#ffc-fields-container').on('change input', 'input, select, textarea', function() {
            updateFieldsJSON();
        });

        // Make fields sortable
        if ($.fn.sortable) {
            $('#ffc-fields-container').sortable({
                handle: '.ffc-sort-handle',
                placeholder: 'ffc-field-placeholder',
                update: function() {
                    updateFieldsJSON();
                    console.log('[FFC] Fields reordered');
                }
            });
        }
    }

    // Show field type dropdown menu
    function showFieldTypeMenu($button) {
        // Remove existing menu
        $('.ffc-field-type-menu').remove();

        // Create menu
        var $menu = $('<div class="ffc-field-type-menu"></div>');
        var headerText = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.chooseFieldType)
            ? ffc_ajax.strings.chooseFieldType
            : 'Choose Field Type:';
        $menu.append('<div class="ffc-menu-header">' + headerText + '</div>');

        var $list = $('<ul></ul>');
        fieldTypes.forEach(function(type) {
            var $item = $('<li data-type="' + type.value + '">' + type.label + '</li>');
            $item.on('click', function() {
                addFieldToBuilder(type.value);
                $menu.remove();
            });
            $list.append($item);
        });

        $menu.append($list);

        // Position menu below button
        var btnOffset = $button.offset();
        var btnHeight = $button.outerHeight();

        $menu.css({
            position: 'absolute',
            top: (btnOffset.top + btnHeight + 5) + 'px',
            left: btnOffset.left + 'px',
            zIndex: 99999,
            background: '#fff',
            border: '1px solid #ccc',
            borderRadius: '4px',
            boxShadow: '0 2px 8px rgba(0,0,0,0.15)',
            minWidth: '200px'
        });

        $('body').append($menu);

        // Close menu when clicking outside
        setTimeout(function() {
            $(document).one('click', function() {
                $menu.remove();
            });
        }, 100);

        console.log('[FFC] Field type menu shown');
    }

    // Add field to builder
    function addFieldToBuilder(fieldType) {
        fieldCounter++;

        // Get localized strings with fallbacks
        var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};
        var removeText = strings.remove || 'Remove';
        var fieldTypeText = strings.fieldType || 'Field Type:';
        var labelText = strings.label || 'Label:';
        var fieldLabelPlaceholder = strings.fieldLabel || 'Field Label';
        var nameVariableText = strings.nameVariable || 'Name (variable):';
        var fieldNamePlaceholder = strings.fieldName || 'field_name';
        var requiredText = strings.required || 'Required:';
        var optionsText = strings.options || 'Options:';
        var separateWithCommasPlaceholder = strings.separateWithCommas || 'Separate with commas';

        var fieldHtml = '<div class="ffc-field-row" data-index="' + fieldCounter + '">';
        fieldHtml += '  <div class="ffc-field-row-header">';
        fieldHtml += '    <span class="ffc-sort-handle">';
        fieldHtml += '      <span class="dashicons dashicons-menu"></span>';
        fieldHtml += '      <span class="ffc-field-title"><strong>' + fieldType.toUpperCase() + '</strong></span>';
        fieldHtml += '    </span>';
        fieldHtml += '    <button type="button" class="button button-link-delete ffc-remove-field">' + removeText + '</button>';
        fieldHtml += '  </div>';
        fieldHtml += '  <div class="ffc-field-row-body">';
        fieldHtml += '    <table class="form-table">';
        fieldHtml += '      <tr>';
        fieldHtml += '        <th><label>' + fieldTypeText + '</label></th>';
        fieldHtml += '        <td><select class="ffc-field-type" name="ffc_fields[' + fieldCounter + '][type]">';

        fieldTypes.forEach(function(type) {
            var selected = type.value === fieldType ? ' selected' : '';
            fieldHtml += '<option value="' + type.value + '"' + selected + '>' + type.label + '</option>';
        });

        fieldHtml += '        </select></td>';
        fieldHtml += '      </tr>';
        fieldHtml += '      <tr>';
        fieldHtml += '        <th><label>' + labelText + '</label></th>';
        fieldHtml += '        <td><input type="text" class="ffc-field-label regular-text" name="ffc_fields[' + fieldCounter + '][label]" placeholder="' + fieldLabelPlaceholder + '"></td>';
        fieldHtml += '      </tr>';
        fieldHtml += '      <tr>';
        fieldHtml += '        <th><label>' + nameVariableText + '</label></th>';
        fieldHtml += '        <td><input type="text" class="ffc-field-name regular-text" name="ffc_fields[' + fieldCounter + '][name]" placeholder="' + fieldNamePlaceholder + '"></td>';
        fieldHtml += '      </tr>';
        fieldHtml += '      <tr>';
        fieldHtml += '        <th><label>' + requiredText + '</label></th>';
        fieldHtml += '        <td><input type="checkbox" class="ffc-field-required" name="ffc_fields[' + fieldCounter + '][required]" value="1"></td>';
        fieldHtml += '      </tr>';

        // Additional options for select/radio/checkbox
        if (fieldType === 'select' || fieldType === 'radio' || fieldType === 'checkbox') {
            fieldHtml += '      <tr>';
            fieldHtml += '        <th><label>' + optionsText + '</label></th>';
            fieldHtml += '        <td><textarea class="ffc-field-options large-text" name="ffc_fields[' + fieldCounter + '][options]" rows="3" placeholder="' + separateWithCommasPlaceholder + '"></textarea></td>';
            fieldHtml += '      </tr>';
        }

        fieldHtml += '    </table>';
        fieldHtml += '  </div>';
        fieldHtml += '</div>';

        $('#ffc-fields-container').append(fieldHtml);
        updateFieldsJSON();

        console.log('[FFC] Field added:', fieldType);
    }

    // Update hidden JSON field with current fields
    function updateFieldsJSON() {
        var fields = [];

        $('#ffc-fields-container .ffc-field-row').each(function() {
            var $row = $(this);
            var field = {
                type: $row.find('.ffc-field-type').val(),
                label: $row.find('.ffc-field-label').val(),
                name: $row.find('.ffc-field-name').val(),
                required: $row.find('.ffc-field-required').is(':checked'),
                options: $row.find('.ffc-field-options').val()
            };
            fields.push(field);
        });

        // Find the JSON field (try different possible IDs/names)
        var $jsonField = $('#ffc-form-fields-json');
        if (!$jsonField.length) {
            $jsonField = $('input[name="ffc_form_fields"], textarea[name="ffc_form_fields"]');
        }

        if ($jsonField.length) {
            $jsonField.val(JSON.stringify(fields));
            console.log('[FFC] Fields JSON updated:', fields.length, 'fields');
        } else {
            console.warn('[FFC] JSON field not found');
        }
    }

    // ==========================================================================
    // PUBLIC API - Export functions
    // ==========================================================================

    // Initialize FFC.Admin namespace if not exists
    window.FFC = window.FFC || {};
    window.FFC.Admin = window.FFC.Admin || {};
    window.FFC.Admin.FieldBuilder = {
        init: initFormBuilder,
        addField: addFieldToBuilder,
        updateJSON: updateFieldsJSON
    };

    // Register module
    if (FFC.registerModule) {
        FFC.registerModule('Admin.FieldBuilder', '3.1.0');
    }

    console.log('[FFC Admin Field Builder] Module loaded v3.1.0');

})(jQuery, window.FFC);
