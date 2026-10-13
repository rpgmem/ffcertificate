/**
 * FFC Admin - Field Builder Module
 *
 * Adds, removes, reorders and reshapes the rows of the certificate form's
 * field builder. A new row is a copy of the server-rendered template row, so
 * the markup a row is born with and the markup it is saved and re-rendered
 * with are the same (#1729) — the builder used to assemble its own, different
 * row out of strings, and every option the server row gained had to be
 * written a second time here.
 *
 * Dependencies:
 * - jQuery
 * - jQuery UI Sortable
 *
 * @since 3.1.0
 */

(function($, FFC) {
    'use strict';

    var CHOICE_TYPES = ['select', 'radio', 'checkbox'];

    var fieldCounter = 0;

    function strings() {
        return (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};
    }

    // Field types — the ordered {value,label} list localized from the canonical
    // PHP source (FormEditorBuilderMetabox::field_types_ordered), so the menu
    // offers the same types, in the same order, as the row's own <select>.
    function getFieldTypes() {
        if (typeof ffc_ajax !== 'undefined' && Array.isArray(ffc_ajax.fieldTypes) && ffc_ajax.fieldTypes.length) {
            return ffc_ajax.fieldTypes;
        }
        return [
            { value: 'checkbox', label: 'Checkbox' },
            { value: 'date', label: 'Date' },
            { value: 'select', label: 'Dropdown Select' },
            { value: 'email', label: 'Email' },
            { value: 'number', label: 'Number' },
            { value: 'radio', label: 'Radio Buttons' },
            { value: 'text', label: 'Text' },
            { value: 'textarea', label: 'Textarea' },
            { value: 'embed', label: 'Embed (Media)' },
            { value: 'hidden', label: 'Hidden Field' },
            { value: 'info', label: 'Info Block' }
        ];
    }

    function typeDescription(type) {
        var map = (typeof ffc_ajax !== 'undefined' && ffc_ajax.fieldTypeDescriptions) ? ffc_ajax.fieldTypeDescriptions : {};
        return map[type] || '';
    }

    function quizOn() {
        return $('#ffc_quiz_enabled').is(':checked');
    }

    /**
     * Show the parts of a row that belong to its type, hide the rest.
     */
    function applyType($row, type) {
        var isInfo = type === 'info';
        var isEmbed = type === 'embed';
        var isChoice = CHOICE_TYPES.indexOf(type) !== -1;

        $row.find('.ffc-content-field').toggleClass('ffc-hidden', !isInfo);
        $row.find('.ffc-embed-field').toggleClass('ffc-hidden', !isEmbed);
        $row.find('.ffc-hidden-value-field').toggleClass('ffc-hidden', type !== 'hidden');
        $row.find('.ffc-standard-row').toggleClass('ffc-hidden', isInfo || isEmbed);
        $row.find('.ffc-options-field').toggleClass('ffc-hidden', !isChoice);
        $row.find('.ffc-quiz-points').toggleClass('ffc-hidden', !(isChoice && quizOn()));
        $row.find('[data-ffc-types]').each(function() {
            var types = String($(this).attr('data-ffc-types')).split(' ');
            $(this).toggleClass('ffc-hidden', types.indexOf(type) === -1);
        });
        $row.find('.ffc-type-desc').text(typeDescription(type));
    }

    // A number above every index already on the page, so a new row's
    // `ffc_fields[N]` never collides with a saved one.
    function nextIndex() {
        var max = -1;
        $('#ffc-fields-container .ffc-field-row').each(function() {
            var n = parseInt($(this).attr('data-index'), 10);
            if (!isNaN(n) && n > max) {
                max = n;
            }
        });
        return Math.max(max + 1, fieldCounter);
    }

    function addChoice($list, value) {
        var $first = $list.children('.ffc-choice-row').first();
        if (!$first.length) {
            return null;
        }
        var $row = $first.clone();
        $row.find('.ffc-choice-input').val(value || '');
        $list.append($row);
        return $row;
    }

    function makeChoicesSortable($scope) {
        if ($.fn.sortable) {
            $scope.find('.ffc-choice-list').sortable({ handle: '.ffc-choice-handle', axis: 'y' });
        }
    }

    function initFormBuilder() {
        var $container = $('#ffc-fields-container');
        if ($container.length === 0) {
            return;
        }

        fieldCounter = 0;
        fieldCounter = nextIndex();

        // Namespaced and replaced, so a second init() does not stack handlers.
        $(document).off('click.ffcFieldBuilder');
        $(document).on('click.ffcFieldBuilder', '.ffc-add-field', function(e) {
            e.preventDefault();
            showFieldTypeMenu($(this));
        });

        $(document).on('click.ffcFieldBuilder', '.ffc-remove-field', function(e) {
            e.preventDefault();
            if (confirm(strings().confirmDeleteField || 'Remove this field?')) {
                $(this).closest('.ffc-field-row').fadeOut(300, function() {
                    $(this).remove();
                });
            }
        });

        $container.on('change', '.ffc-field-type-selector', function() {
            applyType($(this).closest('.ffc-field-row'), $(this).val());
        });

        $container.on('click', '.ffc-choice-add', function(e) {
            e.preventDefault();
            var $row = addChoice($(this).siblings('.ffc-choice-list'), '');
            if ($row) {
                $row.find('.ffc-choice-input').trigger('focus');
            }
        });

        // The last row is emptied rather than removed, so there is always one
        // to copy from.
        $container.on('click', '.ffc-choice-remove', function(e) {
            e.preventDefault();
            var $choice = $(this).closest('.ffc-choice-row');
            if ($choice.siblings('.ffc-choice-row').length) {
                $choice.remove();
            } else {
                $choice.find('.ffc-choice-input').val('');
            }
        });

        // Enter in an option adds the next one instead of submitting the post.
        $container.on('keydown', '.ffc-choice-input', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var $next = addChoice($(this).closest('.ffc-choice-list'), '');
                if ($next) {
                    $next.insertAfter($(this).closest('.ffc-choice-row'));
                    $next.find('.ffc-choice-input').trigger('focus');
                }
            }
        });

        if ($.fn.sortable) {
            $container.sortable({
                handle: '.ffc-sort-handle',
                placeholder: 'ffc-field-placeholder'
            });
        }
        makeChoicesSortable($container);
    }

    function showFieldTypeMenu($button) {
        $('.ffc-field-type-menu').remove();

        var $menu = $('<div class="ffc-field-type-menu"></div>');
        $menu.append($('<div class="ffc-menu-header"></div>').text(strings().chooseFieldType || 'Choose Field Type:'));

        var $list = $('<ul></ul>');
        getFieldTypes().forEach(function(type) {
            var $option = $('<button type="button" class="ffc-field-type-option"></button>')
                .attr('data-type', type.value)
                .text(type.label);
            $option.on('click', function() {
                addFieldToBuilder(type.value);
                $menu.remove();
            });
            $list.append($('<li></li>').append($option));
        });
        $menu.append($list);

        var btnOffset = $button.offset();
        $menu.css({
            position: 'absolute',
            top: (btnOffset.top + $button.outerHeight() + 5) + 'px',
            left: btnOffset.left + 'px'
        });

        $('body').append($menu);
        $menu.find('.ffc-field-type-option').first().trigger('focus');

        setTimeout(function() {
            $(document).one('click', function() {
                $menu.remove();
            });
        }, 100);
    }

    /**
     * Append a row of the given type, copied from the template row.
     *
     * @return {jQuery|null} The new row, or null when the page has no template.
     */
    function addFieldToBuilder(fieldType) {
        var $template = $('.ffc-field-template .ffc-field-row').first();
        if (!$template.length) {
            return null;
        }

        var index = String(nextIndex());
        fieldCounter = parseInt(index, 10) + 1;

        var $row = $template.clone();
        $row.attr('data-index', index);
        $row.find('[name], [id], [for]').each(function() {
            var el = this;
            ['name', 'id', 'for'].forEach(function(attr) {
                var v = el.getAttribute(attr);
                if (v && v.indexOf('TEMPLATE') !== -1) {
                    el.setAttribute(attr, v.split('TEMPLATE').join(index));
                }
            });
        });

        $('#ffc-fields-container').append($row);
        $row.find('.ffc-field-type-selector').val(fieldType);
        applyType($row, fieldType);
        makeChoicesSortable($row);
        $row.find('input[type="text"]').first().trigger('focus');
        return $row;
    }

    window.FFC = window.FFC || {};
    window.FFC.Admin = window.FFC.Admin || {};
    window.FFC.Admin.FieldBuilder = {
        init: initFormBuilder,
        addField: addFieldToBuilder,
        applyType: applyType
    };

    if (FFC && FFC.registerModule) {
        FFC.registerModule('Admin.FieldBuilder', FFC.version);
    }

})(jQuery, window.FFC);
