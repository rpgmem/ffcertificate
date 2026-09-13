<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationFrontend;

/**
 * Thrown by wp_send_json_error stub to halt execution (simulates die()).
 */
class FrontendJsonErrorException extends \RuntimeException {
	/** @var array<string, mixed> */
	public array $payload;

	public function __construct(array $payload = array()) {
		$this->payload = $payload;
		parent::__construct($payload['message'] ?? 'wp_send_json_error');
	}
}

/**
 * Thrown by wp_send_json_success stub to halt execution (simulates die()).
 */
class FrontendJsonSuccessException extends \RuntimeException {
	/** @var array<string, mixed> */
	public array $payload;

	public function __construct(array $payload = array()) {
		$this->payload = $payload;
		parent::__construct('wp_send_json_success');
	}
}

/**
 * Tests for ReregistrationFrontend: init hook registration,
 * AJAX get_form / submit / save_draft guards and delegation,
 * and backward-compatible delegate methods.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationFrontend
 */
class ReregistrationFrontendTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, array{0: string, 1: array}> Captured add_action calls */
	private array $registered_actions = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Translation stubs
		Functions\when('__')->returnArg();
		Functions\when('esc_html__')->returnArg();
		Functions\when('esc_html')->returnArg();
		Functions\when('esc_attr')->returnArg();
		Functions\when('wp_kses_post')->returnArg();

		// Default WP stubs
		Functions\when('absint')->alias(function ($val) {
			return abs(intval($val));
		});
		Functions\when('sanitize_text_field')->alias('trim');
		Functions\when('wp_unslash')->returnArg();
		Functions\when('check_ajax_referer')->justReturn(true);

		// Capture add_action
		$actions = &$this->registered_actions;
		Functions\when('add_action')->alias(function ($hook, $callback) use (&$actions) {
			$actions[$hook] = $callback;
			return true;
		});

		// JSON response stubs — throw to simulate die()
		Functions\when('wp_send_json_error')->alias(function ($data = null) {
			throw new FrontendJsonErrorException(is_array($data) ? $data : array());
		});

		Functions\when('wp_send_json_success')->alias(function ($data = null) {
			throw new FrontendJsonSuccessException(is_array($data) ? $data : array());
		});

		// Mock $wpdb for repository calls
		global $wpdb;
		$wpdb = Mockery::mock('wpdb');
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive('prepare')->andReturnUsing(function () {
			return func_get_args()[0];
		})->byDefault();
		$wpdb->shouldReceive('get_row')->andReturn(null)->byDefault();
		$wpdb->shouldReceive('get_results')->andReturn(array())->byDefault();
		$wpdb->shouldReceive('get_col')->andReturn(array())->byDefault();
		$wpdb->shouldReceive('update')->andReturn(1)->byDefault();

		// Cache stubs
		Functions\when('wp_cache_get')->justReturn(false);
		Functions\when('wp_cache_set')->justReturn(true);
		Functions\when('wp_cache_delete')->justReturn(true);

		$this->registered_actions = array();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		unset($_POST['reregistration_id'], $_POST['nonce']);
	}

	// ==================================================================
	// init()
	// ==================================================================

	public function test_init_registers_three_ajax_actions(): void {
		ReregistrationFrontend::init();

		$this->assertArrayHasKey('wp_ajax_ffc_get_reregistration_form', $this->registered_actions);
		$this->assertArrayHasKey('wp_ajax_ffc_submit_reregistration', $this->registered_actions);
		$this->assertArrayHasKey('wp_ajax_ffc_save_reregistration_draft', $this->registered_actions);
	}

	public function test_init_callbacks_point_to_class_methods(): void {
		ReregistrationFrontend::init();

		$get_form_cb = $this->registered_actions['wp_ajax_ffc_get_reregistration_form'];
		$this->assertIsArray($get_form_cb);
		$this->assertSame(ReregistrationFrontend::class, $get_form_cb[0]);
		$this->assertSame('ajax_get_form', $get_form_cb[1]);
	}

	// ==================================================================
	// ajax_get_form() — early-exit guards
	// ==================================================================

	public function test_ajax_get_form_errors_when_no_reregistration_id(): void {
		Functions\when('get_current_user_id')->justReturn(1);

		$_POST['reregistration_id'] = 0;

		$ex = null;
		try {
			ReregistrationFrontend::ajax_get_form();
		} catch (FrontendJsonErrorException $e) {
			$ex = $e;
		}

		$this->assertNotNull($ex);
		$this->assertStringContainsString('Invalid request', $ex->payload['message']);
	}

	public function test_ajax_get_form_errors_when_no_user(): void {
		Functions\when('get_current_user_id')->justReturn(0);

		$_POST['reregistration_id'] = 5;

		$ex = null;
		try {
			ReregistrationFrontend::ajax_get_form();
		} catch (FrontendJsonErrorException $e) {
			$ex = $e;
		}

		$this->assertNotNull($ex);
		$this->assertStringContainsString('Invalid request', $ex->payload['message']);
	}

	public function test_ajax_get_form_errors_when_rereg_not_found(): void {
		Functions\when('get_current_user_id')->justReturn(1);

		$_POST['reregistration_id'] = 99;

		// wpdb->get_row returns null (no reregistration found)
		$ex = null;
		try {
			ReregistrationFrontend::ajax_get_form();
		} catch (FrontendJsonErrorException $e) {
			$ex = $e;
		}

		$this->assertNotNull($ex);
		$this->assertStringContainsString('not found or not active', $ex->payload['message']);
	}

	/**
	 * Sem linha E fora dos públicos da campanha: recusa, e NADA é criado.
	 *
	 * Este teste afirmava o defeito. Ele congelava a mensagem "No submission
	 * found for this user." como comportamento correto — e era ela o beco sem
	 * saída: a #1125 pôs `no_submission` em SUBMITTABLE_STATUSES, o painel
	 * desenhou o botão, e o handler recusava justamente o estado declarado
	 * submissível. A recusa continua existindo, mas só para quem de fato não
	 * pertence à campanha.
	 *
	 * A metade que importa aqui é a de segurança: criar sob demanda não pode
	 * virar "qualquer usuário logado ganha uma linha ao postar um id".
	 */
	public function test_ajax_get_form_refuses_a_user_outside_the_campaign_audiences(): void {
		Functions\when('get_current_user_id')->justReturn(1);

		$_POST['reregistration_id'] = 1;

		global $wpdb;
		$rereg = (object) array('id' => 1, 'status' => 'active', 'title' => 'Test');
		// get_by_id -> campanha ativa; lookup da submissão -> nada.
		$wpdb->shouldReceive('get_row')->andReturn($rereg, null);
		// get_active_for_user não devolve a campanha: usuário sem público.
		$wpdb->shouldReceive('get_results')->andReturn(array());
		$wpdb->shouldNotReceive('insert');

		$ex = null;
		try {
			ReregistrationFrontend::ajax_get_form();
		} catch (FrontendJsonErrorException $e) {
			$ex = $e;
		}

		$this->assertNotNull($ex);
		$this->assertStringContainsString('not part of this reregistration', $ex->payload['message']);
	}

	/**
	 * Sem linha mas DENTRO da campanha: a linha nasce, e o fluxo segue.
	 *
	 * O caminho feliz que faltava. A suíte só tinha testes de recusa para os
	 * três handlers — inclusive o que afirmava a recusa errada —, e o único
	 * teste da #1125 afirma que `no_submission` está na constante. Ou seja: a
	 * declaração estava provada e o comportamento nunca.
	 *
	 * Vai pelo `save_draft` de propósito: é o handler mais raso depois de
	 * `submission_for()`, então prova a criação sem precisar de alias mock no
	 * renderizador — que é global de processo e quebraria testes vizinhos pela
	 * ordem (a lição do #1053 / #1177).
	 */
	public function test_ajax_save_draft_creates_the_missing_row_for_an_entitled_user(): void {
		Functions\when('get_current_user_id')->justReturn(7);
		Functions\when('wp_parse_args')->alias(function ($args, $defaults) {
			return array_merge($defaults, $args);
		});
		Functions\when('wp_json_encode')->alias('json_encode');
		Functions\when('sanitize_textarea_field')->returnArg();

		$_POST['reregistration_id'] = 5;

		$rereg      = (object) array('id' => 5, 'status' => 'active', 'title' => 'Campanha');
		$audience   = (object) array('id' => 3, 'parent_id' => null, 'name' => 'Público');
		$created    = (object) array('id' => 42, 'reregistration_id' => '5', 'user_id' => '7', 'status' => 'pending');
		$lookups    = 0;

		global $wpdb;
		// `prepare` devolvendo o SQL cru não distingue as duas consultas
		// `WHERE id = %d` (campanha e público), então aqui ele interpola.
		$wpdb->shouldReceive('prepare')->andReturnUsing(function (...$args) {
			$sql  = array_shift($args);
			$flat = array();
			foreach ($args as $arg) {
				foreach (is_array($arg) ? $arg : array($arg) as $one) {
					$flat[] = $one;
				}
			}
			return preg_replace_callback('/%[ids]/', function () use (&$flat) {
				return (string) array_shift($flat);
			}, $sql);
		});

		$wpdb->shouldReceive('get_row')->andReturnUsing(
			function ($sql) use ($rereg, $audience, $created, &$lookups) {
				if (false !== strpos($sql, 'reregistration_id = 5 AND user_id = 7')) {
					++$lookups;
					// Antes do INSERT não existe; depois dele, existe.
					return $lookups > 1 ? $created : null;
				}
				if (false !== strpos($sql, 'ffc_reregistrations WHERE id = 5')) {
					return $rereg;
				}
				if (false !== strpos($sql, 'ffc_audiences WHERE id = 3')) {
					return $audience;
				}
				return null;
			}
		);

		$wpdb->shouldReceive('get_results')->andReturnUsing(
			function ($sql) use ($rereg, $audience) {
				// Públicos do usuário.
				if (false !== strpos($sql, 'm.user_id = 7')) {
					return array($audience);
				}
				// Campanhas ativas do público.
				if (false !== strpos($sql, 'SELECT DISTINCT r.*')) {
					return array($rereg);
				}
				return array();
			}
		);

		$inserted = array();
		$wpdb->shouldReceive('insert')->once()->andReturnUsing(
			function ($table, $data) use (&$inserted) {
				$inserted = $data;
				return 1;
			}
		);
		$wpdb->insert_id = 42;

		$ex = null;
		try {
			ReregistrationFrontend::ajax_save_draft();
		} catch (FrontendJsonSuccessException $e) {
			$ex = $e;
		}

		$this->assertNotNull($ex, 'o rascunho deveria ter sido salvo sobre a linha recém-criada');
		$this->assertSame(5, $inserted['reregistration_id']);
		$this->assertSame(7, $inserted['user_id']);
		$this->assertSame('pending', $inserted['status'], 'a linha nasce no mesmo estado que a semeadura escreve');
		$this->assertSame(2, $lookups, 'lê, cria, relê — a releitura é o que resolve a corrida contra o UNIQUE');
	}

	public function test_ajax_get_form_errors_when_submission_already_approved(): void {
		Functions\when('get_current_user_id')->justReturn(1);

		$_POST['reregistration_id'] = 1;

		global $wpdb;
		$rereg = (object) array('id' => 1, 'status' => 'active', 'title' => 'Test');
		$submission = (object) array('id' => 10, 'status' => 'approved', 'user_id' => 1);
		$wpdb->shouldReceive('get_row')
			->andReturn($rereg, $submission);

		$ex = null;
		try {
			ReregistrationFrontend::ajax_get_form();
		} catch (FrontendJsonErrorException $e) {
			$ex = $e;
		}

		$this->assertNotNull($ex);
		$this->assertStringContainsString('already been completed or expired', $ex->payload['message']);
	}

	public function test_ajax_get_form_errors_when_submission_expired(): void {
		Functions\when('get_current_user_id')->justReturn(1);

		$_POST['reregistration_id'] = 1;

		global $wpdb;
		$rereg = (object) array('id' => 1, 'status' => 'active', 'title' => 'Test');
		$submission = (object) array('id' => 10, 'status' => 'expired', 'user_id' => 1);
		$wpdb->shouldReceive('get_row')
			->andReturn($rereg, $submission);

		$ex = null;
		try {
			ReregistrationFrontend::ajax_get_form();
		} catch (FrontendJsonErrorException $e) {
			$ex = $e;
		}

		$this->assertNotNull($ex);
		$this->assertStringContainsString('already been completed or expired', $ex->payload['message']);
	}

	// ==================================================================
	// ajax_submit() — early-exit guards
	// ==================================================================

	public function test_ajax_submit_errors_when_no_reregistration_id(): void {
		Functions\when('get_current_user_id')->justReturn(1);

		$_POST['reregistration_id'] = 0;

		$ex = null;
		try {
			ReregistrationFrontend::ajax_submit();
		} catch (FrontendJsonErrorException $e) {
			$ex = $e;
		}

		$this->assertNotNull($ex);
		$this->assertStringContainsString('Invalid request', $ex->payload['message']);
	}

	public function test_ajax_submit_errors_when_rereg_not_found(): void {
		Functions\when('get_current_user_id')->justReturn(1);

		$_POST['reregistration_id'] = 99;

		$ex = null;
		try {
			ReregistrationFrontend::ajax_submit();
		} catch (FrontendJsonErrorException $e) {
			$ex = $e;
		}

		$this->assertNotNull($ex);
		$this->assertStringContainsString('not found or not active', $ex->payload['message']);
	}

	public function test_ajax_submit_errors_when_submission_already_approved(): void {
		Functions\when('get_current_user_id')->justReturn(1);

		$_POST['reregistration_id'] = 1;

		global $wpdb;
		$rereg = (object) array('id' => 1, 'status' => 'active', 'title' => 'Test');
		$submission = (object) array('id' => 10, 'status' => 'approved', 'user_id' => 1);
		$wpdb->shouldReceive('get_row')
			->andReturn($rereg, $submission);

		$ex = null;
		try {
			ReregistrationFrontend::ajax_submit();
		} catch (FrontendJsonErrorException $e) {
			$ex = $e;
		}

		$this->assertNotNull($ex);
		$this->assertStringContainsString('already been completed or expired', $ex->payload['message']);
	}

	// ==================================================================
	// ajax_save_draft() — early-exit guards
	// ==================================================================

	public function test_ajax_save_draft_errors_when_no_reregistration_id(): void {
		Functions\when('get_current_user_id')->justReturn(1);

		$_POST['reregistration_id'] = 0;

		$ex = null;
		try {
			ReregistrationFrontend::ajax_save_draft();
		} catch (FrontendJsonErrorException $e) {
			$ex = $e;
		}

		$this->assertNotNull($ex);
		$this->assertStringContainsString('Invalid request', $ex->payload['message']);
	}

	public function test_ajax_save_draft_errors_when_rereg_not_active(): void {
		Functions\when('get_current_user_id')->justReturn(1);

		$_POST['reregistration_id'] = 1;

		global $wpdb;
		$rereg = (object) array('id' => 1, 'status' => 'closed', 'title' => 'Test');
		$wpdb->shouldReceive('get_row')->andReturn($rereg);

		$ex = null;
		try {
			ReregistrationFrontend::ajax_save_draft();
		} catch (FrontendJsonErrorException $e) {
			$ex = $e;
		}

		$this->assertNotNull($ex);
		$this->assertStringContainsString('not active', $ex->payload['message']);
	}

	public function test_ajax_save_draft_errors_when_submission_approved(): void {
		Functions\when('get_current_user_id')->justReturn(1);

		$_POST['reregistration_id'] = 1;

		global $wpdb;
		$rereg = (object) array('id' => 1, 'status' => 'active', 'title' => 'Test');
		$submission = (object) array('id' => 10, 'status' => 'approved', 'user_id' => 1);
		$wpdb->shouldReceive('get_row')
			->andReturn($rereg, $submission);

		$ex = null;
		try {
			ReregistrationFrontend::ajax_save_draft();
		} catch (FrontendJsonErrorException $e) {
			$ex = $e;
		}

		$this->assertNotNull($ex);
		$this->assertStringContainsString('Cannot save draft', $ex->payload['message']);
	}

	// ==================================================================
	// get_user_reregistrations() — returns structured array
	// ==================================================================

	public function test_get_user_reregistrations_returns_empty_when_no_active(): void {
		global $wpdb;
		$wpdb->shouldReceive('get_results')->andReturn(array());

		$result = ReregistrationFrontend::get_user_reregistrations(1);

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function test_get_user_reregistrations_maps_can_submit_for_pending(): void {
		global $wpdb;

		$rereg = (object) array(
			'id'            => 5,
			'title'         => 'Campaign 2026',
			'audience_name' => 'Teachers',
			'start_date'    => '2026-01-01',
			'end_date'      => '2026-06-30',
			'auto_approve'  => 0,
		);

		// First get_results: get_active_for_user -> campaigns
		$wpdb->shouldReceive('get_results')->andReturn(array($rereg));

		$submission = (object) array('id' => 20, 'status' => 'pending', 'user_id' => 1, 'magic_token' => '');
		$wpdb->shouldReceive('get_row')
			->andReturn($submission);

		$result = ReregistrationFrontend::get_user_reregistrations(1);

		$this->assertCount(1, $result);
		$this->assertSame(5, $result[0]['id']);
		$this->assertSame('pending', $result[0]['submission_status']);
		$this->assertTrue($result[0]['can_submit']);
		$this->assertSame('', $result[0]['magic_link']);
	}

	// ==================================================================
	// can_submit across EVERY status (#1125)
	// ==================================================================
	//
	// The survey that was missing. Before #1125 the set was
	// pending/in_progress/rejected, and the dashboard rendered a banner only
	// for approved/submitted — so `expired` and `no_submission` fell through
	// both, producing a blank screen. The gap was only visible by listing all
	// seven states at once, which is what this provider does.

	/**
	 * @dataProvider provider_can_submit_by_status
	 * @param string $status     Submission status, or '' for no submission row.
	 * @param bool   $can_submit Whether the user may fill the form.
	 */
	public function test_can_submit_is_defined_for_every_status(string $status, bool $can_submit): void {
		global $wpdb;

		$rereg = (object) array(
			'id'            => 5,
			'title'         => 'Campaign 2026',
			'audience_name' => 'Teachers',
			'start_date'    => '2026-01-01',
			'end_date'      => '2026-06-30',
			'auto_approve'  => 0,
		);
		$wpdb->shouldReceive('get_results')->andReturn(array($rereg));

		$submission = '' === $status
			? null
			: (object) array('id' => 20, 'status' => $status, 'user_id' => 1, 'magic_token' => 'tok');

		/*
		 * Two different `get_row` calls happen, in this order, and conflating
		 * them is what made the null case look like "no campaign" instead of
		 * "no submission": ReregistrationRepository::get_active_for_audience()
		 * resolves the audience through AudienceReader::get_by_id() FIRST, and
		 * returns early when that is null — so a blanket null never reached the
		 * submission lookup at all.
		 */
		$audience = (object) array('id' => 5, 'name' => 'Teachers', 'parent_id' => 0);
		$wpdb->shouldReceive('get_row')->andReturnValues(array($audience, $submission));

		// submitted/approved build a magic link on the way out; the link is not
		// what this test measures, so the page lookup is stubbed flat.
		Functions\when('get_option')->justReturn(0);
		Functions\when('home_url')->justReturn('https://example.test/valid/');
		Functions\when('trailingslashit')->alias(static fn($u) => rtrim((string) $u, '/') . '/');

		$result = ReregistrationFrontend::get_user_reregistrations(1);

		$expected_status = '' === $status ? 'no_submission' : $status;
		$this->assertSame($expected_status, $result[0]['submission_status']);
		$this->assertSame($can_submit, $result[0]['can_submit'], "can_submit wrong for {$expected_status}");
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function provider_can_submit_by_status(): array {
		return array(
			// The state before the user begins. It reaches the dashboard two
			// ways — joined the audience after activation, or an administrator
			// deleted the submission — and both must be able to submit.
			'no submission row' => array('', true),
			'pending'           => array('pending', true),
			'in_progress'       => array('in_progress', true),
			'rejected'          => array('rejected', true),
			// Records of a delivery: not reopened by anything short of the
			// administrator deleting the row.
			'submitted'         => array('submitted', false),
			'approved'          => array('approved', false),
			// Handled at the source — reactivation reopens it to pending — so
			// a user should not sit here. Pinned so that if one ever does, the
			// dashboard's catch-all branch is what they see, not a blank.
			'expired'           => array('expired', false),
		);
	}

	/**
	 * The set is a contract, not an implementation detail: the dashboard and
	 * the REST endpoints all branch on it.
	 */
	public function test_no_submission_is_in_the_submittable_set(): void {
		$this->assertContains(
			ReregistrationFrontend::STATUS_NO_SUBMISSION,
			ReregistrationFrontend::SUBMITTABLE_STATUSES,
			'A user with no row has not left a state — they have not started one.'
		);
	}

	public function test_a_delivered_submission_is_never_submittable(): void {
		foreach (array('submitted', 'approved') as $status) {
			$this->assertNotContains(
				$status,
				ReregistrationFrontend::SUBMITTABLE_STATUSES,
				"Reopening {$status} would discard a delivery."
			);
		}
	}
}
