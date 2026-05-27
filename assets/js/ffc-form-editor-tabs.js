/**
 * FFC — vertical-tab behaviour for the certificate form editor.
 *
 * Progressive enhancement for the `[data-ffc-form-tabs]` container rendered
 * by FormEditorMetaboxRenderer::render_tabbed_container(). On init it adds
 * the `is-ready` class (which lets the CSS hide inactive panels), wires the
 * WAI-ARIA tablist interaction (click + roving-tabindex arrow keys), and
 * keeps the active tab in sync with a `#ffc-tab-<key>` URL hash so a panel
 * can be deep-linked and survives reload / back-forward.
 *
 * When the layout tab becomes visible it refreshes any CodeMirror instance
 * inside it — CodeMirror mis-measures its gutters when initialised in a
 * `display:none` panel, so it needs a refresh once shown.
 *
 * @since 6.7.x
 */
( function ( $ ) {
	'use strict';

	var HASH_PREFIX  = 'ffc-tab-';
	var PANEL_PREFIX = 'ffc-tabpanel-';

	/**
	 * Tab key (e.g. "geofence") from a tab anchor, derived from its
	 * `aria-controls` panel id.
	 *
	 * @param {jQuery} $tab
	 * @returns {string}
	 */
	function tabKey( $tab ) {
		return String( $tab.attr( 'aria-controls' ) || '' ).replace( PANEL_PREFIX, '' );
	}

	/**
	 * Refresh CodeMirror editors inside a freshly-shown panel so they
	 * re-measure now they have layout.
	 *
	 * @param {jQuery} $panel
	 */
	function refreshCodeEditors( $panel ) {
		$panel.find( '.CodeMirror' ).each( function () {
			if ( this.CodeMirror && typeof this.CodeMirror.refresh === 'function' ) {
				this.CodeMirror.refresh();
			}
		} );
	}

	function setupContainer( $container ) {
		var $tabs = $container.find( '.ffc-form-tabs__tab' );
		if ( ! $tabs.length ) {
			return;
		}

		$container.addClass( 'is-ready' );

		/**
		 * Activate the tab whose panel key matches, updating ARIA state,
		 * panel visibility and (optionally) focus + URL hash.
		 *
		 * @param {string} key
		 * @param {{focus?: boolean, updateHash?: boolean}} [opts]
		 * @returns {boolean} Whether a matching tab was found.
		 */
		function activate( key, opts ) {
			opts = opts || {};
			var matched = false;

			$tabs.each( function () {
				var $tab     = $( this );
				var isTarget = tabKey( $tab ) === key;
				var $panel   = $( '#' + $tab.attr( 'aria-controls' ) );

				$tab
					.toggleClass( 'is-active', isTarget )
					.attr( 'aria-selected', isTarget ? 'true' : 'false' )
					.attr( 'tabindex', isTarget ? '0' : '-1' );
				$panel.toggleClass( 'is-active', isTarget );

				if ( isTarget ) {
					matched = true;
					if ( opts.focus ) {
						$tab.trigger( 'focus' );
					}
					refreshCodeEditors( $panel );
				}
			} );

			if ( matched && opts.updateHash ) {
				updateHash( key );
			}
			return matched;
		}

		function updateHash( key ) {
			var newHash = '#' + HASH_PREFIX + key;
			if ( window.history && window.history.replaceState ) {
				// replaceState avoids the scroll jump a direct hash write causes.
				window.history.replaceState( null, '', newHash );
			} else {
				window.location.hash = newHash;
			}
		}

		/**
		 * Tab key encoded in the current URL hash, or null when the hash is
		 * absent / doesn't map to a tab in this container.
		 *
		 * @returns {?string}
		 */
		function keyFromHash() {
			var raw = String( window.location.hash || '' ).replace( /^#/, '' );
			if ( raw.indexOf( HASH_PREFIX ) !== 0 ) {
				return null;
			}
			var key    = raw.slice( HASH_PREFIX.length );
			var exists = $container.find( '[aria-controls="' + PANEL_PREFIX + key + '"]' ).length > 0;
			return exists ? key : null;
		}

		$tabs.on( 'click', function ( e ) {
			e.preventDefault();
			activate( tabKey( $( this ) ), { focus: false, updateHash: true } );
		} );

		$tabs.on( 'keydown', function ( e ) {
			var idx  = $tabs.index( this );
			var next = null;
			switch ( e.key ) {
				case 'ArrowDown':
				case 'ArrowRight':
					next = ( idx + 1 ) % $tabs.length;
					break;
				case 'ArrowUp':
				case 'ArrowLeft':
					next = ( idx - 1 + $tabs.length ) % $tabs.length;
					break;
				case 'Home':
					next = 0;
					break;
				case 'End':
					next = $tabs.length - 1;
					break;
				default:
					return;
			}
			e.preventDefault();
			activate( tabKey( $tabs.eq( next ) ), { focus: true, updateHash: true } );
		} );

		// Reflect external hash changes (back/forward, deep links) onto the
		// active tab without rewriting the hash again.
		$( window ).on( 'hashchange.ffcFormTabs', function () {
			var key = keyFromHash();
			if ( key ) {
				activate( key, { focus: false, updateHash: false } );
			}
		} );

		/**
		 * Flag tabs whose panels hold a validation error from the last save
		 * (keys localised into `window.ffcFormTabsErrors`) with a `has-error`
		 * class + indicator dot, and open the first offending tab.
		 *
		 * @returns {boolean} Whether at least one error tab was flagged.
		 */
		function markErrorTabs() {
			var keys = window.ffcFormTabsErrors;
			if ( ! Array.isArray( keys ) || ! keys.length ) {
				return false;
			}
			var flagged = false;
			keys.forEach( function ( key ) {
				var $tab = $container.find( '[aria-controls="' + PANEL_PREFIX + key + '"]' );
				if ( ! $tab.length ) {
					return;
				}
				flagged = true;
				$tab.addClass( 'has-error' );
				if ( ! $tab.find( '.ffc-form-tabs__error-dot' ).length ) {
					$tab.append( '<span class="ffc-form-tabs__error-dot" aria-hidden="true"></span>' );
				}
			} );
			if ( flagged ) {
				activate( keys[0], { focus: false, updateHash: false } );
			}
			return flagged;
		}

		// Initial paint: honour a deep-link hash, else keep the first tab
		// (already marked active server-side). Don't rewrite the hash on load.
		var initialKey = keyFromHash() || tabKey( $tabs.first() );
		activate( initialKey, { focus: false, updateHash: false } );

		// A failed save overrides the initial tab so the operator lands on
		// the section they need to fix.
		markErrorTabs();
	}

	function initTabs( root ) {
		var $containers = root ? $( root ) : $( '[data-ffc-form-tabs]' );
		$containers.each( function () {
			setupContainer( $( this ) );
		} );
	}

	window.FFC = window.FFC || {};
	window.FFC.FormEditorTabs = { init: initTabs };

	$( function () {
		initTabs();
	} );
}( jQuery ) );
