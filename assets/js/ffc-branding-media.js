/**
 * FFC Branding — generic media picker for Settings → General logo fields (#865).
 *
 * A "Select image" button with data-ffc-media-target="#input_id" opens the
 * WordPress Media Library and writes the chosen attachment URL into that input;
 * a "Clear" button empties it. Mirrors the Email Model logo picker but is
 * data-attribute driven so it works for any number of fields on the page.
 *
 * Dependencies: jQuery, WordPress Media API (wp.media, enqueued via
 * wp_enqueue_media() on FFC admin pages).
 */
(function ($) {
    'use strict';

    function targetInput(el) {
        var sel = $(el).data('ffc-media-target');
        return sel ? $(sel) : $();
    }

    $(document).on('click', '.ffc-media-select', function (e) {
        e.preventDefault();
        if (!window.wp || !window.wp.media) {
            return;
        }
        var $input = targetInput(this);
        if (!$input.length) {
            return;
        }
        var cfg = (typeof window.ffcBrandingMedia !== 'undefined') ? window.ffcBrandingMedia : {};
        var frame = wp.media({
            title: cfg.chooseImage || 'Select image',
            multiple: false,
            library: { type: 'image' }
        });
        frame.on('select', function () {
            var att = frame.state().get('selection').first().toJSON();
            if (att && att.url) {
                $input.val(att.url).trigger('change');
            }
        });
        frame.open();
    });

    $(document).on('click', '.ffc-media-clear', function (e) {
        e.preventDefault();
        var $input = targetInput(this);
        if ($input.length) {
            $input.val('').trigger('change');
        }
    });
})(jQuery);
