<?php
/**
 * Template: Submission Success Message
 *
 * Variables available:
 * @var string $success_message Success message text
 * @var string $form_title Form title
 * @var string $date_formatted Formatted submission date
 * @var string $auth_code Authentication code (optional)
 *
 * @since 3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="ffc-success-response">
    <div class="ffc-success-icon">✓</div>
    <h3><?php echo esc_html( $success_message ); ?></h3>
    <div class="ffc-success-details">
        <p>
            <strong><?php esc_html_e( 'Form:', 'ffc' ); ?></strong>
            <?php echo esc_html( $form_title ); ?>
        </p>
        <p>
            <strong><?php esc_html_e( 'Date:', 'ffc' ); ?></strong>
            <?php echo esc_html( $date_formatted ); ?>
        </p>
        <?php if ( ! empty( $auth_code ) ): ?>
            <p>
                <strong><?php esc_html_e( 'Authentication Code:', 'ffc' ); ?></strong>
                <code><?php echo esc_html( $auth_code ); ?></code>
            </p>
        <?php endif; ?>
    </div>
</div>
