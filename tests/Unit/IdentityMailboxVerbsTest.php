<?php
/**
 * What the shared-mailbox panel may offer, and what it may never.
 *
 * @package FreeFormCertificate
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Maintenance\IdentityQueue;
use PHPUnit\Framework\TestCase;

/**
 * The shared-mailbox tier offers MOVE and SPLIT, and never CONSOLIDATE.
 *
 * #1368 withheld all three, for a harm it named precisely: verbs that would
 * write one person's number onto another person's records. That describes
 * consolidate, which rewrites one identifier into another. It does not describe
 * a move, which relocates records without touching any number, nor a split,
 * which creates an account nobody else uses -- so the sweep removed three
 * verbs for a reason that justified one, and left the screen telling an
 * operator to decide with HR with nowhere to put the answer (#1461).
 *
 * The two that returned are asymmetric and the asymmetry is the rule: where
 * both are supplied the split wins, because a wrong split leaves the records
 * alone on a fresh account and stays correctable while a move mixes them into
 * another person's and the data can no longer separate them.
 *
 * @covers \FreeFormCertificate\Maintenance\IdentityQueue
 */
class IdentityMailboxVerbsTest extends TestCase {

	/**
	 * The view, read as text.
	 *
	 * @return string
	 */
	private function view(): string {
		return (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );
	}

	/**
	 * The page class, read as text.
	 *
	 * @return string
	 */
	private function page(): string {
		return (string) file_get_contents( __DIR__ . '/../../includes/admin/class-ffc-identity-resolution-page.php' );
	}

	/**
	 * The self-check: the scan is reading the files it thinks it is.
	 *
	 * An exact comparison against a non-empty register rather than a floor,
	 * because a guard whose subject silently became an empty string would
	 * report every rule below as satisfied.
	 */
	public function test_the_scan_reads_both_files(): void {
		$this->assertStringContainsString(
			'IdentityQueue::TIER_MAILBOX',
			$this->view(),
			'The view no longer names the shared-mailbox tier, so nothing below is measuring that panel.'
		);

		$this->assertStringContainsString(
			'mailbox_move_needs_acknowledgement',
			$this->page(),
			'The page no longer names the acknowledgement predicate, so the server-side half is gone.'
		);
	}

	/**
	 * The panel renders the two verbs, which means it shares the branch.
	 *
	 * Asserted on the branch rather than on the buttons: the buttons are
	 * rendered once for both tiers, so a test looking for a second copy of
	 * them would be looking for markup that should not exist.
	 */
	public function test_the_panel_shares_the_branch_that_renders_move_and_split(): void {
		$this->assertStringContainsString(
			'IdentityQueue::TIER_DECISION === $ffc_identity_tier || IdentityQueue::TIER_MAILBOX === $ffc_identity_tier',
			$this->view(),
			'The shared-mailbox panel no longer shares the branch that renders move and split, so it offers neither'
				. ' -- which is the state #1461 was opened to end.'
		);
	}

	/**
	 * THE PANEL'S OWN SENTENCE MUST NOT DENY THE BUTTONS UNDER IT (#1461).
	 *
	 * The note above these cards ended `No verb is offered here: read the
	 * account and decide with HR`. That was true while the tier was classified
	 * and then refused every verb; #1461 gave it two, and the sentence stayed
	 * for four releases — so the panel told the operator there was nothing to
	 * do while the cards beside it offered move and split. It was found in a
	 * screenshot of the shipped screen, with the denial and the two buttons in
	 * one frame, which is the only way it could be found: every test here
	 * asserted the CONTROLS, and the prose describing them was nobody's.
	 *
	 * Asserted in both directions, because either alone is satisfiable by
	 * accident: the denial must be gone, AND the tier's note must name what it
	 * does offer. A note trimmed to the diagnosis alone would pass the first.
	 */
	public function test_the_tier_note_does_not_deny_the_verbs_the_tier_offers(): void {
		$view = $this->view();

		$this->assertStringNotContainsString(
			'No verb is offered here',
			$view,
			'The panel denies the two verbs it renders, which is what shipped from 6.29.0 onwards.'
		);

		// ANCHORED ON THE NOTE CLOSURE, BECAUSE THE TIER IS A `case` IN SEVERAL
		// SWITCHES. The first one in the file is the tier's LABEL (`One
		// address, several people`), so anchoring on the `case` alone reads
		// the wrong string and reports a note that says nothing -- which is
		// how the first version of this test failed.
		$note = (string) strstr( $view, '$ffc_identity_tier_note = static function' );
		$note = (string) strstr( $note, 'case IdentityQueue::TIER_MAILBOX:' );
		$note = (string) strstr( $note, "', 'ffcertificate' );", true );

		$this->assertNotSame( '', $note, 'The mailbox tier has no note at all, so nothing above the cards says what they are.' );

		foreach ( array( 'moves to the account', 'splits onto a new one' ) as $verb ) {
			$this->assertStringContainsString(
				$verb,
				$note,
				sprintf( 'The tier note must name the verb it offers: %s', $verb )
			);
		}
	}

	/**
	 * Consolidate stays absent, which is what the tier is for.
	 *
	 * The consolidate form is rendered under the mechanical tier's branch, so
	 * the rule is that the mailbox tier never appears in that branch's
	 * condition. Reading it this way rather than counting forms is what keeps
	 * the assertion true when the markup is rearranged.
	 */
	public function test_the_panel_never_shares_the_branch_that_renders_consolidate(): void {
		$view = $this->view();

		$consolidate = strpos( $view, 'IdentityResolutionPage::CONSOLIDATE_ACTION' );

		$this->assertNotFalse(
			$consolidate,
			'The consolidate action is no longer rendered anywhere, so this guard is watching a verb that left.'
		);

		// The branch that opens the consolidate form is the nearest tier test
		// above it. Naming the mailbox tier there is the regression.
		$before = substr( $view, 0, $consolidate );
		$branch = strrpos( $before, '$ffc_identity_tier ) : ?>' );

		$this->assertNotFalse( $branch, 'The consolidate form is no longer inside a tier branch.' );

		$this->assertStringNotContainsString(
			'TIER_MAILBOX',
			substr( $before, $branch - 200, 200 + strlen( '$ffc_identity_tier ) : ?>' ) ),
			'The shared-mailbox tier reached the branch that renders CONSOLIDATE. That verb rewrites one identifier'
				. ' into another, and where the identifiers belong to different people it writes one person\'s number'
				. ' onto another person\'s records -- which is the whole reason this tier exists.'
		);
	}

	/**
	 * The move carries an acknowledgement and the split does not.
	 *
	 * The asymmetry is the safety rule, so it is asserted rather than left to
	 * a reader of the markup: the irreversible verb is the one that asks.
	 */
	public function test_the_acknowledgement_is_on_the_move_only(): void {
		$view = $this->view();

		$this->assertStringContainsString(
			'name="ffc_acknowledged"',
			$view,
			'The shared-mailbox move no longer asks for the acknowledgement, so the one movement on this screen that'
				. ' cannot be undone is a single click.'
		);

		// THE CLASS, BECAUSE IT SHIPPED WITHOUT ONE AND THAT WAS VISIBLE.
		//
		// #1461 left the label bare on a comment asserting that no
		// `.ffc-identity-` rule existed to put beside it; there are 152, and
		// the search that said otherwise had failed rather than found
		// nothing. Rendered, the checkbox sat on the first line and the
		// sentence ran the width of the verb column, across the button below.
		// Asserted here rather than left to the eye, since the defect was
		// invisible to every gate and visible in the first screenshot.
		$this->assertStringContainsString(
			'class="ffc-identity-ack"',
			$view,
			'The acknowledgement label lost its class, so nothing gives it an alignment or a measure and its sentence'
				. ' runs over the control beneath it.'
		);

		$this->assertStringContainsString(
			'.ffc-identity-ack',
			(string) file_get_contents( __DIR__ . '/../../assets/css/ffc-admin.css' ),
			'The class is emitted with no rule to receive it, which is the state that shipped and rendered broken.'
		);

		$split = strpos( $view, 'IdentityResolutionPage::SPLIT_NONCE' );
		$this->assertNotFalse( $split, 'The split form is gone.' );

		$this->assertStringNotContainsString(
			'name="ffc_acknowledged"',
			substr( $view, $split ),
			'The acknowledgement moved onto the split. It belongs to the move: the address a split makes the operator'
				. ' type is already its deliberateness gate, and a split stays correctable afterwards.'
		);
	}

	/**
	 * The precedence is declared where the JavaScript can read it.
	 */
	public function test_the_inverted_precedence_is_declared_on_the_address_field(): void {
		$view = $this->view();

		foreach ( array( 'data-ffc-prefer-split', 'data-ffc-move', 'data-ffc-move-submit' ) as $attribute ) {
			$this->assertStringContainsString(
				$attribute,
				$view,
				"The address field no longer carries `{$attribute}`, so nothing tells the search dialog that a typed"
					. ' address outranks a chosen destination on this panel.'
			);
		}

		$script = (string) file_get_contents( __DIR__ . '/../../assets/js/ffc-identity-search.js' );

		$this->assertStringContainsString(
			'barMove',
			$script,
			'The script no longer bars the move, so the two verbs stop being exclusive and the precedence is decorative.'
		);
	}

	/**
	 * The separator has one owner, and the page reads it rather than repeating it.
	 */
	public function test_the_page_reads_the_separator_rather_than_repeating_it(): void {
		$this->assertStringContainsString(
			'IdentityQueue::KEY_SEPARATOR',
			$this->page(),
			'The page spells the cursor separator itself instead of reading it, so the two will disagree the next time'
				. ' it changes -- and it has changed once already (#1459).'
		);

		$this->assertSame(
			'-',
			IdentityQueue::KEY_SEPARATOR,
			'The separator moved. `IdentityQueueKeyTest` says what it may be; this says the two files agree on what it is.'
		);
	}
}
