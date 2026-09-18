<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A client may NAME a captcha field only where it refreshes one (#1307).
 *
 * This is the shape #1305 shipped in. The certificate verification handler
 * built its request out of the fields it knew about:
 *
 *     FFC.request('ffc_verify_certificate', {
 *         ffc_auth_code:     authCode,
 *         ffc_captcha_ans:   captchaAns,    // math
 *         ffc_captcha_hash:  captchaHash,   // math
 *         ffc_honeypot_trap: honeypot
 *     })
 *
 * ALTCHA posts one field named `altcha`. It was on no list, so the proof the
 * visitor had just watched the widget produce was never sent, and a public page
 * answered "Please complete the verification" to every attempt until somebody
 * reported it. The fix was to stop enumerating: all four surfaces now submit the
 * whole form, so a field a future provider introduces travels on its own.
 *
 * **What has no guard is the enumerating coming back.** `AjaxWiringTest`
 * cross-checks action names, never payload shape. So this states the property
 * the fix established: the only code allowed to know a captcha field by name is
 * the code that REFRESHES one. Anything that submits must send the form.
 *
 * It sees names, never behaviour — a client that submits nothing at all names
 * no captcha field and passes. That case needs resolving which actions are
 * captcha-guarded, and #1307 parks it with the reason: the certificate form
 * reaches the gate through `Frontend\Submission\SecurityFieldsGuard`, a composed
 * class that is not an AJAX handler, so a scan resolving one hop would report
 * the guarded set as smaller than it is and read as clean.
 *
 * @coversNothing
 */
class CaptchaFieldNameTest extends TestCase {

	/**
	 * The files allowed to name a captcha field, and why.
	 *
	 * A ratchet that only shrinks: a file missing from here fails, and a listed
	 * file that stopped naming one fails too, so the win is locked in rather
	 * than left as a stale exemption.
	 *
	 * Both entries are refreshers — they REPLACE a challenge the server has
	 * spent, which is the one job that legitimately needs the field by name.
	 *
	 * @var array<string, string>
	 */
	private const REFRESHERS = array(
		'ffc-dynamic-fragments.js' => 'The page-load refresh for full-page caches; its math branch patches label, token and answer.',
		'ffc-frontend-helpers.js'  => 'UI.refreshCaptcha, the error-path refresh; same math branch, and it resets the ALTCHA widget instead.',
	);

	/**
	 * Field names that are unambiguous wherever they appear.
	 *
	 * The math pair means "the captcha field" and nothing else. `altcha` is
	 * deliberately NOT here: the same token is the custom element
	 * (`altcha-widget`), a CSS class (`ffc-altcha-row`), the widget's i18n store
	 * (`window.$altcha`) and the provider id in both dispatches
	 * (`payload.provider === 'altcha'`). It is matched by POSITION instead, in
	 * {@see self::FIELD_POSITION_PATTERNS}.
	 *
	 * @var list<string>
	 */
	private const UNAMBIGUOUS_FIELDS = array(
		'ffc_captcha_ans',
		'ffc_captcha_hash',
	);

	/**
	 * Where a name is being used AS a field name.
	 *
	 * Narrow on purpose: the point is to catch a client reaching for the field,
	 * not one mentioning the provider.
	 *
	 * @var list<string>
	 */
	private const FIELD_POSITION_PATTERNS = array(
		'/\[\s*name\s*=\s*[\'"]altcha[\'"]\s*\]/',   // input[name="altcha"]
		'/\bname\s*=\s*[\'"]altcha[\'"]/',            // name="altcha"
		'/[\'"]?\baltcha\b[\'"]?\s*:/',               // { altcha: … } / 'altcha':
	);

	/**
	 * Absolute path of the non-minified script directory.
	 */
	private function js_dir(): string {
		return dirname( __DIR__, 2 ) . '/assets/js';
	}

	/**
	 * Every non-minified script, basename => source with comments removed.
	 *
	 * **Comments are stripped, and that is not tidiness.** `ffc-frontend.js`
	 * carries two comments naming `altcha` — they are the explanation of the
	 * #1305 fix, written where the fix is. A scan that read them would fail on
	 * its own rationale, which is the fastest way to get a guard deleted.
	 *
	 * @return array<string, string>
	 */
	private function sources(): array {
		$found = array();

		foreach ( (array) glob( $this->js_dir() . '/*.js' ) as $path ) {
			$path = (string) $path;
			if ( substr( $path, -7 ) === '.min.js' ) {
				continue;
			}

			$found[ basename( $path ) ] = self::strip_comments( (string) file_get_contents( $path ) );
		}

		return $found;
	}

	/**
	 * Source with comments removed.
	 *
	 * A method rather than two lines inline so the stripping can be tested on
	 * its own — see `test_a_field_named_in_a_comment_is_not_a_finding()`. It is
	 * the second thing in this file whose absence would otherwise change
	 * nothing measurable, and this session's own lesson is that such a
	 * safeguard either earns a falsifiable test or should not be there.
	 *
	 * `(^|\s)` before `//` is deliberate: it keeps `https://…` inside a string
	 * literal intact, which a bare `//` rule would cut the line at.
	 *
	 * @param string $source Raw script.
	 * @return string
	 */
	private static function strip_comments( string $source ): string {
		$source = (string) preg_replace( '#/\*.*?\*/#s', '', $source );

		return (string) preg_replace( '#(^|\s)//.*$#m', '$1', $source );
	}

	/**
	 * Files naming a captcha field, basename => the names found.
	 *
	 * @return array<string, list<string>>
	 */
	private function namers(): array {
		$namers = array();

		foreach ( $this->sources() as $file => $source ) {
			$hits = array();

			foreach ( self::UNAMBIGUOUS_FIELDS as $field ) {
				if ( false !== strpos( $source, $field ) ) {
					$hits[] = $field;
				}
			}

			foreach ( self::FIELD_POSITION_PATTERNS as $pattern ) {
				if ( 1 === preg_match( $pattern, $source ) ) {
					$hits[] = 'altcha';
					break;
				}
			}

			if ( array() !== $hits ) {
				$namers[ $file ] = $hits;
			}
		}

		return $namers;
	}

	/**
	 * Self-check: an empty scan must fail rather than read as clean.
	 *
	 * The #1071 / #1094 rule every guard here carries. A renamed directory or a
	 * comment-stripper that ate everything would otherwise turn this file green.
	 */
	public function test_the_scan_reads_the_scripts_and_finds_names(): void {
		$sources = $this->sources();

		$this->assertDirectoryExists( $this->js_dir() );
		$this->assertGreaterThan( 20, count( $sources ), 'Far fewer scripts than this repository has.' );

		$this->assertNotEmpty(
			$this->namers(),
			'No script names a captcha field at all, which cannot be right while the math refreshers exist — the scan is broken.'
		);
	}

	/**
	 * Self-check: a name written in PROSE is not a finding.
	 *
	 * Asserted on a synthetic source rather than on whichever file happens to
	 * carry such a comment today. The first version of this test pointed at
	 * `ffc-frontend.js`, whose two `altcha` comments explain the #1305 fix —
	 * and it could not fail, because that prose matches no field-position
	 * pattern anyway. A safeguard nothing can break is not one.
	 *
	 * The case is real rather than hypothetical: the fix's own rationale lives
	 * in comments beside the code it changed, and a guard that failed on its
	 * own explanation would be deleted within a release.
	 */
	public function test_a_field_named_in_a_comment_is_not_a_finding(): void {
		$prose = <<<'JS'
			// The ffc_captcha_ans field used to be listed here by hand.
			/* Under ALTCHA the posted field is name="altcha", which was on no list. */
			var url = 'https://example.invalid/ffc_captcha_hash';
			FFC.request('ffc_x', $form.serialize());
JS;

		$stripped = self::strip_comments( $prose );

		$this->assertStringNotContainsString( 'ffc_captcha_ans', $stripped, 'A line comment survived the stripper.' );
		$this->assertStringNotContainsString( 'name="altcha"', $stripped, 'A block comment survived the stripper.' );
		$this->assertStringContainsString(
			'https://example.invalid',
			$stripped,
			'The `//` of a URL was treated as a comment, which would silently truncate real code.'
		);
	}

	/**
	 * Self-check: the file whose comments prompted the stripper is still clean.
	 *
	 * `ffc-frontend.js` names `altcha` twice, in comments, and in no other way
	 * — that is the file #1305 fixed, and the comments are the fix's rationale.
	 * If it ever shows up as a namer, either the stripper broke or that file
	 * went back to reaching for the field by hand. Both are worth failing on.
	 */
	public function test_a_file_naming_a_field_only_in_comments_is_not_a_namer(): void {
		$raw = (string) file_get_contents( $this->js_dir() . '/ffc-frontend.js' );

		$this->assertStringContainsString(
			'`altcha`',
			$raw,
			'The canary is gone: ffc-frontend.js no longer mentions the field in prose, so this test proves nothing. Point it at another file that does.'
		);

		$this->assertArrayNotHasKey( 'ffc-frontend.js', $this->namers() );
	}

	/**
	 * Self-check: skipping the minified bundles is a real exclusion.
	 *
	 * They carry the same tokens, so scanning them would report every
	 * refresher twice and any future namer twice — asserted rather than
	 * assumed, because an exclusion that excludes nothing is a line nobody
	 * can question later.
	 */
	public function test_the_minified_bundles_would_have_matched(): void {
		$minified = (string) file_get_contents( $this->js_dir() . '/ffc-frontend-helpers.min.js' );

		$this->assertStringContainsString( 'ffc_captcha_ans', $minified );
		$this->assertArrayNotHasKey( 'ffc-frontend-helpers.min.js', $this->namers() );
	}

	/**
	 * The guard itself, in the direction that catches a new defect.
	 */
	public function test_only_a_refresher_names_a_captcha_field(): void {
		$unexpected = array();

		foreach ( $this->namers() as $file => $fields ) {
			if ( ! isset( self::REFRESHERS[ $file ] ) ) {
				$unexpected[] = $file . ' (' . implode( ', ', $fields ) . ')';
			}
		}

		$this->assertSame(
			array(),
			$unexpected,
			'These scripts name a captcha field. A path that SUBMITS must send the whole form instead — enumerating the fields is how #1305 left ALTCHA unsendable on a public page. If this file genuinely refreshes a challenge, add it to REFRESHERS with the reason.'
		);
	}

	/**
	 * The other direction, which is what makes it a ratchet.
	 *
	 * A registered file that stopped naming a field is a win to lock in, not an
	 * exemption to keep: left in place it would silently re-authorise the next
	 * client written in that file.
	 */
	public function test_every_registered_refresher_still_names_one(): void {
		$namers = $this->namers();
		$stale  = array();

		foreach ( array_keys( self::REFRESHERS ) as $file ) {
			if ( ! isset( $namers[ $file ] ) ) {
				$stale[] = $file;
			}
		}

		$this->assertSame(
			array(),
			$stale,
			'These are registered as allowed to name a captcha field and no longer do. Drop them from REFRESHERS.'
		);
	}
}
