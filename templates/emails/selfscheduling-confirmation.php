<?php
/**
 * Self-scheduling confirmation email — default body.
 *
 * The default the "Restore Default Text" button seeds and the send path falls
 * back to when a calendar has no custom confirmation body. Wrapped by the
 * configurable chrome (layout.php) at send. Placeholders resolve via
 * AppointmentEmailHandler::render_confirmation_template().
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since   6.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'body' => __( '<p>Hello {{user_name}},</p><p>Your appointment for <strong>{{calendar_title}}</strong> is confirmed.</p><ul><li>Date: {{appointment_date}}</li><li>Time: {{appointment_time}}</li></ul><p>See you then!</p>', 'ffcertificate' ),
);
