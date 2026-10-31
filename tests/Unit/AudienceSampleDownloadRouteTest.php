<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAdminPage;
use FreeFormCertificate\Audience\AudienceCsvImporter;
use FreeFormCertificate\Core\CsvDownloadInterface;
use FreeFormCertificate\Core\CsvStreamer;

/**
 * The "Download Sample" request on the URL the Import & Export tab now links
 * to (`page=ffc-scheduling-settings&tab=import&download_sample=…`) reaches the
 * sample download through the coordinator's `admin_init` dispatch (#1771).
 *
 * The real `SyncCsvExport`, `CsvStreamer` and `AudienceSampleCsvSource` run;
 * only the HTTP end is replaced, by a buffered `CsvDownloadInterface` handed to
 * the import handler the coordinator built, so the sample's bytes are read
 * back instead of being sent with `exit`.
 *
 * @covers \FreeFormCertificate\Audience\AudienceAdminPage
 */
class AudienceSampleDownloadRouteTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * The `$wpdb` global before the test, restored in tearDown.
	 *
	 * @var mixed
	 */
	private $saved_wpdb;

	/**
	 * Whether `$wpdb` was set before the test.
	 */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb   = array_key_exists( 'wpdb', $GLOBALS );
		$this->saved_wpdb = $this->had_wpdb ? $GLOBALS['wpdb'] : null;
		// A GET for the sample touches no table: a double with no expectations
		// fails the test on any query.
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'get_option' )->justReturn( array() );
	}

	protected function tearDown(): void {
		$_GET = array();
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->saved_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A download that records the file name and captures the CSV bytes.
	 */
	private function buffered_download(): CsvDownloadInterface {
		return new class() implements CsvDownloadInterface {
			/** @var array<int, string> */
			public array $filenames = array();
			public int $finished    = 0;
			public string $output   = '';
			/** @var resource|null */
			private $stream = null;

			public function send_headers( string $filename ): void {
				$this->filenames[] = $filename;
			}

			public function open_stream() {
				if ( ! is_resource( $this->stream ) ) {
					$this->stream = fopen( 'php://memory', 'w+' );
				}
				return $this->stream;
			}

			public function finish(): void {
				++$this->finished;
				if ( is_resource( $this->stream ) ) {
					rewind( $this->stream );
					$this->output = (string) stream_get_contents( $this->stream );
				}
			}
		};
	}

	public function test_sample_download_runs_on_the_settings_import_tab(): void {
		$_GET = array(
			'page'            => 'ffc-scheduling-settings',
			'tab'             => 'import',
			'download_sample' => 'members',
			'_wpnonce'        => 'n',
		);

		$page = new AudienceAdminPage();
		$page->init();

		// Hand the import handler the coordinator built a buffered download.
		$download = $this->buffered_download();
		$import   = ( new \ReflectionProperty( AudienceAdminPage::class, 'import' ) )->getValue( $page );
		( new \ReflectionProperty( get_class( $import ), 'streamer' ) )->setValue( $import, new CsvStreamer( $download ) );

		$page->handle_form_submissions();

		$this->assertSame( 1, $download->finished, 'the sample is streamed exactly once' );
		$this->assertSame( array( 'members-sample.csv' ), $download->filenames, 'the members sample source answered the request' );
		$sample = AudienceCsvImporter::get_sample_rows( 'members' );
		// The writer opens with a UTF-8 BOM and separates with `;` for Excel.
		$lines = preg_split( '/\r?\n/', trim( (string) preg_replace( '/^\xEF\xBB\xBF/', '', $download->output ) ) );
		$this->assertSame( $sample['header'], str_getcsv( (string) $lines[0], ';', '"', '\\' ) );
		$this->assertCount( 1 + count( $sample['rows'] ), $lines );
	}
}
