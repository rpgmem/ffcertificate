<?php
/**
 * Compile every `languages/*.po` into the `.mo` and the `.l10n.php`
 * WordPress reads.
 *
 *   php .github/scripts/ffc-compile-translations.php
 *
 * Edit the `.po` (by hand or in Loco), then run this; never edit a compiled
 * catalogue. `TranslationCatalogueAgreementTest` fails when the three files
 * disagree, so a `.po` change committed without recompiling is caught in CI;
 * this is what makes the fix one command instead of a hand-written
 * conversion each time.
 *
 * The `.mo` comes from GNU gettext's `msgfmt --check`. The `.l10n.php` is
 * written here in `wp i18n make-php`'s shape, byte for byte (#1721): `domain`
 * (WP-CLI reads it from the `.po`'s `X-Domain` header), then the headers in
 * FFC_L10N_HEADERS the `.po` carries, then `messages` in the `.po`'s order, on
 * one line. Loco writes the same shape, so a catalogue compiled here, saved
 * from Loco or made by WP-CLI is the same file. It reads the `.po` through the
 * reader the guards use, so the compiler and the guard cannot disagree about
 * what an entry is. Only translated, non-fuzzy entries are written, as
 * `msgfmt` does.
 *
 * @package FreeFormCertificate\Build
 */

declare(strict_types=1);

require_once __DIR__ . '/../../tests/Support/PoCatalogue.php';

use FreeFormCertificate\Tests\Support\PoCatalogue;

/**
 * The headers `wp i18n make-php` writes after `domain`, in its order.
 */
const FFC_L10N_HEADERS = array( 'plural-forms', 'language', 'project-id-version', 'pot-creation-date', 'po-revision-date', 'x-generator' );

$root  = dirname( __DIR__, 2 );
$files = glob( $root . '/languages/*.po' ) ?: array();
if ( array() === $files ) {
	fwrite( STDERR, "No .po file under languages/.\n" );
	exit( 1 );
}

foreach ( $files as $po ) {
	$mo = substr( $po, 0, -3 ) . '.mo';
	exec( 'msgfmt --check -o ' . escapeshellarg( $mo ) . ' ' . escapeshellarg( $po ) . ' 2>&1', $output, $status );
	if ( 0 !== $status ) {
		fwrite( STDERR, implode( "\n", $output ) . "\nmsgfmt failed on {$po} (is GNU gettext installed?).\n" );
		exit( 1 );
	}

	$messages = array();
	foreach ( PoCatalogue::read( $po ) as $entry ) {
		if ( PoCatalogue::is_translated( $entry ) ) {
			// A plural's forms travel as one value, separated by NUL.
			$messages[ $entry['key'] ] = implode( "\0", $entry['forms'] );
		}
	}

	$headers = ffc_po_headers( $po );
	$pairs   = array( "'domain'=>" . var_export( $headers['x-domain'] ?? null, true ) );
	foreach ( FFC_L10N_HEADERS as $name ) {
		if ( isset( $headers[ $name ] ) ) {
			$pairs[] = var_export( $name, true ) . '=>' . var_export( $headers[ $name ], true );
		}
	}
	$items = array();
	foreach ( $messages as $key => $value ) {
		// An integer-like key is written bare, as PHP and WP-CLI write it: `302=>`.
		$items[] = var_export( $key, true ) . '=>' . var_export( $value, true );
	}
	$pairs[] = "'messages'=>[" . implode( ',', $items ) . ']';

	file_put_contents( substr( $po, 0, -3 ) . '.l10n.php', "<?php\nreturn [" . implode( ',', $pairs ) . '];' );
	echo basename( $po ) . ': ' . count( $messages ) . " entries\n";
}

/**
 * The header entry of a `.po`, lower-cased names as WordPress keys them.
 *
 * @param string $po Path.
 * @return array<string, string>
 */
function ffc_po_headers( string $po ): array {
	$text = (string) file_get_contents( $po );
	if ( 1 !== preg_match( '/^msgid ""\s*\nmsgstr ((?:".*"\s*\n?)+)/m', $text, $m ) ) {
		return array();
	}
	preg_match_all( '/"(.*)"/', $m[1], $lines );
	$body    = stripcslashes( implode( '', $lines[1] ) );
	$headers = array();
	foreach ( explode( "\n", $body ) as $line ) {
		if ( str_contains( $line, ':' ) ) {
			[ $name, $value ]                        = explode( ':', $line, 2 );
			$headers[ strtolower( trim( $name ) ) ] = trim( $value );
		}
	}
	return $headers;
}
