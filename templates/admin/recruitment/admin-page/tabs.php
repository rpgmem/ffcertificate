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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Template variables scoped to this file (the include runs in the including renderer method's function scope, not global).

echo '<ul class="ffc-settings-tabs__nav" role="tablist" aria-orientation="vertical">';
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
		'<li class="ffc-settings-tabs__nav-item" role="presentation"><a href="%1$s" id="ffc-recruitment-tabnav-%2$s" class="ffc-settings-tabs__tab%3$s" role="tab" aria-selected="%4$s" aria-controls="ffc-recruitment-tabpanel-%2$s" tabindex="%5$s"><span class="ffc-settings-tabs__icon dashicons dashicons-%6$s" aria-hidden="true"></span><span class="ffc-settings-tabs__label">%7$s</span></a></li>',
		esc_url( $url ),
		esc_attr( $slug ),
		$is_active ? ' is-active' : '',
		$is_active ? 'true' : 'false',
		$is_active ? '0' : '-1',
		esc_attr( $tab['icon'] ),
		esc_html( $tab['label'] )
	);
}
echo '</ul>';
