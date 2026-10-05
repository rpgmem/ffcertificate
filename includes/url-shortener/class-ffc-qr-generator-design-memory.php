<?php
/**
 * The QR generator's remembered design, per user.
 *
 * @package FreeFormCertificate\UrlShortener
 * @since   6.34.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Generators\QrDesign;
use FreeFormCertificate\Generators\QrLogo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each user's last generator design, kept in user meta (#1568).
 *
 * Only the design is kept -- shapes, colours, logo id, frame, margin and
 * error level -- never the content of a code, so no Wi-Fi password, contact
 * card or event reaches the database: the generator stays stateless about
 * what it encodes. The state is flat and keyed by the design fields'
 * `data-ffc-qr-design` names, so the page script can apply it field by field.
 *
 * The meta key carries the `ffc_` prefix, which is what `uninstall.php`'s
 * user-meta sweep removes by, so it needs no entry of its own there.
 */
final class QrGeneratorDesignMemory {

	/** User meta key. */
	public const META_KEY = 'ffc_qr_generator_design';

	/** Error-correction levels. */
	private const LEVELS = array( 'L', 'M', 'Q', 'H' );

	/**
	 * The state the generator opens with: the user's remembered design, or
	 * the global one.
	 *
	 * @param int $user_id User.
	 * @return array<string, string|int|bool>
	 */
	public static function state( int $user_id ): array {
		$stored = $user_id > 0 ? get_user_meta( $user_id, self::META_KEY, true ) : '';
		return is_array( $stored ) ? self::normalize( $stored ) : self::global_state();
	}

	/**
	 * The global design from Settings → QR Code, in the state shape.
	 *
	 * @return array<string, string|int|bool>
	 */
	public static function global_state(): array {
		$design = QrDesign::from_settings()->to_array();
		$form   = QrDesign::form_state();

		return self::normalize(
			array(
				'qr_design_dots'            => $design['dots'],
				'qr_design_eye_frame'       => $design['eye_frame'],
				'qr_design_eye_ball'        => $design['eye_ball'],
				'qr_design_color'           => $design['color'],
				'qr_design_background'      => $design['background'],
				'qr_design_eye_frame_color' => $design['eye_frame_color'],
				'qr_design_eye_ball_color'  => $design['eye_ball_color'],
				'qr_design_gradient'        => $form['gradient'],
				'qr_design_color_end'       => $form['color_end'],
				'qr_design_frame'           => $design['frame'],
				'qr_design_frame_text'      => $design['frame_text'],
				'qr_design_frame_color'     => $design['frame_color'],
				'qr_design_logo_id'         => $form['logo_id'],
				'margin'                    => $form['margin'],
				'error_level'               => $form['error_level'],
			)
		);
	}

	/**
	 * Remember a design for a user.
	 *
	 * @param int                  $user_id User.
	 * @param array<string, mixed> $state   Loose state, normalised here.
	 */
	public static function remember( int $user_id, array $state ): void {
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::META_KEY, self::normalize( $state ) );
		}
	}

	/**
	 * Forget a user's design, so the generator opens with the global one again.
	 *
	 * @param int $user_id User.
	 */
	public static function forget( int $user_id ): void {
		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, self::META_KEY );
		}
	}

	/**
	 * The drawable design of a state, logo included.
	 *
	 * @param array<string, string|int|bool> $state Normalised state.
	 * @return QrDesign
	 */
	public static function design( array $state ): QrDesign {
		return new QrDesign(
			array(
				'dots'            => $state['qr_design_dots'],
				'eye_frame'       => $state['qr_design_eye_frame'],
				'eye_ball'        => $state['qr_design_eye_ball'],
				'color'           => $state['qr_design_color'],
				'background'      => $state['qr_design_background'],
				'eye_frame_color' => $state['qr_design_eye_frame_color'],
				'eye_ball_color'  => $state['qr_design_eye_ball_color'],
				'gradient'        => $state['qr_design_gradient'],
				'color_end'       => $state['qr_design_color_end'],
				'logo'            => QrLogo::data_uri( (int) $state['qr_design_logo_id'] ),
				'frame'           => $state['qr_design_frame'],
				'frame_text'      => $state['qr_design_frame_text'],
				'frame_color'     => $state['qr_design_frame_color'],
			)
		);
	}

	/**
	 * Every key present, every value inside its allowlist.
	 *
	 * The design half goes through `QrDesign`, the one normaliser of shapes
	 * and colours. The gradient's end colour is kept apart from it, because
	 * `QrDesign` drops that colour while the gradient is off and the form
	 * keeps it so switching the gradient back on restores it.
	 *
	 * @param array<mixed> $state Loose state.
	 * @return array<string, string|int|bool>
	 */
	private static function normalize( array $state ): array {
		$get    = static fn( string $key ) => $state[ $key ] ?? null;
		$design = ( new QrDesign(
			array(
				'dots'            => $get( 'qr_design_dots' ),
				'eye_frame'       => $get( 'qr_design_eye_frame' ),
				'eye_ball'        => $get( 'qr_design_eye_ball' ),
				'color'           => $get( 'qr_design_color' ),
				'background'      => $get( 'qr_design_background' ),
				'eye_frame_color' => $get( 'qr_design_eye_frame_color' ),
				'eye_ball_color'  => $get( 'qr_design_eye_ball_color' ),
				'frame'           => $get( 'qr_design_frame' ),
				'frame_text'      => $get( 'qr_design_frame_text' ),
				'frame_color'     => $get( 'qr_design_frame_color' ),
			)
		) )->to_array();

		$level  = strtoupper( is_scalar( $get( 'error_level' ) ) ? (string) $get( 'error_level' ) : '' );
		$margin = is_numeric( $get( 'margin' ) ) ? (int) $get( 'margin' ) : 2;
		$logo   = is_numeric( $get( 'qr_design_logo_id' ) ) ? (int) $get( 'qr_design_logo_id' ) : 0;

		return array(
			'qr_design_dots'            => (string) $design['dots'],
			'qr_design_eye_frame'       => (string) $design['eye_frame'],
			'qr_design_eye_ball'        => (string) $design['eye_ball'],
			'qr_design_color'           => (string) $design['color'],
			'qr_design_background'      => (string) $design['background'],
			'qr_design_eye_frame_color' => (string) $design['eye_frame_color'],
			'qr_design_eye_ball_color'  => (string) $design['eye_ball_color'],
			'qr_design_gradient'        => ! empty( $get( 'qr_design_gradient' ) ),
			'qr_design_color_end'       => QrDesign::hex( $get( 'qr_design_color_end' ), '#2271b1' ),
			'qr_design_frame'           => (string) $design['frame'],
			'qr_design_frame_text'      => (string) $design['frame_text'],
			'qr_design_frame_color'     => (string) $design['frame_color'],
			'qr_design_logo_id'         => max( 0, $logo ),
			'margin'                    => max( 0, min( 10, $margin ) ),
			'error_level'               => in_array( $level, self::LEVELS, true ) ? $level : 'M',
		);
	}
}
