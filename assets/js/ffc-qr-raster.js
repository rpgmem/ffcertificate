/**
 * QR image helpers shared by the short URL screens and the manual QR
 * generator (#1563): save a base64 payload as a file, and rasterise a
 * designed SVG to PNG.
 *
 * The server cannot draw the QR design with GD, so a designed code travels
 * as SVG and the browser draws it to a canvas; the PNG then matches the
 * preview. Exposed as `window.FFC.QrRaster`.
 */
(function () {
    'use strict';

    /**
     * Save base64 data as a file.
     *
     * @param {string} base64Data Payload.
     * @param {string} filename   File name.
     * @param {string} mime       MIME type.
     */
    function download(base64Data, filename, mime) {
        var byteChars = atob(base64Data);
        var byteNumbers = new Array(byteChars.length);
        for (var i = 0; i < byteChars.length; i++) {
            byteNumbers[i] = byteChars.charCodeAt(i);
        }
        var blob = new Blob([new Uint8Array(byteNumbers)], { type: mime });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    /**
     * Draw a base64 SVG onto a canvas `width` wide and resolve with base64 PNG.
     *
     * A frame makes a code taller than wide, so the height follows the
     * image's own ratio. Rejects when the image does not load or the canvas
     * cannot encode.
     *
     * @param {string} svgBase64 SVG, base64-encoded.
     * @param {number} width     Output width in pixels.
     * @returns {Promise<string>} Base64 PNG.
     */
    function toPng(svgBase64, width) {
        return new Promise(function (resolve, reject) {
            var img = new Image();
            img.onload = function () {
                try {
                    var ratio = img.naturalWidth > 0 ? img.naturalHeight / img.naturalWidth : 1;
                    var height = Math.round(width * (ratio > 0 ? ratio : 1));
                    var canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    canvas.getContext('2d').drawImage(img, 0, 0, width, height);
                    resolve(canvas.toDataURL('image/png').split(',')[1]);
                } catch (e) {
                    reject(e);
                }
            };
            img.onerror = reject;
            img.src = 'data:image/svg+xml;base64,' + svgBase64;
        });
    }

    /**
     * Base64-encode a UTF-8 string (an SVG may carry a non-ASCII caption).
     *
     * @param {string} text Text.
     * @returns {string}
     */
    function encode(text) {
        var bytes = new TextEncoder().encode(text);
        var binary = '';
        for (var i = 0; i < bytes.length; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary);
    }

    window.FFC = window.FFC || {};
    window.FFC.QrRaster = { download: download, toPng: toPng, encode: encode };
})();
