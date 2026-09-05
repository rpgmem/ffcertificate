<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormEditorBuilderMetabox;
use FreeFormCertificate\Core\LabelSorter;

/**
 * Certificate form-editor field-type canonical map + display ordering.
 *
 * @covers \FreeFormCertificate\Admin\FormEditorBuilderMetabox
 */
class FormEditorFieldTypesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// `LabelSorter::locale()` is guarded by `function_exists( 'get_locale' )`,
		// so this used to take the no-WordPress branch only because nothing in
		// the process had defined that function yet. That is an accident of
		// file ordering, not a property of these tests: the moment any other
		// suite stubs `get_locale`, the guard passes here too and the call
		// lands unmocked. Pin it to the same locale the fallback would have
		// produced, so the behaviour under test is chosen rather than inherited.
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( '__' )->returnArg();
		// Preload for pcov coverage attribution (see CLAUDE.md testing notes).
		class_exists( '\\FreeFormCertificate\\Admin\\FormEditorBuilderMetabox' );
		class_exists( '\\FreeFormCertificate\\Core\\LabelSorter' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_field_type_labels_covers_exactly_the_supported_types(): void {
		$this->assertSame(
			FormEditorBuilderMetabox::FIELD_TYPES,
			array_keys( FormEditorBuilderMetabox::field_type_labels() ),
			'field_type_labels() must map every FIELD_TYPES slug (and no extras) so the select never renders a raw slug.'
		);
	}

	public function test_field_type_labels_are_non_empty_strings(): void {
		foreach ( FormEditorBuilderMetabox::field_type_labels() as $slug => $label ) {
			$this->assertIsString( $label, "Label for '{$slug}' must be a string." );
			$this->assertNotSame( '', $label, "Label for '{$slug}' must not be empty." );
		}
	}

	public function test_trailing_types_are_a_subset_of_field_types(): void {
		$this->assertSame(
			array(),
			array_diff( FormEditorBuilderMetabox::TRAILING_TYPES, FormEditorBuilderMetabox::FIELD_TYPES ),
			'every pinned display type must also be a declared field type.'
		);
	}

	public function test_field_types_ordered_preserves_the_full_type_set(): void {
		$ordered  = array_keys( FormEditorBuilderMetabox::field_types_ordered() );
		$expected = FormEditorBuilderMetabox::FIELD_TYPES;

		sort( $ordered );
		sort( $expected );
		$this->assertSame( $expected, $ordered, 'ordering must neither drop nor add a type.' );
	}

	public function test_field_types_ordered_pins_display_block_last(): void {
		$ordered = array_keys( FormEditorBuilderMetabox::field_types_ordered() );
		$tail    = array_slice( $ordered, -count( FormEditorBuilderMetabox::TRAILING_TYPES ) );

		$sorted_tail      = $tail;
		$sorted_trailing  = FormEditorBuilderMetabox::TRAILING_TYPES;
		sort( $sorted_tail );
		sort( $sorted_trailing );
		$this->assertSame(
			$sorted_trailing,
			$sorted_tail,
			'info/embed/hidden must occupy exactly the final positions of the select.'
		);
	}

	public function test_field_types_ordered_alphabetizes_input_block_by_label(): void {
		$ordered = FormEditorBuilderMetabox::field_types_ordered();
		$labels  = array_values( $ordered );
		$input   = array_slice( $labels, 0, count( $labels ) - count( FormEditorBuilderMetabox::TRAILING_TYPES ) );

		$expected = $input;
		usort( $expected, static fn( string $a, string $b ): int => LabelSorter::compare_labels( $a, $b ) );
		$this->assertSame( $expected, $input, 'input types must be alphabetized by their translated label.' );
	}

	public function test_field_types_ordered_alphabetizes_display_block_by_label(): void {
		$ordered = FormEditorBuilderMetabox::field_types_ordered();
		$labels  = array_values( $ordered );
		$tail    = array_slice( $labels, -count( FormEditorBuilderMetabox::TRAILING_TYPES ) );

		$expected = $tail;
		usort( $expected, static fn( string $a, string $b ): int => LabelSorter::compare_labels( $a, $b ) );
		$this->assertSame( $expected, $tail, 'the display block must be alphabetized among itself by label.' );
	}
}
