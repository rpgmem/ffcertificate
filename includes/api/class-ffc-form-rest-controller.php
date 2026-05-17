<?php
/**
 * Form REST Controller
 *
 * Handles form-related REST API endpoints:
 *   GET  /forms             – List published forms
 *   GET  /forms/{id}        – Get single form (full payload incl. background)
 *   GET  /forms/{id}/schema – Lightweight read-only form metadata for integrations
 *   POST /forms/{id}/submit – Submit a form
 *
 * @package FreeFormCertificate\API
 * @since 4.6.1
 */

declare(strict_types=1);

namespace FreeFormCertificate\API;

use FreeFormCertificate\Repositories\FormRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for form endpoints.
 */
class FormRestController {

	/**
	 * Maximum value accepted for the `per_page` query arg on
	 * `GET /forms`. Larger requests are silently clamped to this
	 * ceiling. Tuned to be large enough that no realistic site is
	 * artificially constrained, small enough that a misuse cannot
	 * dump the entire form catalogue in one round trip.
	 */
	private const MAX_PER_PAGE = 100;

	/**
	 * Default value used when the `per_page` query arg is omitted on
	 * `GET /forms`. Matches the WP REST core convention (the
	 * `/wp/v2/posts` endpoint uses 10).
	 */
	private const DEFAULT_PER_PAGE = 10;

	/**
	 * API namespace
	 *
	 * @var string
	 */
	private string $namespace;

	/**
	 * Form repository
	 *
	 * @var FormRepository|null
	 */
	private ?FormRepository $form_repository;

	/**
	 * Constructor
	 *
	 * @param string              $namespace       API namespace.
	 * @param FormRepository|null $form_repository Form repository instance.
	 */
	public function __construct( string $namespace, ?FormRepository $form_repository ) {
		$this->namespace       = $namespace;
		$this->form_repository = $form_repository;
	}

	/**
	 * Register routes
	 */
	public function register_routes(): void {
		// GET /forms - List all published forms.
		//
		// Authenticated. External integrators use a WordPress
		// Application Password (Basic Auth, since WP 5.6) to call
		// this endpoint; the linked user must hold `ffc_read_forms_api`
		// (granted to the administrator role automatically; delegable
		// to other roles via the standard WP cap UI). The previous
		// `__return_true` permission_callback exposed the
		// `_ffc_form_config` blob which contains allowed/denied user
		// lists, generated/validation codes, and geofence config —
		// see issue #139 for the audit finding.
		register_rest_route(
			$this->namespace,
			'/forms',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_forms' ),
				'permission_callback' => array( $this, 'permission_read_forms_api' ),
				'args'                => array(
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => array( $this, 'sanitize_page' ),
					),
					'per_page' => array(
						'default'           => self::DEFAULT_PER_PAGE,
						'sanitize_callback' => array( $this, 'sanitize_per_page' ),
					),
				),
			)
		);

		// GET /forms/{id} - Get single form. Same auth model as /forms above.
		register_rest_route(
			$this->namespace,
			'/forms/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_form' ),
				'permission_callback' => array( $this, 'permission_read_forms_api' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
				),
			)
		);

		// GET /forms/{id}/schema - Lightweight read-only form metadata.
		//
		// Public-by-design — the lightweight counterpart to GET /forms/{id}.
		// Returns only id + title + the fields renderers need (name, label,
		// type, required, options) and is the documented entry point for
		// integrators that want to build a custom form UI without holding
		// `ffc_read_forms_api`. The trim is enforced inside the handler
		// (see get_form_schema()) and is filterable via
		// `ffcertificate_rest_form_schema` so projects can add or remove
		// keys without having to fork the controller. See issue #139.
		register_rest_route(
			$this->namespace,
			'/forms/(?P<id>\d+)/schema',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_form_schema' ),
				// phpcs:ignore -- public-by-design: serves the curated schema; see route docblock above and #139.
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
				),
			)
		);

		// POST /forms/{id}/submit - Submit a form.
		//
		// Public-by-design — anonymous visitors submit certificate forms
		// here (the plugin's primary public flow). Locking this behind a
		// permission gate would break every "fill in the form to receive
		// your certificate" deployment. The handler defends with: form
		// publish-state check, geofence (date/time + IP geolocation), CPF/RF
		// algorithm validation, email format validation, and three rate-
		// limit pools (IP, email, CPF) — see submit_form() body. CSRF on
		// REST endpoints is handled by WordPress's nonce/header model;
		// origin validation is enforced by the submit form_processor used
		// by the same flow. See issue #139.
		register_rest_route(
			$this->namespace,
			'/forms/(?P<id>\d+)/submit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit_form' ),
				// phpcs:ignore -- public-by-design: anonymous submission flow; see route docblock above and #139.
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
				),
			)
		);
	}

	/**
	 * Permission callback for the authenticated `/forms` endpoints.
	 *
	 * @since 6.4.1
	 * @return bool
	 */
	public function permission_read_forms_api(): bool {
		return current_user_can( 'ffc_read_forms_api' );
	}

	/**
	 * Sanitize the `page` query arg on `GET /forms`.
	 *
	 * Returns 1 for non-positive or non-numeric input; otherwise
	 * the absint value (no upper bound — overflowing pages return
	 * an empty array with correct pagination headers).
	 *
	 * @since 6.6.1
	 * @param mixed $value Raw query arg.
	 * @return int Page number, >= 1.
	 */
	public function sanitize_page( $value ): int {
		$n = absint( $value );
		return $n > 0 ? $n : 1;
	}

	/**
	 * Sanitize and clamp the `per_page` query arg on `GET /forms`.
	 *
	 * Returns DEFAULT_PER_PAGE for non-positive or non-numeric input;
	 * clamps positive values to MAX_PER_PAGE.
	 *
	 * @since 6.6.1
	 * @param mixed $value Raw query arg.
	 * @return int Page size, 1..MAX_PER_PAGE.
	 */
	public function sanitize_per_page( $value ): int {
		$n = absint( $value );
		if ( $n <= 0 ) {
			return self::DEFAULT_PER_PAGE;
		}
		return min( $n, self::MAX_PER_PAGE );
	}

	/**
	 * GET /forms
	 *
	 * List published forms with WP REST-style pagination. Accepts
	 * `page` (1-indexed, default 1) and `per_page` (default 10,
	 * clamped to 1..MAX_PER_PAGE). Sets `X-WP-Total`,
	 * `X-WP-TotalPages`, and `Link` headers (rels: first/prev/next/last).
	 *
	 * Out-of-range pages return an empty array with the pagination
	 * headers populated — not a 404 — so external integrators can
	 * detect the terminal condition without parsing the body shape.
	 *
	 * @since 6.4.1 introduced as a single-page endpoint clamped at 100.
	 * @since 6.6.1 real pagination via `page` + `per_page`; the legacy
	 *              `limit` arg is removed.
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_forms( $request ) {
		try {
			$page     = (int) $request->get_param( 'page' );
			$per_page = (int) $request->get_param( 'per_page' );

			if ( ! $this->form_repository ) {
				return new \WP_Error(
					'repository_not_found',
					__( 'Form repository not available', 'ffcertificate' ),
					array( 'status' => 500 )
				);
			}

			$offset      = ( $page - 1 ) * $per_page;
			$total       = $this->form_repository->countPublished();
			$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

			// Out-of-range pages return an empty array with the
			// pagination headers populated so callers can detect the
			// terminal condition without parsing the body shape.
			$forms = ( $page > $total_pages && $total > 0 )
				? array()
				: $this->form_repository->findPublished( $per_page, $offset );

			// Trimmed payload: id/title/status/dates/link only. The
			// previous response embedded the full `_ffc_form_config`
			// blob (allowed/denied user lists, validation/generated
			// codes, geofence + IP areas, email body, etc.) — see
			// issue #139. Integrators that need form structure should
			// hit `/forms/{id}/schema` (lightweight, public-by-design).
			$response = array();
			foreach ( $forms as $form ) {
				$response[] = array(
					'id'       => $form->ID,
					'title'    => $form->post_title,
					'status'   => $form->post_status,
					'date'     => $form->post_date,
					'modified' => $form->post_modified,
					'link'     => get_permalink( $form->ID ),
				);
			}

			$rest_response = rest_ensure_response( $response );
			$rest_response->header( 'X-WP-Total', (string) $total );
			$rest_response->header( 'X-WP-TotalPages', (string) $total_pages );

			$link_header = $this->build_pagination_link_header( $request, $page, $total_pages );
			if ( '' !== $link_header ) {
				$rest_response->header( 'Link', $link_header );
			}

			return $rest_response;

		} catch ( \Exception $e ) {
			$this->log_rest_error( 'get_forms', $e );
			return new \WP_Error(
				'ffc_internal_error',
				__( 'An unexpected error occurred.', 'ffcertificate' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Build a `Link` header matching the WP REST convention for the
	 * paginated `GET /forms` response. Emits up to four rel values
	 * (`first`, `prev`, `next`, `last`) — only the ones that apply
	 * to the current position.
	 *
	 * @param \WP_REST_Request $request     Active request (for `per_page` mirroring).
	 * @param int              $page        1-indexed current page.
	 * @param int              $total_pages Total page count for the result set.
	 * @return string Comma-separated `Link` header value, or '' when not paginated.
	 */
	private function build_pagination_link_header( $request, int $page, int $total_pages ): string {
		if ( $total_pages <= 0 ) {
			return '';
		}

		$base = rest_url( $this->namespace . '/forms' );
		$args = array(
			'per_page' => (int) $request->get_param( 'per_page' ),
		);

		$build = function ( int $target_page ) use ( $base, $args ) {
			$args['page'] = $target_page;
			return add_query_arg( $args, $base );
		};

		$links = array();
		if ( $page > 1 ) {
			$links[] = '<' . esc_url_raw( $build( 1 ) ) . '>; rel="first"';
			$links[] = '<' . esc_url_raw( $build( max( 1, $page - 1 ) ) ) . '>; rel="prev"';
		}
		if ( $page < $total_pages ) {
			$links[] = '<' . esc_url_raw( $build( $page + 1 ) ) . '>; rel="next"';
			$links[] = '<' . esc_url_raw( $build( $total_pages ) ) . '>; rel="last"';
		}

		return implode( ', ', $links );
	}

	/**
	 * GET /forms/{id}
	 * Get single form details
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_form( $request ) {
		try {
			$form_id = $request->get_param( 'id' );

			$form = get_post( $form_id );

			if ( ! $form || 'ffc_form' !== $form->post_type ) {
				return new \WP_Error(
					'form_not_found',
					__( 'Form not found', 'ffcertificate' ),
					array( 'status' => 404 )
				);
			}

			if ( 'publish' !== $form->post_status ) {
				return new \WP_Error(
					'form_not_published',
					__( 'Form is not published', 'ffcertificate' ),
					array( 'status' => 403 )
				);
			}

			// Trimmed payload, same rationale as `GET /forms`: drop
			// the `_ffc_form_config` blob, fields, and background that
			// the previous unauthenticated response leaked. Integrators
			// that need form structure use `GET /forms/{id}/schema`
			// (public-by-design, returns id/title/fields with only the
			// keys a renderer needs). See issue #139.
			$response = array(
				'id'       => $form->ID,
				'title'    => $form->post_title,
				'status'   => $form->post_status,
				'date'     => $form->post_date,
				'modified' => $form->post_modified,
				'link'     => get_permalink( $form->ID ),
			);

			return rest_ensure_response( $response );

		} catch ( \Exception $e ) {
			$this->log_rest_error( 'get_form', $e );
			return new \WP_Error(
				'ffc_internal_error',
				__( 'An unexpected error occurred.', 'ffcertificate' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * GET /forms/{id}/schema
	 *
	 * Returns a lightweight, read-only metadata payload describing the
	 * form — id, title, and the trimmed list of fields with only the
	 * keys that client integrations actually need to render a form
	 * (name, label, type, required, options).
	 *
	 * This is cheaper than /forms/{id}: it does not include the
	 * background image, full config blob, or post dates. Integrators
	 * can filter the payload via `ffcertificate_rest_form_schema`.
	 *
	 * @since 5.4.0
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_form_schema( $request ) {
		try {
			$form_id = (int) $request->get_param( 'id' );

			$form = get_post( $form_id );

			if ( ! $form || 'ffc_form' !== $form->post_type ) {
				return new \WP_Error(
					'form_not_found',
					__( 'Form not found', 'ffcertificate' ),
					array( 'status' => 404 )
				);
			}

			if ( 'publish' !== $form->post_status ) {
				return new \WP_Error(
					'form_not_published',
					__( 'Form is not published', 'ffcertificate' ),
					array( 'status' => 403 )
				);
			}

			if ( ! $this->form_repository ) {
				return new \WP_Error(
					'repository_not_found',
					__( 'Form repository not available', 'ffcertificate' ),
					array( 'status' => 500 )
				);
			}

			$raw_fields = $this->form_repository->getFields( $form_id );
			if ( ! is_array( $raw_fields ) ) {
				$raw_fields = array();
			}

			$fields = array();
			foreach ( $raw_fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$fields[] = array(
					'name'     => isset( $field['name'] ) ? (string) $field['name'] : '',
					'label'    => isset( $field['label'] ) ? (string) $field['label'] : '',
					'type'     => isset( $field['type'] ) ? (string) $field['type'] : 'text',
					'required' => ! empty( $field['required'] ),
					'options'  => ( isset( $field['options'] ) && is_array( $field['options'] ) ) ? array_values( $field['options'] ) : array(),
				);
			}

			$schema = array(
				'id'     => $form->ID,
				'title'  => $form->post_title,
				'fields' => $fields,
			);

			/**
			 * Filters the lightweight form schema returned by the REST
			 * metadata endpoint. Integrators can add/remove keys or inject
			 * custom computed values.
			 *
			 * @since 5.4.0
			 *
			 * @param array<string, mixed> $schema  Default schema (id, title, fields).
			 * @param int                  $form_id Form post ID.
			 * @param \WP_Post             $form    Form post object.
			 */
			$schema = (array) apply_filters( 'ffcertificate_rest_form_schema', $schema, $form_id, $form );

			return rest_ensure_response( $schema );

		} catch ( \Exception $e ) {
			$this->log_rest_error( 'get_form_schema', $e );
			return new \WP_Error(
				'ffc_internal_error',
				__( 'An unexpected error occurred.', 'ffcertificate' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * POST /forms/{id}/submit
	 * Submit a form via API
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function submit_form( $request ) {
		try {
			$form_id = $request->get_param( 'id' );
			$params  = $request->get_json_params();

			if ( empty( $params ) ) {
				return new \WP_Error(
					'no_data',
					'No data provided in request body',
					array( 'status' => 400 )
				);
			}

			// Verify form exists and is published.
			$form = get_post( $form_id );

			if ( ! $form || 'ffc_form' !== $form->post_type ) {
				return new \WP_Error(
					'form_not_found',
					'Form not found',
					array( 'status' => 404 )
				);
			}

			if ( 'publish' !== $form->post_status ) {
				return new \WP_Error(
					'form_not_published',
					'Form is not published',
					array( 'status' => 403 )
				);
			}

			if ( ! $this->form_repository ) {
				return new \WP_Error( 'form_repo_unavailable', 'Form repository not available', array( 'status' => 500 ) );
			}

			// Get form configuration and fields.
			$form_config = $this->form_repository->getConfig( $form_id );
			$form_fields = $this->form_repository->getFields( $form_id );

			// Sanitize submission data.
			$submission_data = \FreeFormCertificate\Core\DataSanitizer::recursive_sanitize( $params );

			// Validate required fields.
			$validation_errors = $this->validate_required_fields( $submission_data, $form_fields );
			if ( ! empty( $validation_errors ) ) {
				return new \WP_Error(
					'validation_failed',
					'Validation failed: ' . implode( ', ', $validation_errors ),
					array(
						'status' => 400,
						'errors' => $validation_errors,
					)
				);
			}

			// Validate CPF if present.
			if ( ! empty( $submission_data['cpf_rf'] ) ) {
				$cpf = preg_replace( '/[^0-9]/', '', $submission_data['cpf_rf'] );

				if ( strlen( $cpf ) === 11 ) {
					if ( ! \FreeFormCertificate\Core\DocumentFormatter::validate_cpf( $cpf ) ) {
						return new \WP_Error(
							'invalid_cpf',
							'Invalid CPF. Please check the number and try again.',
							array( 'status' => 400 )
						);
					}
				} elseif ( strlen( $cpf ) === 7 ) {
					if ( ! \FreeFormCertificate\Core\DocumentFormatter::validate_rf( $cpf ) ) {
						return new \WP_Error(
							'invalid_rf',
							'Invalid RF. Must contain only numbers.',
							array( 'status' => 400 )
						);
					}
				} else {
					return new \WP_Error(
						'invalid_cpf_rf',
						'CPF/RF must be exactly 7 or 11 digits',
						array( 'status' => 400 )
					);
				}

				$submission_data['cpf_rf'] = $cpf;
			}

			// Validate email if present.
			if ( ! empty( $submission_data['email'] ) ) {
				if ( ! is_email( $submission_data['email'] ) ) {
					return new \WP_Error(
						'invalid_email',
						'Invalid email address',
						array( 'status' => 400 )
					);
				}
			}

			// Geofence validation (date/time + IP geolocation).
			if ( class_exists( '\FreeFormCertificate\Security\Geofence' ) ) {
				$geofence_config    = \FreeFormCertificate\Security\Geofence::get_form_config( $form_id );
				$should_validate_ip = false;

				if ( $geofence_config && ! empty( $geofence_config['geo_enabled'] ) && ! empty( $geofence_config['geo_ip_enabled'] ) ) {
					$should_validate_ip = true;
				}

				$geofence_check = \FreeFormCertificate\Security\Geofence::can_access_form(
					$form_id,
					array(
						'check_datetime' => true,
						'check_geo'      => $should_validate_ip,
					)
				);

				if ( ! $geofence_check['allowed'] ) {
					return new \WP_Error(
						'geofence_blocked',
						$geofence_check['message'] ?? '',
						array(
							'status' => 403,
							'reason' => $geofence_check['reason'] ?? '',
						)
					);
				}
			}

			// Rate limiting check.
			if ( class_exists( '\FreeFormCertificate\Security\RateLimiter' ) ) {
				$ip       = \FreeFormCertificate\Core\Utils::get_user_ip();
				$ip_check = \FreeFormCertificate\Security\RateLimiter::check_ip_limit( $ip );
				if ( ! $ip_check['allowed'] ) {
					return new \WP_Error(
						'rate_limit_exceeded',
						'Too many requests. Please try again later.',
						array( 'status' => 429 )
					);
				}

				if ( ! empty( $submission_data['email'] ) ) {
					$email_check = \FreeFormCertificate\Security\RateLimiter::check_email_limit( $submission_data['email'] );
					if ( ! $email_check['allowed'] ) {
						return new \WP_Error(
							'rate_limit_exceeded',
							'Too many submissions from this email. Please try again later.',
							array( 'status' => 429 )
						);
					}
				}

				if ( ! empty( $submission_data['cpf_rf'] ) ) {
					$cpf_check = \FreeFormCertificate\Security\RateLimiter::check_cpf_limit( $submission_data['cpf_rf'] );
					if ( ! $cpf_check['allowed'] ) {
						return new \WP_Error(
							'rate_limit_exceeded',
							'Too many submissions with this CPF/RF. Please try again later.',
							array( 'status' => 429 )
						);
					}
				}
			}

			// Use SubmissionHandler to process submission.
			if ( ! class_exists( '\FreeFormCertificate\Submissions\SubmissionHandler' ) ) {
				return new \WP_Error(
					'handler_not_found',
					'Submission handler not available',
					array( 'status' => 500 )
				);
			}

			$handler    = new \FreeFormCertificate\Submissions\SubmissionHandler();
			$user_email = isset( $submission_data['email'] ) ? sanitize_email( $submission_data['email'] ) : '';
			$result     = $handler->process_submission( $form_id, $form->post_title, $submission_data, $user_email, $form_fields, $form_config );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$submission_id = (int) $result;
			$auth_code     = isset( $submission_data['auth_code'] ) ? $submission_data['auth_code'] : '';

			$response = array(
				'success'       => true,
				'submission_id' => $submission_id,
				'auth_code'     => \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $auth_code, \FreeFormCertificate\Core\DocumentFormatter::PREFIX_CERTIFICATE ),
				'message'       => __( 'Form submitted successfully', 'ffcertificate' ),
			);

			$response['validation_url'] = home_url( '/validate-certificate/' );

			return rest_ensure_response( $response );

		} catch ( \Exception $e ) {
			$this->log_rest_error( 'submit_form', $e );
			return new \WP_Error(
				'ffc_internal_error',
				__( 'An unexpected error occurred.', 'ffcertificate' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Validate required fields
	 *
	 * @param array<string, mixed>             $data Submission data.
	 * @param array<int, array<string, mixed>> $fields Form fields configuration.
	 * @return array<int, string> Array of validation errors
	 */
	private function validate_required_fields( array $data, array $fields ): array {
		$errors = array();

		if ( empty( $fields ) ) {
			return $errors;
		}

		foreach ( $fields as $field ) {
			if ( isset( $field['required'] ) && $field['required'] ) {
				$field_name = isset( $field['name'] ) ? $field['name'] : '';

				if ( empty( $field_name ) || ! isset( $data[ $field_name ] ) || trim( $data[ $field_name ] ) === '' ) {
					$field_label = isset( $field['label'] ) ? $field['label'] : $field_name;
					$errors[]    = $field_label . ' is required';
				}
			}
		}

		return $errors;
	}

	/**
	 * Log REST API error without exposing details to clients.
	 *
	 * @since 4.6.6
	 * @param string     $context Action that caused the error.
	 * @param \Exception $e       The exception.
	 */
	private function log_rest_error( string $context, \Exception $e ): void {
		if ( class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
			\FreeFormCertificate\Core\Debug::log_rest_api(
				"REST API error: {$context}",
				array(
					'message' => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				)
			);
		}
	}
}
