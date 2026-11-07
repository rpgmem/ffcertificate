<?php
/**
 * Template: Date Messages dashboard (#2486).
 *
 * Included from page.php; see it for the variables in scope.
 *
 * @var array<string, mixed>  $dashboard  {@see DateMessagesAdminPage::dashboard()}.
 * @var bool                  $can_manage Whether the user may change anything.
 * @var string                $send_time  Daily send time, HH:MM.
 * @var int|false             $next_run   Next daily run.
 * @var array<int, string>    $rule_names Rule id => name.
 *
 * @package FreeFormCertificate\DateMessages
 * @since   6.44.0
 */

use FreeFormCertificate\Admin\AdminUI;
use FreeFormCertificate\Core\AdminDashboard;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\DateMessages\DateMessagesAdminPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Template variables scoped to this file (the include runs in the including method's function scope, not global).

$ffc_dm_days  = DateMessagesAdminPage::DASHBOARD_DAYS;
$ffc_dm_next  = false !== $next_run ? DateFormatter::format_datetime( (int) $next_run ) : __( 'Not scheduled', 'ffcertificate' );
$ffc_dm_tiles = array(
	array(
		'label' => __( 'Active rules', 'ffcertificate' ),
		'value' => (int) $dashboard['active'],
		'icon'  => 'list',
	),
	array(
		/* translators: %d: number of days */
		'label' => sprintf( _n( 'E-mails sent in the last %d day', 'E-mails sent in the last %d days', $ffc_dm_days, 'ffcertificate' ), $ffc_dm_days ),
		'value' => (int) $dashboard['sent'],
		'icon'  => 'email',
	),
	array(
		/* translators: %d: number of days */
		'label' => sprintf( _n( 'Failures in the last %d day', 'Failures in the last %d days', $ffc_dm_days, 'ffcertificate' ), $ffc_dm_days ),
		'value' => (int) $dashboard['failed'],
		'icon'  => 'warning',
		'tone'  => (int) $dashboard['failed'] > 0 ? 'danger' : 'primary',
	),
	array(
		'label' => __( 'Next daily run', 'ffcertificate' ),
		'value' => $ffc_dm_next,
		'icon'  => 'clock',
	),
);

echo '<div class="ffc-stats">';
foreach ( $ffc_dm_tiles as $ffc_dm_tile ) {
	echo AdminUI::get_stat_card( $ffc_dm_tile ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_stat_card() escapes every part.
}
echo '</div>';

echo '<div class="ffc-dashboard"><div class="ffc-dashboard__sections">';

$ffc_dm_rule_buttons = array(
	array(
		'label' => __( 'Open rules', 'ffcertificate' ),
		'icon'  => 'list',
		'href'  => DateMessagesAdminPage::section_url( 'rules' ),
	),
);
if ( $can_manage ) {
	$ffc_dm_rule_buttons[] = array(
		'label'    => __( 'Add rule', 'ffcertificate' ),
		'category' => 'create',
		'icon'     => 'plus',
		'href'     => add_query_arg(
			array(
				'page' => DateMessagesAdminPage::MENU_SLUG,
				'rule' => 0,
			),
			admin_url( 'admin.php' )
		),
	);
}
AdminDashboard::section_card(
	'ffc-icon-list',
	__( 'Rules', 'ffcertificate' ),
	AdminDashboard::figures(
		array(
			__( 'Dated', 'ffcertificate' )         => (int) $dashboard['dated'],
			__( 'Announcements', 'ffcertificate' ) => (int) $dashboard['announcements'],
			__( 'Inactive', 'ffcertificate' )      => (int) $dashboard['inactive'],
		)
	),
	$ffc_dm_rule_buttons
);

AdminDashboard::section_card(
	'ffc-icon-send',
	__( 'Send now', 'ffcertificate' ),
	'<p>' . esc_html__( 'Send a rule outside the daily run: a dated rule over the dates you choose, an announcement to everyone in its audience.', 'ffcertificate' ) . '</p>',
	array(
		array(
			'label' => __( 'Open', 'ffcertificate' ),
			'icon'  => 'send',
			'href'  => DateMessagesAdminPage::section_url( 'send' ),
		),
	)
);

AdminDashboard::section_card(
	'ffc-icon-history',
	__( 'History', 'ffcertificate' ),
	'<p>' . esc_html__( 'Every run, who started it, and what it sent, skipped and failed.', 'ffcertificate' ) . '</p>',
	array(
		array(
			'label' => __( 'Open history', 'ffcertificate' ),
			'icon'  => 'history',
			'href'  => DateMessagesAdminPage::section_url( 'history' ),
		),
	)
);

if ( DateMessagesAdminPage::can_view_pii() ) {
	AdminDashboard::section_card(
		'ffc-icon-calendar',
		__( 'Upcoming dates', 'ffcertificate' ),
		'<p>' . esc_html__( 'Who receives a message in the coming days, with names masked until revealed.', 'ffcertificate' ) . '</p>',
		array(
			array(
				'label' => __( 'Open', 'ffcertificate' ),
				'icon'  => 'calendar',
				'href'  => DateMessagesAdminPage::section_url( 'upcoming' ),
			),
		)
	);
}

AdminDashboard::section_card(
	'ffc-icon-clock',
	__( 'Schedule', 'ffcertificate' ),
	AdminDashboard::figures( array( __( 'Daily send time', 'ffcertificate' ) => $send_time ) ),
	array(
		array(
			'label' => __( 'Open', 'ffcertificate' ),
			'icon'  => 'clock',
			'href'  => DateMessagesAdminPage::section_url( 'settings' ),
		),
	)
);

echo '</div>'; // .ffc-dashboard__sections

echo '<aside class="ffc-dashboard__side">';
echo '<div class="card"><h2 class="ffc-icon-history">' . esc_html__( 'Latest runs', 'ffcertificate' ) . '</h2>';
$ffc_dm_recent = (array) $dashboard['recent'];
if ( array() === $ffc_dm_recent ) {
	echo '<p class="description">' . esc_html__( 'Nothing has run yet.', 'ffcertificate' ) . '</p>';
} else {
	$ffc_dm_list = array();
	foreach ( $ffc_dm_recent as $ffc_dm_run ) {
		$ffc_dm_rule  = (int) ( $ffc_dm_run['rule_id'] ?? 0 );
		$ffc_dm_label = sprintf(
			/* translators: 1: rule name, 2: when the run started */
			__( '%1$s · %2$s', 'ffcertificate' ),
			$rule_names[ $ffc_dm_rule ] ?? __( 'Deleted rule', 'ffcertificate' ),
			DateFormatter::format_datetime( (int) ( $ffc_dm_run['started_at'] ?? 0 ) )
		);
		$ffc_dm_failed                = (int) ( $ffc_dm_run['failed'] ?? 0 );
		$ffc_dm_list[ $ffc_dm_label ] = $ffc_dm_failed > 0
			/* translators: 1: e-mails sent, 2: e-mails that failed */
			? sprintf( __( '%1$d sent, %2$d failed', 'ffcertificate' ), (int) ( $ffc_dm_run['sent'] ?? 0 ), $ffc_dm_failed )
			/* translators: %d: e-mails sent */
			: sprintf( __( '%d sent', 'ffcertificate' ), (int) ( $ffc_dm_run['sent'] ?? 0 ) );
	}
	echo wp_kses_post( AdminDashboard::figures( $ffc_dm_list ) );
}
echo '</div>';

echo '<div class="card"><h2 class="ffc-icon-warning">' . esc_html__( 'Needs attention', 'ffcertificate' ) . '</h2>';
$ffc_dm_attention = array();
if ( (int) $dashboard['failed_runs'] > 0 ) {
	/* translators: %d: number of days */
	$ffc_dm_attention[ sprintf( _n( 'Runs with failures in the last %d day', 'Runs with failures in the last %d days', $ffc_dm_days, 'ffcertificate' ), $ffc_dm_days ) ] = (int) $dashboard['failed_runs'];
}
if ( false === $next_run ) {
	$ffc_dm_attention[ __( 'The daily run is not scheduled', 'ffcertificate' ) ] = '';
}
if ( 0 === (int) $dashboard['active'] ) {
	$ffc_dm_attention[ __( 'No rule is active', 'ffcertificate' ) ] = '';
}
if ( array() === $ffc_dm_attention ) {
	echo '<p class="description">' . esc_html__( 'Nothing is waiting.', 'ffcertificate' ) . '</p>';
} else {
	echo wp_kses_post( AdminDashboard::figures( $ffc_dm_attention ) );
}
echo '</div>';
echo '</aside></div>'; // .ffc-dashboard
