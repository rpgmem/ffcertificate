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
    // Attribute-safe escape (encodes the double quote esc() leaves through) for
    // values interpolated into value="…"/style="…" attributes — #837 S12.
    var escAttr = helpers.escAttr;
    var setStatus = helpers.setStatus;

    // Profile shows three multi-value fields (names, emails, CPFs) that all
    // share the same single/list/fallback pattern with different list classes.
    function renderListField(label, listClass, items, fallback, revealField) {
        var html = '<div class="ffc-profile-field"><label>' + label + '</label>';
        if (items && items.length > 1) {
            html += '<ul class="' + listClass + '">';
            items.forEach(function (item) { html += '<li>' + esc(item) + '</li>'; });
            html += '</ul>';
        } else if (items && items.length === 1) {
            html += '<div class="ffc-field-value">' + esc(items[0]) + '</div>';
        } else {
            html += '<div class="ffc-field-value">' + esc(fallback || '-') + '</div>';
        }
        html += revealButton(revealField);
        html += '</div>';
        return html;
    }

    /**
     * The email addresses, phone, birth date and CPF/RF arrive masked, and
     * each is revealed on request through /user/profile/reveal: by the owner
     * without an audit row (#1897), in "view as user" audited (#1863, #1888).
     */
    function isMasked(profile, field) {
        return !!(profile && profile.pii_masked && !(profile.revealed && profile.revealed[field]));
    }

    function revealButton(field) {
        var profile = FFCDashboard.panels.profile.state;
        if (!field || !isMasked(profile, field) || !profile.can_reveal) {
            return '';
        }
        return ' ' + window.FFC.buttonHtml({
            label: ffcDashboard.strings.reveal || 'Reveal',
            category: 'neutral',
            icon: 'eye',
            className: 'ffc-profile-reveal-btn',
            attrs: { 'data-field': field }
        });
    }

    function revealField(field, $btn) {
        var panel = FFCDashboard.panels.profile;
        var url = ffcDashboard.restUrl + 'user/profile/reveal';
        if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

        $btn.prop('disabled', true);
        return FFC.rest(url, { method: 'POST', data: { field: field }, nonce: ffcDashboard.nonce })
            .then(function (response) {
                var state = panel.state || {};
                state.revealed = state.revealed || {};
                state.revealed[field] = true;
                if (field === 'email') {
                    state.email = response.value;
                    state.emails = response.values || [];
                } else if (field === 'phone') {
                    state.phone = response.value;
                } else if (field === 'birth_date') {
                    state.birth_date = response.value;
                    state.birth_date_display = response.display;
                } else if (field === 'cpf_rf') {
                    // Formatted in clear (#1888); the masked keys hold the
                    // revealed list, so the render needs no second path.
                    state.cpfs_masked = response.values || [];
                    state.cpf_masked = response.value;
                }
                panel.render(state);
            })
            .catch(function () {
                $btn.prop('disabled', false);
                window.alert(ffcDashboard.strings.revealError || 'Unable to reveal this value.');
            });
    }

    // defaultOn: a key that means RECEIVE when absent (date messages are sent
    // unless the person opted out), unlike the opt-in toggles above it.
    function buildToggle(key, label, prefs, defaultOn) {
        var has = Object.prototype.hasOwnProperty.call(prefs, key);
        var on = defaultOn ? (!has || prefs[key] !== false) : !!prefs[key];
        var checked = on ? ' checked' : '';
        // The plugin's one toggle (#1748): the checkbox stays focusable, and
        // the knob carries the on/off mark like every other toggle (#1873).
        return '<label class="ffc-control-toggle ffc-control-toggle-mark">' +
            '<input type="checkbox" class="ffc-notif-toggle" data-key="' + key + '"' + checked + ' />' +
            '<span class="ffc-control-toggle-track" aria-hidden="true"></span>' +
            '<span class="ffc-control-toggle-label">' + label + '</span></label>';
    }

    function showEditForm() {
        var $container = $('#ffc-tabpanel-profile');
        var profile = FFCDashboard.panels.profile.state;
        if (!profile) return;
        var s = ffcDashboard.strings;

        var html = '<div class="ffc-profile-edit-form">';
        html += '<h3>' + (s.editProfile || 'Edit Profile') + '</h3>';

        html += '<div class="ffc-profile-field"><label for="ffc-edit-display-name">' + s.name + '</label>';
        html += '<input type="text" id="ffc-edit-display-name" value="' + escAttr(profile.display_name) + '" maxlength="250" /></div>';

        html += '<div class="ffc-profile-field"><label for="ffc-edit-phone">' + (s.phone || 'Phone:') + '</label>';
        // (DD) 98765-4321: area code plus up to nine digits, masked by the
        // same formatter the reregistration form uses.
        if (isMasked(profile, 'phone')) {
            // Masked until revealed (#1863, #1897): shown, never posted.
            html += '<input type="text" id="ffc-edit-phone" value="' + escAttr(profile.phone) + '" disabled />';
            html += '<span class="ffc-field-hint">' + esc(s.revealToEdit || 'Reveal the value to edit it.') + '</span></div>';
        } else {
            html += '<input type="tel" id="ffc-edit-phone" value="' + escAttr(maskedPhone(profile.phone)) + '" maxlength="15" data-mask="phone" inputmode="numeric" /></div>';
        }

        html += '<div class="ffc-profile-field"><label for="ffc-edit-department">' + (s.department || 'Department:') + '</label>';
        html += '<input type="text" id="ffc-edit-department" value="' + escAttr(profile.department) + '" maxlength="250" /></div>';

        html += '<div class="ffc-profile-field"><label for="ffc-edit-organization">' + (s.organization || 'Organization:') + '</label>';
        html += '<input type="text" id="ffc-edit-organization" value="' + escAttr(profile.organization) + '" maxlength="250" /></div>';

        html += '<div class="ffc-profile-field"><label for="ffc-edit-birth-date">' + (s.birthDate || 'Birth date:') + '</label>';
        if (isMasked(profile, 'birth_date')) {
            html += '<input type="text" id="ffc-edit-birth-date" value="' + escAttr(profile.birth_date_display) + '" disabled />';
            html += '<span class="ffc-field-hint">' + esc(s.revealToEdit || 'Reveal the value to edit it.') + '</span></div>';
        } else {
            html += '<input type="date" id="ffc-edit-birth-date" value="' + escAttr(profile.birth_date) + '" /></div>';
        }

        html += '<div class="ffc-profile-field"><label for="ffc-edit-notes">' + (s.notesLabel || 'Notes:') + '</label>';
        html += '<textarea id="ffc-edit-notes" rows="3" maxlength="1000" placeholder="' + (s.notesPlaceholder || 'Personal notes...') + '">' + esc(profile.notes) + '</textarea></div>';

        html += '<div class="ffc-profile-edit-actions">';
        html += window.FFC.buttonHtml({ label: s.save || 'Save', category: 'primary', icon: 'save', className: 'ffc-profile-save-btn' }) + ' ';
        html += window.FFC.buttonHtml({ label: s.cancel || 'Cancel', category: 'neutral', icon: 'cross', className: 'ffc-profile-cancel-btn' });
        html += '<span class="ffc-profile-save-status ffc-status" role="status" aria-live="polite" hidden></span>';
        html += '</div></div>';

        $container.html(html);

        if (window.FFC && window.FFC.Fields) {
            window.FFC.Fields.initMasks($container.find('.ffc-profile-edit-form'));
        }
    }

    /**
     * A stored phone shown in the mask's shape, when it fits the mask. A
     * value with more than eleven digits (an international number saved
     * before the mask existed) is shown as stored rather than truncated.
     */
    function maskedPhone(value) {
        var v = value ? String(value) : '';
        if (!v || !window.FFC || !window.FFC.Fields || v.replace(/\D/g, '').length > 11) {
            return v;
        }
        return window.FFC.Fields.masks.phone(v);
    }

    function saveProfile() {
        var data = {
            display_name: $('#ffc-edit-display-name').val(),
            department: $('#ffc-edit-department').val(),
            organization: $('#ffc-edit-organization').val(),
            notes: $('#ffc-edit-notes').val()
        };
        // A field still masked (#1863, #1897) is left out, so its
        // mask can never overwrite the stored value.
        if (!$('#ffc-edit-phone').prop('disabled')) {
            data.phone = $('#ffc-edit-phone').val();
        }
        if (!$('#ffc-edit-birth-date').prop('disabled')) {
            data.birth_date = $('#ffc-edit-birth-date').val();
        }

        var $saveBtn = $('.ffc-profile-save-btn');
        var $status = $('.ffc-profile-save-status');
        $saveBtn.prop('disabled', true);
        setStatus($status, ffcDashboard.strings.saving || 'Saving...', 'busy').show();

        var url = ffcDashboard.restUrl + 'user/profile';
        if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

        FFC.rest(url, { method: 'PUT', data: data, nonce: ffcDashboard.nonce })
            .then(function (response) {
                FFCDashboard.panels.profile.state = null;
                FFCDashboard.panels.profile.render(response);
            })
            .catch(function (err) {
                var msg = (ffcDashboard.strings.saveError || 'Error saving profile');
                if (err.xhr && err.xhr.responseJSON && err.xhr.responseJSON.message) msg = err.xhr.responseJSON.message;
                setStatus($status, msg, 'error');
                $saveBtn.prop('disabled', false);
            });
    }

    function changePassword() {
        var s = ffcDashboard.strings;
        var current = $('#ffc-current-password').val();
        var newPwd = $('#ffc-new-password').val();
        var confirmPwd = $('#ffc-confirm-password').val();
        var $status = $('.ffc-password-status');

        // Each rule is said under the field it is about, in the
        // field-message standard (#1844); the status line keeps the
        // server's answer.
        var $current = $('#ffc-current-password');
        var $new = $('#ffc-new-password');
        var $confirm = $('#ffc-confirm-password');
        var checks = [
            [$current, current ? '' : FFC.requiredMessage()],
            [$new, !newPwd ? FFC.requiredMessage() : (newPwd.length < 8 ? (s.passwordTooShort || 'Min 8 characters') : '')],
            [$confirm, !confirmPwd ? FFC.requiredMessage() : (newPwd && newPwd !== confirmPwd ? (s.passwordMismatch || 'Passwords do not match') : '')]
        ];
        var $first = null;
        checks.forEach(function (check) {
            if (check[1]) {
                FFC.fieldMessage(check[0], 'error', check[1]);
                $first = $first || check[0];
            } else {
                FFC.clearFieldMessage(check[0]);
            }
        });
        if ($first) {
            setStatus($status, '', '');
            $first.trigger('focus');
            return;
        }

        var $btn = $('.ffc-password-save-btn');
        $btn.prop('disabled', true);
        setStatus($status, s.saving || 'Saving...', 'busy');

        var url = ffcDashboard.restUrl + 'user/change-password';
        if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

        FFC.rest(url, {
            method: 'POST',
            data: { current_password: current, new_password: newPwd },
            nonce: ffcDashboard.nonce
        })
            .then(function (response) {
                setStatus($status, response.message || s.passwordChanged || 'Password changed!', 'success');
                FFC.clearFieldMessage($('#ffc-current-password, #ffc-new-password, #ffc-confirm-password').val(''));
                $btn.prop('disabled', false);
            })
            .catch(function (err) {
                var msg = s.passwordError || 'Error';
                if (err.xhr && err.xhr.responseJSON && err.xhr.responseJSON.message) msg = err.xhr.responseJSON.message;
                setStatus($status, msg, 'error');
                $btn.prop('disabled', false);
            });
    }

    function privacyRequest(type) {
        var $status = $('.ffc-lgpd-status');
        setStatus($status, ffcDashboard.strings.loading || 'Loading...', 'busy').show();

        var url = ffcDashboard.restUrl + 'user/privacy-request';
        if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

        FFC.rest(url, { method: 'POST', data: { type: type }, nonce: ffcDashboard.nonce })
            .then(function (response) {
                setStatus($status, response.message || ffcDashboard.strings.privacyRequestSent || 'Request sent!', 'success');
            })
            .catch(function (err) {
                var msg = ffcDashboard.strings.privacyRequestError || 'Error';
                if (err.xhr && err.xhr.responseJSON && err.xhr.responseJSON.message) msg = err.xhr.responseJSON.message;
                setStatus($status, msg, 'error');
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

        FFC.rest(url, { method: 'PUT', data: { preferences: prefs }, nonce: ffcDashboard.nonce })
            .then(function (response) {
                if (response.preferences && FFCDashboard.panels.profile.state) {
                    FFCDashboard.panels.profile.state.preferences = response.preferences;
                }
                setStatus($status, ffcDashboard.strings.notifSaved || 'Saved', 'success').show();
                setTimeout(function () { $status.fadeOut(); }, 2000);
            })
            .catch(function () {
                setStatus($status, ffcDashboard.strings.saveError || 'Error', 'error').show();
            });
    }

    FFCDashboard.panels.profile = {
        state: null,

        bindEvents: function () {
            $(document).on('click', '.ffc-profile-edit-btn', function (e) { e.preventDefault(); showEditForm(); });
            $(document).on('click', '.ffc-profile-reveal-btn', function (e) {
                e.preventDefault();
                revealField($(this).data('field'), $(this));
            });
            $(document).on('click', '.ffc-profile-save-btn', function (e) { e.preventDefault(); saveProfile(); });
            $(document).on('click', '.ffc-profile-cancel-btn', function (e) {
                e.preventDefault();
                var profile = FFCDashboard.panels.profile.state;
                if (profile) FFCDashboard.panels.profile.render(profile);
            });

            $(document).on('click', '.ffc-password-save-btn', function (e) { e.preventDefault(); changePassword(); });
            $(document).on('click', '.ffc-password-toggle-btn', function (e) {
                e.preventDefault();
                $('#ffc-tabpanel-profile .ffc-password-form').slideToggle(200);
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
            var $container = $('#ffc-tabpanel-profile');
            if ($container.find('.ffc-profile-info').length > 0) return;

            $container.html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');

            var url = ffcDashboard.restUrl + 'user/profile';
            if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

            var self = this;
            FFC.rest(url, { nonce: ffcDashboard.nonce })
                .then(function (response) { self.render(response); })
                .catch(function () {
                    $container.empty().append(FFC.notice('danger', 'error', ffcDashboard.strings.error, '', { role: 'alert' }));
                });
        },

        render: function (profile) {
            var $container = $('#ffc-tabpanel-profile');
            this.state = profile;
            var s = ffcDashboard.strings;

            // Log Out lives in the tab bar, printed by the shortcode, so it
            // shows on every tab (#1840).
            var html = '<div class="ffc-profile-info">';

            html += renderListField(s.name, 'ffc-name-list', profile.names, profile.display_name);
            html += renderListField(s.linkedEmails, 'ffc-email-list', profile.emails, profile.email, 'email');
            // Reveal only when there is an identifier: the fallback is "Not found".
            var hasCpfRf = profile.cpfs_masked && profile.cpfs_masked.length > 0;
            html += renderListField(s.cpfRf, 'ffc-cpf-list', profile.cpfs_masked, profile.cpf_masked, hasCpfRf ? 'cpf_rf' : '');

            html += '<div class="ffc-profile-field"><label>' + (s.phone || 'Phone:') + '</label>';
            html += '<div class="ffc-field-value">' + esc(profile.phone || '-') + '</div>' + (profile.phone ? revealButton('phone') : '') + '</div>';

            html += '<div class="ffc-profile-field"><label>' + (s.department || 'Department:') + '</label>';
            html += '<div class="ffc-field-value">' + esc(profile.department || '-') + '</div></div>';

            html += '<div class="ffc-profile-field"><label>' + (s.organization || 'Organization:') + '</label>';
            html += '<div class="ffc-field-value">' + esc(profile.organization || '-') + '</div></div>';

            html += '<div class="ffc-profile-field"><label>' + (s.birthDate || 'Birth date:') + '</label>';
            html += '<div class="ffc-field-value">' + esc(profile.birth_date_display || '-') + '</div>' + (profile.birth_date_display ? revealButton('birth_date') : '') + '</div>';

            if (profile.notes) {
                html += '<div class="ffc-profile-field"><label>' + (s.notesLabel || 'Notes:') + '</label>';
                html += '<div class="ffc-field-value">' + esc(profile.notes) + '</div></div>';
            }

            if (profile.audience_groups && profile.audience_groups.length > 0) {
                html += '<div class="ffc-profile-field">';
                html += '<label>' + (s.audienceGroups || 'Groups') + '</label>';
                html += '<div class="ffc-field-value ffc-profile-groups">';
                profile.audience_groups.forEach(function (group) {
                    html += helpers.rowColorTag('ffc-profile-group-tag', group);
                });
                html += '</div></div>';
            }

            html += '<div class="ffc-profile-field"><label>' + s.memberSince + '</label>';
            html += '<div class="ffc-field-value">' + esc(profile.member_since || '-') + '</div></div>';

            html += '</div>';

            html += '<div class="ffc-profile-actions">';
            html += window.FFC.buttonHtml({ label: s.editProfile || 'Edit Profile', category: 'neutral', icon: 'edit', className: 'ffc-profile-edit-btn' });
            html += window.FFC.buttonHtml({ label: s.changePassword || 'Change Password', category: 'neutral', icon: 'key', className: 'ffc-password-toggle-btn' });
            if (profile.audience_groups && profile.audience_groups.length > 0) {
                html += window.FFC.buttonHtml({ label: s.leaveAllGroups || 'Leave all groups', category: 'danger', icon: 'logout', className: 'ffc-leave-all-groups-btn' });
            }
            html += '</div>';

            html += '<div class="ffc-password-form" style="display: none;">';
            var req = ' <span class="ffc-required" aria-hidden="true">*</span>';
            html += '<div class="ffc-profile-field"><label for="ffc-current-password">' + (s.currentPassword || 'Current Password') + req + '</label>';
            html += '<input type="password" id="ffc-current-password" autocomplete="current-password" required /></div>';
            html += '<div class="ffc-profile-field"><label for="ffc-new-password">' + (s.newPassword || 'New Password') + req + '</label>';
            html += '<input type="password" id="ffc-new-password" autocomplete="new-password" minlength="8" required /></div>';
            html += '<div class="ffc-profile-field"><label for="ffc-confirm-password">' + (s.confirmPassword || 'Confirm New Password') + req + '</label>';
            html += '<input type="password" id="ffc-confirm-password" autocomplete="new-password" required /></div>';
            html += window.FFC.buttonHtml({ label: s.save || 'Save', category: 'primary', icon: 'save', className: 'ffc-password-save-btn' });
            html += '<span class="ffc-password-status ffc-status" role="status" aria-live="polite" hidden></span>';
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
            // Only toggles the server reads (#1545). Both are on until turned
            // off, which is what the send sites do with a missing key.
            html += buildToggle('notify_appointment_reminder', s.notifAppointmentReminder || 'Appointment reminder', prefs, true);
            if (ffcDashboard.dateMessagesEnabled) {
                html += buildToggle('notify_date_messages', s.notifDateMessages || 'Date messages (such as birthday greetings)', prefs, true);
            }
            html += '</div>';
            html += '<span class="ffc-notif-status ffc-status" role="status" aria-live="polite" hidden></span>';
            html += '</div>';

            html += '<div class="ffc-profile-section ffc-lgpd-section">';
            html += '<h3>' + (s.privacySection || 'Privacy & Data (LGPD)') + '</h3>';
            html += '<p class="ffc-lgpd-desc">' + (s.exportDataDesc || 'Request a copy of all your personal data stored in the system.') + '</p>';
            html += window.FFC.buttonHtml({ label: s.exportData || 'Export My Data', category: 'neutral', icon: 'download', className: 'ffc-lgpd-export-btn' });
            html += '<p class="ffc-lgpd-desc">' + (s.deletionDataDesc || 'Request deletion of your personal data. An administrator will review your request.') + '</p>';
            html += window.FFC.buttonHtml({ label: s.requestDeletion || 'Request Data Deletion', category: 'danger', icon: 'delete', className: 'ffc-lgpd-delete-btn' });
            html += '<span class="ffc-lgpd-status ffc-status" role="status" aria-live="polite" hidden></span>';
            html += '</div>';

            $container.html(html);
        },

        // Force-reload (bypass the cache guard in load).
        reload: function () {
            this.state = null;
            var $container = $('#ffc-tabpanel-profile');
            $container.html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');

            var url = ffcDashboard.restUrl + 'user/profile';
            if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

            var self = this;
            FFC.rest(url, { nonce: ffcDashboard.nonce })
                .then(function (response) { self.render(response); })
                .catch(function () {
                    $container.empty().append(FFC.notice('danger', 'error', ffcDashboard.strings.error, '', { role: 'alert' }));
                });
        }
    };

})(jQuery);
