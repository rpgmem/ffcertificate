/**
 * Certificate template "Template fields" rows (#2581): add a row from the
 * `<template>`, remove a row. Rows post as `ffc_template_fields[<n>][…]`; the
 * server drops a row without a key, so an empty added row is harmless.
 */
(function ($) {
	'use strict';

	var next = Date.now();

	$(document).on('click', '.ffc-template-field-add', function (e) {
		e.preventDefault();
		var template = document.getElementById('ffc-template-field-row');
		var $rows = $('.ffc-template-fields-rows');
		if (!template || !$rows.length) {
			return;
		}
		next += 1;
		var html = template.innerHTML.split('__i__').join(String(next));
		var $row = $($.parseHTML(html.trim())).filter('tr');
		$rows.append($row);
		$row.find('.ffc-template-field-key').trigger('focus');
	});

	$(document).on('click', '.ffc-template-field-remove', function (e) {
		e.preventDefault();
		var $row = $(this).closest('.ffc-template-field-row');
		var $focus = $row.next('.ffc-template-field-row').find('.ffc-template-field-key');
		if (!$focus.length) {
			$focus = $('.ffc-template-field-add');
		}
		$row.remove();
		$focus.first().trigger('focus');
	});
})(jQuery);
