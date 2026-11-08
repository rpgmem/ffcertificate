<?php
/**
 * Short URLs against a real database and a real object cache (#2337).
 *
 * **WHY A REAL DATABASE.** What decides a short URL's fate is a database
 * property: the `idx_short_code` UNIQUE key refuses a second row under a
 * code, the column's collation decides whether `AbC` and `abc` are one code,
 * and `varchar(10)` bounds what a code can be. The unit tests run against a
 * `$wpdb` double and a stubbed `wp_cache_*`, so none of them can show that a
 * disabled, trashed, re-targeted or deleted link stops being served from the
 * cache, or which posts the backfill picks.
 *
 * **WHAT IT PLANTS.** A throwaway post and short URLs under codes that start
 * with `zq`, which the generator never draws twice in a row. Everything
 * planted is deleted before the script exits.
 *
 * @package FreeFormCertificate\CI
 */

declare(strict_types=1);

$wp_root = (string) ( $argv[1] ?? '' );

if ( '' === $wp_root || ! is_readable( $wp_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php url-shortener-db-check.php <wp-root>\n" );
	exit( 2 );
}

require_once $wp_root . '/wp-load.php';

use FreeFormCertificate\UrlShortener\UrlShortenerRepository;
use FreeFormCertificate\UrlShortener\UrlShortenerService;

global $wpdb;

$ffc_us_table   = $wpdb->prefix . 'ffc_short_urls';
$ffc_us_retired = $wpdb->prefix . 'ffc_short_url_retired';
$ffc_us_post    = 0;

/**
 * Remove what the script planted.
 *
 * @return void
 */
function ffc_us_cleanup(): void {
	global $wpdb, $ffc_us_table, $ffc_us_retired, $ffc_us_post;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE LOWER(short_code) LIKE %s', $ffc_us_table, 'zq%' ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE LOWER(short_code) LIKE %s', $ffc_us_retired, 'zq%' ) );
	if ( $ffc_us_post > 0 ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE post_id = %d', $ffc_us_table, $ffc_us_post ) );
		wp_delete_post( $ffc_us_post, true );
	}
}

/**
 * Clean up, report a failure and stop.
 *
 * @param string $message What failed.
 * @return never
 */
function ffc_us_fail( string $message ): void {
	ffc_us_cleanup();
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

/**
 * A planted row, written the way the service writes one.
 *
 * @param UrlShortenerRepository $repo   Repository.
 * @param string                 $code   Code.
 * @param int|null               $post   Post ID.
 * @param string                 $status Status.
 * @return int Row ID, 0 when the database refused it.
 */
function ffc_us_plant( UrlShortenerRepository $repo, string $code, ?int $post = null, string $status = 'active', ?int $now = null ): int {
	$now = $now ?? time();
	$id  = $repo->insert(
		array(
			'short_code'  => $code,
			'target_url'  => 'https://example.org/' . $code,
			'post_id'     => $post,
			'title'       => 'ci',
			'click_count' => 0,
			'created_by'  => 0,
			'created_at'  => $now,
			'updated_at'  => $now,
			'status'      => $status,
		)
	);
	return is_int( $id ) ? $id : 0;
}

foreach ( array( $ffc_us_table, $ffc_us_retired ) as $ffc_us_required ) {
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ffc_us_required ) ) ) {
		ffc_us_fail( "{$ffc_us_required} does not exist." );
	}
}
ffc_us_cleanup();

$ffc_us_service = new UrlShortenerService();
$ffc_us_repo    = $ffc_us_service->get_repository();

// 1. The UNIQUE key refuses a second row under a code.
$ffc_us_first = ffc_us_plant( $ffc_us_repo, 'zqUnique1' );
if ( $ffc_us_first <= 0 ) {
	ffc_us_fail( 'A short URL could not be inserted.' );
}
$wpdb->suppress_errors( true );
$ffc_us_second = ffc_us_plant( $ffc_us_repo, 'zqUnique1' );
$wpdb->suppress_errors( false );
if ( 0 !== $ffc_us_second ) {
	ffc_us_fail( 'A second row under the same code was accepted: idx_short_code is not unique.' );
}
echo "OK   the UNIQUE key refuses a second row under a code\n";

// 2. The collation: whatever it is, the lookup, codeExists() and the key agree.
$ffc_us_collation = (string) $wpdb->get_var(
	$wpdb->prepare( 'SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $ffc_us_table, 'short_code' )
);
$ffc_us_other_case = 'ZQuNIQUE1';
$ffc_us_found      = null !== $ffc_us_repo->findByShortCode( $ffc_us_other_case );
$ffc_us_exists     = $ffc_us_repo->codeExists( $ffc_us_other_case );
$wpdb->suppress_errors( true );
$ffc_us_refused = 0 === ffc_us_plant( $ffc_us_repo, $ffc_us_other_case );
$wpdb->suppress_errors( false );
if ( $ffc_us_found !== $ffc_us_exists || $ffc_us_exists !== $ffc_us_refused ) {
	ffc_us_fail( "Collation {$ffc_us_collation}: lookup " . var_export( $ffc_us_found, true ) . ', codeExists ' . var_export( $ffc_us_exists, true ) . ', key refused ' . var_export( $ffc_us_refused, true ) . ' disagree on a code in another case.' );
}
// Case counts (#2337): the generator draws from both cases.
if ( $ffc_us_exists || '_bin' !== substr( $ffc_us_collation, -4 ) ) {
	ffc_us_fail( "short_code is {$ffc_us_collation}: a code in another case is the same code, so the generator's upper and lower case would collide." );
}
echo 'OK   collation ' . $ffc_us_collation . ': codes in another case are ' . ( $ffc_us_exists ? 'one code' : 'two codes' ) . ", and the lookup, codeExists() and the key agree\n";

// 3. The column holds the longest code the settings allow.
$ffc_us_width = (int) $wpdb->get_var(
	$wpdb->prepare( 'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $ffc_us_table, 'short_code' )
);
if ( $ffc_us_width < 10 ) {
	ffc_us_fail( "short_code is varchar({$ffc_us_width}), shorter than the 10 characters the settings allow." );
}
$ffc_us_long = ffc_us_plant( $ffc_us_repo, 'zq34567890' );
if ( $ffc_us_long <= 0 || null === $ffc_us_repo->findByShortCode( 'zq34567890' ) ) {
	ffc_us_fail( 'A ten-character code was not stored and read back whole.' );
}
echo "OK   short_code is varchar({$ffc_us_width}) and holds a ten-character code\n";

// 4. The cache follows every write.
$ffc_us_id = ffc_us_plant( $ffc_us_repo, 'zqCache1' );
$ffc_us_repo->findByShortCode( 'zqCache1' );
$ffc_us_service->set_active( $ffc_us_id, false );
if ( 'disabled' !== ( $ffc_us_repo->findByShortCode( 'zqCache1' )['status'] ?? '' ) ) {
	ffc_us_fail( 'A disabled link is still served active from the cache.' );
}
$ffc_us_service->set_active( $ffc_us_id, true );
$ffc_us_service->trash_short_url( $ffc_us_id );
if ( 'trashed' !== ( $ffc_us_repo->findByShortCode( 'zqCache1' )['status'] ?? '' ) ) {
	ffc_us_fail( 'A trashed link is still served from the cache.' );
}
$ffc_us_service->restore_short_url( $ffc_us_id );
$ffc_us_service->set_active( $ffc_us_id, true );
$ffc_us_service->update_short_url( $ffc_us_id, 'https://example.org/moved', 'ci' );
if ( 'https://example.org/moved' !== ( $ffc_us_repo->findByShortCode( 'zqCache1' )['target_url'] ?? '' ) ) {
	ffc_us_fail( 'A re-targeted link still redirects to its old destination from the cache.' );
}
$ffc_us_service->delete_short_url( $ffc_us_id );
if ( null !== $ffc_us_repo->findByShortCode( 'zqCache1' ) ) {
	ffc_us_fail( 'A deleted link is still served from the cache.' );
}
if ( null !== $ffc_us_repo->findByShortCode( 'zqLater1' ) ) {
	ffc_us_fail( 'An unplanted code was found.' );
}
ffc_us_plant( $ffc_us_repo, 'zqLater1' );
if ( null === $ffc_us_repo->findByShortCode( 'zqLater1' ) ) {
	ffc_us_fail( 'A code remembered as missing stayed missing after it was created.' );
}
echo "OK   disable, trash, re-target and delete reach the cache, and a remembered miss clears on insert\n";

// 4b. Deleting retires the code, and a retired code is never issued (#2337).
if ( ! $ffc_us_repo->codeRetired( 'zqCache1' ) ) {
	ffc_us_fail( 'A deleted link left its code free to be issued again.' );
}
if ( $ffc_us_repo->codeRetired( 'ZQCACHE1' ) !== $ffc_us_exists ) {
	ffc_us_fail( 'The retired codes compare case differently from the links table.' );
}
$ffc_us_drawn = $ffc_us_service->generate_unique_code();
if ( 1 !== preg_match( '/^[A-Za-z0-9]+$/', $ffc_us_drawn ) || $ffc_us_repo->codeExists( $ffc_us_drawn ) || $ffc_us_repo->codeRetired( $ffc_us_drawn ) ) {
	ffc_us_fail( "The generator drew '{$ffc_us_drawn}', which is not a free code." );
}
echo "OK   deleting a link retires its code, and the generator draws a free code\n";

// 4c. Empty Trash removes every trashed link, retires each code and counts them.
$ffc_us_trashed_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $ffc_us_table, 'trashed' ) );
foreach ( array( 'zqTrash1', 'zqTrash2', 'zqTrash3' ) as $ffc_us_code ) {
	ffc_us_plant( $ffc_us_repo, $ffc_us_code, null, 'trashed' );
}
$ffc_us_emptied = $ffc_us_service->empty_trash();
$ffc_us_left    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $ffc_us_table, 'trashed' ) );
if ( $ffc_us_emptied !== $ffc_us_trashed_before + 3 || 0 !== $ffc_us_left || ! $ffc_us_repo->codeRetired( 'zqTrash2' ) ) {
	ffc_us_fail( "Empty Trash reported {$ffc_us_emptied} for " . ( $ffc_us_trashed_before + 3 ) . " trashed links and left {$ffc_us_left}." );
}
echo "OK   Empty Trash deletes every trashed link, retires their codes and counts them\n";

// 5. The backfill leaves alone a post whose link is disabled.
$ffc_us_post = (int) wp_insert_post(
	array(
		'post_title'  => 'ffc short url ci',
		'post_status' => 'publish',
		'post_type'   => 'post',
	)
);
if ( $ffc_us_post <= 0 ) {
	ffc_us_fail( 'The test post could not be created.' );
}
// Publishing may already have given it a link; start from none.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE post_id = %d', $ffc_us_table, $ffc_us_post ) );
wp_cache_flush();
$ffc_us_before = $ffc_us_repo->countBackfillCandidates( array( 'post' ) );
ffc_us_plant( $ffc_us_repo, 'zqPost1', $ffc_us_post, 'disabled' );
$ffc_us_after = $ffc_us_repo->countBackfillCandidates( array( 'post' ) );
$ffc_us_page  = $ffc_us_repo->findBackfillCandidates( array( 'post' ), PHP_INT_MAX, 1000 );
if ( $ffc_us_after !== $ffc_us_before - 1 || in_array( (string) $ffc_us_post, array_map( 'strval', array_column( $ffc_us_page, 'ID' ) ), true ) ) {
	ffc_us_fail( "The backfill still offers a post whose link is disabled (before {$ffc_us_before}, after {$ffc_us_after})." );
}
echo "OK   the backfill leaves alone a post whose link is disabled\n";

// 6. Regenerate swaps the code in place and retires the old one (#2337).
$ffc_us_swap_id = ffc_us_plant( $ffc_us_repo, 'zqSwap1' );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET click_count = 7 WHERE id = %d', $ffc_us_table, $ffc_us_swap_id ) );
$ffc_us_repo->findByShortCode( 'zqSwap1' );
$ffc_us_swapped = $ffc_us_service->regenerate_code( (array) $ffc_us_repo->findById( $ffc_us_swap_id ) );
$ffc_us_new     = (string) ( $ffc_us_swapped['data']['short_code'] ?? '' );
$ffc_us_reason  = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT reason FROM %i WHERE short_code = %s', $ffc_us_retired, 'zqSwap1' ) );
$ffc_us_after   = '' === $ffc_us_new ? null : $ffc_us_repo->findByShortCode( $ffc_us_new );
if ( empty( $ffc_us_swapped['success'] ) || null !== $ffc_us_repo->findByShortCode( 'zqSwap1' ) || 'regenerated' !== $ffc_us_reason
	|| ! $ffc_us_after || (int) $ffc_us_after['id'] !== $ffc_us_swap_id || 7 !== (int) $ffc_us_after['click_count'] ) {
	ffc_us_fail( 'Regenerate did not give the same row a new code, keep its clicks and retire the old code.' );
}
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', $ffc_us_table, $ffc_us_swap_id ) );
echo "OK   Regenerate gives the same row a new code, keeps its clicks and retires the old one\n";

// 7. The link follows its post: trash disables, publishing again restores, delete retires.
$ffc_us_follow = (int) wp_insert_post(
	array(
		'post_title'  => 'ffc short url ci follow',
		'post_status' => 'publish',
		'post_type'   => 'post',
	)
);
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE post_id = %d', $ffc_us_table, $ffc_us_follow ) );
wp_cache_flush();
ffc_us_plant( $ffc_us_repo, 'zqFollow1', $ffc_us_follow );
$ffc_us_status = static function () use ( $ffc_us_repo, $ffc_us_follow ): string {
	$row = $ffc_us_repo->findByPostId( $ffc_us_follow );
	return $row ? (string) $row['status'] : 'none';
};
wp_trash_post( $ffc_us_follow );
$ffc_us_trashed = $ffc_us_status();
wp_untrash_post( $ffc_us_follow );
$ffc_us_untrashed = $ffc_us_status();
wp_publish_post( $ffc_us_follow );
$ffc_us_published = $ffc_us_status();
wp_delete_post( $ffc_us_follow, true );
$ffc_us_deleted = $ffc_us_status();
$ffc_us_follow_retired = $ffc_us_repo->codeRetired( 'zqFollow1' );
// wp_untrash_post() restores to draft unless a filter says otherwise, and a
// draft's link stays off until the post is published; either is accepted.
if ( 'disabled' !== $ffc_us_trashed || ! in_array( $ffc_us_untrashed, array( 'active', 'disabled' ), true ) || 'active' !== $ffc_us_published || 'none' !== $ffc_us_deleted || ! $ffc_us_follow_retired ) {
	ffc_us_fail( "The link did not follow its post: trashed {$ffc_us_trashed}, restored {$ffc_us_untrashed}, published {$ffc_us_published}, deleted {$ffc_us_deleted}." );
}
echo "OK   the link follows its post: trash disables it, publishing restores it, deleting retires its code\n";

// 8. The instants (#2337): both columns are BIGINT, and the cleaner's
// "never clicked" age compares them with a cutoff in UTC seconds.
foreach ( array( 'created_at', 'updated_at' ) as $ffc_us_column ) {
	$ffc_us_type = (string) $wpdb->get_var(
		$wpdb->prepare( 'SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $ffc_us_table, $ffc_us_column )
	);
	if ( 'bigint' !== strtolower( $ffc_us_type ) ) {
		ffc_us_fail( "{$ffc_us_column} is {$ffc_us_type}, not an instant (BIGINT)." );
	}
}
ffc_us_plant( $ffc_us_repo, 'zqOld1', null, 'active', time() - 40 * DAY_IN_SECONDS );
ffc_us_plant( $ffc_us_repo, 'zqNew1' );
$ffc_us_never = array_column( $ffc_us_repo->find_cleanup_candidates( array( 'never_clicked' => true ), 30 ), 'short_code' );
if ( ! in_array( 'zqOld1', $ffc_us_never, true ) || in_array( 'zqNew1', $ffc_us_never, true ) ) {
	ffc_us_fail( 'The never-clicked age is wrong: ' . implode( ', ', $ffc_us_never ) );
}
echo "OK   created_at and updated_at are instants, and the never-clicked age compares them in UTC seconds\n";

ffc_us_cleanup();
echo "URL SHORTENER DB CHECK PASSED\n";
