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
     * @var \FreeFormCertificate\Submissions\SubmissionHandler
     */
    private $submission_handler;

    /**
     * Submission data (array format)
     *
     * @var array<string, mixed>
     */
    private $sub_array;

    /**
     * Decoded JSON data
     *
     * @var array<string, mixed>
     */
    private $data;

    /**
     * Form fields configuration
     *
     * @var array<int, array<string, mixed>>
     */
    private $fields;

    /**
     * Constructor
     *
     * @param \FreeFormCertificate\Submissions\SubmissionHandler $handler Submission handler instance
     */
    public function __construct( object $handler ) {
        $this->submission_handler = $handler;
    }

    /**
     * Check if current user can edit submissions
     *
     * @since 4.3.0
     * @return bool True if user can edit submissions
     */
    private function can_edit_submission(): bool {
        return \FreeFormCertificate\Core\Utils::current_user_can_manage();
    }

    /**
     * Render the edit page
     *
     * Main entry point that delegates to specialized render methods.
     *
     * @param int $submission_id Submission ID to edit
     */
    public function render( int $submission_id ): void {
        // Permission check
        if ( ! $this->can_edit_submission() ) {
            echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to edit submissions.', 'ffcertificate' ) . '</p></div></div>';
            return;
        }

        // Get submission data
        $sub = $this->submission_handler->get_submission( $submission_id );

        if ( ! $sub ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Submission not found.', 'ffcertificate' ) . '</p></div>';
            return;
        }

        // Prepare data (convert form_id to int - wpdb returns strings)
        $this->sub_array = (array) $sub;
        $this->data = json_decode( $this->sub_array['data'], true ) ?: array();
        $this->fields = get_post_meta( (int) $this->sub_array['form_id'], '_ffc_form_fields', true );

        // Render page
        ?>
        <div class="wrap">
            <h1><?php
                /* translators: %s: submission ID */
                echo esc_html( sprintf( __( 'Edit Submission #%s', 'ffcertificate' ), $this->sub_array['id'] ) );
            ?></h1>

            <?php $this->render_edit_warning(); ?>

            <form method="POST" class="ffc-edit-submission-form">
                <?php wp_nonce_field( 'ffc_edit_submission_nonce', 'ffc_edit_submission_action' ); ?>
                <input type="hidden" name="submission_id" value="<?php echo esc_attr( $this->sub_array['id'] ); ?>">

                <table class="form-table ffc-edit-table">
                    <?php
                    $this->render_system_info_section();
                    $this->render_consent_section();
                    $this->render_participant_data_section();
                    $this->render_dynamic_fields();
                    ?>
                </table>

                <p class="submit">
                    <button type="submit" name="ffc_save_edit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'ffcertificate' ); ?></button>
                    <a href="<?php echo esc_url( admin_url('edit.php?post_type=ffc_form&page=ffc-submissions') ); ?>" class="button"><?php esc_html_e( 'Cancel', 'ffcertificate' ); ?></a>
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
                <strong class="ffc-icon-warning"><?php esc_html_e( 'Warning:', 'ffcertificate' ); ?></strong>
                <?php
                echo wp_kses_post( sprintf(
                    /* translators: %s: name */
                    __( 'This record was manually edited on <strong>%s</strong>', 'ffcertificate' ),
                    esc_html( date_i18n( get_option('date_format') . ' ' . get_option('time_format'), strtotime($edited_at) ) )
                ) );
                ?>
                <?php if ( $edited_by_name ): ?>
                    <?php
                    /* translators: %s: editor name */
                    echo wp_kses_post( sprintf( __( ' by <strong>%s</strong>', 'ffcertificate' ), esc_html($edited_by_name) ) );
                    ?>
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
            : __( 'Unknown', 'ffcertificate' );

        ?>
        <!-- SEÇÃO: INFORMAÇÕES DO SISTEMA -->
        <tr>
            <td colspan="2">
                <h2 class="ffc-section-header">
                    <?php esc_html_e( 'System Information', 'ffcertificate' ); ?>
                </h2>
            </td>
        </tr>

        <tr>
            <th><label><?php esc_html_e( 'Submission ID', 'ffcertificate' ); ?></label></th>
            <td>
                <input type="text" value="<?php echo esc_attr( $this->sub_array['id'] ); ?>" class="regular-text ffc-input-readonly" readonly>
                <p class="description"><?php esc_html_e( 'Unique submission identifier.', 'ffcertificate' ); ?></p>
            </td>
        </tr>

        <tr>
            <th><label><?php esc_html_e( 'Submission Date', 'ffcertificate' ); ?></label></th>
            <td>
                <input type="text" value="<?php echo esc_attr( $formatted_date ); ?>" class="regular-text ffc-input-readonly" readonly>
                <p class="description"><?php esc_html_e( 'Original submission timestamp (read-only).', 'ffcertificate' ); ?></p>
            </td>
        </tr>

        <tr>
            <th><label><?php esc_html_e( 'Status', 'ffcertificate' ); ?></label></th>
            <td>
                <input type="text" value="<?php echo esc_attr( $this->sub_array['status'] ); ?>" class="regular-text ffc-input-readonly" readonly>
                <p class="description"><?php esc_html_e( 'Submission status (publish, trash, etc).', 'ffcertificate' ); ?></p>
            </td>
        </tr>

        <tr>
            <th><label><?php esc_html_e( 'Magic Link Token', 'ffcertificate' ); ?></label></th>
            <td>
                <?php if ( ! empty( $magic_token ) ): ?>
                    <input type="text" value="<?php echo esc_attr( $magic_token ); ?>" class="regular-text ffc-input-readonly" readonly>
                    <p class="description">
                        <?php esc_html_e( 'Unique token for certificate access (read-only).', 'ffcertificate' ); ?>
                        <?php echo wp_kses_post( \FreeFormCertificate\Generators\MagicLinkHelper::get_magic_link_html( $magic_token ) ); ?>
                    </p>
                <?php else: ?>
                    <p class="description"><?php esc_html_e( 'Submission created before magic links', 'ffcertificate' ); ?></p>
                <?php endif; ?>
            </td>
        </tr>

        <?php if ( !empty( $this->sub_array['user_ip'] ) ): ?>
        <tr>
            <th><label><?php esc_html_e( 'User IP', 'ffcertificate' ); ?></label></th>
            <td>
                <input type="text" value="<?php echo esc_attr( $this->sub_array['user_ip'] ); ?>" class="regular-text ffc-input-readonly" readonly>
                <?php if ( ! empty( $this->sub_array['user_ip_encrypted'] ) ): ?>
                    <p class="description"><span class="ffc-icon-lock"></span><?php esc_html_e( 'This IP is encrypted in the database.', 'ffcertificate' ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php endif; ?>

        <?php $this->render_user_link_section(); ?>
        <?php
    }

    /**
     * Render user link section
     *
     * Simplified UI: Shows unlink button if linked, search field if not.
     *
     * @since 4.3.0
     */
    private function render_user_link_section(): void {
        $current_user_id = isset( $this->sub_array['user_id'] ) ? (int) $this->sub_array['user_id'] : 0;
        $current_user = $current_user_id ? get_userdata( $current_user_id ) : null;
        $nonce = wp_create_nonce( 'ffc_user_search_nonce' );

        ?>
        <tr>
            <th><label><?php esc_html_e( 'Linked User', 'ffcertificate' ); ?></label></th>
            <td>
                <div class="ffc-user-link-container" data-submission-id="<?php echo esc_attr( $this->sub_array['id'] ); ?>">
                    <?php if ( $current_user ): ?>
                        <!-- User is linked: show info + unlink button -->
                        <div class="ffc-linked-user-display">
                            <div class="ffc-current-user">
                                <span class="ffc-user-info">
                                    <?php echo get_avatar( $current_user_id, 32 ); ?>
                                    <strong><?php echo esc_html( $current_user->display_name ); ?></strong>
                                    <span class="ffc-user-email">(<?php echo esc_html( $current_user->user_email ); ?>)</span>
                                    <span class="ffc-user-id">ID: <?php echo esc_html( (string) $current_user_id ); ?></span>
                                </span>
                                <a href="<?php echo esc_url( get_edit_user_link( $current_user_id ) ); ?>" target="_blank" class="button button-small">
                                    <?php esc_html_e( 'View Profile', 'ffcertificate' ); ?>
                                </a>
                            </div>
                            <div class="ffc-unlink-action">
                                <input type="hidden" name="linked_user_id" value="__keep__">
                                <button type="button" class="button button-secondary ffc-unlink-user-btn" data-confirm="<?php esc_attr_e( 'Are you sure you want to unlink this user from the submission?', 'ffcertificate' ); ?>">
                                    <?php esc_html_e( 'Unlink User', 'ffcertificate' ); ?>
                                </button>
                                <p class="description">
                                    <?php esc_html_e( 'Removes the link between this submission and the WordPress user.', 'ffcertificate' ); ?>
                                </p>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- No user linked: show search field -->
                        <div class="ffc-user-search-container">
                            <p class="ffc-no-user">
                                <em><?php esc_html_e( 'No user linked to this submission.', 'ffcertificate' ); ?></em>
                            </p>
                            <div class="ffc-user-search-form">
                                <input type="hidden" name="linked_user_id" id="ffc-selected-user-id" value="">
                                <input type="text" id="ffc-user-search-input" class="regular-text" placeholder="<?php esc_attr_e( 'Search by name, email, ID or CPF/RF...', 'ffcertificate' ); ?>">
                                <button type="button" class="button ffc-search-user-btn" data-nonce="<?php echo esc_attr( $nonce ); ?>">
                                    <?php esc_html_e( 'Search', 'ffcertificate' ); ?>
                                </button>
                                <span class="spinner" id="ffc-search-spinner"></span>
                            </div>
                            <div id="ffc-user-search-results" class="ffc-user-search-results" style="display: none;">
                                <!-- Results will be populated via AJAX -->
                            </div>
                            <div id="ffc-selected-user-preview" class="ffc-selected-user-preview" style="display: none;">
                                <!-- Selected user preview will be shown here -->
                            </div>
                            <p class="description">
                                <?php esc_html_e( 'Search for a WordPress user to link to this submission. The user will see this certificate in their dashboard.', 'ffcertificate' ); ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php
    }


    /**
     * Render LGPD consent status section (collapsible)
     *
     * @since 4.3.0 Made collapsible
     */
    private function render_consent_section(): void {
        $consent_given = isset( $this->sub_array['consent_given'] ) ? (int) $this->sub_array['consent_given'] : 0;
        $consent_date = isset( $this->sub_array['consent_date'] ) ? $this->sub_array['consent_date'] : '';
        $consent_ip = isset( $this->sub_array['consent_ip'] ) ? $this->sub_array['consent_ip'] : '';

        ?>
        <!-- SEÇÃO LGPD CONSENT STATUS (collapsible) -->
        <tr>
            <td colspan="2">
                <div class="ffc-consent-box ffc-collapsible <?php echo esc_attr( $consent_given ? 'consent-given' : 'consent-not-given' ); ?>">
                    <h3 class="ffc-consent-header" tabindex="0" role="button" aria-expanded="false">
                        <span class="ffc-consent-toggle-icon">&#9654;</span>
                        <span class="<?php echo esc_attr( $consent_given ? 'ffc-icon-success' : 'ffc-icon-warning' ); ?>"></span>
                        <?php esc_html_e( 'LGPD Consent Status', 'ffcertificate' ); ?>
                        <span class="ffc-consent-summary">
                            — <?php echo esc_html( $consent_given ? __( 'Consent given', 'ffcertificate' ) : __( 'No consent recorded', 'ffcertificate' ) ); ?>
                        </span>
                    </h3>

                    <div class="ffc-consent-details" style="display: none;">
                        <?php if ( $consent_given ): ?>
                            <p>
                                <strong><?php esc_html_e( 'Consent given:', 'ffcertificate' ); ?></strong>
                                <?php esc_html_e( 'User explicitly agreed to data storage and privacy policy.', 'ffcertificate' ); ?>
                            </p>
                            <?php if ( $consent_date ): ?>
                                <p class="description">
                                    <?php
                                    /* translators: %s: consent date/time */
                                    echo esc_html( sprintf( __( 'Date: %s', 'ffcertificate' ), $consent_date ) );
                                    ?>
                                </p>
                            <?php endif; ?>

                            <?php if ( $consent_ip ): ?>
                                <p class="description">
                                    <?php
                                    /* translators: %s: IP address */
                                    echo esc_html( sprintf( __( 'IP: %s', 'ffcertificate' ), $consent_ip ) );
                                    ?>
                                </p>
                            <?php endif; ?>

                            <p class="description">
                                <?php esc_html_e( 'Sensitive data (email, CPF/RF, IP) is encrypted in the database.', 'ffcertificate' ); ?>
                            </p>
                        <?php else: ?>
                            <p>
                                <strong><?php esc_html_e( 'No consent recorded:', 'ffcertificate' ); ?></strong>
                                <?php esc_html_e( 'This submission was created before LGPD consent feature (v2.10.0).', 'ffcertificate' ); ?>
                            </p>
                            <p class="description">
                                <?php esc_html_e( 'Older submissions do not have explicit consent flag but may have been collected under privacy policy.', 'ffcertificate' ); ?>
                            </p>
                        <?php endif; ?>
                    </div>
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
                    <?php esc_html_e( 'Participant Data', 'ffcertificate' ); ?>
                </h2>
            </td>
        </tr>

        <!-- ✅ EMAIL (editável) -->
        <tr>
            <th><label for="user_email"><?php esc_html_e( 'Email', 'ffcertificate' ); ?> *</label></th>
            <td>
                <input type="email" name="user_email" id="user_email" value="<?php echo esc_attr($this->sub_array['email']); ?>" class="regular-text" required>
                <?php if ( ! empty( $this->sub_array['email_encrypted'] ) ): ?>
                    <p class="description"><span class="ffc-icon-lock"></span><?php esc_html_e( 'This email is encrypted in the database.', 'ffcertificate' ); ?></p>
                <?php endif; ?>
            </td>
        </tr>

        <!-- ✅ CPF/RF (read-only se existir) -->
        <?php if ( !empty( $this->sub_array['cpf_rf'] ) ): ?>
        <tr>
            <th><label><?php echo esc_html( !empty( $this->sub_array['rf'] ) ? __( 'RF', 'ffcertificate' ) : __( 'CPF', 'ffcertificate' ) ); ?></label></th>
            <td>
                <input type="text" value="<?php echo esc_attr( $this->sub_array['cpf_rf'] ); ?>" class="regular-text ffc-input-readonly" readonly>
                <?php if ( ! empty( $this->sub_array['cpf_encrypted'] ) || ! empty( $this->sub_array['rf_encrypted'] ) ): ?>
                    <p class="description"><span class="ffc-icon-lock"></span><?php esc_html_e( 'This identifier is encrypted in the database.', 'ffcertificate' ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php endif; ?>

        <!-- ✅ AUTH CODE (read-only se existir) -->
        <?php if ( !empty( $this->sub_array['auth_code'] ) ): ?>
        <tr>
            <th><label><?php esc_html_e( 'Auth Code', 'ffcertificate' ); ?></label></th>
            <td>
                <input type="text" value="<?php echo esc_attr( $this->sub_array['auth_code'] ); ?>" class="regular-text ffc-input-readonly" readonly>
                <p class="description"><?php esc_html_e( 'Protected authentication code.', 'ffcertificate' ); ?></p>
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
                    <input type="text" name="data[<?php echo esc_attr($k); ?>]" value="<?php echo esc_attr($display_value); ?>" class="<?php echo esc_attr($field_class); ?>" <?php echo esc_attr( $readonly_attr ); ?>>
                    <?php if ( $is_protected ): ?>
                        <p class="description"><?php esc_html_e('Protected internal field.', 'ffcertificate'); ?></p>
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
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified below via check_admin_referer.
        if ( ! isset( $_POST['ffc_save_edit'] ) ) {
            return;
        }

        // Permission check
        if ( ! $this->can_edit_submission() ) {
            wp_die( esc_html__( 'You do not have permission to edit submissions.', 'ffcertificate' ) );
        }

        if ( ! check_admin_referer( 'ffc_edit_submission_nonce', 'ffc_edit_submission_action' ) ) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_admin_referer.
        $id = isset( $_POST['submission_id'] ) ? absint( wp_unslash( $_POST['submission_id'] ) ) : 0;
        // Normalize email to lowercase for consistent storage and lookups
        $new_email = isset( $_POST['user_email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['user_email'] ) ) ) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field sanitized individually below.
        $raw_data = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array();
        $clean_data = array();

        // Name fields that should be normalized (capitalized with lowercase connectives)
        $name_fields = array( 'nome_completo', 'nome', 'name', 'full_name', 'ffc_nome', 'participante' );

        foreach ( $raw_data as $k => $v ) {
            $sanitized_key = sanitize_key( $k );
            $sanitized_value = wp_kses( $v, \FreeFormCertificate\Core\Utils::get_allowed_html_tags() );

            // Normalize name fields (proper capitalization with lowercase connectives)
            if ( in_array( $sanitized_key, $name_fields, true ) && ! empty( $sanitized_value ) ) {
                $sanitized_value = \FreeFormCertificate\Core\Utils::normalize_brazilian_name( $sanitized_value );
            }

            $clean_data[ $sanitized_key ] = $sanitized_value;
        }

        // Process user link change (simplified: value is user ID, empty string, or __keep__)
        $linked_user_id = isset( $_POST['linked_user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['linked_user_id'] ) ) : '__keep__';

        // phpcs:enable WordPress.Security.NonceVerification.Missing

        // Update submission data (email + custom fields)
        $this->submission_handler->update_submission( $id, $new_email, $clean_data );

        // Update user link if changed (not __keep__)
        if ( $linked_user_id !== '__keep__' ) {
            $new_user_id = $linked_user_id === '' ? null : (int) $linked_user_id;

            // Validate user exists if linking to a user
            if ( $new_user_id !== null && ! get_userdata( $new_user_id ) ) {
                // Invalid user ID - skip user link update
                \FreeFormCertificate\Core\Utils::debug_log( 'Invalid user ID for linking', array(
                    'submission_id' => $id,
                    'user_id' => $new_user_id,
                ) );
            } else {
                $this->submission_handler->update_user_link( $id, $new_user_id );
            }
        }

        wp_safe_redirect( admin_url( 'edit.php?post_type=ffc_form&page=ffc-submissions&msg=updated' ) );
        exit;
    }
}
