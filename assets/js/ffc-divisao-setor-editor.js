/**
 * Divisão → Setor Map Editor
 *
 * Admin-only nested repeater for the reregistration divisao_setor map,
 * rendered in Settings → Reregistration. Each division holds a list of
 * sectors; the editor keeps a hidden JSON input in sync on every change.
 *
 * HTML contract:
 *   <input type="hidden" id="TARGET_ID" name="..." value='{JSON}'>
 *   <div class="ffc-ds-editor" data-target="TARGET_ID">
 *     <div class="ffc-ds-divisions">
 *       <div class="ffc-ds-division">
 *         <div class="ffc-ds-division-head">
 *           <input class="ffc-ds-division-name">
 *           <button class="ffc-ds-division-remove">
 *         </div>
 *         <div class="ffc-ds-sectors">
 *           <div class="ffc-ds-sector">
 *             <input class="ffc-ds-sector-name">
 *             <button class="ffc-ds-sector-remove">
 *           </div>
 *         </div>
 *         <button class="ffc-ds-sector-add">
 *       </div>
 *     </div>
 *     <button class="ffc-ds-division-add">
 *   </div>
 *
 * @since 6.7.8
 * @package FreeFormCertificate
 */
(function ($) {
    'use strict';

    var i18n = (window.ffcDivisaoSetorEditor && window.ffcDivisaoSetorEditor.strings) || {};

    function t(key, fallback) {
        return (i18n && i18n[key]) ? i18n[key] : fallback;
    }

    function sectorRow(value) {
        return '<div class="ffc-ds-sector">' +
            '<input type="text" class="ffc-ds-sector-name regular-text" value="' + escapeAttr(value || '') + '" placeholder="' + escapeAttr(t('departmentName', 'Department name')) + '">' +
            '<button type="button" class="button-link ffc-ds-sector-remove" aria-label="' + escapeAttr(t('removeSector', 'Remove department')) + '">×</button>' +
            '</div>';
    }

    function divisionBlock() {
        return '<div class="ffc-ds-division">' +
            '<div class="ffc-ds-division-head">' +
            '<input type="text" class="ffc-ds-division-name regular-text" value="" placeholder="' + escapeAttr(t('divisionName', 'Division name')) + '">' +
            '<button type="button" class="button button-link-delete ffc-ds-division-remove">' + escapeHtml(t('removeDivision', 'Remove division')) + '</button>' +
            '</div>' +
            '<div class="ffc-ds-sectors">' + sectorRow('') + '</div>' +
            '<button type="button" class="button button-small ffc-ds-sector-add">' + escapeHtml(t('addSector', '+ Add Department')) + '</button>' +
            '</div>';
    }

    function escapeAttr(s) {
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function escapeHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function syncHidden($editor) {
        var targetId = $editor.data('target');
        var $hidden = $('#' + targetId);
        if (!$hidden.length) {
            $hidden = $('[name="' + targetId + '"]');
        }

        var map = {};
        $editor.find('.ffc-ds-division').each(function () {
            var $div = $(this);
            var name = String($div.find('.ffc-ds-division-name').val() || '').trim();
            if (name === '') {
                return;
            }
            var sectors = [];
            $div.find('.ffc-ds-sector-name').each(function () {
                var sName = String($(this).val() || '').trim();
                if (sName !== '' && sectors.indexOf(sName) === -1) {
                    sectors.push(sName);
                }
            });
            map[name] = sectors;
        });

        $hidden.val(JSON.stringify(map));
    }

    // Add division.
    $(document).on('click', '.ffc-ds-division-add', function (e) {
        e.preventDefault();
        var $editor = $(this).closest('.ffc-ds-editor');
        $editor.find('.ffc-ds-divisions').append(divisionBlock());
        syncHidden($editor);
    });

    // Remove division.
    $(document).on('click', '.ffc-ds-division-remove', function (e) {
        e.preventDefault();
        var $editor = $(this).closest('.ffc-ds-editor');
        $(this).closest('.ffc-ds-division').remove();
        syncHidden($editor);
    });

    // Add sector.
    $(document).on('click', '.ffc-ds-sector-add', function (e) {
        e.preventDefault();
        var $editor = $(this).closest('.ffc-ds-editor');
        $(this).closest('.ffc-ds-division').find('.ffc-ds-sectors').append(sectorRow(''));
        syncHidden($editor);
    });

    // Remove sector.
    $(document).on('click', '.ffc-ds-sector-remove', function (e) {
        e.preventDefault();
        var $editor = $(this).closest('.ffc-ds-editor');
        $(this).closest('.ffc-ds-sector').remove();
        syncHidden($editor);
    });

    // Sync on any text change.
    $(document).on('input', '.ffc-ds-division-name, .ffc-ds-sector-name', function () {
        syncHidden($(this).closest('.ffc-ds-editor'));
    });

})(jQuery);
