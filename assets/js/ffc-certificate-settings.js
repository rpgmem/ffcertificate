/**
 * Settings → Certificates: the "Required data" table (#1741, #1759).
 *
 * Adds a row from the <template> under a fresh index and removes a row.
 * The Email and {{auth_code}} rows render their Remove button disabled,
 * so they cannot go.
 *
 * The table autosaves as one field: the rows container carries
 * `data-ffc-autosave-key="required_data"`, and the value the autosave
 * widget sends for it is the whole table, read here, as JSON. Every edit,
 * switch, added row and removed row lands in that one field.
 */
(function ($) {
	'use strict';

	/**
	 * The table as the server stores it: one object per row.
	 *
	 * @param {jQuery} $rows The rows container.
	 * @returns {string} JSON list of {name, label, type, form, html}.
	 */
	function serializeTable($rows) {
		var rows = [];
		$rows.find('.ffc-required-data-row').each(function () {
			var $row = $(this);
			rows.push({
				name: String($row.find('.ffc-required-data-name').val() || ''),
				label: String($row.find('.ffc-required-data-label').val() || ''),
				type: String($row.find('.ffc-required-data-type').val() || 'text'),
				form: $row.find('.ffc-required-data-form').is(':checked'),
				html: $row.find('.ffc-required-data-html').is(':checked'),
			});
		});
		return JSON.stringify(rows);
	}

	// Registered before the autosave widget boots (it boots at
	// document-ready), so the rows container is saved as a table rather
	// than as the empty value a <tbody> has.
	if (window.FFC && window.FFC.Admin) {
		window.FFC.Admin.autoSaveTransforms = window.FFC.Admin.autoSaveTransforms || {};
		window.FFC.Admin.autoSaveTransforms.required_data = serializeTable;
	}

	function addRow() {
		var $rows = $('#ffc-required-data-rows');
		var template = document.getElementById('ffc-required-data-template');
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

	$(document).on('click', '#ffc-required-data-add', function (e) {
		e.preventDefault();
		addRow();
	});

	$(document).on('click', '.ffc-required-data-remove', function (e) {
		e.preventDefault();
		if ($(this).prop('disabled')) {
			return;
		}
		var $rows = $(this).closest('#ffc-required-data-rows');
		$(this).closest('.ffc-required-data-row').remove();
		// The row is gone, so no field of it can announce the change.
		$rows.trigger('change');
	});
})(jQuery);
