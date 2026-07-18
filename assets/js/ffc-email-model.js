/**
 * Email Model editor (Settings → SMTP).
 *
 * Wires the "Email Model" box: wp-color-picker on the color inputs, a media
 * uploader for the header logo, a restore-to-defaults button, and a
 * client-side live preview that mirrors templates/emails/layout.php.
 *
 * Config is injected via wp_localize_script as `ffcEmailModel`:
 *   { defaults, fontStacks, siteName, tokens }.
 */
/* global ffcEmailModel */
jQuery(function ($) {
	'use strict';

	var cfg = window.ffcEmailModel || {};
	var defaults = cfg.defaults || {};
	var fontStacks = cfg.fontStacks || {};
	var tokens = cfg.tokens || {};
	var $box = $('#ffc-email-model');
	if (!$box.length) {
		return;
	}

	function esc(str) {
		return String(str == null ? '' : str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function intVal(v, fallback) {
		var n = parseInt(v, 10);
		return isNaN(n) || n < 0 ? fallback : n;
	}

	// Read every [data-ffc-model-field] control into a plain object.
	function collect() {
		var v = {};
		$box.find('[data-ffc-model-field]').each(function () {
			v[$(this).data('ffc-model-field')] = $(this).val();
		});
		return v;
	}

	// Resolve the footer {{tokens}} for the preview using localized samples.
	function resolveFooter(text) {
		var out = String(text || '');
		Object.keys(tokens).forEach(function (k) {
			out = out.split(k).join(tokens[k]);
		});
		return out;
	}

	function buildPreview(v) {
		var font = fontStacks[v.body_font_family] || fontStacks.system || 'sans-serif';
		var maxW = intVal(v.body_max_width, 600);
		var header = v.header_logo_url
			? '<img src="' + esc(v.header_logo_url) + '" alt="" width="' + intVal(v.header_logo_max_width, 180) +
				'" style="display:inline-block;max-width:' + intVal(v.header_logo_max_width, 180) + 'px;height:auto;">'
			: '<span style="font-size:22px;font-weight:600;color:' + esc(v.header_text_color) + ';">' + esc(cfg.siteName || '') + '</span>';
		var footer = resolveFooter(v.footer_text);
		var miolo =
			'<h2 style="margin:0 0 16px;color:#0073aa;font-size:22px;">' + esc(cfg.sampleTitle || 'Sample email') + '</h2>' +
			'<p style="margin:0 0 12px;">' + esc(cfg.sampleBody || 'This is how your emails will look.') + '</p>' +
			'<p style="margin:0;"><a href="#">' + esc(cfg.sampleLink || 'A sample link') + '</a></p>';

		return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' +
			'body{margin:0;padding:0;} .ffc-email-body a{color:' + esc(v.body_link_color) + ';}' +
			'</style></head>' +
			'<body style="margin:0;padding:0;background-color:' + esc(v.wrapper_bg) + ';font-family:' + font + ';">' +
			'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:' + esc(v.wrapper_bg) +
				';padding:' + intVal(v.wrapper_padding, 32) + 'px 0;"><tr><td align="center">' +
			'<table role="presentation" width="' + maxW + '" cellpadding="0" cellspacing="0" style="width:' + maxW +
				'px;max-width:100%;background-color:' + esc(v.body_bg) + ';border-radius:' + intVal(v.wrapper_border_radius, 6) + 'px;overflow:hidden;">' +
			'<tr><td align="' + esc(v.header_alignment) + '" style="background-color:' + esc(v.header_bg) + ';color:' + esc(v.header_text_color) +
				';padding:' + intVal(v.header_padding, 24) + 'px;font-family:' + font + ';">' + header + '</td></tr>' +
			'<tr><td class="ffc-email-body" style="background-color:' + esc(v.body_bg) + ';color:' + esc(v.body_text_color) +
				';font-family:' + font + ';font-size:' + intVal(v.body_font_size, 14) + 'px;line-height:1.6;padding:' + intVal(v.body_padding, 24) + 'px;">' + miolo + '</td></tr>' +
			(String(footer).trim() !== ''
				? '<tr><td align="center" style="background-color:' + esc(v.footer_bg) + ';color:' + esc(v.footer_text_color) +
					';font-family:' + font + ';font-size:12px;line-height:1.6;padding:16px ' + intVal(v.body_padding, 24) + 'px;">' + footer + '</td></tr>'
				: '') +
			'</table></td></tr></table></body></html>';
	}

	function refreshPreview() {
		var frame = $box.find('.ffc-email-model-preview-frame').get(0);
		if (frame) {
			frame.srcdoc = buildPreview(collect());
		}
	}

	// Color pickers.
	if ($.fn.wpColorPicker) {
		$box.find('.ffc-em-color').wpColorPicker({ change: refreshPreview, clear: refreshPreview });
	}

	// Live preview on any field change.
	$box.on('change keyup', '[data-ffc-model-field]', refreshPreview);

	// Header logo media uploader.
	$box.on('click', '.ffc-em-logo-select', function (e) {
		e.preventDefault();
		if (!window.wp || !window.wp.media) {
			return;
		}
		var frame = wp.media({ title: cfg.chooseLogo || 'Select image', multiple: false, library: { type: 'image' } });
		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			$box.find('[data-ffc-model-field="header_logo_url"]').val(att.url).trigger('change');
		});
		frame.open();
	});
	$box.on('click', '.ffc-em-logo-clear', function (e) {
		e.preventDefault();
		$box.find('[data-ffc-model-field="header_logo_url"]').val('').trigger('change');
	});

	// Restore defaults (fills the fields; the user still clicks Save to persist).
	$box.on('click', '.ffc-email-model-restore', function (e) {
		e.preventDefault();
		if (cfg.confirmRestore && !window.confirm(cfg.confirmRestore)) {
			return;
		}
		$box.find('[data-ffc-model-field]').each(function () {
			var $f = $(this);
			var key = $f.data('ffc-model-field');
			if (!(key in defaults)) {
				return;
			}
			if ($f.hasClass('ffc-em-color') && $f.wpColorPicker) {
				$f.wpColorPicker('color', String(defaults[key]));
			} else {
				$f.val(defaults[key]);
			}
		});
		refreshPreview();
	});

	refreshPreview();
});
