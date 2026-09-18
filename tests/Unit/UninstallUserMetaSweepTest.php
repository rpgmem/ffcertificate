<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * What `uninstall.php` must know about user meta (#1316).
 *
 * THE DEFECT
 *
 * Step 7 deleted exactly two meta keys BY NAME -- `ffc_registration_date` and
 * `ffc_custom_fields_data` -- while both of its `phpcs:ignore` justifications
 * already claimed it matched a prefix. Everything the user profile writes
 * survived: `ffc_user_cpf`, `ffc_user_rf` and `ffc_user_rg` hold AES-256-CBC
 * ciphertext of the document numbers, and the dynamic reregistration fields add
 * an unbounded set beside them. So an administrator who ticked "Delete all
 * plugin data on uninstall" and watched every `ffc_*` table drop still had
 * encrypted PII in `wp_usermeta`, with the key material gone.
 *
 * WHY A LIST COULD NEVER HAVE WORKED
 *
 * `grep` finds exactly THREE literal `ffc_*` user-meta keys under `includes/`.
 * Every other one is assembled at runtime from `EXTENDED_META_PREFIX` plus a
 * field name, and `UserManager::update_extended_profile()` appends a
 * `sanitize_key()`'d name that is declared nowhere in the source. A list cannot
 * track a set the source does not contain -- the same reason #1290 deleted the
 * capability list rather than guarding it, and #1291 the table list.
 *
 * WHAT THIS FILE ASSERTS, AND WHAT IT DELIBERATELY DOES NOT
 *
 * The sweep's PRECONDITION: every key the plugin can write starts with the
 * prefix the sweep removes. That is checkable where the key is a literal or a
 * declared prefix, and those are the two shapes below.
 *
 * It is NOT checkable at the eleven call sites that pass a variable
 * (`$meta_key`, `$hash_key`, `$spec['meta_key']`). Those keys descend from the
 * prefix constants this file checks -- that is a claim about the code, stated
 * here rather than proven, because proving it means resolving a descriptor
 * array through two classes and a runtime override. Said plainly so the next
 * reader knows the edge of the instrument.
 *
 * The prefix constants are named ONE BY ONE rather than matched by a pattern
 * over constant names, and that is deliberate: `CertTemplateCpt::META_HTML`,
 * `PublicCsvDownload::META_HASH` and a dozen siblings are POST meta, prefixed
 * `_ffc_` with a leading underscore, and they are legitimately outside this
 * sweep. A pattern loose enough to catch `EXTENDED_META_PREFIX` catches those
 * too and fails on data the uninstaller never promised to touch.
 *
 * And what none of it sees is whether the sweep RUNS. That is proven where a
 * database exists: the `fresh-install` job plants a live key, the legacy hash
 * key beside it, and `ffc_zz_synthetic_meta` -- a name in no list anywhere in
 * this repository -- then asserts zero `ffc_*` user meta remains after a real
 * uninstall. The synthetic one is the mutation: only a prefix sweep removes it.
 *
 * @coversNothing
 */
class UninstallUserMetaSweepTest extends TestCase {

	/**
	 * Files whose user-meta calls pass a VARIABLE, with what it descends from.
	 *
	 * A register rather than a silence: each entry is the reason a call site
	 * this scan cannot resolve is nonetheless covered. It shrinks when a site
	 * starts naming its key, and it is where a new unresolvable site has to
	 * argue for itself.
	 *
	 * @var array<string, string>
	 */
	private const RUNTIME_KEY_SITES = array(
		'includes/user-dashboard/class-ffc-user-profile-service.php'                    =>
			'Keys come from a UserProfileFieldMap descriptor, or from a runtime descriptor UserManager builds with the same prefix.',
		'includes/user-dashboard/class-ffc-user-manager.php'                            =>
			'EXTENDED_META_PREFIX . sanitize_key( $key ) — the dynamic reregistration half, whose field names live in the database.',
		'includes/migrations/strategies/class-ffc-identity-normalization-migration-strategy.php' =>
			'PROFILE_META_PREFIX . <field key>, pinned in the class and charged against UserManager::EXTENDED_META_PREFIX by IdentityNormalizationTargetsTest — so the prefix this register vouches for is itself proven to be the one the profile writes under.',
		'includes/migrations/strategies/class-ffc-key-rotation-remaining-migration-strategy.php' =>
			'Keys are the literals of profile_meta_map(), which pins them to the UserProfileFieldMap entries a test already compares it against.',
	);

	/**
	 * Prefix constants every runtime-built user-meta key descends from.
	 *
	 * Read as TEXT, like `uninstall.php` itself, so the test pulls no class
	 * into the process: a declaration is a literal in a file, and autoloading
	 * `UserManager` here would teach the rest of the run something it did not
	 * ask for (CLAUDE.md, "A `function_exists()` guard makes tests
	 * order-dependent").
	 *
	 * @var array<string, string> Relative path => constant name.
	 */
	private const PREFIX_CONSTANTS = array(
		'includes/user-dashboard/class-ffc-user-manager.php'            => 'EXTENDED_META_PREFIX',
		'includes/user-dashboard/class-ffc-user-profile-field-map.php'  => 'EXTENDED_META_PREFIX',
		'includes/reregistration/class-ffc-custom-field-reader.php'     => 'USER_META_KEY',
	);

	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	private function uninstall_source(): string {
		return (string) file_get_contents( $this->root() . '/uninstall.php' );
	}

	/**
	 * The prefix the uninstaller sweeps, read through the shared manifest.
	 */
	private function swept_prefix(): string {
		require_once $this->root() . '/.github/scripts/ffc-uninstall-manifest.php';

		return ffc_manifest_user_meta_prefix( $this->root() . '/uninstall.php' );
	}

	/**
	 * Literal meta keys in the key position of a `*_user_meta()` call.
	 *
	 * @return array<string, string> Key => the file it was found in.
	 */
	private function literal_keys(): array {
		$found = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root() . '/includes', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$path = $file->getPathname();
			if ( substr( $path, -4 ) !== '.php' ) {
				continue;
			}

			$source = (string) file_get_contents( $path );

			if ( ! preg_match_all(
				'/(?:update|add|delete|get)_user_meta\(\s*[^,]{1,80},\s*\'([^\']+)\'/',
				$source,
				$matches
			) ) {
				continue;
			}

			foreach ( $matches[1] as $key ) {
				$found[ $key ] = substr( $path, strlen( $this->root() ) + 1 );
			}
		}

		return $found;
	}

	/**
	 * Files with a `*_user_meta()` call whose key this scan cannot resolve.
	 *
	 * Empty parentheses are excluded on purpose: `get_user_meta()` written
	 * inside a comment or a docblock is prose about the function, not a call,
	 * and two files mention it exactly that way.
	 *
	 * @return array<int, string> Relative paths, unique and sorted.
	 */
	private function unresolvable_sites(): array {
		$found = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root() . '/includes', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$path = $file->getPathname();
			if ( substr( $path, -4 ) !== '.php' ) {
				continue;
			}

			$source = (string) file_get_contents( $path );

			if ( ! preg_match_all(
				'/(?:update|add|delete|get)_user_meta\(\s*[^)\s][^,]{0,80},\s*([^,)]+)/',
				$source,
				$matches
			) ) {
				continue;
			}

			foreach ( $matches[1] as $argument ) {
				$argument = trim( $argument );

				// Resolvable: a quoted literal, or a class constant whose
				// declaration PREFIX_CONSTANTS already reads.
				if ( 1 === preg_match( '/^[\'"]/', $argument ) || false !== strpos( $argument, '::' ) ) {
					continue;
				}

				$found[] = substr( $path, strlen( $this->root() ) + 1 );
				break;
			}
		}

		$found = array_values( array_unique( $found ) );
		sort( $found );

		return $found;
	}

	/**
	 * The declared value of one class constant, read as text.
	 */
	private function constant_value( string $relative_path, string $name ): string {
		$source = (string) file_get_contents( $this->root() . '/' . $relative_path );

		if ( ! preg_match( '/const\s+' . preg_quote( $name, '/' ) . '\s*=\s*\'([^\']*)\'/', $source, $m ) ) {
			return '';
		}

		return $m[1];
	}

	// ==================================================================

	/**
	 * The self-check. An empty scan must never read as a clean one.
	 */
	public function test_the_scans_find_something_at_all(): void {
		$this->assertNotSame(
			'',
			$this->swept_prefix(),
			'The manifest could not read the swept prefix out of uninstall.php — every assertion below would be vacuous.'
		);

		$this->assertNotEmpty(
			$this->literal_keys(),
			'No literal user-meta key was found under includes/ — the extractor stopped matching, it is not that the plugin stopped writing meta.'
		);

		foreach ( self::PREFIX_CONSTANTS as $path => $name ) {
			$this->assertFileExists( $this->root() . '/' . $path );
			$this->assertNotSame(
				'',
				$this->constant_value( $path, $name ),
				"Could not read {$name} out of {$path} — the declaration moved or changed shape."
			);
		}

		foreach ( array_keys( self::RUNTIME_KEY_SITES ) as $path ) {
			$source = (string) file_get_contents( $this->root() . '/' . $path );
			$this->assertMatchesRegularExpression(
				'/(?:update|add|delete|get)_user_meta\(/',
				$source,
				"{$path} is registered as an unresolvable call site but no longer touches user meta — drop it from the register."
			);
		}
	}

	/**
	 * A call site this scan cannot read must be registered, with its reason.
	 *
	 * Without this the register is decorative: a new class could start building
	 * meta keys from a prefix of its own and nothing here would notice, which
	 * is the failure mode of a guard that only checks what it already knows
	 * about.
	 */
	public function test_every_unresolvable_call_site_is_registered(): void {
		$measured = $this->unresolvable_sites();
		$declared = array_keys( self::RUNTIME_KEY_SITES );
		sort( $declared );

		$this->assertNotEmpty(
			$measured,
			'No unresolvable call site was found — the detector stopped matching, not the code.'
		);

		$this->assertSame(
			$declared,
			$measured,
			'A file builds a user-meta key this scan cannot read and is not registered. Add it with the prefix its keys descend from, or give the call a key the scan can resolve.'
		);
	}

	/**
	 * Every literal key the plugin writes is reachable by the sweep.
	 */
	public function test_every_literal_user_meta_key_carries_the_swept_prefix(): void {
		$prefix = $this->swept_prefix();

		foreach ( $this->literal_keys() as $key => $path ) {
			$this->assertStringStartsWith(
				$prefix,
				$key,
				"'{$key}' in {$path} does not start with '{$prefix}', so uninstall would leave it behind."
			);
		}
	}

	/**
	 * Every prefix a runtime-built key descends from is reachable too.
	 *
	 * This is the half that covers the eleven call sites passing a variable:
	 * their keys are this prefix plus a field name, so a prefix the sweep can
	 * reach makes all of them reachable — and a mistyped one makes the whole
	 * family invisible at once, which is exactly the shape that shipped.
	 */
	public function test_every_user_meta_prefix_constant_carries_the_swept_prefix(): void {
		$prefix = $this->swept_prefix();

		foreach ( self::PREFIX_CONSTANTS as $path => $name ) {
			$value = $this->constant_value( $path, $name );

			$this->assertStringStartsWith(
				$prefix,
				$value,
				"{$name} in {$path} is '{$value}', which does not start with '{$prefix}' — every key built from it would survive uninstall."
			);
		}
	}

	/**
	 * The two copies of the prefix must not drift apart.
	 *
	 * `UserProfileFieldMap` carries its own copy of `UserManager`'s constant,
	 * with a docblock saying they are kept in sync. Nothing enforced that, and
	 * the two resolve meta keys for the same rows.
	 */
	public function test_the_two_copies_of_the_prefix_agree(): void {
		$this->assertSame(
			$this->constant_value( 'includes/user-dashboard/class-ffc-user-manager.php', 'EXTENDED_META_PREFIX' ),
			$this->constant_value( 'includes/user-dashboard/class-ffc-user-profile-field-map.php', 'EXTENDED_META_PREFIX' ),
			'The two EXTENDED_META_PREFIX declarations disagree — one of them resolves a meta key the other cannot find.'
		);
	}

	/**
	 * The regression guard: the sweep is a prefix rule, not a list of names.
	 */
	public function test_the_uninstaller_does_not_delete_user_meta_by_name(): void {
		$source = $this->uninstall_source();

		$this->assertDoesNotMatchRegularExpression(
			'/\$wpdb->delete\(\s*\$wpdb->usermeta/',
			$source,
			'uninstall.php names a user-meta key again. That is the #1316 defect: a list cannot track keys the source does not contain.'
		);

		$this->assertMatchesRegularExpression(
			'/DELETE FROM %i WHERE meta_key LIKE %s/',
			$source,
			'The user-meta sweep statement is gone — nothing removes the plugin\'s user meta on uninstall.'
		);
	}

	/**
	 * The underscore is escaped, or the sweep reaches another plugin's meta.
	 *
	 * `_` is a LIKE wildcard, so a bare `ffc_%` also matches an `ffcx_` key.
	 * The table sweep records the same reasoning (#1291); here the cost of
	 * getting it wrong is deleting data this plugin never owned, which is worse
	 * than the residue the sweep exists to remove.
	 */
	public function test_the_sweep_escapes_the_underscore(): void {
		$this->assertMatchesRegularExpression(
			'/esc_like\(\s*\$ffcertificate_user_meta_prefix\s*\)\s*\.\s*\'%\'/',
			$this->uninstall_source(),
			'The user-meta sweep does not escape its prefix, so `ffc_` also matches `ffcX` keys belonging to somebody else.'
		);
	}
}
