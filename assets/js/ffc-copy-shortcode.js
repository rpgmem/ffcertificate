/**
 * Copy-to-clipboard for the shortcode buttons that `AdminUI::render_shortcode_copy()`
 * prints in admin list tables: the certificate forms list (`[ffc_form id=…]`)
 * and the audience calendar and environment lists (`[ffc_audience …]`, #1814).
 * Each `.ffc-copy-shortcode` button carries the shortcode in `data-shortcode`;
 * clicking copies it (Clipboard API with a legacy `execCommand('copy')`
 * fallback). A copy that worked shows the check mark in the success tone
 * for a moment and is announced through a polite region created at load,
 * with the button's `data-copied` text (#2344).
 *
 * Named `ffc-form-list-copy-shortcode.js` while it served only the forms list.
 * Extracted from an inline <script> in class-ffc-form-list-columns.php
 * (Item 10 of the frontend audit). The original printed in `admin_head`
 * (before DOMContentLoaded); the enqueued asset ships in the footer, which
 * may run AFTER DOMContentLoaded has fired — so we guard on readyState and
 * bind immediately when the document is already parsed. No server-side
 * interpolation; everything comes from the DOM.
 */
(function () {
	var live = null;

	function legacyCopy(text) {
		var t = document.createElement('textarea');
		t.value = text;
		document.body.appendChild(t);
		t.select();
		var ok = false;
		try {
			ok = document.execCommand('copy');
		} catch (e) {
			ok = false;
		}
		document.body.removeChild(t);
		return ok;
	}

	function copied(btn) {
		btn.classList.add('is-copied', 'ffc-icon-checkmark');
		btn.classList.remove('ffc-icon-copy');
		if (live) {
			live.textContent = btn.getAttribute('data-copied') || 'Copied!';
		}
		setTimeout(function () {
			btn.classList.remove('is-copied', 'ffc-icon-checkmark');
			btn.classList.add('ffc-icon-copy');
			if (live) {
				live.textContent = '';
			}
		}, 1500);
	}

	function init() {
		var buttons = document.querySelectorAll('.ffc-copy-shortcode');
		if (!buttons.length) {
			return;
		}
		// In the document before it is filled, so the first copy is heard.
		live = document.createElement('span');
		live.className = 'screen-reader-text ffc-copy-shortcode-live';
		live.setAttribute('role', 'status');
		live.setAttribute('aria-live', 'polite');
		document.body.appendChild(live);

		buttons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				var shortcode = btn.getAttribute('data-shortcode');
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(shortcode).then(function () {
						copied(btn);
					}, function () {
						if (legacyCopy(shortcode)) {
							copied(btn);
						}
					});
				} else if (legacyCopy(shortcode)) {
					copied(btn);
				}
			});
		});
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
