<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Core\PersonName;
use PHPUnit\Framework\TestCase;

/**
 * The one split of a full name into WordPress's first and last name (#1552).
 *
 * @covers \FreeFormCertificate\Core\PersonName
 */
class PersonNameTest extends TestCase {

	/**
	 * @dataProvider splits
	 * @param string $full  Full name.
	 * @param string $first Expected first name.
	 * @param string $last  Expected last name.
	 */
	public function test_split_takes_the_first_word_and_the_rest( string $full, string $first, string $last ): void {
		$this->assertSame( array( 'first' => $first, 'last' => $last ), PersonName::split( $full ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function splits(): array {
		return array(
			'two words'          => array( 'Alex Meusburger', 'Alex', 'Meusburger' ),
			'particles stay'     => array( 'Maria da Silva Santos', 'Maria', 'da Silva Santos' ),
			'extra whitespace'   => array( "  Maria \t da   Silva ", 'Maria', 'da Silva' ),
			'non-breaking space' => array( "Maria\u{00A0}Silva", 'Maria', 'Silva' ),
			'one word'           => array( 'Cher', 'Cher', '' ),
			'empty'              => array( '   ', '', '' ),
		);
	}

	public function test_join_is_the_inverse_of_split_on_a_normalised_name(): void {
		$parts = PersonName::split( ' Maria  da Silva ' );

		$this->assertSame( 'Maria da Silva', PersonName::join( $parts['first'], $parts['last'] ) );
		$this->assertSame( 'Cher', PersonName::join( 'Cher', '' ) );
		$this->assertSame( '', PersonName::join( '', '' ) );
	}
}
