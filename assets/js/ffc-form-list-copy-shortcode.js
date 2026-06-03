/**
 * Copy-to-clipboard for the `[ffc_form id=…]` shortcode buttons on the
 * forms list table (admin). Each `.ffc-copy-shortcode` button carries the
 * shortcode in `data-shortcode`; clicking copies it (Clipboard API with a
 * legacy `execCommand('copy')` fallback) and flashes a `.copied` class.
 *
 * Extracted from an inline <script> in class-ffc-form-list-columns.php
 * (Item 10 of the frontend audit). The original printed in `admin_head`
 * (before DOMContentLoaded); the enqueued asset ships in the footer, which
 * may run AFTER DOMContentLoaded has fired — so we guard on readyState and
 * bind immediately when the document is already parsed. No server-side
 * interpolation; everything comes from the DOM.
 */
(function () {
	function init() {
		document.querySelectorAll('.ffc-copy-shortcode').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var shortcode = this.getAttribute('data-shortcode');
				if (navigator.clipboard) {
					navigator.clipboard.writeText(shortcode);
				} else {
					var t = document.createElement('textarea');
					t.value = shortcode;
					document.body.appendChild(t);
					t.select();
					document.execCommand('copy');
					document.body.removeChild(t);
				}
				this.classList.add('copied');
				var self = this;
				setTimeout(function () { self.classList.remove('copied'); }, 1500);
			});
		});
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
