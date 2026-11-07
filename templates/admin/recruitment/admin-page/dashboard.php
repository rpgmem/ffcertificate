<?php
/**
 * Template: Recruitment dashboard (#2486).
 *
 * @var array<string, mixed> $stats    {@see RecruitmentDashboard::stats()}.
 * @var bool                 $can_edit Holds the recruitment manage capability.
 * @var bool                 $reasons  May view the reasons.
 * @var bool                 $settings May view the settings.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.44.0
 */

use FreeFormCertificate\Admin\AdminUI;
use FreeFormCertificate\Recruitment\RecruitmentAdminPage;
use FreeFormCertificate\Recruitment\RecruitmentAdminPageRenderer;
use FreeFormCertificate\Recruitment\RecruitmentDashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Template variables scoped to this file (the include runs in the including method's function scope, not global).

$notices = (array) $stats['notices'];
$open    = (int) ( $notices['preliminary'] ?? 0 ) + (int) ( $notices['definitive'] ?? 0 );

echo '<div class="ffc-stats">';
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_stat_card() escapes every part.
echo AdminUI::get_stat_card(
	array(
		'label' => __( 'Open notices', 'ffcertificate' ),
		'value' => $open,
		'icon'  => 'megaphone',
	)
);
echo AdminUI::get_stat_card(
	array(
		'label' => __( 'Candidates', 'ffcertificate' ),
		'value' => (int) $stats['candidates'],
		'icon'  => 'id',
	)
);
echo AdminUI::get_stat_card(
	array(
		'label' => __( 'Calls awaiting an answer', 'ffcertificate' ),
		'value' => (int) $stats['awaiting'],
		'icon'  => 'email',
	)
);
echo AdminUI::get_stat_card(
	array(
		/* translators: %d: number of days */
		'label' => sprintf( _n( 'Presentations in the next %d day', 'Presentations in the next %d days', RecruitmentDashboard::UPCOMING_DAYS, 'ffcertificate' ), RecruitmentDashboard::UPCOMING_DAYS ),
		'value' => (int) $stats['upcoming'],
		'icon'  => 'calendar',
	)
);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
echo '</div>';

echo '<div class="ffc-dashboard"><div class="ffc-dashboard__sections">';

$notice_buttons = array(
	array(
		'label' => __( 'Open notices', 'ffcertificate' ),
		'icon'  => 'megaphone',
		'href'  => RecruitmentAdminPage::section_url( 'notices' ),
	),
);
if ( $can_edit ) {
	$notice_buttons[] = array(
		'label'    => __( 'New notice', 'ffcertificate' ),
		'category' => 'create',
		'icon'     => 'plus',
		'attrs'    => array(
			'data-ffc-dialog-open' => RecruitmentAdminPageRenderer::NOTICE_DIALOG,
			'aria-haspopup'        => 'dialog',
		),
	);
}
$by_status = array();
foreach ( $notices as $status => $count ) {
	$by_status[ RecruitmentAdminPage::notice_status_label( (string) $status ) ] = (int) $count;
}
RecruitmentDashboard::section_card( 'ffc-icon-megaphone', __( 'Notices', 'ffcertificate' ), RecruitmentDashboard::figures( $by_status ), $notice_buttons );

RecruitmentDashboard::section_card(
	'ffc-icon-id',
	__( 'Candidates', 'ffcertificate' ),
	RecruitmentDashboard::figures(
		array(
			__( 'Candidates', 'ffcertificate' )           => (int) $stats['candidates'],
			__( 'Linked to an account', 'ffcertificate' ) => (int) $stats['linked'],
		)
	),
	array(
		array(
			'label' => __( 'Open candidates', 'ffcertificate' ),
			'icon'  => 'id',
			'href'  => RecruitmentAdminPage::section_url( 'candidates' ),
		),
	)
);

$adj_buttons = array(
	array(
		'label' => __( 'Open adjutancies', 'ffcertificate' ),
		'icon'  => 'building',
		'href'  => RecruitmentAdminPage::section_url( 'adjutancies' ),
	),
);
RecruitmentDashboard::section_card(
	'ffc-icon-building',
	__( 'Adjutancies', 'ffcertificate' ),
	RecruitmentDashboard::figures(
		array(
			__( 'Adjutancies', 'ffcertificate' )       => (int) $stats['adjutancies'],
			__( 'Not in any notice', 'ffcertificate' ) => (int) $stats['unattached'],
		)
	),
	$adj_buttons
);

if ( $reasons ) {
	RecruitmentDashboard::section_card(
		'ffc-icon-clipboard',
		__( 'Reasons', 'ffcertificate' ),
		RecruitmentDashboard::figures( array( __( 'Reasons', 'ffcertificate' ) => (int) $stats['reasons'] ) ),
		array(
			array(
				'label' => __( 'Open reasons', 'ffcertificate' ),
				'icon'  => 'clipboard',
				'href'  => RecruitmentAdminPage::section_url( 'reasons' ),
			),
		)
	);
}

if ( $settings ) {
	RecruitmentDashboard::section_card(
		'ffc-icon-settings',
		__( 'Settings', 'ffcertificate' ),
		'<p>' . esc_html__( 'The call e-mail, the public shortcode and the badge colors.', 'ffcertificate' ) . '</p>',
		array(
			array(
				'label' => __( 'Open settings', 'ffcertificate' ),
				'icon'  => 'settings',
				'href'  => RecruitmentAdminPage::section_url( 'settings' ),
			),
		)
	);
}

echo '</div>'; // .ffc-dashboard__sections

echo '<aside class="ffc-dashboard__side">';
echo '<div class="card"><h2 class="ffc-icon-calendar">' . esc_html__( 'Next presentations', 'ffcertificate' ) . '</h2>';
$rows = (array) $stats['upcoming_rows'];
if ( empty( $rows ) ) {
	/* translators: %d: number of days */
	echo '<p class="description">' . esc_html( sprintf( _n( 'No presentation in the next %d day.', 'No presentation in the next %d days.', RecruitmentDashboard::UPCOMING_DAYS, 'ffcertificate' ), RecruitmentDashboard::UPCOMING_DAYS ) ) . '</p>';
} else {
	$list = array();
	foreach ( $rows as $row ) {
		$label = sprintf(
			/* translators: 1: notice code, 2: number of people */
			_n( '%1$s · %2$d person', '%1$s · %2$d people', (int) $row['people'], 'ffcertificate' ),
			(string) $row['code'],
			(int) $row['people']
		);
		$list[ $label ] = \FreeFormCertificate\Core\DateFormatter::format_date( (string) $row['date'] );
	}
	echo wp_kses_post( RecruitmentDashboard::figures( $list ) );
}
echo '</div>';

echo '<div class="card"><h2 class="ffc-icon-warning">' . esc_html__( 'Needs attention', 'ffcertificate' ) . '</h2>';
if ( 0 === (int) $stats['overdue'] ) {
	echo '<p class="description">' . esc_html__( 'Nothing is waiting.', 'ffcertificate' ) . '</p>';
} else {
	$overdue = array( __( 'Calls past their presentation date', 'ffcertificate' ) => (int) $stats['overdue'] );
	echo wp_kses_post( RecruitmentDashboard::figures( $overdue ) );
}
echo '</div>';
echo '</aside></div>'; // .ffc-dashboard
