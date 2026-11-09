/**
 * Certificate render preparation (#2569).
 *
 * The step every certificate capture runs between building the page and
 * handing it to html2canvas, shared by the PDF generator and the editor
 * preview so the two cannot drift: what the operator previews is what the
 * participant downloads.
 *
 * Today it waits for the fonts the page uses. A font the site installed
 * through the Font Library loads only once an element names it, so the
 * capture would otherwise draw the fallback while the real face is still on
 * its way. The wait is bounded: a font that never arrives must not hold the
 * certificate hostage, and the capture then draws what the browser has.
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

	/**
	 * Prepare a built certificate page for capture.
	 *
	 * @param {Element} root The element html2canvas will draw.
	 * @return {Promise<void>}
	 */
	function prepare(root) { // eslint-disable-line no-unused-vars
		return waitForFonts();
	}

	window.FFCCertificateRender = {
		FONT_WAIT_MS: FONT_WAIT_MS,
		waitForFonts: waitForFonts,
		prepare: prepare
	};
})(window, document);
