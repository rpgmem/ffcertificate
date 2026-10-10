/**
 * Capture the README screenshots from a WordPress seeded by seed.php.
 *
 * Usage:
 *   node .github/scripts/screenshots/capture.mjs <base-url> <manifest.json> [out-dir]
 *
 * The admin account is read from FFC_SHOT_USER / FFC_SHOT_PASS (default
 * admin / admin, which is what a throwaway install uses). FFC_SHOT_RESOLVE maps
 * the base URL's host to a local address (`127.0.0.1:8080`), so the site can
 * run under a fictitious domain and the URLs in the shots stay neutral.
 *
 * Every shot is the light theme except one, which switches the plugin's Dark
 * Mode setting on for that capture and back off afterwards. Public pages are
 * captured signed out, so no admin bar crosses them.
 */

import { chromium } from 'playwright';
import { readFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';

const [ base, manifestPath, outDir = 'docs/screenshots' ] = process.argv.slice( 2 );
if ( ! base || ! manifestPath ) {
	console.error( 'Usage: node capture.mjs <base-url> <manifest.json> [out-dir]' );
	process.exit( 1 );
}
const m = JSON.parse( readFileSync( manifestPath, 'utf8' ) );
const user = process.env.FFC_SHOT_USER || 'admin';
const pass = process.env.FFC_SHOT_PASS || 'admin';
mkdirSync( outDir, { recursive: true } );

const admin = ( path ) => `${ base }/wp-admin/${ path }`;

async function login( page, login, password ) {
	await page.goto( `${ base }/wp-login.php` );
	await page.fill( '#user_login', login );
	await page.fill( '#user_pass', password );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
}

async function open( page, url ) {
	await page.goto( url );
	await page.waitForLoadState( 'networkidle' );
}

async function shot( page, name, settle = 300 ) {
	// Park the pointer where it hovers nothing: the corner holds the admin bar.
	await page.mouse.move( 1439, 899 );
	await page.waitForTimeout( settle );
	await page.screenshot( { path: join( outDir, `${ name }.png` ) } );
	console.log( name );
}

async function setDarkMode( page, value ) {
	await open( page, admin( 'admin.php?page=ffc-settings&tab=general' ) );
	const saved = page.waitForResponse( ( r ) => r.url().includes( 'admin-ajax.php' ) && r.request().method() === 'POST' );
	await page.selectOption( '#ffc_dark_mode', value );
	await saved;
}

// Scroll a public page so its title sits under the top edge, past the theme
// header that would otherwise fill the first screen.
async function toTitle( page ) {
	await page.evaluate( () => {
		const title = document.querySelector( 'h1.wp-block-post-title, main h1' );
		if ( title ) {
			window.scrollTo( 0, title.getBoundingClientRect().top + window.scrollY - 32 );
		}
	} );
}

const resolve = process.env.FFC_SHOT_RESOLVE;
const browser = await chromium.launch(
	resolve ? { args: [ `--host-resolver-rules=MAP ${ new URL( base ).hostname } ${ resolve }` ] } : {}
);
const context = await browser.newContext( { viewport: { width: 1440, height: 900 } } );
const page = await context.newPage();
await login( page, user, pass );

// Certificates.
await open( page, admin( 'edit.php?post_type=ffc_form&page=ffc-certificates-dashboard' ) );
await shot( page, '01-certificates-dashboard' );

await open( page, admin( `post.php?post=${ m.form_id }&action=edit` ) );
await page.click( '#ffc-tabnav-builder' );
await shot( page, '02-form-builder' );

await page.click( '#ffc-tabnav-layout' );
await page.click( '#ffc_btn_preview' );
await page.waitForTimeout( 1500 );
await shot( page, '03-certificate-preview' );

await open( page, admin( 'edit.php?post_type=ffc_form&page=ffc-submissions' ) );
await shot( page, '04-submissions' );

// Public side, signed out.
const visitor = await ( await browser.newContext( { viewport: { width: 1440, height: 900 } } ) ).newPage();
await open( visitor, m.pages[ 'Get your certificate' ] );
await toTitle( visitor );
await shot( visitor, '05-public-form' );

await open( visitor, m.certificate_url );
await visitor.waitForTimeout( 1500 );
await toTitle( visitor );
await shot( visitor, '06-certificate-verification' );

await open( visitor, m.pages[ 'Book a meeting' ] );
await toTitle( visitor );
await shot( visitor, '07-booking-calendar' );

await open( page, admin( 'admin.php?page=ffc-appointments' ) );
await shot( page, '08-appointments' );

await open( visitor, m.pages[ 'Room calendar' ] );
await visitor.waitForTimeout( 1000 );
await toTitle( visitor );
await shot( visitor, '09-room-calendar' );

// Date Messages.
await open( page, admin( `admin.php?page=ffc-date-messages&rule=${ m.rule_id }` ) );
await page.click( '.ffc-dm-message-button' );
await page.waitForSelector( '.ffc-dm-message:not([hidden])' );
await page.waitForTimeout( 1500 );
await page.locator( '.ffc-dm-message' ).scrollIntoViewIfNeeded();
await page.evaluate( () => window.scrollBy( 0, -120 ) );
await shot( page, '10-date-message-preview' );

// Short URLs and QR codes.
await open( page, admin( 'admin.php?page=ffc-qr-generator' ) );
await page.fill( '#ffc-qr-url', 'https://example.org/events/digital-literacy-2026' );
await page.fill( '#ffc-qr-short-title', 'Digital Literacy Workshop' );
await page.waitForTimeout( 2000 );
await shot( page, '11-qr-generator' );

await open( page, admin( 'admin.php?page=ffc-short-urls' ) );
await shot( page, '12-short-urls' );

// The one dark capture.
await setDarkMode( page, 'on' );
await open( page, admin( 'edit.php?post_type=ffc_form&page=ffc-certificates-dashboard' ) );
await shot( page, '13-dark-mode', 1500 );
await setDarkMode( page, 'off' );

// The participant's own dashboard.
const participant = await browser.newContext( { viewport: { width: 1440, height: 900 } } );
const p2 = await participant.newPage();
await login( p2, m.participant.login, m.participant.password );
await open( p2, m.dashboard_url );
await toTitle( p2 );
await shot( p2, '14-user-dashboard' );

await browser.close();
