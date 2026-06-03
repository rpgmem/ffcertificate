/**
 * Tab switching for the audience Import & Export admin screen.
 *
 * Clicking a `.nav-tab` shows its `.ffc-tab-content` panel and hides the
 * others; on load, a `#hash` in the URL re-activates the matching tab so a
 * deep link (or a post-submit redirect with a hash) lands on the right
 * panel.
 *
 * Extracted verbatim from an inline <script> in
 * class-ffc-audience-admin-import.php (Item 10 of the frontend audit). No
 * server-side interpolation — everything comes from the DOM and the URL.
 */
jQuery(function ($) {
	$('.nav-tab-wrapper .nav-tab').on('click', function (e) {
		e.preventDefault();
		var tab = $(this).data('tab');
		$('.nav-tab-wrapper .nav-tab').removeClass('nav-tab-active');
		$(this).addClass('nav-tab-active');
		$('.ffc-tab-content').hide();
		$('#' + tab).show();
	});
	// Restore tab from URL hash.
	if (window.location.hash) {
		var hash = window.location.hash.substring(1);
		var $tab = $('.nav-tab[data-tab="' + hash + '"]');
		if ($tab.length) {
			$tab.trigger('click');
		}
	}
});
