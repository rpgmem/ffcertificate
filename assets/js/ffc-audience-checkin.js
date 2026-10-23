/**
 * The participant's check-in page (#1947): when the window asks for a
 * location, read it before the form posts, and say why when it cannot be
 * read. The server checks the position against the window's geofence; this
 * script only collects it.
 */
(function () {
	'use strict';

	var strings = window.ffcCheckin || {};

	function init() {
		var form = document.querySelector('form[data-ffc-checkin-geo="1"]');
		if (!form) {
			return;
		}

		var submit = form.querySelector('.ffc-checkin-submit');
		var error = form.querySelector('.ffc-checkin-geo-error');
		var sent = false;

		function fail(message) {
			if (submit) {
				submit.disabled = false;
				submit.classList.remove('ffc-btn-loading');
			}
			if (error) {
				error.textContent = message;
				error.hidden = false;
			}
		}

		form.addEventListener('submit', function (event) {
			if (sent) {
				return;
			}
			event.preventDefault();

			if (!navigator.geolocation) {
				fail(strings.unavailable || '');
				return;
			}

			if (submit) {
				submit.disabled = true;
				submit.classList.add('ffc-btn-loading');
			}
			if (error) {
				error.hidden = true;
			}

			navigator.geolocation.getCurrentPosition(
				function (position) {
					form.elements.ffc_lat.value = String(position.coords.latitude);
					form.elements.ffc_lng.value = String(position.coords.longitude);
					sent = true;
					form.submit();
				},
				function (geoError) {
					fail(geoError && 1 === geoError.code ? (strings.denied || '') : (strings.unavailable || ''));
				},
				{ enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
			);
		});
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
