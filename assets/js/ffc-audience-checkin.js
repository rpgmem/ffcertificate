/**
 * The participant's check-in page (#1947): when the window asks for a
 * location, read it before the form posts, and say why when it cannot be
 * read. The server checks the position against the window's geofence; this
 * script only collects it.
 *
 * Before the button can be used, the page runs the visitor pre-flight
 * (#2040): `secure` (the browser gives the location only to a secure page),
 * `browser` (an app's own browser, with "Continue anyway") and the page's
 * own `checkin-geo`, which shows the "location blocked" message at once
 * when the permission was already refused. The position itself is read on
 * the tap, where a permission prompt works best on iOS. There is no failure
 * policy here: no position, no check-in.
 */
(function () {
	'use strict';

	var strings = window.ffcCheckin || {};

	function text(key, fallback) {
		var own = strings.strings || {};
		return own[key] || fallback;
	}

	/**
	 * Run the pre-flight on the page. A step that blocks hides the form
	 * behind its notice; the location step shows a refused permission under
	 * the button at once.
	 *
	 * @param {HTMLFormElement} form The check-in form.
	 * @param {Function}        fail Shows a message under the button.
	 */
	function preflight(form, fail) {
		var ns = window.FFC;
		if (!ns || !ns.Preflight || typeof window.jQuery !== 'function') {
			return;
		}

		ns.Preflight.register('checkin-geo', {
			run: function (ctx) {
				ctx.wrapper.find('.ffc-checkin-form').show();
				// A "denied" is confirmed before it is shown (#2048); the tap
				// still asks whatever the answer.
				ns.Preflight.geoPermission().then(function (answer) {
					if (answer.state === 'denied') {
						ns.Preflight.log(ctx, 'gps_denied');
						fail(strings.denied || '');
					}
				});
			}
		});

		ns.Preflight.run(['secure', 'browser', 'checkin-geo'], {
			wrapper: window.jQuery(form.closest('.ffc-checkin-card') || form.parentNode),
			config: {},
			needsSecure: true,
			browserCheck: strings.browserCheck !== false,
			featureCheck: false,
			formSelector: '.ffc-checkin-form',
			telemetry: strings.telemetry,
			text: text
		});
	}

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
					if (form.elements.ffc_accuracy) {
						form.elements.ffc_accuracy.value = String(position.coords.accuracy);
					}
					sent = true;
					form.submit();
				},
				function (geoError) {
					fail(geoError && 1 === geoError.code ? (strings.denied || '') : (strings.unavailable || ''));
				},
				{ enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
			);
		});

		preflight(form, fail);
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
