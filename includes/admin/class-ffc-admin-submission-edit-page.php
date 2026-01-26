<?php
declare(strict_types=1);

/**
 * AdminSubmissionEditPage
 *
 * Manages the submission edit page rendering and saving.
 * Extracted from FFC_Admin class to follow Single Responsibility Principle.
 *
 * @since 3.1.1 (Extracted from FFC_Admin)
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AdminSubmissionEditPage {

    /**
     * Submission handler instance
     *
     * @var FFC_Submission_Handler
     */
    private $submission_handler;

    /**
     * Submission data (array format)
     *
     * @var array
     */
    private $sub_array;

    /**
     * Decoded JSON data
     *
     * @var array
     */
    private $data;

    /**
     * Form fields configuration
     *
     * @var array
     */
    private $fields;

    /**
     * Constructor
     *
     * @param FFC_Submission_Handler $handler Submission handler instance
     */
    public function __construct( object $handler ) {
        $this->submission_handler = $handler;
    }

    /**
     * Render the edit page
     *
     * Main entry point that delegates to specialized render methods.
     *
     * @param int $submission_id Submission ID to edit
     */
    public function render( int $submission_id ): void {
        // Get submission data
        $sub = $this->submission_handler->get_submission( $submission_id );

        if ( ! $sub ) {
            echo '<div class="wrap"><p>' . __( 'Submission not found.', 'ffc' ) . '</p></div>';
            return;
        }

        // Prepare data (convert form_id to int - wpdb returns strings)
        $this->sub_array = (array) $sub;
        $this->data = json_decode( $this->sub_array['data'], true ) ?: array();
        $this->fields = get_post_meta( (int) $this->sub_array['form_id'], '_ffc_form_fields', true );

        // Render page
        ?>
        <div class="wrap">
            <h1><?php printf( __( 'Edit Submission #%s', 'ffc' ), $this->sub_array['id'] ); ?></h1>

            <?php $this->render_edit_warning(); ?>

            <form method="POST" class="ffc-edit-submission-form">
                <?php wp_nonce_field( 'ffc_edit_submission_nonce', 'ffc_edit_submission_action' ); ?>
                <input type="hidden" name="submission_id" value="<?php echo $this->sub_array['id']; ?>">

                <table class="form-table ffc-edit-table">
                    <?php
                    $this->render_system_info_section();
                    $this->render_qr_code_section();
                    $this->render_consent_section();
                    $this->render_participant_data_section();
                    $this->render_dynamic_fields();
                    ?>
                </table>

                <p class="submit">
                    <button type="submit" name="ffc_save_edit" class="button button-primary"><?php _e( 'Save Changes', 'ffc' ); ?></button>
                    <a href="<?php echo admin_url('edit.php?post_type=ffc_form&page=ffc-submissions'); ?>" class="button"><?php _e( 'Cancel', 'ffc' ); ?></a>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Render edit warning notice
     *
     * Shows if submission was previously edited.
     */
    private function render_edit_warning(): void {
        $was_edited = ! empty( $this->sub_array['edited_at'] );

        if ( ! $was_edited ) {
            return;
        }

        $edited_at = $this->sub_array['edited_at'];
        $edited_by_id = ! empty( $this->sub_array['edited_by'] ) ? (int) $this->sub_array['edited_by'] : 0;
        $edited_by_name = '';

        if ( $edited_by_id ) {
            $user = get_userdata( $edited_by_id );
            $edited_by_name = $user ? $user->display_name : 'ID: ' . $edited_by_id;
        }

        ?>
        <div class="notice notice-warning ffc-edited-notice">
            <p>
                <strong><?php _e( '⚠️ Warning:', 'ffc' ); ?></strong>
                <?php
                printf(
                    __( 'This record was manually edited on <strong>%s</strong>', 'ffc' ),
                    date_i18n( get_option('date_format') . ' ' . get_option('time_format'), strtotime($edited_at) )
                );
                ?>
                <?php if ( $edited_by_name ): ?>
                    <?php printf( __( ' by <strong>%s</strong>', 'ffc' ), esc_html($edited_by_name) ); ?>
                <?php endif; ?>.
            </p>
        </div>
        <?php
    }

    /**
     * Render system information section
     *
     * Displays ID, date, status, magic token, user IP.
     */
    private function render_system_info_section(): void {
        $magic_token = isset( $this->sub_array['magic_token'] ) ? $this->sub_array['magic_token'] : '';
        $formatted_date = isset( $this->sub_array['submission_date'] )
            ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $this->sub_array['submission_date'] ) )
            : __( 'Unknown', 'ffc' );

        ?>
        <!-- SEÇÃO: INFORMAÇÕES DO SISTEMA -->
        <tr>
            <td colspan="2">
                <h2 class="ffc-section-header">
                    <?php _e( 'System Information', 'ffc' ); ?>
                </h2>
            </td>
        </tr>

        <tr>
            <th><label><?php _e( 'Submission ID', 'ffc' ); ?></label></th>
            <td>
                <input type="text" value="<?php echo esc_attr( $this->sub_array['id'] ); ?>" class="regular-text ffc-input-readonly" readonly>
                <p class="description"><?php _e( 'Unique submission identifier.', 'ffc' ); ?></p>
            </td>
        </tr>

        <tr>
            <th><label><?php _e( 'Submission Date', 'ffc' ); ?></label></th>
            <td>
                <input type="text" value="<?php echo esc_attr( $formatted_date ); ?>" class="regular-text ffc-input-readonly" readonly>
                <p class="description"><?php _e( 'Original submission timestamp (read-only).', 'ffc' ); ?></p>
            </td>
        </tr>

        <tr>
            <th><label><?php _e( 'Status', 'ffc' ); ?></label></th>
            <td>
                <input type="text" value="<?php echo esc_attr( $this->sub_array['status'] ); ?>" class="regular-text ffc-input-readonly" readonly>
                <p class="description"><?php _e( 'Submission status (publish, trash, etc).', 'ffc' ); ?></p>
            </td>
        </tr>

        <tr>
            <th><label><?php _e( 'Magic Link Token', 'ffc' ); ?></label></th>
            <td>
                <?php if ( ! empty( $magic_token ) ): ?>
                    <input type="text" value="<?php echo esc_attr( $magic_token ); ?>" class="regular-text ffc-input-readonly" readonly>
                    <p class="description">
                        <?php _e( 'Unique token for certificate access (read-only).', 'ffc' ); ?>
                        <?php echo \FFC_Magic_Link_Helper::get_magic_link_html( $magic_token ); ?>
                    </p>
                <?php else: ?>
                    <p class="description"><?php _e( 'Submission created before magic links', 'ffc' ); ?></p>
                <?php endif; ?>
            </td>
        </tr>

        <?php if ( !empty( $this->sub_array['user_ip'] ) ): ?>
        <tr>
            <th><label><?php _e( 'User IP', 'ffc' ); ?></label></th>
            <td>
                <input type="text" value="<?php echo esc_attr( $this->sub_array['user_ip'] ); ?>" class="regular-text ffc-input-readonly" readonly>
                <?php if ( ! empty( $this->sub_array['user_ip_encrypted'] ) ): ?>
                    <p class="description">🔒 <?php _e( 'This IP is encrypted in the database.', 'ffc' ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php endif; ?>
        <?php
    }

    /**
     * Render QR Code usage information section
     */
    private function render_qr_code_section(): void {
        ?>
        <!-- SEÇÃO: QR CODE USAGE -->
        <tr>
            <td colspan="2">
                <div class="ffc-qr-info-box">
                    <h3>
                        📱 <?php _e( 'QR Code Placeholder Usage', 'ffc' ); ?>
                    </h3>
                    <p>
                        <?php _e( 'You can add dynamic QR Codes to your certificate template using these placeholders:', 'ffc' ); ?>
                    </p>
                    <table>
                        <tr>
                            <td><code>{{qr_code}}</code></td>
                            <td><?php _e( 'Default QR Code (200x200px)', 'ffc' ); ?></td>
                        </tr>
                        <tr>
                            <td><code>{{qr_code:size=150}}</code></td>
                            <td><?php _e( 'Custom size (150x150px)', 'ffc' ); ?></td>
                        </tr>
                        <tr>
                            <td><code>{{qr_code:size=250:margin=0}}</code></td>
                            <td><?php _e( 'Custom size without margin', 'ffc' ); ?></td>
                        </tr>
                        <tr>
                            <td><code>{{qr_code:error=H}}</code></td>
                            <td><?php _e( 'High error correction (30%)', 'ffc' ); ?></td>
                        </tr>
                    </table>
                    <p class="ffc-qr-note">
                        💡 <?php _e( 'QR Codes automatically link to this certificate verification page. Configure defaults in Settings → QR Code.', 'ffc' ); ?>
                    </p>
                </div>
            </td>
        </tr>
        <?php
    }

    /**
     * Render LGPD consent status section
     */
    private function render_consent_section(): void {
        $consent_given = isset( $this->sub_array['consent_given'] ) ? (int) $this->sub_array['consent_given'] : 0;
        $consent_date = isset( $this->sub_array['consent_date'] ) ? $this->sub_array['consent_date'] : '';
        $consent_ip = isset( $this->sub_array['consent_ip'] ) ? $this->sub_array['consent_ip'] : '';

        ?>
        <!-- ✅ v2.10.0: SEÇÃO LGPD CONSENT STATUS -->
        <tr>
            <td colspan="2">
                <div class="ffc-consent-box <?php echo $consent_given ? 'consent-given' : 'consent-not-given'; ?>">
                    <h3>
                        <?php echo $consent_given ? '✅' : '⚠️'; ?>
                        <?php _e( 'LGPD Consent Status', 'ffc' ); ?>
                    </h3>

                    <?php if ( $consent_given ): ?>
                        <p>
                            <strong><?php _e( 'Consent given:', 'ffc' ); ?></strong>
                            <?php _e( 'User explicitly agreed to data storage and privacy policy.', 'ffc' ); ?>
                        </p>
                        <?php if ( $consent_date ): ?>
                            <p class="description">
                                📅 <?php printf( __( 'Date: %s', 'ffc' ), esc_html( $consent_date ) ); ?>
                            </p>
                        <?php endif; ?>

                        <?php if ( $consent_ip ): ?>
                            <p class="description">
                                🌐 <?php printf( __( 'IP: %s', 'ffc' ), esc_html( $consent_ip ) ); ?>
                            </p>
                        <?php endif; ?>

                        <p class="description">
                            🔒 <?php _e( 'Sensitive data (email, CPF/RF, IP) is encrypted in the database.', 'ffc' ); ?>
                        </p>
                    <?php else: ?>
                        <p>
                            <strong><?php _e( 'No consent recorded:', 'ffc' ); ?></strong>
                            <?php _e( 'This submission was created before LGPD consent feature (v2.10.0).', 'ffc' ); ?>
                        </p>
                        <p class="description">
                            ℹ️ <?php _e( 'Older submissions do not have explicit consent flag but may have been collected under privacy policy.', 'ffc' ); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php
    }

    /**
     * Render participant data section
     *
     * Displays email (editable), CPF/RF, auth code (read-only).
     */
    private function render_participant_data_section(): void {
        ?>
        <!-- SEÇÃO: DADOS DO PARTICIPANTE -->
        <tr>
            <td colspan="2">
                <h2 class="ffc-section-header">
                    <?php _e( 'Participant Data', 'ffc' ); ?>
                </h2>
            </td>
        </tr>

        <!-- ✅ EMAIL (editável) -->
        <tr>
            <th><label for="user_email"><?php _e( 'Email', 'ffc' ); ?> *</label></th>
            <td>
                <input type="email" name="user_email" id="user_email" value="<?php echo esc_attr($this->sub_array['email']); ?>" class="regular-text" required>
                <?php if ( ! empty( $this->sub_array['email_encrypted'] ) ): ?>
                    <p class="description">🔒 <?php _e( 'This email is encrypted in the database.', 'ffc' ); ?></p>
                <?php endif; ?>
            </td>
        </tr>

        <!-- ✅ CPF/RF (read-only se existir) -->
        <?php if ( !empty( $this->sub_array['cpf_rf'] ) ): ?>
        <tr>
            <th><label><?php _e( 'CPF/RF', 'ffc' ); ?></label></th>
            <td>
                <input type="text" value="<?php echo esc_attr( $this->sub_array['cpf_rf'] ); ?>" class="regular-text ffc-input-readonly" readonly>
                <?php if ( ! empty( $this->sub_array['cpf_rf_encrypted'] ) ): ?>
                    <p class="description">🔒 <?php _e( 'This CPF/RF is encrypted in the database.', 'ffc' ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php endif; ?>

        <!-- ✅ AUTH CODE (read-only se existir) -->
        <?php if ( !empty( $this->sub_array['auth_code'] ) ): ?>
        <tr>
            <th><label><?php _e( 'Auth Code', 'ffc' ); ?></label></th>
            <td>
                <input type="text" value="<?php echo esc_attr( $this->sub_array['auth_code'] ); ?>" class="regular-text ffc-input-readonly" readonly>
                <p class="description"><?php _e( 'Protected authentication code.', 'ffc' ); ?></p>
            </td>
        </tr>
        <?php endif; ?>
        <?php
    }

    /**
     * Render dynamic fields from JSON data
     *
     * Renders all custom fields from the form submission.
     */
    private function render_dynamic_fields(): void {
        // Protected fields (read-only within JSON)
        $protected_json_fields = array( 'auth_code', 'fill_date', 'ticket' );

        if ( ! is_array( $this->data ) ) {
            return;
        }

        foreach ( $this->data as $k => $v ) {
            // Skip old tracking fields (now in columns)
            if ( $k === 'is_edited' || $k === 'edited_at' ) {
                continue;
            }

            // Get field label
            $lbl = $k;
            if ( is_array( $this->fields ) ) {
                foreach ( $this->fields as $f ) {
                    if ( isset( $f['name'] ) && $f['name'] === $k ) {
                        $lbl = $f['label'];
                    }
                }
            }

            // Determine if field is protected
            $is_protected = in_array( $k, $protected_json_fields );
            $field_class = $is_protected ? 'regular-text ffc-input-readonly' : 'regular-text';
            $readonly_attr = $is_protected ? 'readonly' : '';
            $display_value = is_array( $v ) ? implode( ', ', $v ) : $v;

            ?>
            <tr>
                <th><?php echo esc_html( $lbl ); ?></th>
                <td>
                    <input type="text" name="data[<?php echo esc_attr($k); ?>]" value="<?php echo esc_attr($display_value); ?>" class="<?php echo esc_attr($field_class); ?>" <?php echo $readonly_attr; ?>>
                    <?php if ( $is_protected ): ?>
                        <p class="description"><?php _e('Protected internal field.', 'ffc'); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
        }
    }

    /**
     * Handle save request
     *
     * Processes submission edit form POST request.
     */
    public function handle_save(): void {
        if ( ! isset( $_POST['ffc_save_edit'] ) ) {
            return;
        }

        if ( ! check_admin_referer( 'ffc_edit_submission_nonce', 'ffc_edit_submission_action' ) ) {
            return;
        }

        $id = absint( $_POST['submission_id'] );
        $new_email = sanitize_email( $_POST['user_email'] );
        $raw_data = isset( $_POST['data'] ) ? $_POST['data'] : array();
        $clean_data = array();

        foreach ( $raw_data as $k => $v ) {
            $clean_data[ sanitize_key( $k ) ] = wp_kses( $v, \FFC_Utils::get_allowed_html_tags() );
        }

        $this->submission_handler->update_submission( $id, $new_email, $clean_data );

        wp_redirect( admin_url( 'edit.php?post_type=ffc_form&page=ffc-submissions&msg=updated' ) );
        exit;
    }
}
