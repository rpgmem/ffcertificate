/**
 * Server crontab-line generator on the Scheduled Tasks settings tab (#1538).
 *
 * Every line is built and quoted in PHP and localized as
 * `ffcScheduledTasks.lines[<method>][<minutes>]`; this only swaps the value of
 * the read-only input when the method or the frequency changes, so no shell
 * command is ever assembled in the browser. Copying reuses the shared
 * `.ffc-copy-link` handler. Selector-guarded: a no-op on any other screen.
 */
jQuery(function ($) {
	'use strict';

	var lines = (window.ffcScheduledTasks && window.ffcScheduledTasks.lines) || {};
	var $method = $('#ffc-crontab-method');
	var $minutes = $('#ffc-crontab-minutes');
	var $line = $('#ffc-crontab-line');

	if (!$line.length) {
		return;
	}

	function refresh() {
		var byMethod = lines[String($method.val())] || {};
		var value = byMethod[String($minutes.val())];
		$line.val(typeof value === 'string' ? value : '');
	}

	$method.on('change', refresh);
	$minutes.on('change', refresh);
	refresh();
});
