/**
 * Settings → Certificates: the required-field table (#1741).
 *
 * Adds a row from the <template> under a fresh index and removes a row.
 * The Email row renders its Remove button disabled, so it cannot go.
 */
(function ($) {
	'use strict';

	function addRow() {
		var $rows = $('#ffc-required-fields-rows');
		var template = document.getElementById('ffc-required-field-template');
		if (!$rows.length || !template) {
			return;
		}
		var index = parseInt($rows.attr('data-ffc-next-index'), 10) || 0;
		var html = template.innerHTML.replace(/__index__/g, String(index));
		$rows.attr('data-ffc-next-index', String(index + 1));
		var $row = $(html.trim());
		$rows.append($row);
		$row.find('input').first().trigger('focus');
	}

	$(document).on('click', '#ffc-required-field-add', function (e) {
		e.preventDefault();
		addRow();
	});

	$(document).on('click', '.ffc-required-field-remove', function (e) {
		e.preventDefault();
		if ($(this).prop('disabled')) {
			return;
		}
		$(this).closest('.ffc-required-field-row').remove();
	});
})(jQuery);
