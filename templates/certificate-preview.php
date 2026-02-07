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
        <span class="ffc-status-badge success">✅ <?php esc_html_e( 'Valid Certificate', 'wp-ffcertificate' ); ?></span>
    </div>

    <div class="ffc-preview-body">
        <h3><?php esc_html_e( 'Certificate Details', 'wp-ffcertificate' ); ?></h3>

        <div class="ffc-detail-row">
            <span class="label"><?php esc_html_e( 'Authentication Code:', 'wp-ffcertificate' ); ?></span>
            <span class="value code"><?php echo esc_html( $display_code ); ?></span>
        </div>

        <div class="ffc-detail-row">
            <span class="label"><?php esc_html_e( 'Event:', 'wp-ffcertificate' ); ?></span>
            <span class="value"><?php echo esc_html( $form_title ); ?></span>
        </div>

        <div class="ffc-detail-row">
            <span class="label"><?php esc_html_e( 'Issued on:', 'wp-ffcertificate' ); ?></span>
            <span class="value"><?php echo esc_html( $date_generated ); ?></span>
        </div>

        <hr>
        <h4><?php esc_html_e( 'Participant Data:', 'wp-ffcertificate' ); ?></h4>

        <?php if ( is_array( $data ) ) : ?>
            <?php
            // Show priority fields first
            foreach ( $priority_fields as $wp_ffcertificate_field ) {
                if ( ! isset( $data[$wp_ffcertificate_field] ) || in_array( $wp_ffcertificate_field, $skip_fields ) ) {
                    continue;
                }

                $wp_ffcertificate_value = $data[$wp_ffcertificate_field];
                $wp_ffcertificate_label = call_user_func( $get_field_label_callback, $wp_ffcertificate_field );
                $wp_ffcertificate_display = call_user_func( $format_field_value_callback, $wp_ffcertificate_field, $wp_ffcertificate_value );
                ?>
                <div class="ffc-detail-row">
                    <span class="label"><?php echo esc_html( $wp_ffcertificate_label ); ?>:</span>
                    <span class="value"><?php echo esc_html( $wp_ffcertificate_display ); ?></span>
                </div>
                <?php
            }

            // Then show remaining fields
            foreach ( $data as $wp_ffcertificate_key => $wp_ffcertificate_value ) {
                // Skip if already shown or in skip list
                if ( in_array( $wp_ffcertificate_key, $priority_fields ) || in_array( $wp_ffcertificate_key, $skip_fields, true ) ) {
                    continue;
                }

                $wp_ffcertificate_label = call_user_func( $get_field_label_callback, $wp_ffcertificate_key );
                $wp_ffcertificate_display = call_user_func( $format_field_value_callback, $wp_ffcertificate_key, $wp_ffcertificate_value );
                ?>
                <div class="ffc-detail-row">
                    <span class="label"><?php echo esc_html( $wp_ffcertificate_label ); ?>:</span>
                    <span class="value"><?php echo esc_html( $wp_ffcertificate_display ); ?></span>
                </div>
                <?php
            }
            ?>
        <?php endif; ?>
    </div>

    <?php if ( $show_download_button ) : ?>
        <div class="ffc-preview-actions">
            <button class="ffc-download-btn ffc-download-pdf-btn" data-submission-id="<?php echo esc_attr( $submission->id ); ?>">
                ⬇️ <?php esc_html_e( 'Download Certificate (PDF)', 'wp-ffcertificate' ); ?>
            </button>
        </div>
    <?php endif; ?>
</div>
