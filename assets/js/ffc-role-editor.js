/**
 * FFC Role Capability Editor
 *
 * Settings → User Access → "FFC Roles & Capabilities". Pick a role from the
 * selector; the cataloged capability toggles reflect what that role grants.
 * Toggling a capability persists immediately (per-toggle AJAX, WP_Role
 * add_cap/remove_cap) — a global, retroactive change for every user with the
 * role. Pure DOM (no jQuery); reuses the .ffc-cap-* card markup/styles.
 * A role that differs from its shipped definition shows a bar with
 * "Restore role default" and a tag on each changed row (#2340); the restore
 * asks first through the plugin's dialog (`FFC.overrideConfirm`), and so
 * does granting a capability that reaches personal data, destroys records
 * or opens the activity log.
 *
 * @since 6.9.0
 * @package FreeFormCertificate\Admin
 */

(function () {
	'use strict';

	var CFG = window.ffcRoleEditor || {};
	var roleCaps = CFG.roleCaps || {};
	var roleDefaults = CFG.roleDefaults || {};
	var i18n = CFG.i18n || {};
	var current = '';

	// Fill `%s`, `%d` and `%1$s` / `%2$d` placeholders in order.
	function fill(text, values) {
		var i = 0;
		return String(text || '').replace(/%(\d+)\$[sd]|%[sd]/g, function (whole, position) {
			var index = position ? parseInt(position, 10) - 1 : i++;
			return values[index] !== undefined ? String(values[index]) : whole;
		});
	}

	// How the selected role differs from its shipped definition (#2340):
	// null for a role the plugin does not define.
	function diffOf(slug) {
		if (!Object.prototype.hasOwnProperty.call(roleDefaults, slug)) {
			return null;
		}
		var held = roleCaps[slug] || [];
		var shipped = roleDefaults[slug] || [];
		return {
			added: held.filter(function (c) { return shipped.indexOf(c) === -1; }),
			removed: shipped.filter(function (c) { return held.indexOf(c) === -1; })
		};
	}

	function capName(panel, slug) {
		var name = panel.querySelector('.ffc-cap-row[data-ffc-cap-slug="' + slug + '"] .ffc-cap-row-name');
		return name && name.firstChild ? String(name.firstChild.textContent).trim() : slug;
	}

	// The bar above the list and the tag on each changed row.
	function renderDiff(panel) {
		var diff = diffOf(current);
		var added = diff ? diff.added : [];
		var removed = diff ? diff.removed : [];
		panel.querySelectorAll('.ffc-cap-row').forEach(function (row) {
			var tag = row.querySelector('.ffc-role-diff-tag');
			if (!tag) { return; }
			var slug = row.getAttribute('data-ffc-cap-slug');
			var kind = added.indexOf(slug) !== -1 ? 'added' : (removed.indexOf(slug) !== -1 ? 'removed' : '');
			tag.className = 'ffc-role-diff-tag' + (kind ? ' ffc-role-diff-tag--' + kind : '');
			tag.textContent = kind === 'added' ? (i18n.tagAdded || '') : (kind === 'removed' ? (i18n.tagRemoved || '') : '');
			tag.hidden = kind === '';
		});
		var bar = panel.querySelector('.ffc-role-diff');
		if (!bar) { return; }
		var changed = added.length + removed.length > 0;
		bar.hidden = !changed;
		var count = bar.querySelector('.ffc-role-diff-count');
		if (count) {
			count.textContent = changed ? fill(i18n.diffSummary, [added.length, removed.length]) : '';
		}
	}

	function restore(panel, button) {
		var diff = diffOf(current);
		if (!diff || !window.FFC || typeof window.FFC.overrideConfirm !== 'function') {
			return;
		}
		var role = (CFG.roles || {})[current] || {};
		var reasons = [];
		diff.removed.forEach(function (c) { reasons.push(fill(i18n.comesBack, [capName(panel, c)])); });
		diff.added.forEach(function (c) { reasons.push(fill(i18n.isRemoved, [capName(panel, c)])); });
		var strings = {};
		var base = i18n.dialog || {};
		Object.keys(base).forEach(function (k) { strings[k] = base[k]; });
		strings.intro = fill(i18n.restoreIntro, [role.label || current, role.users || 0]);
		var slug = current;
		window.FFC.overrideConfirm({
			strings: strings,
			reasons: reasons,
			agreeCategory: 'caution',
			agreeIcon: 'restore'
		}).then(function (agreed) {
			if (!agreed) { return; }
			button.disabled = true;
			var body = new URLSearchParams();
			body.append('action', 'ffc_restore_role_default');
			body.append('nonce', CFG.nonce || '');
			body.append('role', slug);
			return fetch(CFG.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					button.disabled = false;
					if (!res || !res.success || !res.data) {
						window.FFC.adminNotice('danger', i18n.error || 'Error');
						return;
					}
					roleCaps[slug] = Array.isArray(res.data.caps) ? res.data.caps : [];
					if (current === slug) { applyRole(panel, slug); }
				});
		}).catch(function () {
			button.disabled = false;
			window.FFC.adminNotice('danger', i18n.error || 'Error');
		});
	}

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
		renderDiff(panel);
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
					window.FFC.adminNotice('danger', i18n.error || 'Error');
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
				renderDiff(panel);
			})
			.catch(function () {
				cb.disabled = false;
				cb.checked = !grant;
				window.FFC.adminNotice('danger', i18n.error || 'Error');
			});
	}

	// Granting a capability that reaches personal data, destroys records or
	// opens the activity log asks first, naming who it reaches (#2340).
	// Everything else, and every removal, saves at once.
	function needsConfirm(cb) {
		var row = cb.closest('.ffc-cap-row');
		return !!(cb.checked && row && row.getAttribute('data-ffc-confirm') === '1'
			&& window.FFC && typeof window.FFC.overrideConfirm === 'function');
	}

	function confirmGrant(panel, cb) {
		var row = cb.closest('.ffc-cap-row');
		var slug = row.getAttribute('data-ffc-cap-slug');
		var role = (CFG.roles || {})[current] || {};
		var desc = row.querySelector('.ffc-cap-row-desc');
		var strings = {};
		var base = i18n.dialog || {};
		Object.keys(base).forEach(function (k) { strings[k] = base[k]; });
		strings.title = fill(i18n.grantTitle, [capName(panel, slug)]);
		strings.intro = fill(i18n.grantIntro, [role.users || 0, role.label || current]);
		strings.agree = i18n.grantAgree || strings.agree;
		return window.FFC.overrideConfirm({
			strings: strings,
			reasons: desc ? [String(desc.textContent).trim()] : [],
			agreeCategory: 'caution',
			agreeIcon: 'checkmark'
		}).then(function (agreed) { return !!agreed; }, function () { return false; });
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
		roleDefaults = CFG.roleDefaults || {};
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
				if (!CFG.ajaxUrl) { return; }
				if (!needsConfirm(cb)) {
					persist(panel, cb);
					return;
				}
				confirmGrant(panel, cb).then(function (agreed) {
					if (agreed) {
						persist(panel, cb);
					} else {
						cb.checked = false;
					}
				});
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

		var restoreBtn = panel.querySelector('.ffc-role-restore');
		if (restoreBtn) {
			restoreBtn.addEventListener('click', function () { restore(panel, restoreBtn); });
		}
		renderDiff(panel);

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
