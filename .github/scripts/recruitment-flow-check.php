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

use FreeFormCertificate\Recruitment\RecruitmentAccountLinker;
use FreeFormCertificate\Recruitment\RecruitmentAdjutancyWriter;
use FreeFormCertificate\Recruitment\RecruitmentCallService;
use FreeFormCertificate\Recruitment\RecruitmentCsvImporter;
use FreeFormCertificate\Recruitment\RecruitmentDeleteService;
use FreeFormCertificate\Recruitment\RecruitmentNoticeAdjutancyRepository;
use FreeFormCertificate\Recruitment\RecruitmentNoticeReader;
use FreeFormCertificate\Recruitment\RecruitmentNoticeStateMachine;
use FreeFormCertificate\Recruitment\RecruitmentNoticeWriter;

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
	. "Bruno Flow,20456942084,,ci-flow-b@example.test,ci-flow-{$suffix},2,80,nao\n";

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

// 7. The call is cancelled.
$cancel = RecruitmentCallService::cancel_call( (int) $call['call_ids'][0], 'CI flow check', 1 );
if ( ! $cancel['success'] ) {
	ffc_rf_fail( 'Cancelling the call failed: ' . implode( ' | ', $cancel['errors'] ) );
}

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
echo "Recruitment flow: draft import makes no account, the batch links once, the definitive import promotes in one change and refuses a second, a published notice is not deleted, a draft is deleted with its rows.\n";
exit( 0 );
