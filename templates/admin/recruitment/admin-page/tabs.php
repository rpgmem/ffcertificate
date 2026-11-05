<?php
/**
 * Template: Recruitment admin page — Vertical tab navigation.
 *
 * Extracted verbatim from the matching RecruitmentAdminPageRenderer method
 * (rpgmem/ffcertificate#563 coverage extraction); markup byte-identical.
 *
 * @var array<string,array<string,string>> $tabs Visible tabs. @var string $active Current tab slug.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.12.0
 */

use FreeFormCertificate\Recruitment\RecruitmentAdminPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Template variables scoped to this file (the include runs in the including renderer method's function scope, not global).

printf( '<nav class="ffc-settings-tabs__nav" aria-label="%s"><ul class="ffc-settings-tabs__list">', esc_attr( __( 'Sections', 'ffcertificate' ) ) );
foreach ( $tabs as $slug => $tab ) {
	$is_active = ( $slug === $active );
	$url       = add_query_arg(
		array(
			'page' => RecruitmentAdminPage::PAGE_SLUG,
			'tab'  => $slug,
		),
		admin_url( 'admin.php' )
	);
	printf(
		'<li class="ffc-settings-tabs__nav-item"><a href="%1$s" id="ffc-recruitment-tabnav-%2$s" class="ffc-settings-tabs__tab%3$s"%4$s><span class="ffc-settings-tabs__icon %5$s" aria-hidden="true"></span><span class="ffc-settings-tabs__label">%6$s</span></a></li>',
		esc_url( $url ),
		esc_attr( $slug ),
		$is_active ? ' is-active' : '',
		$is_active ? ' aria-current="page"' : '',
		esc_attr( \FreeFormCertificate\Core\Icons::tab_class( (string) $tab['icon'] ) ),
		esc_html( $tab['label'] )
	);
}
echo '</ul></nav>';
