<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabDocumentation;

/**
 * @covers \FreeFormCertificate\Settings\Tabs\TabDocumentation
 */
class TabDocumentationTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private TabDocumentation $tab;

	/**
	 * Temporary view directory a test created, removed in tearDown() so a
	 * failing assertion cannot leave it behind.
	 */
	private ?string $tmp_dir = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message, $args = array() ) {
				$ffc_type = isset( $args['type'] ) ? $args['type'] : 'info';
				$ffc_cls  = 'notice notice-' . $ffc_type;
				if ( ! empty( $args['dismissible'] ) ) { $ffc_cls .= ' is-dismissible'; }
				if ( ! empty( $args['additional_classes'] ) ) { $ffc_cls .= ' ' . implode( ' ', $args['additional_classes'] ); }
				$ffc_wrap = ! array_key_exists( 'paragraph_wrap', $args ) || $args['paragraph_wrap'];
				echo '<div class="' . $ffc_cls . '">' . ( $ffc_wrap ? '<p>' . $message . '</p>' : $message ) . '</div>';
			}
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		// `SettingsTab` requires `ABSPATH . 'wp-includes/formatting.php'` when
		// `wp_kses_post` is undefined, and the test ABSPATH has no such file:
		// without this stub the file only passed after an earlier test had
		// taught Patchwork the function (same reason as TabMigrationsTest).
		Functions\when( 'wp_kses_post' )->returnArg();

		$this->tab = new TabDocumentation();
	}

	protected function tearDown(): void {
		if ( null !== $this->tmp_dir && is_dir( $this->tmp_dir ) ) {
			foreach ( (array) glob( $this->tmp_dir . '/*' ) as $file ) {
				unlink( (string) $file );
			}
			rmdir( $this->tmp_dir );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_tab_id(): void {
		$this->assertSame( 'documentation', $this->tab->get_id() );
	}

	public function test_tab_title(): void {
		$this->assertSame( 'Documentation', $this->tab->get_title() );
	}

	public function test_tab_icon(): void {
		$this->assertSame( 'ffc-icon-doc', $this->tab->get_icon() );
	}

	public function test_tab_order(): void {
		$this->assertSame( 90, $this->tab->get_order() );
	}

	/**
	 * The tab under test with only its view directory redirected, through the
	 * `view_path()` seam: render() is the product's own (#1811).
	 *
	 * @param string $views_dir Directory the tab reads its view from.
	 * @return TabDocumentation
	 */
	private function tab_with_views_in( string $views_dir ): TabDocumentation {
		return new class( $views_dir ) extends TabDocumentation {
			private string $views_dir;
			public function __construct( string $views_dir ) {
				$this->views_dir = $views_dir;
				parent::__construct();
			}
			protected function view_path( string $file ): string {
				return $this->views_dir . '/' . $file;
			}
		};
	}

	public function test_render_error_when_view_missing(): void {
		$tab = $this->tab_with_views_in( sys_get_temp_dir() . '/ffc_test_no_such_view_dir_' . getmypid() );

		ob_start();
		$tab->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice notice-error', $output );
		$this->assertStringContainsString( 'Documentation view file not found.', $output );
	}

	public function test_render_includes_view_when_file_exists(): void {
		$this->tmp_dir = sys_get_temp_dir() . '/ffc_test_views_doc_' . getmypid();
		if ( ! is_dir( $this->tmp_dir ) ) {
			mkdir( $this->tmp_dir, 0777, true );
		}
		file_put_contents( $this->tmp_dir . '/ffc-tab-documentation.php', '<?php echo "documentation-view-rendered";' );

		ob_start();
		$this->tab_with_views_in( $this->tmp_dir )->render();
		$output = ob_get_clean();

		$this->assertSame( 'documentation-view-rendered', $output );
	}


	public function test_get_option_returns_default(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertSame( 'x', $this->tab->get_option( 'missing_key', 'x' ) );
	}
}
