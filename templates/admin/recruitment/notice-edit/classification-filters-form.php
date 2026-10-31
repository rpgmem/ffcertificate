<?php
/**
 * Template: Recruitment notice edit — classifications filter form.
 *
 * Extracted verbatim from
 * {@see \FreeFormCertificate\Recruitment\RecruitmentNoticeEditPageRenderer::render_classification_filters_form()}
 * (rpgmem/ffcertificate#589 phase-2). Markup is byte-identical to the
 * pre-extraction inline body; the renderer prepares the locals below and
 * includes this file.
 *
 * Variables in scope (provided by the including method):
 *
 * @var int               $notice_id    Notice id.
 * @var array<int, int>   $adjutancies  Adjutancy ids attached to the notice.
 * @var int               $adj_id       Currently-selected adjutancy filter id.
 * @var string            $query        Name substring filter.
 * @var string            $cpf          CPF filter.
 * @var string            $rf           RF filter.
 * @var string            $subscription Subscription-type filter.
 * @var string            $reset_url    Reset link URL.
 * @var string            $status_key   Selected definitive status, or ''.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.7.7
 */

use FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader;
use FreeFormCertificate\Recruitment\RecruitmentAdminPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo '<form method="get" class="ffc-cls-filters ffc-rec-cls-filters">';
echo '<input type="hidden" name="page" value="' . esc_attr( RecruitmentAdminPage::PAGE_SLUG ) . '">';
echo '<input type="hidden" name="action" value="edit-notice">';
echo '<input type="hidden" name="notice_id" value="' . esc_attr( (string) $notice_id ) . '">';
// The status filter is a link above the definitive list; the form keeps it (#2245).
if ( '' !== $status_key ) {
	echo '<input type="hidden" name="ffc_cls_status" value="' . esc_attr( $status_key ) . '">';
	echo '<input type="hidden" name="ffc_cls_tab" value="definitive">';
}

echo '<input type="text" name="ffc_cls_q" value="' . esc_attr( $query ) . '" placeholder="' . esc_attr__( 'Name (substring)', 'ffcertificate' ) . '" size="20">';
echo ' <input type="text" name="ffc_cls_cpf" value="' . esc_attr( $cpf ) . '" placeholder="' . esc_attr__( 'CPF (digits only)', 'ffcertificate' ) . '" size="15">';
echo ' <input type="text" name="ffc_cls_rf" value="' . esc_attr( $rf ) . '" placeholder="' . esc_attr__( 'RF (digits only)', 'ffcertificate' ) . '" size="10">';

if ( ! empty( $adjutancies ) ) {
	echo ' <select name="ffc_cls_adj">';
	echo '<option value="0">' . esc_html__( 'All adjutancies', 'ffcertificate' ) . '</option>';
	foreach ( $adjutancies as $aid ) {
		$row = RecruitmentAdjutancyReader::get_by_id( (int) $aid );
		if ( null === $row ) {
			continue;
		}
		$is_selected = (int) $row->id === $adj_id ? ' selected' : '';
		echo '<option value="' . esc_attr( (string) (int) $row->id ) . '"' . esc_attr( $is_selected ) . '>' . esc_html( (string) $row->name ) . '</option>';
	}
	echo '</select>';
}

echo ' <select name="ffc_cls_sub">';
echo '<option value=""' . selected( '', $subscription, false ) . '>' . esc_html__( 'All subscription types', 'ffcertificate' ) . '</option>';
echo '<option value="pcd"' . selected( 'pcd', $subscription, false ) . '>' . esc_html__( 'PCD only', 'ffcertificate' ) . '</option>';
echo '<option value="geral"' . selected( 'geral', $subscription, false ) . '>' . esc_html__( 'GERAL only', 'ffcertificate' ) . '</option>';
echo '</select>';

echo ' ';
\FreeFormCertificate\Core\Button::render(
	array(
		'label' => __( 'Filter', 'ffcertificate' ),
		'icon'  => 'filter',
		'type'  => 'submit',
	)
);
echo ' ';
\FreeFormCertificate\Core\Button::render(
	array(
		'label' => __( 'Reset', 'ffcertificate' ),
		'icon'  => 'eraser',
		'href'  => $reset_url,
	)
);
echo '</form>';
