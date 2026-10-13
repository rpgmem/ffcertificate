/**
 * "View details" modal: a screenshot viewer instead of a new tab.
 *
 * WordPress renders the modal's Screenshots tab as links to the full images,
 * so a click leaves the modal for a bare image in another tab. This opens the
 * image in an overlay inside the modal instead, with the caption and a
 * counter. The arrows, the keyboard and a swipe move between screenshots; a
 * click on the image, the wheel, a pinch or the zoom buttons enlarge it, and
 * an enlarged image can be dragged.
 *
 * Progressive enhancement, no dependencies: without this script each link
 * still opens its image, as before. Labels come from `ffcScreenshots`,
 * localized by the PHP that enqueues this file.
 */
( function () {
	'use strict';

	var MIN = 1;
	var MAX = 4;
	var STEP = 1.25;
	var SWIPE = 50;

	var labels = Object.assign(
		{
			dialog: 'Screenshots',
			close: 'Close',
			previous: 'Previous screenshot',
			next: 'Next screenshot',
			zoomIn: 'Zoom in',
			zoomOut: 'Zoom out',
			counter: '%1$s of %2$s',
		},
		window.ffcScreenshots || {}
	);

	var links = [];
	var index = 0;
	var scale = MIN;
	var x = 0;
	var y = 0;
	var trigger = null;
	var pointers = {};
	var gesture = null;
	var ui = null;

	function button( spec ) {
		var el = document.createElement( 'button' );
		el.type = 'button';
		el.className = spec.className;
		el.setAttribute( 'aria-label', spec.label );
		el.title = spec.label;
		el.textContent = spec.glyph;
		return el;
	}

	function build() {
		var root = document.createElement( 'div' );
		root.className = 'ffc-shots';
		root.hidden = true;
		root.setAttribute( 'role', 'dialog' );
		root.setAttribute( 'aria-modal', 'true' );
		root.setAttribute( 'aria-label', labels.dialog );

		var stage = document.createElement( 'div' );
		stage.className = 'ffc-shots-stage';
		var img = document.createElement( 'img' );
		img.className = 'ffc-shots-img';
		img.alt = '';
		img.draggable = false;
		stage.appendChild( img );

		var bar = document.createElement( 'div' );
		bar.className = 'ffc-shots-bar';
		var caption = document.createElement( 'p' );
		caption.className = 'ffc-shots-caption';
		var count = document.createElement( 'span' );
		count.className = 'ffc-shots-count';
		count.setAttribute( 'aria-live', 'polite' );
		bar.appendChild( count );
		bar.appendChild( caption );

		var controls = {
			zoomOut: button( { className: 'ffc-shots-button ffc-shots-zoom-out', label: labels.zoomOut, glyph: '−' } ),
			zoomIn: button( { className: 'ffc-shots-button ffc-shots-zoom-in', label: labels.zoomIn, glyph: '+' } ),
			close: button( { className: 'ffc-shots-button ffc-shots-close', label: labels.close, glyph: '×' } ),
			previous: button( { className: 'ffc-shots-button ffc-shots-previous', label: labels.previous, glyph: '‹' } ),
			next: button( { className: 'ffc-shots-button ffc-shots-next', label: labels.next, glyph: '›' } ),
		};

		var tools = document.createElement( 'div' );
		tools.className = 'ffc-shots-tools';
		tools.appendChild( controls.zoomOut );
		tools.appendChild( controls.zoomIn );
		tools.appendChild( controls.close );

		root.appendChild( stage );
		root.appendChild( controls.previous );
		root.appendChild( controls.next );
		root.appendChild( tools );
		root.appendChild( bar );
		document.body.appendChild( root );

		controls.close.addEventListener( 'click', close );
		controls.previous.addEventListener( 'click', function () {
			show( index - 1 );
		} );
		controls.next.addEventListener( 'click', function () {
			show( index + 1 );
		} );
		controls.zoomIn.addEventListener( 'click', function () {
			zoom( scale * STEP );
		} );
		controls.zoomOut.addEventListener( 'click', function () {
			zoom( scale / STEP );
		} );

		// A click on the backdrop, not on the image or a control, closes.
		root.addEventListener( 'click', function ( event ) {
			if ( event.target === root || event.target === stage ) {
				close();
			}
		} );
		stage.addEventListener( 'wheel', onWheel, { passive: false } );
		stage.addEventListener( 'pointerdown', onPointerDown );
		stage.addEventListener( 'pointermove', onPointerMove );
		stage.addEventListener( 'pointerup', onPointerUp );
		stage.addEventListener( 'pointercancel', onPointerUp );
		root.addEventListener( 'keydown', onKey );

		return {
			root: root,
			img: img,
			caption: caption,
			count: count,
			controls: controls,
		};
	}

	function render() {
		ui.img.style.transform = 'translate(' + x + 'px, ' + y + 'px) scale(' + scale + ')';
		ui.root.classList.toggle( 'is-zoomed', scale > MIN );
		ui.controls.zoomOut.disabled = scale <= MIN;
		ui.controls.zoomIn.disabled = scale >= MAX;
	}

	function zoom( to ) {
		scale = Math.min( MAX, Math.max( MIN, to ) );
		if ( scale === MIN ) {
			x = 0;
			y = 0;
		}
		render();
	}

	function show( to ) {
		index = ( to + links.length ) % links.length;
		var link = links[ index ];
		var thumb = link.querySelector( 'img' );
		var text = thumb ? thumb.getAttribute( 'alt' ) || '' : '';

		ui.img.src = link.getAttribute( 'href' );
		ui.img.alt = text;
		ui.caption.textContent = text;
		ui.count.textContent = labels.counter.replace( '%1$s', String( index + 1 ) ).replace( '%2$s', String( links.length ) );

		var single = links.length < 2;
		ui.controls.previous.hidden = single;
		ui.controls.next.hidden = single;

		scale = MIN;
		x = 0;
		y = 0;
		render();
	}

	function open( link ) {
		ui = ui || build();
		trigger = link;
		ui.root.hidden = false;
		document.documentElement.classList.add( 'ffc-shots-open' );
		show( links.indexOf( link ) );
		ui.controls.close.focus();
	}

	function close() {
		ui.root.hidden = true;
		document.documentElement.classList.remove( 'ffc-shots-open' );
		pointers = {};
		gesture = null;
		if ( trigger ) {
			trigger.focus();
		}
	}

	function onKey( event ) {
		switch ( event.key ) {
			case 'Escape':
				close();
				break;
			case 'ArrowLeft':
				show( index - 1 );
				break;
			case 'ArrowRight':
				show( index + 1 );
				break;
			case '+':
			case '=':
				zoom( scale * STEP );
				break;
			case '-':
				zoom( scale / STEP );
				break;
			case '0':
				zoom( MIN );
				break;
			case 'Tab':
				trap( event );
				return;
			default:
				return;
		}
		event.preventDefault();
	}

	// Keeps Tab inside the dialog while it is open.
	function trap( event ) {
		var focusable = Array.prototype.filter.call( ui.root.querySelectorAll( 'button' ), function ( el ) {
			return ! el.hidden && ! el.disabled;
		} );
		var first = focusable[ 0 ];
		var last = focusable[ focusable.length - 1 ];
		if ( event.shiftKey && document.activeElement === first ) {
			last.focus();
			event.preventDefault();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			first.focus();
			event.preventDefault();
		}
	}

	function onWheel( event ) {
		event.preventDefault();
		zoom( event.deltaY < 0 ? scale * STEP : scale / STEP );
	}

	function distance() {
		var points = Object.keys( pointers ).map( function ( id ) {
			return pointers[ id ];
		} );
		return Math.hypot( points[ 0 ].x - points[ 1 ].x, points[ 0 ].y - points[ 1 ].y );
	}

	function onPointerDown( event ) {
		pointers[ event.pointerId ] = { x: event.clientX, y: event.clientY };
		if ( event.target.setPointerCapture ) {
			event.target.setPointerCapture( event.pointerId );
		}
		if ( Object.keys( pointers ).length === 2 ) {
			gesture = { type: 'pinch', start: distance(), scale: scale };
		} else {
			gesture = { type: 'drag', startX: event.clientX, startY: event.clientY, x: x, y: y, moved: false };
		}
	}

	function onPointerMove( event ) {
		if ( ! gesture || ! pointers[ event.pointerId ] ) {
			return;
		}
		pointers[ event.pointerId ] = { x: event.clientX, y: event.clientY };

		if ( gesture.type === 'pinch' && Object.keys( pointers ).length === 2 ) {
			zoom( gesture.scale * ( distance() / gesture.start ) );
			return;
		}

		var dx = event.clientX - gesture.startX;
		var dy = event.clientY - gesture.startY;
		if ( Math.abs( dx ) > 3 || Math.abs( dy ) > 3 ) {
			gesture.moved = true;
		}
		if ( scale > MIN ) {
			x = gesture.x + dx;
			y = gesture.y + dy;
			render();
		}
	}

	function onPointerUp( event ) {
		delete pointers[ event.pointerId ];
		if ( ! gesture ) {
			return;
		}
		var ended = gesture;
		gesture = Object.keys( pointers ).length ? gesture : null;

		if ( ended.type !== 'drag' ) {
			return;
		}
		var dx = event.clientX - ended.startX;
		if ( scale === MIN && Math.abs( dx ) >= SWIPE ) {
			// A horizontal swipe at the fitted size moves between screenshots.
			show( dx < 0 ? index + 1 : index - 1 );
		} else if ( ! ended.moved && event.target === ui.img ) {
			// A plain click on the image toggles between fitted and enlarged.
			zoom( scale > MIN ? MIN : 2 );
		}
	}

	function init() {
		links = Array.prototype.filter.call( document.querySelectorAll( '#section-screenshots a[href]' ), function ( link ) {
			return !! link.querySelector( 'img' );
		} );
		links.forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				if ( event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey ) {
					return; // A new tab when that is what the user asked for.
				}
				event.preventDefault();
				open( link );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
