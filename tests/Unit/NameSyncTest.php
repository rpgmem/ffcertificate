<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use FreeFormCertificate\UserDashboard\NameSync;
use FreeFormCertificate\UserDashboard\UserProfileService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * WordPress's first / last name back into the plugin's full name (#1552).
 *
 * Runs the real UserProfileService and UserProfileRepository over a `$wpdb`
 * double: the profile row is what `get_row()` answers, and a full name the
 * sync writes is what reaches `$wpdb->update()` on the profile table.
 *
 * @covers \FreeFormCertificate\UserDashboard\NameSync
 */
class NameSyncTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * User meta by key.
	 *
	 * @var array<string, string>
	 */
	private array $meta = array();

	/**
	 * Full names written to the profile table.
	 *
	 * @var array<int, string>
	 */
	private array $written = array();

	/**
	 * The plugin full name the profile row carries, null for no row.
	 */
	private ?string $full = null;

	/**
	 * The `$wpdb` global before the test, restored in tearDown().
	 *
	 * @var mixed
	 */
	private $previous_wpdb;

	/**
	 * Whether `$wpdb` was set before the test.
	 */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$_POST         = array();
		$this->meta    = array();
		$this->written = array();
		$this->full    = null;

		$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			fn() => null === $this->full ? null : array(
				'user_id'      => '7',
				'display_name' => $this->full,
			)
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing( fn() => null === $this->full ? null : '1' );
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, array $data ) {
				if ( 'wp_ffc_user_profiles' === $table && isset( $data['display_name'] ) ) {
					$this->written[] = $data['display_name'];
				}
				return 1;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'get_userdata' )->alias(
			fn() => (object) array(
				'first_name' => $this->meta['first_name'] ?? '',
				'last_name'  => $this->meta['last_name'] ?? '',
			)
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_update_user' )->alias( static fn( array $u ) => $u['ID'] );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'delete_user_meta' )->justReturn( true );

		NameSync::reset();
	}

	protected function tearDown(): void {
		$_POST = array();
		NameSync::reset();
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The account's plugin profile, or none.
	 *
	 * @param string|null $full Full name, null for no profile.
	 * @return void
	 */
	private function profile( ?string $full ): void {
		$this->full = $full;
	}

	public function test_init_snapshots_before_the_plugin_fields_save_and_reconciles_after_core(): void {
		Actions\expectAdded( 'personal_options_update' )->with( array( NameSync::class, 'snapshot' ), 1 );
		Actions\expectAdded( 'edit_user_profile_update' )->with( array( NameSync::class, 'snapshot' ), 1 );
		Actions\expectAdded( 'profile_update' )->with( array( NameSync::class, 'on_profile_update' ), 20, 3 );

		NameSync::init();
	}

	public function test_changing_wordpress_parts_off_screen_updates_the_full_name(): void {
		$this->profile( 'Alex Meusburger' );
		$this->meta = array( 'first_name' => 'Alexandre', 'last_name' => 'Meusburger' );

		NameSync::on_profile_update( 7, null, array( 'first_name' => 'Alexandre' ) );

		$this->assertSame( array( 'Alexandre Meusburger' ), $this->written );
	}

	public function test_an_update_that_did_not_touch_the_parts_writes_nothing(): void {
		$this->profile( 'Alex Meusburger' );
		$this->meta = array( 'first_name' => 'Other', 'last_name' => '' );

		NameSync::on_profile_update( 7, null, array( 'user_email' => 'x@example.org' ) );

		$this->assertSame( array(), $this->written );
	}

	public function test_parts_that_already_make_the_full_name_write_nothing(): void {
		$this->profile( 'Maria da Silva' );
		$this->meta = array( 'first_name' => 'Maria', 'last_name' => ' da  Silva' );

		NameSync::on_profile_update( 7, null, array( 'first_name' => 'Maria', 'last_name' => 'da Silva' ) );

		$this->assertSame( array(), $this->written );
	}

	public function test_an_account_without_a_plugin_profile_is_left_alone(): void {
		$this->profile( null );
		$this->meta = array( 'first_name' => 'Site', 'last_name' => 'Admin' );

		NameSync::on_profile_update( 1, null, array( 'first_name' => 'Site' ) );

		$this->assertSame( array(), $this->written );
	}

	/**
	 * The service's mirror calls wp_update_user(), which fires
	 * `profile_update` while the write is still in progress; the sync must
	 * not answer it with a second write of the stale parts.
	 */
	public function test_the_plugins_own_write_is_not_answered(): void {
		$this->profile( 'Alex Meusburger' );
		$this->meta = array( 'first_name' => 'Stale', 'last_name' => 'Parts' );
		// Fire the hook once, as core would for this one update.
		$fired = false;
		Functions\when( 'wp_update_user' )->alias(
			static function ( array $u ) use ( &$fired ) {
				if ( ! $fired ) {
					$fired = true;
					NameSync::on_profile_update( (int) $u['ID'], null, array( 'first_name' => 'Stale' ) );
				}
				return $u['ID'];
			}
		);

		UserProfileService::write( 7, array( 'display_name' => 'Alexandre Meusburger' ) );

		$this->assertSame( array( 'Alexandre Meusburger' ), $this->written, 'only the plugin\'s own write reaches the profile table' );
	}

	/**
	 * On the user-edit screen: only WordPress's parts were changed, so they
	 * win over the plugin field the form posted back unchanged.
	 */
	public function test_on_the_screen_changed_parts_win_over_an_unchanged_plugin_name(): void {
		$this->profile( 'Alex Meusburger' );
		$this->meta = array( 'first_name' => 'Alex', 'last_name' => 'Meusburger' );
		$_POST      = array( 'first_name' => 'Alex', 'last_name' => 'M. Meusburger' );

		NameSync::snapshot( 7 );
		// Core then writes the posted parts.
		$this->meta = array( 'first_name' => 'Alex', 'last_name' => 'M. Meusburger' );
		NameSync::on_profile_update( 7, null, array( 'first_name' => 'Alex', 'last_name' => 'M. Meusburger' ) );

		$this->assertSame( array( 'Alex M. Meusburger' ), $this->written );
	}

	/**
	 * On the user-edit screen: only the plugin's name was changed. Core then
	 * overwrote the derived parts with the stale ones the form carried, so the
	 * name is written again to re-derive them.
	 */
	public function test_on_the_screen_a_changed_plugin_name_re_derives_the_parts_core_overwrote(): void {
		$this->profile( 'Alex Meusburger' );
		$this->meta = array( 'first_name' => 'Alex', 'last_name' => 'Meusburger' );
		$_POST      = array( 'first_name' => 'Alex', 'last_name' => 'Meusburger' );

		NameSync::snapshot( 7 );
		$this->profile( 'Alexandre Meusburger' ); // The plugin field saved at 10.
		NameSync::on_profile_update( 7, null, array( 'first_name' => 'Alex', 'last_name' => 'Meusburger' ) );

		$this->assertSame( array( 'Alexandre Meusburger' ), $this->written );
	}

	public function test_on_the_screen_when_both_changed_the_plugin_name_wins(): void {
		$this->profile( 'Alex Meusburger' );
		$this->meta = array( 'first_name' => 'Alex', 'last_name' => 'Meusburger' );
		$_POST      = array( 'first_name' => 'Al', 'last_name' => 'M' );

		NameSync::snapshot( 7 );
		$this->profile( 'Alexandre Meusburger' );
		NameSync::on_profile_update( 7, null, array( 'first_name' => 'Al', 'last_name' => 'M' ) );

		$this->assertSame( array( 'Alexandre Meusburger' ), $this->written );
	}

	public function test_on_the_screen_with_nothing_changed_nothing_is_written(): void {
		$this->profile( 'Alex Meusburger' );
		$this->meta = array( 'first_name' => 'Alex', 'last_name' => 'Meusburger' );
		$_POST      = array( 'first_name' => 'Alex', 'last_name' => 'Meusburger' );

		NameSync::snapshot( 7 );
		NameSync::on_profile_update( 7, null, array( 'first_name' => 'Alex', 'last_name' => 'Meusburger' ) );

		$this->assertSame( array(), $this->written );
	}
}
