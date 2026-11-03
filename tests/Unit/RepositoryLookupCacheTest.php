<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Repositories\CalendarRepository;
use FreeFormCertificate\Repositories\SubmissionReader;
use FreeFormCertificate\Repositories\SubmissionRepository;
use FreeFormCertificate\Repositories\SubmissionWriter;
use FreeFormCertificate\UrlShortener\UrlShortenerReader;
use FreeFormCertificate\UrlShortener\UrlShortenerRepository;
use FreeFormCertificate\UrlShortener\UrlShortenerWriter;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * A write clears every key a row is cached under, not only `id_*` (#2343).
 *
 * With a persistent object cache the alternate copies outlived the write: a
 * disabled or deleted short URL kept redirecting, a deleted certificate kept
 * verifying, a saved calendar kept its old settings.
 *
 * @covers \FreeFormCertificate\Repositories\AbstractRepository
 * @covers \FreeFormCertificate\Repositories\SubmissionReader
 * @covers \FreeFormCertificate\Repositories\SubmissionWriter
 * @covers \FreeFormCertificate\Repositories\SubmissionRepository
 * @covers \FreeFormCertificate\Repositories\CalendarRepository
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerReader
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerWriter
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerRepository
 */
class RepositoryLookupCacheTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $wpdb;

	/** @var array{0: bool, 1: mixed} Whether `$wpdb` was set, and its value. */
	private array $wpdb_backup = array( false, null );

	/**
	 * Every `group:key` deleted from the object cache.
	 *
	 * @var array<int, string>
	 */
	private array $deleted = array();

	/**
	 * The row the double answers a read by id with.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $row = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb_backup = array( array_key_exists( 'wpdb', $GLOBALS ), $GLOBALS['wpdb'] ?? null );

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$wpdb->insert_id  = 0;
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql ) => (string) $sql )->byDefault();
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing( fn() => $this->row )->byDefault();
		$wpdb->shouldReceive( 'update' )->andReturn( 1 )->byDefault();
		$wpdb->shouldReceive( 'delete' )->andReturn( 1 )->byDefault();
		$wpdb->shouldReceive( 'insert' )->andReturn( 1 )->byDefault();
		$this->wpdb = $wpdb;

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_supports' )->justReturn( true );
		Functions\when( 'wp_cache_flush_group' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key, $group ): bool {
				$this->deleted[] = $group . ':' . $key;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		if ( $this->wpdb_backup[0] ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup[1];
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_renamed_short_url_drops_its_old_and_new_codes(): void {
		$this->row = array(
			'id'         => '7',
			'short_code' => 'old123',
			'post_id'    => '55',
		);

		( new UrlShortenerRepository() )->update( 7, array( 'short_code' => 'new456' ) );

		$this->assertSame(
			array( 'ffc_short_urls:id_7', 'ffc_short_urls:code_old123', 'ffc_short_urls:post_55', 'ffc_short_urls:code_new456' ),
			$this->deleted
		);
	}

	public function test_a_deleted_certificate_stops_verifying(): void {
		$this->row = array(
			'id'          => '9',
			'auth_code'   => 'ABCD1234EFGH',
			'magic_token' => 'tok',
		);

		( new SubmissionRepository() )->delete( 9 );

		$this->assertSame(
			array( 'ffc_submissions:id_9', 'ffc_submissions:auth_ABCD1234EFGH', 'ffc_submissions:token_tok' ),
			$this->deleted
		);
	}

	public function test_a_trashed_submission_drops_its_lookups_through_the_writer(): void {
		$this->row = array(
			'id'          => '9',
			'auth_code'   => 'ABCD1234EFGH',
			'magic_token' => '',
		);
		// A status change also drops the status-count cache.
		Functions\when( 'delete_transient' )->justReturn( true );

		( new SubmissionWriter() )->update( 9, array( 'status' => 'trash' ) );

		$this->assertContains( 'ffc_submissions:auth_ABCD1234EFGH', $this->deleted );
		$this->assertNotContains( 'ffc_submissions:token_', $this->deleted, 'An empty value names no key.' );
	}

	public function test_a_saved_calendar_drops_its_post_key(): void {
		$this->row = array(
			'id'      => '3',
			'post_id' => '120',
		);

		( new CalendarRepository() )->update( 3, array( 'status' => 'inactive' ) );

		$this->assertSame( array( 'ffc_self_scheduling_calendars:id_3', 'ffc_self_scheduling_calendars:post_120' ), $this->deleted );
	}

	public function test_every_class_writing_a_group_names_the_same_keys(): void {
		$read = static function ( object $repository ): array {
			$method = new \ReflectionMethod( $repository, 'alternate_cache_keys' );
			$method->setAccessible( true );
			return $method->invoke( $repository );
		};

		$this->assertSame( SubmissionReader::LOOKUP_CACHE_KEYS, $read( new SubmissionReader() ) );
		$this->assertSame( SubmissionReader::LOOKUP_CACHE_KEYS, $read( new SubmissionWriter() ) );
		$this->assertSame( SubmissionReader::LOOKUP_CACHE_KEYS, $read( new SubmissionRepository() ) );
		$this->assertSame( UrlShortenerReader::LOOKUP_CACHE_KEYS, $read( new UrlShortenerReader() ) );
		$this->assertSame( UrlShortenerReader::LOOKUP_CACHE_KEYS, $read( new UrlShortenerWriter() ) );
		$this->assertSame( UrlShortenerReader::LOOKUP_CACHE_KEYS, $read( new UrlShortenerRepository() ) );
		$this->assertNotEmpty( SubmissionReader::LOOKUP_CACHE_KEYS );
	}

	public function test_a_group_without_alternate_keys_reads_nothing_before_a_write(): void {
		$this->wpdb->shouldNotReceive( 'get_row' );

		( new \FreeFormCertificate\Repositories\BlockedDateRepository() )->update( 4, array( 'reason' => 'x' ) );

		$this->assertSame( array( 'ffc_self_scheduling_blocked_dates:id_4' ), $this->deleted );
	}
}
