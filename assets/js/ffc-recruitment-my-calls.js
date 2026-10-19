/**
 * "My Calls" (#1889): pages the Waiting and Closed tables and opens the
 * info popovers.
 *
 * The server renders every row and an empty `nav.ffc-mycalls-pager` after
 * each table, carrying the page size and the pager's strings as data
 * attributes. Without this script every row shows and no pager does.
 *
 * Paging hides rows rather than rebuilding them, so the popovers inside stay
 * the server's markup. A group label row (`.ffc-mycalls-group-row`) shows
 * when its group has a row on the page, and takes `is-continued` — which the
 * sheet reads to show "(continued)" — when the group began on an earlier page.
 *
 * The pager keys are the button family's neutral buttons written by hand
 * (#1874): the current page is `aria-current`, never the primary category.
 */
(function (window, document) {
	'use strict';

	function esc(text) {
		return String(text)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function key(page, label, extra, content, attrs) {
		return '<button type="button" class="ffc-btn ffc-btn-neutral ffc-mycalls-page-btn' + extra + '"' +
			' data-page="' + page + '" aria-label="' + esc(label) + '"' + attrs + '>' + content + '</button>';
	}

	// Show one page of a table and redraw its pager.
	function showPage(state, page) {
		var rows = state.rows;
		var size = state.size;
		var pages = Math.ceil(rows.length / size);
		page = Math.min(Math.max(1, page), pages);
		var start = (page - 1) * size;
		var end = Math.min(start + size, rows.length);

		rows.forEach(function (row, i) {
			row.hidden = i < start || i >= end;
		});

		state.groups.forEach(function (groupRow) {
			var group = groupRow.getAttribute('data-group');
			var visible = false;
			var continued = false;
			for (var i = start; i < end; i++) {
				if (rows[i].getAttribute('data-group') === group) {
					visible = true;
					continued = i > 0 && rows[i - 1].getAttribute('data-group') === group && i === start;
					break;
				}
			}
			groupRow.hidden = !visible;
			groupRow.classList.toggle('is-continued', continued);
		});

		var pager = state.pager;
		var d = pager.dataset;
		var range = (d.labelRange || '%1$s–%2$s of %3$s')
			.replace('%1$s', String(start + 1))
			.replace('%2$s', String(end))
			.replace('%3$s', String(rows.length));

		var html = '<span class="ffc-mycalls-pager-range">' + esc(range) + '</span>';
		html += '<span class="ffc-mycalls-pager-pages">';
		html += key(page - 1, d.labelPrevious || 'Previous page', ' ffc-btn-icon ffc-icon-chevron-left', '',
			' title="' + esc(d.labelPrevious || 'Previous page') + '"' + (page <= 1 ? ' disabled' : ''));
		for (var n = 1; n <= pages; n++) {
			html += key(n, (d.labelPage || 'Page %d').replace('%d', String(n)), '', String(n),
				n === page ? ' aria-current="page"' : '');
		}
		html += key(page + 1, d.labelNext || 'Next page', ' ffc-btn-icon ffc-icon-chevron-right', '',
			' title="' + esc(d.labelNext || 'Next page') + '"' + (page >= pages ? ' disabled' : ''));
		html += '</span>';

		pager.innerHTML = html;
		state.page = page;
	}

	// Page the table that precedes `pager`. Returns false when one page
	// holds every row: then nothing is hidden and the pager stays empty.
	function initPager(pager) {
		var section = pager.closest('.ffc-mycalls-section');
		var body = section ? section.querySelector('tbody') : null;
		var size = parseInt(pager.getAttribute('data-per-page'), 10);
		if (!body || !(size > 0)) {
			return false;
		}
		var rows = Array.prototype.slice.call(body.querySelectorAll('tr.ffc-mycalls-row'));
		if (rows.length <= size) {
			pager.innerHTML = '';
			return false;
		}
		var state = {
			pager: pager,
			rows: rows,
			size: size,
			groups: Array.prototype.slice.call(body.querySelectorAll('tr.ffc-mycalls-group-row')),
			page: 1,
		};
		pager.addEventListener('click', function (e) {
			var btn = e.target.closest('.ffc-mycalls-page-btn');
			if (!btn || btn.disabled) {
				return;
			}
			showPage(state, parseInt(btn.getAttribute('data-page'), 10));
			var current = pager.querySelector('[aria-current="page"]');
			if (current) {
				current.focus();
			}
		});
		showPage(state, 1);
		return true;
	}

	function popoverOf(btn) {
		var id = btn.getAttribute('aria-controls');
		return id ? document.getElementById(id) : null;
	}

	function setOpen(btn, open) {
		var pop = popoverOf(btn);
		btn.setAttribute('aria-expanded', open ? 'true' : 'false');
		var wrap = btn.closest('.ffc-mycalls-info');
		if (wrap) {
			wrap.classList.toggle('is-open', open);
		}
		if (pop) {
			pop.hidden = !open;
		}
	}

	function closeAll(except) {
		var open = document.querySelectorAll('.ffc-mycalls-info-btn[aria-expanded="true"]');
		Array.prototype.forEach.call(open, function (btn) {
			if (btn !== except) {
				setOpen(btn, false);
			}
		});
	}

	function onClick(e) {
		var btn = e.target.closest ? e.target.closest('.ffc-mycalls-info-btn') : null;
		if (!btn) {
			if (!e.target.closest || !e.target.closest('.ffc-mycalls-pop')) {
				closeAll(null);
			}
			return;
		}
		closeAll(btn);
		setOpen(btn, btn.getAttribute('aria-expanded') !== 'true');
	}

	function onKeydown(e) {
		if (e.key !== 'Escape') {
			return;
		}
		var open = document.querySelector('.ffc-mycalls-info-btn[aria-expanded="true"]');
		closeAll(null);
		if (open) {
			open.focus();
		}
	}

	var bound = false;

	function init() {
		var pagers = document.querySelectorAll('.ffc-mycalls-pager');
		Array.prototype.forEach.call(pagers, initPager);
		if (!bound) {
			document.addEventListener('click', onClick);
			document.addEventListener('keydown', onKeydown);
			bound = true;
		}
	}

	window.FFCMyCalls = {
		init: init,
		initPager: initPager,
		closeAll: closeAll,
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
