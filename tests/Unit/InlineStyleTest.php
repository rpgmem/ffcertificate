<?php
/**
 * Ratchet on CSS written outside a stylesheet (#1837).
 *
 * An inline declaration bypasses the whole stylesheet architecture at once:
 * every CSS guard reads `assets/css/` and none of them sees it, it beats the
 * tokenized class on specificity (CLAUDE.md "Theme", item 6), and so it does
 * not follow the dark theme. Nothing stopped a new one from landing -- #1836
 * found a `style="margin-top: 30px;"` on the dashboard headings by reading.
 *
 * The audit gave every occurrence a verdict. The ones that belonged in a sheet
 * moved there, the dead ones were deleted, and what is left is frozen HERE,
 * per file, each with its reason. A ratchet both ways, in the
 * `OpacityContrastTest` mould: a new occurrence fails (move it to the screen's
 * sheet, or argue it into the register), and an entry that vanished fails too
 * (the win gets locked in).
 *
 * What counts and what does not -- comments, `display` toggles, the three
 * template directories that are inline by design -- is written once, in
 * `Tests\Support\InlineStyles`, which is the scan this freezes.
 *
 * It sees presence, never truth: a wrong reason passes. And it cannot see an
 * inline style that a PHP variable assembles out of words the patterns do not
 * know -- the `$style` that `BadgeHtml` concatenates is found because the
 * attribute name is a literal, not because its value is.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\InlineStyles;
use FreeFormCertificate\Tests\Support\JsIdSelectors;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class InlineStyleTest extends TestCase {

	// ------------------------------------------------------------------
	// The reasons. One sentence each, shared, so the register reads as a
	// list of decisions rather than a list of excuses.
	// ------------------------------------------------------------------

	private const INITIAL_STATE = 'initial state of an element a script shows: jQuery .show() restores the sheet\'s display only when the hide is inline -- with a class or `hidden` it writes the tag\'s default (block for a flex modal), and a theme\'s `[hidden] { display: none !important }` would beat it outright';

	private const OPERATOR_COLOUR = 'a colour an operator picked (audience, environment, reason, badge row), arriving as data; no token can carry it -- a custom property where a sheet reads it, ContrastColor where text sits on it';

	private const EMAIL_BODY = 'part of an e-mail body; Gmail and Outlook ignore external sheets, the same reason templates/emails/ is excluded';

	private const PDF_DOCUMENT = 'part of a certificate, record or receipt document rasterised by html2canvas; no plugin sheet reaches the canvas';

	private const QUOTED_EXAMPLE = 'HTML quoted as an example in a documentation topic, escaped text a reader copies into a certificate body -- not an attribute of the page';

	private const RUNTIME_VALUE = 'a value computed at run time (a progress width, a scale that fits the viewport, a position under a button, an autosize height)';

	private const OWN_DOCUMENT = 'CSS for a document of its own -- an iframe preview or the e-mail preview -- which no stylesheet of the page reaches';

	private const SAMPLE_LINK = 'the sample validation link written into a certificate preview, mirroring what ValidationUrlPlaceholders writes into the real document';

	private const SELF_CONTAINED = 'the PDF generator draws its overlay, error box and rasterising container itself: it also runs on the standalone appointment receipt, which loads no plugin stylesheet';

	private const TRANSIENT_STATE = 'a busy state set and cleared around one request (the row or link being processed), never the resting state anyone reads';

	private const SCROLL_LOCK = 'scroll lock on the host document\'s body while a modal is open; the body belongs to the theme, so no sheet of ours should style it';

	private const OFFSCREEN = 'a throwaway helper element (clipboard textarea, print iframe) created and removed in the same task; it must not depend on a sheet being loaded';

	private const PRINT_DOCUMENT = 'the stylesheet of the QR print window, a document the script writes from scratch';

	private const NOSCRIPT = 'inside <noscript>: reveals the no-JS fallback, which a stylesheet cannot do because it cannot know scripts are off and we do not own <html> to carry a no-js class';

	private const GENERATED = 'generated on purpose from settings or the icon registry (menu mask, recruitment badge palette, public design, holiday colours), attached to the sheet it extends';

	private const OPTION_FILTER = 'the initial hidden state of a reason <option> the script filters, and the very state it reads back (`style.display === "none"`)';

	private const RECEIPT_PAGE = 'the standalone printable appointment receipt carries its own <style>: it is a whole document, printed, with no plugin stylesheet';

	/**
	 * Every inline occurrence left, per file, with how many and why.
	 *
	 * File => `kind: text` => array( count, reason ). The text is what the scan
	 * reads up to the first quote, so a value assembled at run time shows only
	 * its literal head.
	 *
	 * @var array<string, array<string, array{0: int, 1: string}>>
	 */
	private const ALLOWED = array(
		'assets/js/ffc-admin-field-builder.js' => array(
			'.css(): .css({' => array( 1, self::RUNTIME_VALUE ),
		),
		'assets/js/ffc-admin-migrations.js' => array(
			'.css(): .css("width", percent + "%"' => array( 1, self::RUNTIME_VALUE ),
		),
		'assets/js/ffc-admin-submissions-bulk.js' => array(
			'.css(): .css("opacity", "0.55"' => array( 1, self::TRANSIENT_STATE ),
			'.css(): .css("opacity", ""'     => array( 1, self::TRANSIENT_STATE ),
		),
		'assets/js/ffc-audience-admin.js' => array(
			'.css(): .css("pointer-events", "none"' => array( 1, self::TRANSIENT_STATE ),
			'.css(): .css("opacity", "0.5"'         => array( 1, self::TRANSIENT_STATE ),
			'.css(): .css("pointer-events", ""'     => array( 1, self::TRANSIENT_STATE ),
			'.css(): .css("opacity", ""'            => array( 1, self::TRANSIENT_STATE ),
		),
		'assets/js/ffc-audience-bookings.js' => array(
			'style=: style="border-left: 4px solid' => array( 1, self::OPERATOR_COLOUR ),
			'style=: style="background-color:'      => array( 1, self::OPERATOR_COLOUR ),
		),
		'assets/js/ffc-audience-calendar.js' => array(
			'style=: style="border-left: 3px solid' => array( 1, self::OPERATOR_COLOUR ),
			'style=: style="background-color:'      => array( 2, self::OPERATOR_COLOUR ),
			'style=: style="--ffc-color:'           => array( 1, self::OPERATOR_COLOUR ),
		),
		'assets/js/ffc-audience-transfer-list.js' => array(
			'.css(): .css("background", a.color' => array( 1, self::OPERATOR_COLOUR ),
		),
		'assets/js/ffc-batched-export.js' => array(
			'style=: style="width:0%'         => array( 1, self::RUNTIME_VALUE ),
			'.css(): .css("width", pct + "%"' => array( 1, self::RUNTIME_VALUE ),
		),
		'assets/js/ffc-calendar-frontend.js' => array(
			'.css(): .css("overflow", "hidden"' => array( 1, self::SCROLL_LOCK ),
			'.css(): .css("overflow", ""'       => array( 2, self::SCROLL_LOCK ),
		),
		'assets/js/ffc-email-model.js' => array(
			'style=: style="display:inline-block;max-width:'               => array( 1, self::OWN_DOCUMENT ),
			'style=: style="font-size:22px;font-weight:600;color:'         => array( 1, self::OWN_DOCUMENT ),
			'style=: style="margin:0 0 16px;color:#0073aa;font-size:22px;' => array( 1, self::OWN_DOCUMENT ),
			'style=: style="margin:0 0 12px;'                              => array( 1, self::OWN_DOCUMENT ),
			'style=: style="margin:0;'                                     => array( 1, self::OWN_DOCUMENT ),
			'style=: style="margin:0;padding:0;background-color:'          => array( 1, self::OWN_DOCUMENT ),
			'style=: style="background-color:'                             => array( 4, self::OWN_DOCUMENT ),
			'style=: style="width:'                                        => array( 1, self::OWN_DOCUMENT ),
			'<style>: <style'                                              => array( 1, self::OWN_DOCUMENT ),
		),
		'assets/js/ffc-frontend.js' => array(
			'.css(): .css({'                                                                         => array( 1, self::OFFSCREEN ),
			'.style.setProperty: .style.setProperty("height", "auto", "important"'                   => array( 1, self::RUNTIME_VALUE ),
			'.style.setProperty: .style.setProperty("height", this.scrollHeight + "px", "important"' => array( 1, self::RUNTIME_VALUE ),
		),
		'assets/js/ffc-pdf-generator.js' => array(
			'style=: style="font-weight:600;margin:0 0 12px'                                        => array( 1, self::SELF_CONTAINED ),
			'style=: style="width:100%;height:100%;position:relative;'                              => array( 1, self::SELF_CONTAINED ),
			'style=: style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover' => array( 1, self::SELF_CONTAINED ),
			'style=: style="position:relative;z-index:1;'                                           => array( 1, self::SELF_CONTAINED ),
			'<style>: <style id="ffc-spinner-animation"'                                            => array( 1, self::SELF_CONTAINED ),
			'.css(): .css({'                                                                        => array( 16, self::SELF_CONTAINED ),
			'.css(): .css("opacity", "1"'                                                           => array( 1, self::SELF_CONTAINED ),
			'.style.x =: .style.cssText = "font-family:-apple-system,BlinkMacSystemFont,sans'       => array( 1, self::SELF_CONTAINED ),
		),
		'assets/js/ffc-qr-generator.js' => array(
			'<style>: <style'                       => array( 1, self::PRINT_DOCUMENT ),
			'.style.x =: .style.position = "fixed"' => array( 2, self::OFFSCREEN ),
			'.style.x =: .style.opacity = "0"'      => array( 1, self::OFFSCREEN ),
			'.style.x =: .style.width = "0"'        => array( 1, self::OFFSCREEN ),
			'.style.x =: .style.height = "0"'       => array( 1, self::OFFSCREEN ),
			'.style.x =: .style.border = "0"'       => array( 1, self::OFFSCREEN ),
		),
		'assets/js/ffc-reregistration-frontend.js' => array(
			'style=: style="display:none;' => array( 1, self::INITIAL_STATE ),
		),
		'assets/js/ffc-screenshots.js' => array(
			'.style.x =: .style.transform = "translate(" + x + "px, " + y + "px) scale(" + sca' => array( 1, self::RUNTIME_VALUE ),
		),
		'assets/js/ffc-user-dashboard-audience-join.js' => array(
			'style=: style="background-color:' => array( 1, self::OPERATOR_COLOUR ),
		),
		'assets/js/ffc-user-dashboard-cal-export.js' => array(
			'.css(): .css({' => array( 1, self::RUNTIME_VALUE ),
		),
		'assets/js/ffc-user-dashboard-core.js' => array(
			'style=: style="--ffc-badge-row-bg:' => array( 1, self::OPERATOR_COLOUR ),
		),
		'assets/js/ffc-user-dashboard-profile.js' => array(
			'style=: style="display: none;' => array( 1, self::INITIAL_STATE ),
		),
		'includes/admin/class-ffc-admin-menu-icons.php' => array(
			'wp_add_inline_style: wp_add_inline_style( "admin-menu"' => array( 1, self::GENERATED ),
		),
		'includes/admin/class-ffc-admin-user-capabilities.php' => array(
			'style=: style="--ffc-cap-chip-color:' => array( 1, self::OPERATOR_COLOUR ),
		),
		'includes/admin/class-ffc-admin-user-custom-fields.php' => array(
			'style=: style="--ffc-color: <?php echo esc_attr( $audience->color ); ?>;' => array( 1, self::OPERATOR_COLOUR ),
		),
		'includes/generators/class-ffc-form-layout-preview.php' => array(
			'style=: style = "position:relative;width:'                                                    => array( 1, self::OWN_DOCUMENT ),
			'style=: style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover'          => array( 1, self::OWN_DOCUMENT ),
			'style=: style="'                                                                                 => array( 1, self::OWN_DOCUMENT ),
		),
		'includes/admin/class-ffc-cert-template-admin-screen.php' => array(
			'<style>: <style' => array( 1, self::OWN_DOCUMENT ),
		),
		'includes/admin/class-ffc-submissions-list.php' => array(
			'style=: style="display: none;' => array( 1, self::INITIAL_STATE ),
		),
		'includes/audience/class-ffc-audience-admin-audience-renderer.php' => array(
			'style=: style="--ffc-color: <?php echo esc_attr( $audience->color ); ?>;' => array( 2, self::OPERATOR_COLOUR ),
			'style=: style="--ffc-color: <?php echo esc_attr( $ancestor->color ); ?>;' => array( 1, self::OPERATOR_COLOUR ),
			'style=: style="display:none;'                                             => array( 9, self::INITIAL_STATE ),
		),
		'includes/audience/class-ffc-audience-admin-environment.php' => array(
			'style=: style="--ffc-color: <?php echo esc_attr( $env->color ??' => array( 1, self::OPERATOR_COLOUR ),
		),
		'includes/audience/class-ffc-audience-notification-handler.php' => array(
			'style=: style="background:#f0f6fc;padding:15px;border-radius:4px;margin:20px 0;' => array( 1, self::EMAIL_BODY ),
			'style=: style="margin:8px 0;'                                                    => array( 1, self::EMAIL_BODY ),
			'style=: style="font-weight:600;'                                                 => array( 1, self::EMAIL_BODY ),
		),
		'includes/audience/class-ffc-audience-shortcode.php' => array(
			'style=: style="display: none;' => array( 2, self::INITIAL_STATE ),
		),
		'includes/core/captcha/class-ffc-composite-captcha.php' => array(
			'<style>: <style' => array( 1, self::NOSCRIPT ),
		),
		'includes/core/class-ffc-badge-html.php' => array(
			'style=: style="' => array( 1, self::OPERATOR_COLOUR ),
		),
		'includes/core/class-ffc-email-helper-trait.php' => array(
			'style=: style="text-align: center; margin: 30px 0;'                                    => array( 1, self::EMAIL_BODY ),
			'style=: style="margin: 0 0 10px 0; font-size: 14px; color: #666;'                      => array( 1, self::EMAIL_BODY ),
			'style=: style="display: inline-block; background:'                                     => array( 1, self::EMAIL_BODY ),
			'style=: style="border-collapse:collapse; width:100%; font-family: sans-serif; border:' => array( 1, self::EMAIL_BODY ),
			'style=: style="background:#f9f9f9; width:30%; font-weight: bold; border: 1px solid #d' => array( 1, self::EMAIL_BODY ),
			'style=: style="border: 1px solid #ddd;'                                                => array( 1, self::EMAIL_BODY ),
		),
		'includes/core/class-ffc-public-design.php' => array(
			'<style>: <style id="ffc-public-design"' => array( 1, self::GENERATED ),
		),
		'includes/date-messages/class-ffc-message-builder.php' => array(
			'style=: style="margin: 20px 0 0 0; font-size: 12px; color: #646970;' => array( 1, self::EMAIL_BODY ),
		),
		'includes/generators/class-ffc-pdf-html-renderer.php' => array(
			'style=: style="text-align:center; padding: 50px;'                                      => array( 1, self::PDF_DOCUMENT ),
			'style=: style="width:1123px;height:794px;padding:60px;box-sizing:border-box;font-fami' => array( 1, self::PDF_DOCUMENT ),
			'style=: style="text-align:center;color:#0073aa'                                        => array( 1, self::PDF_DOCUMENT ),
		),
		'includes/generators/class-ffc-qrcode-generator.php' => array(
			'style=: style="width:%dpx !important; height:%dpx !important; display:block !importan' => array( 1, self::PDF_DOCUMENT ),
		),
		'includes/generators/class-ffc-validation-url-placeholders.php' => array(
			'style=: style="color:' => array( 1, self::PDF_DOCUMENT ),
		),
		'includes/integrations/class-ffc-email-handler.php' => array(
			'style=: style="border-collapse:collapse; width:100%; font-family: sans-serif; border:' => array( 1, self::EMAIL_BODY ),
			'style=: style="background:#f9f9f9; width:30%; font-weight: bold; border: 1px solid #d' => array( 1, self::EMAIL_BODY ),
			'style=: style="border: 1px solid #ddd;'                                                => array( 1, self::EMAIL_BODY ),
		),
		'includes/recruitment/class-ffc-recruitment-badge-palette.php' => array(
			'wp_add_inline_style: wp_add_inline_style( $handle' => array( 1, self::GENERATED ),
		),
		'includes/recruitment/class-ffc-recruitment-notice-edit-page-renderer.php' => array(
			'style=: style="' => array( 1, self::OPTION_FILTER ),
		),
		'includes/recruitment/class-ffc-recruitment-public-shortcode-renderer.php' => array(
			'style=: style="background:%2$s;color:%3$s;' => array( 1, self::OPERATOR_COLOUR ),
		),
		'includes/recruitment/class-ffc-recruitment-reasons-list-table.php' => array(
			'style=: style="--ffc-color:%s' => array( 1, self::OPERATOR_COLOUR ),
		),
		'includes/reregistration/class-ffc-record-generator.php' => array(
			'style=: style="padding:1px 4px;border:1px solid #ccc;text-align:center;font-size:7.5p' => array( 2, self::PDF_DOCUMENT ),
			'style=: style="width:100%;border-collapse:collapse;margin-top:2px'                     => array( 1, self::PDF_DOCUMENT ),
			'style=: style="margin-bottom: 6px'                                                     => array( 1, self::PDF_DOCUMENT ),
			'style=: style="font-size: 9pt;font-weight: bold;color: #000;text-transform: uppercase' => array( 1, self::PDF_DOCUMENT ),
			'style=: style="width: 100%;border-collapse: collapse;font-size: 8pt'                   => array( 1, self::PDF_DOCUMENT ),
			'style=: style="padding: 2px 0;font-weight: bold;color: #666;width: 120px;vertical-ali' => array( 1, self::PDF_DOCUMENT ),
			'style=: style="padding: 2px 0;color: #222'                                             => array( 1, self::PDF_DOCUMENT ),
			'style=: style="width:794px;height:1123px;padding:60px;box-sizing:border-box;font-fami' => array( 1, self::PDF_DOCUMENT ),
			'style=: style="text-align:center;color:#0073aa'                                        => array( 1, self::PDF_DOCUMENT ),
		),
		'includes/reregistration/class-ffc-reregistration-custom-fields-page.php' => array(
			'style=: style="--ffc-color:<?php echo esc_attr( $audience->color ); ?>' => array( 1, self::OPERATOR_COLOUR ),
		),
		'includes/scheduling/class-ffc-closed-date-kind.php' => array(
			'wp_add_inline_style: wp_add_inline_style( $handle' => array( 1, self::GENERATED ),
		),
		'includes/self-scheduling/class-ffc-self-scheduling-appointment-email-handler.php' => array(
			'style=: style="margin: 20px 0;'                                    => array( 2, self::EMAIL_BODY ),
			'style=: style="margin: 0 0 5px 0; font-weight: bold; color: #666;' => array( 2, self::EMAIL_BODY ),
			'style=: style="margin: 0; color: #333;'                            => array( 2, self::EMAIL_BODY ),
		),
		'includes/self-scheduling/class-ffc-self-scheduling-appointment-receipt-handler.php' => array(
			'<style>: <style' => array( 1, self::RECEIPT_PAGE ),
		),
		'includes/self-scheduling/class-ffc-self-scheduling-editor.php' => array(
			'style=: style="display:none;' => array( 2, self::INITIAL_STATE ),
		),
		'includes/self-scheduling/class-ffc-self-scheduling-shortcode.php' => array(
			'style=: style="display: none;' => array( 4, self::INITIAL_STATE ),
		),
		'includes/settings/views/documentation/feature-certificates.php' => array(
			'style=: style="text-align: center; font-family: Arial; padding: 50px;'      => array( 1, self::QUOTED_EXAMPLE ),
			'style=: style="font-family: Arial; padding: 30px;'                          => array( 1, self::QUOTED_EXAMPLE ),
			'style=: style="text-align: center;'                                         => array( 2, self::QUOTED_EXAMPLE ),
			'style=: style="font-size: 10pt;'                                            => array( 1, self::QUOTED_EXAMPLE ),
			'style=: style="text-align: right;'                                          => array( 1, self::QUOTED_EXAMPLE ),
			'style=: style="text-align: center; margin-top: 40px;'                       => array( 1, self::QUOTED_EXAMPLE ),
			'style=: style="font-size: 20pt;'                                            => array( 1, self::QUOTED_EXAMPLE ),
			'style=: style="text-align: center; margin: 40px 0; font-size: 12pt;'        => array( 1, self::QUOTED_EXAMPLE ),
			'style=: style="margin-top: 60px;'                                           => array( 2, self::QUOTED_EXAMPLE ),
			'style=: style="border-top: 1px solid #000; width: 200px; margin: 5px auto;' => array( 1, self::QUOTED_EXAMPLE ),
			'style=: style="font-size: 9pt;'                                             => array( 1, self::QUOTED_EXAMPLE ),
			'style=: style="font-size: 9pt; vertical-align: middle;'                     => array( 1, self::QUOTED_EXAMPLE ),
		),
		'includes/settings/views/documentation/operations-troubleshooting.php' => array(
			'style=: style="font-weight: bold;' => array( 1, self::QUOTED_EXAMPLE ),
		),
		'includes/settings/views/documentation/reference-html-styling.php' => array(
			'style=: style="font-size: 14pt;'                => array( 1, self::QUOTED_EXAMPLE ),
			'style=: style="color: #2271b1;'                 => array( 1, self::QUOTED_EXAMPLE ),
			'style=: style="text-align: center;'             => array( 1, self::QUOTED_EXAMPLE ),
			'style=: style="background-color: #f0f0f0;'      => array( 1, self::QUOTED_EXAMPLE ),
			'style=: style="margin: 20px; padding: 15px;'    => array( 1, self::QUOTED_EXAMPLE ),
			'style=: style="font-family: Arial, sans-serif;' => array( 1, self::QUOTED_EXAMPLE ),
			'style=: style="border: 2px solid #000;'         => array( 1, self::QUOTED_EXAMPLE ),
		),
		'includes/settings/views/ffc-tab-migrations.php' => array(
			'style=: style="width: <?php echo esc_attr( number_format( $ffcertificate_percent, 1 )' => array( 1, self::RUNTIME_VALUE ),
		),
		'includes/shortcodes/class-ffc-dashboard-shortcode.php' => array(
			'style=: style="display:none;' => array( 1, self::INITIAL_STATE ),
		),
		'templates/admin/audience/audience-tab.php' => array(
			'style=: style="display:none;' => array( 2, self::INITIAL_STATE ),
		),
		'templates/admin/audience/self-scheduling-tab.php' => array(
			'style=: style="display:none;' => array( 2, self::INITIAL_STATE ),
		),
		'templates/admin/recruitment/notice-edit/classifications-section.php' => array(
			'style=: style="display:' => array( 2, self::INITIAL_STATE ),
		),
		'templates/admin/reregistration/list-row.php' => array(
			'style=: style="--ffc-color:<?php echo esc_attr( $aud->color ); ?>' => array( 1, self::OPERATOR_COLOUR ),
		),
		'templates/admin/reregistration/submissions.php' => array(
			'style=: style="display:none;' => array( 1, self::INITIAL_STATE ),
		),
		'templates/admin/settings/email-body-hub.php' => array(
			'style=: style="display:none;' => array( 1, self::INITIAL_STATE ),
			'<style>: <style'              => array( 1, self::NOSCRIPT ),
		),
		'templates/admin/url-shortener/short-urls-page.php' => array(
			'style=: style="display:none;' => array( 3, self::INITIAL_STATE ),
		),
	);

	/**
	 * No new inline CSS enters without a written reason.
	 */
	public function test_no_new_inline_style(): void {
		$new = array();

		foreach ( InlineStyles::found() as $file => $entries ) {
			foreach ( $entries as $key => $count ) {
				$allowed = self::ALLOWED[ $file ][ $key ][0] ?? 0;
				if ( $count > $allowed ) {
					$new[] = sprintf( '%s: %s (%d, %d allowed)', $file, $key, $count, $allowed );
				}
			}
		}

		$this->assertSame(
			array(),
			$new,
			"New inline CSS, with no written reason:\n  " . implode( "\n  ", $new )
			. "\n\nAn inline declaration is invisible to every CSS guard, beats the tokenized"
			. "\nclass and does not follow the dark theme. Put the rule in the sheet the screen"
			. "\nenqueues (check its gate), as a class reading tokens. If it is a run-time value,"
			. "\nan operator colour, an e-mail or a document, add it to ALLOWED with the reason."
		);
	}

	/**
	 * An entry that shrank or vanished leaves the register.
	 */
	public function test_the_register_only_shrinks(): void {
		$found = InlineStyles::found();
		$stale = array();

		foreach ( self::ALLOWED as $file => $entries ) {
			foreach ( $entries as $key => $entry ) {
				$count = $found[ $file ][ $key ] ?? 0;
				if ( $count < $entry[0] ) {
					$stale[] = sprintf( '%s: %s (%d frozen, %d found)', $file, $key, $entry[0], $count );
				}
			}
		}

		$this->assertSame(
			array(),
			$stale,
			"These shrank or no longer exist -- lower or drop them to lock the win in:\n  " . implode( "\n  ", $stale )
		);
	}

	/**
	 * Every reason says something.
	 *
	 * A floor against "ok" / "see above" -- never a quality bar, the same
	 * criterion the suppression guards use (#1027 / #1035).
	 */
	public function test_every_reason_says_something(): void {
		$short = array();

		foreach ( self::ALLOWED as $file => $entries ) {
			foreach ( $entries as $key => $entry ) {
				if ( 20 > strlen( $entry[1] ) || 1 > $entry[0] ) {
					$short[] = $file . ': ' . $key;
				}
			}
		}

		$this->assertSame( array(), $short, "Reason too short, or a count below one:\n  " . implode( "\n  ", $short ) );
	}

	/**
	 * The excluded template directories exist and each says why.
	 *
	 * An exclusion whose directory was renamed would silently stop excluding --
	 * and, worse, the renamed directory would start being scanned with no
	 * register entry, which is at least loud. The first half is the silent one.
	 */
	public function test_every_exclusion_names_a_real_directory(): void {
		$root = dirname( __DIR__, 2 );
		foreach ( InlineStyles::EXCLUDED_DIRS as $dir => $reason ) {
			$this->assertDirectoryExists( $root . '/' . $dir, $dir . ' is excluded by name and no longer exists.' );
			$this->assertGreaterThanOrEqual( 20, strlen( $reason ), $dir . ' is excluded without a reason.' );
		}
	}

	/**
	 * The patterns catch each shape, and a comment is not an occurrence.
	 *
	 * Synthetic source, so the proof does not depend on what the tree happens
	 * to contain today -- the `DeprecationDueTest` canary shape.
	 */
	public function test_the_patterns_on_synthetic_source(): void {
		$php_code = '<p style="margin:0">x</p> <b data-ffc-confirm-style="primary">y</b>'
			. " <?php echo '<span style=\\\"color:red\\\">'; wp_add_inline_style( 'h', \$css );"
			. " \$a = array( 'style' => 'margin: 12px 0;' ); \$b = array( 'style' => 'primary' ); ?>"
			. ' <style>.x{}</style>';
		$this->assertSame(
			array(
				'style=: style="margin:0'             => 1,
				'style=: style="color:red'            => 1,
				'<style>: <style'                     => 1,
				'wp_add_inline_style: wp_add_inline_style( "h"' => 1,
				'attributes: "style" => "margin: 12px 0;' => 1,
			),
			InlineStyles::in_code( $php_code, true ),
			'The PHP patterns no longer read the shapes they exist for.'
		);

		$js = JsIdSelectors::strip_comments(
			"// style=\"color:red\" in a comment\n"
			. "/* \$el.css('color', 'red') */\n"
			. "var u = 'https://example.com'; \$el.css('color', 'red');\n"
			. "\$el.css('display', 'none'); el.style.display = 'none'; \$el.css('width');\n"
			. "\$el.css({ top: 0 }); el.style.top = '1px'; if (el.style.top === '1px') {}\n"
			. "el.style.setProperty('height', 'auto');"
		);
		$this->assertSame(
			array(
				'.css(): .css("color", "red"'                 => 1,
				'.css(): .css({'                              => 1,
				'.style.x =: .style.top = "1px"'              => 1,
				'.style.setProperty: .style.setProperty("height", "auto"' => 1,
			),
			InlineStyles::in_code( $js, false ),
			'The JS patterns no longer read the shapes they exist for -- or a comment, a display toggle or a getter started counting.'
		);
	}

	/**
	 * The scan must not collapse in silence, and a second reading agrees.
	 *
	 * The register is non-empty and spans dozens of files, so a scan that
	 * loses files already loses entries and the shrink test fires -- the
	 * "exact comparison against a frozen register" shape. This adds the
	 * independent recount CLAUDE.md "The guards" prefers: the files listed by
	 * a different traversal, and the occurrences counted by a different
	 * comment stripper.
	 */
	public function test_the_scan_cannot_collapse_in_silence(): void {
		$root = dirname( __DIR__, 2 );

		$php_by_glob = array();
		foreach ( array( 'includes', 'templates' ) as $dir ) {
			foreach ( InlineStyles::glob_recursive( $root . '/' . $dir, '*.php' ) as $path ) {
				$relative = str_replace( $root . '/', '', $path );
				if ( ! InlineStyles::is_excluded( $relative ) ) {
					$php_by_glob[] = $relative;
				}
			}
		}
		sort( $php_by_glob );
		$this->assertSame( $php_by_glob, InlineStyles::php_files(), 'The PHP file list disagrees with a glob() walk of the same roots.' );

		$js_by_scandir = array();
		foreach ( scandir( $root . '/assets/js' ) ?: array() as $name ) {
			if ( '.js' === substr( $name, -3 ) && ! str_contains( $name, '.min.' ) ) {
				$js_by_scandir[] = 'assets/js/' . $name;
			}
		}
		$this->assertSame( $js_by_scandir, InlineStyles::js_files(), 'The JS file list disagrees with a scandir() of assets/js.' );

		$php_total = 0;
		$js_total  = 0;
		foreach ( InlineStyles::found() as $file => $entries ) {
			if ( str_starts_with( $file, 'assets/js/' ) ) {
				$js_total += array_sum( $entries );
			} else {
				$php_total += array_sum( $entries );
			}
		}
		$this->assertSame( InlineStyles::php_total_by_second_reading(), $php_total, 'php_strip_whitespace() counts a different number of PHP occurrences than the token scan.' );
		$this->assertSame( InlineStyles::js_total_by_second_reading(), $js_total, 'A regex comment stripper counts a different number of JS occurrences than the character loop.' );
	}
}
