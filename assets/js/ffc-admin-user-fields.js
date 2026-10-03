/**
 * The FFC section of the wp-admin user screen (profile.php / user-edit.php).
 *
 * Wires the shared field behaviours (ffc-field-behaviours.js) onto the custom
 * fields, and turns a format error into the browser's own validation message,
 * so the WordPress "Update Profile" button refuses an invalid CPF or e-mail
 * the way it refuses an empty required field -- this screen has no error
 * slot of its own to write into.
 *
 * @since 6.33.0
 * @package FreeFormCertificate
 */
(function ($) {
    'use strict';

    var S = (window.ffcAdminUserFields && window.ffcAdminUserFields.strings) || {};

    function checkFormat($row, input) {
        var format = $row.attr('data-format') || '';
        if (!format || !input.setCustomValidity) {
            return;
        }
        input.setCustomValidity(window.FFC.Fields.formatError(
            input.value,
            format,
            S,
            { pattern: $row.attr('data-regex'), message: $row.attr('data-regex-msg') }
        ));
    }

    function init() {
        var $container = $('#ffc-user-custom-fields');
        if (!$container.length || !window.FFC || !window.FFC.Fields) {
            return;
        }

        window.FFC.Fields.initMasks($container);
        window.FFC.Fields.initDependentSelects($container, S);
        window.FFC.Fields.initDualPost($container, S.dualPostShowValue);

        $container.find('tr[data-format]:not([data-format=""])').find('input:not([type="hidden"]), textarea').each(function () {
            var $row = $(this).closest('tr[data-format]');
            checkFormat($row, this);
            $(this).on('input change', function () {
                checkFormat($row, this);
            });
        });
    }

    $(init);

    window.FFC = window.FFC || {};
    window.FFC.AdminUserFields = { init: init };

})(jQuery);
