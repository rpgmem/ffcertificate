/**
 * FFC User Capabilities
 *
 * Interactions for the grouped per-user permission manager on the WordPress
 * user-edit / profile screen:
 *   - role preset chips: hover to illuminate the capabilities a role grants,
 *     click to assign/remove the role (applied immediately via AJAX), and the
 *     capability grid recomputes live (role-granted caps lock as "Role");
 *   - grant/revoke-all presets, live search across label + slug, collapsible
 *     group cards, copy-slug, and a live per-group "granted" counter.
 *
 * Pure DOM (no jQuery). Markup is rendered server-side by AdminUserCapabilities
 * and carries the data-* hooks this script reads; role data + AJAX wiring come
 * from the localized `ffcUserPerms` object.
 *
 * @since 6.9.0
 * @package FreeFormCertificate\Admin
 */

(function () {
	'use strict';

	var CFG = window.ffcUserPerms || {};
	var roleCaps = CFG.roleCaps || {};
	var i18n = CFG.i18n || {};

	// Live state, seeded per init() from the server-rendered markup.
	var assigned = new Set();
	var userGranted = new Set();

	function originLabel(kind) {
		if (kind === 'user') { return i18n.user || 'User'; }
		if (kind === 'role') { return i18n.role || 'Role'; }
		return i18n.none || '—';
	}

	function setOrigin(el, kind) {
		if (!el) { return; }
		el.className = 'ffc-cap-origin ffc-cap-origin--' + kind;
		el.textContent = originLabel(kind);
	}

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

	function roleCapSet() {
		var s = new Set();
		assigned.forEach(function (role) {
			(roleCaps[role] || []).forEach(function (cap) { s.add(cap); });
		});
		return s;
	}

	// Reflect current role + per-user state onto one capability row.
	function refreshRow(row, roleSet) {
		var slug = row.getAttribute('data-ffc-cap-slug');
		var cb = row.querySelector('.ffc-cap-checkbox');
		var tg = row.querySelector('.ffc-toggle');
		var origin = row.querySelector('[data-ffc-origin]');
		if (!cb) { return; }
		if (roleSet.has(slug)) {
			cb.checked = true;
			cb.disabled = true; // disabled = not submitted = no redundant per-user grant
			if (tg) { tg.classList.add('ffc-cap-toggle--byrole'); }
			setOrigin(origin, 'role');
		} else {
			cb.disabled = false;
			cb.checked = userGranted.has(slug);
			if (tg) { tg.classList.remove('ffc-cap-toggle--byrole'); }
			setOrigin(origin, cb.checked ? 'user' : 'none');
		}
	}

	function recompute(panel) {
		var roleSet = roleCapSet();
		panel.querySelectorAll('.ffc-cap-row').forEach(function (row) {
			refreshRow(row, roleSet);
		});
		refreshAllCounts(panel);
	}

	function illuminate(panel, caps, label) {
		var set = new Set(caps);
		panel.querySelectorAll('.ffc-cap-row').forEach(function (row) {
			var on = set.has(row.getAttribute('data-ffc-cap-slug'));
			row.classList.toggle('is-lit', on);
			var tag = row.querySelector('[data-ffc-role-tag]');
			if (tag) { tag.textContent = (on && label) ? label : ''; }
		});
	}

	function toggleRole(panel, chip) {
		var role = chip.getAttribute('data-ffc-role');
		var willAssign = !assigned.has(role);
		chip.disabled = true;
		var body = new URLSearchParams();
		body.append('action', 'ffc_toggle_user_role');
		body.append('nonce', CFG.nonce || '');
		body.append('user_id', String(CFG.userId || 0));
		body.append('role', role);
		body.append('assign', willAssign ? '1' : '0');
		fetch(CFG.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				chip.disabled = false;
				if (!res || !res.success) { window.alert(i18n.error || 'Error'); return; }
				if (willAssign) { assigned.add(role); } else { assigned.delete(role); }
				chip.classList.toggle('is-on', willAssign);
				chip.setAttribute('aria-pressed', willAssign ? 'true' : 'false');
				recompute(panel);
			})
			.catch(function () { chip.disabled = false; window.alert(i18n.error || 'Error'); });
	}

	function applyPreset(panel, grant) {
		panel.querySelectorAll('.ffc-cap-checkbox').forEach(function (cb) {
			if (cb.disabled) { return; } // role-locked rows are not touched by presets
			cb.checked = grant;
			var row = cb.closest('.ffc-cap-row');
			if (row) {
				var slug = row.getAttribute('data-ffc-cap-slug');
				if (grant) { userGranted.add(slug); } else { userGranted.delete(slug); }
				setOrigin(row.querySelector('[data-ffc-origin]'), grant ? 'user' : 'none');
			}
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

		// Re-read the localized config at init time (not just module load) so
		// the markup + config can be injected together — e.g. by unit tests.
		CFG = window.ffcUserPerms || {};
		roleCaps = CFG.roleCaps || {};
		i18n = CFG.i18n || {};

		// Seed live state from the freshly-rendered markup.
		assigned = new Set(Array.isArray(CFG.assigned) ? CFG.assigned : []);
		userGranted = new Set();
		panel.querySelectorAll('.ffc-cap-row').forEach(function (row) {
			if (row.getAttribute('data-ffc-user-granted') === '1') {
				userGranted.add(row.getAttribute('data-ffc-cap-slug'));
			}
		});

		// Role preset chips (only when localized role data is present).
		panel.querySelectorAll('.ffc-cap-role[data-ffc-role]').forEach(function (chip) {
			var role = chip.getAttribute('data-ffc-role');
			chip.addEventListener('mouseenter', function () {
				var nm = chip.querySelector('.ffc-cap-role-nm');
				illuminate(panel, roleCaps[role] || [], nm ? nm.textContent : '');
			});
			chip.addEventListener('mouseleave', function () { illuminate(panel, [], ''); });
			chip.addEventListener('focus', function () {
				var nm = chip.querySelector('.ffc-cap-role-nm');
				illuminate(panel, roleCaps[role] || [], nm ? nm.textContent : '');
			});
			chip.addEventListener('blur', function () { illuminate(panel, [], ''); });
			chip.addEventListener('click', function () {
				if (CFG.ajaxUrl) { toggleRole(panel, chip); }
			});
		});

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
				if (cb.disabled) { return; }
				var row = cb.closest('.ffc-cap-row');
				if (row) {
					var slug = row.getAttribute('data-ffc-cap-slug');
					if (cb.checked) { userGranted.add(slug); } else { userGranted.delete(slug); }
					setOrigin(row.querySelector('[data-ffc-origin]'), cb.checked ? 'user' : 'none');
				}
				var group = cb.closest('.ffc-cap-group');
				if (group) { countGroup(group); }
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
