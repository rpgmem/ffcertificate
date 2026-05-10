/**
 * FFC User Dashboard — Calendar Export
 *
 * ICS / Google Calendar / Outlook export utilities and the export-button
 * UI dropdown. Used by the appointments and audience-bookings panels.
 *
 * Exposes `FFCDashboard.calExport.buildButton(event)` which returns the
 * dropdown HTML for a single event, and self-binds the global click
 * handlers (toggle, ICS download, click-outside-to-close) on parse.
 *
 * @since 6.5.2
 */

(function ($) {
    'use strict';

    var pad2 = FFCDashboard.helpers.pad2;

    var calIcons = {
        google: '<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="1" y="3" width="14" height="12" rx="2" fill="#fff" stroke="#4285F4" stroke-width="1.5"/><path d="M1 5h14" stroke="#4285F4" stroke-width="1.5"/><circle cx="5" cy="9" r="1.2" fill="#EA4335"/><circle cx="8" cy="9" r="1.2" fill="#FBBC04"/><circle cx="11" cy="9" r="1.2" fill="#34A853"/><path d="M5 1v3M11 1v3" stroke="#4285F4" stroke-width="1.5" stroke-linecap="round"/></svg>',
        outlook: '<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="1" y="3" width="14" height="12" rx="2" fill="#fff" stroke="#0078D4" stroke-width="1.5"/><path d="M1 5h14" stroke="#0078D4" stroke-width="1.5"/><rect x="4" y="7" width="3" height="2.5" rx="0.5" fill="#0078D4"/><rect x="9" y="7" width="3" height="2.5" rx="0.5" fill="#0078D4" opacity="0.5"/><path d="M5 1v3M11 1v3" stroke="#0078D4" stroke-width="1.5" stroke-linecap="round"/></svg>',
        ics: '<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 10V3M8 10l-2.5-2.5M8 10l2.5-2.5" stroke="#666" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 12h10" stroke="#666" stroke-width="1.5" stroke-linecap="round"/></svg>',
        calendar: '<svg width="14" height="14" viewBox="0 0 16 16" fill="none"><rect x="1" y="3" width="14" height="12" rx="2" fill="none" stroke="#fff" stroke-width="1.5"/><path d="M1 7h14" stroke="#fff" stroke-width="1.2"/><path d="M5 1v3M11 1v3" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/></svg>'
    };

    function parseTime(timeStr) {
        if (!timeStr) return { h: 0, m: 0 };
        var parts = timeStr.split(':');
        return { h: parseInt(parts[0], 10) || 0, m: parseInt(parts[1], 10) || 0 };
    }

    function toIcsDateTime(dateRaw, timeStr) {
        var d = dateRaw.replace(/-/g, '');
        var t = parseTime(timeStr);
        return d + 'T' + pad2(t.h) + pad2(t.m) + '00';
    }

    function toGoogleDateTime(dateRaw, timeStr) { return toIcsDateTime(dateRaw, timeStr); }

    function toOutlookDateTime(dateRaw, timeStr) {
        var t = parseTime(timeStr);
        return dateRaw + 'T' + pad2(t.h) + ':' + pad2(t.m) + ':00';
    }

    function escapeIcsText(text) {
        if (!text) return '';
        return text.replace(/\\/g, '\\\\').replace(/\r/g, '').replace(/\n/g, '\\n').replace(/,/g, '\\,').replace(/;/g, '\\;');
    }

    function generateICS(event) {
        var domain = window.location.hostname;
        var uid = (event.uid || 'ffc-' + Date.now()) + '@' + domain;
        var dtstamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '');

        var ics = 'BEGIN:VCALENDAR\r\n';
        ics += 'VERSION:2.0\r\n';
        ics += 'PRODID:-//' + (ffcDashboard.siteName || domain) + '//FFC//PT\r\n';
        ics += 'CALSCALE:GREGORIAN\r\n';
        ics += 'METHOD:PUBLISH\r\n';
        ics += 'BEGIN:VEVENT\r\n';
        ics += 'UID:' + uid + '\r\n';
        ics += 'DTSTAMP:' + dtstamp + '\r\n';
        ics += 'DTSTART:' + toIcsDateTime(event.date, event.startTime) + '\r\n';
        ics += 'DTEND:' + toIcsDateTime(event.date, event.endTime) + '\r\n';
        ics += 'SUMMARY:' + escapeIcsText(event.summary) + '\r\n';
        if (event.description) { ics += 'DESCRIPTION:' + escapeIcsText(event.description) + '\r\n'; }
        if (event.location) { ics += 'LOCATION:' + escapeIcsText(event.location) + '\r\n'; }
        ics += 'STATUS:CONFIRMED\r\n';
        ics += 'END:VEVENT\r\n';
        ics += 'END:VCALENDAR\r\n';
        return ics;
    }

    function buildGoogleCalendarUrl(event) {
        var dates = toGoogleDateTime(event.date, event.startTime) + '/' + toGoogleDateTime(event.date, event.endTime);
        var params = { action: 'TEMPLATE', text: event.summary, dates: dates, details: event.description || '' };
        if (event.location) { params.location = event.location; }
        if (ffcDashboard.wpTimezone) { params.ctz = ffcDashboard.wpTimezone; }
        var queryParts = [];
        for (var key in params) {
            if (params.hasOwnProperty(key)) { queryParts.push(encodeURIComponent(key) + '=' + encodeURIComponent(params[key])); }
        }
        return 'https://calendar.google.com/calendar/render?' + queryParts.join('&');
    }

    function buildOutlookUrl(event) {
        var params = { rru: 'addevent', subject: event.summary, startdt: toOutlookDateTime(event.date, event.startTime), enddt: toOutlookDateTime(event.date, event.endTime), body: event.description || '', path: '/calendar/action/compose' };
        if (event.location) { params.location = event.location; }
        var queryParts = [];
        for (var key in params) {
            if (params.hasOwnProperty(key)) { queryParts.push(encodeURIComponent(key) + '=' + encodeURIComponent(params[key])); }
        }
        return 'https://outlook.live.com/calendar/0/deeplink/compose?' + queryParts.join('&');
    }

    function downloadIcsFile(event) {
        var icsContent = generateICS(event);
        var blob = new Blob([icsContent], { type: 'text/calendar;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = (event.summary || 'event').replace(/[^a-zA-Z0-9À-ɏ]/g, '_').substring(0, 50) + '.ics';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    function buildButton(event) {
        var googleUrl = buildGoogleCalendarUrl(event);
        var outlookUrl = buildOutlookUrl(event);
        var eventJson = JSON.stringify(event).replace(/'/g, '&#39;').replace(/"/g, '&quot;');

        var html = '<div class="ffc-cal-export-wrap">';
        html += '<button type="button" class="ffc-cal-export-btn">';
        html += calIcons.calendar + ' ';
        html += (ffcDashboard.strings.exportToCalendar || 'Exportar para Calendario');
        html += ' &#9662;</button>';
        html += '<div class="ffc-cal-export-dropdown">';
        html += '<a href="' + googleUrl + '" target="_blank" rel="noopener noreferrer">' + calIcons.google + ' Google Calendar</a>';
        html += '<a href="' + outlookUrl + '" target="_blank" rel="noopener noreferrer">' + calIcons.outlook + ' Outlook</a>';
        html += '<a href="#" class="ffc-cal-export-ics" data-event="' + eventJson + '">' + calIcons.ics + ' ' + (ffcDashboard.strings.otherIcs || 'Outros (.ics)') + '</a>';
        html += '</div></div>';
        return html;
    }

    FFCDashboard.calExport = { buildButton: buildButton };

    // Self-bind at parse time. $(document).on(...) registers delegated handlers
    // that don't require the DOM to exist yet, so this is safe regardless of
    // when (relative to DOMContentLoaded) this file parses.
    $(document).on('click', '.ffc-cal-export-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $dropdown = $(this).siblings('.ffc-cal-export-dropdown');
        $('.ffc-cal-export-dropdown.open').not($dropdown).removeClass('open');
        $dropdown.toggleClass('open');
    });
    $(document).on('click', '.ffc-cal-export-ics', function (e) {
        e.preventDefault();
        var eventData = $(this).data('event');
        if (eventData) downloadIcsFile(eventData);
        $(this).closest('.ffc-cal-export-dropdown').removeClass('open');
    });
    $(document).on('click', function () { $('.ffc-cal-export-dropdown.open').removeClass('open'); });
    $(document).on('click', '.ffc-cal-export-dropdown', function (e) { e.stopPropagation(); });

})(jQuery);
