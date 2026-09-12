/**
 * Admin user-profile "FFC Custom Data" — collapsible audience sections.
 *
 * Extracted from the inline <script> previously emitted by
 * AdminUserCustomFields::render_section(). Each `.ffc-cf-toggle` heading
 * toggles a `collapsed` class on itself and its `data-target` body, and
 * mirrors the state on `aria-expanded`; Enter/Space activate it for
 * keyboard users. No server-side interpolation — the markup carries the
 * `data-target` ids.
 */
(function () {
	'use strict';

	// `.collapsed` is `display: none`, and constraint validation does not
	// care: an empty required field inside a collapsed section blocks the
	// profile save, reported against a control the operator cannot see or
	// reach. Only `disabled` bars a control, and disabling would drop the
	// field from the POST — so the attribute travels with the section
	// instead (#1120). Two kinds of field are covered: the ones #1120 made
	// required from `is_required`, and the two working-hours time inputs,
	// which have carried `required` since before either.
	function setRequired(body, visible) {
		if (window.FFC && typeof window.FFC.setRequiredWithin === 'function') {
			window.FFC.setRequiredWithin(body, visible);
		}
	}

	function init() {
		var headings = document.querySelectorAll('.ffc-cf-toggle');
		Array.prototype.forEach.call(headings, function (heading) {
			heading.addEventListener('click', function () {
				var targetId    = this.getAttribute('data-target');
				var body        = targetId ? document.getElementById(targetId) : null;
				var isCollapsed = this.classList.toggle('is-collapsed');
				this.setAttribute('aria-expanded', String(!isCollapsed));
				if (body) {
					body.classList.toggle('is-collapsed', isCollapsed);
					setRequired(body, !isCollapsed);
				}
			});
			heading.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					this.click();
				}
			});

			// Sections render expanded, but a future render could ship one
			// collapsed; sync from the markup rather than assume.
			var initialTarget = heading.getAttribute('data-target');
			var initialBody   = initialTarget ? document.getElementById(initialTarget) : null;
			if (initialBody) {
				setRequired(initialBody, !initialBody.classList.contains('is-collapsed'));
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
