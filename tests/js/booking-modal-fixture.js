// The audience booking dialog's markup (#2008), shared by the tests that
// drive it. It mirrors what `AudienceShortcode::render_calendar_html()`
// prints -- the same ids, names and classes, without the labels' text --
// so a test fails when a script looks for something the dialog no longer
// carries, instead of passing against a fixture of its own.

/**
 * One audience row as `populateAudienceSelect()` renders it.
 *
 * @param {number} id
 * @param {string} name
 * @param {Object} [opts]
 * @param {number} [opts.parent] The parent audience id.
 * @param {number} [opts.depth]
 * @return {string}
 */
export function audienceRow(id, name, opts = {}) {
	const parent = opts.parent ? ` data-parent="${opts.parent}"` : '';
	return `<label class="ffc-audience-picker-option" data-depth="${opts.depth || 0}">` +
		`<input type="checkbox" name="audience_ids[]" value="${id}"${parent}>` +
		'<span class="ffc-color-dot" aria-hidden="true"></span>' +
		`<span class="ffc-audience-picker-name">${name}</span></label>`;
}

/**
 * The booking modal.
 *
 * @param {Object} [opts]
 * @param {string} [opts.audiences] Rows for the audience list.
 * @return {string}
 */
export function bookingModalHtml(opts = {}) {
	return `
		<div class="ffc-shortcode ffc-modal ffc-booking-modal" id="ffc-booking-modal" style="display:none">
			<div class="ffc-modal-backdrop"></div>
			<div class="ffc-modal-content ffc-booking-dialog">
				<div class="ffc-modal-header ffc-booking-header">
					<h3 id="ffc-booking-modal-title">New Booking</h3>
					<p class="ffc-booking-date-display ffc-icon-calendar"></p>
					<button type="button" class="ffc-btn ffc-btn-neutral ffc-btn-icon ffc-icon-cross ffc-modal-close" aria-label="Close"></button>
				</div>
				<div class="ffc-modal-body ffc-booking-body">
					<form id="ffc-booking-form" class="ffc-audience-booking-form">
						<input type="hidden" name="booking_date" id="booking-date">
						<fieldset id="booking-span" class="ffc-control-segmented">
							<label><input type="radio" id="booking-span-1" name="booking_span" value="single" checked></label>
							<label><input type="radio" id="booking-span-2" name="booking_span" value="range"></label>
						</fieldset>
						<div class="ffc-form-group" id="booking-end-date-group" hidden>
							<input type="date" name="end_date" id="booking-end-date">
						</div>
						<div class="ffc-booking-field" id="booking-all-day-group">
							<label class="ffc-control-toggle" for="booking-all-day"><input type="checkbox" id="booking-all-day" name="is_all_day" value="1"></label>
						</div>
						<div class="ffc-form-row" id="booking-time-row">
							<input type="time" name="start_time" id="booking-start-time" required>
							<input type="time" name="end_time" id="booking-end-time" required>
						</div>
						<div class="ffc-booking-range" id="booking-range-options" hidden>
							<label class="ffc-booking-choice"><input type="radio" name="time_mode" value="daily" checked><span class="ffc-booking-choice-hint" data-ffc-time-mode-hint="daily"></span></label>
							<label class="ffc-booking-choice"><input type="radio" name="time_mode" value="span"><span class="ffc-booking-choice-hint" data-ffc-time-mode-hint="span"></span></label>
							<label class="ffc-control-toggle" for="booking-skip-blocked"><input type="checkbox" id="booking-skip-blocked" name="skip_blocked" value="1"></label>
						</div>
						<label for="booking-environment-id">Env *</label>
						<select name="environment_id" id="booking-environment-id" required></select>
						<fieldset id="booking-type" class="ffc-control-segmented">
							<label><input type="radio" id="booking-type-1" name="booking_type" value="audience" checked></label>
							<label><input type="radio" id="booking-type-2" name="booking_type" value="individual"></label>
						</fieldset>
						<div class="ffc-booking-field" id="audience-select-group">
							<button type="button" class="ffc-audience-picker-summary ffc-icon-chevron-right" id="booking-audience-summary" aria-controls="booking-audience-picker" aria-expanded="false">
								<span class="ffc-audience-picker-summary-value" id="booking-audience-summary-value">None selected</span>
							</button>
							<div class="ffc-audience-picker" id="booking-audience-picker">
								<div class="ffc-audience-picker-bar">
									<button type="button" class="ffc-btn ffc-btn-primary ffc-icon-checkmark ffc-audience-picker-done">Done</button>
								</div>
								<div class="ffc-audience-picker-search ffc-icon-search">
									<input type="search" id="booking-audience-search">
								</div>
								<div class="ffc-audience-picker-list" id="booking-audiences" role="group">${opts.audiences || ''}</div>
								<div class="ffc-audience-picker-chips" id="booking-audience-chips" hidden></div>
							</div>
						</div>
						<div class="ffc-form-group" id="user-select-group" hidden>
							<input type="text" id="booking-user-search">
							<div id="booking-user-results" class="ffc-user-results"></div>
							<div id="booking-selected-users" class="ffc-selected-users"></div>
							<input type="hidden" name="user_ids" id="booking-user-ids">
						</div>
						<div class="ffc-form-group">
							<textarea name="description" id="booking-description">A valid description here</textarea>
							<span id="desc-char-count">0</span>
						</div>
						<div id="ffc-conflict-warning" hidden>
							<label class="ffc-conflict-acknowledge"><input type="checkbox" id="ffc-conflict-acknowledge"> I am aware</label>
						</div>
						<div id="ffc-conflict-error" hidden></div>
					</form>
				</div>
				<div class="ffc-modal-footer ffc-booking-footer">
					<button type="button" class="ffc-btn ffc-btn-neutral ffc-icon-cross ffc-modal-cancel">Cancel</button>
					<button type="button" class="ffc-btn ffc-btn-primary ffc-icon-search" id="ffc-booking-submit" data-step="check">Check Conflicts</button>
				</div>
			</div>
		</div>
	`;
}
