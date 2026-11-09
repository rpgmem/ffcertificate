<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\CertificateFonts;

/**
 * The fonts a certificate can use (#2569): the Font Library families read
 * from their posts, the `@font-face` rules built from them, and the
 * recommendation the certificate screens show.
 *
 * @covers \FreeFormCertificate\Core\CertificateFonts
 */
class CertificateFontsTest extends TestCase {

	/**
	 * Family posts get_posts() answers.
	 *
	 * @var list<object>
	 */
	private array $family_posts = array();

	/**
	 * Face posts get_posts() answers.
	 *
	 * @var list<object>
	 */
	private array $face_posts = array();

	/**
	 * Arguments of every get_posts() call.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		CertificateFonts::reset();

		$this->family_posts = array();
		$this->face_posts   = array();
		$this->queries      = array();

		Functions\when( 'post_type_exists' )->justReturn( true );
		Functions\when( 'get_posts' )->alias(
			function ( array $args ): array {
				$this->queries[] = $args;
				return 'wp_font_family' === $args['post_type'] ? $this->family_posts : $this->face_posts;
			}
		);
		Functions\when( 'esc_url_raw' )->alias( static fn( $url ) => is_string( $url ) && 1 === preg_match( '#^https?://#', $url ) ? $url : '' );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		Functions\when( '_n' )->alias( static fn( $single, $plural, $number ) => 1 === $number ? $single : $plural );
	}

	protected function tearDown(): void {
		CertificateFonts::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A family post.
	 *
	 * @param int    $id    Post ID.
	 * @param string $name  Title.
	 * @param string $stack Stored fontFamily.
	 */
	private function family( int $id, string $name, string $stack ): void {
		$this->family_posts[] = (object) array(
			'ID'           => $id,
			'post_title'   => $name,
			'post_content' => (string) json_encode( array( 'fontFamily' => $stack ) ),
		);
	}

	/**
	 * A face post.
	 *
	 * @param int                  $parent   Family post ID.
	 * @param array<string, mixed> $settings Face settings.
	 */
	private function face( int $parent, array $settings ): void {
		$this->face_posts[] = (object) array(
			'ID'           => 1000 + count( $this->face_posts ),
			'post_parent'  => $parent,
			'post_content' => (string) json_encode( $settings ),
		);
	}

	public function test_no_font_library_means_no_families_and_no_query(): void {
		Functions\when( 'post_type_exists' )->justReturn( false );

		$this->assertSame( array(), CertificateFonts::installed() );
		$this->assertSame( '', CertificateFonts::css() );
		$this->assertSame( array(), $this->queries );
	}

	public function test_an_installed_family_is_read_with_its_faces(): void {
		$this->family( 7, 'Great Vibes', '"Great Vibes", cursive' );
		$this->face(
			7,
			array(
				'fontFamily' => 'Great Vibes',
				'fontStyle'  => 'normal',
				'fontWeight' => '400',
				'src'        => 'https://example.test/wp-content/uploads/fonts/great-vibes.woff2',
			)
		);

		$families = CertificateFonts::installed();

		$this->assertCount( 1, $families );
		$this->assertSame( 'Great Vibes', $families[0]['name'] );
		$this->assertSame( '"Great Vibes", cursive', $families[0]['css'] );
		$this->assertSame( array( 'https://example.test/wp-content/uploads/fonts/great-vibes.woff2' ), $families[0]['faces'][0]['src'] );
		$this->assertSame( array( 7 ), $this->queries[1]['post_parent__in'], 'The faces are read for the families found, in one query.' );
	}

	public function test_the_query_runs_once_per_request(): void {
		$this->family( 7, 'Lora', 'Lora, serif' );
		$this->face( 7, array( 'fontFamily' => 'Lora', 'src' => 'https://example.test/lora.woff2' ) );

		CertificateFonts::installed();
		CertificateFonts::css();
		CertificateFonts::notice_html();

		$this->assertCount( 2, $this->queries );
	}

	public function test_a_family_without_a_usable_face_is_left_out(): void {
		$this->family( 1, 'Empty', 'Empty' );
		$this->family( 2, 'Broken', 'Broken' );
		$this->face( 2, array( 'fontFamily' => 'Broken', 'src' => 'javascript:alert(1)' ) );
		$this->face_posts[] = (object) array( 'ID' => 9, 'post_parent' => 2, 'post_content' => 'not json' );

		$this->assertSame( array(), CertificateFonts::installed() );
	}

	public function test_the_rules_carry_style_weight_format_range_and_block_display(): void {
		$this->family( 3, 'Lora', 'Lora, serif' );
		$this->face(
			3,
			array(
				'fontFamily'   => 'Lora',
				'fontStyle'    => 'italic',
				'fontWeight'   => '400 700',
				'src'          => array( 'https://example.test/lora.woff2', 'https://example.test/lora.ttf' ),
				'unicodeRange' => 'U+0000-00FF, U+0131',
			)
		);

		$this->assertSame(
			'@font-face{font-family:"Lora";font-style:italic;font-weight:400 700;font-display:block;'
				. 'src:url("https://example.test/lora.woff2") format("woff2"),url("https://example.test/lora.ttf") format("truetype");'
				. 'unicode-range:U+0000-00FF, U+0131;}',
			CertificateFonts::css()
		);
	}

	public function test_unknown_values_fall_back_rather_than_reach_the_css(): void {
		$this->family( 4, 'Lora', 'Lora' );
		$this->face(
			4,
			array(
				'fontFamily'   => 'Lora',
				'fontStyle'    => 'bold;}body{color:red',
				'fontWeight'   => '450;}',
				'src'          => 'https://example.test/lora.woff2',
				'unicodeRange' => 'U+00;}x{',
			)
		);

		$css = CertificateFonts::css();

		$this->assertStringContainsString( 'font-style:normal;font-weight:400;', $css );
		$this->assertStringNotContainsString( 'unicode-range', $css );
		$this->assertStringNotContainsString( 'body', $css );
		$this->assertSame( 1, substr_count( $css, '}' ), 'One rule, closed once.' );
	}

	public function test_a_url_that_would_break_out_of_url_is_refused(): void {
		$this->family( 5, 'Lora', 'Lora' );
		$this->face( 5, array( 'fontFamily' => 'Lora', 'src' => array( 'https://example.test/a.woff2");}body{x:y', 'https://example.test/ok.woff2' ) ) );

		$css = CertificateFonts::css();

		$this->assertStringContainsString( 'url("https://example.test/ok.woff2")', $css );
		$this->assertStringNotContainsString( 'body{', $css );
	}

	public function test_a_family_name_with_markup_is_refused(): void {
		$this->family( 6, 'Evil', 'Evil' );
		$this->face( 6, array( 'fontFamily' => 'Evil"}</style><script>', 'src' => 'https://example.test/e.woff2' ) );

		$this->assertSame( array(), CertificateFonts::installed() );
	}

	public function test_a_stack_that_fails_validation_falls_back_to_the_face_name(): void {
		$this->family( 8, 'Lora', 'Lora, "bad;name"' );
		$this->face( 8, array( 'fontFamily' => 'Lora', 'src' => 'https://example.test/lora.woff2' ) );

		$this->assertSame( '"Lora"', CertificateFonts::installed()[0]['css'] );
	}

	public function test_generic_families_stay_unquoted_in_the_stack(): void {
		$this->family( 9, 'Inter', "'Inter', Sans-Serif" );
		$this->face( 9, array( 'fontFamily' => 'Inter', 'src' => 'https://example.test/inter.woff2' ) );

		$this->assertSame( '"Inter", sans-serif', CertificateFonts::installed()[0]['css'] );
	}

	public function test_enqueue_attaches_the_rules_to_a_file_less_handle_once(): void {
		$this->family( 3, 'Lora', 'Lora' );
		$this->face( 3, array( 'fontFamily' => 'Lora', 'src' => 'https://example.test/lora.woff2' ) );

		$enqueued = false;
		$inline   = array();
		Functions\when( 'wp_style_is' )->alias( static function () use ( &$enqueued ) {
			return $enqueued;
		} );
		Functions\when( 'wp_register_style' )->alias( static function ( $handle, $src ) {
			self::assertSame( CertificateFonts::STYLE_HANDLE, $handle );
			self::assertFalse( $src );
			return true;
		} );
		Functions\when( 'wp_enqueue_style' )->alias( static function () use ( &$enqueued ) {
			$enqueued = true;
		} );
		Functions\when( 'wp_add_inline_style' )->alias( static function ( $handle, $css ) use ( &$inline ) {
			$inline[] = array( $handle, $css );
			return true;
		} );

		CertificateFonts::enqueue();
		CertificateFonts::enqueue();

		$this->assertCount( 1, $inline );
		$this->assertSame( CertificateFonts::STYLE_HANDLE, $inline[0][0] );
		$this->assertStringStartsWith( '@font-face{font-family:"Lora"', $inline[0][1] );
	}

	public function test_enqueue_does_nothing_without_an_installed_font(): void {
		$registered = 0;
		Functions\when( 'wp_style_is' )->justReturn( false );
		Functions\when( 'wp_register_style' )->alias( static function () use ( &$registered ) {
			++$registered;
			return true;
		} );

		CertificateFonts::enqueue();

		$this->assertSame( 0, $registered );
	}

	public function test_the_notice_lists_installed_families_with_their_css_value(): void {
		$this->family( 3, 'Great Vibes', '"Great Vibes", cursive' );
		$this->face( 3, array( 'fontFamily' => 'Great Vibes', 'src' => 'https://example.test/gv.woff2' ) );

		$html = CertificateFonts::notice_html();

		$this->assertStringContainsString( '<strong>Great Vibes</strong>: <code>font-family: "Great Vibes", cursive</code>', $html );
		$this->assertStringContainsString( 'ffc-certificate-fonts-notice', $html );
		$this->assertStringNotContainsString( 'No font is installed', $html );
	}

	public function test_without_fonts_the_notice_points_at_the_route_this_site_has(): void {
		$route = CertificateFonts::install_route();
		$html  = CertificateFonts::notice_html();

		$this->assertStringContainsString( 'No font is installed for certificates', $html );
		if ( 'library' === $route ) {
			$this->assertStringContainsString( 'font-library.php', $html );
		} else {
			$this->assertStringContainsString( 'WordPress 7.0', $html );
		}
	}

	public function test_install_route_follows_the_fonts_screen_and_the_theme(): void {
		$file    = ABSPATH . 'wp-admin/font-library.php';
		$existed = file_exists( $file );
		if ( $existed ) {
			$this->assertSame( 'library', CertificateFonts::install_route() );
			return;
		}

		$this->assertSame( 'update', CertificateFonts::install_route(), 'A classic theme before 7.0 has no font screen.' );

		Functions\when( 'wp_is_block_theme' )->justReturn( true );
		$this->assertSame( 'site_editor', CertificateFonts::install_route() );

		if ( ! is_dir( dirname( $file ) ) ) {
			mkdir( dirname( $file ), 0777, true );
		}
		file_put_contents( $file, '<?php' );
		try {
			$this->assertSame( 'library', CertificateFonts::install_route(), 'Appearance → Fonts wins once it exists.' );
		} finally {
			unlink( $file );
		}
	}

	public function test_the_site_editor_route_and_a_user_who_cannot_install(): void {
		$file = ABSPATH . 'wp-admin/font-library.php';
		if ( file_exists( $file ) ) {
			$this->markTestSkipped( 'This environment carries the WordPress 7.0 fonts screen.' );
		}
		Functions\when( 'wp_is_block_theme' )->justReturn( true );

		$this->assertStringContainsString( 'site-editor.php', CertificateFonts::notice_html() );

		CertificateFonts::reset();
		Functions\when( 'current_user_can' )->justReturn( false );
		$html = CertificateFonts::notice_html();
		$this->assertStringNotContainsString( 'site-editor.php', $html );
		$this->assertStringContainsString( 'An administrator can install a font in the Site Editor', $html );
	}

	/**
	 * Install one family with a usable face.
	 *
	 * @param int    $id    Post ID.
	 * @param string $name  Title.
	 * @param string $stack Stored fontFamily.
	 */
	private function installed_family( int $id, string $name, string $stack ): void {
		$this->family( $id, $name, $stack );
		$this->face(
			$id,
			array(
				'fontFamily' => $name,
				'src'        => 'https://example.test/wp-content/uploads/fonts/' . $id . '.woff2',
			)
		);
	}

	public function test_requested_reads_the_first_family_of_every_stack_once(): void {
		$html = '<div style="font-family: Arial, Helvetica, sans-serif;color:#000">'
			. '<p style="margin:0; font-family: \'Great Vibes\', Georgia, serif">a</p>'
			. "<p style='font-family:\"EB Garamond\",serif'>b</p>"
			. '<p style="font-family: &quot;Cinzel&quot;, Georgia, serif !important">c</p>'
			. '<p style="font-family: great vibes, cursive">d</p>'
			. '<font face="Cousine, monospace">e</font>'
			. '<p style="font-size: 12pt">f</p></div>';

		$this->assertSame(
			array( 'Arial', 'Great Vibes', 'EB Garamond', 'Cinzel', 'Cousine' ),
			CertificateFonts::requested( $html ),
			'First names only, unquoted, entities decoded, one per name whatever its case.'
		);
	}

	public function test_requested_ignores_text_that_only_mentions_a_font(): void {
		$html = '<p>Use font-family: Lobster in the template.</p><p data-x="font-family: Lobster">x</p>';

		$this->assertSame( array(), CertificateFonts::requested( $html ) );
	}

	public function test_system_fonts_generic_families_and_installed_fonts_are_not_missing(): void {
		$this->installed_family( 7, 'Great Vibes', '"Great Vibes", cursive' );
		$html = '<p style="font-family: Georgia, serif">a</p>'
			. '<p style="font-family: \'Courier New\', monospace">b</p>'
			. '<p style="font-family: sans-serif">c</p>'
			. '<p style="font-family: \'GREAT VIBES\', Georgia, serif">d</p>';

		$this->assertSame( array(), CertificateFonts::missing( $html ) );
		$this->assertSame( '', CertificateFonts::missing_notice_html( $html ) );
	}

	public function test_an_installed_family_counts_by_the_first_name_of_its_stack(): void {
		$this->installed_family( 7, 'Great Vibes Pro', '"Great Vibes", cursive' );

		$this->assertSame( array(), CertificateFonts::missing( '<p style="font-family: \'Great Vibes\'">a</p>' ) );
	}

	public function test_a_font_neither_installed_nor_a_system_font_is_missing(): void {
		$this->installed_family( 7, 'Cinzel', 'Cinzel, serif' );
		$html = '<p style="font-family: Cinzel, Georgia, serif">a</p>'
			. '<p style="font-family: \'EB Garamond\', Georgia, serif">b</p>'
			. '<p style="font-family: \'EB Garamond\', Georgia, serif">c</p>'
			. '<p style="font-family: Arimo, \'Missing Fallback\', sans-serif">d</p>';

		$this->assertSame( array( 'EB Garamond', 'Arimo' ), CertificateFonts::missing( $html ), 'A missing fallback is never reported.' );
	}

	public function test_the_missing_notice_names_each_font_and_the_way_to_install_it(): void {
		$one = CertificateFonts::missing_notice_html( '<p style="font-family: Lobster, cursive">a</p>' );

		$this->assertStringContainsString( 'ffc-template-fonts-missing', $one );
		$this->assertStringContainsString( '<strong>Lobster</strong>', $one );
		$this->assertStringContainsString( 'which is not installed', $one );

		$two = CertificateFonts::missing_notice_html( '<p style="font-family: Lobster">a</p><p style="font-family: Pacifico">b</p>' );
		$this->assertStringContainsString( '<strong>Lobster</strong>, <strong>Pacifico</strong>', $two );
		$this->assertStringContainsString( 'which are not installed', $two );

		$file = ABSPATH . 'wp-admin/font-library.php';
		$this->assertStringContainsString( file_exists( $file ) ? 'font-library.php' : 'update to install fonts', $two );
	}

	public function test_a_font_name_with_markup_is_escaped_in_the_notice(): void {
		Functions\when( 'esc_html' )->alias( static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ) );

		$html = CertificateFonts::missing_notice_html( '<p style="font-family: &lt;b&gt;X">a</p>' );

		$this->assertStringContainsString( '&lt;b&gt;X', $html );
		$this->assertStringNotContainsString( '<b>X', $html );
	}
}
