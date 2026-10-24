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

	/** Apps whose own browser marks the user agent, by the name shown. */
	var APPS = [
		['Instagram', /Instagram/i],
		['Facebook', /FBAN|FBAV|FBIOS/i],
		['WhatsApp', /WhatsApp/i],
		['TikTok', /BytedanceWebView|musical_ly/i],
		['LinkedIn', /LinkedInApp/i],
		['LINE', /Line\//]
	];

	/**
	 * Chromium brands by the name shown, in the order they win: a derivative
	 * lists "Chromium" too, and Chrome lists only "Google Chrome" and
	 * "Chromium" (#2052).
	 */
	var BRANDS = [
		['Brave', 'Brave'],
		['Microsoft Edge', 'Edge'],
		['Opera', 'Opera'],
		['Samsung Internet', 'Samsung Internet'],
		['Google Chrome', 'Chrome']
	];

	function uaVersion(ua, re) {
		var m = re.exec(ua);
		return m ? m[1] : '';
	}

	/**
	 * A version as the browser gave it, without the zeros a reduced user
	 * agent pads it with ("129.0.0.0" says only "129").
	 *
	 * @param {string} version
	 * @returns {string}
	 */
	function trimVersion(version) {
		return String(version || '').replace(/(\.0)+$/, '');
	}

	function labelled(name, version) {
		return version ? name + ' (v. ' + trimVersion(version) + ')' : name;
	}

	/**
	 * The name and version of a Chromium-based browser, from the brands it
	 * declares. Its own number goes before the engine's where it has one
	 * (Opera, Samsung Internet), read from the user agent.
	 *
	 * @param {Array<{brand: string, version: string}>} brands
	 * @param {string} ua
	 * @returns {string} '' when no brand names the browser.
	 */
	function fromBrands(brands, ua) {
		var versionOf = function (brand) {
			for (var i = 0; i < brands.length; i++) {
				if (brands[i].brand === brand) {
					return brands[i].version;
				}
			}
			return null;
		};
		var engine = versionOf('Chromium');
		for (var i = 0; i < BRANDS.length; i++) {
			var own = versionOf(BRANDS[i][0]);
			if (own === null) {
				continue;
			}
			var name = BRANDS[i][1];
			if ('Chrome' === name) {
				return labelled('Chrome', own);
			}
			if ('Opera' === name) {
				name += ' ' + (uaVersion(ua, /(?:OPR|Opera)\/(\d+)/) || trimVersion(own).split('.')[0]);
			} else if ('Samsung Internet' === name) {
				name += ' ' + (uaVersion(ua, /SamsungBrowser\/(\d+)/) || trimVersion(own).split('.')[0]);
			}
			return labelled(name + ' / Chromium', engine || '');
		}
		return engine !== null ? labelled('Chromium', engine) : '';
	}

	/**
	 * The name and version from the user agent alone: Firefox, Safari, every
	 * browser on iOS (all of them WebKit), and a Chromium browser that does
	 * not declare its brands.
	 *
	 * @param {string} ua
	 * @param {boolean} ios
	 * @returns {string} '' for a browser this does not know.
	 */
	function fromUserAgent(ua, ios) {
		var v;
		if (ios) {
			if ((v = uaVersion(ua, /CriOS\/([\d.]+)/))) {
				return labelled('Chrome / WebKit', v);
			}
			if ((v = uaVersion(ua, /FxiOS\/([\d.]+)/))) {
				return labelled('Firefox / WebKit', v);
			}
			if ((v = uaVersion(ua, /EdgiOS\/([\d.]+)/))) {
				return labelled('Edge / WebKit', v);
			}
			v = uaVersion(ua, /Version\/([\d.]+).*Safari/);
			return v ? labelled('Safari', v) : '';
		}
		var chromium = uaVersion(ua, /(?:Chrome|Chromium)\/([\d.]+)/);
		if ((v = uaVersion(ua, /Edg(?:A)?\/([\d.]+)/))) {
			return labelled('Edge / Chromium', chromium || v);
		}
		if ((v = uaVersion(ua, /(?:OPR|Opera)\/(\d+)/))) {
			return labelled('Opera ' + v + ' / Chromium', chromium);
		}
		if ((v = uaVersion(ua, /SamsungBrowser\/(\d+)/))) {
			return labelled('Samsung Internet ' + v + ' / Chromium', chromium);
		}
		if ((v = uaVersion(ua, /Firefox\/([\d.]+)/))) {
			return labelled('Firefox', v);
		}
		if ((v = uaVersion(ua, /Chromium\/([\d.]+)/))) {
			return labelled('Chromium', v);
		}
		if (chromium) {
			return labelled('Chrome', chromium);
		}
		v = uaVersion(ua, /Version\/([\d.]+).*Safari/);
		return v ? labelled('Safari', v) : '';
	}

	/**
	 * The browser's name and version (#2052), for the person reading the
	 * results: no decision reads it, which is why it lives here and not in
	 * FFC.Platform. The brands a Chromium browser declares come first, with
	 * the full version when it answers; the user agent is the fallback.
	 *
	 * @param {object} platform FFC.Platform.
	 * @returns {Promise<string>} '' for a browser this does not know.
	 */
	function browserName(platform) {
		var ua = platform.ua();
		var fallback = function () {
			return fromUserAgent(ua, platform.ios());
		};
		var data = navigator.userAgentData;
		if (!data || !Array.isArray(data.brands) || !data.brands.length) {
			return Promise.resolve(fallback());
		}
		var brandsOnly = function () {
			return fromBrands(data.brands, ua) || fallback();
		};
		if (typeof data.getHighEntropyValues !== 'function') {
			return Promise.resolve(brandsOnly());
		}
		try {
			return Promise.resolve(data.getHighEntropyValues(['fullVersionList'])).then(function (values) {
				var list = values && values.fullVersionList;
				return (Array.isArray(list) && list.length && fromBrands(list, ua)) || brandsOnly();
			}, brandsOnly);
		} catch (e) {
			return Promise.resolve(brandsOnly());
		}
	}

	/** The app whose own browser this is, or ''. */
	function appName(ua) {
		for (var i = 0; i < APPS.length; i++) {
			if (APPS[i][1].test(ua)) {
				return APPS[i][0];
			}
		}
		return '';
	}

	function checkBrowser(platform) {
		var where = device(platform);
		if (platform.inApp()) {
			var app = appName(platform.ua());
			set('browser', 'warn', text('browserInApp', 'An app\'s own browser (%s): the location and downloads often fail. Open the page in the phone\'s browser.')
				.replace('%s', app ? app + ', ' + where : where));
			return;
		}
		set('browser', 'ok', text('browserOk', 'Ordinary browser (%s)').replace('%s', where));
		browserName(platform).then(function (name) {
			if (name) {
				set('browser', 'ok', text('browserNamed', '%1$s — %2$s').replace('%1$s', name).replace('%2$s', where));
			}
		});
	}

	function checkLocationPermission() {
		if (!navigator.geolocation) {
			set('location', 'fail', text('noGeolocation', 'This browser cannot give a location.'));
			return;
		}
		// The queue module confirms a "denied" before it is believed
		// (#2048), as the forms do.
		window.FFC.Preflight.geoPermission().then(function (answer) {
			if (answer.state === 'granted') {
				set('location', 'ok', text('locationGranted', 'Allowed'));
			} else if (answer.state === 'denied') {
				set('location', 'fail', text('locationDenied', 'Blocked: allow it in the browser settings.'));
			} else if (answer.state === 'prompt') {
				set('location', 'info', text('locationPrompt', 'Not decided yet: the browser will ask.'));
			} else {
				set('location', 'info', text('locationUnknown', 'The browser does not say until it asks.'));
			}
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

		checkBrowser(platform);

		set('cookies', preflight.cookiesWork() ? 'ok' : 'fail', preflight.cookiesWork()
			? text('cookiesYes', 'Allowed')
			: text('cookiesNo', 'Blocked: sending a form may fail.'));

		var missing = preflight.missingFeatures();
		set('pdf', missing.length ? 'fail' : 'ok', missing.length
			? text('pdfNo', 'Not supported: missing %s.').replace('%s', missing.join(', '))
			: text('pdfYes', 'Supported: this browser can build and save the PDF.'));

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

	window.FFCDeviceCheck = { summary: summary, browserName: browserName };
})();
