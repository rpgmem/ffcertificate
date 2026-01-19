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
?>

<div class="ffc-certificate-preview">
    <div class="ffc-preview-header">
        <span class="ffc-status-badge success">✅ <?php esc_html_e( 'Valid Certificate', 'ffc' ); ?></span>
    </div>

    <div class="ffc-preview-body">
        <h3><?php esc_html_e( 'Certificate Details', 'ffc' ); ?></h3>

        <div class="ffc-detail-row">
            <span class="label"><?php esc_html_e( 'Authentication Code:', 'ffc' ); ?></span>
            <span class="value code"><?php echo esc_html( $display_code ); ?></span>
        </div>

        <div class="ffc-detail-row">
            <span class="label"><?php esc_html_e( 'Event:', 'ffc' ); ?></span>
            <span class="value"><?php echo esc_html( $form_title ); ?></span>
        </div>

        <div class="ffc-detail-row">
            <span class="label"><?php esc_html_e( 'Issued on:', 'ffc' ); ?></span>
            <span class="value"><?php echo esc_html( $date_generated ); ?></span>
        </div>

        <hr>
        <h4><?php esc_html_e( 'Participant Data:', 'ffc' ); ?></h4>

        <?php if ( is_array( $data ) ) : ?>
            <?php
            // Show priority fields first
            foreach ( $priority_fields as $field ) {
                if ( ! isset( $data[$field] ) || in_array( $field, $skip_fields ) ) {
                    continue;
                }

                $value = $data[$field];
                $label = call_user_func( $get_field_label_callback, $field );
                $display_value = call_user_func( $format_field_value_callback, $field, $value );
                ?>
                <div class="ffc-detail-row">
                    <span class="label"><?php echo esc_html( $label ); ?>:</span>
                    <span class="value"><?php echo esc_html( $display_value ); ?></span>
                </div>
                <?php
            }

            // Then show remaining fields
            foreach ( $data as $key => $value ) {
                // Skip if already shown or in skip list
                if ( in_array( $key, $priority_fields ) || in_array( $key, $skip_fields, true ) ) {
                    continue;
                }

                $label = call_user_func( $get_field_label_callback, $key );
                $display_value = call_user_func( $format_field_value_callback, $key, $value );
                ?>
                <div class="ffc-detail-row">
                    <span class="label"><?php echo esc_html( $label ); ?>:</span>
                    <span class="value"><?php echo esc_html( $display_value ); ?></span>
                </div>
                <?php
            }
            ?>
        <?php endif; ?>
    </div>

    <?php if ( $show_download_button ) : ?>
        <div class="ffc-preview-actions">
            <button class="ffc-download-btn ffc-download-pdf-btn" data-submission-id="<?php echo esc_attr( $submission->id ); ?>">
                ⬇️ <?php esc_html_e( 'Download Certificate (PDF)', 'ffc' ); ?>
            </button>
        </div>
    <?php endif; ?>
</div>
