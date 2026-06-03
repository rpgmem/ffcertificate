/**
 * FFC Role Capability Editor
 *
 * Settings → User Access → "FFC Roles & Capabilities". Pick a role from the
 * selector; the cataloged capability toggles reflect what that role grants.
 * Toggling a capability persists immediately (per-toggle AJAX, WP_Role
 * add_cap/remove_cap) — a global, retroactive change for every user with the
 * role. Pure DOM (no jQuery); reuses the .ffc-cap-* card markup/styles.
 *
 * @since 6.9.0
 * @package FreeFormCertificate\Admin
 */

(function () {
	'use strict';

	var CFG = window.ffcRoleEditor || {};
	var roleCaps = CFG.roleCaps || {};
	var i18n = CFG.i18n || {};
	var current = '';

	function countGroup(group) {
		var n = group.querySelectorAll('.ffc-role-cap:checked').length;
		var counter = group.querySelector('.ffc-cap-count');
		if (counter) {
			counter.textContent = String(n);
		}
	}

	function refreshCounts(panel) {
		panel.querySelectorAll('.ffc-cap-group').forEach(countGroup);
	}

	// Reflect the selected role's granted caps onto the toggles.
	function applyRole(panel, slug) {
		current = slug;
		var granted = roleCaps[slug] || [];
		panel.querySelectorAll('.ffc-cap-row').forEach(function (row) {
			var cb = row.querySelector('.ffc-role-cap');
			if (!cb) { return; }
			cb.checked = granted.indexOf(row.getAttribute('data-ffc-cap-slug')) !== -1;
			cb.disabled = false;
			var state = row.querySelector('[data-ffc-savestate]');
			if (state) { state.textContent = ''; }
		});
		refreshCounts(panel);
	}

	function showState(row, text) {
		var state = row.querySelector('[data-ffc-savestate]');
		if (!state) { return; }
		state.textContent = text;
		if (text) {
			setTimeout(function () { state.textContent = ''; }, 1500);
		}
	}

	function persist(panel, cb) {
		var row = cb.closest('.ffc-cap-row');
		var slug = row.getAttribute('data-ffc-cap-slug');
		var grant = cb.checked;
		cb.disabled = true;
		var body = new URLSearchParams();
		body.append('action', 'ffc_set_role_cap');
		body.append('nonce', CFG.nonce || '');
		body.append('role', current);
		body.append('cap', slug);
		body.append('grant', grant ? '1' : '0');
		fetch(CFG.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				cb.disabled = false;
				if (!res || !res.success) {
					cb.checked = !grant;
					window.alert(i18n.error || 'Error');
					return;
				}
				// Keep the in-memory map in sync so switching away and back
				// reflects the change without a reload.
				var list = roleCaps[current] || [];
				var idx = list.indexOf(slug);
				if (grant && idx === -1) { list.push(slug); }
				if (!grant && idx !== -1) { list.splice(idx, 1); }
				roleCaps[current] = list;
				showState(row, i18n.saved || 'Saved');
				var group = cb.closest('.ffc-cap-group');
				if (group) { countGroup(group); }
			})
			.catch(function () {
				cb.disabled = false;
				cb.checked = !grant;
				window.alert(i18n.error || 'Error');
			});
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
			var any = false;
			group.querySelectorAll('.ffc-cap-row').forEach(function (row) {
				var name = row.getAttribute('data-ffc-cap-name') || '';
				var slug = row.getAttribute('data-ffc-cap-slug') || '';
				var hit = !q || name.indexOf(q) !== -1 || slug.indexOf(q) !== -1;
				row.hidden = !hit;
				if (hit) { any = true; }
			});
			group.hidden = !any;
			if (q && any) { setCollapsed(group, false); }
		});
	}

	function init() {
		var panel = document.querySelector('.ffc-role-editor');
		if (!panel) { return; }

		CFG = window.ffcRoleEditor || {};
		roleCaps = CFG.roleCaps || {};
		i18n = CFG.i18n || {};

		var select = panel.querySelector('.ffc-role-select');
		if (select) {
			current = select.value;
			select.addEventListener('change', function () {
				applyRole(panel, select.value);
			});
		}

		panel.querySelectorAll('.ffc-role-cap').forEach(function (cb) {
			cb.addEventListener('change', function () {
				if (CFG.ajaxUrl) { persist(panel, cb); }
			});
		});

		panel.querySelectorAll('.ffc-cap-group-h').forEach(function (header) {
			header.addEventListener('click', function () {
				var group = header.closest('.ffc-cap-group');
				if (group) {
					setCollapsed(group, !group.classList.contains('is-collapsed'));
				}
			});
		});

		var search = panel.querySelector('.ffc-cap-search');
		if (search) {
			search.addEventListener('input', function () {
				runSearch(panel, search.value);
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	// Exposed so unit tests can re-init after injecting fresh markup.
	window.ffcRoleEditorInit = init;
})();
