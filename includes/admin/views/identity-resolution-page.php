<?php
/**
 * Identity Resolution page markup.
 *
 * Rendered from `IdentityResolutionPage::render_page()`, which owns the
 * capability gate and supplies `$ffc_identity_findings`. Markup only -- the
 * same carve-out `phpstan.neon.dist` and `phpunit.xml.dist` make for this
 * directory.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.28.2
 *
 * @var array<int, array<string, mixed>> $ffc_identity_findings Findings from the check-digit scan.
 * @var array{type: string, text: string, code?: string, subject?: string}|false $ffc_identity_outcome Outcome of the last write, if any; a refusal carries its code and the finding it named.
 * @var IdentityResolutionScreen                                       $ffc_identity_screen   What the page derived: panels, cursors, coverage strips, counters.
 * @var bool                                                              $ffc_identity_rf_gate  Whether the submission form requires the RF check digit.
 * @var array<int, string>                                       $ffc_identity_capped   Checks that returned a full page.
 * @var int                                                      $ffc_identity_taken_at When the list was taken (unix).
 * @var bool                                                     $ffc_identity_may_split Whether the operator may open an account.
 * @var bool                                                     $ffc_identity_may_merge Whether the operator may merge two.
 * @var string                                                   $ffc_identity_export_url The audit CSV, or '' without the capability.
 * @var int                                                      $ffc_identity_resolved   Findings resolved since the queue was read.
 * @var callable                                                 $ffc_identity_facts      Account ids -> status, per-store counts and last activity.
 * @var callable                                                 $ffc_identity_proposal   Hash + field -> the address a split could inherit and the names on those records.
 */

// No `declare(strict_types=1)` here on purpose: none of the 17 view and
// template files in this repository carries one. A view is `require`d into a
// caller's scope, and the directive is per-file, so one view under a
// different calling convention from every sibling is a trap for anybody
// moving markup between them.

// A VIEW DOES NOT INHERIT THE IMPORTS OF WHATEVER `require`d IT.
//
// `render_page()` is in `FreeFormCertificate\Admin` and this file declares no
// namespace, so every unqualified class name here resolves against the GLOBAL
// namespace. A name missing from this list is a fatal the moment the line
// runs -- and PHPStan cannot see it, because `phpstan.neon.dist` excludes
// `includes/admin/views` as markup. `ViewClassImportTest` is what watches
// this list now.
use FreeFormCertificate\Admin\IdentityResolutionScreen;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// THIS FILE IS THE ORDER OF THE PAGE, AND NOTHING ELSE (#1925).
//
// It was 2,900 lines: the derivations ahead of the markup and every panel's
// markup in one file. What is derived is `IdentityResolutionScreen` now, the
// names and sentences are `IdentityResolutionLabels`, and each panel is a
// partial under `templates/admin/identity/`, required below in the order the
// page shows them. The partials share this scope -- they are this file, cut
// at its panel boundaries -- so the three closures below stay here, where
// every partial that calls them can see them.
//
// A partial's FIRST LINE carries no indentation; the line requiring it does.
// Indentation in front of `<?php` is output, so moving it onto the require is
// what keeps the page byte for byte what it was.

/**
 * A panel's header: what it is, where the operator is in it, and the way out.
 *
 * THE COUNTER IS `aria-live`, BECAUSE THE NAVIGATION IS OTHERWISE MUTE.
 *
 * Moving between findings replaces the panel's body and changes nothing a
 * screen reader announces on its own -- so somebody navigating by keyboard
 * would hear the link they activated and nothing about where they landed.
 *
 * @param array<string, mixed> $panel  The panel.
 * @param string               $label  Its name.
 * @param string               $note   One line on what the tier is.
 * @return void
 */
$ffc_identity_head = static function ( $panel, $label, $note ) use ( $ffc_identity_screen ) {
	require FFC_PLUGIN_DIR . 'templates/admin/identity/panel-head.php';
};

/**
 * Close the card a panel header opened.
 *
 * Paired with `$ffc_identity_head` rather than wrapping the body in it,
 * because each tier's body is markup that already exists and moving it into
 * a closure would put three tables through one more indirection for nothing.
 *
 * @return void
 */
$ffc_identity_foot = static function () {
	?>
	</div></div>
	<?php
};

/**
 * The acknowledgement a correction needs once its value is another account's.
 *
 * RENDERED ONLY AFTER THE OPERATOR HAS BEEN TOLD WHY (#1478).
 *
 * A correction whose confirmed value already belongs to another account is not
 * a merge -- no record moves and no account is absorbed -- so it is allowed,
 * once the operator states they mean it. What it cannot be is reflexive: the
 * write is not undoable by another use of the verb, because putting the old
 * value back means confirming a number that fails its own check digit, which
 * the service refuses first.
 *
 * WHICH IS NOT THE SAME AS `ONLY AFTER A REFUSAL`, AND THAT COST A RELEASE
 * (#1487).
 *
 * This used to render for a refusal and nothing else, and said so: `the box
 * appears on the one form the refusal came from`. On the repair form the
 * refusal carried no code, so the box was never drawn there -- and that is the
 * only form the refusal can arrive from. The gate held and the way through it
 * did not exist. The payload is fixed in `IdentityResolutionPage::outcome()`,
 * and this no longer depends on that being the only route.
 *
 * The argument that followed was that two of the three forms have no
 * correction preview, so a box revealed by a typed value could only appear on the
 * third. Both halves are true and the conclusion was backwards: the third form
 * is exactly the one that needs it, because the correction preview already ASKS whether
 * the value belongs to somebody and paints the answer. Revealing the box
 * beneath that sentence satisfies `told why` in one request instead of two.
 * So a form with a correction preview ships the box hidden and the correction preview reveals
 * it; a form without one still grows it from the refusal.
 *
 * @param string $subject   The finding this form acts on.
 * @param bool   $correction_preview Whether this form can reveal the box itself.
 * @return void
 */
$ffc_identity_ack = static function ( $subject, $correction_preview = false ) use ( $ffc_identity_outcome ) {
	$refused = IdentityResolutionScreen::refused( $ffc_identity_outcome, (string) $subject );

	// A form with no correction preview has nothing that could reveal a hidden box, so
	// there it is rendered only by the refusal that asks for it.
	if ( ! $refused && ! $correction_preview ) {
		return;
	}

	require FFC_PLUGIN_DIR . 'templates/admin/identity/ack.php';
};

?>
<div class="wrap ffc-admin-page ffc-page-identities">
	<h1><?php esc_html_e( 'Identity Resolution', 'ffcertificate' ); ?></h1>

	<?php if ( is_array( $ffc_identity_outcome ) && ! empty( $ffc_identity_outcome['text'] ) ) : ?>
		<?php
		\FreeFormCertificate\Core\AdminNotice::callout(
			(string) ( $ffc_identity_outcome['type'] ?? 'info' ),
			esc_html( (string) $ffc_identity_outcome['text'] )
		);
		?>
	<?php endif; ?>

	<p class="description">
		<?php esc_html_e( 'Accounts and stored numbers that do not agree with each other, sorted by how much of the answer is already known. The check digits are what sort them: they can say a number is wrong, and never what the right one is. Identifiers are shown as a hash prefix — the values are decrypted in memory to be checked and none of them leaves the scan.', 'ffcertificate' ); ?>
	</p>

<?php require FFC_PLUGIN_DIR . 'templates/admin/identity/coverage.php'; ?>

<?php require FFC_PLUGIN_DIR . 'templates/admin/identity/summary.php'; ?>

<?php require FFC_PLUGIN_DIR . 'templates/admin/identity/panel-shared.php'; ?>

<?php require FFC_PLUGIN_DIR . 'templates/admin/identity/panel-accounts.php'; ?>

<?php require FFC_PLUGIN_DIR . 'templates/admin/identity/panel-isolated.php'; ?>

<?php require FFC_PLUGIN_DIR . 'templates/admin/identity/panel-accepted.php'; ?>

<?php require FFC_PLUGIN_DIR . 'templates/admin/identity/panel-orphans.php'; ?>

<?php require FFC_PLUGIN_DIR . 'templates/admin/identity/dialog.php'; ?>
</div>
