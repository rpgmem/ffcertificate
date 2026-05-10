/**
 * FFC User Dashboard — Profile panel
 *
 * Profile read view, edit form, save, password change, LGPD export/delete,
 * notification preferences. Joinable groups are rendered into the profile
 * panel by `ffc-user-dashboard-audience-join.js`.
 *
 * @since 6.5.2
 */

(function ($) {
    'use strict';

    var helpers = FFCDashboard.helpers;
    var esc = helpers.esc;

    // Profile shows three multi-value fields (names, emails, CPFs) that all
    // share the same single/list/fallback pattern with different list classes.
    function renderListField(label, listClass, items, fallback) {
        var html = '<div class="ffc-profile-field"><label>' + label + '</label>';
        if (items && items.length > 1) {
            html += '<ul class="' + listClass + '">';
            items.forEach(function (item) { html += '<li>' + esc(item) + '</li>'; });
            html += '</ul>';
        } else if (items && items.length === 1) {
            html += '<div class="value">' + esc(items[0]) + '</div>';
        } else {
            html += '<div class="value">' + esc(fallback || '-') + '</div>';
        }
        html += '</div>';
        return html;
    }

    function buildToggle(key, label, prefs) {
        var checked = prefs[key] ? ' checked' : '';
        return '<label class="ffc-toggle-label">' +
            '<input type="checkbox" class="ffc-notif-toggle" data-key="' + key + '"' + checked + ' />' +
            '<span class="ffc-toggle-switch"></span>' +
            '<span>' + label + '</span></label>';
    }

    function showEditForm() {
        var $container = $('#tab-profile');
        var profile = FFCDashboard.panels.profile.state;
        if (!profile) return;
        var s = ffcDashboard.strings;

        var html = '<div class="ffc-profile-edit-form">';
        html += '<h3>' + (s.editProfile || 'Edit Profile') + '</h3>';

        html += '<div class="ffc-profile-field"><label for="ffc-edit-display-name">' + s.name + '</label>';
        html += '<input type="text" id="ffc-edit-display-name" value="' + esc(profile.display_name) + '" maxlength="250" /></div>';

        html += '<div class="ffc-profile-field"><label for="ffc-edit-phone">' + (s.phone || 'Phone:') + '</label>';
        html += '<input type="tel" id="ffc-edit-phone" value="' + esc(profile.phone) + '" maxlength="50" /></div>';

        html += '<div class="ffc-profile-field"><label for="ffc-edit-department">' + (s.department || 'Department:') + '</label>';
        html += '<input type="text" id="ffc-edit-department" value="' + esc(profile.department) + '" maxlength="250" /></div>';

        html += '<div class="ffc-profile-field"><label for="ffc-edit-organization">' + (s.organization || 'Organization:') + '</label>';
        html += '<input type="text" id="ffc-edit-organization" value="' + esc(profile.organization) + '" maxlength="250" /></div>';

        html += '<div class="ffc-profile-field"><label for="ffc-edit-notes">' + (s.notesLabel || 'Notes:') + '</label>';
        html += '<textarea id="ffc-edit-notes" rows="3" maxlength="1000" placeholder="' + (s.notesPlaceholder || 'Personal notes...') + '">' + esc(profile.notes) + '</textarea></div>';

        html += '<div class="ffc-profile-edit-actions" style="margin-top: 15px;">';
        html += '<button type="button" class="button button-primary ffc-profile-save-btn">' + (s.save || 'Save') + '</button> ';
        html += '<button type="button" class="button ffc-profile-cancel-btn">' + (s.cancel || 'Cancel') + '</button>';
        html += '<span class="ffc-profile-save-status" style="margin-left: 10px; display: none;"></span>';
        html += '</div></div>';

        $container.html(html);
    }

    function saveProfile() {
        var data = {
            display_name: $('#ffc-edit-display-name').val(),
            phone: $('#ffc-edit-phone').val(),
            department: $('#ffc-edit-department').val(),
            organization: $('#ffc-edit-organization').val(),
            notes: $('#ffc-edit-notes').val()
        };

        var $saveBtn = $('.ffc-profile-save-btn');
        var $status = $('.ffc-profile-save-status');
        $saveBtn.prop('disabled', true);
        $status.text(ffcDashboard.strings.saving || 'Saving...').show().css('color', '#666');

        var url = ffcDashboard.restUrl + 'user/profile';
        if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

        $.ajax({
            url: url,
            method: 'PUT',
            contentType: 'application/json',
            data: JSON.stringify(data),
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
            success: function (response) {
                FFCDashboard.panels.profile.state = null;
                FFCDashboard.panels.profile.render(response);
            },
            error: function (xhr) {
                var msg = (ffcDashboard.strings.saveError || 'Error saving profile');
                if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                $status.text(msg).css('color', '#d63638');
                $saveBtn.prop('disabled', false);
            }
        });
    }

    function changePassword() {
        var s = ffcDashboard.strings;
        var current = $('#ffc-current-password').val();
        var newPwd = $('#ffc-new-password').val();
        var confirmPwd = $('#ffc-confirm-password').val();
        var $status = $('.ffc-password-status');

        if (!current || !newPwd || !confirmPwd) {
            $status.text(s.passwordError || 'All fields required').css('color', '#d63638');
            return;
        }
        if (newPwd !== confirmPwd) {
            $status.text(s.passwordMismatch || 'Passwords do not match').css('color', '#d63638');
            return;
        }
        if (newPwd.length < 8) {
            $status.text(s.passwordTooShort || 'Min 8 characters').css('color', '#d63638');
            return;
        }

        var $btn = $('.ffc-password-save-btn');
        $btn.prop('disabled', true);
        $status.text(s.saving || 'Saving...').css('color', '#666');

        var url = ffcDashboard.restUrl + 'user/change-password';
        if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

        $.ajax({
            url: url,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ current_password: current, new_password: newPwd }),
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
            success: function (response) {
                $status.text(response.message || s.passwordChanged || 'Password changed!').css('color', '#28a745');
                $('#ffc-current-password, #ffc-new-password, #ffc-confirm-password').val('');
                $btn.prop('disabled', false);
            },
            error: function (xhr) {
                var msg = s.passwordError || 'Error';
                if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                $status.text(msg).css('color', '#d63638');
                $btn.prop('disabled', false);
            }
        });
    }

    function privacyRequest(type) {
        var $status = $('.ffc-lgpd-status');
        $status.text(ffcDashboard.strings.loading || 'Loading...').css('color', '#666').show();

        var url = ffcDashboard.restUrl + 'user/privacy-request';
        if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

        $.ajax({
            url: url,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ type: type }),
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
            success: function (response) {
                $status.text(response.message || ffcDashboard.strings.privacyRequestSent || 'Request sent!').css('color', '#28a745');
            },
            error: function (xhr) {
                var msg = ffcDashboard.strings.privacyRequestError || 'Error';
                if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                $status.text(msg).css('color', '#d63638');
            }
        });
    }

    function saveNotificationPreferences() {
        var prefs = {};
        $('.ffc-notif-toggle').each(function () {
            prefs[$(this).data('key')] = $(this).is(':checked');
        });

        var $status = $('.ffc-notif-status');

        var url = ffcDashboard.restUrl + 'user/profile';
        if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

        $.ajax({
            url: url,
            method: 'PUT',
            contentType: 'application/json',
            data: JSON.stringify({ preferences: prefs }),
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
            success: function (response) {
                if (response.preferences && FFCDashboard.panels.profile.state) {
                    FFCDashboard.panels.profile.state.preferences = response.preferences;
                }
                $status.text(ffcDashboard.strings.notifSaved || 'Saved').show();
                setTimeout(function () { $status.fadeOut(); }, 2000);
            },
            error: function () {
                $status.text(ffcDashboard.strings.saveError || 'Error').css('color', '#d63638').show();
            }
        });
    }

    FFCDashboard.panels.profile = {
        state: null,

        bindEvents: function () {
            $(document).on('click', '.ffc-profile-edit-btn', function (e) { e.preventDefault(); showEditForm(); });
            $(document).on('click', '.ffc-profile-save-btn', function (e) { e.preventDefault(); saveProfile(); });
            $(document).on('click', '.ffc-profile-cancel-btn', function (e) {
                e.preventDefault();
                var profile = FFCDashboard.panels.profile.state;
                if (profile) FFCDashboard.panels.profile.render(profile);
            });

            $(document).on('click', '.ffc-password-save-btn', function (e) { e.preventDefault(); changePassword(); });
            $(document).on('click', '.ffc-password-toggle-btn', function (e) {
                e.preventDefault();
                $('#tab-profile .ffc-password-form').slideToggle(200);
            });

            $(document).on('click', '.ffc-lgpd-export-btn', function (e) { e.preventDefault(); privacyRequest('export_personal_data'); });
            $(document).on('click', '.ffc-lgpd-delete-btn', function (e) {
                e.preventDefault();
                if (confirm(ffcDashboard.strings.confirmDeletion || 'Are you sure?')) {
                    privacyRequest('remove_personal_data');
                }
            });

            $(document).on('change', '.ffc-notif-toggle', function () { saveNotificationPreferences(); });
        },

        load: function () {
            var $container = $('#tab-profile');
            if ($container.find('.ffc-profile-info').length > 0) return;

            $container.html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');

            var url = ffcDashboard.restUrl + 'user/profile';
            if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

            var self = this;
            $.ajax({
                url: url,
                method: 'GET',
                beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
                success: function (response) { self.render(response); },
                error: function () {
                    $container.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>');
                }
            });
        },

        render: function (profile) {
            var $container = $('#tab-profile');
            this.state = profile;
            var s = ffcDashboard.strings;

            var html = '';

            if (ffcDashboard.logoutUrl) {
                html += '<div class="ffc-profile-logout"><a href="' + ffcDashboard.logoutUrl + '" class="ffc-logout-link"><span class="ffc-icon-logout" aria-hidden="true"></span>' + (s.logout || 'Log Out') + '</a></div>';
            }

            html += '<div class="ffc-profile-info">';

            html += renderListField(s.name, 'name-list', profile.names, profile.display_name);
            html += renderListField(s.linkedEmails, 'email-list', profile.emails, profile.email);
            html += renderListField(s.cpfRf, 'cpf-list', profile.cpfs_masked, profile.cpf_masked);

            html += '<div class="ffc-profile-field"><label>' + (s.phone || 'Phone:') + '</label>';
            html += '<div class="value">' + esc(profile.phone || '-') + '</div></div>';

            html += '<div class="ffc-profile-field"><label>' + (s.department || 'Department:') + '</label>';
            html += '<div class="value">' + esc(profile.department || '-') + '</div></div>';

            html += '<div class="ffc-profile-field"><label>' + (s.organization || 'Organization:') + '</label>';
            html += '<div class="value">' + esc(profile.organization || '-') + '</div></div>';

            if (profile.notes) {
                html += '<div class="ffc-profile-field"><label>' + (s.notesLabel || 'Notes:') + '</label>';
                html += '<div class="value">' + esc(profile.notes) + '</div></div>';
            }

            if (profile.audience_groups && profile.audience_groups.length > 0) {
                html += '<div class="ffc-profile-field">';
                html += '<label>' + (s.audienceGroups || 'Groups') + '</label>';
                html += '<div class="value" style="display: flex; flex-wrap: wrap; gap: 6px;">';
                profile.audience_groups.forEach(function (group) {
                    html += '<span style="background-color: ' + esc(group.color || '#2271b1') + '; color: #fff; padding: 4px 12px; border-radius: 3px; font-size: 13px;">' + esc(group.name) + '</span>';
                });
                html += '</div></div>';
            }

            html += '<div class="ffc-profile-field"><label>' + s.memberSince + '</label>';
            html += '<div class="value">' + esc(profile.member_since || '-') + '</div></div>';

            html += '</div>';

            html += '<div class="ffc-profile-actions">';
            html += '<button type="button" class="button button-primary ffc-profile-edit-btn">' + (s.editProfile || 'Edit Profile') + '</button>';
            html += '<button type="button" class="button ffc-password-toggle-btn">' + (s.changePassword || 'Change Password') + '</button>';
            if (profile.audience_groups && profile.audience_groups.length > 0) {
                html += '<button type="button" class="button ffc-leave-all-groups-btn">' + (s.leaveAllGroups || 'Leave all groups') + '</button>';
            }
            html += '</div>';

            html += '<div class="ffc-password-form" style="display: none;">';
            html += '<div class="ffc-profile-field"><label for="ffc-current-password">' + (s.currentPassword || 'Current Password') + '</label>';
            html += '<input type="password" id="ffc-current-password" autocomplete="current-password" /></div>';
            html += '<div class="ffc-profile-field"><label for="ffc-new-password">' + (s.newPassword || 'New Password') + '</label>';
            html += '<input type="password" id="ffc-new-password" autocomplete="new-password" minlength="8" /></div>';
            html += '<div class="ffc-profile-field"><label for="ffc-confirm-password">' + (s.confirmPassword || 'Confirm New Password') + '</label>';
            html += '<input type="password" id="ffc-confirm-password" autocomplete="new-password" /></div>';
            html += '<button type="button" class="button button-primary ffc-password-save-btn">' + (s.save || 'Save') + '</button>';
            html += '<span class="ffc-password-status" style="margin-left: 10px;"></span>';
            html += '</div>';

            // Audience self-join section — populated by ffc-user-dashboard-audience-join.js.
            html += '<div class="ffc-audience-join-section" id="ffc-audience-join-section"></div>';
            if (FFCDashboard.audienceJoin && typeof FFCDashboard.audienceJoin.load === 'function') {
                setTimeout(function () { FFCDashboard.audienceJoin.load(); }, 0);
            }

            var prefs = profile.preferences || {};
            html += '<div class="ffc-profile-section">';
            html += '<h3>' + (s.notificationSection || 'Notification Preferences') + '</h3>';
            html += '<div class="ffc-notif-list">';
            html += buildToggle('notify_appointment_confirm', s.notifAppointmentConfirm || 'Appointment confirmation', prefs);
            html += buildToggle('notify_appointment_reminder', s.notifAppointmentReminder || 'Appointment reminder', prefs);
            html += buildToggle('notify_new_certificate', s.notifNewCertificate || 'New certificate issued', prefs);
            html += '</div>';
            html += '<span class="ffc-notif-status" style="margin-left: 10px; color: #28a745; display: none;"></span>';
            html += '</div>';

            html += '<div class="ffc-profile-section ffc-lgpd-section">';
            html += '<h3>' + (s.privacySection || 'Privacy & Data (LGPD)') + '</h3>';
            html += '<p class="ffc-lgpd-desc">' + (s.exportDataDesc || 'Request a copy of all your personal data stored in the system.') + '</p>';
            html += '<button type="button" class="button ffc-lgpd-export-btn">' + (s.exportData || 'Export My Data') + '</button>';
            html += '<p class="ffc-lgpd-desc" style="margin-top: 20px;">' + (s.deletionDataDesc || 'Request deletion of your personal data. An administrator will review your request.') + '</p>';
            html += '<button type="button" class="button ffc-lgpd-delete-btn">' + (s.requestDeletion || 'Request Data Deletion') + '</button>';
            html += '<span class="ffc-lgpd-status" style="margin-left: 10px; display: none;"></span>';
            html += '</div>';

            $container.html(html);
        },

        // Force-reload (bypass the cache guard in load).
        reload: function () {
            this.state = null;
            var $container = $('#tab-profile');
            $container.html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');

            var url = ffcDashboard.restUrl + 'user/profile';
            if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

            var self = this;
            $.ajax({
                url: url,
                method: 'GET',
                beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
                success: function (response) { self.render(response); },
                error: function () {
                    $container.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>');
                }
            });
        }
    };

})(jQuery);
