/**
 * The "Test my device" mode of the verification page (#2040): every visitor
 * pre-flight check, run as the forms run it, with its result written into
 * the page.
 *
 * The checks come from the queue module (`FFC.Preflight`) and the platform
 * helpers (`FFC.Platform`), so the page answers what a form would. The
 * location is read only when the visitor asks, and only its margin of
 * error is shown: the position itself never leaves this function. Nothing
 * is sent anywhere.
 */
(function () {
	'use strict';

	var cfg = window.ffcDeviceCheck || {};
	var strings = cfg.strings || {};

	function text(key, fallback) {
		return strings[key] || fallback;
	}

	function row(key) {
		return document.querySelector('[data-ffc-check="' + key + '"]');
	}

	/**
	 * Write a check's result.
	 *
	 * @param {string} key   Check key.
	 * @param {string} state `ok` | `warn` | `fail` | `info`.
	 * @param {string} value What to show.
	 */
	function set(key, state, value) {
		var el = row(key);
		if (!el) {
			return;
		}
		el.classList.remove('is-ok', 'is-warn', 'is-fail', 'is-info');
		el.classList.add('is-' + state);
		el.querySelector('.ffc-device-check-result').textContent = value;
	}

	function device(platform) {
		var os = platform.os();
		if (os === 'ios') {
			return text('deviceIos', 'iPhone or iPad');
		}
		return os === 'android' ? text('deviceAndroid', 'Android') : text('deviceDesktop', 'computer');
	}

	function checkLocationPermission() {
		if (!navigator.geolocation) {
			set('location', 'fail', text('noGeolocation', 'This browser cannot give a location.'));
			return;
		}
		var permissions = navigator.permissions;
		if (!permissions || typeof permissions.query !== 'function') {
			set('location', 'info', text('locationUnknown', 'The browser does not say until it asks.'));
			return;
		}
		permissions.query({ name: 'geolocation' }).then(function (status) {
			var state = status && status.state;
			if (state === 'granted') {
				set('location', 'ok', text('locationGranted', 'Allowed'));
			} else if (state === 'denied') {
				set('location', 'fail', text('locationDenied', 'Blocked: allow it in the browser settings.'));
			} else {
				set('location', 'info', text('locationPrompt', 'Not decided yet: the browser will ask.'));
			}
		}, function () {
			set('location', 'info', text('locationUnknown', 'The browser does not say until it asks.'));
		});
	}

	function readAccuracy() {
		if (!navigator.geolocation) {
			set('accuracy', 'fail', text('noGeolocation', 'This browser cannot give a location.'));
			return;
		}
		set('accuracy', 'info', text('accuracyReading', 'Reading your location…'));
		var started = Date.now();
		navigator.geolocation.getCurrentPosition(
			function (position) {
				var metres = Math.round(position.coords.accuracy);
				var seconds = ((Date.now() - started) / 1000).toFixed(1);
				set(
					'accuracy',
					metres <= 100 ? 'ok' : 'warn',
					text('accuracyResult', 'About %1$s m, read in %2$s s').replace('%1$s', String(metres)).replace('%2$s', seconds)
				);
				// The permission may have just been granted.
				checkLocationPermission();
			},
			function (error) {
				var code = error && error.code;
				if (code === 1) {
					set('accuracy', 'fail', text('accuracyDenied', 'Refused by the browser or by you.'));
				} else if (code === 3) {
					set('accuracy', 'fail', text('accuracyTimeout', 'Took too long: move to an open area and try again.'));
				} else {
					set('accuracy', 'fail', text('accuracyFailed', 'Could not be read: move to an open area and try again.'));
				}
				checkLocationPermission();
			},
			{ enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
		);
	}

	/**
	 * The results as plain text, for a message to support: each row, the
	 * browser's own identification and the time of the test.
	 *
	 * @returns {string}
	 */
	function summary() {
		var lines = [];
		document.querySelectorAll('.ffc-device-check-row').forEach(function (el) {
			lines.push(el.querySelector('.ffc-device-check-label').textContent + ': ' + el.querySelector('.ffc-device-check-result').textContent);
		});
		lines.push('User agent: ' + ((window.FFC && window.FFC.Platform && window.FFC.Platform.ua()) || ''));
		lines.push(new Date().toISOString());
		return lines.join('\n');
	}

	function copy(button) {
		var done = function (ok) {
			var label = button.querySelector('.ffc-btn-label') || button;
			var before = label.textContent;
			label.textContent = ok ? text('copied', 'Copied') : text('copyFailed', 'Could not copy: take a screenshot instead.');
			setTimeout(function () {
				label.textContent = before;
			}, 2500);
		};
		if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
			navigator.clipboard.writeText(summary()).then(function () {
				done(true);
			}, function () {
				done(false);
			});
		} else {
			done(false);
		}
	}

	function init() {
		var ns = window.FFC || {};
		var preflight = ns.Preflight;
		var platform = ns.Platform;
		if (!preflight || !platform) {
			return;
		}

		set('secure', preflight.secureContext() ? 'ok' : 'fail', preflight.secureContext()
			? text('secureYes', 'Yes')
			: text('secureNo', 'No: the browser gives no location here, and forms that ask for it will stop.'));

		set('browser', platform.inApp() ? 'warn' : 'ok', (platform.inApp()
			? text('browserInApp', 'An app\'s own browser (%s): the location and downloads often fail. Open the page in the phone\'s browser.')
			: text('browserOk', 'Ordinary browser (%s)')).replace('%s', device(platform)));

		set('cookies', preflight.cookiesWork() ? 'ok' : 'fail', preflight.cookiesWork()
			? text('cookiesYes', 'Allowed')
			: text('cookiesNo', 'Blocked: sending a form may fail.'));

		var missing = preflight.missingFeatures();
		set('pdf', missing.length ? 'fail' : 'ok', missing.length
			? text('pdfNo', 'Not supported: missing %s.').replace('%s', missing.join(', '))
			: text('pdfYes', 'Supported'));

		checkLocationPermission();
		set('accuracy', 'info', text('accuracyIdle', 'Press "Test my location".'));

		var locate = document.querySelector('.ffc-device-check-locate');
		if (locate) {
			locate.addEventListener('click', readAccuracy);
		}
		var copyButton = document.querySelector('.ffc-device-check-copy');
		if (copyButton) {
			copyButton.addEventListener('click', function () {
				copy(copyButton);
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	window.FFCDeviceCheck = { summary: summary };
})();
