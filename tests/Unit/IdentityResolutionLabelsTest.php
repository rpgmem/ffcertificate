<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\IdentityResolutionLabels;
use FreeFormCertificate\Maintenance\IdentityQueue;

/**
 * The words the identity-resolution screen prints (#1925).
 *
 * @covers \FreeFormCertificate\Admin\IdentityResolutionLabels
 */
class IdentityResolutionLabelsTest extends TestCase {

	/** @var mixed */
	private $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );

		global $wpdb;
		$this->previous_wpdb = $wpdb;
		$wpdb                = new \stdClass();
		$wpdb->prefix        = 'wp_';
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->previous_wpdb;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Both shapes the queries hand out name the same store.
	 */
	public function test_a_store_is_named_from_either_shape(): void {
		foreach ( array( 'submissions', 'wp_ffc_submissions', 'ffc_submissions' ) as $shape ) {
			$this->assertSame( 'submissions', IdentityResolutionLabels::store_name( $shape ), $shape );
		}

		$this->assertSame( 'appointments', IdentityResolutionLabels::store_name( 'wp_ffc_self_scheduling_appointments' ) );
		$this->assertSame( 'candidacies', IdentityResolutionLabels::store_name( 'recruitment_candidate' ) );
		$this->assertSame( 'the identity index', IdentityResolutionLabels::store_name( 'user_profiles' ), 'The index is named for what it is.' );
	}

	public function test_an_unmapped_store_renders_as_its_normalised_self(): void {
		$this->assertSame( 'audience_bookings', IdentityResolutionLabels::store_name( 'wp_ffc_audience_bookings' ) );
		$this->assertSame( '', IdentityResolutionLabels::store_name( array( 'not a store' ) ) );
	}

	public function test_a_store_list_is_joined_by_wordpress_never_by_the_query_separator(): void {
		Functions\expect( 'wp_sprintf_l' )
			->once()
			->with( '%l', array( 'submissions', 'candidacies' ) )
			->andReturn( 'submissions and candidacies' );

		$this->assertSame( 'submissions and candidacies', IdentityResolutionLabels::store_list( 'submissions| recruitment_candidate|' ) );
	}

	public function test_an_empty_store_list_is_empty(): void {
		Functions\expect( 'wp_sprintf_l' )->never();

		$this->assertSame( '', IdentityResolutionLabels::store_list( '' ) );
		$this->assertSame( '', IdentityResolutionLabels::store_list( null ) );
	}

	public function test_an_account_is_named_when_wordpress_knows_the_name(): void {
		Functions\when( 'get_userdata' )->alias(
			static fn( $id ) => 5 === $id ? (object) array( 'display_name' => 'Ana' ) : ( 6 === $id ? (object) array( 'display_name' => '' ) : false )
		);

		$this->assertSame( 'Ana (#5)', IdentityResolutionLabels::account( '5' ) );
		$this->assertSame( 'Account #6', IdentityResolutionLabels::account( 6 ), 'An empty name falls back to the id.' );
		$this->assertSame( 'Account #9', IdentityResolutionLabels::account( 9 ), 'A deleted account still has an id to show.' );
	}

	public function test_an_identifier_is_named_only_when_known(): void {
		$this->assertSame( 'RF', IdentityResolutionLabels::identifier_name( 'rf_hash' ) );
		$this->assertSame( 'CPF', IdentityResolutionLabels::identifier_name( 'cpf_hash' ) );
		$this->assertSame( '', IdentityResolutionLabels::identifier_name( 'phone_hash' ) );
		$this->assertSame( '', IdentityResolutionLabels::identifier_name( null ) );
	}

	public function test_the_corrected_label_names_its_identifier_or_says_nothing_wrong(): void {
		$this->assertSame( 'Corrected CPF', IdentityResolutionLabels::corrected_label( 'cpf_hash' ) );
		$this->assertSame( 'The corrected number', IdentityResolutionLabels::corrected_label( 'phone_hash' ), 'A wrong name is worse than no name.' );
	}

	public function test_the_chip_class_keeps_only_letters(): void {
		$this->assertSame( 'ffc-identity-chip-mechanical', IdentityResolutionLabels::chip_class( IdentityQueue::TIER_MECHANICAL ) );
		$this->assertSame( 'ffc-identity-chip-scriptalert', IdentityResolutionLabels::chip_class( '"><script>alert(1)' ) );
	}

	public function test_every_tier_has_its_own_label_and_note(): void {
		$labels = array();
		foreach ( array( IdentityQueue::TIER_MECHANICAL, IdentityQueue::TIER_SHARED, IdentityQueue::TIER_ISOLATED, IdentityQueue::TIER_MAILBOX, IdentityQueue::TIER_DECISION ) as $tier ) {
			$labels[ $tier ] = IdentityResolutionLabels::tier_label( $tier );
			$this->assertNotSame( '', IdentityResolutionLabels::tier_note( $tier ), $tier );
		}

		$this->assertCount( 5, array_unique( $labels ), 'Two tiers sharing a name would be one chip for two decisions.' );
		$this->assertSame( 'Needs a decision', IdentityResolutionLabels::tier_label( IdentityQueue::TIER_DECISION ) );
	}
}
