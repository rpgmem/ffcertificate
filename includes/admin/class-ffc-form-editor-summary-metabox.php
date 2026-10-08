<?php
/**
 * Form Editor Summary Metabox
 *
 * A read-only sidebar box listing what the form does, as last saved: how
 * many fields and submissions it has, and which features are on (#1614).
 *
 * @since   6.35.0
 * @package FreeFormCertificate\Admin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the form summary sidebar metabox.
 *
 * Reads the stored meta only: it describes the saved form, which is what
 * visitors get, while the tab dots follow the unsaved toggles live.
 */
class FormEditorSummaryMetabox {

	/**
	 * Render the summary.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render( WP_Post $post ): void {
		echo '<dl class="ffc-facts ffc-form-summary">';
		foreach ( $this->rows( $post ) as $row ) {
			printf(
				'<dt class="ffc-facts__label">%s</dt><dd class="ffc-facts__value%s">%s</dd>',
				esc_html( $row['label'] ),
				$row['on'] ? ' is-on' : '',
				$row['html'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each value is escaped where rows() builds it.
			);
		}
		echo '</dl>';
		printf( '<p class="description">%s</p>', esc_html__( 'As last saved. Unsaved changes show on the tab dots.', 'ffcertificate' ) );
	}

	/**
	 * The summary rows, each with its escaped HTML value.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return array<int, array{label: string, html: string, on: bool}>
	 */
	public function rows( WP_Post $post ): array {
		$id       = (int) $post->ID;
		$config   = self::meta_array( $id, '_ffc_form_config' );
		$geofence = self::meta_array( $id, '_ffc_geofence_config' );
		$fields   = self::meta_array( $id, '_ffc_form_fields' );

		$count = 0;
		if ( 'auto-draft' !== $post->post_status ) {
			$count = ( new \FreeFormCertificate\Repositories\SubmissionRepository() )->countForExport( array( $id ), 'publish' );
		}
		$submissions = esc_html( number_format_i18n( $count ) );
		if ( $count > 0 ) {
			$submissions = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'edit.php?post_type=ffc_form&page=ffc-submissions&filter_form_id=' . $id ) ),
				$submissions
			);
		}

		$restrictions = is_array( $config['restrictions'] ?? null ) ? $config['restrictions'] : array();
		$active       = array();
		$names        = array(
			'password'  => __( 'Password', 'ffcertificate' ),
			'allowlist' => __( 'Allowlist', 'ffcertificate' ),
			'denylist'  => __( 'Denylist', 'ffcertificate' ),
			'ticket'    => __( 'Ticket', 'ffcertificate' ),
		);
		foreach ( $names as $key => $name ) {
			if ( '1' === (string) ( $restrictions[ $key ] ?? '' ) ) {
				$active[] = $name;
			}
		}
		if ( '1' === (string) get_post_meta( $id, '_ffc_device_limit_enabled', true ) ) {
			$active[] = __( 'Device limit', 'ffcertificate' );
		}

		$emails = array();
		if ( '1' === (string) ( $config['send_user_email'] ?? '' ) ) {
			$emails[] = __( 'Participant', 'ffcertificate' );
		}
		if ( '1' === (string) ( $config['send_admin_email'] ?? '' ) ) {
			$emails[] = __( 'Administrator', 'ffcertificate' );
		}

		return array(
			self::row( __( 'Fields', 'ffcertificate' ), esc_html( number_format_i18n( count( $fields ) ) ), count( $fields ) > 0 ),
			array(
				'label' => __( 'Submissions', 'ffcertificate' ),
				'html'  => $submissions,
				'on'    => $count > 0,
			),
			self::row( __( 'Restrictions', 'ffcertificate' ), esc_html( array() === $active ? __( 'Open to everyone', 'ffcertificate' ) : implode( ', ', $active ) ), array() !== $active ),
			self::row( __( 'Emails', 'ffcertificate' ), esc_html( array() === $emails ? __( 'Off', 'ffcertificate' ) : implode( ', ', $emails ) ), array() !== $emails ),
			self::toggle_row( __( 'Date/time window', 'ffcertificate' ), '1' === (string) ( $geofence['datetime_enabled'] ?? '' ) ),
			self::toggle_row( __( 'Geolocation', 'ffcertificate' ), '1' === (string) ( $geofence['geo_enabled'] ?? '' ) ),
			self::toggle_row( __( 'Quiz', 'ffcertificate' ), '1' === (string) ( $config['quiz_enabled'] ?? '' ) ),
			self::toggle_row( __( 'Operator access', 'ffcertificate' ), '1' === (string) get_post_meta( $id, '_ffc_csv_public_enabled', true ) ),
		);
	}

	/**
	 * A row with an already-escaped value.
	 *
	 * @param string $label Row label.
	 * @param string $html  Escaped value.
	 * @param bool   $on    Whether the row describes something active.
	 * @return array{label: string, html: string, on: bool}
	 */
	private static function row( string $label, string $html, bool $on ): array {
		return array(
			'label' => $label,
			'html'  => $html,
			'on'    => $on,
		);
	}

	/**
	 * A row that is simply on or off.
	 *
	 * @param string $label Row label.
	 * @param bool   $on    Whether the feature is on.
	 * @return array{label: string, html: string, on: bool}
	 */
	private static function toggle_row( string $label, bool $on ): array {
		return self::row( $label, esc_html( $on ? __( 'On', 'ffcertificate' ) : __( 'Off', 'ffcertificate' ) ), $on );
	}

	/**
	 * A post meta value that must be an array, or an empty one.
	 *
	 * @param int    $id  Post id.
	 * @param string $key Meta key.
	 * @return array<mixed>
	 */
	private static function meta_array( int $id, string $key ): array {
		$value = get_post_meta( $id, $key, true );
		return is_array( $value ) ? $value : array();
	}
}
