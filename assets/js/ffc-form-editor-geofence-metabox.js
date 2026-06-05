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
