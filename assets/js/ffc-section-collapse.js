/**
 * Section collapse helper — wires a "master" toggle to a set of "section"
 * elements so the section hides when the master is off and reappears when
 * the master is on. Establishes a uniform UX pattern across the settings
 * page (Cache / URL Shortener / Rate Limit) and any future toggle group
 * that follows the same idiom.
 *
 * Markup contract:
 *   <input type="checkbox" data-ffc-section-master="my-key" ...>
 *   <tr  data-ffc-section="my-key">...</tr>
 *   <div data-ffc-section="my-key">...</div>
 *
 * Multiple masters with the same key are not supported — pick a unique
 * key per group. Multiple sections per key are fine; all of them follow
 * the same master.
 *
 * @since 6.7.8
 */
(function ($) {
	'use strict';

	/**
	 * Show or hide a master's sections. A hidden section takes its required
	 * fields with it (#2344): constraint validation ignores visibility, so a
	 * cleared required number inside a section the master turned off would
	 * block the save against a field nobody can see.
	 *
	 * @param {jQuery} $master The toggle.
	 */
	function apply($master) {
		var key = $master.attr('data-ffc-section-master');
		var on  = $master.is(':checked');
		var $sections = $('[data-ffc-section="' + key + '"]');
		$sections.toggle(on);
		if (window.FFC && typeof window.FFC.setRequiredWithin === 'function') {
			$sections.each(function () {
				window.FFC.setRequiredWithin($(this), on);
			});
		}
	}

	function applyAll() {
		$('[data-ffc-section-master]').each(function () {
			apply($(this));
		});
	}

	$(document).on('change', '[data-ffc-section-master]', function () {
		apply($(this));
	});

	// Initial state.
	$(applyAll);
}(jQuery));
