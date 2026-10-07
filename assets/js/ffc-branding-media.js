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
        // data-ffc-media-value="id" stores the attachment id instead of its URL
        // (the QR logo, #1563); data-ffc-media-thumb names an <img> to refresh.
        var asId = $(this).data('ffc-media-value') === 'id';
        var thumb = $(this).data('ffc-media-thumb');
        frame.on('select', function () {
            var att = frame.state().get('selection').first().toJSON();
            if (!att || !att.url) {
                return;
            }
            $input.val(asId ? String(att.id) : att.url).trigger('change');
            if (thumb) {
                var src = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
                $(thumb).attr('src', src).prop('hidden', false);
            }
        });
        frame.open();
    });

    $(document).on('click', '.ffc-media-clear', function (e) {
        e.preventDefault();
        var $input = targetInput(this);
        if ($input.length) {
            $input.val($(this).data('ffc-media-value') === 'id' ? '0' : '').trigger('change');
        }
        var thumb = $(this).data('ffc-media-thumb');
        if (thumb) {
            $(thumb).attr('src', '').prop('hidden', true);
        }
    });
})(jQuery);
