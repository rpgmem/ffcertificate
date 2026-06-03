/**
 * FFC User Capabilities
 *
 * Interactions for the grouped per-user capability manager on the WordPress
 * user-edit / profile screen: grant/revoke-all presets, live search across
 * label + slug, collapsible group cards, copy-slug, and a live per-group
 * "granted" counter. Pure DOM (no jQuery); markup is rendered server-side by
 * AdminUserCapabilities and carries the data-* hooks this script reads.
 *
 * @since 6.9.0
 * @package FreeFormCertificate\Admin
 */

(function () {
	'use strict';

	function countGroup(group) {
		var checked = group.querySelectorAll('.ffc-cap-checkbox:checked').length;
		var counter = group.querySelector('.ffc-cap-count');
		if (counter) {
			counter.textContent = String(checked);
		}
	}

	function refreshAllCounts(panel) {
		panel.querySelectorAll('.ffc-cap-group').forEach(countGroup);
	}

	function applyPreset(panel, grant) {
		panel.querySelectorAll('.ffc-cap-checkbox').forEach(function (cb) {
			cb.checked = grant;
		});
		refreshAllCounts(panel);
	}

	function setCollapsed(group, collapsed) {
		group.classList.toggle('is-collapsed', collapsed);
		var header = group.querySelector('.ffc-cap-group-h');
		if (header) {
			header.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
		}
	}

	function runSearch(panel, raw) {
		var q = raw.trim().toLowerCase();
		panel.querySelectorAll('.ffc-cap-group').forEach(function (group) {
			var anyVisible = false;
			group.querySelectorAll('.ffc-cap-row').forEach(function (row) {
				var name = row.getAttribute('data-ffc-cap-name') || '';
				var slug = row.getAttribute('data-ffc-cap-slug') || '';
				var hit = !q || name.indexOf(q) !== -1 || slug.indexOf(q) !== -1;
				row.hidden = !hit;
				if (hit) {
					anyVisible = true;
				}
			});
			group.hidden = !anyVisible;
			// A non-empty query auto-expands the groups that still have hits so
			// matches inside collapsed admin cards are not missed.
			if (q && anyVisible) {
				setCollapsed(group, false);
			}
		});
	}

	function copySlug(btn) {
		var slug = btn.getAttribute('data-ffc-copy') || '';
		if (slug && navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(slug);
		}
		var prev = btn.textContent;
		btn.textContent = '✓';
		setTimeout(function () {
			btn.textContent = prev;
		}, 900);
	}

	function init() {
		var panel = document.querySelector('.ffc-cap-panel');
		if (!panel) {
			return;
		}

		panel.querySelectorAll('[data-ffc-preset]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				applyPreset(panel, btn.getAttribute('data-ffc-preset') === 'all');
			});
		});

		var search = panel.querySelector('.ffc-cap-search');
		if (search) {
			search.addEventListener('input', function () {
				runSearch(panel, search.value);
			});
		}

		panel.querySelectorAll('.ffc-cap-group-h').forEach(function (header) {
			header.addEventListener('click', function () {
				var group = header.closest('.ffc-cap-group');
				if (group) {
					setCollapsed(group, !group.classList.contains('is-collapsed'));
				}
			});
		});

		panel.querySelectorAll('.ffc-cap-copy').forEach(function (btn) {
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				copySlug(btn);
			});
		});

		panel.querySelectorAll('.ffc-cap-checkbox').forEach(function (cb) {
			cb.addEventListener('change', function () {
				var group = cb.closest('.ffc-cap-group');
				if (group) {
					countGroup(group);
				}
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	// Exposed so unit tests can re-init after injecting fresh markup.
	window.ffcUserPermissionsInit = init;
})();
