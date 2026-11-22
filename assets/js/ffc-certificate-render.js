/**
 * Certificate render preparation (#2569).
 *
 * The step every certificate capture runs between building the page and
 * handing it to html2canvas, shared by the PDF generator and the editor
 * preview so the two cannot drift: what the operator previews is what the
 * participant downloads.
 *
 * First it waits for the fonts the page uses. A font the site installed
 * through the Font Library loads only once an element names it, so the
 * capture would otherwise draw the fallback while the real face is still on
 * its way. The wait is bounded: a font that never arrives must not hold the
 * certificate hostage, and the capture then draws what the browser has.
 *
 * Then it fits the text a template marks with `ffc-fit` or `ffc-fit-box`
 * (#2570): a long name or course title shrinks until it fits its box,
 * measured in the real layout with the real font, so the screen and the
 * capture agree. The template carries a class and nothing else: the
 * sanitizer drops `data-*`, so there is no parameter to pass.
 */
(function (window, document) {
	'use strict';

	/** How long a capture waits for fonts before drawing what it has. */
	var FONT_WAIT_MS = 3000;

	/**
	 * Resolve once the document's fonts are ready, or after the limit.
	 *
	 * Never rejects: a browser without the Font Loading API, or a font that
	 * fails, still lets the capture run.
	 *
	 * @param {number} [limitMs] Upper bound in milliseconds.
	 * @return {Promise<void>}
	 */
	function waitForFonts(limitMs) {
		var fonts = document.fonts;
		if (!fonts || !fonts.ready || typeof fonts.ready.then !== 'function') {
			return Promise.resolve();
		}

		var limit = typeof limitMs === 'number' ? limitMs : FONT_WAIT_MS;

		return new Promise(function (resolve) {
			var settled = false;
			function finish() {
				if (!settled) {
					settled = true;
					resolve();
				}
			}
			window.setTimeout(finish, limit);
			fonts.ready.then(finish, finish);
		});
	}

	/** The smallest share of its own size a fitted text may shrink to. */
	var FIT_FLOOR = 0.5;

	/** Steps of the search between the floor and the full size. */
	var FIT_STEPS = 8;

	/**
	 * Whether a single-line text is wider than its box.
	 *
	 * A block element is its own box. An inline one has no width of its own,
	 * so its box is the parent's content width.
	 *
	 * @param {HTMLElement} el The fitted element.
	 * @return {boolean}
	 */
	function overflowsWidth(el) {
		if (window.getComputedStyle(el).display !== 'inline') {
			return el.scrollWidth > el.clientWidth;
		}

		var parent = el.parentElement;
		if (!parent) {
			return false;
		}
		var style = window.getComputedStyle(parent);
		var room = parent.clientWidth
			- (parseFloat(style.paddingLeft) || 0)
			- (parseFloat(style.paddingRight) || 0);

		return el.getBoundingClientRect().width > room;
	}

	/**
	 * Whether a wrapping text is taller than its box. A box with no height of
	 * its own grows with the text and never overflows, so it is left alone.
	 *
	 * @param {HTMLElement} el The fitted element.
	 * @return {boolean}
	 */
	function overflowsHeight(el) {
		return el.scrollHeight > el.clientHeight;
	}

	/**
	 * Shrink one element's font size until it fits, never below the floor.
	 *
	 * A value that already fits is not touched. Otherwise a binary search
	 * keeps the largest size that fits; when even the floor overflows, the
	 * floor stays, so a pathological value is still readable.
	 *
	 * @param {HTMLElement} el        The fitted element.
	 * @param {function(HTMLElement): boolean} overflows The test for this kind.
	 * @return {void}
	 */
	function fitElement(el, overflows) {
		var base = parseFloat(window.getComputedStyle(el).fontSize);
		if (!base || !overflows(el)) {
			return;
		}

		var low = base * FIT_FLOOR;
		var high = base;

		el.style.fontSize = low + 'px';
		if (overflows(el)) {
			return;
		}

		for (var step = 0; step < FIT_STEPS; step++) {
			var middle = (low + high) / 2;
			el.style.fontSize = middle + 'px';
			if (overflows(el)) {
				high = middle;
			} else {
				low = middle;
			}
		}

		el.style.fontSize = low + 'px';
	}

	/**
	 * Fit every marked element inside a certificate page.
	 *
	 * @param {Element} root The page.
	 * @return {void}
	 */
	function fit(root) {
		if (!root || typeof root.querySelectorAll !== 'function') {
			return;
		}

		Array.prototype.forEach.call(root.querySelectorAll('.ffc-fit'), function (el) {
			fitElement(el, overflowsWidth);
		});
		Array.prototype.forEach.call(root.querySelectorAll('.ffc-fit-box'), function (el) {
			fitElement(el, overflowsHeight);
		});
	}

	/**
	 * Prepare a built certificate page for capture: fonts first, because a
	 * fit measured in the fallback face is wrong once the real one arrives.
	 *
	 * @param {Element} root The element html2canvas will draw.
	 * @return {Promise<void>}
	 */
	function prepare(root) {
		return waitForFonts().then(function () {
			fit(root);
		});
	}

	window.FFCCertificateRender = {
		FONT_WAIT_MS: FONT_WAIT_MS,
		FIT_FLOOR: FIT_FLOOR,
		waitForFonts: waitForFonts,
		fit: fit,
		prepare: prepare
	};
})(window, document);
