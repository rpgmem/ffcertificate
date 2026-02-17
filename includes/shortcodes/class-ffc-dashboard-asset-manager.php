<?php
declare(strict_types=1);

/**
 * DashboardAssetManager
 *
 * Extracted from DashboardShortcode (Sprint 18 refactoring).
 * Enqueues CSS/JS assets and localizes JavaScript for the user dashboard.
 *
 * @since 4.12.19
 */

namespace FreeFormCertificate\Shortcodes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DashboardAssetManager {

    /**
     * Check if user belongs to at least one audience group
     *
     * @since 4.9.7
     * @param int $user_id WordPress user ID
     * @return bool
     */
    public static function user_has_audience_groups( int $user_id ): bool {
        if ( ! class_exists( '\FreeFormCertificate\Audience\AudienceRepository' ) ) {
            return false;
        }

        // Admins always see the audience tab (they can manage all audiences)
        if ( user_can( $user_id, 'manage_options' ) ) {
            return true;
        }

        $audiences = \FreeFormCertificate\Audience\AudienceRepository::get_user_audiences( $user_id );
        return ! empty( $audiences );
    }

    /**
     * Enqueue dashboard assets
     *
     * @param int|false $view_as_user_id User ID in view-as mode
     */
    public static function enqueue_assets( $view_as_user_id = false ): void {
        // Get user permissions (based on capabilities, not just role)
        $user_id = $view_as_user_id ?: get_current_user_id();
        $user = get_user_by('id', $user_id);

        $can_view_certificates = $user && (
            user_can($user, 'view_own_certificates') ||
            user_can($user, 'manage_options')
        );

        $can_view_appointments = $user && (
            user_can($user, 'ffc_view_self_scheduling') ||
            user_can($user, 'manage_options')
        );

        $can_view_audience_bookings = $user && (
            user_can($user, 'ffc_view_audience_bookings') ||
            user_can($user, 'manage_options')
        );

        // Only show audience tab if user actually belongs to at least one audience group
        if ($can_view_audience_bookings) {
            $can_view_audience_bookings = self::user_has_audience_groups($user_id);
        }

        $can_view_reregistrations = class_exists('\FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository')
            && !empty(\FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository::get_all_by_user($user_id));

        $s = \FreeFormCertificate\Core\Utils::asset_suffix();

        // Enqueue CSS (ffc-common provides icon classes)
        wp_enqueue_style( 'ffc-common', FFC_PLUGIN_URL . "assets/css/ffc-common{$s}.css", array(), FFC_VERSION );
        wp_enqueue_style( 'ffc-dashboard', FFC_PLUGIN_URL . "assets/css/ffc-user-dashboard{$s}.css", array( 'ffc-common' ), FFC_VERSION );

        // Dark mode
        \FreeFormCertificate\Core\Utils::enqueue_dark_mode();

        // Enqueue JavaScript
        wp_enqueue_script( 'ffc-dashboard', FFC_PLUGIN_URL . "assets/js/ffc-user-dashboard{$s}.js", array('jquery'), FFC_VERSION, true );

        // Working hours field component (shared)
        wp_enqueue_style('ffc-working-hours', FFC_PLUGIN_URL . "assets/css/ffc-working-hours{$s}.css", array(), FFC_VERSION);
        wp_enqueue_script('ffc-working-hours', FFC_PLUGIN_URL . "assets/js/ffc-working-hours{$s}.js", array('jquery'), FFC_VERSION, true);
        wp_localize_script('ffc-working-hours', 'ffcWorkingHours', array(
            'days' => array(
                array('value' => 0, 'label' => __('Sunday', 'ffcertificate')),
                array('value' => 1, 'label' => __('Monday', 'ffcertificate')),
                array('value' => 2, 'label' => __('Tuesday', 'ffcertificate')),
                array('value' => 3, 'label' => __('Wednesday', 'ffcertificate')),
                array('value' => 4, 'label' => __('Thursday', 'ffcertificate')),
                array('value' => 5, 'label' => __('Friday', 'ffcertificate')),
                array('value' => 6, 'label' => __('Saturday', 'ffcertificate')),
            ),
        ));

        // Reregistration frontend assets
        wp_enqueue_style('ffc-reregistration-frontend', FFC_PLUGIN_URL . "assets/css/ffc-reregistration-frontend{$s}.css", array('ffc-dashboard'), FFC_VERSION);
        wp_enqueue_script('ffc-reregistration-frontend', FFC_PLUGIN_URL . "assets/js/ffc-reregistration-frontend{$s}.js", array('jquery', 'ffc-dashboard', 'ffc-working-hours'), FFC_VERSION, true);
        wp_localize_script('ffc-reregistration-frontend', 'ffcReregistration', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('ffc_reregistration_frontend'),
            'strings' => array(
                'loading'         => __('Loading form...', 'ffcertificate'),
                'saving'          => __('Saving...', 'ffcertificate'),
                'submitting'      => __('Submitting...', 'ffcertificate'),
                'saveDraft'       => __('Save Draft', 'ffcertificate'),
                'submit'          => __('Submit', 'ffcertificate'),
                'draftSaved'      => __('Draft saved.', 'ffcertificate'),
                'submitted'       => __('Reregistration submitted successfully!', 'ffcertificate'),
                'errorLoading'    => __('Error loading form.', 'ffcertificate'),
                'errorSaving'     => __('Error saving draft.', 'ffcertificate'),
                'errorSubmitting' => __('Error submitting.', 'ffcertificate'),
                'fixErrors'       => __('Please fix the errors below.', 'ffcertificate'),
                'required'        => __('This field is required.', 'ffcertificate'),
                'invalidCpf'      => __('Invalid CPF.', 'ffcertificate'),
                'invalidEmail'    => __('Invalid email.', 'ffcertificate'),
                'invalidPhone'    => __('Invalid phone number.', 'ffcertificate'),
                'invalidFormat'   => __('Invalid format.', 'ffcertificate'),
                'select'          => __('Select', 'ffcertificate'),
                'selectDivisao'   => __('Select Division / Location', 'ffcertificate'),
                'selectSetor'     => __('Select', 'ffcertificate'),
                'sunday'          => __('Sunday', 'ffcertificate'),
                'monday'          => __('Monday', 'ffcertificate'),
                'tuesday'         => __('Tuesday', 'ffcertificate'),
                'wednesday'       => __('Wednesday', 'ffcertificate'),
                'thursday'        => __('Thursday', 'ffcertificate'),
                'friday'          => __('Friday', 'ffcertificate'),
                'saturday'        => __('Saturday', 'ffcertificate'),
                'acumuloShowValue' => __('I hold', 'ffcertificate'),
            ),
        ));

        // Localize script
        wp_localize_script('ffc-dashboard', 'ffcDashboard', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('ffc/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'viewAsUserId' => $view_as_user_id ? $view_as_user_id : false,
            'isAdminViewing' => $view_as_user_id && $view_as_user_id !== get_current_user_id(),
            'canViewCertificates' => $can_view_certificates,
            'canViewAppointments' => $can_view_appointments,
            'canViewAudienceBookings' => $can_view_audience_bookings,
            'canViewReregistrations' => $can_view_reregistrations,
            'siteName' => get_bloginfo('name'),
            'wpTimezone' => wp_timezone_string(),
            'mainAddress' => (get_option('ffc_settings', array()))['main_address'] ?? '',
            'strings' => array(
                'loading' => __('Loading...', 'ffcertificate'),
                'error' => __('Error loading data', 'ffcertificate'),
                'noCertificates' => __('No certificates found', 'ffcertificate'),
                'noAppointments' => __('No appointments found', 'ffcertificate'),
                'noAudienceBookings' => __('No scheduled activities found', 'ffcertificate'),
                'downloadPdf' => __('View PDF', 'ffcertificate'),
                'yes' => __('Yes', 'ffcertificate'),
                'no' => __('No', 'ffcertificate'),
                // Table headers
                'eventName' => __('Event Name', 'ffcertificate'),
                'calendar' => __('Calendar', 'ffcertificate'),
                'date' => __('Date', 'ffcertificate'),
                'time' => __('Time', 'ffcertificate'),
                'status' => __('Status', 'ffcertificate'),
                'consent' => __('Consent (LGPD)', 'ffcertificate'),
                'email' => __('Email', 'ffcertificate'),
                'code' => __('Code', 'ffcertificate'),
                'actions' => __('Actions', 'ffcertificate'),
                'notes' => __('Notes', 'ffcertificate'),
                // Profile fields
                'name' => __('Name:', 'ffcertificate'),
                'linkedEmails' => __('Linked Emails:', 'ffcertificate'),
                'cpfRf' => __('CPF/RF:', 'ffcertificate'),
                'memberSince' => __('Member Since:', 'ffcertificate'),
                // Appointment actions
                'cancelAppointment' => __('Cancel', 'ffcertificate'),
                'viewReceipt' => __('View Receipt', 'ffcertificate'),
                'viewDetails' => __('View Details', 'ffcertificate'),
                'confirmCancel' => __('Are you sure you want to cancel this appointment?', 'ffcertificate'),
                'cancelSuccess' => __('Appointment cancelled successfully', 'ffcertificate'),
                'cancelError' => __('Error cancelling appointment', 'ffcertificate'),
                'noPermission' => __('You do not have permission to view this content.', 'ffcertificate'),
                // Calendar export
                'exportToCalendar' => __('Export to Calendar', 'ffcertificate'),
                'otherIcs' => __('Other (.ics)', 'ffcertificate'),
                // Audience bookings
                'environment' => __('Environment', 'ffcertificate'),
                'description' => __('Description', 'ffcertificate'),
                'audiences' => __('Audiences', 'ffcertificate'),
                'upcoming' => __('Upcoming', 'ffcertificate'),
                'past' => __('Past', 'ffcertificate'),
                'cancelled' => __('Cancelled', 'ffcertificate'),
                // Profile
                'audienceGroups' => __('Groups:', 'ffcertificate'),
                'notesLabel' => __('Notes:', 'ffcertificate'),
                'notesPlaceholder' => __('Personal notes...', 'ffcertificate'),
                'phone' => __('Phone:', 'ffcertificate'),
                'department' => __('Department:', 'ffcertificate'),
                'organization' => __('Organization:', 'ffcertificate'),
                'editProfile' => __('Edit Profile', 'ffcertificate'),
                'save' => __('Save', 'ffcertificate'),
                'cancel' => __('Cancel', 'ffcertificate'),
                'saving' => __('Saving...', 'ffcertificate'),
                'saveError' => __('Error saving profile', 'ffcertificate'),
                // Password change
                'securitySection' => __('Security', 'ffcertificate'),
                'changePassword' => __('Change Password', 'ffcertificate'),
                'currentPassword' => __('Current Password', 'ffcertificate'),
                'newPassword' => __('New Password', 'ffcertificate'),
                'confirmPassword' => __('Confirm New Password', 'ffcertificate'),
                'passwordChanged' => __('Password changed successfully!', 'ffcertificate'),
                'passwordMismatch' => __('Passwords do not match', 'ffcertificate'),
                'passwordTooShort' => __('Password must be at least 8 characters', 'ffcertificate'),
                'passwordError' => __('Error changing password', 'ffcertificate'),
                // LGPD
                'privacySection' => __('Privacy & Data (LGPD)', 'ffcertificate'),
                'exportData' => __('Export My Data', 'ffcertificate'),
                'requestDeletion' => __('Request Data Deletion', 'ffcertificate'),
                'exportDataDesc' => __('Request a copy of all your personal data stored in the system.', 'ffcertificate'),
                'deletionDataDesc' => __('Request deletion of your personal data. An administrator will review your request.', 'ffcertificate'),
                'privacyRequestSent' => __('Request sent! The administrator will review it.', 'ffcertificate'),
                'privacyRequestError' => __('Error sending request', 'ffcertificate'),
                'confirmDeletion' => __('Are you sure you want to request deletion of your personal data? This will be reviewed by an administrator.', 'ffcertificate'),
                // Audience self-join
                'joinGroups' => __('Join Groups', 'ffcertificate'),
                'joinGroupsDesc' => __('Select up to {max} groups to participate in collective calendars.', 'ffcertificate'),
                'joinGroup' => __('Join', 'ffcertificate'),
                'leaveGroup' => __('Leave', 'ffcertificate'),
                'confirmLeaveGroup' => __('Are you sure you want to leave this group?', 'ffcertificate'),
                // Summary
                'summaryTitle' => __('Overview', 'ffcertificate'),
                'totalCertificates' => __('Certificates', 'ffcertificate'),
                'nextAppointment' => __('Next Appointment', 'ffcertificate'),
                'upcomingGroupEvents' => __('Group Events', 'ffcertificate'),
                'noUpcoming' => __('None scheduled', 'ffcertificate'),
                // Filters
                'filterFrom' => __('From:', 'ffcertificate'),
                'filterTo' => __('To:', 'ffcertificate'),
                'filterSearch' => __('Search...', 'ffcertificate'),
                'filterApply' => __('Filter', 'ffcertificate'),
                'filterClear' => __('Clear', 'ffcertificate'),
                // Notification preferences
                'notificationSection' => __('Notification Preferences', 'ffcertificate'),
                'notifAppointmentConfirm' => __('Appointment confirmation', 'ffcertificate'),
                'notifAppointmentReminder' => __('Appointment reminder', 'ffcertificate'),
                'notifNewCertificate' => __('New certificate issued', 'ffcertificate'),
                'notifSaved' => __('Preferences saved', 'ffcertificate'),
                // Pagination
                'previous' => __('Previous', 'ffcertificate'),
                'next' => __('Next', 'ffcertificate'),
                'pageOf' => __('Page {current} of {total}', 'ffcertificate'),
                'perPage' => __('Per page:', 'ffcertificate'),
                // Reregistrations tab
                'noReregistrations' => __('No reregistrations found.', 'ffcertificate'),
                'reregistrationTitle' => __('Campaign', 'ffcertificate'),
                'period' => __('Period', 'ffcertificate'),
                'submittedAt' => __('Submitted', 'ffcertificate'),
                'validationCode' => __('Validation Code', 'ffcertificate'),
                'downloadFicha' => __('Download Ficha', 'ffcertificate'),
                'active' => __('Active', 'ffcertificate'),
                'completed' => __('Completed', 'ffcertificate'),
                'editReregistration' => __('Edit', 'ffcertificate'),
            ),
        ));
    }
}
