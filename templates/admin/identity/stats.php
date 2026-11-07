<?php
/**
 * Template: Identity Resolution — the counts, as stat cards (#2498).
 *
 * One card for the whole queue, one per category the queue holds, and one for
 * the records that name no account. They sit above the two columns, as on the
 * module dashboards.
 *
 * Included from `includes/admin/views/identity-resolution-page.php`, inside
 * `IdentityResolutionPage::render_page()`, so the page's `$ffc_identity_*`
 * locals are in scope.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.45.0
 */

use FreeFormCertificate\Admin\AdminUI;
use FreeFormCertificate\Admin\IdentityResolutionLabels;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// THE COUNTERS ARE THE PANELS, COUNTED — NEVER A SECOND OPINION.
//
// Both these cards and the bodies below read `$ffc_identity_screen->panels`,
// which `IdentityQueuePanels::build()` already emptied of the tiers holding
// nothing. So a category absent here is absent below by construction, and the
// two cannot drift into disagreeing about how many findings there are.
//
// The shared tier is counted even where the merge capability is absent and
// its panel is therefore not drawn: the finding exists and is somebody's to
// resolve, and a counter that hid it would report a shorter queue to the
// operator least able to see why.
$ffc_identity_remaining = $ffc_identity_screen->remaining;
if ( $ffc_identity_remaining <= 0 ) {
	return;
}

$ffc_identity_cards = array(
	array(
		'label' => __( 'Findings left', 'ffcertificate' ),
		'value' => (int) $ffc_identity_remaining,
		'icon'  => 'list',
	),
);
foreach ( $ffc_identity_screen->panels as $ffc_identity_panel ) {
	$ffc_identity_tier    = (string) $ffc_identity_panel['tier'];
	$ffc_identity_look    = IdentityResolutionLabels::tier_look( $ffc_identity_tier );
	$ffc_identity_cards[] = array(
		'label' => IdentityResolutionLabels::tier_label( $ffc_identity_tier ),
		'value' => (int) $ffc_identity_panel['total'],
		'icon'  => $ffc_identity_look['icon'],
		'tone'  => $ffc_identity_look['tone'],
	);
}
if ( array() !== $ffc_identity_orphans ) {
	$ffc_identity_cards[] = array(
		'label' => __( 'No account', 'ffcertificate' ),
		'value' => count( $ffc_identity_orphans ),
		'icon'  => 'user-minus',
		'tone'  => 'neutral',
	);
}
?>
	<div class="ffc-stats ffc-identity-counts">
		<?php foreach ( $ffc_identity_cards as $ffc_identity_card ) : ?>
			<?php echo AdminUI::get_stat_card( $ffc_identity_card ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_stat_card() escapes every part. ?>
		<?php endforeach; ?>
	</div>
