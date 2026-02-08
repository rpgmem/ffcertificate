<?php
/**
 * Template: Certificate Preview for Verification
 *
 * Variables available:
 * @var object $submission Submission object
 * @var array  $data Submission data array
 * @var bool   $show_download_button Whether to show download button
 * @var string $form_title Form title
 * @var string $date_generated Formatted date
 * @var string $display_code Formatted authentication code
 * @var array  $priority_fields Priority fields to show first
 * @var array  $skip_fields Fields to skip in display
 * @var callable $get_field_label_callback Callback to get field label
 * @var callable $format_field_value_callback Callback to format field value
 *
 * @since 3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file
?>

<div class="ffc-certificate-preview">
    <div class="ffc-preview-header">
        <span class="ffc-status-badge success ffc-icon-success"><?php esc_html_e( 'Valid Certificate', 'ffcertificate' ); ?></span>
    </div>

    <div class="ffc-preview-body">
        <h3><?php esc_html_e( 'Certificate Details', 'ffcertificate' ); ?></h3>

        <div class="ffc-detail-row">
            <span class="label"><?php esc_html_e( 'Authentication Code:', 'ffcertificate' ); ?></span>
            <span class="value code"><?php echo esc_html( $display_code ); ?></span>
        </div>

        <div class="ffc-detail-row">
            <span class="label"><?php esc_html_e( 'Event:', 'ffcertificate' ); ?></span>
            <span class="value"><?php echo esc_html( $form_title ); ?></span>
        </div>

        <div class="ffc-detail-row">
            <span class="label"><?php esc_html_e( 'Issued on:', 'ffcertificate' ); ?></span>
            <span class="value"><?php echo esc_html( $date_generated ); ?></span>
        </div>

        <hr>
        <h4><?php esc_html_e( 'Participant Data:', 'ffcertificate' ); ?></h4>

        <?php if ( is_array( $data ) ) : ?>
            <?php
            // Show priority fields first
            foreach ( $priority_fields as $ffcertificate_field ) {
                if ( ! isset( $data[$ffcertificate_field] ) || in_array( $ffcertificate_field, $skip_fields ) ) {
                    continue;
                }

                $ffcertificate_value = $data[$ffcertificate_field];
                $ffcertificate_label = call_user_func( $get_field_label_callback, $ffcertificate_field );
                $ffcertificate_display = call_user_func( $format_field_value_callback, $ffcertificate_field, $ffcertificate_value );
                ?>
                <div class="ffc-detail-row">
                    <span class="label"><?php echo esc_html( $ffcertificate_label ); ?>:</span>
                    <span class="value"><?php echo esc_html( $ffcertificate_display ); ?></span>
                </div>
                <?php
            }

            // Then show remaining fields
            foreach ( $data as $ffcertificate_key => $ffcertificate_value ) {
                // Skip if already shown or in skip list
                if ( in_array( $ffcertificate_key, $priority_fields ) || in_array( $ffcertificate_key, $skip_fields, true ) ) {
                    continue;
                }

                $ffcertificate_label = call_user_func( $get_field_label_callback, $ffcertificate_key );
                $ffcertificate_display = call_user_func( $format_field_value_callback, $ffcertificate_key, $ffcertificate_value );
                ?>
                <div class="ffc-detail-row">
                    <span class="label"><?php echo esc_html( $ffcertificate_label ); ?>:</span>
                    <span class="value"><?php echo esc_html( $ffcertificate_display ); ?></span>
                </div>
                <?php
            }
            ?>
        <?php endif; ?>
    </div>

    <?php if ( $show_download_button ) : ?>
        <div class="ffc-preview-actions">
            <button class="ffc-download-btn ffc-download-pdf-btn ffc-icon-download" data-submission-id="<?php echo esc_attr( $submission->id ); ?>">
                <?php esc_html_e( 'Download Certificate (PDF)', 'ffcertificate' ); ?>
            </button>
        </div>
    <?php endif; ?>
</div>
