<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Core\IpAddress;
use PHPUnit\Framework\TestCase;

/**
 * The plugin's one rule for which requests come from the same address (#2106).
 *
 * @covers \FreeFormCertificate\Core\IpAddress
 */
class IpAddressTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public function canonical_cases(): array {
		return array(
			'IPv4'                    => array( '203.0.113.7', '203.0.113.7' ),
			'IPv6, compressed'        => array( '2804:14c:1:2::1', '2804:14c:1:2::1' ),
			'IPv6, upper case'        => array( '2804:14C:1:2::1', '2804:14c:1:2::1' ),
			'IPv6, written out'       => array( '2804:014c:0001:0002:0000:0000:0000:0001', '2804:14c:1:2::1' ),
			'IPv4 mapped into IPv6'   => array( '::ffff:203.0.113.7', '203.0.113.7' ),
			'IPv4 mapped, in hex'     => array( '::ffff:cb00:7107', '203.0.113.7' ),
			'the resolver sentinel'   => array( '0.0.0.0', null ),
			'empty'                   => array( '', null ),
			'not an address'          => array( 'example.com', null ),
			'address with a port'     => array( '203.0.113.7:8080', null ),
			'IPv6 with a zone'        => array( 'fe80::1%eth0', null ),
		);
	}

	/**
	 * @dataProvider canonical_cases
	 */
	public function test_canonical_form( string $ip, ?string $expected ): void {
		$this->assertSame( $expected, IpAddress::canonical( $ip ) );
	}

	public function test_an_ipv4_address_is_counted_whole(): void {
		$this->assertSame( 'v4:cb007107', IpAddress::rate_key( '203.0.113.7' ) );
		$this->assertNotSame( IpAddress::rate_key( '203.0.113.7' ), IpAddress::rate_key( '203.0.113.8' ) );
	}

	public function test_an_ipv6_address_is_counted_by_its_64(): void {
		$key = IpAddress::rate_key( '2804:14c:1:2::1' );

		$this->assertSame( 'v6:2804014c00010002::/64', $key );
		$this->assertSame( $key, IpAddress::rate_key( '2804:14C:1:2:ffff:ffff:ffff:ffff' ) );
		$this->assertNotSame( $key, IpAddress::rate_key( '2804:14c:1:3::1' ) );
	}

	public function test_an_ipv4_address_inside_ipv6_counts_as_itself(): void {
		$this->assertSame( IpAddress::rate_key( '203.0.113.7' ), IpAddress::rate_key( '::ffff:203.0.113.7' ) );
	}

	public function test_an_unresolved_address_has_no_key(): void {
		$this->assertNull( IpAddress::rate_key( '0.0.0.0' ) );
		$this->assertNull( IpAddress::rate_key( '' ) );
		$this->assertNull( IpAddress::rate_key( 'unknown' ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public function range_cases(): array {
		return array(
			'IPv4 address'                => array( '203.0.113.7', '203.0.113.7' ),
			'IPv4 address, padded'        => array( "  203.0.113.7\t", '203.0.113.7' ),
			'IPv6 address, upper case'    => array( '2804:14C:0:0::1', '2804:14c::1' ),
			'IPv4 inside IPv6'            => array( '::ffff:203.0.113.7', '203.0.113.7' ),
			'IPv4 range'                  => array( '203.0.113.0/24', '203.0.113.0/24' ),
			'IPv4 range, host bits set'   => array( '10.1.2.3/8', '10.0.0.0/8' ),
			'IPv4 range, odd length'      => array( '203.0.113.200/26', '203.0.113.192/26' ),
			'IPv4 /32'                    => array( '203.0.113.7/32', '203.0.113.7/32' ),
			'IPv4 /0'                     => array( '1.2.3.4/0', '0.0.0.0/0' ),
			'IPv6 range'                  => array( '2804:14C:1:2::/48', '2804:14c:1::/48' ),
			'IPv6 /64'                    => array( '2804:14c:1:2:3::9/64', '2804:14c:1:2::/64' ),
			'IPv4-mapped range'           => array( '::ffff:10.0.0.0/104', '10.0.0.0/8' ),
			'IPv4 prefix too long'        => array( '203.0.113.0/33', null ),
			'IPv6 prefix too long'        => array( '2804:14c::/129', null ),
			'prefix not a number'         => array( '203.0.113.0/abc', null ),
			'negative prefix'             => array( '203.0.113.0/-1', null ),
			'empty prefix'                => array( '203.0.113.0/', null ),
			'two slashes'                 => array( '203.0.113.0/24/8', null ),
			'a typo'                      => array( '203.0.113', null ),
			'a hostname'                  => array( 'example.com', null ),
			'a wildcard'                  => array( '203.0.113.*', null ),
			'empty'                       => array( '', null ),
		);
	}

	/**
	 * @dataProvider range_cases
	 */
	public function test_a_list_entry_is_stored_canonical( string $entry, ?string $expected ): void {
		$this->assertSame( $expected, IpAddress::range( $entry ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public function match_cases(): array {
		return array(
			'same IPv4 address'                   => array( '203.0.113.7', '203.0.113.7', true ),
			'another IPv4 address'                => array( '203.0.113.8', '203.0.113.7', false ),
			'IPv6 spelled another way'            => array( '2804:14C:0:0::1', '2804:14c::1', true ),
			'IPv4 inside IPv6 against IPv4'       => array( '::ffff:203.0.113.7', '203.0.113.7', true ),
			'IPv4 against an entry inside IPv6'   => array( '203.0.113.7', '::ffff:203.0.113.7', true ),
			'inside an IPv4 range'                => array( '203.0.113.250', '203.0.113.0/24', true ),
			'outside an IPv4 range'               => array( '203.0.114.1', '203.0.113.0/24', false ),
			'odd length, inside'                  => array( '203.0.113.200', '203.0.113.192/26', true ),
			'odd length, outside'                 => array( '203.0.113.191', '203.0.113.192/26', false ),
			'range stored with host bits'         => array( '10.9.9.9', '10.1.2.3/8', true ),
			'inside an IPv6 range'                => array( '2804:14c:1:ffff::1', '2804:14c:1::/48', true ),
			'outside an IPv6 range'               => array( '2804:14c:2::1', '2804:14c:1::/48', false ),
			'IPv4 never matches an IPv6 range'    => array( '203.0.113.7', '::/0', false ),
			'IPv6 never matches an IPv4 range'    => array( '2804:14c::1', '0.0.0.0/0', false ),
			'unresolved never matches'            => array( '0.0.0.0', '0.0.0.0/0', false ),
			'an invalid entry never matches'      => array( '203.0.113.7', '203.0.113.*', false ),
			'an invalid address never matches'    => array( 'not-an-ip', '0.0.0.0/0', false ),
		);
	}

	/**
	 * @dataProvider match_cases
	 */
	public function test_an_address_matches_an_entry_by_range( string $ip, string $entry, bool $expected ): void {
		$this->assertSame( $expected, IpAddress::in_range( $ip, $entry ) );
	}

	public function test_a_fingerprint_follows_the_counting_key(): void {
		$fingerprint = IpAddress::fingerprint( '2804:14c:1:2::1' );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $fingerprint );
		$this->assertSame( $fingerprint, IpAddress::fingerprint( '2804:14C:1:2:ffff::7' ), 'one /64, one caller' );
		$this->assertNotSame( $fingerprint, IpAddress::fingerprint( '2804:14c:1:3::1' ) );
		$this->assertSame( IpAddress::fingerprint( '203.0.113.7' ), IpAddress::fingerprint( '::ffff:203.0.113.7' ) );
		$this->assertNotSame( IpAddress::fingerprint( '203.0.113.7' ), IpAddress::fingerprint( '203.0.113.8' ) );
	}

	public function test_a_fingerprint_is_keyed_not_a_plain_digest_of_the_address(): void {
		$fingerprint = IpAddress::fingerprint( '203.0.113.7' );

		foreach ( array( '203.0.113.7', 'v4:cb007107' ) as $input ) {
			foreach ( array( 'md5', 'sha1', 'sha256' ) as $algo ) {
				$this->assertNotSame( hash( $algo, $input ), $fingerprint, "not an unkeyed $algo of $input" );
			}
		}
	}

	public function test_unresolved_callers_share_one_fingerprint(): void {
		$this->assertSame( IpAddress::fingerprint( '0.0.0.0' ), IpAddress::fingerprint( '' ) );
		$this->assertNotSame( IpAddress::fingerprint( '0.0.0.0' ), IpAddress::fingerprint( '203.0.113.7' ) );
	}

	public function test_a_list_matches_when_any_entry_does(): void {
		$list = array( 'junk', 42, '198.51.100.0/24', '2804:14c::/32' );

		$this->assertTrue( IpAddress::in_any( '198.51.100.9', $list ) );
		$this->assertTrue( IpAddress::in_any( '2804:14c:9::1', $list ) );
		$this->assertFalse( IpAddress::in_any( '203.0.113.7', $list ) );
		$this->assertFalse( IpAddress::in_any( '203.0.113.7', array() ) );
	}
}
