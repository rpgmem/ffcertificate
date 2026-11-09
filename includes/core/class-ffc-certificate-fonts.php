<?php
/**
 * CertificateFonts
 *
 * The fonts a certificate can use: the families installed through the
 * WordPress Font Library (#2569).
 *
 * @package FreeFormCertificate\Core
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the installed font families and prints their `@font-face` rules on
 * the pages that render a certificate.
 *
 * A certificate is drawn in the participant's browser, and a template cannot
 * load a font itself: the sanitizer strips `<style>` and `<link>`. So until
 * this class, the typeface was whatever the device had installed. The Font
 * Library stores what an administrator installs as `wp_font_family` and
 * `wp_font_face` posts, with the files under `uploads/fonts`, whatever the
 * theme. Core prints only the families activated in the global styles, and
 * only on the front end, so the PDF pages and the admin previews read the
 * posts directly instead.
 *
 * No cache sits in front of the query: the posts are few, WordPress caches
 * them for the request, and the rules are built at most once per request.
 */
final class CertificateFonts {

	/**
	 * Handle of the stylesheet the rules are attached to. It has no file.
	 */
	public const STYLE_HANDLE = 'ffc-certificate-fonts';

	/**
	 * More families than any site installs; a bound, not a limit anyone meets.
	 */
	private const MAX_FAMILIES = 200;

	/**
	 * The `format()` hint for each font file extension.
	 */
	private const FORMATS = array(
		'woff2' => 'woff2',
		'woff'  => 'woff',
		'ttf'   => 'truetype',
		'otf'   => 'opentype',
	);

	/**
	 * Fonts a template may name first without installing them: the ones that
	 * come with Windows or macOS, which every template already relied on
	 * before the Font Library, and the CSS generic families. Lowercase.
	 *
	 * A template that names one of these first is drawn in the device's own
	 * copy, as it always was; only a name outside this list and outside the
	 * installed families is reported as missing.
	 */
	private const SYSTEM_FONTS = array(
		'arial',
		'helvetica',
		'helvetica neue',
		'times new roman',
		'times',
		'georgia',
		'courier new',
		'courier',
		'verdana',
		'tahoma',
		'trebuchet ms',
		'segoe ui',
		'calibri',
		'cambria',
		'palatino',
		'palatino linotype',
		'book antiqua',
		'garamond',
		'impact',
		'comic sans ms',
		'lucida console',
		'lucida sans unicode',
		'consolas',
		'monaco',
		'menlo',
		'-apple-system',
		'blinkmacsystemfont',
		'serif',
		'sans-serif',
		'monospace',
		'cursive',
		'fantasy',
		'system-ui',
		'ui-serif',
		'ui-sans-serif',
		'ui-monospace',
		'ui-rounded',
		'emoji',
		'math',
		'fangsong',
		'inherit',
		'initial',
		'unset',
		'revert',
	);

	/**
	 * Families read in this request, so the query runs once.
	 *
	 * @var list<array{name: string, css: string, faces: list<array{family: string, style: string, weight: string, src: list<string>, unicode_range: string}>}>|null
	 */
	private static ?array $families = null;

	/**
	 * The installed families with at least one usable face, by name.
	 *
	 * `name` is what the Font Library shows; `css` is the `font-family` value
	 * a template writes, the stack WordPress stored for the family.
	 *
	 * @return list<array{name: string, css: string, faces: list<array{family: string, style: string, weight: string, src: list<string>, unicode_range: string}>}>
	 */
	public static function installed(): array {
		if ( null !== self::$families ) {
			return self::$families;
		}

		self::$families = array();

		if ( ! function_exists( 'get_posts' ) || ! post_type_exists( 'wp_font_family' ) ) {
			return self::$families;
		}

		$family_posts = get_posts(
			array(
				'post_type'        => 'wp_font_family',
				'post_status'      => 'publish',
				'numberposts'      => self::MAX_FAMILIES,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		if ( empty( $family_posts ) ) {
			return self::$families;
		}

		$ids = array_map( static fn( $post ): int => (int) $post->ID, $family_posts );

		$face_posts = get_posts(
			array(
				'post_type'        => 'wp_font_face',
				'post_status'      => 'publish',
				'post_parent__in'  => $ids,
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);

		$faces_by_family = array();
		foreach ( $face_posts as $face_post ) {
			$face = self::face( (string) $face_post->post_content );
			if ( null !== $face ) {
				$faces_by_family[ (int) $face_post->post_parent ][] = $face;
			}
		}

		foreach ( $family_posts as $family_post ) {
			$faces = $faces_by_family[ (int) $family_post->ID ] ?? array();
			if ( array() === $faces ) {
				continue;
			}
			$settings = json_decode( (string) $family_post->post_content, true );
			$css      = is_array( $settings ) && is_string( $settings['fontFamily'] ?? null ) ? self::css_value( $settings['fontFamily'] ) : '';

			self::$families[] = array(
				'name'  => (string) $family_post->post_title,
				'css'   => '' !== $css ? $css : self::quote( $faces[0]['family'] ),
				'faces' => $faces,
			);
		}

		return self::$families;
	}

	/**
	 * The `@font-face` rules of every installed family, or '' when none is.
	 *
	 * `font-display: block` on purpose: the capture waits for the fonts, and
	 * a fallback drawn while one loads is exactly the mismatch this exists
	 * to remove.
	 *
	 * @return string CSS.
	 */
	public static function css(): string {
		$rules = array();

		foreach ( self::installed() as $family ) {
			foreach ( $family['faces'] as $face ) {
				$sources = array();
				foreach ( $face['src'] as $url ) {
					$format    = self::FORMATS[ strtolower( (string) pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ) ] ?? '';
					$sources[] = 'url("' . $url . '")' . ( '' !== $format ? ' format("' . $format . '")' : '' );
				}

				$rule = '@font-face{font-family:' . self::quote( $face['family'] )
					. ';font-style:' . $face['style']
					. ';font-weight:' . $face['weight']
					. ';font-display:block'
					. ';src:' . implode( ',', $sources );
				if ( '' !== $face['unicode_range'] ) {
					$rule .= ';unicode-range:' . $face['unicode_range'];
				}
				$rules[] = $rule . ';}';
			}
		}

		return implode( "\n", $rules );
	}

	/**
	 * Enqueue the rules on the current page, when any family is installed.
	 *
	 * Called wherever a script that renders a certificate is enqueued, so a
	 * page that draws a PDF gets the fonts its template names.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		$css = self::css();
		if ( '' === $css || wp_style_is( self::STYLE_HANDLE, 'enqueued' ) ) {
			return;
		}

		// The handle is written out rather than read from STYLE_HANDLE: the
		// stylesheet guards read a declaration off its literal handle.
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- No file: the handle only carries the inline rules below.
		wp_register_style( 'ffc-certificate-fonts', false, array(), null );
		wp_enqueue_style( 'ffc-certificate-fonts' );
		wp_add_inline_style( self::STYLE_HANDLE, $css );
	}

	/**
	 * Where this site installs a font, for the recommendation on the
	 * certificate screens.
	 *
	 * `library` when Appearance → Fonts exists (WordPress 7.0+, any theme),
	 * `site_editor` on an older WordPress with a block theme, `update` when
	 * neither is available: a classic theme before 7.0 has no screen for it.
	 *
	 * @return string One of 'library', 'site_editor', 'update'.
	 */
	public static function install_route(): string {
		if ( file_exists( ABSPATH . 'wp-admin/font-library.php' ) ) {
			return 'library';
		}

		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ? 'site_editor' : 'update';
	}

	/**
	 * The recommendation the certificate screens show, as callout markup.
	 *
	 * While no family is installed it stays, with no dismiss, and names the
	 * route this site has; once one is, it lists what a template can name.
	 *
	 * @return string Escaped HTML.
	 */
	public static function notice_html(): string {
		$families = self::installed();

		if ( array() !== $families ) {
			$items = '';
			foreach ( $families as $family ) {
				$items .= '<li><strong>' . esc_html( $family['name'] ) . '</strong>: <code>font-family: ' . esc_html( $family['css'] ) . '</code></li>';
			}

			return AdminNotice::callout_markup(
				'info',
				'<p>' . esc_html__( 'Fonts installed on this site, for certificate templates. Name one in a style, and every device draws the certificate in it:', 'ffcertificate' ) . '</p>'
					. '<ul>' . $items . '</ul>',
				array(
					'icon'    => 'text',
					'classes' => array( 'ffc-certificate-fonts-notice', 'inline' ),
				)
			);
		}

		$intro = esc_html__( 'No font is installed for certificates, so each one is drawn in whatever typeface the participant’s device has, and can look different on another device.', 'ffcertificate' );

		$how = self::install_sentence();

		return AdminNotice::callout_markup(
			'warning',
			'<p>' . $intro . '</p><p>' . $how . '</p>',
			array(
				'icon'    => 'text',
				'classes' => array( 'ffc-certificate-fonts-notice', 'inline' ),
			)
		);
	}

	/**
	 * The fonts a template asks for: the first name of every `font-family`
	 * in its inline styles, and every `<font face>`, once each.
	 *
	 * Only the first name counts. The rest of a stack is the fallback, and
	 * a fallback the device lacks is the point of having one.
	 *
	 * @param string $html Template HTML.
	 * @return list<string> Names as the template spells them, in order of appearance.
	 */
	public static function requested( string $html ): array {
		$stacks = array();

		if ( preg_match_all( '/\\bstyle\\s*=\\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $styles, PREG_SET_ORDER ) ) {
			foreach ( $styles as $style ) {
				$css = html_entity_decode( ( $style[1] ?? '' ) . ( $style[2] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( preg_match_all( '/(?:^|;)\\s*font-family\\s*:\\s*([^;]+)/i', $css, $declarations ) ) {
					foreach ( $declarations[1] as $declaration ) {
						$stacks[] = $declaration;
					}
				}
			}
		}

		// `\s` rather than `\b` before `face`: an attribute is always preceded by whitespace, and the emoji guard reads a backslash followed by hex digits as a CSS escape.
		if ( preg_match_all( '/<font\\b[^>]*\\sface\\s*=\\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $faces, PREG_SET_ORDER ) ) {
			foreach ( $faces as $face ) {
				$stacks[] = html_entity_decode( ( $face[1] ?? '' ) . ( $face[2] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}

		$names = array();
		foreach ( $stacks as $stack ) {
			$name = trim( trim( explode( ',', $stack )[0] ), " \t\"'" );
			$name = trim( (string) preg_replace( '/\\s*!important$/i', '', $name ), " \t\"'" );
			if ( '' !== $name && ! isset( $names[ strtolower( $name ) ] ) ) {
				$names[ strtolower( $name ) ] = $name;
			}
		}

		return array_values( $names );
	}

	/**
	 * The fonts a template asks for that this site cannot supply: not
	 * installed through the Font Library and not a system font.
	 *
	 * @param string $html Template HTML.
	 * @return list<string> Names as the template spells them.
	 */
	public static function missing( string $html ): array {
		$requested = self::requested( $html );
		if ( array() === $requested ) {
			return array();
		}

		$available = array_flip( self::SYSTEM_FONTS );
		foreach ( self::installed() as $family ) {
			$first = self::face_name( $family['css'] );

			$available[ strtolower( $family['name'] ) ] = true;
			if ( '' !== $first ) {
				$available[ strtolower( $first ) ] = true;
			}
		}

		return array_values(
			array_filter(
				$requested,
				static fn( string $name ): bool => ! isset( $available[ strtolower( $name ) ] )
			)
		);
	}

	/**
	 * A warning naming the fonts a template asks for and this site lacks,
	 * as callout markup, or '' when nothing is missing.
	 *
	 * @param string $html Template HTML.
	 * @return string Escaped HTML.
	 */
	public static function missing_notice_html( string $html ): string {
		$missing = self::missing( $html );
		if ( array() === $missing ) {
			return '';
		}

		$names = implode(
			', ',
			array_map( static fn( string $name ): string => '<strong>' . esc_html( $name ) . '</strong>', $missing )
		);

		return AdminNotice::callout_markup(
			'warning',
			'<p>' . sprintf(
				/* translators: %s: comma-separated font names */
				esc_html( _n( 'This template asks for %s, which is not installed on this site. Until it is, each device draws the text in the next font of its list, so the document can look different from one device to another.', 'This template asks for %s, which are not installed on this site. Until they are, each device draws the text in the next font of its list, so the document can look different from one device to another.', count( $missing ), 'ffcertificate' ) ),
				$names
			) . '</p><p>' . self::install_sentence() . '</p>',
			array(
				'icon'    => 'text',
				'classes' => array( 'ffc-template-fonts-missing', 'inline' ),
			)
		);
	}

	/**
	 * Print {@see self::missing_notice_html()}.
	 *
	 * @param string $html Template HTML.
	 * @return void
	 */
	public static function render_missing_notice( string $html ): void {
		$notice = self::missing_notice_html( $html );
		if ( '' !== $notice ) {
			echo wp_kses_post( $notice );
		}
	}

	/**
	 * How this site installs a font, as one escaped sentence, with a link
	 * for a user who can reach the screen.
	 *
	 * @return string Escaped HTML.
	 */
	private static function install_sentence(): string {
		$can   = current_user_can( 'edit_theme_options' );
		$route = self::install_route();
		if ( 'library' === $route ) {
			$how = $can
				? sprintf(
					/* translators: %s: link to the WordPress Fonts screen */
					esc_html__( 'Install a font in %s, then name it in the template.', 'ffcertificate' ),
					'<a href="' . esc_url( admin_url( 'font-library.php' ) ) . '">' . esc_html__( 'Appearance → Fonts', 'ffcertificate' ) . '</a>'
				)
				: esc_html__( 'An administrator can install a font in Appearance → Fonts.', 'ffcertificate' );
		} elseif ( 'site_editor' === $route ) {
			$how = $can
				? sprintf(
					/* translators: %s: link to the WordPress Site Editor */
					esc_html__( 'Install a font in the %s, under Styles → Typography, then name it in the template.', 'ffcertificate' ),
					'<a href="' . esc_url( admin_url( 'site-editor.php' ) ) . '">' . esc_html__( 'Site Editor', 'ffcertificate' ) . '</a>'
				)
				: esc_html__( 'An administrator can install a font in the Site Editor, under Styles → Typography.', 'ffcertificate' );
		} else {
			$how = esc_html__( 'This WordPress has no font screen for a classic theme. WordPress 7.0 adds Appearance → Fonts for every theme; update to install fonts there.', 'ffcertificate' );
		}

		return $how;
	}

	/**
	 * Print {@see self::notice_html()}.
	 *
	 * @return void
	 */
	public static function render_notice(): void {
		echo wp_kses_post( self::notice_html() );
	}

	/**
	 * Forget what this request read. For tests and for a request that
	 * installs a font and renders afterwards.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$families = null;
	}

	/**
	 * One stored face, validated, or null when it cannot be printed safely.
	 *
	 * @param string $content The face post's JSON settings.
	 * @return array{family: string, style: string, weight: string, src: list<string>, unicode_range: string}|null
	 */
	private static function face( string $content ): ?array {
		$settings = json_decode( $content, true );
		if ( ! is_array( $settings ) ) {
			return null;
		}

		$family = self::face_name( is_string( $settings['fontFamily'] ?? null ) ? $settings['fontFamily'] : '' );
		if ( '' === $family ) {
			return null;
		}

		$src = $settings['src'] ?? array();
		$src = is_string( $src ) ? array( $src ) : ( is_array( $src ) ? $src : array() );

		$urls = array();
		foreach ( $src as $url ) {
			$url = is_string( $url ) ? esc_url_raw( trim( $url ), array( 'http', 'https' ) ) : '';
			// A quote, a parenthesis or a backslash would end url("…") early.
			if ( '' !== $url && 1 !== preg_match( '/["\'()\\\\\s]/', $url ) ) {
				$urls[] = $url;
			}
		}
		if ( array() === $urls ) {
			return null;
		}

		$font_style = is_string( $settings['fontStyle'] ?? null ) ? strtolower( trim( $settings['fontStyle'] ) ) : 'normal';
		if ( ! in_array( $font_style, array( 'normal', 'italic', 'oblique' ), true ) ) {
			$font_style = 'normal';
		}

		$weight = is_scalar( $settings['fontWeight'] ?? null ) ? strtolower( trim( (string) $settings['fontWeight'] ) ) : '400';
		if ( 1 !== preg_match( '/^(normal|bold|[1-9]00(\s+[1-9]00)?)$/', $weight ) ) {
			$weight = '400';
		}

		$range = is_string( $settings['unicodeRange'] ?? null ) ? trim( $settings['unicodeRange'] ) : '';
		if ( 1 !== preg_match( '/^U\+[0-9A-Fa-f?]{1,6}(-[0-9A-Fa-f]{1,6})?(\s*,\s*U\+[0-9A-Fa-f?]{1,6}(-[0-9A-Fa-f]{1,6})?)*$/', $range ) ) {
			$range = '';
		}

		return array(
			'family'        => $family,
			'style'         => $font_style,
			'weight'        => $weight,
			'src'           => $urls,
			'unicode_range' => $range,
		);
	}

	/**
	 * A family name as `@font-face` declares it: the first name of a stack,
	 * unquoted, letters, digits, spaces, hyphens and underscores only.
	 *
	 * @param string $value A stored `fontFamily`.
	 * @return string The name, or '' when it holds anything else.
	 */
	private static function face_name( string $value ): string {
		$first = trim( explode( ',', $value )[0] );
		$first = trim( $first, " \t\"'" );

		return 1 === preg_match( '/^[\p{L}\p{N} _-]{1,100}$/u', $first ) ? $first : '';
	}

	/**
	 * A stored `fontFamily` stack, rebuilt from validated names.
	 *
	 * @param string $value The stack, e.g. `"Great Vibes", cursive`.
	 * @return string The same stack with each name checked, or '' when one fails.
	 */
	private static function css_value( string $value ): string {
		$parts = array();
		foreach ( explode( ',', $value ) as $part ) {
			$name = self::face_name( $part );
			if ( '' === $name ) {
				return '';
			}
			$parts[] = in_array( strtolower( $name ), array( 'serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui' ), true ) ? strtolower( $name ) : self::quote( $name );
		}

		return implode( ', ', $parts );
	}

	/**
	 * A validated family name in double quotes.
	 *
	 * @param string $name Name from {@see self::face_name()}.
	 * @return string
	 */
	private static function quote( string $name ): string {
		return '"' . $name . '"';
	}
}
