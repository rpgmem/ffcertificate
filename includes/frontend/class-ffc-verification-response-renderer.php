<?php
declare(strict_types=1);

/**
 * Verification Response Renderer
 *
 * Renders HTML output for certificate and appointment verification results.
 * Handles field labels, value formatting, and PDF data generation.
 *
 * Extracted from VerificationHandler (M7 refactoring).
 *
 * @since 4.6.8
 * @package FreeFormCertificate\Frontend
 */

namespace FreeFormCertificate\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VerificationResponseRenderer {

    /**
     * Format certificate verification response HTML
     *
     * @param object $submission Submission object
     * @param array<string, mixed> $data Submission data fields
     * @param bool $show_download_button Whether to show PDF download button
     * @return string HTML output
     */
    public function format_verification_response( object $submission, array $data, bool $show_download_button = false ): string {
        $form = get_post( $submission->form_id );
        $form_title = $form ? $form->post_title : __( 'N/A', 'ffcertificate' );
        $date_generated = date_i18n(
            get_option('date_format') . ' ' . get_option('time_format'),
            strtotime( $submission->submission_date )
        );
        $display_code = isset($data['auth_code'])
            ? \FreeFormCertificate\Core\Utils::format_auth_code( $data['auth_code'] )
            : '';

        // Fields to skip (internal/technical)
        $skip_fields = array(
            'auth_code', 'ticket', 'fill_date', 'fill_time',
            'is_edited', 'edited_at', 'submission_id', 'magic_token'
        );

        // Priority fields to show first (in order)
        $priority_fields = array('name', 'cpf_rf', 'email', 'program', 'date');

        // Callbacks for template
        $get_field_label_callback = array( $this, 'get_field_label' );
        $format_field_value_callback = array( $this, 'format_field_value' );

        // Render template
        ob_start();
        include FFC_PLUGIN_DIR . 'templates/certificate-preview.php';
        return ob_get_clean();
    }

    /**
     * Format appointment verification response HTML
     *
     * @param array<string, mixed> $result Appointment search result
     * @return string HTML output
     */
    public function format_appointment_verification_response( array $result ): string {
        $data = $result['data'];
        $appointment = $result['appointment'];

        $date_format = get_option( 'date_format' );
        $time_format = get_option( 'time_format' );

        // Format date
        $formatted_date = __( 'N/A', 'ffcertificate' );
        if ( ! empty( $appointment['appointment_date'] ) ) {
            $ts = strtotime( $appointment['appointment_date'] );
            if ( $ts !== false ) {
                $formatted_date = date_i18n( $date_format, $ts );
            }
        }

        // Format time
        $formatted_time = __( 'N/A', 'ffcertificate' );
        if ( ! empty( $appointment['start_time'] ) ) {
            $ts = strtotime( $appointment['start_time'] );
            if ( $ts !== false ) {
                $formatted_time = date_i18n( $time_format, $ts );
            }
            if ( ! empty( $appointment['end_time'] ) ) {
                $ts2 = strtotime( $appointment['end_time'] );
                if ( $ts2 !== false ) {
                    $formatted_time .= ' - ' . date_i18n( $time_format, $ts2 );
                }
            }
        }

        // Format created_at
        $formatted_created = __( 'N/A', 'ffcertificate' );
        if ( ! empty( $appointment['created_at'] ) ) {
            $ts = strtotime( $appointment['created_at'] );
            if ( $ts !== false ) {
                $formatted_created = date_i18n( $date_format . ' ' . $time_format, $ts );
            }
        }

        // Status labels
        $status_labels = array(
            'pending'   => __( 'Pending Approval', 'ffcertificate' ),
            'confirmed' => __( 'Confirmed', 'ffcertificate' ),
            'cancelled' => __( 'Cancelled', 'ffcertificate' ),
            'completed' => __( 'Completed', 'ffcertificate' ),
            'no_show'   => __( 'No Show', 'ffcertificate' ),
        );
        $status = $appointment['status'] ?? 'pending';
        $status_label = $status_labels[ $status ] ?? $status;

        // Format validation code
        $display_code = '';
        if ( ! empty( $appointment['validation_code'] ) ) {
            $display_code = \FreeFormCertificate\Core\Utils::format_auth_code( $appointment['validation_code'] );
        }

        // Format CPF/RF
        $cpf_rf_display = '';
        if ( ! empty( $data['cpf_rf'] ) ) {
            $cpf_rf_display = \FreeFormCertificate\Core\Utils::format_document( $data['cpf_rf'] );
        }

        // Build HTML
        $html = '<div class="ffc-certificate-preview ffc-appointment-verification">';

        $html .= '<div class="ffc-preview-header">';
        $html .= '<span class="ffc-status-badge success ffc-icon-success">' . esc_html__( 'Appointment Receipt Valid', 'ffcertificate' ) . '</span>';
        $html .= '<br><span class="ffc-appointment-status ffc-status-' . esc_attr( $status ) . '">' . esc_html( $status_label ) . '</span>';
        $html .= '</div>';

        $html .= '<div class="ffc-preview-body">';
        $html .= '<h3>' . esc_html__( 'Appointment Details', 'ffcertificate' ) . '</h3>';

        if ( ! empty( $display_code ) ) {
            $html .= '<div class="ffc-detail-row">';
            $html .= '<span class="label">' . esc_html__( 'Validation Code:', 'ffcertificate' ) . '</span>';
            $html .= '<span class="value code">' . esc_html( $display_code ) . '</span>';
            $html .= '</div>';
        }

        if ( ! empty( $data['calendar_title'] ) ) {
            $html .= '<div class="ffc-detail-row">';
            $html .= '<span class="label">' . esc_html__( 'Event:', 'ffcertificate' ) . '</span>';
            $html .= '<span class="value">' . esc_html( $data['calendar_title'] ) . '</span>';
            $html .= '</div>';
        }

        $html .= '<div class="ffc-detail-row">';
        $html .= '<span class="label">' . esc_html__( 'Date:', 'ffcertificate' ) . '</span>';
        $html .= '<span class="value">' . esc_html( $formatted_date ) . '</span>';
        $html .= '</div>';

        $html .= '<div class="ffc-detail-row">';
        $html .= '<span class="label">' . esc_html__( 'Time:', 'ffcertificate' ) . '</span>';
        $html .= '<span class="value">' . esc_html( $formatted_time ) . '</span>';
        $html .= '</div>';

        $html .= '<hr>';
        $html .= '<h4>' . esc_html__( 'Participant Data:', 'ffcertificate' ) . '</h4>';

        if ( ! empty( $data['name'] ) ) {
            $html .= '<div class="ffc-detail-row">';
            $html .= '<span class="label">' . esc_html__( 'Name:', 'ffcertificate' ) . '</span>';
            $html .= '<span class="value">' . esc_html( $data['name'] ) . '</span>';
            $html .= '</div>';
        }

        if ( ! empty( $cpf_rf_display ) ) {
            $html .= '<div class="ffc-detail-row">';
            $html .= '<span class="label">' . esc_html__( 'CPF/RF:', 'ffcertificate' ) . '</span>';
            $html .= '<span class="value">' . esc_html( $cpf_rf_display ) . '</span>';
            $html .= '</div>';
        }

        $html .= '<div class="ffc-detail-row">';
        $html .= '<span class="label">' . esc_html__( 'Booked on:', 'ffcertificate' ) . '</span>';
        $html .= '<span class="value">' . esc_html( $formatted_created ) . '</span>';
        $html .= '</div>';

        $html .= '</div>'; // .ffc-preview-body

        $html .= '<div class="ffc-preview-actions">';
        $html .= '<button class="ffc-download-btn ffc-download-pdf-btn ffc-icon-download">' . esc_html__( 'Download Receipt (PDF)', 'ffcertificate' ) . '</button>';
        $html .= '</div>';

        $html .= '</div>'; // .ffc-certificate-preview

        return $html;
    }

    /**
     * Generate appointment PDF data for verification context
     *
     * @param array<string, mixed> $result Search result array
     * @param \FreeFormCertificate\Generators\PdfGenerator $pdf_generator PDF generator instance
     * @return array<string, mixed> PDF data array
     */
    public function generate_appointment_verification_pdf( array $result, \FreeFormCertificate\Generators\PdfGenerator $pdf_generator ): array {
        $appointment = $result['appointment'];
        $calendar = array( 'title' => $result['data']['calendar_title'] ?? __( 'N/A', 'ffcertificate' ) );

        if ( ! empty( $appointment['calendar_id'] ) ) {
            $calendar_repo = new \FreeFormCertificate\Repositories\CalendarRepository();
            $full_calendar = $calendar_repo->findById( (int) $appointment['calendar_id'] );
            if ( $full_calendar ) {
                $calendar = $full_calendar;
            }
        }

        return $pdf_generator->generate_appointment_pdf_data( $appointment, $calendar );
    }

    /**
     * Format reregistration verification response HTML.
     *
     * @since 4.12.0
     * @param array<string, mixed> $result Reregistration search result.
     * @return string HTML output.
     */
    public function format_reregistration_verification_response( array $result ): string {
        $rereg = $result['reregistration'];

        $date_format = get_option( 'date_format' );
        $time_format = get_option( 'time_format' );

        $submitted_at = __( 'N/A', 'ffcertificate' );
        if ( ! empty( $rereg['submitted_at'] ) ) {
            $ts = strtotime( $rereg['submitted_at'] );
            if ( $ts !== false ) {
                $submitted_at = date_i18n( $date_format . ' ' . $time_format, $ts );
            }
        }

        $display_code = ! empty( $rereg['auth_code'] )
            ? \FreeFormCertificate\Core\Utils::format_auth_code( $rereg['auth_code'] )
            : '';

        // Format CPF
        $cpf_display = '';
        if ( ! empty( $rereg['cpf'] ) && class_exists( '\\FreeFormCertificate\\Core\\Utils' ) ) {
            $cpf_display = \FreeFormCertificate\Core\Utils::format_document( $rereg['cpf'] );
        }

        // Status badge class
        $status_class = 'info';
        if ( $rereg['status'] === 'approved' ) {
            $status_class = 'success';
        } elseif ( $rereg['status'] === 'rejected' ) {
            $status_class = 'error';
        }

        $html = '<div class="ffc-certificate-preview ffc-reregistration-verification">';

        $html .= '<div class="ffc-preview-header">';
        $html .= '<span class="ffc-status-badge ' . esc_attr( $status_class ) . ' ffc-icon-success">' . esc_html__( 'Reregistration Record Valid', 'ffcertificate' ) . '</span>';
        $html .= '<br><span class="ffc-appointment-status ffc-status-' . esc_attr( $rereg['status'] ) . '">' . esc_html( $rereg['status_label'] ) . '</span>';
        $html .= '</div>';

        $html .= '<div class="ffc-preview-body">';
        $html .= '<h3>' . esc_html( $rereg['title'] ) . '</h3>';

        if ( ! empty( $display_code ) ) {
            $html .= '<div class="ffc-detail-row">';
            $html .= '<span class="label">' . esc_html__( 'Validation Code:', 'ffcertificate' ) . '</span>';
            $html .= '<span class="value code">' . esc_html( $display_code ) . '</span>';
            $html .= '</div>';
        }

        if ( ! empty( $rereg['display_name'] ) ) {
            $html .= '<div class="ffc-detail-row">';
            $html .= '<span class="label">' . esc_html__( 'Name:', 'ffcertificate' ) . '</span>';
            $html .= '<span class="value">' . esc_html( $rereg['display_name'] ) . '</span>';
            $html .= '</div>';
        }

        if ( ! empty( $cpf_display ) ) {
            $html .= '<div class="ffc-detail-row">';
            $html .= '<span class="label">' . esc_html__( 'CPF:', 'ffcertificate' ) . '</span>';
            $html .= '<span class="value">' . esc_html( $cpf_display ) . '</span>';
            $html .= '</div>';
        }

        if ( ! empty( $rereg['email'] ) ) {
            $html .= '<div class="ffc-detail-row">';
            $html .= '<span class="label">' . esc_html__( 'Email:', 'ffcertificate' ) . '</span>';
            $html .= '<span class="value">' . esc_html( $rereg['email'] ) . '</span>';
            $html .= '</div>';
        }

        $html .= '<div class="ffc-detail-row">';
        $html .= '<span class="label">' . esc_html__( 'Submitted:', 'ffcertificate' ) . '</span>';
        $html .= '<span class="value">' . esc_html( $submitted_at ) . '</span>';
        $html .= '</div>';

        $html .= '</div>'; // .ffc-preview-body

        $html .= '<div class="ffc-preview-actions">';
        $html .= '<button class="ffc-download-btn ffc-download-pdf-btn ffc-icon-download">' . esc_html__( 'Download Ficha (PDF)', 'ffcertificate' ) . '</button>';
        $html .= '</div>';

        $html .= '</div>'; // .ffc-certificate-preview

        return $html;
    }

    /**
     * Get human-readable field label
     *
     * @param string $field_key Field key
     * @return string Formatted label
     */
    public function get_field_label( string $field_key ): string {
        $labels = array(
            'cpf_rf'   => __( 'CPF/RF', 'ffcertificate' ),
            'cpf'      => __( 'CPF', 'ffcertificate' ),
            'rf'       => __( 'RF', 'ffcertificate' ),
            'name'     => __( 'Name', 'ffcertificate' ),
            'email'    => __( 'Email', 'ffcertificate' ),
            'program'  => __( 'Program', 'ffcertificate' ),
            'date'     => __( 'Date', 'ffcertificate' ),
            'rg'       => __( 'RG', 'ffcertificate' ),
            'phone'    => __( 'Phone', 'ffcertificate' ),
            'address'  => __( 'Address', 'ffcertificate' ),
            'city'     => __( 'City', 'ffcertificate' ),
            'state'    => __( 'State', 'ffcertificate' ),
            'zip'      => __( 'ZIP Code', 'ffcertificate' ),
            'course'   => __( 'Course', 'ffcertificate' ),
            'duration' => __( 'Duration', 'ffcertificate' ),
            'hours'    => __( 'Hours', 'ffcertificate' ),
            'grade'    => __( 'Grade', 'ffcertificate' ),
        );

        if ( isset( $labels[$field_key] ) ) {
            return $labels[$field_key];
        }

        return ucwords( str_replace( array('_', '-'), ' ', $field_key ) );
    }

    /**
     * Format field value for display
     *
     * @param string $field_key Field key
     * @param mixed $value Field value
     * @return string Formatted value
     */
    public function format_field_value( string $field_key, $value ): string {
        if ( is_array( $value ) ) {
            return implode( ', ', $value );
        }

        if ( in_array( $field_key, array( 'cpf', 'cpf_rf', 'rg' ) ) && ! empty( $value ) ) {
            return \FreeFormCertificate\Core\Utils::format_document( $value, 'auto' );
        }

        return $value;
    }
}
