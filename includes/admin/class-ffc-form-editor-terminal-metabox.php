<?php
/**
 * FormEditorTerminalMetabox
 *
 * The form editor's Terminal tab and its side box (#2892): whether an event
 * terminal may issue this form's certificate from the participant's app QR,
 * and who may receive it there.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.52.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\TerminalTargetKey;
use FreeFormCertificate\Frontend\CertificateFormTarget;
use FreeFormCertificate\Frontend\CertificateTerminalReadingRepository;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Terminal tab of the form editor and its side box, following
 * the approved mockup (#2889): the switch, the event's day and hours read
 * from the Time tab, who may receive, which restrictions apply there, and a
 * warning for each required field a profile cannot fill.
 */
final class FormEditorTerminalMetabox {

	/**
	 * Id of the switch, read by the tab's on/off dot.
	 */
	public const TOGGLE_ID = 'ffc_terminal_enabled';

	/**
	 * Render the tab's panel.
	 *
	 * @param WP_Post $post Form being edited.
	 * @return void
	 */
	public function render( WP_Post $post ): void {
		$form_id  = (int) $post->ID;
		$settings = CertificateFormTarget::settings( $form_id );
		$blocker  = CertificateFormTarget::cannot_enable( $form_id );
		$refuses  = null !== $blocker && ! $settings['enabled'];
		$fields   = get_post_meta( $form_id, '_ffc_form_fields', true );
		$fields   = is_array( $fields ) ? array_values( array_filter( $fields, 'is_array' ) ) : array();
		$unfilled = CertificateFormTarget::unfilled_required( $fields );
		?>
		<p class="description"><?php esc_html_e( 'The event terminal reads the QR in the participant\'s app and issues the certificate at once, from the profile. The form still accepts entries on the site.', 'ffcertificate' ); ?></p>

		<?php
		AdminUI::render_toggle(
			array(
				'name'        => 'ffc_config[' . CertificateFormTarget::CONFIG_KEY . '][enabled]',
				'id'          => self::TOGGLE_ID,
				'checked'     => $settings['enabled'],
				'disabled'    => $refuses,
				'label'       => __( 'Issue at the terminal', 'ffcertificate' ),
				'description' => null !== $blocker ? $blocker->get_error_message() : '',
			)
		);
		?>

		<h4><?php esc_html_e( 'Event', 'ffcertificate' ); ?></h4>
		<?php $this->render_window( $form_id ); ?>

		<h4><?php esc_html_e( 'Who may receive at the terminal', 'ffcertificate' ); ?></h4>
		<?php
		AdminUI::render_segmented(
			array(
				'name'         => 'ffc_config[' . CertificateFormTarget::CONFIG_KEY . '][who]',
				'id'           => 'ffc_terminal_who',
				'legend'       => __( 'Who may receive at the terminal', 'ffcertificate' ),
				'value'        => $settings['who'],
				'options'      => array(
					CertificateFormTarget::WHO_ANY       => __( 'Any account', 'ffcertificate' ),
					CertificateFormTarget::WHO_ALLOWLIST => __( 'Allow list only', 'ffcertificate' ),
				),
				'descriptions' => array(
					CertificateFormTarget::WHO_ANY       => __( 'Anyone who shows the app QR receives the certificate, unless the deny list names them.', 'ffcertificate' ),
					CertificateFormTarget::WHO_ALLOWLIST => __( 'Only people on the Security tab\'s allow list receive it at the terminal.', 'ffcertificate' ),
				),
			)
		);
		?>

		<p class="description"><?php esc_html_e( 'From the Security tab, the deny list, the submission limits and the ticket apply to a reading: with the ticket on, the terminal asks for the code after the QR, each code once, and pauses reading while it is offline. The password and the GPS area do not apply: standing at the terminal proves both.', 'ffcertificate' ); ?></p>

		<?php
		if ( array() !== $unfilled ) {
			\FreeFormCertificate\Core\AdminNotice::callout(
				'warning',
				'<p>' . esc_html(
					sprintf(
						/* translators: %s: labels of the required fields, separated by commas. */
						__( 'Required with no profile source: %s. Whoever lacks it has the attendance recorded and receives "Complete your certificate".', 'ffcertificate' ),
						implode( ', ', $unfilled )
					)
				) . '</p><p><a href="#ffc-tab-builder">' . esc_html__( 'Map the field', 'ffcertificate' ) . '</a></p>',
				array(
					'title' => sprintf(
						/* translators: %d: number of required fields. */
						_n( '%d required field does not come from the profile', '%d required fields do not come from the profile', count( $unfilled ), 'ffcertificate' ),
						count( $unfilled )
					),
				)
			);
		}
	}

	/**
	 * The event's day, opening and closing, as the Time tab sets them.
	 *
	 * @param int $form_id Form id.
	 * @return void
	 */
	private function render_window( int $form_id ): void {
		$geofence = get_post_meta( $form_id, '_ffc_geofence_config', true );
		$geofence = is_array( $geofence ) ? $geofence : array();
		$dated    = ! empty( $geofence['datetime_enabled'] );
		$date     = $dated && is_string( $geofence['date_start'] ?? null ) ? DateFormatter::format_wallclock_date( $geofence['date_start'] ) : '';
		$opens    = $dated && is_string( $geofence['time_start'] ?? null ) ? $geofence['time_start'] : '';
		$closes   = $dated && is_string( $geofence['time_end'] ?? null ) ? $geofence['time_end'] : '';
		$none     = '—';
		?>
		<dl class="ffc-facts ffc-terminal-window">
			<dt class="ffc-facts__label"><?php esc_html_e( 'Date', 'ffcertificate' ); ?></dt>
			<dd class="ffc-facts__value"><?php echo esc_html( '' !== $date ? $date : $none ); ?></dd>
			<dt class="ffc-facts__label"><?php esc_html_e( 'Opens', 'ffcertificate' ); ?></dt>
			<dd class="ffc-facts__value"><?php echo esc_html( '' !== $opens ? substr( $opens, 0, 5 ) : $none ); ?></dd>
			<dt class="ffc-facts__label"><?php esc_html_e( 'Closes', 'ffcertificate' ); ?></dt>
			<dd class="ffc-facts__value"><?php echo esc_html( '' !== $closes ? substr( $closes, 0, 5 ) : $none ); ?></dd>
		</dl>
		<p class="description">
			<?php esc_html_e( 'The terminal reads while the form is open: the date and hours are the Time tab\'s, with no window of their own. To turn it on, the opening and closing must fall on the same day.', 'ffcertificate' ); ?>
			<a href="#ffc-tab-time"><?php esc_html_e( 'Open the Time tab', 'ffcertificate' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Render the side box: this event at the terminal.
	 *
	 * @param WP_Post $post Form being edited.
	 * @return void
	 */
	public function render_side( WP_Post $post ): void {
		$form_id  = (int) $post->ID;
		$settings = CertificateFormTarget::settings( $form_id );
		$window   = CertificateFormTarget::window( $form_id );
		$hours    = null === $window
			? '—'
			: DateFormatter::format_time( $window['opens'] ) . '–' . DateFormatter::format_time( $window['closes'] );
		$paired   = $settings['enabled'] ? \FreeFormCertificate\Audience\AudienceTerminalReader::count_active_for_target( TerminalTargetKey::of( CertificateFormTarget::TYPE, $form_id ), time() ) : 0;
		$issued   = CertificateTerminalReadingRepository::counts( $form_id )[ CertificateTerminalReadingRepository::ISSUED ];
		?>
		<dl class="ffc-facts">
			<dt class="ffc-facts__label"><?php esc_html_e( 'Terminal', 'ffcertificate' ); ?></dt>
			<dd class="ffc-facts__value<?php echo $settings['enabled'] ? ' is-on' : ''; ?>"><?php echo esc_html( $settings['enabled'] ? __( 'On', 'ffcertificate' ) : __( 'Off', 'ffcertificate' ) ); ?></dd>
			<dt class="ffc-facts__label"><?php esc_html_e( 'Reading', 'ffcertificate' ); ?></dt>
			<dd class="ffc-facts__value"><?php echo esc_html( $hours ); ?></dd>
			<dt class="ffc-facts__label"><?php esc_html_e( 'Paired terminals', 'ffcertificate' ); ?></dt>
			<dd class="ffc-facts__value"><?php echo esc_html( number_format_i18n( $paired ) ); ?></dd>
			<dt class="ffc-facts__label"><?php esc_html_e( 'Issued by the terminal', 'ffcertificate' ); ?></dt>
			<dd class="ffc-facts__value"><?php echo esc_html( number_format_i18n( $issued ) ); ?></dd>
		</dl>
		<p class="description"><?php esc_html_e( 'To pair: in the app, More › Pair terminal, then choose this form.', 'ffcertificate' ); ?></p>
		<?php
	}
}
