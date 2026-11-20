// FFC.formatDate / FFC.formatTime (#2676): wall-clock values from the server
// print in the plugin's date and time formats, read from ffcCoreConfig.dates.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-core.js');
});

beforeEach(() => {
	window.ffcCoreConfig = {
		dates: {
			date: 'd/m/Y',
			time: 'H:i',
			locale: 'pt-BR',
			months: ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'],
			monthsShort: ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'],
			weekdays: ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'],
			weekdaysShort: ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'],
			meridiem: { am: 'am', pm: 'pm', AM: 'AM', PM: 'PM' },
		},
	};
});

describe('FFC.formatDate', () => {
	it('uses the plugin date format', () => {
		expect(window.FFC.formatDate('2026-03-05')).toBe('05/03/2026');
		window.ffcCoreConfig.dates.date = 'Y-m-d';
		expect(window.FFC.formatDate('2026-03-05')).toBe('2026-03-05');
	});

	it('reads month and weekday names, and escaped characters', () => {
		window.ffcCoreConfig.dates.date = 'l, j \\d\\e F \\d\\e Y';
		expect(window.FFC.formatDate('2026-03-05')).toBe('quinta-feira, 5 de março de 2026');
		window.ffcCoreConfig.dates.date = 'D j M y';
		expect(window.FFC.formatDate('2026-03-05')).toBe('qui 5 mar 26');
	});

	it('writes the long style in the WordPress locale', () => {
		expect(window.FFC.formatDate('2026-03-05', 'long')).toBe(new Date(2026, 2, 5).toLocaleDateString('pt-BR', { year: 'numeric', month: 'long', day: 'numeric' }));
	});

	it('falls back to d/m/Y without config, and leaves a non-date alone', () => {
		window.ffcCoreConfig = {};
		expect(window.FFC.formatDate('2026-03-05')).toBe('05/03/2026');
		expect(window.FFC.formatDate('tomorrow')).toBe('tomorrow');
		expect(window.FFC.formatDate('')).toBe('');
	});
});

describe('FFC.formatTime', () => {
	it('uses the plugin time format', () => {
		expect(window.FFC.formatTime('09:05:00')).toBe('09:05');
		window.ffcCoreConfig.dates.time = 'g:i A';
		expect(window.FFC.formatTime('14:30')).toBe('2:30 PM');
		expect(window.FFC.formatTime('00:15')).toBe('12:15 AM');
	});

	it('leaves a non-time alone', () => {
		expect(window.FFC.formatTime('all day')).toBe('all day');
	});
});
