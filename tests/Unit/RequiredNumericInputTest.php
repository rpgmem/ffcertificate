<?php
/**
 * Every admin number input demands a value, or says why it does not.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guard over `<input type="number">` across the plugin (#1114, #1115, #1117).
 *
 * A number input without `required` posts the empty string when cleared, and
 * `absint( '' )` is 0. What that 0 then means is per-consumer and was never
 * uniform: on the Rate Limit tab it read as "limit already reached" and barred
 * every submission from every address (#1114); on a reregistration campaign it
 * collapsed the reminder onto the last day (#1117). The attribute is the cheap
 * half of the fix — the server-side half lives with each save handler.
 *
 * The exceptions are the point of this file. Three shapes recur, and each is
 * a reason `required` would be a *bug* rather than a missing safeguard:
 *
 *   1. Empty is a documented value — "inherit the global default", "no limit".
 *      The save handler deletes the meta so the read side falls back.
 *   2. The control is not a form field at all: no `name`, read by JS. It is
 *      still a candidate for constraint validation, so a `required` there
 *      would block the surrounding form while never being submitted.
 *   3. Requiredness belongs to the field's own definition, not to the markup.
 *
 * It is a ratchet: an unlisted input without `required` fails, and so does a
 * listed one that gained `required` or disappeared — the exception list must
 * not outlive what it excuses.
 */
class RequiredNumericInputTest extends TestCase {

	/**
	 * Inputs that must NOT carry `required`, each with the reason.
	 *
	 * @var array<string, string>
	 */
	private const KNOWN_OPTIONAL = array(
		// 1. Empty means "inherit from global" — `save_device_limit_meta()`
		// deletes the meta so `get_device_effective_settings()` falls back at
		// read time. `required` would remove the only way to say "inherit".
		'includes/admin/class-ffc-form-editor-device-limit-metabox.php::ffc_device_limit[max]'       => 'inherit-from-global',
		'includes/admin/class-ffc-form-editor-device-limit-metabox.php::ffc_device_limit[threshold]' => 'inherit-from-global',
		'includes/admin/class-ffc-form-editor-device-limit-metabox.php::ffc_device_limit[strong_min]' => 'inherit-from-global',
		'includes/admin/class-ffc-form-editor-public-csv-download-metabox.php::ffc_csv_public[limit]' => 'inherit-from-global',

		// Same shape, different wording: the field says "Leave empty for no
		// limit", and the booking guard reads a missing limit as unlimited.
		'includes/audience/class-ffc-audience-admin-calendar.php::schedule_future_days'              => 'empty means no limit',

		// 2. Not a form field. `ffc_qty_codes` has no `name` — it is the
		// argument to the "Generate Tickets" AJAX button — and it sits inside
		// a `.ffc-collapsed-target` block. A `required` here would block
		// saving the form editor while never reaching the server.
		'includes/admin/class-ffc-form-editor-restriction-metabox.php::ffc_qty_codes'                => 'JS control, no name',

		// The "add a new location" row of the geolocation tab, inside the same
		// <form> as every other setting on it: required there would refuse to
		// save the tab unless a new location were being added (caught in #1115
		// before it shipped).
		'includes/settings/views/ffc-tab-geolocation.php::ffc_location_new[lat]'                     => 'empty add-row',
		'includes/settings/views/ffc-tab-geolocation.php::ffc_location_new[lng]'                     => 'empty add-row',
		'includes/settings/views/ffc-tab-geolocation.php::ffc_location_new[radius]'                  => 'empty add-row',

		// 3. User-defined custom fields: whether one is required is a property
		// of the field definition, not of this markup. Both renderers now
		// emit `required` from `is_required` (#1117 catalogued the gap,
		// #1120 closed it on the user-profile side) — they stay listed
		// because the attribute is written through a PHP expression, which
		// the scanner blanks along with every other PHP block, so it can
		// never see one here. A hardcoded attribute would be the bug.
		'includes/admin/class-ffc-admin-user-custom-fields.php::#1'                                  => 'per-field is_required',
		'includes/reregistration/class-ffc-reregistration-form-renderer.php::%s'                     => 'per-field is_required',
	);

	/**
	 * Every number input, keyed as `path::name-or-id`.
	 *
	 * PHP blocks are blanked before the tag is matched: an `<?php echo … ?>`
	 * inside an attribute carries a `>` that truncates the tag otherwise, and
	 * a scan that truncates reports fields as unbounded that are not.
	 *
	 * @return array<string, bool> Key to "carries required".
	 */
	private function scan(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array();
		foreach ( array( 'includes', 'templates' ) as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir ) );
			foreach ( $it as $file ) {
				if ( $file->isFile() && 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}
		sort( $files );

		$found = array();
		foreach ( $files as $path ) {
			$src = (string) file_get_contents( $path );
			$src = (string) preg_replace_callback(
				'/<\?php.*?\?>/s',
				static fn( array $m ): string => str_repeat( ' ', strlen( $m[0] ) ),
				$src
			);

			preg_match_all( '/<input\b[^>]*>/s', $src, $matches );
			$index = 0;
			foreach ( $matches[0] as $tag ) {
				$tag = (string) preg_replace( '/\s+/', ' ', $tag );
				if ( ! str_contains( $tag, 'type="number"' ) ) {
					continue;
				}
				// A bare `<input type="number">` inside a docblock is prose.
				if ( '<input type="number">' === $tag ) {
					continue;
				}
				++$index;

				$name = '';
				if ( preg_match( '/name="([^"]*)"/', $tag, $m ) || preg_match( '/id="([^"]*)"/', $tag, $m ) ) {
					$name = trim( (string) preg_replace( '/\s+/', ' ', $m[1] ) );
				}
				if ( '' === $name ) {
					$name = '#' . $index;
				}

				$key           = str_replace( $root . '/', '', $path ) . '::' . $name;
				$found[ $key ] = str_contains( $tag, 'required' );
			}
		}

		return $found;
	}

	public function test_the_scan_sees_the_whole_population(): void {
		$found = $this->scan();

		// A regex that truncates on the `>` inside a PHP block silently finds
		// far fewer, and every assertion below would then pass vacuously.
		$this->assertGreaterThan( 60, count( $found ), 'The scan collapsed — check the PHP-block blanking.' );
	}

	public function test_every_number_input_requires_a_value_or_is_listed(): void {
		$unlisted = array();
		foreach ( $this->scan() as $key => $has_required ) {
			if ( ! $has_required && ! isset( self::KNOWN_OPTIONAL[ $key ] ) ) {
				$unlisted[] = $key;
			}
		}

		$this->assertSame(
			array(),
			$unlisted,
			"A number input accepts an empty value. Add `required`, or list it in KNOWN_OPTIONAL with the reason:\n  " . implode( "\n  ", $unlisted )
		);
	}

	public function test_the_exception_list_does_not_outlive_what_it_excuses(): void {
		$found = $this->scan();
		$stale = array();
		foreach ( array_keys( self::KNOWN_OPTIONAL ) as $key ) {
			if ( ! isset( $found[ $key ] ) ) {
				$stale[] = $key . ' (gone)';
			} elseif ( $found[ $key ] ) {
				$stale[] = $key . ' (now required)';
			}
		}

		$this->assertSame(
			array(),
			$stale,
			"An exception no longer describes reality — drop it from KNOWN_OPTIONAL:\n  " . implode( "\n  ", $stale )
		);
	}
}
