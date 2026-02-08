<?php
declare(strict_types=1);

/**
 * RestController (Coordinator)
 *
 * Thin coordinator that initialises all REST sub-controllers:
 *
 *   FormRestController       – /forms, /forms/{id}, /forms/{id}/submit
 *   SubmissionRestController – /submissions, /submissions/{id}, /verify
 *   UserDataRestController   – /user/certificates, /user/profile, /user/appointments, /user/audience-bookings
 *   CalendarRestController   – /calendars, /calendars/{id}, /calendars/{id}/slots
 *   AppointmentRestController – /calendars/{id}/appointments, /appointments/{id}
 *
 * Namespace: /wp-json/ffc/v1/
 *
 * @since 3.0.0
 * @version 3.3.0 - Added strict types and type hints
 * @version 4.6.1 - Refactored into coordinator + 5 sub-controllers
 * @package FreeFormCertificate\API
 */

namespace FreeFormCertificate\API;

use FreeFormCertificate\Repositories\FormRepository;
use FreeFormCertificate\Repositories\SubmissionRepository;

if (!defined('ABSPATH')) exit;

class RestController {

    /**
     * API namespace
     */
    private string $namespace = 'ffc/v1';

    /**
     * Repositories
     */
    private ?FormRepository $form_repository = null;
    private ?SubmissionRepository $submission_repository = null;

    /**
     * Constructor
     */
    public function __construct() {
        // Initialize repositories
        $this->form_repository = new FormRepository();
        $this->submission_repository = new SubmissionRepository();

        // Register REST routes
        add_action('rest_api_init', array($this, 'register_routes'));

        // Suppress PHP notices/warnings in REST API responses to prevent JSON corruption
        add_action('rest_api_init', array($this, 'suppress_rest_api_notices'));
    }

    /**
     * Buffer REST API output to prevent stray PHP notices from corrupting JSON.
     *
     * Uses output buffering only — warnings are still logged via WP_DEBUG_LOG
     * but never leak into REST responses.
     */
    public function suppress_rest_api_notices(): void {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            if (!ob_get_level()) {
                ob_start();
            }

            add_filter('rest_pre_serve_request', function($served, $result, $request, $server) {
                if (ob_get_level()) {
                    ob_clean();
                }
                return $served;
            }, 10, 4);
        }
    }

    /**
     * Register all REST routes via sub-controllers
     */
    public function register_routes(): void {
        $form_controller = new FormRestController($this->namespace, $this->form_repository);
        $form_controller->register_routes();

        $submission_controller = new SubmissionRestController($this->namespace, $this->submission_repository);
        $submission_controller->register_routes();

        $user_data_controller = new UserDataRestController($this->namespace);
        $user_data_controller->register_routes();

        $calendar_controller = new CalendarRestController($this->namespace);
        $calendar_controller->register_routes();

        $appointment_controller = new AppointmentRestController($this->namespace);
        $appointment_controller->register_routes();
    }
}
