<?php
/**
 * Self-Scheduling module loader.
 *
 * Single bootstrap entry point for the Self-Scheduling module — wires the
 * module's ~10 runtime classes (admin screens, CPT, appointment handler + its
 * AJAX / e-mail / receipt / cancellation collaborators, and the shortcode)
 * behind one call so the orchestrator (`Loader`) touches a single symbol
 * instead of newing them up inline. Mirrors AdminLoader / AudienceLoader /
 * RecruitmentLoader (#563 B3 coupling reduction).
 *
 * Note: `SelfSchedulingActivator::maybe_migrate()` stays in the orchestrator —
 * it is upgrade-safety/migration, not module bootstrap.
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since   6.12.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstraps the Self-Scheduling module.
 */
class SelfSchedulingLoader {

	/**
	 * Admin screens controller — held to keep the instance alive for its hooks.
	 *
	 * @var SelfSchedulingAdmin|null
	 */
	protected ?SelfSchedulingAdmin $admin = null;

	/**
	 * Admin editor — held to keep the instance alive for its hooks.
	 *
	 * @var SelfSchedulingEditor|null
	 */
	protected ?SelfSchedulingEditor $editor = null;

	/**
	 * Appointment CPT — held to keep the instance alive for its hooks.
	 *
	 * @var SelfSchedulingCPT|null
	 */
	protected ?SelfSchedulingCPT $cpt = null;

	/**
	 * Appointment handler — held; also passed to the AJAX + cancellation handlers.
	 *
	 * @var AppointmentHandler|null
	 */
	protected ?AppointmentHandler $appointment_handler = null;

	/**
	 * Appointment e-mail handler — held to keep the instance alive for its hooks.
	 *
	 * @var AppointmentEmailHandler|null
	 */
	protected ?AppointmentEmailHandler $email_handler = null;

	/**
	 * Appointment receipt handler — held to keep the instance alive for its hooks.
	 *
	 * @var AppointmentReceiptHandler|null
	 */
	protected ?AppointmentReceiptHandler $receipt_handler = null;

	/**
	 * Public scheduling shortcode — held to keep the instance alive for its hooks.
	 *
	 * @var SelfSchedulingShortcode|null
	 */
	protected ?SelfSchedulingShortcode $shortcode = null;

	/**
	 * Wire the module's admin + frontend surface.
	 *
	 * Order matches the previous inline sequence in `Loader::init_plugin()`:
	 * admin (when `is_admin()`) → CPT → appointment handler + AJAX → e-mail →
	 * receipt → public cancellation page → shortcode.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( is_admin() ) {
			$this->admin  = new SelfSchedulingAdmin();
			$this->editor = new SelfSchedulingEditor();

			// Appointment CSV export — register the batched source with the
			// shared registry (#772); the unified dispatcher (wired in Loader)
			// routes `type=appointments` start/batch/download requests to it.
			// Runs under is_admin(), true on admin-ajax, so it is reachable
			// during the export job.
			\FreeFormCertificate\Core\SourceRegistry::register(
				AppointmentExportSource::TYPE,
				static function (): AppointmentExportSource {
					return new AppointmentExportSource(
						new \FreeFormCertificate\Repositories\AppointmentRepository(),
						new \FreeFormCertificate\Repositories\CalendarRepository()
					);
				}
			);
		}

		$this->cpt                 = new SelfSchedulingCPT();
		$this->appointment_handler = new AppointmentHandler();
		new AppointmentAjaxHandler( $this->appointment_handler );
		$this->email_handler   = new AppointmentEmailHandler();
		$this->receipt_handler = new AppointmentReceiptHandler();
		// #Item9 — public token-based cancellation page reached from the
		// appointment e-mails; delegates the actual cancel to the handler.
		new AppointmentCancellationHandler( $this->appointment_handler );
		$this->shortcode = new SelfSchedulingShortcode();
	}
}
