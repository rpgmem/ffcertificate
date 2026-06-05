/**
 * Form-editor geofence metabox UI wiring (admin).
 *
 * Two independent jQuery initializers, extracted verbatim from inline
 * <script> blocks in class-ffc-form-editor-geofence-metabox.php (Item 10
 * of the frontend audit). Both are selector-guarded — they no-op on a page
 * that doesn't render the corresponding section — so the single asset can be
 * enqueued from either render method (render_time / render_geolocation)
 * without duplication. No server-side interpolation.
 */

// Date/Time Restrictions: the "Display during, outside daily slot" row has a
// dual gate — it's only meaningful when the event spans multiple days AND the
// operator picked per-day time semantics. The outer .ffc-collapsed-target
// initializer handles the multi-day side for the End Date / Time Behavior
// rows; the during-row needs the AND of both, so we wire it manually here.
// Without this, flipping time_mode between span and daily wouldn't toggle the
// row at runtime (we currently only honour the initial SSR state).
jQuery(function ($) {
	function syncDuringRow() {
		var multi   = $('#ffc_geofence_multi_day').is(':checked');
		var isDaily = $('input[name="ffc_geofence[time_mode]"]:checked').val() === 'daily';
		$('#ffc-datetime-hide-mode-during-row').toggle( multi && isDaily );
	}
	$('#ffc_geofence_multi_day, input[name="ffc_geofence[time_mode]"]').on('change', syncDuringRow);
	syncDuringRow();

	// End date floor: with multi-day on, the End must be at least the day after
	// Start. Keep the native `min` in sync as Start changes so the browser's
	// date picker flags an out-of-range End live (server-side validate_datetime
	// is the authority on save).
	//
	// Only enforce `min` while multi-day is ON. When OFF the End field is hidden
	// and mirrors Start, so a `min` of start+1 would make the hidden control
	// fail native validation and block submission with a non-focusable error —
	// remove it in that state. Re-runs when multi-day toggles.
	function syncEndDateMin() {
		var $start = $('#ffc_geofence_date_start');
		var $end   = $('#ffc_geofence_date_end');
		if ( ! $start.length || ! $end.length ) { return; }
		if ( ! $('#ffc_geofence_multi_day').is(':checked') ) { $end.removeAttr('min'); return; }
		var startVal = $start.val();
		if ( ! startVal ) { $end.removeAttr('min'); return; }
		var d = new Date(startVal + 'T00:00:00');
		if ( isNaN(d.getTime()) ) { $end.removeAttr('min'); return; }
		d.setDate(d.getDate() + 1);
		var mm = String(d.getMonth() + 1).padStart(2, '0');
		var dd = String(d.getDate()).padStart(2, '0');
		$end.attr('min', d.getFullYear() + '-' + mm + '-' + dd);
	}
	$('#ffc_geofence_date_start, #ffc_geofence_multi_day').on('change', syncEndDateMin);
	syncEndDateMin();
});

// Geolocation: each area picker (GPS + IP) toggles between the saved-locations
// list and the custom-polygon editor based on the selected source radio.
jQuery(function ($) {
	function toggleGeoSource(prefix) {
		var source = $('input[name="ffc_geofence[' + prefix + '_source]"]:checked').val();
		var container = $('input[name="ffc_geofence[' + prefix + '_source]"]').closest('td');
		container.find('.ffc-geo-source-locations')[source === 'locations' ? 'show' : 'hide']();
		container.find('.ffc-geo-source-custom')[source === 'custom' ? 'show' : 'hide']();
	}
	$('input[name="ffc_geofence[geo_area_source]"]').on('change', function () { toggleGeoSource('geo_area'); });
	$('input[name="ffc_geofence[geo_ip_area_source]"]').on('change', function () { toggleGeoSource('geo_ip_area'); });
	toggleGeoSource('geo_area');
	toggleGeoSource('geo_ip_area');

	// IP-Areas-Permissive collapse is now handled by the generic
	// `.ffc-collapsed-target` initializer in ffc-admin.js (#238 / Sprint 3)
	// — the container above carries
	// data-ffc-master="ffc_geofence_geo_ip_areas_permissive".
});
