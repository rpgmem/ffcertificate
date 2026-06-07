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

	function init() {
		var headings = document.querySelectorAll('.ffc-cf-toggle');
		Array.prototype.forEach.call(headings, function (heading) {
			heading.addEventListener('click', function () {
				var targetId    = this.getAttribute('data-target');
				var body        = targetId ? document.getElementById(targetId) : null;
				var isCollapsed = this.classList.toggle('collapsed');
				this.setAttribute('aria-expanded', String(!isCollapsed));
				if (body) {
					body.classList.toggle('collapsed', isCollapsed);
				}
			});
			heading.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					this.click();
				}
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
