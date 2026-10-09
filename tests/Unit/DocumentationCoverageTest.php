<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Settings\SettingsReader;
use PHPUnit\Framework\TestCase;

/**
 * Every Settings tab and every module has a documentation topic, and the
 * documentation index points only at topics that exist (#1638).
 *
 * An audit of Settings → Documentation found seven shipped features with no
 * coverage, five of them added after the previous documentation review
 * without touching a topic. Whether a topic tells the truth is beyond any
 * test; whether one exists for each tab and module, and whether the index
 * and the topic files agree, is a contract between names declared on both
 * sides, and that is what this holds. A new tab or module fails here until it
 * is mapped to the topic that documents it.
 *
 * It sees coverage, never accuracy: a topic that describes a tab wrongly
 * passes.
 *
 * @coversNothing
 */
class DocumentationCoverageTest extends TestCase {

	/**
	 * The topic (its anchor) that documents each Settings tab.
	 *
	 * @var array<string, string>
	 */
	private const TAB_TOPICS = array(
		'activity_log'    => 'config-activity-log',
		'advanced'        => 'config-advanced',
		'cache'           => 'config-cache',
		'captcha'         => 'config-captcha',
		'email_model'     => 'email-texts-hub',
		'email_texts'     => 'email-texts-hub',
		'general'         => 'config-general',
		'geolocation'     => 'config-geolocation',
		'ip_diagnostics'  => 'config-ip-diagnostics',
		'migrations'      => 'operations-migrations',
		'modulos'         => 'config-modules',
		'qr_code'         => 'config-qr-code',
		'rate_limit'      => 'config-rate-limit',
		'reregistration'  => 'feature-reregistration',
		'scheduled_tasks' => 'config-scheduled-tasks',
		'smtp'            => 'reference-emails',
		'templates'       => 'document-templates-hub',
		'url_shortener'   => 'feature-url-shortener',
		'user_access'     => 'config-user-access',
	);

	/**
	 * Tabs that need no topic of their own, with why.
	 *
	 * @var array<string, string>
	 */
	private const TABS_WITHOUT_TOPIC = array(
		'documentation' => 'the tab is the documentation',
	);

	/**
	 * The topic that documents each module.
	 *
	 * @var array<string, string>
	 */
	private const MODULE_TOPICS = array(
		'certificates'    => 'feature-certificates',
		'self_scheduling' => 'feature-self-scheduling',
		'audiences'       => 'scheduling-audiences',
		'reregistration'  => 'feature-reregistration',
		'recruitment'     => 'feature-recruitment',
		'url_shortener'   => 'feature-url-shortener',
		'date_messages'   => 'feature-date-messages',
	);

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * The documentation index as anchor => file.
	 *
	 * @return array<string, string>
	 */
	private static function index(): array {
		$src = (string) file_get_contents( self::root() . '/includes/settings/views/ffc-tab-documentation.php' );
		preg_match_all( "/'anchor'\\s*=>\\s*'([a-z0-9-]+)',(?:(?!'anchor').)*?'file'\\s*=>\\s*'([a-z0-9-]+\\.php)'/s", $src, $m );
		return array_combine( $m[1], $m[2] );
	}

	/**
	 * The Settings tab ids, read from the tab classes.
	 *
	 * @return array<int, string>
	 */
	private static function tab_ids(): array {
		$ids = array();
		foreach ( (array) glob( self::root() . '/includes/settings/tabs/*.php' ) as $file ) {
			if ( 1 === preg_match( "/tab_id\\s*=\\s*'([a-z_]+)'/", (string) file_get_contents( (string) $file ), $m ) ) {
				$ids[] = $m[1];
			}
		}
		sort( $ids );
		return $ids;
	}

	public function test_the_scans_read_every_tab_and_topic(): void {
		// Independent recounts: one tab id per tab class, one index entry per
		// topic file on disk.
		$this->assertCount( count( (array) glob( self::root() . '/includes/settings/tabs/*.php' ) ), self::tab_ids() );
		$this->assertCount( count( (array) glob( self::root() . '/includes/settings/views/documentation/*.php' ) ), self::index() );
	}

	public function test_every_settings_tab_has_a_topic(): void {
		$index = self::index();
		foreach ( self::tab_ids() as $id ) {
			if ( isset( self::TABS_WITHOUT_TOPIC[ $id ] ) ) {
				continue;
			}
			$this->assertArrayHasKey( $id, self::TAB_TOPICS, 'Settings tab "' . $id . '" has no documentation topic — write one and map it here' );
			$this->assertArrayHasKey( self::TAB_TOPICS[ $id ], $index, $id . ' is mapped to a topic the index does not list' );
		}
		$this->assertSame( array(), array_diff( array_keys( self::TAB_TOPICS ), self::tab_ids() ), 'a mapped tab no longer exists; drop it' );
	}

	public function test_every_module_has_a_topic(): void {
		$index = self::index();
		$modules = SettingsReader::MODULE_SLUGS;
		$mapped  = array_keys( self::MODULE_TOPICS );
		sort( $modules );
		sort( $mapped );
		$this->assertSame( $modules, $mapped, 'a module was added or removed; map it to the topic that documents it' );
		foreach ( self::MODULE_TOPICS as $module => $anchor ) {
			$this->assertArrayHasKey( $anchor, $index, $module . ' is mapped to a topic the index does not list' );
		}
	}

	public function test_the_top_level_follows_the_admin_menu(): void {
		// The overview first, then one branch per plugin area in menu order,
		// then Developer and Troubleshooting. Read as the order of the
		// top-level `'icon'` lines: an area names itself through PluginAreas.
		$src = (string) file_get_contents( self::root() . '/includes/settings/views/ffc-tab-documentation.php' );
		preg_match_all( "/^\\tarray\\(\\n\\t\\t(?:'anchor' => '([a-z-]+)',\\n\\t\\t)?'icon'\\s*=> (?:\\\\FreeFormCertificate\\\\Core\\\\PluginAreas::icon_class\\( '([a-z_]+)' \\)|'([a-z-]+)')/m", $src, $m, PREG_SET_ORDER );
		$order = array_map(
			static fn( array $hit ): string => '' !== $hit[2] ? $hit[2] : ( '' !== $hit[1] ? $hit[1] : $hit[3] ),
			$m
		);
		$this->assertSame(
			array_merge( array( 'overview' ), array_keys( \FreeFormCertificate\Core\PluginAreas::all() ), array( 'ffc-icon-code', 'operations-troubleshooting' ) ),
			$order
		);
	}

	public function test_index_and_topic_files_agree(): void {
		$dir = self::root() . '/includes/settings/views/documentation/';
		foreach ( self::index() as $anchor => $file ) {
			$this->assertFileExists( $dir . $file, 'the index lists a topic file that does not exist' );
			$this->assertStringContainsString( 'id="' . $anchor . '"', (string) file_get_contents( $dir . $file ), $file . ' does not carry the anchor the index links to' );
		}
		$on_disk = array_map( 'basename', (array) glob( $dir . '*.php' ) );
		$this->assertSame( array(), array_values( array_diff( $on_disk, array_values( self::index() ) ) ), 'a topic file the index never shows' );
	}
}
