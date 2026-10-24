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
	 * @param {string} key    Check key.
	 * @param {string} state  `ok` | `warn` | `fail` | `info`.
	 * @param {string} value  What to show.
	 * @param {string} [help] The string key of how to fix it, shown behind
	 *                        an information button when the state is
	 *                        `warn` or `fail` (#2054).
	 * @param {boolean} [explain] Show the button on an `info` row too, for
	 *                        an answer that needs a word but is no fault
	 *                        (#2060).
	 */
	function set(key, state, value, help, explain) {
		var el = row(key);
		if (!el) {
			return;
		}
		stopCountdown(key);
		el.classList.remove('is-ok', 'is-warn', 'is-fail', 'is-info');
		el.classList.add('is-' + state);
		el.querySelector('.ffc-device-check-result').textContent = value;
		var shown = 'warn' === state || 'fail' === state || (explain === true && 'info' === state);
		showHelp(el, key, shown && help ? text(help, '') : '');
		el.setAttribute('data-ffc-checked', '1');
		verdict();
	}

	/** Rank of a row state, from neutral to failing. */
	var RANK = { info: 0, ok: 1, warn: 2, fail: 3 };

	/** The verdict drawn last, so a repeat draws nothing. */
	var drawn = '';

	/**
	 * The overall verdict above the rows (#2062): checking while a row has
	 * no answer yet, then the worst state any row reached.
	 */
	function verdict() {
		var box = document.querySelector('.ffc-device-check-verdict');
		var ns = window.FFC || {};
		if (!box || typeof ns.notice !== 'function') {
			return;
		}
		var worst = -1;
		var rows = document.querySelectorAll('.ffc-device-check-row');
		for (var i = 0; i < rows.length; i++) {
			if (!rows[i].hasAttribute('data-ffc-checked') || countdowns[rows[i].getAttribute('data-ffc-check')]) {
				worst = -1;
				break;
			}
			['info', 'ok', 'warn', 'fail'].forEach(function (state) {
				if (rows[i].classList.contains('is-' + state)) {
					worst = Math.max(worst, RANK[state]);
				}
			});
		}
		var key = worst < 0 ? 'checking' : (worst === RANK.fail ? 'fail' : (worst === RANK.warn ? 'warn' : 'ok'));
		if (key === drawn) {
			return;
		}
		drawn = key;
		var notice = {
			checking: ['info', 'sync', text('verdictChecking', 'Running the checks…'), ''],
			ok: ['success', 'success', text('verdictOk', 'Every check passed: the forms should work here.'), text('verdictOkTitle', 'This device is ready')],
			warn: ['warning', 'warning', text('verdictWarn', 'The forms should work, but the items in yellow may get in the way. Open "How to fix it" on each one.'), text('verdictWarnTitle', 'Ready, with warnings')],
			fail: ['danger', 'error', text('verdictFail', 'Fix the items in red: open "How to fix it" on each one, then reload this page.'), text('verdictFailTitle', 'The forms may not work here')]
		}[key];
		box.textContent = '';
		box.appendChild(ns.notice(notice[0], notice[1], notice[2], notice[3]));
		box.setAttribute('data-ffc-verdict', key);
	}

	/** How long a check may run before its row says it got no answer. */
	var PENDING_SECONDS = 20;

	/** Running countdowns, by check key. */
	var countdowns = {};

	function stopCountdown(key) {
		if (countdowns[key]) {
			clearInterval(countdowns[key]);
			delete countdowns[key];
		}
	}

	/**
	 * Show that a check is still running, with the seconds it has left, so
	 * the visitor is never left without an answer (#2054). At zero the row
	 * says the check got no answer.
	 *
	 * @param {string} key     Check key.
	 * @param {string} message What is being done.
	 */
	function pending(key, message) {
		var el = row(key);
		if (!el) {
			return;
		}
		var left = PENDING_SECONDS;
		var paint = function () {
			el.classList.remove('is-ok', 'is-warn', 'is-fail');
			el.classList.add('is-info');
			el.querySelector('.ffc-device-check-result').textContent = fill(text('pendingCountdown', '%1$s (%2$s s)'), [message, left]);
		};
		set(key, 'info', message);
		paint();
		countdowns[key] = setInterval(function () {
			left--;
			if (left > 0) {
				paint();
				return;
			}
			set(key, 'warn', text('pendingTimeout', 'No answer after %s s: reload the page to test again.').replace('%s', String(PENDING_SECONDS)));
		}, 1000);
		verdict();
	}

	/**
	 * The information button and the fix it reveals: drawn while a check
	 * fails, removed when it passes.
	 *
	 * @param {HTMLElement} el   The row.
	 * @param {string}      key  Check key.
	 * @param {string}      fix  What to do; '' removes both.
	 */
	function showHelp(el, key, fix) {
		var old = el.querySelector('.ffc-device-check-tip');
		var box = el.querySelector('.ffc-device-check-fix');
		if (!fix) {
			if (old) {
				old.remove();
			}
			if (box) {
				box.remove();
			}
			return;
		}
		if (!box) {
			box = document.createElement('p');
			box.className = 'ffc-device-check-fix';
			box.id = 'ffc-device-check-fix-' + key;
			box.hidden = true;
			el.appendChild(box);
		}
		box.textContent = fix;
		if (old) {
			return;
		}
		var ns = window.FFC || {};
		var tip = typeof ns.button === 'function' ? ns.button({
			label: text('helpLabel', 'How to fix it'),
			icon: 'info',
			iconOnly: true,
			className: 'ffc-device-check-tip',
			attrs: { 'aria-expanded': 'false', 'aria-controls': box.id }
		}) : null;
		if (!tip) {
			box.hidden = false;
			return;
		}
		tip.addEventListener('click', function () {
			box.hidden = !box.hidden;
			tip.setAttribute('aria-expanded', box.hidden ? 'false' : 'true');
		});
		el.querySelector('.ffc-device-check-label').appendChild(tip);
	}

	/** The fix for a blocked location, for the platform in hand. */
	function locationHelp() {
		var os = window.FFC && window.FFC.Platform ? window.FFC.Platform.os() : 'desktop';
		return { ios: 'helpLocationIos', android: 'helpLocationAndroid' }[os] || 'helpLocationDesktop';
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
		['LINE', /Line\/\d/]
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
				.replace('%s', app ? app + ', ' + where : where), 'helpInApp');
			return;
		}
		set('browser', 'ok', text('browserOk', 'Ordinary browser (%s)').replace('%s', where));
		browserName(platform).then(function (name) {
			if (name) {
				set('browser', 'ok', text('browserNamed', '%1$s — %2$s').replace('%1$s', name).replace('%2$s', where));
			}
		});
	}

	function fill(template, values) {
		return values.reduce(function (out, value, i) {
			return out.replace('%' + (i + 1) + '$s', String(value));
		}, template);
	}

	/**
	 * A short code for the signals read: the first eight hex characters of a
	 * hash over every signal hash, in name order. Two attempts on one device
	 * show the same code while the signals hold (#2054).
	 *
	 * @param {Object<string, string>} hashed
	 * @returns {Promise<string>}
	 */
	function shortCode(hashed) {
		var joined = Object.keys(hashed).sort().map(function (key) {
			return key + ':' + hashed[key];
		}).join('|');
		return window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(joined)).then(function (buf) {
			var hex = Array.prototype.map.call(new Uint8Array(buf), function (b) {
				return (b + 0x100).toString(16).slice(1);
			}).join('');
			return hex.slice(0, 4) + ' ' + hex.slice(4, 8);
		});
	}

	/**
	 * The device ID row (#2054): the signals the device limit reads, read
	 * twice to see whether they hold. Read-only: the collector never creates
	 * the persistent id here, and nothing leaves the page.
	 */
	function checkDevice() {
		if (!row('device')) {
			return;
		}
		var collector = window.FFCDeviceSignals;
		if (!collector) {
			// The collector loads only where the device limit is on, and
			// stays away without a secure context or the library. With both
			// in place, its absence means its script or the library's was
			// stopped before it ran: a blocker (#2062).
			if (!window.ffc_device_config) {
				set('device', 'info', text('deviceOff', 'Not used on this site: the device limit is off.'));
			} else if (!window.crypto || !window.crypto.subtle) {
				set('device', 'warn', text('deviceNone', 'Not available in this browser: it needs a secure connection and a recent browser.'), 'helpDeviceNone');
			} else {
				set('device', 'warn', text('deviceBlocked', 'Did not load: a content or script blocker may be stopping it.'), 'helpDeviceBlocked');
			}
			return;
		}
		pending('device', text('deviceReading', 'Reading the device signals…'));
		var failed = function () {
			set('device', 'fail', text('deviceFailed', 'The device signals could not be read.'), 'helpDeviceNone');
		};
		collector.collect().then(function (first) {
			return collector.collect().then(function (second) {
				var changed = Object.keys(first).filter(function (key) {
					return first[key] !== second[key];
				});
				var total = collector.enabled.length || Object.keys(first).length;
				return shortCode(first).then(function (code) {
					var read = Object.keys(first).length;
					if (changed.length) {
						set('device', 'warn', fill(text('deviceUnstable', '%1$s · %2$s of %3$s signals · changes on each read: %4$s'), [code, read, total, changed.sort().join(', ')]), 'helpDeviceUnstable');
					} else {
						set('device', read ? 'ok' : 'warn', fill(text('deviceStable', '%1$s · %2$s of %3$s signals · stable'), [code, read, total]), 'helpDeviceNone');
					}
				});
			});
		}).catch(failed);
	}

	/**
	 * The theme row (#2054): what the page shows, the site's setting and the
	 * system preference, which together explain unexpected colours.
	 */
	function checkTheme() {
		if (!row('theme')) {
			return;
		}
		var dark = document.documentElement.classList.contains('ffc-dark-mode');
		var mode = (window.ffcDarkMode && window.ffcDarkMode.mode) || 'off';
		var site = { on: text('themeSiteOn', 'always dark'), auto: text('themeSiteAuto', 'follows the system') }[mode]
			|| text('themeSiteOff', 'always light');
		var system = text('themeSystemNone', 'not stated');
		if (window.matchMedia) {
			if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
				system = text('themeSystemDark', 'dark');
			} else if (window.matchMedia('(prefers-color-scheme: light)').matches) {
				system = text('themeSystemLight', 'light');
			}
		}
		set('theme', 'info', fill(text('themeResult', '%1$s (site: %2$s; system: %3$s)'), [
			dark ? text('themeDark', 'Dark') : text('themeLight', 'Light'),
			site,
			system
		]));
	}

	/** A probe key, written and removed at once: the test keeps nothing. */
	var PROBE = 'ffc_device_check_probe';

	function storageWorks(store) {
		try {
			window[store].setItem(PROBE, '1');
			var ok = window[store].getItem(PROBE) === '1';
			window[store].removeItem(PROBE);
			return ok;
		} catch (e) {
			return false;
		}
	}

	/**
	 * Local and session storage (#2054): the "already submitted" notice and
	 * "Continue anyway" are remembered there.
	 */
	function checkStorage() {
		var ok = storageWorks('localStorage') && storageWorks('sessionStorage');
		set('storage', ok ? 'ok' : 'warn', ok
			? text('storageYes', 'Allowed')
			: text('storageNo', 'Blocked (a private window?): the "already submitted" notice and "Continue anyway" will not be remembered.'), 'helpStorage');
	}

	/**
	 * What the ALTCHA captcha needs (#2054): Web Crypto, which only a secure
	 * page has, and Web Workers. Where the site uses ALTCHA, also whether its
	 * widget defined itself, which a blocker that stops the bundle prevents
	 * (#2062).
	 */
	function checkCaptcha() {
		var missing = [];
		if (!window.crypto || !window.crypto.subtle) {
			missing.push('Web Crypto');
		}
		if (typeof window.Worker !== 'function') {
			missing.push('Web Workers');
		}
		if (missing.length) {
			set('captcha', 'fail', text('captchaNo', 'Not supported: missing %s. A form whose captcha is ALTCHA only cannot be sent.').replace('%s', missing.join(', ')), 'helpCaptcha');
			return;
		}
		var altcha = 'altcha' === cfg.captcha || 'both' === cfg.captcha;
		if (altcha && !(window.customElements && window.customElements.get('altcha-widget'))) {
			set('captcha', 'fail', text('captchaNotLoaded', 'Did not load: a content or script blocker may be stopping it. The forms cannot be sent.'), 'helpCaptchaBlocked');
			return;
		}
		set('captcha', 'ok', text('captchaYes', 'Supported'));
	}

	/**
	 * Whether a request reaches the server the forms post to (#2062): an
	 * empty POST to admin-ajax.php, which WordPress answers with a 400 and
	 * "0" when it names no action. Anything else came from a firewall in
	 * front of the site; no answer at all means a blocker or the network
	 * stopped it.
	 */
	function checkServer() {
		if (!row('server')) {
			return;
		}
		var ns = window.FFC || {};
		var url = (window.ffc_ajax && window.ffc_ajax.ajax_url) || (typeof ns.getAjaxUrl === 'function' ? ns.getAjaxUrl() : '');
		var blocked = function () {
			set('server', 'fail', text('serverBlocked', 'Did not reach the server: sending a form will fail.'), 'helpServerBlocked');
		};
		if (!url || typeof window.fetch !== 'function') {
			blocked();
			return;
		}
		pending('server', text('serverReading', 'Sending a test request…'));
		var started = Date.now();
		window.fetch(url, { method: 'POST', cache: 'no-store', credentials: 'same-origin' }).then(function (response) {
			return response.text().then(function (body) {
				if (400 === response.status && '0' === String(body).trim()) {
					set('server', 'ok', text('serverOk', 'Reached, answered in %s ms').replace('%s', String(Date.now() - started)));
				} else {
					set('server', 'fail', text('serverRefused', 'Refused by the server or a firewall (code %s): sending a form will fail.').replace('%s', String(response.status)), 'helpServerRefused');
				}
			});
		}).catch(blocked);
	}

	/**
	 * A duration, rounded to the unit a person reads.
	 *
	 * @param {number} seconds
	 * @returns {string}
	 */
	function duration(seconds) {
		seconds = Math.round(Math.abs(seconds));
		if (seconds < 60) {
			return seconds + ' s';
		}
		if (seconds < 3600) {
			return Math.round(seconds / 60) + ' min';
		}
		return Math.round(seconds / 3600) + ' h';
	}

	/**
	 * The device clock against the server's (#2054), read from the `Date`
	 * header of a request for this same page: no endpoint, nothing sent but
	 * the request itself. A clock more than a minute off breaks date windows
	 * and the captcha's expiry.
	 */
	function checkClock() {
		if (!row('clock')) {
			return;
		}
		var unknown = function () {
			set('clock', 'info', text('clockUnknown', 'Could not compare with the server.'));
		};
		if (typeof window.fetch !== 'function') {
			unknown();
			return;
		}
		pending('clock', text('clockReading', 'Comparing with the server…'));
		var sent = Date.now();
		window.fetch(window.location.href, { method: 'HEAD', cache: 'no-store', credentials: 'same-origin' }).then(function (response) {
			var server = Date.parse(response.headers.get('Date') || '');
			if (isNaN(server)) {
				unknown();
				return;
			}
			// The header is the server's second; the device's matching moment
			// is the middle of the request.
			var skew = ((sent + Date.now()) / 2 - server) / 1000;
			if (Math.abs(skew) <= 60) {
				set('clock', 'ok', text('clockOk', 'Correct (differs by %s)').replace('%s', duration(skew)));
			} else if (skew > 0) {
				set('clock', 'warn', text('clockAhead', 'Ahead by %s: date windows and the captcha may fail. Set the clock to automatic.').replace('%s', duration(skew)), 'helpClock');
			} else {
				set('clock', 'warn', text('clockBehind', 'Behind by %s: date windows and the captcha may fail. Set the clock to automatic.').replace('%s', duration(skew)), 'helpClock');
			}
		}, unknown);
	}

	/** The time zone and the language the browser reports (#2054). */
	function checkLocale() {
		var zone = '';
		try {
			zone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
		} catch (e) {
			zone = '';
		}
		var offset = -new Date().getTimezoneOffset();
		var utc = 'UTC' + (offset < 0 ? '-' : '+') + Math.floor(Math.abs(offset) / 60) + (offset % 60 ? ':' + ('0' + Math.abs(offset % 60)).slice(-2) : '');
		set('locale', 'info', [zone || utc, zone ? utc : '', navigator.language || ''].filter(Boolean).join(' · '));
	}

	/** The window, the screen and the pixel density (#2054). */
	function checkScreen() {
		var screen = window.screen || {};
		set('screen', 'info', fill(text('screenResult', 'Window %1$s · screen %2$s · density %3$s×'), [
			window.innerWidth + ' × ' + window.innerHeight,
			(screen.width || '?') + ' × ' + (screen.height || '?'),
			Math.round((window.devicePixelRatio || 1) * 100) / 100
		]));
	}

	/**
	 * Online or not, and what the Network Information API says where the
	 * browser has it (Chromium): the type and whether data saver is on.
	 */
	/** `navigator.connection.type` values worth naming, by string key. */
	var CONNECTION_TYPES = {
		wifi: ['connectionWifi', 'Wi-Fi'],
		cellular: ['connectionCellular', 'Mobile data'],
		ethernet: ['connectionEthernet', 'Wired'],
		bluetooth: ['connectionBluetooth', 'Bluetooth'],
		wimax: ['connectionOther', 'Other network'],
		other: ['connectionOther', 'Other network']
	};

	/** `navigator.connection.effectiveType` speed classes, by string key. */
	var CONNECTION_SPEEDS = {
		'4g': ['speedFast', 'Fast (like 4G or better)'],
		'3g': ['speedSlow', 'Slow (like 3G)'],
		'2g': ['speedVerySlow', 'Very slow (like 2G)'],
		'slow-2g': ['speedSlowest', 'Very slow (below 2G)']
	};

	/** A map's own entry, never one inherited from Object.prototype. */
	function own(map, key) {
		return Object.prototype.hasOwnProperty.call(map, key) ? map[key] : null;
	}

	function checkConnection() {
		if (navigator.onLine === false) {
			set('connection', 'fail', text('connectionOffline', 'Offline'), 'helpOffline');
			return;
		}
		var info = navigator.connection;
		// Always the same pair, on every device: the medium after
		// "Online", the speed after "Speed", and "not reported" in the slot
		// the browser leaves empty. `type` exists only on Chromium for
		// Android and ChromeOS; `effectiveType` is a speed class the
		// browser estimates, never the network generation (#2060).
		var medium = info && own(CONNECTION_TYPES, info.type);
		var speed = info && own(CONNECTION_SPEEDS, info.effectiveType);
		var value = fill(text('connectionType', 'Online/Speed: %1$s / %2$s'), [
			medium ? text(medium[0], medium[1]) : text('connectionTypeUnknown', 'Connection type not reported'),
			speed ? text(speed[0], speed[1]) : text('connectionSpeedUnknown', 'Connection speed not reported')
		]);
		if (info && info.saveData) {
			set('connection', 'warn', value, 'helpDataSaver');
			return;
		}
		if (!medium && !speed) {
			// Nothing to fix, but the visitor deserves to know why: iOS gives
			// no browser this, and neither do Safari on a Mac and Firefox.
			var ns = window.FFC || {};
			var ios = ns.Platform && typeof ns.Platform.ios === 'function' && ns.Platform.ios();
			set('connection', 'info', value, ios ? 'helpConnectionIos' : 'helpConnectionBrowser', true);
			return;
		}
		set('connection', 'info', value);
	}

	/**
	 * The location row (#2062): the permission's answer, and the precision
	 * once the visitor asks for a reading, in one row. The row takes the
	 * worse of the two states and that one's fix.
	 */
	var geo = { perm: null, acc: null, reading: false };

	function paintLocation() {
		if (geo.reading || !geo.perm) {
			return;
		}
		var perm = geo.perm;
		var acc = geo.acc;
		var worst = acc && RANK[acc.state] > RANK[perm.state] ? acc : perm;
		set('location', worst.state, acc
			? fill(text('locationWithAccuracy', '%1$s · %2$s'), [perm.value, acc.value])
			: perm.value, worst.help);
	}

	function noGeolocation() {
		geo.perm = { state: 'fail', value: text('noGeolocation', 'This browser cannot give a location.'), help: 'helpNoGeolocation' };
		paintLocation();
	}

	function checkLocationPermission() {
		if (!navigator.geolocation) {
			noGeolocation();
			return;
		}
		// The queue module confirms a "denied" before it is believed
		// (#2048), as the forms do; the confirming read can take seconds.
		if (!geo.reading) {
			pending('location', text('locationChecking', 'Asking the browser…'));
		}
		window.FFC.Preflight.geoPermission().then(function (answer) {
			if (answer.state === 'granted') {
				geo.perm = { state: 'ok', value: text('locationGranted', 'Allowed') };
			} else if (answer.state === 'denied') {
				geo.perm = { state: 'fail', value: text('locationDenied', 'Blocked: allow it in the browser settings.'), help: locationHelp() };
			} else if (answer.state === 'prompt') {
				geo.perm = { state: 'info', value: text('locationPrompt', 'Not decided yet: the browser will ask.') };
			} else {
				geo.perm = { state: 'info', value: text('locationUnknown', 'The browser does not say until it asks.') };
			}
			paintLocation();
		});
	}

	function readAccuracy() {
		if (!navigator.geolocation) {
			noGeolocation();
			return;
		}
		geo.reading = true;
		pending('location', text('accuracyReading', 'Reading your location…'));
		var started = Date.now();
		var done = function (acc) {
			geo.reading = false;
			geo.acc = acc;
			// The permission may have just been granted, or refused.
			checkLocationPermission();
		};
		navigator.geolocation.getCurrentPosition(
			function (position) {
				var metres = Math.round(position.coords.accuracy);
				var seconds = ((Date.now() - started) / 1000).toFixed(1);
				done({
					state: metres <= 100 ? 'ok' : 'warn',
					value: text('accuracyResult', 'About %1$s m, read in %2$s s').replace('%1$s', String(metres)).replace('%2$s', seconds),
					help: 'helpAccuracy'
				});
			},
			function (error) {
				var code = error && error.code;
				if (code === 1) {
					done({ state: 'fail', value: text('accuracyDenied', 'Refused by the browser or by you.'), help: locationHelp() });
				} else if (code === 3) {
					done({ state: 'fail', value: text('accuracyTimeout', 'Took too long: move to an open area and try again.'), help: 'helpAccuracy' });
				} else {
					done({ state: 'fail', value: text('accuracyFailed', 'Could not be read: move to an open area and try again.'), help: 'helpAccuracy' });
				}
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
		var title = document.querySelector('.ffc-device-check-verdict .ffc-notice-title');
		if (title) {
			lines.push(title.textContent);
		}
		document.querySelectorAll('.ffc-device-check-row').forEach(function (el) {
			lines.push(el.querySelector('.ffc-device-check-label').textContent + ': ' + el.querySelector('.ffc-device-check-result').textContent);
		});
		// What the site runs, so support need not ask (#2062): the version
		// the page was built with and the two settings a blocker interacts
		// with.
		var modes = { math: ['captchaModeMath', 'math question'], altcha: ['captchaModeAltcha', 'ALTCHA'], both: ['captchaModeBoth', 'ALTCHA with a math question when JavaScript is off'] };
		var mode = own(modes, cfg.captcha);
		lines.push(text('summaryVersion', 'Plugin version: %s').replace('%s', (window.ffcCoreConfig && window.ffcCoreConfig.version) || '?'));
		lines.push(text('summaryCaptcha', 'Captcha mode: %s').replace('%s', mode ? text(mode[0], mode[1]) : '?'));
		lines.push(text('summaryDeviceLimit', 'Device limit: %s').replace('%s', window.ffc_device_config ? text('summaryOn', 'on') : text('summaryOff', 'off')));
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
		var root = document.querySelector('.ffc-device-check');
		if (!preflight || !platform) {
			// A script this one needs was stopped: say so now rather than
			// after the stylesheet's delay (#2062).
			if (root) {
				root.classList.add('is-stalled');
			}
			return;
		}
		if (root) {
			root.classList.add('is-running');
		}

		set('secure', preflight.secureContext() ? 'ok' : 'fail', preflight.secureContext()
			? text('secureYes', 'Yes')
			: text('secureNo', 'No: the browser gives no location here, and forms that ask for it will stop.'), 'helpSecure');

		checkBrowser(platform);

		set('cookies', preflight.cookiesWork() ? 'ok' : 'fail', preflight.cookiesWork()
			? text('cookiesYes', 'Allowed')
			: text('cookiesNo', 'Blocked: sending a form may fail.'), 'helpCookies');

		var missing = preflight.missingFeatures();
		set('pdf', missing.length ? 'fail' : 'ok', missing.length
			? text('pdfNo', 'Not supported: missing %s.').replace('%s', missing.join(', '))
			: text('pdfYes', 'Supported: this browser can build and save the PDF.'), 'helpPdf');

		checkServer();
		checkStorage();
		checkCaptcha();
		checkDevice();
		checkClock();
		checkLocale();
		checkScreen();
		checkConnection();
		checkTheme();
		checkLocationPermission();

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
