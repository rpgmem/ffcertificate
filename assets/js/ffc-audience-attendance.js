/**
 * The attendance screen and the projected check-in QR (#1947).
 *
 * Both poll one read-only AJAX action. The projection swaps the QR at the
 * rotation boundary the server names, and shows how long the code has left;
 * the screen refreshes who is present. The QR is drawn on the server, so the
 * signing key never reaches the browser.
 */
(function () {
	'use strict';

	var config = window.ffcAttendance || {};
	var LIST_INTERVAL = 10000;

	function poll(withQr) {
		var body = new URLSearchParams();
		body.append('action', config.action || '');
		body.append('nonce', config.nonce || '');
		body.append('booking_id', String(config.bookingId || 0));
		body.append('date', config.date || '');
		if (withQr) {
			body.append('qr', '1');
		}

		return fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) {
				return response.json();
			})
			.then(function (json) {
				return json && json.success ? json.data : null;
			})
			.catch(function () {
				return null;
			});
	}

	function setCount(root, state) {
		var count = root.querySelector('.ffc-attendance-present');
		if (count) {
			count.textContent = String(state.present);
		}
	}

	function refreshList(root, state) {
		setCount(root, state);
		(state.rows || []).forEach(function (row) {
			var tr = root.querySelector('tr[data-user-id="' + row.user_id + '"]');
			if (!tr) {
				return;
			}
			var cell = tr.querySelector('.ffc-attendance-cell');
			if (cell && !cell.querySelector('.ffc-attendance-badge')) {
				cell.textContent = '';
				var badge = document.createElement('span');
				badge.className = 'ffc-attendance-badge ffc-attendance-badge-open';
				badge.textContent = row.time;
				var how = document.createElement('span');
				how.className = 'ffc-attendance-method';
				how.textContent = 'manual' === row.method ? (config.strings || {}).byHand : (config.strings || {}).qr;
				cell.appendChild(badge);
				cell.appendChild(document.createTextNode(' '));
				cell.appendChild(how);
			}
			var mark = tr.querySelector('.ffc-attendance-mark');
			if (mark) {
				mark.disabled = true;
			}
		});
	}

	function initScreen() {
		var root = document.querySelector('.ffc-attendance-participants');
		if (!root) {
			return;
		}
		window.setInterval(function () {
			poll(false).then(function (state) {
				if (state) {
					refreshList(root, state);
				}
			});
		}, LIST_INTERVAL);
	}

	function initProjection() {
		var stage = document.querySelector('.ffc-attendance-stage');
		if (!stage) {
			return;
		}
		var qr = stage.querySelector('.ffc-attendance-qr');
		var timer = stage.querySelector('.ffc-attendance-timer');
		var remaining = timer ? Number(timer.value) || 0 : 0;

		function apply(state) {
			if (!state) {
				schedule(5);
				return;
			}
			setCount(stage, state);
			if (!state.open) {
				qr.textContent = '';
				var closed = document.createElement('p');
				closed.className = 'ffc-attendance-stage-closed';
				closed.setAttribute('role', 'status');
				closed.textContent = (config.strings || {}).closed || '';
				qr.appendChild(closed);
				if (timer) {
					timer.hidden = true;
				}
				return;
			}
			qr.innerHTML = state.qr;
			remaining = Number(state.seconds_remaining) || 1;
			if (timer) {
				timer.max = Number(state.rotation) || remaining;
				timer.value = remaining;
			}
			schedule(remaining);
		}

		function schedule(seconds) {
			window.setTimeout(function () {
				poll(true).then(apply);
			}, Math.max(1, seconds) * 1000);
		}

		if (timer) {
			window.setInterval(function () {
				remaining = Math.max(0, remaining - 1);
				timer.value = remaining;
			}, 1000);
		}

		window.setInterval(function () {
			poll(false).then(function (state) {
				if (state) {
					setCount(stage, state);
				}
			});
		}, LIST_INTERVAL);

		schedule(remaining || 1);
	}

	function init() {
		if (config.projection) {
			initProjection();
		} else {
			initScreen();
		}
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
