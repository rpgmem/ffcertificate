// The participant's check-in page (#1947): the location is read before the
// form posts, and a refusal says why instead of posting without it.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function page(geo) {
	document.body.innerHTML =
		'<form method="post"' + (geo ? ' data-ffc-checkin-geo="1"' : '') + '>' +
		'<input type="hidden" name="ffc_lat" value=""><input type="hidden" name="ffc_lng" value="">' +
		'<p class="ffc-checkin-geo-error" hidden></p>' +
		'<button type="submit" class="ffc-checkin-submit">Confirm</button>' +
		'</form>';
	return document.querySelector('form');
}

function setGeolocation(impl) {
	Object.defineProperty(window.navigator, 'geolocation', { value: impl, configurable: true });
}

describe('ffc-audience-checkin.js', () => {
	beforeEach(() => {
		window.ffcCheckin = { denied: 'Allow it.', unavailable: 'Not read.' };
	});

	it('fills the position and posts once it is read', () => {
		const form = page(true);
		const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => {});
		setGeolocation({ getCurrentPosition: (ok) => ok({ coords: { latitude: -23.5, longitude: -46.6 } }) });
		loadScript('assets/js/ffc-audience-checkin.js');

		form.dispatchEvent(new Event('submit', { cancelable: true }));

		expect(form.elements.ffc_lat.value).toBe('-23.5');
		expect(form.elements.ffc_lng.value).toBe('-46.6');
		expect(submitSpy).toHaveBeenCalledTimes(1);
	});

	it('does not post and says why when the location is refused', () => {
		const form = page(true);
		const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => {});
		setGeolocation({ getCurrentPosition: (ok, fail) => fail({ code: 1 }) });
		loadScript('assets/js/ffc-audience-checkin.js');

		const event = new Event('submit', { cancelable: true });
		form.dispatchEvent(event);

		const error = form.querySelector('.ffc-checkin-geo-error');
		expect(event.defaultPrevented).toBe(true);
		expect(submitSpy).not.toHaveBeenCalled();
		expect(error.hidden).toBe(false);
		expect(error.textContent).toBe('Allow it.');
		expect(form.querySelector('.ffc-checkin-submit').disabled).toBe(false);
	});

	it('reports an unreadable location when the browser has no geolocation', () => {
		const form = page(true);
		setGeolocation(undefined);
		loadScript('assets/js/ffc-audience-checkin.js');

		form.dispatchEvent(new Event('submit', { cancelable: true }));

		expect(form.querySelector('.ffc-checkin-geo-error').textContent).toBe('Not read.');
	});

	it('leaves a form without a geofence alone', () => {
		const form = page(false);
		loadScript('assets/js/ffc-audience-checkin.js');

		const event = new Event('submit', { cancelable: true });
		form.dispatchEvent(event);

		expect(event.defaultPrevented).toBe(false);
	});
});
