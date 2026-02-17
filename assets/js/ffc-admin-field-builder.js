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
            { value: 'date', label: strings.date || 'Date' },
            { value: 'info', label: strings.infoBlock || 'Info Block' },
            { value: 'embed', label: strings.embedMedia || 'Embed (Media)' }
        ];
    }

    var fieldTypes = getFieldTypes();

    // Initialize Form Builder
    function initFormBuilder() {
        if ($('#ffc-fields-container').length === 0) {
            return;
        }

        // Count existing fields
        fieldCounter = $('#ffc-fields-container .ffc-field-row').length;

        // Add Field button - Show dropdown menu
        $(document).on('click', '.ffc-add-field', function(e) {
            e.preventDefault();
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
                });
            }
        });

        // Update JSON when fields change
        $('#ffc-fields-container').on('change input', 'input, select, textarea', function() {
            updateFieldsJSON();
        });

        // Toggle info/embed vs standard rows when field type changes (JS-built rows)
        $('#ffc-fields-container').on('change', '.ffc-field-type', function() {
            var $row = $(this).closest('.ffc-field-row');
            var val = $(this).val();
            var isInfo = val === 'info';
            var isEmbed = val === 'embed';
            var isDisplayOnly = isInfo || isEmbed;
            $row.find('.ffc-content-row').toggle(isInfo);
            $row.find('.ffc-embed-row').toggle(isEmbed);
            $row.find('.ffc-standard-row').toggle(!isDisplayOnly);
            $row.find('.ffc-options-field').toggle(val === 'select' || val === 'radio' || val === 'checkbox');
        });

        // Toggle info/embed vs standard rows when field type changes (PHP-rendered rows)
        $('#ffc-fields-container').on('change', '.ffc-field-type-selector', function() {
            var $row = $(this).closest('.ffc-field-row');
            var val = $(this).val();
            var isInfo = val === 'info';
            var isEmbed = val === 'embed';
            var isDisplayOnly = isInfo || isEmbed;
            $row.find('.ffc-content-field').toggleClass('ffc-hidden', !isInfo);
            $row.find('.ffc-embed-field').toggleClass('ffc-hidden', !isEmbed);
            $row.find('.ffc-standard-row').toggleClass('ffc-hidden', isDisplayOnly);
            $row.find('.ffc-options-field').toggleClass('ffc-hidden', !(val === 'select' || val === 'radio' || val === 'checkbox'));
        });

        // Make fields sortable
        if ($.fn.sortable) {
            $('#ffc-fields-container').sortable({
                handle: '.ffc-sort-handle',
                placeholder: 'ffc-field-placeholder',
                update: function() {
                    updateFieldsJSON();
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
        var contentText = strings.content || 'Content:';
        var contentPlaceholder = strings.contentPlaceholder || 'Text to display. Supports <b>, <i>, <a>.';
        var titleOptionalText = strings.titleOptional || 'Title (optional):';
        var embedUrlText = strings.embedUrl || 'Media URL:';
        var embedUrlPlaceholder = strings.embedUrlPlaceholder || 'https://www.youtube.com/watch?v=... or image URL';
        var captionOptionalText = strings.captionOptional || 'Caption (optional):';

        var isInfo = fieldType === 'info';
        var isEmbed = fieldType === 'embed';
        var isDisplayOnly = isInfo || isEmbed;

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

        // Content row (info only)
        fieldHtml += '      <tr class="ffc-content-row"' + (isInfo ? '' : ' style="display:none"') + '>';
        fieldHtml += '        <th><label>' + contentText + '</label></th>';
        fieldHtml += '        <td><textarea class="ffc-field-content large-text" name="ffc_fields[' + fieldCounter + '][content]" rows="4" placeholder="' + contentPlaceholder + '"></textarea></td>';
        fieldHtml += '      </tr>';

        // Embed URL row (embed only)
        fieldHtml += '      <tr class="ffc-embed-row"' + (isEmbed ? '' : ' style="display:none"') + '>';
        fieldHtml += '        <th><label>' + embedUrlText + '</label></th>';
        fieldHtml += '        <td><input type="url" class="ffc-field-embed-url regular-text" name="ffc_fields[' + fieldCounter + '][embed_url]" placeholder="' + embedUrlPlaceholder + '"></td>';
        fieldHtml += '      </tr>';

        // Label row (title for info, caption for embed, label for others)
        fieldHtml += '      <tr>';
        var currentLabelText = isInfo ? titleOptionalText : (isEmbed ? captionOptionalText : labelText);
        fieldHtml += '        <th><label>' + currentLabelText + '</label></th>';
        fieldHtml += '        <td><input type="text" class="ffc-field-label regular-text" name="ffc_fields[' + fieldCounter + '][label]" placeholder="' + fieldLabelPlaceholder + '"></td>';
        fieldHtml += '      </tr>';

        // Name row (standard fields only)
        fieldHtml += '      <tr class="ffc-standard-row"' + (isDisplayOnly ? ' style="display:none"' : '') + '>';
        fieldHtml += '        <th><label>' + nameVariableText + '</label></th>';
        fieldHtml += '        <td><input type="text" class="ffc-field-name regular-text" name="ffc_fields[' + fieldCounter + '][name]" placeholder="' + fieldNamePlaceholder + '"></td>';
        fieldHtml += '      </tr>';

        // Required row (standard fields only)
        fieldHtml += '      <tr class="ffc-standard-row"' + (isDisplayOnly ? ' style="display:none"' : '') + '>';
        fieldHtml += '        <th><label>' + requiredText + '</label></th>';
        fieldHtml += '        <td><input type="checkbox" class="ffc-field-required" name="ffc_fields[' + fieldCounter + '][required]" value="1"></td>';
        fieldHtml += '      </tr>';

        // Additional options for select/radio/checkbox
        if (fieldType === 'select' || fieldType === 'radio' || fieldType === 'checkbox') {
            fieldHtml += '      <tr>';
            fieldHtml += '        <th><label>' + optionsText + '</label></th>';
            fieldHtml += '        <td><textarea class="ffc-field-options large-text" name="ffc_fields[' + fieldCounter + '][options]" rows="3" placeholder="' + separateWithCommasPlaceholder + '"></textarea>';
            // Quiz points row (initially hidden, toggled by quiz checkbox)
            var quizPointsText = strings.quizPoints || 'Points per option:';
            var quizPointsPlaceholder = strings.quizPointsPlaceholder || 'Ex: 0, 10, 0';
            var quizOn = typeof jQuery !== 'undefined' && jQuery('#ffc_quiz_enabled').is(':checked');
            fieldHtml += '          <div class="ffc-quiz-points' + (quizOn ? '' : ' ffc-hidden') + '" style="margin-top:8px">';
            fieldHtml += '            <label style="font-weight:500;font-size:13px">' + quizPointsText + '</label>';
            fieldHtml += '            <input type="text" class="ffc-field-points regular-text" name="ffc_fields[' + fieldCounter + '][points]" placeholder="' + quizPointsPlaceholder + '" style="width:100%;margin-top:4px">';
            fieldHtml += '          </div>';
            fieldHtml += '        </td>';
            fieldHtml += '      </tr>';
        }

        fieldHtml += '    </table>';
        fieldHtml += '  </div>';
        fieldHtml += '</div>';

        $('#ffc-fields-container').append(fieldHtml);
        updateFieldsJSON();
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
                options: $row.find('.ffc-field-options').val(),
                content: $row.find('.ffc-field-content').val() || '',
                embed_url: $row.find('.ffc-field-embed-url').val() || '',
                points: $row.find('.ffc-field-points').val() || ''
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
        FFC.registerModule('Admin.FieldBuilder', FFC.version);
    }

})(jQuery, window.FFC);
