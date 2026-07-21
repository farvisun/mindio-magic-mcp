/**
 * Completes the browser handoff from the local result screen to the client.
 */

( function () {
	'use strict';

	const result = document.querySelector( '[data-fmp-oauth-result]' );
	if ( ! result ) {
		return;
	}

	const target = result.getAttribute( 'data-redirect-target' );
	const delay = Number.parseInt( result.getAttribute( 'data-redirect-delay' ) || '1800', 10 );
	if ( ! target || ! Number.isFinite( delay ) ) {
		return;
	}

	let destination;
	try {
		destination = new URL( target, window.location.href );
	} catch ( error ) {
		return;
	}

	if ( ! [ 'http:', 'https:' ].includes( destination.protocol ) ) {
		return;
	}

	const timer = window.setTimeout( function () {
		window.location.replace( destination.href );
	}, Math.max( 500, Math.min( delay, 5000 ) ) );

	const continueLink = result.querySelector( 'a[href]' );
	if ( continueLink ) {
		continueLink.addEventListener( 'click', function () {
			window.clearTimeout( timer );
		} );
	}
}() );
