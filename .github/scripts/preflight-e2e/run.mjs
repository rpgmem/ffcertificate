/**
 * End-to-end baseline of the visitor pre-flight on a real WordPress (#2030).
 *
 * Usage:
 *   node .github/scripts/preflight-e2e/run.mjs <wp-dir> <manifest.json> [baseline.json]
 *
 * <wp-dir> is a throwaway install seeded by seed.php and served at the URLs
 * the manifest names; run.mjs calls seed.php itself to switch the captcha
 * provider for the ALTCHA case and back. Each scenario drives Chromium the
 * way a visitor would and records what the visitor ends up seeing, so the
 * Vitest baselines (which mock the browser) are checked against the real
 * plugin once. With FFC_UPDATE_PREFLIGHT_BASELINE=1 the result is written to
 * the baseline file; otherwise it is compared with it and the run fails on
 * any difference. The baseline is a record for one install, not a CI gate.
 */

import { chromium } from 'playwright';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const [ wpDir, manifestPath, baselinePath = join( here, 'baseline.json' ) ] = process.argv.slice( 2 );
if ( ! wpDir || ! manifestPath ) {
	console.error( 'Usage: node run.mjs <wp-dir> <manifest.json> [baseline.json]' );
	process.exit( 1 );
}
const m = JSON.parse( readFileSync( manifestPath, 'utf8' ) );
const base = new URL( m.pages.plain ).origin;
const wpLoad = join( wpDir, 'wp-load.php' );

const php = ( ...args ) => execFileSync( 'php', args, { encoding: 'utf8' } ).trim();
const setCaptcha = ( mode ) => php( join( here, 'seed.php' ), wpLoad, `--captcha=${ mode }` );
const setBrowserCheck = ( on ) => php( join( here, 'seed.php' ), wpLoad, `--browser-check=${ on ? 1 : 0 }` );
const wp = ( ...args ) => execFileSync( 'wp', [ ...args, `--path=${ wpDir }`, '--allow-root' ], { encoding: 'utf8' } ).trim();
const submissions = () => Number( wp( 'db', 'query', 'SELECT COUNT(*) FROM wp_ffc_submissions', '--skip-column-names' ) );

const WORDS = [ 'zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
	'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty' ];

/**
 * Answer the math challenge the way a person reads it.
 *
 * @param {string} label "Security: How much is 9 times two?"
 * @returns {number}
 */
function solveMath( label ) {
	const m2 = label.match( /How much is (\S+) (\S+) (\S+?)\?/ );
	if ( ! m2 ) {
		throw new Error( `Unreadable challenge: ${ label }` );
	}
	const num = ( t ) => ( /^\d+$/.test( t ) ? Number( t ) : WORDS.indexOf( t.toLowerCase() ) );
	const [ a, op, b ] = [ num( m2[ 1 ] ), m2[ 2 ].toLowerCase(), num( m2[ 3 ] ) ];
	if ( a < 0 || b < 0 ) {
		throw new Error( `Unreadable operand: ${ label }` );
	}
	if ( [ '+', 'plus' ].includes( op ) ) {
		return a + b;
	}
	if ( [ '-', '−', 'minus' ].includes( op ) ) {
		return a - b;
	}
	if ( [ '×', 'x', '*', 'times' ].includes( op ) ) {
		return a * b;
	}
	throw new Error( `Unknown operator: ${ label }` );
}

/**
 * What the visitor sees in the plugin's block: which notices, by hook and
 * text, and whether the title and the form are on screen.
 *
 * @param {import('playwright').Page} page
 */
async function snapshot( page ) {
	return page.evaluate( () => {
		const visible = ( el ) => !! el && !! ( el.offsetWidth || el.offsetHeight || el.getClientRects().length )
			&& getComputedStyle( el ).visibility !== 'hidden';
		const root = document.querySelector( '.ffc-form-wrapper, .ffc-geofence-content' );
		const notices = [ ...document.querySelectorAll( '[data-ffc-notice]' ) ]
			.filter( visible )
			.map( ( n ) => ( { hook: n.getAttribute( 'data-ffc-notice' ), text: n.innerText.replace( /\s+/g, ' ' ).trim() } ) );
		return {
			formShown: visible( document.querySelector( 'form.ffc-submission-form' ) ),
			titleShown: visible( root && root.querySelector( '.ffc-form-title' ) ),
			contentShown: visible( document.querySelector( '.e2e-secret' ) ),
			notices,
		};
	} );
}

/**
 * Let the pre-flight finish: network idle, then a pause for its timers.
 *
 * @param {import('playwright').Page} page
 */
async function settle( page ) {
	await page.waitForLoadState( 'networkidle' );
	await page.waitForTimeout( 1500 );
}

/**
 * Fill the form and submit it; return what the visitor sees afterwards.
 *
 * @param {import('playwright').Page} page
 * @param {{name: string, email: string, cpf: string}} who
 * @param {'math'|'altcha'} captcha
 */
async function submit( page, who, captcha ) {
	await page.fill( 'input[name="name"]', who.name );
	await page.fill( 'input[name="email"]', who.email );
	await page.fill( 'input[name="cpf_rf"]', who.cpf );
	const consent = page.locator( 'input[name="ffc_lgpd_consent"]' );
	if ( await consent.count() ) {
		await consent.check( { force: true } );
	}
	if ( 'math' === captcha ) {
		const label = await page.locator( '.ffc-captcha-label-text' ).innerText();
		await page.fill( 'input[name="ffc_captcha_ans"]', String( solveMath( label ) ) );
	} else {
		// The widget turns the tick into its "verifying" state, not "checked".
		await page.locator( 'altcha-widget input[type="checkbox"]' ).click( { force: true } );
		await page.waitForFunction( () => {
			const input = document.querySelector( 'altcha-widget input[name="altcha"], input[name="altcha"]' );
			return input && input.value;
		}, null, { timeout: 30000 } );
	}
	const download = page.waitForEvent( 'download', { timeout: 15000 } ).then( ( d ) => d.suggestedFilename(), () => null );
	await page.locator( 'form.ffc-submission-form [type="submit"]' ).click();
	await page.waitForSelector( '.ffc-success-where-is-file, [data-ffc-notice="error"], .ffc-form-error, .ffc-message-error', { timeout: 20000 } )
		.catch( async ( e ) => {
			console.error( ( await page.locator( '.ffc-form-wrapper' ).innerHTML() ).replace( /\s+/g, ' ' ).slice( -2500 ) );
			throw e;
		} );
	const file = await download;
	const shown = await page.evaluate( () => ( {
		successCard: !! document.querySelector( '.ffc-success-where-is-file' ),
		platformLines: [ ...document.querySelectorAll( '.ffc-success-where-is-file li[data-platform]' ) ]
			.filter( ( li ) => getComputedStyle( li ).display !== 'none' )
			.map( ( li ) => li.getAttribute( 'data-platform' ) ),
		error: ( document.querySelector( '[data-ffc-notice="error"], .ffc-form-error, .ffc-message-error' ) || {} ).innerText || '',
	} ) );
	// The file name carries the submission id and its random auth code.
	return { ...shown, pdf: file ? file.replace( /_\d+_/, '_N_' ).replace( /_C-[A-Z0-9]+/, '_C-CODE' ) : null, code: file ? ( file.match( /C-[A-Z0-9]+/ ) || [ '' ] )[ 0 ] : '' };
}

const results = {};
const record = ( name, value ) => {
	results[ name ] = value;
	console.log( name, JSON.stringify( value ) );
};

setCaptcha( 'math' );
const browser = await chromium.launch();
const visitor = { name: 'Maria Teste Silva', email: 'maria.teste@example.org', cpf: '529.982.247-25' };

/**
 * A fresh signed-out visitor.
 *
 * @param {object} options Context options (geolocation, permissions…).
 * @param {string} [initScript] Script run before the page's own.
 */
async function visit( options = {}, initScript ) {
	const context = await browser.newContext( { acceptDownloads: true, ...options } );
	if ( initScript ) {
		await context.addInitScript( initScript );
	}
	return { context, page: await context.newPage() };
}

// 1 + 5. Plain form, math captcha: first issue, then the same person again.
{
	const before = submissions();
	const { context, page } = await visit();
	await page.goto( m.pages.plain );
	await settle( page );
	const first = await submit( page, visitor, 'math' );
	await page.goto( m.pages.plain );
	await settle( page );
	const again = await submit( page, visitor, 'math' );
	const sameCode = '' !== first.code && first.code === again.code;
	delete first.code;
	delete again.code;
	record( 'plain-math-issue', { ...first, rowsAdded: submissions() - before } );
	record( 'plain-math-reprint', { ...again, sameCode, rowsAddedTotal: submissions() - before } );
	await context.close();
}

// 2. GPS form: inside the area, outside it, permission refused.
const inside = { latitude: m.area.lat, longitude: m.area.lng, accuracy: 20 };
const outside = { latitude: m.area.lat + 1, longitude: m.area.lng + 1, accuracy: 20 };
for ( const [ name, options ] of Object.entries( {
	'gps-inside': { geolocation: inside, permissions: [ 'geolocation' ] },
	'gps-outside': { geolocation: outside, permissions: [ 'geolocation' ] },
	'gps-denied': { permissions: [] },
} ) ) {
	const { context, page } = await visit( options );
	await page.goto( m.pages.gps );
	await settle( page );
	record( name, await snapshot( page ) );
	await context.close();
}

// 3. A form whose period is over.
{
	const { context, page } = await visit();
	await page.goto( m.pages.closed );
	await settle( page );
	record( 'closed-period', await snapshot( page ) );
	await context.close();
}

// 4. Cookies refused by the browser.
{
	const { context, page } = await visit( { geolocation: inside, permissions: [ 'geolocation' ] },
		"Object.defineProperty(Navigator.prototype, 'cookieEnabled', { get: () => false });" );
	await page.goto( m.pages.gps );
	await settle( page );
	record( 'cookies-blocked', await snapshot( page ) );
	await context.close();
}

// 4b. The checks every form runs since #2030 PR 4: cookies on a form with no
// restriction, an app's own browser (stopped, then "Continue anyway"), and
// the same browser with Settings → Certificates → Browser check off.
{
	const { context, page } = await visit( {},
		"Object.defineProperty(Navigator.prototype, 'cookieEnabled', { get: () => false });" );
	await page.goto( m.pages.plain );
	await settle( page );
	record( 'plain-cookies-blocked', await snapshot( page ) );
	await context.close();
}
{
	const userAgent = 'Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.81 Mobile Safari/537.36 Instagram 350.0.0.42.92 Android';
	const { context, page } = await visit( { userAgent } );
	await page.goto( m.pages.plain );
	await settle( page );
	record( 'plain-inapp', await snapshot( page ) );
	await page.click( '.ffc-preflight-continue' );
	await settle( page );
	record( 'plain-inapp-continue', await snapshot( page ) );
	await context.close();

	setBrowserCheck( false );
	try {
		const off = await visit( { userAgent } );
		await off.page.goto( m.pages.plain );
		await settle( off.page );
		record( 'plain-inapp-check-off', await snapshot( off.page ) );
		await off.context.close();
	} finally {
		setBrowserCheck( true );
	}
}

// 6. Gated content: inside the area, permission refused.
for ( const [ name, options ] of Object.entries( {
	'content-inside': { geolocation: inside, permissions: [ 'geolocation' ] },
	'content-denied': { permissions: [] },
} ) ) {
	const { context, page } = await visit( options );
	await page.goto( m.pages.content );
	await settle( page );
	record( name, await snapshot( page ) );
	await context.close();
}

// 7. The identity-correction preview is wired: registered for signed-in users
// only (a nonce failure answers -1/403; an unknown action answers 0/400).
{
	const { context, page } = await visit();
	const ajax = `${ base }/wp-admin/admin-ajax.php`;
	const probe = async () => {
		const r = await page.request.post( ajax, { form: { action: 'ffc_identity_correction_preview', nonce: 'x' } } );
		return { status: r.status(), body: ( await r.text() ).slice( 0, 20 ) };
	};
	const signedOut = await probe();
	await page.goto( `${ base }/wp-login.php` );
	await page.fill( '#user_login', process.env.FFC_E2E_USER || 'admin' );
	await page.fill( '#user_pass', process.env.FFC_E2E_PASS || 'admin' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
	record( 'identity-correction-preview-wiring', { signedOut, signedIn: await probe() } );
	await context.close();
}

// 9. Boot watchdog (#2030 PR 1): a form whose scripts all load is marked
// ready; one whose script, or whose captcha and nonce refresh, did not
// complete shows the notice at the deadline and offers no submit.
const boot = async ( page ) => ( {
	...( await snapshot( page ) ),
	ready: await page.evaluate( () => document.querySelector( '.ffc-form-wrapper' ).getAttribute( 'data-ffc-ready' ) ),
	submitDisabled: await page.evaluate( () => !! document.querySelector( 'form.ffc-submission-form [type="submit"]' ).disabled ),
} );
const deadline = 11000;
{
	const { context, page } = await visit();
	await page.goto( m.pages.plain );
	await settle( page );
	record( 'boot-ready', await boot( page ) );
	await context.close();
}
for ( const [ name, pattern ] of Object.entries( {
	'boot-script-blocked': '**/ffc-frontend*.js*',
	'boot-pdf-library-blocked': '**/libs/js/jspdf-*',
} ) ) {
	const { context, page } = await visit();
	await page.route( pattern, ( r ) => r.abort() );
	await page.goto( m.pages.plain );
	await page.waitForTimeout( deadline );
	record( name, await boot( page ) );
	await context.close();
}
{
	const { context, page } = await visit();
	await page.route( '**/admin-ajax.php', async ( r ) => (
		( r.request().postData() || '' ).includes( 'action=ffc_get_dynamic_fragments' ) ? r.fulfill( { status: 500, body: '' } ) : r.continue()
	) );
	await page.goto( m.pages.plain );
	await page.waitForTimeout( deadline );
	record( 'boot-fragments-failed', await boot( page ) );
	await context.close();
}

// 10. JavaScript off (#2030 PR 1): the notice says so, and a submit the
// browser performs itself is a POST that keeps the fields out of the URL.
{
	const { context, page } = await visit( { javaScriptEnabled: false } );
	await page.goto( m.pages.plain );
	const shown = await snapshot( page );
	await page.fill( 'input[name="name"]', visitor.name );
	await page.fill( 'input[name="email"]', visitor.email );
	await page.fill( 'input[name="cpf_rf"]', visitor.cpf );
	const [ request ] = await Promise.all( [
		page.waitForRequest( ( r ) => r.isNavigationRequest() && r.frame() === page.mainFrame() ),
		page.evaluate( () => document.querySelector( 'form.ffc-submission-form' ).submit() ),
	] );
	record( 'no-javascript', { ...shown, method: request.method(), queryInUrl: new URL( request.url() ).search !== '' } );
	await context.close();
}

// 8. ALTCHA: the same plain form under the proof-of-work captcha.
try {
	setCaptcha( 'altcha' );
	const before = submissions();
	const { context, page } = await visit();
	await page.goto( m.pages.plain );
	await settle( page );
	const outcome = await submit( page, { ...visitor, email: 'joao.teste@example.org', cpf: '111.444.777-35', name: 'Joao Teste Souza' }, 'altcha' );
	delete outcome.code;
	record( 'altcha-issue', { ...outcome, rowsAdded: submissions() - before } );
	await context.close();
} finally {
	setCaptcha( 'math' );
}

await browser.close();

if ( process.env.FFC_UPDATE_PREFLIGHT_BASELINE ) {
	writeFileSync( baselinePath, JSON.stringify( results, null, '\t' ) + '\n' );
	console.log( `baseline written: ${ baselinePath }` );
	process.exit( 0 );
}
if ( ! existsSync( baselinePath ) ) {
	console.error( `No baseline at ${ baselinePath }; record one with FFC_UPDATE_PREFLIGHT_BASELINE=1.` );
	process.exit( 1 );
}
const expected = JSON.parse( readFileSync( baselinePath, 'utf8' ) );
const changed = [ ...new Set( [ ...Object.keys( expected ), ...Object.keys( results ) ] ) ]
	.filter( ( k ) => JSON.stringify( expected[ k ] ) !== JSON.stringify( results[ k ] ) );
if ( changed.length ) {
	console.error( `Changed against the baseline: ${ changed.join( ', ' ) }` );
	process.exit( 1 );
}
console.log( `all ${ Object.keys( results ).length } scenarios match the baseline` );
