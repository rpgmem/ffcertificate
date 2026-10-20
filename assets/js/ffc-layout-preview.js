/**
 * Layout preview capture.
 *
 * Draws a certificate page the way the PDF generator does, up to the canvas,
 * and resolves with it as a PNG data URL. The PDF generator hands that canvas
 * to jsPDF; a preview only needs the picture, so jsPDF is not loaded here.
 *
 * Needs html2canvas on the page (window.html2canvas), enqueued by the caller.
 *
 * @since 6.42.0
 */
(function (window, document) {
	'use strict';

	// html2canvas reads an image only once it has decoded, so wait for each
	// one; a failed image still resolves, so one broken URL cannot hang the
	// preview (it simply paints without the image).
	function waitForImages(root) {
		var pending = Array.prototype.map.call(root.querySelectorAll('img'), function (img) {
			if (img.complete) {
				return Promise.resolve();
			}
			return new Promise(function (resolve) {
				img.onload = resolve;
				img.onerror = resolve;
			});
		});
		return Promise.all(pending);
	}

	/**
	 * @param {string} html   Page markup from FormLayoutPreview (already sanitised on the server).
	 * @param {number} width  Page width in CSS pixels.
	 * @param {number} height Page height in CSS pixels.
	 * @return {Promise<string>} PNG data URL of the page.
	 */
	function capture(html, width, height) {
		var host = document.createElement('div');
		host.setAttribute('aria-hidden', 'true');
		// Painted in place but behind everything: html2canvas reads the layout
		// from the DOM, and a hidden element would be captured as nothing.
		host.setAttribute('style', 'position:fixed;left:0;top:0;z-index:-1;pointer-events:none;overflow:hidden;width:' + width + 'px;height:' + height + 'px;');
		host.innerHTML = html;
		document.body.appendChild(host);

		function remove() {
			if (host.parentNode) {
				host.parentNode.removeChild(host);
			}
		}

		return waitForImages(host)
			.then(function () {
				return window.html2canvas(host, {
					scale: 2,
					width: width,
					height: height,
					backgroundColor: null,
					useCORS: true,
					logging: false
				});
			})
			.then(function (canvas) {
				// A cross-origin background taints the canvas; toDataURL throws
				// then, and the caller shows the preview as unavailable.
				return canvas.toDataURL('image/png');
			})
			.then(function (dataUrl) {
				remove();
				return dataUrl;
			}, function (error) {
				remove();
				throw error;
			});
	}

	window.FFCLayoutPreview = { capture: capture };
})(window, document);
