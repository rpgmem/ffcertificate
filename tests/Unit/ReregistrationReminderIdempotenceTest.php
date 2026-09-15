<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationSubmissionReader;

/**
 * The reregistration reminder is sent ONCE per campaign, plus once per deadline
 * extension (#1232).
 *
 * THE DEFECT THIS FIXES
 *
 * `run_automated_reminders()` selects campaigns with
 * `DATEDIFF(end_date, CURDATE()) <= reminder_days`, which is a WINDOW and not a
 * day, and the cron is DAILY. With no per-submission mark, every pending
 * participant received `reminder_days` emails -- seven, in the commonest
 * configuration. It was not a theoretical risk: it ran that way in production,
 * with no error and no log.
 *
 * WHAT THIS FILE TESTS, AND WHY NOT THROUGH THE HANDLER
 *
 * The decision of WHO receives one lives entirely in the reader's predicate,
 * and that is where it can regress. Exercising the handler would mean staging
 * wp_mail, templates, `PasswordInvite` and the cron -- a lot of staging to
 * observe a WHERE clause. Here `$wpdb` is a double that merely CAPTURES the
 * prepared SQL, so the assertions speak about the query the product really
 * emits.
 *
 * The trade-off is stated: this proves the predicate, not the send. That the
 * stamp happens per item after each send is covered in
 * `ReregistrationEmailHandlerTest`.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationSubmissionReader
 */
class ReregistrationReminderIdempotenceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var string */
	private string $captured = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Reregistration\ReregistrationSubmissionReader' );

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		// A naive `prepare()`: it interpolates so the test reads the query's
		// INTENT. The double simulates no database -- what matters here is the
		// emitted predicate, not the rows returned.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				foreach ( $values as $v ) {
					$sql = preg_replace( '/%[ids]/', (string) $v, (string) $sql, 1 );
				}
				$this->captured = (string) $sql;
				return (string) $sql;
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Sem extensao de prazo, so quem nunca foi lembrado entra.
	 *
	 * E a asercao que reprova a volta do defeito: sem `reminder_sent_at IS
	 * NULL` no predicado, a consulta devolve todo mundo em toda execucao
	 * diaria.
	 */
	public function test_without_an_extension_only_the_never_reminded_are_selected(): void {
		ReregistrationSubmissionReader::get_awaiting_reminder( 7, null );

		$this->assertStringContainsString(
			'reminder_sent_at IS NULL',
			$this->captured,
			'Without this clause the reminder goes back to being resent on every cron run.'
		);
		$this->assertStringNotContainsString(
			'reminder_sent_at <',
			$this->captured,
			'The reopening may only appear when there is a deadline extension.'
		);
	}

	/**
	 * With an extension, whoever was reminded BEFORE it becomes remindable again
	 * -- once, because the comparison is against the extension's own timestamp.
	 *
	 * It is the same shape the invitation has used since #1190, with
	 * `reminder_sent_at` in place of `invited_at`.
	 */
	public function test_an_extension_reopens_the_reminder_once(): void {
		$extended_at = 1771000000;

		ReregistrationSubmissionReader::get_awaiting_reminder( 7, $extended_at );

		$this->assertStringContainsString( 'reminder_sent_at IS NULL', $this->captured );
		$this->assertStringContainsString(
			'reminder_sent_at < ' . $extended_at,
			$this->captured,
			'The reopening compares against the extension\'s timestamp; without it, extending the deadline reminds nobody.'
		);
	}

	/**
	 * The reminder's base audience is a DELIBERATE subset of what the invitation
	 * reaches.
	 *
	 * The invitation reopens for `UNFINISHED_STATUSES`, which includes `expired`
	 * and `rejected`. Adopting that set as the reminder's base would WIDEN who
	 * gets an email -- a behaviour change that does not belong in a duplication
	 * fix. This assertion exists so that widening cannot enter without somebody
	 * deciding on it.
	 */
	public function test_the_base_audience_stays_pending_and_in_progress(): void {
		ReregistrationSubmissionReader::get_awaiting_reminder( 7, null );

		// The double's `prepare()` interpolates without quoting, so the captured
		// shape is `IN (pending,in_progress)`. What matters here is WHICH statuses
		// enter, not how the driver quotes them.
		$this->assertStringContainsString( 'status IN (pending,in_progress)', $this->captured );
		$this->assertSame(
			array( 'pending', 'in_progress' ),
			ReregistrationSubmissionReader::REMINDABLE_STATUSES
		);
		$this->assertNotSame(
			ReregistrationSubmissionReader::UNFINISHED_STATUSES,
			ReregistrationSubmissionReader::REMINDABLE_STATUSES,
			'If the two sets converge, it was by decision — and this assertion is where that is argued.'
		);
	}

	/**
	 * The order is by `id`, stable under concurrent inserts -- the same choice
	 * #772's export contract makes, and what allows batching this send without a
	 * new row pushing another off the page.
	 */
	public function test_rows_come_back_in_a_stable_order(): void {
		ReregistrationSubmissionReader::get_awaiting_reminder( 7, null );

		$this->assertStringContainsString( 'ORDER BY id ASC', $this->captured );
	}

	/**
	 * With no cursor and no limit the query mentions neither.
	 *
	 * It is the self-check for the two assertions below: without it, a `LIMIT`
	 * glued into the literal would pass the next two tests without any parameter
	 * being read at all.
	 */
	public function test_the_unbatched_read_carries_neither_cursor_nor_limit(): void {
		ReregistrationSubmissionReader::get_awaiting_reminder( 7, null );

		$this->assertStringNotContainsString( 'id > ', $this->captured );
		$this->assertStringNotContainsString( 'LIMIT', $this->captured );
	}

	/**
	 * The keyset cursor becomes `id > N` (#1232 step 2).
	 *
	 * It is not only stable pagination: it is what guarantees PROGRESS. A
	 * submission whose user was deleted never gets a stamp -- `user_id` is
	 * `NOT NULL` and an accepted orphan (#822) -- so without the cursor the next
	 * batch fetches it again and the rescheduling never ends.
	 */
	public function test_a_cursor_narrows_the_page_to_rows_after_it(): void {
		ReregistrationSubmissionReader::get_awaiting_reminder( 7, null, 120, 50 );

		$this->assertStringContainsString( 'id > 120', $this->captured );
	}

	/**
	 * The limit becomes `LIMIT N`, after the `ORDER BY`.
	 *
	 * The order in the SQL matters because `prepare()` takes a single array and
	 * substitutes in the order the placeholders appear: cursor and limit come
	 * after the status and extension clauses.
	 */
	public function test_a_limit_bounds_the_page(): void {
		ReregistrationSubmissionReader::get_awaiting_reminder( 7, null, 120, 50 );

		$this->assertStringContainsString( 'ORDER BY id ASC LIMIT 50', $this->captured );
	}

	/**
	 * With a deadline extension, cursor and limit stay at the end of the query.
	 *
	 * The path with the most placeholders is where a misaligned `prepare()` would
	 * show up -- and it would show up as wrong data, not as an error.
	 */
	public function test_cursor_and_limit_survive_an_extension(): void {
		ReregistrationSubmissionReader::get_awaiting_reminder( 7, 1771000000, 120, 50 );

		$this->assertStringContainsString( 'reminder_sent_at < 1771000000', $this->captured );
		$this->assertStringContainsString( 'id > 120', $this->captured );
		$this->assertStringContainsString( 'ORDER BY id ASC LIMIT 50', $this->captured );
	}
}
