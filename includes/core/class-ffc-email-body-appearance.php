<?php
/**
 * EmailBodyAppearance
 *
 * @package FreeFormCertificate\Core
 * @since   6.36.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How the body cell of one message is drawn: a background image, the colour
 * shown when the image does not load, the text colour, and where the text sits
 * over the image (#1660).
 *
 * The header and the footer stay the Email Model's (one chrome, #662); this
 * changes only the body cell between them, so the image runs edge to edge
 * inside the same width, padding and rounded corners. Every value left empty
 * falls back to the Email Model, which is why an empty appearance changes
 * nothing in the rendered e-mail.
 *
 * The fallback colour is required with an image because it is what Outlook
 * on Windows and every reader that blocks images will show; text colour
 * contrast is measured against it, the one background the plugin can see.
 */
final class EmailBodyAppearance {

	/**
	 * Where the text sits: across the whole body, or in a column on one side
	 * with the image showing on the other.
	 */
	public const POSITIONS = array( 'full', 'left', 'right' );

	/**
	 * Share of the body width the text column takes, in percent.
	 */
	public const WIDTHS = array( 50, 60, 70 );

	/**
	 * Text column width when none is chosen.
	 */
	public const DEFAULT_WIDTH = 60;

	/**
	 * Constructor.
	 *
	 * @param int    $image_id       Media Library attachment id, 0 for none.
	 * @param string $fallback_color Hex colour, '' for the Email Model's body colour.
	 * @param string $text_color     Hex colour, '' for the Email Model's text colour.
	 * @param string $position       One of POSITIONS.
	 * @param int    $text_width     One of WIDTHS.
	 */
	private function __construct(
		public readonly int $image_id,
		public readonly string $fallback_color,
		public readonly string $text_color,
		public readonly string $position,
		public readonly int $text_width,
	) {}

	/**
	 * The appearance that changes nothing.
	 *
	 * @return self
	 */
	public static function none(): self {
		return new self( 0, '', '', 'full', self::DEFAULT_WIDTH );
	}

	/**
	 * Build from a stored JSON string or a submitted array, validating it.
	 *
	 * An empty value is the default appearance; anything the editor never
	 * offers is refused rather than corrected, so a stored row always holds
	 * what the screen can show back.
	 *
	 * @param mixed $raw JSON string, array, or null.
	 * @return self|\WP_Error
	 */
	public static function from_array( $raw ) {
		if ( is_string( $raw ) ) {
			$decoded = '' === trim( $raw ) ? array() : json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return self::none();
		}

		$image_id = isset( $raw['image_id'] ) && is_numeric( $raw['image_id'] ) ? max( 0, (int) $raw['image_id'] ) : 0;
		$fallback = self::color( $raw['fallback_color'] ?? '' );
		$text     = self::color( $raw['text_color'] ?? '' );
		$position = is_string( $raw['position'] ?? null ) && '' !== $raw['position'] ? $raw['position'] : 'full';
		$width    = isset( $raw['text_width'] ) && is_numeric( $raw['text_width'] ) ? (int) $raw['text_width'] : self::DEFAULT_WIDTH;

		if ( null === $fallback || null === $text ) {
			return new \WP_Error( 'ffc_appearance_color', __( 'Colours must be written as #RRGGBB.', 'ffcertificate' ) );
		}
		if ( ! in_array( $position, self::POSITIONS, true ) ) {
			return new \WP_Error( 'ffc_appearance_position', __( 'Unknown text position.', 'ffcertificate' ) );
		}
		if ( ! in_array( $width, self::WIDTHS, true ) ) {
			return new \WP_Error( 'ffc_appearance_width', __( 'Unknown text area width.', 'ffcertificate' ) );
		}
		if ( $image_id > 0 && ! wp_attachment_is_image( $image_id ) ) {
			return new \WP_Error( 'ffc_appearance_image', __( 'The background must be an image from the Media Library.', 'ffcertificate' ) );
		}
		if ( $image_id > 0 && '' === $fallback ) {
			return new \WP_Error( 'ffc_appearance_fallback', __( 'Choose a fallback colour: it is what readers that do not load the image will see.', 'ffcertificate' ) );
		}

		return new self( $image_id, $fallback, $text, $position, $width );
	}

	/**
	 * Whether this changes nothing in the e-mail.
	 *
	 * @return bool
	 */
	public function is_default(): bool {
		return 0 === $this->image_id && '' === $this->fallback_color && '' === $this->text_color && 'full' === $this->position;
	}

	/**
	 * The stored form.
	 *
	 * @return array{image_id: int, fallback_color: string, text_color: string, position: string, text_width: int}
	 */
	public function to_array(): array {
		return array(
			'image_id'       => $this->image_id,
			'fallback_color' => $this->fallback_color,
			'text_color'     => $this->text_color,
			'position'       => $this->position,
			'text_width'     => $this->text_width,
		);
	}

	/**
	 * What the e-mail layout reads, under `body_appearance`; empty for the
	 * default appearance, so the layout renders exactly as before.
	 *
	 * The image URL is resolved here, at send time: an attachment deleted
	 * since the rule was saved leaves the fallback colour, not a broken image.
	 *
	 * @return array<string, array{image_url: string, min_height: int, fallback_color: string, text_color: string, position: string, text_width: int}>
	 */
	public function document_args(): array {
		if ( $this->is_default() ) {
			return array();
		}

		$url = $this->image_id > 0 ? wp_get_attachment_image_url( $this->image_id, 'full' ) : false;

		return array(
			'body_appearance' => array(
				'image_url'      => is_string( $url ) ? $url : '',
				'min_height'     => is_string( $url ) ? $this->image_height() : 0,
				'fallback_color' => $this->fallback_color,
				'text_color'     => $this->text_color,
				'position'       => $this->position,
				'text_width'     => $this->text_width,
			),
		);
	}

	/**
	 * Height the image takes at the Email Model width, so a short message
	 * still shows the whole artwork instead of a strip cut from it; 0 when
	 * the attachment carries no dimensions.
	 *
	 * @return int
	 */
	private function image_height(): int {
		$meta = wp_get_attachment_metadata( $this->image_id );
		if ( ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['height'] ) ) {
			return 0;
		}
		$width = (int) EmailTemplateOptions::all()['body_max_width'];

		return (int) round( (int) $meta['height'] * $width / (int) $meta['width'] );
	}

	/**
	 * Contrast between the text and the fallback colour, each defaulting to
	 * the Email Model's, or null when either cannot be read.
	 *
	 * @return float|null
	 */
	public function contrast(): ?float {
		$model = EmailTemplateOptions::all();
		$back  = '' !== $this->fallback_color ? $this->fallback_color : (string) $model['body_bg'];
		$text  = '' !== $this->text_color ? $this->text_color : (string) $model['body_text_color'];

		return ContrastColor::ratio( $text, $back );
	}

	/**
	 * A hex colour, '' for none, or null for anything else.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function color( $value ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}
		$hex = sanitize_hex_color( trim( $value ) );
		return is_string( $hex ) && '' !== $hex ? strtolower( $hex ) : null;
	}
}
