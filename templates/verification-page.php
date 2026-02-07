<?php
/**
 * Template: Document Verification Page
 *
 * Handles both certificate and appointment receipt verification.
 *
 * Variables available:
 * @var string $security_fields Generated security fields HTML
 *
 * @since 3.1.0
 * @since 3.4.0 - Restructured layout: code → captcha → button. Generic "Document" text.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="ffc-verification-container ffc-verification-auto-check">
    <!-- Loading (hidden initially, shown by JS if hash token found) -->
    <div class="ffc-verify-loading" style="display:none;">
        <div class="ffc-spinner"></div>
        <p><?php esc_html_e( 'Verifying document...', 'ffcertificate' ); ?></p>
    </div>

    <!-- Manual verification form -->
    <div class="ffc-verification-manual">
        <div class="ffc-verification-header">
            <h2><?php esc_html_e( 'Verify Document', 'ffcertificate' ); ?></h2>
            <p><?php esc_html_e( 'Enter the authentication code to verify document authenticity.', 'ffcertificate' ); ?></p>
        </div>

        <form method="POST" class="ffc-verification-form">
            <div class="ffc-form-field">
                <label for="ffc_auth_code">
                    <?php esc_html_e( 'Authentication Code', 'ffcertificate' ); ?> <span class="required">*</span>
                </label>
                <input
                    type="text"
                    name="ffc_auth_code"
                    id="ffc_auth_code"
                    class="ffc-input ffc-verify-input"
                    placeholder="<?php esc_attr_e( 'XXXX-XXXX-XXXX', 'ffcertificate' ); ?>"
                    required
                    maxlength="14"
                    pattern="[A-Za-z0-9\-]+"
                >
            </div>

            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generate_security_fields() escapes all output internally ?>
            <div class="ffc-no-js-security"><?php echo $security_fields; ?></div>

            <button type="submit" class="ffc-submit-btn"><?php esc_html_e( 'Verify', 'ffcertificate' ); ?></button>
        </form>
    </div>

    <!-- Verification result -->
    <div class="ffc-verify-result"></div>
</div>
