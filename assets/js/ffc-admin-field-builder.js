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
    var TEXT_TYPES = ['text', 'textarea'];
    var RANGE_TYPES = ['number', 'date'];

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
        $row.find('[data-ffc-types]').each(function() {
            var types = String($(this).attr('data-ffc-types')).split(' ');
            $(this).toggleClass('ffc-hidden', types.indexOf(type) === -1);
        });
        $row.find('.ffc-type-desc').text(typeDescription(type));
        applyQuiz($row, type);
    }

    /**
     * Show the quiz settings a row's type and choices call for (#1730).
     */
    function applyQuiz($row, type) {
        type = type || String($row.find('.ffc-field-type-selector').val() || '');
        var $block = $row.find('.ffc-field-quiz');
        var scorable = String($block.attr('data-ffc-quiz-types') || '').split(' ').indexOf(type) !== -1;
        var on = quizOn() && scorable;
        var scored = on && $block.find('.ffc-quiz-scored').is(':checked');
        var isChoice = CHOICE_TYPES.indexOf(type) !== -1;
        var mode = isChoice ? String($block.find('.ffc-quiz-mode').val()) : '';
        var textNone = TEXT_TYPES.indexOf(type) !== -1 && $block.find('.ffc-quiz-text').val() === 'none';

        $block.toggleClass('ffc-hidden', !on);
        $block.find('.ffc-field-quiz-body').toggleClass('ffc-hidden', !scored);
        $row.find('.ffc-choice-quiz').each(function() {
            $(this).toggleClass('ffc-hidden', !(scored && isChoice && $(this).attr('data-ffc-quiz-mode') === mode));
        });
        $block.find('.ffc-quiz-points-setting').toggleClass('ffc-hidden', (isChoice && mode === 'points') || textNone);
        $block.find('[data-ffc-types]').each(function() {
            $(this).toggleClass('ffc-hidden', String($(this).attr('data-ffc-types')).split(' ').indexOf(type) === -1);
        });
        $block.find('[data-ffc-quiz-help="correct"]').toggleClass('ffc-hidden', !(isChoice && mode === 'correct'));
        $block.find('[data-ffc-quiz-help="points"]').toggleClass('ffc-hidden', !(isChoice && mode === 'points'));
        $block.find('[data-ffc-quiz-help="range"]').toggleClass('ffc-hidden', RANGE_TYPES.indexOf(type) === -1);
        checkMaxSelect($row);
    }

    /**
     * A checkbox scored by its correct options cannot cap the selections
     * below how many are correct: nobody could answer it right.
     */
    function checkMaxSelect($row) {
        var $max = $row.find('input[name$="[max_select]"]');
        if (!$max.length || !$max[0].setCustomValidity) {
            return;
        }
        var type = String($row.find('.ffc-field-type-selector').val() || '');
        var limit = parseInt($max.val(), 10);
        var correct = $row.find('.ffc-choice-correct-value').filter(function() {
            return this.value === '1';
        }).length;
        var applies = type === 'checkbox' && quizOn() &&
            $row.find('.ffc-quiz-scored').is(':checked') &&
            $row.find('.ffc-quiz-mode').val() === 'correct';
        var message = '';
        if (applies && limit > 0 && limit < correct) {
            message = (strings().maxSelectBelowCorrect || 'The maximum selections is below the number of correct options (%d).').replace('%d', String(correct));
        }
        $max[0].setCustomValidity(message);
    }

    function refreshQuiz() {
        $('#ffc-fields-container .ffc-field-row').each(function() {
            applyQuiz($(this));
        });
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
        resetChoiceQuiz($row);
        $list.append($row);
        return $row;
    }

    function resetChoiceQuiz($choice) {
        $choice.find('.ffc-choice-correct-value').val('0');
        $choice.find('.ffc-choice-correct-input').prop('checked', false);
        $choice.find('.ffc-choice-points-input').val('');
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
            var $fieldRow = $choice.closest('.ffc-field-row');
            if ($choice.siblings('.ffc-choice-row').length) {
                $choice.remove();
            } else {
                $choice.find('.ffc-choice-input').val('');
                resetChoiceQuiz($choice);
            }
            checkMaxSelect($fieldRow);
        });

        // The visible box drives the aligned 0/1 the server reads.
        $container.on('change', '.ffc-choice-correct-input', function() {
            $(this).closest('.ffc-choice-row').find('.ffc-choice-correct-value').val(this.checked ? '1' : '0');
            checkMaxSelect($(this).closest('.ffc-field-row'));
        });

        $container.on('change', '.ffc-quiz-scored, .ffc-quiz-mode, .ffc-quiz-text', function() {
            applyQuiz($(this).closest('.ffc-field-row'));
        });

        $container.on('input change', 'input[name$="[max_select]"]', function() {
            checkMaxSelect($(this).closest('.ffc-field-row'));
        });

        $(document).off('change.ffcFieldBuilder');
        $(document).on('change.ffcFieldBuilder', '#ffc_quiz_enabled', refreshQuiz);
        refreshQuiz();

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

    /**
     * Lock a row the way the server renders a required field (#1741): the
     * name, the type and the required flag cannot change, and the row
     * cannot be removed. Only the label stays editable.
     */
    function lockRequiredRow($row, field) {
        var index = String($row.attr('data-index'));
        var lockId = 'ffc_field_lock_' + index;
        var $select = $row.find('.ffc-field-type-selector');

        $row.addClass('ffc-field-row-required-tag');
        $row.find('input[name$="[label]"]').first().val(field.label || field.name);
        $row.find('input[name$="[name]"]').first().val(field.name).prop('readonly', true).attr('aria-describedby', lockId);

        // A disabled select posts nothing, so the type travels in a hidden input.
        $select.removeAttr('name').prop('disabled', true).attr('aria-describedby', lockId);
        $('<input type="hidden">').attr('name', 'ffc_fields[' + index + '][type]').val(field.type).insertBefore($select);

        $row.find('.ffc-field-required').prop('checked', true).prop('disabled', true);
        $row.find('.ffc-remove-field').prop('disabled', true).attr('aria-describedby', lockId);

        var $badge = $('<span class="ffc-required-tag-badge"></span>').attr('id', lockId);
        $badge.append('<span class="ffc-icon-lock" aria-hidden="true"></span> ');
        $badge.append(document.createTextNode(strings().requiredField || 'Required field'));
        $row.find('.ffc-field-title').after($badge);
    }

    /**
     * Add every required field the form lacks, locked, and drop the notice.
     */
    function addRequiredFields($button) {
        var fields;
        try {
            fields = JSON.parse(String($button.attr('data-ffc-required-fields') || '[]'));
        } catch (err) {
            fields = [];
        }
        if (!Array.isArray(fields)) {
            return;
        }
        fields.forEach(function(field) {
            if (!field || !field.name) {
                return;
            }
            var $row = addFieldToBuilder(field.type || 'text');
            if ($row) {
                lockRequiredRow($row, field);
            }
        });
        $button.closest('.ffc-required-fields-missing').remove();
    }

    $(document).on('click', '.ffc-add-required-fields', function(e) {
        e.preventDefault();
        addRequiredFields($(this));
    });

    window.FFC = window.FFC || {};
    window.FFC.Admin = window.FFC.Admin || {};
    window.FFC.Admin.FieldBuilder = {
        init: initFormBuilder,
        addField: addFieldToBuilder,
        applyType: applyType,
        refreshQuiz: refreshQuiz,
        addRequiredFields: addRequiredFields
    };

    if (FFC && FFC.registerModule) {
        FFC.registerModule('Admin.FieldBuilder', FFC.version);
    }

})(jQuery, window.FFC);
