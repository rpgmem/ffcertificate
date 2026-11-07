<?php
/**
 * Template: Date Messages admin screen (the page title and the outcome notice).
 *
 * Variables in scope (provided by DateMessagesAdminPage::render_page()):
 *
 * @var string                                                $tab         Current page: dashboard, rules, edit, send, history, upcoming, settings.
 * @var array<string, mixed>                                  $dashboard   The dashboard figures, on that page only.
 * @var array<string, mixed>                                  $outcome     Outcome of the last write ('type', 'message', 'draft').
 * @var bool                                                  $can_manage  Whether the user may change anything.
 * @var bool                                                  $can_test    Whether the user may send a test to themselves.
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
 * @var int                                                   $upcoming_rule      Upcoming-dates rule filter, 0 for every active rule.
 * @var array<int, string>                                    $upcoming_audiences Audiences the upcoming-dates filter offers.
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

$ffc_dm_tabs   = DateMessagesAdminPage::section_labels();
$ffc_dm_titles = array( 'dashboard' => __( 'Date Messages', 'ffcertificate' ) ) + $ffc_dm_tabs;
// Each page names its icon from Core\Icons; the form pages open in one card
// headed by it, the lists run full width, and the rule editor draws a card
// per part of the rule itself (#1631, #2486).
$ffc_dm_icons = array(
	'send'     => 'ffc-icon-send',
	'settings' => 'ffc-icon-clock',
);
$ffc_dm_title = 'edit' === $tab ? $ffc_dm_titles['rules'] : $ffc_dm_titles[ $tab ];
?>
<div class="wrap ffc-admin-page ffc-page-date-messages ffc-settings-wrap ffc-boxed" id="ffc-date-messages-<?php echo esc_attr( $tab ); ?>">
	<h1 class="wp-heading-inline"><?php echo esc_html( $ffc_dm_title ); ?></h1>
	<?php if ( $can_manage && ( 'rules' === $tab || 'dashboard' === $tab ) ) : ?>
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_button(
			array(
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
			)
		);
		?>
	<?php endif; ?>
	<?php if ( 'edit' === $tab ) : ?>
		<?php
		// The editor's way back to the list, a button beside the title as on
		// every editor (#2486).
		\FreeFormCertificate\Admin\AdminUI::render_button(
			array(
				'label' => __( 'Back to rules', 'ffcertificate' ),
				'icon'  => 'chevron-left',
				'href'  => DateMessagesAdminPage::section_url( 'rules' ),
			)
		);
		?>
	<?php endif; ?>
	<hr class="wp-header-end">

	<?php \FreeFormCertificate\Core\EmailDisabledNotice::render(); ?>
	<?php DateMessagesAdminPage::render_backfill_notice(); ?>

	<?php if ( isset( $outcome['message'] ) && is_string( $outcome['message'] ) && '' !== $outcome['message'] ) : ?>
		<?php
		\FreeFormCertificate\Core\AdminNotice::render(
			'success' === ( $outcome['type'] ?? '' ) ? 'success' : 'error',
			esc_html( $outcome['message'] )
		);
		?>
	<?php endif; ?>

	<?php if ( isset( $ffc_dm_icons[ $tab ] ) ) : ?>
	<div class="card">
		<h2 class="<?php echo esc_attr( $ffc_dm_icons[ $tab ] ); ?>"><?php echo esc_html( $ffc_dm_tabs[ $tab ] ); ?></h2>
	<?php endif; ?>

	<?php
	$ffc_dm_partial = array(
		'dashboard' => 'dashboard.php',
		'rules'     => 'rules.php',
		'edit'      => 'rule-form.php',
		'send'      => 'send.php',
		'history'   => 'history.php',
		'upcoming'  => 'upcoming.php',
		'settings'  => 'settings.php',
	);
	require __DIR__ . '/' . $ffc_dm_partial[ $tab ];
	?>

	<?php if ( isset( $ffc_dm_icons[ $tab ] ) ) : ?>
	</div>
	<?php endif; ?>
</div>
