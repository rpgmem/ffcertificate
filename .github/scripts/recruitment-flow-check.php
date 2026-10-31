<?php
/**
 * The recruitment import, promotion and deletion flow, against a real
 * database (#2245).
 *
 * **WHY A REAL DATABASE.** Every recruitment unit test mocks `$wpdb`, so it
 * proves which statements a class sends and nothing about what they do
 * together. The defects #2245 fixed were all of that second kind: a
 * definitive import that wiped called rows before the state check refused
 * it, a notice deletion that orphaned its rows, accounts created inside a
 * transaction that rolled back. Only a server can say what a sequence of
 * statements leaves behind.
 *
 * **THE FLOW.** One throwaway adjutancy and notice:
 *
 *   1. a preview import on a draft creates the candidates and NO account;
 *   2. moving to `preliminary` queues the account batch, which links both
 *      candidates; a second run links nothing;
 *   3. a definitive import promotes the notice in the same change;
 *   4. one candidate is called;
 *   5. a second definitive import is refused and the called row survives;
 *   6. deleting the published notice is refused and its rows survive;
 *   7. the call is cancelled.
 *
 * A second draft notice is then deleted, and its rows must go with it.
 *
 * **WHY THIS JOB.** `fresh-install` has the only real MariaDB and the freshly
 * created schema. Everything planted is deleted before the script exits.
 *
 * @package FreeFormCertificate\CI
 */

declare(strict_types=1);

use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\Recruitment\RecruitmentAccountLinker;
use FreeFormCertificate\Recruitment\RecruitmentAdjutancyWriter;
use FreeFormCertificate\Recruitment\RecruitmentClassificationRepository;
use FreeFormCertificate\Recruitment\RecruitmentCallReader;
use FreeFormCertificate\Recruitment\RecruitmentCallService;
use FreeFormCertificate\Recruitment\RecruitmentCandidateReader;
use FreeFormCertificate\Recruitment\RecruitmentCsvImporter;
use FreeFormCertificate\Recruitment\RecruitmentDeleteService;
use FreeFormCertificate\Recruitment\RecruitmentNoticeAdjutancyRepository;
use FreeFormCertificate\Recruitment\RecruitmentNoticeReader;
use FreeFormCertificate\Recruitment\RecruitmentNoticeStateMachine;
use FreeFormCertificate\Recruitment\RecruitmentNoticeWriter;
use FreeFormCertificate\Recruitment\RecruitmentPromotionService;

$wp_root = (string) ( $argv[1] ?? '' );

if ( '' === $wp_root || ! is_readable( $wp_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php recruitment-flow-check.php <wp-root>\n" );
	exit( 2 );
}

require_once $wp_root . '/wp-load.php';

global $wpdb;

/**
 * Everything planted, for the cleanup.
 *
 * @var array{notices: list<int>, adjutancy: int, users: list<int>, emails: list<string>}
 */
$ffc_rf_planted = array(
	'notices'   => array(),
	'adjutancy' => 0,
	'users'     => array(),
	'emails'    => array( 'ci-flow-a@example.test', 'ci-flow-b@example.test' ),
);

/**
 * Delete what this script planted.
 *
 * @return void
 */
function ffc_rf_cleanup(): void {
	global $wpdb, $ffc_rf_planted;
	$classification = $wpdb->prefix . 'ffc_recruitment_classification';
	foreach ( $ffc_rf_planted['notices'] as $notice_id ) {
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE notice_id = %d', $classification, $notice_id ) );
		foreach ( $ids as $id ) {
			$wpdb->delete( $wpdb->prefix . 'ffc_recruitment_call', array( 'classification_id' => (int) $id ), array( '%d' ) );
		}
		$candidates = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT candidate_id FROM %i WHERE notice_id = %d', $classification, $notice_id ) );
		$wpdb->delete( $classification, array( 'notice_id' => $notice_id ), array( '%d' ) );
		foreach ( $candidates as $candidate_id ) {
			$wpdb->delete( $wpdb->prefix . 'ffc_recruitment_candidate', array( 'id' => (int) $candidate_id ), array( '%d' ) );
		}
		$wpdb->delete( $wpdb->prefix . 'ffc_recruitment_notice_adjutancy', array( 'notice_id' => $notice_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'ffc_recruitment_notice', array( 'id' => $notice_id ), array( '%d' ) );
	}
	if ( $ffc_rf_planted['adjutancy'] > 0 ) {
		$wpdb->delete( $wpdb->prefix . 'ffc_recruitment_adjutancy', array( 'id' => $ffc_rf_planted['adjutancy'] ), array( '%d' ) );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $ffc_rf_planted['emails'] as $email ) {
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			wp_delete_user( (int) $user->ID );
		}
	}
	wp_clear_scheduled_hook( RecruitmentAccountLinker::HOOK );
}

/**
 * Clean up, report a failure and stop.
 *
 * @param string $message Why.
 * @return never
 */
function ffc_rf_fail( string $message ) {
	ffc_rf_cleanup();
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

/**
 * Rows of one list of a notice: candidate id => status.
 *
 * @param int    $notice_id Notice ID.
 * @param string $list_type `preview` or `definitive`.
 * @return array<int, string>
 */
function ffc_rf_rows( int $notice_id, string $list_type ): array {
	global $wpdb;
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT id, candidate_id, status FROM %i WHERE notice_id = %d AND list_type = %s ORDER BY id',
			$wpdb->prefix . 'ffc_recruitment_classification',
			$notice_id,
			$list_type
		)
	);
	$out = array();
	foreach ( (array) $rows as $row ) {
		$out[ (int) $row->id ] = (string) $row->status;
	}
	return $out;
}

/**
 * Linked account ids of a notice's candidates, by candidate id.
 *
 * @param int $notice_id Notice ID.
 * @return array<int, int|null>
 */
function ffc_rf_links( int $notice_id ): array {
	global $wpdb;
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT DISTINCT c.id, c.user_id FROM %i c INNER JOIN %i cl ON cl.candidate_id = c.id WHERE cl.notice_id = %d ORDER BY c.id',
			$wpdb->prefix . 'ffc_recruitment_candidate',
			$wpdb->prefix . 'ffc_recruitment_classification',
			$notice_id
		)
	);
	$out = array();
	foreach ( (array) $rows as $row ) {
		$out[ (int) $row->id ] = null === $row->user_id ? null : (int) $row->user_id;
	}
	return $out;
}

wp_set_current_user( 1 );

$suffix = (string) wp_rand( 100000, 999999 );
$csv    = "name,cpf,rf,email,adjutancy,rank,score,pcd\n"
	. "Ana Flow,51817842080,,ci-flow-a@example.test,ci-flow-{$suffix},1,90.5,nao\n"
	. "Bruno Flow,20456942084,5181780,ci-flow-b@example.test,ci-flow-{$suffix},2,80,nao\n";

$adjutancy = RecruitmentAdjutancyWriter::create( 'ci-flow-' . $suffix, 'CI flow' );
if ( false === $adjutancy ) {
	ffc_rf_fail( 'Could not create the adjutancy.' );
}
$ffc_rf_planted['adjutancy'] = (int) $adjutancy;

$notice = RecruitmentNoticeWriter::create( 'CI-FLOW-' . $suffix, 'CI flow' );
if ( false === $notice ) {
	ffc_rf_fail( 'Could not create the notice.' );
}
$notice                       = (int) $notice;
$ffc_rf_planted['notices'][] = $notice;
RecruitmentNoticeAdjutancyRepository::attach( $notice, (int) $adjutancy );

// 1. Preview import on a draft: candidates, and no account.
$result = RecruitmentCsvImporter::import_preview( $notice, $csv );
if ( ! $result['success'] || 2 !== $result['inserted'] ) {
	ffc_rf_fail( 'Preview import on a draft failed: ' . implode( ' | ', $result['errors'] ) );
}
$links = ffc_rf_links( $notice );
if ( 2 !== count( $links ) || array( null, null ) !== array_values( $links ) ) {
	ffc_rf_fail( 'A draft import linked or created an account: ' . wp_json_encode( $links ) );
}
if ( false !== wp_next_scheduled( RecruitmentAccountLinker::HOOK, array( $notice, 0 ) ) ) {
	ffc_rf_fail( 'A draft import queued the account batch.' );
}

// 2. Preliminary queues the batch; the batch links both, a rerun links none.
$transition = RecruitmentNoticeStateMachine::transition_to( $notice, 'preliminary' );
if ( ! $transition['success'] ) {
	ffc_rf_fail( 'draft -> preliminary failed: ' . implode( ' | ', $transition['errors'] ) );
}
if ( false === wp_next_scheduled( RecruitmentAccountLinker::HOOK, array( $notice, 0 ) ) ) {
	ffc_rf_fail( 'Reaching preliminary did not queue the account batch.' );
}
$linked = RecruitmentAccountLinker::run_batch( $notice, 0 );
$links  = ffc_rf_links( $notice );
if ( 2 !== $linked || in_array( null, $links, true ) ) {
	ffc_rf_fail( "The account batch linked {$linked}, links: " . wp_json_encode( $links ) );
}
$ffc_rf_planted['users'] = array_values( array_filter( $links ) );
if ( 0 !== RecruitmentAccountLinker::run_batch( $notice, 0 ) ) {
	ffc_rf_fail( 'A second account batch linked again.' );
}

// 3. Definitive import promotes the notice in the same change.
$result = RecruitmentCsvImporter::import_definitive( $notice, $csv );
if ( ! $result['success'] ) {
	ffc_rf_fail( 'Definitive import failed: ' . implode( ' | ', $result['errors'] ) );
}
$current = RecruitmentNoticeReader::get_by_id( $notice );
if ( null === $current || 'definitive' !== $current->status ) {
	ffc_rf_fail( 'The definitive import did not promote the notice.' );
}

// 4. Call the first definitive row.
$definitive = ffc_rf_rows( $notice, 'definitive' );
$first      = (int) array_key_first( $definitive );
$call       = RecruitmentCallService::call_single( $first, gmdate( 'Y-m-d', time() + WEEK_IN_SECONDS ), '09:00', 1, null, null, false );
if ( ! $call['success'] ) {
	ffc_rf_fail( 'The call failed: ' . implode( ' | ', $call['errors'] ) );
}
$before = ffc_rf_rows( $notice, 'definitive' );

// 5. A second definitive import is refused before any write.
$result = RecruitmentCsvImporter::import_definitive( $notice, $csv );
if ( $result['success'] || array( 'recruitment_definitive_import_requires_preliminary' ) !== $result['errors'] ) {
	ffc_rf_fail( 'A definitive import on a definitive notice was not refused: ' . wp_json_encode( $result ) );
}
if ( ffc_rf_rows( $notice, 'definitive' ) !== $before ) {
	ffc_rf_fail( 'The refused definitive import changed the list.' );
}

// 6. A published notice cannot be deleted.
$deleted = RecruitmentDeleteService::delete_notice( $notice );
if ( $deleted['success'] || ffc_rf_rows( $notice, 'definitive' ) !== $before ) {
	ffc_rf_fail( 'A published notice was deleted, or lost rows.' );
}

// 7. The call is cancelled, through the row it belongs to, and ends with it
// (#2245): no active call is left behind, and the row can be called again.
$cancel = RecruitmentCallService::cancel_call( (int) $call['call_ids'][0], 'CI flow check', 1, $first, false );
if ( ! $cancel['success'] ) {
	ffc_rf_fail( 'Cancelling the call failed: ' . implode( ' | ', $cancel['errors'] ) );
}
if ( null !== RecruitmentCallReader::get_active_for_classification( $first ) ) {
	ffc_rf_fail( 'The cancelled call is still active.' );
}
$recall = RecruitmentCallService::call_single( $first, gmdate( 'Y-m-d', time() + WEEK_IN_SECONDS ), '10:00', 1, null, null, false );
if ( ! $recall['success'] ) {
	ffc_rf_fail( 'The row could not be called again after the cancel: ' . implode( ' | ', $recall['errors'] ) );
}
$wrong = RecruitmentCallService::cancel_call( (int) $recall['call_ids'][0], 'CI flow check', 1, $first + 100000, false );
if ( $wrong['success'] || array( 'recruitment_call_not_in_classification' ) !== $wrong['errors'] ) {
	ffc_rf_fail( 'A call was cancelled under another row: ' . wp_json_encode( $wrong ) );
}
RecruitmentCallService::cancel_call( (int) $recall['call_ids'][0], 'CI flow check', 1, $first, false );

// A draft is deleted with its rows.
$draft = RecruitmentNoticeWriter::create( 'CI-FLOW-D-' . $suffix, 'CI flow draft' );
if ( false === $draft ) {
	ffc_rf_fail( 'Could not create the draft notice.' );
}
$draft                       = (int) $draft;
$ffc_rf_planted['notices'][] = $draft;
RecruitmentNoticeAdjutancyRepository::attach( $draft, (int) $adjutancy );
$result = RecruitmentCsvImporter::import_preview( $draft, $csv );
if ( ! $result['success'] ) {
	ffc_rf_fail( 'Preview import on the second draft failed: ' . implode( ' | ', $result['errors'] ) );
}
// Nobody is called on a draft notice, nor on a preview row (#2245).
$draft_rows = ffc_rf_rows( $draft, 'preview' );
$refused    = RecruitmentCallService::call_single( (int) array_key_first( $draft_rows ), gmdate( 'Y-m-d', time() + WEEK_IN_SECONDS ), '09:00', 1, null, null, false );
if ( $refused['success'] || array( 'recruitment_call_requires_definitive_list' ) !== $refused['errors'] ) {
	ffc_rf_fail( 'A preview row on a draft notice was called: ' . wp_json_encode( $refused ) );
}
// Identity (#2245): a row naming somebody else than the stored candidate
// waits for the operator, keeps the stored identity once acknowledged, and a
// CPF and an RF of two candidates are refused even when acknowledged.
$renamed = "name,cpf,rf,email,adjutancy,rank,score,pcd\n"
	. "Someone Else,51817842080,,ci-flow-a@example.test,ci-flow-{$suffix},1,90.5,nao\n";
$result  = RecruitmentCsvImporter::import_preview( $draft, $renamed );
if ( $result['success'] || empty( $result['mismatches'] ) || array( 'name' ) !== $result['mismatches'][0]['fields'] ) {
	ffc_rf_fail( 'A row naming somebody else was not held for the operator: ' . wp_json_encode( $result ) );
}
if ( 2 !== count( ffc_rf_rows( $draft, 'preview' ) ) ) {
	ffc_rf_fail( 'A held import changed the list.' );
}
// The file drops a row of a list that has two, so it also waits for the
// comparison to be confirmed (#2245).
$result = RecruitmentCsvImporter::import_preview( $draft, $renamed, true );
if ( $result['success'] || ! isset( $result['diff'] ) || 1 !== count( $result['diff']['removed'] ) ) {
	ffc_rf_fail( 'A re-import that drops a row did not wait for the comparison: ' . wp_json_encode( $result ) );
}
if ( 2 !== count( ffc_rf_rows( $draft, 'preview' ) ) ) {
	ffc_rf_fail( 'A re-import waiting for the comparison changed the list.' );
}
$result = RecruitmentCsvImporter::import_preview( $draft, $renamed, true, true );
if ( ! $result['success'] ) {
	ffc_rf_fail( 'The acknowledged import failed: ' . implode( ' | ', $result['errors'] ) );
}
$stored = RecruitmentCandidateReader::get_by_cpf_hash( (string) SensitiveFieldRegistry::hash_identifier( 'cpf', '51817842080' ) );
if ( null === $stored || 'Ana Flow' !== $stored->name ) {
	ffc_rf_fail( 'The acknowledged import overwrote the stored name.' );
}
$conflict = "name,cpf,rf,email,adjutancy,rank,score,pcd\n"
	. "Ana Flow,51817842080,5181780,ci-flow-a@example.test,ci-flow-{$suffix},1,90.5,nao\n";
$result   = RecruitmentCsvImporter::import_preview( $draft, $conflict, true );
if ( $result['success'] || false === strpos( implode( ' ', $result['errors'] ), 'recruitment_csv_identity_conflict' ) ) {
	ffc_rf_fail( 'A CPF and an RF of two candidates were not refused: ' . wp_json_encode( $result ) );
}

// A corrected preliminary list (#2245): a manual status survives the
// re-import, the promotion after going back to preliminary copies the new
// list, and a bare status flip is refused.
$cycle = RecruitmentNoticeWriter::create( 'CI-FLOW-C-' . $suffix, 'CI flow cycle' );
if ( false === $cycle ) {
	ffc_rf_fail( 'Could not create the cycle notice.' );
}
$cycle                       = (int) $cycle;
$ffc_rf_planted['notices'][] = $cycle;
RecruitmentNoticeAdjutancyRepository::attach( $cycle, (int) $adjutancy );
if ( ! RecruitmentCsvImporter::import_preview( $cycle, $csv )['success'] ) {
	ffc_rf_fail( 'Preview import on the cycle notice failed.' );
}
RecruitmentNoticeStateMachine::transition_to( $cycle, 'preliminary' );
$snapshot = RecruitmentPromotionService::snapshot_to_definitive( $cycle );
if ( ! $snapshot['success'] || 2 !== $snapshot['copied'] ) {
	ffc_rf_fail( 'The first copy to definitive failed: ' . wp_json_encode( $snapshot ) );
}
$back = RecruitmentNoticeStateMachine::transition_to( $cycle, 'preliminary' );
if ( ! $back['success'] ) {
	ffc_rf_fail( 'definitive -> preliminary with no calls failed: ' . implode( ' | ', $back['errors'] ) );
}
$preview_rows = RecruitmentClassificationRepository::get_for_notice( $cycle, 'preview' );
$bruno        = null;
foreach ( $preview_rows as $row ) {
	if ( 2 === (int) $row->rank ) {
		$bruno = $row;
	}
}
if ( null === $bruno || ! RecruitmentClassificationRepository::set_preview_status( (int) $bruno->id, 'denied', null ) ) {
	ffc_rf_fail( 'Could not mark the preliminary row by hand.' );
}
$corrected = "name,cpf,rf,email,adjutancy,rank,score,pcd\n"
	. "Bruno Flow,20456942084,5181780,ci-flow-b@example.test,ci-flow-{$suffix},1,95,nao\n"
	. "Ana Flow,51817842080,,ci-flow-a@example.test,ci-flow-{$suffix},2,90.5,nao\n";
$result    = RecruitmentCsvImporter::import_preview( $cycle, $corrected );
if ( $result['success'] || ! isset( $result['diff'] ) || 2 !== count( $result['diff']['changed'] ) || 1 !== $result['diff']['marks_kept'] ) {
	ffc_rf_fail( 'The corrected list did not report its changes: ' . wp_json_encode( $result ) );
}
if ( ! RecruitmentCsvImporter::import_preview( $cycle, $corrected, false, true )['success'] ) {
	ffc_rf_fail( 'The confirmed corrected import failed.' );
}
$kept = null;
foreach ( RecruitmentClassificationRepository::get_for_notice( $cycle, 'preview' ) as $row ) {
	if ( (int) $row->candidate_id === (int) $bruno->candidate_id ) {
		$kept = $row;
	}
}
if ( null === $kept || 'denied' !== (string) $kept->preview_status || 1 !== (int) $kept->rank ) {
	ffc_rf_fail( 'The manual status did not survive the re-import: ' . wp_json_encode( $kept ) );
}
if ( ! RecruitmentPromotionService::is_bare_promotion( $cycle, 'definitive' ) ) {
	ffc_rf_fail( 'A bare preliminary -> definitive flip was not recognised.' );
}
if ( ! RecruitmentPromotionService::snapshot_to_definitive( $cycle )['success'] ) {
	ffc_rf_fail( 'The second copy to definitive failed.' );
}
$ranks = array();
foreach ( RecruitmentClassificationRepository::get_for_notice( $cycle, 'definitive' ) as $row ) {
	$ranks[ (int) $row->candidate_id ] = (int) $row->rank;
}
if ( 1 !== ( $ranks[ (int) $bruno->candidate_id ] ?? 0 ) || 2 !== count( $ranks ) ) {
	ffc_rf_fail( 'The promotion kept the old definitive list: ' . wp_json_encode( $ranks ) );
}

$deleted = RecruitmentDeleteService::delete_notice( $draft );
if ( ! $deleted['success'] ) {
	ffc_rf_fail( 'Deleting a draft failed: ' . implode( ' | ', $deleted['errors'] ) );
}
$left = (int) $wpdb->get_var(
	$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE notice_id = %d', $wpdb->prefix . 'ffc_recruitment_classification', $draft )
);
$junction = RecruitmentNoticeAdjutancyRepository::get_adjutancy_ids_for_notice( $draft );
if ( 0 !== $left || array() !== $junction || null !== RecruitmentNoticeReader::get_by_id( $draft ) ) {
	ffc_rf_fail( "The draft left rows behind: {$left} classifications, " . count( $junction ) . ' adjutancy links.' );
}

ffc_rf_cleanup();
echo "Recruitment flow: draft import makes no account, the batch links once, the definitive import promotes in one change and refuses a second, a published notice is not deleted, a cancelled call ends and the row can be called again, nobody is called on a draft, a row naming somebody else waits for the operator and keeps the stored identity, a CPF and an RF of two candidates are refused, a re-import waits for its comparison and keeps manual statuses, the promotion after going back copies the corrected list, a draft is deleted with its rows.\n";
exit( 0 );
