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
}
