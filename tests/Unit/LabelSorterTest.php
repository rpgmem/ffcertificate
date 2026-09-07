<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\LabelSorter;

/**
 * Tests for the locale-aware label ordering helper.
 *
 * @covers \FreeFormCertificate\Core\LabelSorter
 */
class LabelSorterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Core\\LabelSorter' );

		// `LabelSorter::locale()` is guarded by `function_exists( 'get_locale' )`,
		// so this used to take the no-WordPress branch only because nothing in
		// the process had defined that function yet. That is an accident of
		// file ordering, not a property of these tests: the moment any other
		// suite stubs `get_locale`, the guard passes here too and the call
		// lands unmocked. Pin it to the same locale the fallback would have
		// produced, so the behaviour under test is chosen rather than inherited.
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/*
	 * ---- compare_labels ------------------------------------------------
	 */

	public function test_compare_labels_orders_ascii_alphabetically(): void {
		$this->assertLessThan( 0, LabelSorter::compare_labels( 'Apple', 'Banana' ) );
		$this->assertGreaterThan( 0, LabelSorter::compare_labels( 'Banana', 'Apple' ) );
	}

	public function test_compare_labels_is_case_insensitive(): void {
		$this->assertSame( 0, LabelSorter::compare_labels( 'cache', 'Cache' ) );
	}

	public function test_compare_labels_treats_accents_as_their_base_letter(): void {
		// The whole point: "Água" must sort near "A", not after "Z".
		$this->assertLessThan( 0, LabelSorter::compare_labels( 'Água', 'Banana' ) );
		$this->assertLessThan( 0, LabelSorter::compare_labels( 'Geolocalização', 'Zona' ) );
		$this->assertGreaterThan( 0, LabelSorter::compare_labels( 'Água', 'Abacaxi' ) );
	}

	/*
	 * ---- compare_ascii (ext-intl-less fallback) ------------------------
	 */

	public function test_compare_ascii_folds_accents(): void {
		$this->assertSame( 0, LabelSorter::compare_ascii( 'Água', 'Agua' ) );
		$this->assertSame( 0, LabelSorter::compare_ascii( 'Configurações', 'configuracoes' ) );
	}

	public function test_compare_ascii_is_case_insensitive_and_ordered(): void {
		$this->assertSame( 0, LabelSorter::compare_ascii( 'SMTP', 'smtp' ) );
		$this->assertLessThan( 0, LabelSorter::compare_ascii( 'Ável', 'Banana' ) );
		$this->assertGreaterThan( 0, LabelSorter::compare_ascii( 'Zona', 'Água' ) );
	}

	/*
	 * ---- sort ----------------------------------------------------------
	 */

	/** @return array<string, string> id => label */
	private function tabs(): array {
		return array(
			'general'       => 'Geral',
			'smtp'          => 'SMTP',
			'cache'         => 'Cache',
			'geolocation'   => 'Geolocalização',
			'advanced'      => 'Avançado',
			'migrations'    => 'Migrações',
			'documentation' => 'Documentação',
		);
	}

	public function test_sort_pins_head_and_tail_and_alphabetizes_middle(): void {
		$sorted = LabelSorter::sort(
			$this->tabs(),
			static fn( string $label ): string => $label,
			array( 'general' ),
			array( 'advanced', 'migrations', 'documentation' )
		);

		$this->assertSame(
			array( 'general', 'cache', 'geolocation', 'smtp', 'advanced', 'migrations', 'documentation' ),
			array_keys( $sorted )
		);
	}

	public function test_sort_preserves_key_to_value_mapping(): void {
		$sorted = LabelSorter::sort(
			$this->tabs(),
			static fn( string $label ): string => $label,
			array( 'general' ),
			array( 'advanced', 'migrations', 'documentation' )
		);

		$this->assertSame( 'Cache', $sorted['cache'] );
		$this->assertSame( 'Geral', $sorted['general'] );
	}

	public function test_sort_with_no_anchors_is_pure_alphabetical(): void {
		$sorted = LabelSorter::sort(
			array(
				'b' => 'Banana',
				'a' => 'Abacaxi',
				'c' => 'Água',
			),
			static fn( string $label ): string => $label
		);

		$this->assertSame( array( 'a', 'c', 'b' ), array_keys( $sorted ) );
	}

	public function test_sort_uses_key_resolver_for_object_items(): void {
		$items = array(
			(object) array( 'id' => 'notices', 'label' => 'Convocações' ),
			(object) array( 'id' => 'settings', 'label' => 'Configurações' ),
			(object) array( 'id' => 'reasons', 'label' => 'Motivos' ),
			(object) array( 'id' => 'adjutancies', 'label' => 'Adjutorias' ),
		);

		$sorted = LabelSorter::sort(
			$items,
			static fn( object $item ): string => $item->label,
			array( 'notices' ),
			array( 'settings' ),
			static fn( object $item ): string => $item->id
		);

		$this->assertSame(
			array( 'notices', 'adjutancies', 'reasons', 'settings' ),
			array_map( static fn( object $item ): string => $item->id, array_values( $sorted ) )
		);
	}

	public function test_sort_skips_anchor_ids_that_are_absent(): void {
		$sorted = LabelSorter::sort(
			array(
				'cache' => 'Cache',
				'smtp'  => 'SMTP',
			),
			static fn( string $label ): string => $label,
			array( 'general' ),            // absent — must be skipped, not error
			array( 'documentation' )       // absent — must be skipped
		);

		$this->assertSame( array( 'cache', 'smtp' ), array_keys( $sorted ) );
	}

	public function test_sort_is_stable_on_equal_labels(): void {
		$items = array(
			'first'  => 'Same',
			'second' => 'Same',
			'third'  => 'Same',
		);

		$sorted = LabelSorter::sort( $items, static fn( string $label ): string => $label );

		$this->assertSame( array( 'first', 'second', 'third' ), array_keys( $sorted ) );
	}
}
