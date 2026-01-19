<?php
/**
 * Template: Certificate Verification Page
 *
 * Variables available:
 * @var string $security_fields Generated security fields HTML
 *
 * @since 3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="ffc-verification-container ffc-verification-auto-check">
    <!-- Loading (hidden initially, shown by JS if hash token found) -->
    <div class="ffc-verify-loading" style="display:none;">
        <div class="ffc-spinner"></div>
        <p><?php esc_html_e( 'Verifying certificate...', 'ffc' ); ?></p>
    </div>

    <!-- Manual verification form -->
    <div class="ffc-verification-manual">
        <div class="ffc-verification-header">
            <h2><?php esc_html_e( 'Verify Certificate', 'ffc' ); ?></h2>
            <p><?php esc_html_e( 'Enter the authentication code to verify the certificate authenticity.', 'ffc' ); ?></p>
        </div>

        <form method="POST" class="ffc-verification-form">
            <div class="ffc-verify-input-group">
                <input
                    type="text"
                    name="ffc_auth_code"
                    class="ffc-input ffc-verify-input"
                    placeholder="<?php esc_attr_e( 'XXXX-XXXX-XXXX', 'ffc' ); ?>"
                    required
                    maxlength="14"
                    pattern="[A-Za-z0-9\-]+"
                >
                <button type="submit" class="ffc-submit-btn"><?php esc_html_e( 'Verify', 'ffc' ); ?></button>
            </div>
            <div class="ffc-no-js-security"><?php echo $security_fields; ?></div>
        </form>
    </div>

    <!-- Verification result -->
    <div class="ffc-verify-result"></div>
</div>
