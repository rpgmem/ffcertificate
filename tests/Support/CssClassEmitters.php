<?php
/**
 * Where each CSS class is EMITTED, in PHP and in JS.
 *
 * It exists because renaming a class is safe exactly as far as you can find who
 * emits it, and a grep cannot. Measuring for #1170, three different nets gave
 * three answers, and two of them were wrong:
 *
 *  1. Grepping the bare word — `.error`, `.value`, `.top` match in any PHP file
 *     for no reason at all.
 *  2. Grepping with `\b` — worse, and silently so: in CSS **`-` is a word
 *     boundary**, so `ffc-error` matches as `error` and inflates precisely the
 *     generic names one wants to rename.
 *  3. A delimited token — the real number, and the basis of this class.
 *
 * **The case that decides everything is a name assembled at runtime.**
 * `'ffc-dashboard-status-' . $status` exists as a literal nowhere; searching for
 * `ffc-dashboard-status-confirmed` finds nothing, and renaming it breaks with no
 * warning. So the scan also records the dynamic PREFIXES, and a class counts as
 * emitted when it starts with one of them.
 *
 * It is the same choice `AjaxWiringTest` already documents for action names —
 * matching the shapes exactly reported all of them as orphans — and for the same
 * reason: **too loose reports too little; too strict reports the world.**
 *
 * @package FreeFormCertificate\Tests\Support
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Scan of the sources that emit classes.
 */
final class CssClassEmitters {

	/**
	 * Directories scanned, relative to the repository root.
	 */
	private const ROOTS = array( 'includes', 'templates', 'assets/js', 'libs/js' );

	/**
	 * A COMPLETE string literal, in either quote style.
	 *
	 * Matching `(["\'])(.*?)\1` by alternation loses parity at the first
	 * apostrophe inside a double-quoted string and reads the rest of the file
	 * shifted — this class's own header already records the fact, and even so
	 * the first version of shape 5b fell for it: `ffc-has-geofence` had no
	 * findable emitter because, scanning `class-ffc-shortcodes.php` from the
	 * start, parity was already swapped by the time the scan reached line 169.
	 *
	 * Each alternative here consumes the WHOLE literal, escapes included, so a
	 * quote of the other kind inside it is content, not a delimiter.
	 */
	private const STRING_LITERAL = '/"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'/';

	/**
	 * Cache of the scan — it reads hundreds of files.
	 *
	 * @var array{literals: array<string, array<int, string>>, prefixes: array<string, array<int, string>>}|null
	 */
	private static ?array $cache = null;

	/**
	 * Absolute path of the repository root.
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Every PHP/JS file that can emit a class.
	 *
	 * @return array<string, string> Relative path => contents.
	 */
	private static function sources(): array {
		$out = array();

		foreach ( self::ROOTS as $dir ) {
			$base = self::root() . '/' . $dir;
			if ( ! is_dir( $base ) ) {
				continue;
			}
			$walk = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $walk as $file ) {
				$path = $file->getPathname();
				if ( ! preg_match( '/\.(php|js)$/', $path ) || str_contains( $path, '.min.' ) ) {
					continue;
				}
				$out[ str_replace( self::root() . '/', '', $path ) ] = (string) file_get_contents( $path );
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Scans once and stores literals and dynamic prefixes.
	 *
	 * @return array{literals: array<string, array<int, string>>, prefixes: array<string, array<int, string>>}
	 */
	private static function scan(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$literals = array();
		$prefixes = array();

		/*
		 * One accumulator per map, capturing the array by reference.
		 *
		 * The obvious shape would be ONE closure taking `array &$bucket`, and it
		 * breaks — but only once some earlier test in the same process has
		 * registered a patch. Patchwork instruments the dynamic call and
		 * dispatches it through `call_user_func_array()`, which cannot pass by
		 * reference: the call dies with
		 * "Argument #1 (\$bucket) must be passed by reference".
		 *
		 * Running this file alone passes, and running it after any class that
		 * uses Brain\Monkey fails — that is how the local green lied and CI
		 * caught it. A variable captured with `use ( &… )` is not a parameter,
		 * so the dispatch never touches it.
		 */
		$add_literal = static function ( string $key, string $file ) use ( &$literals ): void {
			if ( '' === $key ) {
				return;
			}
			if ( ! isset( $literals[ $key ] ) ) {
				$literals[ $key ] = array();
			}
			if ( ! in_array( $file, $literals[ $key ], true ) ) {
				$literals[ $key ][] = $file;
			}
		};

		$add_prefix = static function ( string $key, string $file ) use ( &$prefixes ): void {
			if ( '' === $key ) {
				return;
			}
			if ( ! isset( $prefixes[ $key ] ) ) {
				$prefixes[ $key ] = array();
			}
			if ( ! in_array( $file, $prefixes[ $key ], true ) ) {
				$prefixes[ $key ][] = $file;
			}
		};

		foreach ( self::sources() as $file => $src ) {
			/*
			 * ── Shapes 1 and 2: the `class="…"` attribute.
			 *
			 * The value may carry a PHP block in the middle; the literals around
			 * it are still class tokens, and whatever is INSIDE is handled by the
			 * string shapes further down.
			 *
			 * This comment is a BLOCK out of necessity: a `//` comment ends at
			 * the PHP closing tag, so quoting one here would close the tag in the
			 * middle of the function — the same fact CLAUDE.md records about
			 * PHPCS annotations, and the one I tripped over writing this.
			 */
			if ( preg_match_all( '/class\s*=\s*(["\'])(.*?)\1/s', $src, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $hit ) {
					$value = (string) preg_replace( '/<\?(php|=).*?\?>/s', ' ', $hit[2] );
					foreach ( preg_split( '/\s+/', trim( $value ) ) ?: array() as $token ) {
						/*
						 * The token arrives with the CONCATENATION PUNCTUATION
						 * glued to it when the attribute is opened and not
						 * closed in the same string --
						 * `'<table class="ffc-appointments-table' +
						 * (past ? ' ffc-table-past' : '') + '">'`. The quote the
						 * match above finds is the one at the END of the
						 * expression, and the first name comes out as
						 * `ffc-appointments-table'`, which fails validation and
						 * disappears.
						 *
						 * It is the mirror image of the shape #1170 taught:
						 * there the END token arrived with its separator space,
						 * here the START token arrives with the quote. Fixing
						 * one without the other is why four dashboard tables sat
						 * in the orphan list for as long as it existed.
						 */
						$token = trim( $token, "\"'+., \t\n" );

						if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_-]*$/', $token ) ) {
							$add_literal( $token, $file );
						}
					}
				}
			}

			// ── Shape 3: the class API, in the DOM and in jQuery.
			// `classList.add('a','b')` and `addClass('a b')` — the argument may
			// carry more than one name.
			if ( preg_match_all( '/(?:classList\.(?:add|remove|toggle|contains|replace)|(?:add|remove|toggle|has)Class)\s*\(([^)]*)\)/i', $src, $m ) ) {
				foreach ( $m[1] as $args ) {
					if ( preg_match_all( '/(["\'])([^"\']*)\1/', $args, $strings ) ) {
						foreach ( $strings[2] as $group ) {
							foreach ( preg_split( '/\s+/', trim( $group ) ) ?: array() as $token ) {
								if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_-]*$/', $token ) ) {
									$add_literal( $token, $file );
								}
							}
						}
					}
				}
			}

			/*
			 * ── Shape 3b: the class as a SELECTOR inside a string.
			 *
			 * `$(document).on('click', '.ffc-timeslot:not(.ffc-timeslot-full)')`
			 * is an emission site as far as renaming goes: changing the class
			 * without changing the selector breaks the handler, and nothing
			 * reports it. It needs no class context around it — the dot before
			 * the name is the signal.
			 *
			 * It searches the whole source rather than inside matched strings,
			 * and that is deliberate: matching `"…"` and `'…'` by alternation
			 * LOSES SYNC at the first apostrophe inside a double-quoted string
			 * (`"don't"`), and from there on reads the file with parity swapped.
			 * That is how the first version failed to find `.ffc-timeslot-full`.
			 * It is the same reason `CssSelectors` has to be quote-aware.
			 */
			if ( preg_match_all( '/\.(ffc-[a-z0-9]+(?:-{1,2}[a-z0-9]+)*)/i', $src, $m ) ) {
				foreach ( $m[1] as $token ) {
					$add_literal( $token, $file );
				}
			}

			/*
			 * ── Shape 7: a library option whose VALUE is a class.
			 *
			 * `$('#list').sortable({ placeholder: 'ffc-sortable-placeholder' })`
			 * -- jQuery UI applies that string as a class on the phantom element
			 * it inserts. The word `class` appears nowhere near it, so no context
			 * window reaches, and the class sat in the orphan list looking dead.
			 *
			 * It matches only the OPTION form (`placeholder:`), never the HTML
			 * attribute (`placeholder="Enter the name"`), which is free user text.
			 */
			if ( preg_match_all( '/placeholder\s*:\s*(["\'])([A-Za-z_][A-Za-z0-9_-]*)\1/', $src, $m ) ) {
				foreach ( $m[2] as $token ) {
					$add_literal( $token, $file );
				}
			}

			// ── Shape 4: `className = 'x'` and `className += ' x'`.
			if ( preg_match_all( '/className\s*\+?=\s*(["\'])([^"\']*)\1/', $src, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $hit ) {
					foreach ( preg_split( '/\s+/', trim( $hit[2] ) ) ?: array() as $token ) {
						if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_-]*$/', $token ) ) {
							$add_literal( $token, $file );
						}
					}
				}
			}

			/*
			 * ── Shape 5: a loose string in a class context.
			 *
			 * This is what catches the ternary inside `esc_attr()`, the
			 * `var rowClass = 'past-row'` and the `sprintf( '…class="%s"…', $c )`.
			 *
			 * PROXIMITY is what makes this shape worth anything. Without it the
			 * scan counts every `wp_enqueue_style( 'ffc-…' )` handle and every
			 * option key as a class — and starts reporting that everything has an
			 * emitter, which was the first result when this was written: zero
			 * orphaned classes, because the net was catching the ocean.
			 */
			foreach ( self::near_class_context( $src ) as $window ) {
				/*
				 * The `\s*` on each side is not slack: the token arrives with its
				 * SEPARATOR space when it is concatenated onto an attribute that
				 * already exists —
				 * `'<table class="ffc-appointments-table' + (past ? ' past-appointments' : '') + '">'`.
				 * Without it the scan found no emitter for the dashboard's three
				 * `past-*` classes, and #1170 would have renamed them blind.
				 */
				if ( preg_match_all( '/(["\'])\s*((?:ffc-)?[a-z][a-z0-9]*(?:-[a-z0-9]+)+)\s*\1/i', $window, $m ) ) {
					foreach ( $m[2] as $token ) {
						$add_literal( $token, $file );
					}
				}

				/*
				 * ── Shape 5b: SEVERAL classes in one string.
				 *
				 * `'ffc-shortcode ffc-form-wrapper ffc-has-geofence'` and
				 * `'ffc-hierarchy-child ffc-hierarchy-level-' . $level`. Shape 5
				 * anchors on both quotes, so it sees only a single-token string
				 * and misses these -- what survived was the PREFIX of the last
				 * name, which shape 6 catches, while the whole names before it
				 * vanished.
				 *
				 * The discriminator is having TWO OR MORE tokens: the slack shape
				 * 5 cannot afford exists because a `wp_enqueue_style` handle is
				 * always a single token, and it is what made the first version of
				 * this scan say nothing was orphaned. Requiring the plural keeps
				 * the net closed against handles, option keys and capability
				 * slugs.
				 */
				if ( preg_match_all( self::STRING_LITERAL, $window, $m, PREG_SET_ORDER ) ) {
					foreach ( $m as $hit ) {
						$value  = ( isset( $hit[2] ) && '' !== $hit[2] ) ? $hit[2] : $hit[1];
						$tokens = preg_split( '/\s+/', trim( $value ) ) ?: array();

						if ( count( $tokens ) < 2 ) {
							continue;
						}

						foreach ( $tokens as $token ) {
							if ( preg_match( '/^(?:ffc-)?[a-z][a-z0-9]*(?:-[a-z0-9]+)+$/i', $token ) ) {
								$add_literal( $token, $file );
							}
						}
					}
				}

				/*
				 * ── Shape 6, the one that decides: the PREFIX of a name
				 * assembled at runtime. `'ffc-dashboard-status-' . $status` and
				 * `'ffc-status-' + item.status`. The full name exists nowhere, so
				 * searching for it finds nothing — and renaming it breaks with no
				 * warning.
				 *
				 * It requires `ffc-` PLUS a segment: the bare prefix `ffc-` would
				 * cover all 1,046 classes and declare none of them orphaned. It
				 * genuinely showed up, in `'ffc-' + Date.now()` — which builds an
				 * iCal event's UID, not a class.
				 */
				/*
				 * The prefix may sit at the END of a longer string rather than be
				 * the whole string — it is the commonest shape in JS that builds
				 * HTML: `'<td><span class="… ffc-dashboard-status-' +
				 * item.status`. Anchoring on the opening quote missed exactly
				 * those.
				 */
				if ( preg_match_all( '/(ffc-[a-z0-9]+(?:-{1,2}[a-z0-9]+)*-{1,2})(["\'])\s*[.+]/i', $window, $m ) ) {
					foreach ( $m[1] as $prefix ) {
						$add_prefix( $prefix, $file );
					}
				}
				if ( preg_match_all( '/(["\'])(ffc-[a-z0-9]+(?:-[a-z0-9]+)*-)\{?\$/i', $window, $m ) ) {
					foreach ( $m[2] as $prefix ) {
						$add_prefix( $prefix, $file );
					}
				}

				/*
				 * Two prefix shapes that are NOT concatenation and so escaped the
				 * first version of this scan. Each corresponds to a rename that
				 * would have broken in silence:
				 *
				 *  - the echo embedded in the attribute itself,
				 *    `class="ffc-audience-status-` followed by a PHP block;
				 *  - the `printf` placeholder,
				 *    `class="ffc-cap-origin--%7$s"`.
				 */
				if ( preg_match_all( '/(ffc-[a-z0-9]+(?:-{1,2}[a-z0-9]+)*-{1,2})<\?/i', $window, $m ) ) {
					foreach ( $m[1] as $prefix ) {
						$add_prefix( $prefix, $file );
					}
				}
				if ( preg_match_all( '/(ffc-[a-z0-9]+(?:-{1,2}[a-z0-9]+)*-{1,2})%[0-9]*\$?[sd]/i', $window, $m ) ) {
					foreach ( $m[1] as $prefix ) {
						$add_prefix( $prefix, $file );
					}
				}
			}
		}

		ksort( $literals );
		ksort( $prefixes );

		self::$cache = array(
			'literals' => $literals,
			'prefixes' => $prefixes,
		);

		return self::$cache;
	}


	/**
	 * Slices of the source around something that talks about classes.
	 *
	 * The signal is the word `class` in any of its forms of use — the attribute,
	 * `classList`, `addClass`, `className`, or a variable named `rowClass` —
	 * plus the name of a HELPER that takes classes as positional arguments.
	 * `BadgeHtml::render( 'ffc-recruitment-subscription-badge', … )` has the word
	 * `class` nowhere in the call: it is in the PARAMETER's name, which lives in
	 * the definition and not at the site. Two classes had no findable emitter
	 * that way, and surfaced only when #1193 started declaring them in a sheet —
	 * before that nobody was looking for them. The window is generous (240
	 * characters each side) because the goal here is **not to lose a site**, not
	 * to be exact: whoever reads the output is a person about to rename
	 * something, and a false positive costs a glance while a false negative
	 * costs a style that disappears.
	 *
	 * @param string $src File contents.
	 * @return array<int, string>
	 */
	private static function near_class_context( string $src ): array {
		$out = array();

		if ( ! preg_match_all( '/[A-Za-z]*(?:class(?:List|Name)?|cls)[A-Za-z]*|BadgeHtml/i', $src, $m, PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}

		foreach ( $m[0] as $hit ) {
			$start = max( 0, $hit[1] - 240 );
			$out[] = substr( $src, $start, 480 + strlen( $hit[0] ) );
		}

		return $out;
	}

	/**
	 * Class => the files that emit it as a literal.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function literals(): array {
		return self::scan()['literals'];
	}

	/**
	 * Dynamic prefix => the files that concatenate it.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function prefixes(): array {
		return self::scan()['prefixes'];
	}

	/**
	 * Where a class is emitted — literally, or through a prefix.
	 *
	 * The second case returns the prefix that covers it, because that is the
	 * information a renamer needs: the emission site names the prefix, not the
	 * class, and it is the prefix that has to change.
	 *
	 * @param string $class Class name, without the dot.
	 * @return array{how: string, prefix: string, files: array<int, string>}
	 */
	public static function of( string $class ): array {
		$scan = self::scan();

		if ( isset( $scan['literals'][ $class ] ) ) {
			return array(
				'how'    => 'literal',
				'prefix' => '',
				'files'  => $scan['literals'][ $class ],
			);
		}

		// The LONGEST prefix that covers it — the most specific one is what
		// actually describes the emission site.
		$best = '';
		foreach ( array_keys( $scan['prefixes'] ) as $prefix ) {
			if ( str_starts_with( $class, $prefix ) && strlen( $prefix ) > strlen( $best ) ) {
				$best = $prefix;
			}
		}

		if ( '' !== $best ) {
			return array(
				'how'    => 'prefix',
				'prefix' => $best,
				'files'  => $scan['prefixes'][ $best ],
			);
		}

		return array(
			'how'    => 'none',
			'prefix' => '',
			'files'  => array(),
		);
	}

	/**
	 * Every `ffc-*` class the sheets declare.
	 *
	 * @return array<int, string>
	 */
	public static function declared_ffc_classes(): array {
		$out = array();

		foreach ( CssSelectors::sheets() as $path ) {
			foreach ( CssSelectors::of( (string) file_get_contents( $path ) ) as $selector ) {
				if ( preg_match_all( '/\.(ffc-[A-Za-z0-9_-]+)/', $selector, $m ) ) {
					foreach ( $m[1] as $class ) {
						$out[ $class ] = true;
					}
				}
			}
		}

		$names = array_keys( $out );
		sort( $names );

		return $names;
	}
}
