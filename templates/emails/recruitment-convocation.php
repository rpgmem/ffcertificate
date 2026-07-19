<?php
/**
 * Recruitment convocation email — default body.
 *
 * The editable default for the recruitment convocation email (Recruitment →
 * Settings). Wrapped by the configurable chrome (layout.php) at send.
 * All {{placeholder}} markers resolve via Core\TokenResolver at send time.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'body' => __( '<p>Hello {{name}},</p><p>You have been called for notice <strong>{{notice_code}} — {{notice_name}}</strong> in adjutancy <strong>{{adjutancy}}</strong>.</p><ul><li><strong>Rank:</strong> {{rank}}</li><li><strong>Score:</strong> {{score}}</li><li><strong>Date to assume:</strong> {{date_to_assume}}</li><li><strong>Time:</strong> {{time_to_assume}}</li></ul><p>{{notes}}</p><p>— {{site_name}}<br>{{site_url}}</p>', 'ffcertificate' ),
);
