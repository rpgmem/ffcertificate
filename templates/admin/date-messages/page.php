<?php
/**
 * Template: Date Messages admin screen (tabs and outcome notice).
 *
 * Variables in scope (provided by DateMessagesAdminPage::render_page()):
 *
 * @var string                                                $tab         Current tab: rules, edit, send, history, settings.
 * @var array<string, mixed>                                  $outcome     Outcome of the last write ('type', 'message', 'draft').
 * @var bool                                                  $can_manage  Whether the user may change anything.
 * @var array<int, \FreeFormCertificate\DateMessages\Rule>    $rules       Every rule.
 * @var array<int, string>                                    $audiences   Audience id => indented name.
 * @var array<int, string>                                    $managers    User id => "Name <email>", on the editor only.
 * @var \FreeFormCertificate\DateMessages\Rule|null           $editing     Rule being edited, null for a new one.
 * @var array<string, mixed>                                  $draft       Values to put back after a failed save.
 * @var array<int, array<string, mixed>>                      $history     A page of runs.
 * @var int                                                   $total       Number of runs.
 * @var int                                                   $paged       History page.
 * @var array<int, string>                                    $rule_names  Rule id => name.
 * @var string                                                $send_time   Daily send time, HH:MM.
 * @var int|false                                             $next_run    Next daily run.
 * @var bool                                                  $queue_ready Whether a mail queue is active.
 * @var array<string, mixed>|null                             $upcoming    The upcoming-dates panel's data, on that tab only.
 * @var string                                                $period      Upcoming-dates period key.
 * @var int                                                   $audience_id Upcoming-dates audience filter.
 * @var \DateTimeImmutable                                    $today       Today, site timezone.
 *
 * @package FreeFormCertificate\DateMessages
 * @since   6.33.0
 */

use FreeFormCertificate\DateMessages\DateMessagesAdminPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_dm_tabs = array(
	'rules'    => __( 'Rules', 'ffcertificate' ),
	'send'     => __( 'Send now', 'ffcertificate' ),
	'history'  => __( 'History', 'ffcertificate' ),
	'upcoming' => __( 'Upcoming dates', 'ffcertificate' ),
	'settings' => __( 'Schedule', 'ffcertificate' ),
);
if ( ! DateMessagesAdminPage::can_view_pii() ) {
	unset( $ffc_dm_tabs['upcoming'] );
}
$ffc_dm_active = 'edit' === $tab ? 'rules' : $tab;
?>
<div class="wrap ffc-admin-page ffc-page-date-messages">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Date Messages', 'ffcertificate' ); ?></h1>
	<?php if ( $can_manage && 'rules' === $tab ) : ?>
		<a href="
		<?php
		echo esc_url(
			add_query_arg(
				array(
					'page' => DateMessagesAdminPage::MENU_SLUG,
					'rule' => 0,
				),
				admin_url( 'admin.php' )
			)
		);
		?>
					" class="page-title-action"><?php esc_html_e( 'Add rule', 'ffcertificate' ); ?></a>
	<?php endif; ?>
	<hr class="wp-header-end">

	<?php \FreeFormCertificate\Core\EmailDisabledNotice::render(); ?>

	<?php if ( isset( $outcome['message'] ) && is_string( $outcome['message'] ) && '' !== $outcome['message'] ) : ?>
		<?php
		wp_admin_notice(
			esc_html( $outcome['message'] ),
			array(
				'type'        => 'success' === ( $outcome['type'] ?? '' ) ? 'success' : 'error',
				'dismissible' => true,
			)
		);
		?>
	<?php endif; ?>

	<nav class="nav-tab-wrapper">
		<?php foreach ( $ffc_dm_tabs as $ffc_dm_key => $ffc_dm_label ) : ?>
			<a href="
			<?php
			echo esc_url(
				add_query_arg(
					array(
						'page' => DateMessagesAdminPage::MENU_SLUG,
						'tab'  => $ffc_dm_key,
					),
					admin_url( 'admin.php' )
				)
			);
			?>
						"
				class="nav-tab<?php echo $ffc_dm_key === $ffc_dm_active ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $ffc_dm_label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php
	$ffc_dm_partial = array(
		'rules'    => 'rules.php',
		'edit'     => 'rule-form.php',
		'send'     => 'send.php',
		'history'  => 'history.php',
		'upcoming' => 'upcoming.php',
		'settings' => 'settings.php',
	);
	require __DIR__ . '/' . $ffc_dm_partial[ $tab ];
	?>
</div>
