<?php
/**
 * Wipe the plugin's data on the testes site and fill every module with
 * fictitious records (#1855).
 *
 * Developer tooling, never shipped: `.github/` is in `.distignore` and the
 * deploy's rsync excludes it, so on the testes host the script is copied next
 * to `ffc-uninstall-manifest.php` (the same manifest the fresh-install job and
 * the post-deploy smoke read) and run from there.
 *
 * Usage, either way:
 *
 *   php testes-seed.php /path/to/wp-load.php --yes-wipe --site=https://testes.example.org
 *   wp eval-file testes-seed.php yes-wipe site=https://testes.example.org
 *
 * WP-CLI refuses an unknown `--flag` (even after `--`), so under `wp eval-file`
 * the two arguments are passed without their dashes; either spelling works in
 * either mode.
 *
 * THE GUARD. Nothing is read or written unless BOTH hold: `--yes-wipe` is
 * passed, and `--site` equals `home_url()` (a trailing slash is ignored). The
 * testes and production sites share one hosting account, so the URL is the
 * only thing that tells them apart; a refusal prints why and exits 1.
 *
 * THE WIPE. Every `ffc_*` table `uninstall.php` declares is emptied, with
 * foreign-key checks off for the duration (`MigrationForeignKeys` adds FKs to
 * `wp_users`). `ffc_user_profiles` is the one exception: only the rows of the
 * demo accounts and of deleted accounts go, so a real user's profile stays.
 * Then the plugin's post types (forms, personal calendars, certificate
 * templates), every user whose e-mail ends in `@example.test`, the `ffc`
 * transients, and one option that is pure data about the rows just removed:
 * `ffc_identity_accepted`, the identity-audit acceptances, which name users
 * and identifiers that no longer exist. Real users and every setting stay.
 *
 * THE SEED. At least 20 rows on every module's main screen, written through the
 * plugin's own repositories and services so CPF, RF and e-mail come out
 * encrypted and hashed with THIS site's key, exactly as a real write would.
 * Every person, e-mail (`@example.test`, a reserved domain) and document is
 * invented, and every FFC role gets one demo account, `<role>@example.test`,
 * so each tier can be signed into and looked at (#1860); CPFs and RFs are generated to pass their check digits. Outgoing
 * e-mail is blocked while the script runs, the activity log is switched on in
 * memory only (the stored setting is untouched), and every wp-cron event the
 * seed scheduled is removed at the end, so nothing is sent later either.
 *
 * Running it twice leaves the same counts: the wipe resets every table's
 * auto-increment, and randomness is seeded with `mt_srand()`.
 *
 * Not seeded, on purpose: the rate-limit and device-signal tables (written by
 * live traffic, no screen lists them as records) and the two CSV-import
 * staging pairs (a job's scratch space, emptied when the job finishes).
 *
 * @package FreeFormCertificate\CI
 */

// phpcs:ignoreFile -- Developer tooling run from the CLI against the testes site, never shipped.

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	// `wp eval-file` runs this inside a method, with the arguments in `$args`.
	$ffc_tseed_args = isset( $args ) && is_array( $args ) ? $args : array();
} else {
	if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) ) {
		fwrite( STDERR, "Usage: php testes-seed.php /path/to/wp-load.php --yes-wipe --site=<home_url>\n       wp eval-file testes-seed.php yes-wipe site=<home_url>\n" );
		exit( 1 );
	}
	$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
	require $argv[1];
	$ffc_tseed_args = array_slice( $argv, 2 );
}

exit( ffc_tseed_main( $ffc_tseed_args, __DIR__ ) );

/**
 * Entry point.
 *
 * @param array<int, string> $cli_args   Arguments after the script (or wp-load.php).
 * @param string             $script_dir Directory this script lives in.
 * @return int Exit code.
 */
function ffc_tseed_main( array $cli_args, string $script_dir ): int {
	// WP-CLI rejects an unknown `--flag` even after `--`, so under `wp
	// eval-file` the two arguments travel without their dashes; both
	// spellings are accepted either way.
	$yes_wipe = false;
	$site     = '';
	foreach ( $cli_args as $arg ) {
		$arg = preg_replace( '/^--/', '', (string) $arg );
		if ( 'yes-wipe' === $arg ) {
			$yes_wipe = true;
		} elseif ( 0 === strpos( $arg, 'site=' ) ) {
			$site = substr( $arg, 5 );
		}
	}

	if ( ! function_exists( 'home_url' ) || ! defined( 'FFC_PLUGIN_DIR' ) ) {
		return ffc_tseed_refuse( 'WordPress did not load with the plugin active.' );
	}
	if ( ! $yes_wipe ) {
		return ffc_tseed_refuse( 'pass --yes-wipe (yes-wipe under wp eval-file) to confirm that every plugin record on this site is to be deleted.' );
	}
	$home = untrailingslashit( home_url() );
	if ( '' === $site || untrailingslashit( $site ) !== $home ) {
		return ffc_tseed_refuse( sprintf( '--site=%s does not match this site\'s home_url(), %s.', '' === $site ? '(missing)' : $site, $home ) );
	}

	$manifest = '';
	foreach ( array( $script_dir . '/ffc-uninstall-manifest.php', FFC_PLUGIN_DIR . '.github/scripts/ffc-uninstall-manifest.php' ) as $candidate ) {
		if ( is_readable( $candidate ) ) {
			$manifest = $candidate;
			break;
		}
	}
	if ( '' === $manifest ) {
		return ffc_tseed_refuse( 'ffc-uninstall-manifest.php was not found next to this script; copy it alongside.' );
	}
	require_once $manifest;
	$tables = ffc_manifest_tables( FFC_PLUGIN_DIR . 'uninstall.php' );
	if ( array() === $tables ) {
		// An empty parse must never read as "nothing to wipe".
		return ffc_tseed_refuse( 'the table list parsed out of uninstall.php is empty.' );
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';
	require_once ABSPATH . 'wp-admin/includes/post.php';

	$admins = get_users(
		array(
			'role'    => 'administrator',
			'number'  => 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'fields'  => 'ID',
		)
	);
	if ( array() === $admins ) {
		return ffc_tseed_refuse( 'no administrator account to act as.' );
	}
	$admin_id = (int) $admins[0];
	wp_set_current_user( $admin_id );

	// Nothing reaches an inbox, and the activity log records the seed even
	// where the setting is off -- in memory, the stored option is untouched.
	add_filter( 'pre_wp_mail', '__return_false' );
	add_filter(
		'option_ffc_settings',
		static function ( $value ) {
			$value                        = is_array( $value ) ? $value : array();
			$value['enable_activity_log'] = 1;
			return $value;
		}
	);

	$cron_before = ffc_tseed_cron_keys();

	ffc_tseed_wipe( $tables );
	echo "Wiped.\n";

	mt_srand( 1855 );
	$ctx = array( 'admin_id' => $admin_id );
	ffc_tseed_seed( $ctx );

	\FreeFormCertificate\Core\ActivityLog::flush_buffer();
	$removed = ffc_tseed_drop_new_cron_events( $cron_before );
	wp_cache_flush();

	echo "Seeded. Removed {$removed} wp-cron event(s) the seed scheduled.\n\n";
	ffc_tseed_summary( $tables );
	return 0;
}

/**
 * Print why the script refused, change nothing.
 *
 * @param string $why Reason.
 */
function ffc_tseed_refuse( string $why ): int {
	fwrite( STDERR, "REFUSED, nothing was changed: {$why}\n" );
	return 1;
}

// -----------------------------------------------------------------------------
// Wipe.
// -----------------------------------------------------------------------------

/**
 * Empty the plugin's tables, posts, demo users and transients.
 *
 * @param array<int, string> $tables Unprefixed table names from the manifest.
 */
function ffc_tseed_wipe( array $tables ): void {
	global $wpdb;

	$demo_ids = ffc_tseed_demo_user_ids();

	$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
	foreach ( $tables as $name ) {
		$table = $wpdb->prefix . $name;
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			continue;
		}
		if ( 'ffc_user_profiles' === $name ) {
			// A real user's profile is not seed data: only demo and orphaned rows go.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id NOT IN (SELECT ID FROM %i)', $table, $wpdb->users ) );
			if ( array() !== $demo_ids ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id IN (' . implode( ',', array_fill( 0, count( $demo_ids ), '%d' ) ) . ')', array_merge( array( $table ), $demo_ids ) ) );
			}
			continue;
		}
		if ( false === $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) ) ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i AUTO_INCREMENT = 1', $table ) );
		}
	}
	$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );

	$types = array( 'ffc_form', 'ffc_self_scheduling', \FreeFormCertificate\Admin\CertTemplateCpt::POST_TYPE );
	$ids   = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT ID FROM %i WHERE post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')',
			array_merge( array( $wpdb->posts ), $types )
		)
	);
	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}

	foreach ( $demo_ids as $id ) {
		wp_delete_user( $id );
	}

	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ffc\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ffc\\_%' OR option_name LIKE '\\_transient\\_\\_ffc\\_%' OR option_name LIKE '\\_transient\\_timeout\\_\\_ffc\\_%'" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_site\\_transient\\_ffc\\_%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_ffc\\_%'" );

	// Pure data about the rows above: acceptances that name deleted users.
	delete_option( 'ffc_identity_accepted' );

	wp_cache_flush();

	// The shipped default templates went with the post type; put them back.
	\FreeFormCertificate\Admin\CertTemplateSeeder::seed();
}

/**
 * Accounts whose e-mail ends in `@example.test`.
 *
 * @return array<int, int>
 */
function ffc_tseed_demo_user_ids(): array {
	global $wpdb;
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM %i WHERE user_email LIKE %s', $wpdb->users, '%' . $wpdb->esc_like( '@example.test' ) ) ) );
}

/**
 * Every scheduled event, as hook + timestamp + args signature.
 *
 * @return array<string, bool>
 */
function ffc_tseed_cron_keys(): array {
	$keys = array();
	foreach ( (array) _get_cron_array() as $ts => $hooks ) {
		foreach ( (array) $hooks as $hook => $events ) {
			foreach ( (array) $events as $sig => $event ) {
				$keys[ $ts . '|' . $hook . '|' . $sig ] = true;
			}
		}
	}
	return $keys;
}

/**
 * Unschedule every single event that appeared during the run, so wp-cron does
 * not send the e-mails the seed's writes queued.
 *
 * @param array<string, bool> $before Keys from before the run.
 * @return int Events removed.
 */
function ffc_tseed_drop_new_cron_events( array $before ): int {
	$removed = 0;
	foreach ( (array) _get_cron_array() as $ts => $hooks ) {
		foreach ( (array) $hooks as $hook => $events ) {
			foreach ( (array) $events as $sig => $event ) {
				if ( isset( $before[ $ts . '|' . $hook . '|' . $sig ] ) || ! empty( $event['schedule'] ) ) {
					continue;
				}
				if ( true === wp_unschedule_event( (int) $ts, (string) $hook, (array) ( $event['args'] ?? array() ) ) ) {
					++$removed;
				}
			}
		}
	}
	return $removed;
}

// -----------------------------------------------------------------------------
// Fictitious people and documents.
// -----------------------------------------------------------------------------

/**
 * A CPF that passes the check digit. Fictitious by construction.
 */
function ffc_demo_cpf(): string {
	$n = array();
	for ( $i = 0; $i < 9; $i++ ) {
		$n[] = mt_rand( 0, 9 );
	}
	for ( $d = 0; $d < 2; $d++ ) {
		$sum = 0;
		$len = count( $n );
		for ( $i = 0; $i < $len; $i++ ) {
			$sum += $n[ $i ] * ( $len + 1 - $i );
		}
		$r   = ( $sum * 10 ) % 11;
		$n[] = 10 === $r ? 0 : $r;
	}
	return implode( '', $n );
}

/**
 * An RF (seven digits) that passes the check digit.
 */
function ffc_tseed_rf(): string {
	$base = (string) mt_rand( 100000, 999999 );
	return $base . (string) \FreeFormCertificate\Core\DocumentFormatter::rf_check_digit( $base . '0' );
}

/**
 * Forty invented people, the same forty on every run.
 *
 * @return array<int, array{name: string, email: string, cpf: string, rf: string, phone: string, birth: string}>
 */
function ffc_tseed_people(): array {
	$first = array( 'Ana', 'Bruno', 'Carla', 'Daniel', 'Eduarda', 'Felipe', 'Gabriela', 'Henrique', 'Isabela', 'João', 'Larissa', 'Marcos', 'Natália', 'Otávio', 'Paula', 'Rafael', 'Sofia', 'Thiago', 'Vanessa', 'Wagner' );
	$last  = array( 'Souza', 'Lima', 'Rocha', 'Alves', 'Costa', 'Santos', 'Pereira', 'Gomes', 'Dias', 'Cardoso', 'Barros', 'Pinto', 'Freitas', 'Monteiro' );
	$out   = array();
	for ( $i = 0; $i < 40; $i++ ) {
		$name  = $first[ $i % 20 ] . ' ' . $last[ ( $i * 3 ) % 14 ] . ' ' . $last[ ( $i * 5 + 1 ) % 14 ];
		$slug  = strtolower( remove_accents( str_replace( ' ', '.', $name ) ) );
		$out[] = array(
			'name'  => $name,
			'email' => $slug . '.' . ( $i + 1 ) . '@example.test',
			'cpf'   => ffc_demo_cpf(),
			'rf'    => ffc_tseed_rf(),
			'phone' => '(11) 9' . mt_rand( 1000, 9999 ) . '-' . mt_rand( 1000, 9999 ),
			'birth' => ( 1965 + ( $i * 7 ) % 35 ) . '-' . gmdate( 'm-d', time() + ( $i * 9 - 60 ) * DAY_IN_SECONDS ),
		);
	}
	return $out;
}

/**
 * A future weekday, `$n` weekdays from today.
 *
 * @param int $n Offset in weekdays (1 = next weekday).
 */
function ffc_tseed_weekday( int $n ): string {
	$ts = time();
	while ( $n > 0 ) {
		$ts += DAY_IN_SECONDS;
		if ( (int) gmdate( 'N', $ts ) < 6 ) {
			--$n;
		}
	}
	return gmdate( 'Y-m-d', $ts );
}

/**
 * Report a failed write without stopping the seed.
 *
 * @param string $what  What was being written.
 * @param mixed  $error WP_Error, false or a message.
 */
function ffc_tseed_warn( string $what, $error ): void {
	$msg = is_wp_error( $error ) ? $error->get_error_message() : ( is_string( $error ) ? $error : 'failed' );
	fwrite( STDERR, "warning: {$what}: {$msg}\n" );
}

// -----------------------------------------------------------------------------
// Seed.
// -----------------------------------------------------------------------------

/**
 * Fill every module.
 *
 * @param array<string, mixed> $ctx Shared context.
 */
function ffc_tseed_seed( array $ctx ): void {
	$ctx['people'] = ffc_tseed_people();

	$ctx = ffc_tseed_certificates( $ctx );
	$ctx = ffc_tseed_dashboard_users( $ctx );
	$ctx = ffc_tseed_role_users( $ctx );
	$ctx = ffc_tseed_templates( $ctx );
	$ctx = ffc_tseed_calendars( $ctx );
	$ctx = ffc_tseed_audiences( $ctx );
	$ctx = ffc_tseed_custom_fields( $ctx );
	$ctx = ffc_tseed_reregistration( $ctx );
	$ctx = ffc_tseed_recruitment( $ctx );
	$ctx = ffc_tseed_short_urls( $ctx );
	$ctx = ffc_tseed_date_messages( $ctx );
	ffc_tseed_activity_log( $ctx );
}

/**
 * Certificate forms of every kind, with submissions across statuses.
 *
 * @param array<string, mixed> $ctx Context.
 * @return array<string, mixed>
 */
function ffc_tseed_certificates( array $ctx ): array {
	global $wpdb;

	$layout  = (string) file_get_contents( FFC_PLUGIN_DIR . 'templates/certificate-defaults/default_certificate_1.html' );
	$schools = array( 'North Primary School', 'Riverside Middle School', 'Hillcrest High School', 'Lakeview Learning Center' );
	$base    = array(
		array( 'type' => 'text', 'label' => 'Full Name', 'name' => 'name', 'required' => '1', 'options' => '' ),
		array( 'type' => 'email', 'label' => 'Email', 'name' => 'email', 'required' => '1', 'options' => '' ),
		array( 'type' => 'text', 'label' => 'CPF / RF', 'name' => 'cpf_rf', 'required' => '1', 'options' => '' ),
		array( 'type' => 'select', 'label' => 'School', 'name' => 'school', 'required' => '1', 'options' => implode( ', ', $schools ) ),
	);
	$quiz    = array(
		array( 'type' => 'radio', 'label' => 'Which device stores data permanently?', 'name' => 'q_storage', 'required' => '1', 'options' => 'RAM, Hard drive, CPU cache', 'quiz_scored' => '1', 'quiz_mode' => 'correct', 'correct' => array( 'Hard drive' ), 'quiz_points' => '1' ),
		array( 'type' => 'checkbox', 'label' => 'Which are web browsers?', 'name' => 'q_browsers', 'required' => '1', 'options' => 'Firefox, Excel, Chromium, Notepad', 'quiz_scored' => '1', 'quiz_mode' => 'correct', 'correct' => array( 'Firefox', 'Chromium' ), 'quiz_points' => '2' ),
		array( 'type' => 'number', 'label' => 'How many bits in a byte?', 'name' => 'q_bits', 'required' => '1', 'options' => '', 'quiz_scored' => '1', 'quiz_answer_min' => '8', 'quiz_answer_max' => '8', 'quiz_points' => '1' ),
	);
	$manual  = array(
		array( 'type' => 'textarea', 'label' => 'Describe one activity you will run with your class', 'name' => 'q_essay', 'required' => '1', 'options' => '', 'quiz_scored' => '1', 'quiz_text' => 'manual', 'quiz_points' => '5' ),
	);

	$kinds = array( 'standard', 'quiz', 'manual', 'geofence', 'exception' );
	$names = array( 'Digital Literacy Workshop', 'Inclusive Education', 'Science Fair Volunteers', 'Reading Club Mediators', 'School Safety Training', 'Math Olympiad Coaches', 'Library Week', 'First Aid Basics', 'Environmental Education', 'Arts Festival Crew', 'Parent Council Meeting', 'Robotics Mentors', 'Sign Language Intro', 'Sports Day Referees', 'Music Education Forum', 'History Fair Jury', 'Coding for Teachers', 'School Garden Project', 'Bullying Prevention', 'Assessment Strategies' );

	$forms = array();
	foreach ( $names as $i => $title ) {
		$kind   = $kinds[ $i % count( $kinds ) ];
		$fields = $base;
		$config = array(
			'pdf_layout'         => $layout,
			'bg_image'           => '',
			'enable_restriction' => '',
			'send_user_email'    => '1',
			'email_subject'      => 'Your certificate: {{form_title}}',
			'restrictions'       => array( 'password' => '0', 'allowlist' => '0', 'denylist' => '0', 'ticket' => '0' ),
			'quiz_enabled'       => '0',
		);
		$geo    = array();
		if ( 'quiz' === $kind || 'manual' === $kind ) {
			$fields                       = array_merge( $base, 'quiz' === $kind ? $quiz : $manual );
			$config['quiz_enabled']       = '1';
			$config['quiz_passing_score'] = 70;
			$config['quiz_max_attempts']  = 3;
			$config['quiz_show_score']    = '1';
			$config['quiz_show_correct']  = '0';
		} elseif ( 'geofence' === $kind ) {
			$geo = array(
				'datetime_enabled'           => '1',
				'geo_enabled'                => '1',
				'geo_ip_areas_permissive'    => '0',
				'schedule_exception_enabled' => '0',
				'class_time_start'           => '',
				'class_time_end'             => '',
				'schedule_default_mode'      => 'now',
				'date_start'                 => gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS ),
				'date_end'                   => gmdate( 'Y-m-d', time() + 60 * DAY_IN_SECONDS ),
				'time_start'                 => '07:00',
				'time_end'                   => '22:00',
				'time_mode'                  => 'daily',
				'datetime_hide_mode_before'  => 'message',
				'datetime_hide_mode_during'  => 'message',
				'datetime_hide_mode_after'   => 'message',
				'msg_datetime'               => 'This form is open on weekdays during the event.',
				'multi_day'                  => '1',
				'geo_gps_enabled'            => '1',
				'geo_ip_enabled'             => '0',
				'geo_area_source'            => 'custom',
				'geo_area_location_ids'      => array(),
				'geo_areas'                  => "-23.5505, -46.6333, 5000\n-23.5614, -46.6559, 2000",
				'geo_gps_ip_logic'           => 'or',
				'geo_hide_mode'              => 'message',
				'msg_geo_blocked'            => 'This form is only available at the school.',
				'msg_geo_error'              => 'Your location could not be read.',
			);
		} elseif ( 'exception' === $kind ) {
			$geo = array(
				'datetime_enabled'           => '0',
				'geo_enabled'                => '0',
				'geo_ip_areas_permissive'    => '0',
				'schedule_exception_enabled' => '1',
				'class_time_start'           => '08:00',
				'class_time_end'             => '12:00',
				'schedule_default_mode'      => 'manual',
			);
		}

		$id = wp_insert_post(
			array(
				'post_type'   => 'ffc_form',
				'post_status' => 'publish',
				'post_title'  => $title . ' (' . $kind . ')',
				'post_date'   => wp_date( 'Y-m-d H:i:s', time() - ( 40 - $i ) * DAY_IN_SECONDS ),
			)
		);
		if ( ! $id || is_wp_error( $id ) ) {
			ffc_tseed_warn( 'form ' . $title, $id );
			continue;
		}
		update_post_meta( $id, '_ffc_form_fields', $fields );
		update_post_meta( $id, '_ffc_form_config', $config );
		if ( array() !== $geo ) {
			update_post_meta( $id, '_ffc_geofence_config', $geo );
		}
		$forms[] = array( 'id' => (int) $id, 'title' => get_the_title( $id ), 'kind' => $kind );
	}

	// Three submissions per form: 60 in all, over the forty people.
	$handler   = new \FreeFormCertificate\Submissions\SubmissionHandler();
	$repo      = $handler->get_repository();
	$people    = $ctx['people'];
	$cursor    = 0;
	$sub_ids   = array();
	foreach ( $forms as $f ) {
		$fields = get_post_meta( $f['id'], '_ffc_form_fields', true );
		$config = get_post_meta( $f['id'], '_ffc_form_config', true );
		for ( $k = 0; $k < 3; $k++ ) {
			$p = $people[ $cursor % count( $people ) ];
			// Every fourth person identifies with the RF instead of the CPF.
			$doc  = 0 === $cursor % 4 ? $p['rf'] : $p['cpf'];
			$data = array(
				'name'             => $p['name'],
				'email'            => $p['email'],
				'cpf_rf'           => $doc,
				'school'           => $schools[ $cursor % count( $schools ) ],
				'ffc_lgpd_consent' => '1',
			);
			if ( 'quiz' === $f['kind'] ) {
				$data['q_storage']  = 0 === $k ? 'RAM' : 'Hard drive';
				$data['q_browsers'] = array( 'Firefox', 'Chromium' );
				$data['q_bits']     = '8';
			} elseif ( 'manual' === $f['kind'] ) {
				$data['q_essay'] = 'A short reading circle with a shared notebook, once a week.';
			}
			++$cursor;
			$sid = $handler->process_submission( $f['id'], $f['title'], $data, $p['email'], (array) $fields, (array) $config );
			if ( is_wp_error( $sid ) ) {
				ffc_tseed_warn( 'submission', $sid );
				continue;
			}
			$status = 'publish';
			if ( 'quiz' === $f['kind'] ) {
				$status = array( 'quiz_failed', 'publish', 'quiz_in_progress' )[ $k ];
			} elseif ( 'manual' === $f['kind'] ) {
				$status = array( 'quiz_pending', 'publish', 'quiz_pending' )[ $k ];
			} elseif ( 2 === $k && 0 === $cursor % 2 ) {
				$status = 'trash';
			}
			if ( 'publish' !== $status ) {
				$repo->update( (int) $sid, array( 'status' => $status ) );
			}
			$sub_ids[] = (int) $sid;
		}
	}

	// Spread the submissions over the last weeks.
	$table = $wpdb->prefix . 'ffc_submissions';
	foreach ( $sub_ids as $n => $sid ) {
		$wpdb->update( $table, array( 'submission_date' => time() - ( ( count( $sub_ids ) - $n ) * 11 + 1 ) * HOUR_IN_SECONDS ), array( 'id' => $sid ), array( '%d' ), array( '%d' ) );
	}

	$ctx['forms'] = $forms;
	return $ctx;
}

/**
 * Dashboard users: every person has an account (`ffc_end_user`), a display
 * name and a birth date; the submissions above created most of them.
 *
 * @param array<string, mixed> $ctx Context.
 * @return array<string, mixed>
 */
function ffc_tseed_dashboard_users( array $ctx ): array {
	$ids = array();
	foreach ( $ctx['people'] as $i => $p ) {
		$user = get_user_by( 'email', $p['email'] );
		if ( ! $user ) {
			$login = sanitize_user( strstr( $p['email'], '@', true ), true );
			$uid   = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_email'   => $p['email'],
					'user_pass'    => wp_generate_password( 24 ),
					'display_name' => $p['name'],
					'role'         => 'ffc_end_user',
				)
			);
			if ( is_wp_error( $uid ) ) {
				ffc_tseed_warn( 'user ' . $p['email'], $uid );
				continue;
			}
			$user = get_user_by( 'id', $uid );
		}
		if ( ! in_array( 'ffc_end_user', (array) $user->roles, true ) ) {
			$user->add_role( 'ffc_end_user' );
		}
		wp_update_user( array( 'ID' => $user->ID, 'display_name' => $p['name'] ) );
		\FreeFormCertificate\UserDashboard\UserManager::update_profile(
			(int) $user->ID,
			array(
				'display_name' => $p['name'],
				'phone'        => $p['phone'],
				'department'   => array( 'Teaching', 'Coordination', 'Administration', 'Library' )[ $i % 4 ],
				'organization' => 'Example School Network',
			)
		);
		\FreeFormCertificate\UserDashboard\UserManager::update_extended_profile( (int) $user->ID, array( 'birth_date' => $p['birth'] ) );
		$ids[ $p['email'] ] = (int) $user->ID;
	}
	$ctx['user_ids'] = $ids;
	return $ctx;
}

/**
 * One demo account per FFC role (#1860): `<role>@example.test`, holding that
 * role alone and named after its label, so every tier can be signed into and
 * checked. `ffc_end_user` is left out: the people above already hold it.
 * The wipe removes them with every other `@example.test` account.
 *
 * Passwords are random and never printed; sign in with "Lost your password"
 * or switch to the account from an administrator.
 *
 * @param array<string, mixed> $ctx Context.
 * @return array<string, mixed>
 */
function ffc_tseed_role_users( array $ctx ): array {
	$ids = array();
	foreach ( \FreeFormCertificate\UserDashboard\RoleRegistrar::ffc_managed_role_labels() as $role => $label ) {
		if ( 'ffc_end_user' === $role ) {
			continue;
		}
		if ( ! get_role( $role ) instanceof \WP_Role ) {
			ffc_tseed_warn( 'role user ' . $role, 'the role is not registered' );
			continue;
		}
		$email = $role . '@example.test';
		$user  = get_user_by( 'email', $email );
		if ( ! $user ) {
			$uid = wp_insert_user(
				array(
					'user_login'   => $role,
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 24 ),
					'display_name' => $label,
					'role'         => $role,
				)
			);
			if ( is_wp_error( $uid ) ) {
				ffc_tseed_warn( 'role user ' . $email, $uid );
				continue;
			}
			$user = get_user_by( 'id', $uid );
		}
		$user->set_role( $role );
		$ids[ $role ] = (int) $user->ID;
	}
	$ctx['role_user_ids'] = $ids;
	return $ctx;
}

/**
 * Twenty certificate templates beside the shipped defaults.
 *
 * @param array<string, mixed> $ctx Context.
 * @return array<string, mixed>
 */
function ffc_tseed_templates( array $ctx ): array {
	$cpt  = '\FreeFormCertificate\Admin\CertTemplateCpt';
	$html = (string) file_get_contents( FFC_PLUGIN_DIR . 'templates/certificate-defaults/default_certificate_2.html' );
	for ( $i = 1; $i <= 20; $i++ ) {
		$id = wp_insert_post(
			array(
				'post_type'   => $cpt::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => sprintf( 'School template %02d', $i ),
			)
		);
		if ( ! $id || is_wp_error( $id ) ) {
			ffc_tseed_warn( 'template', $id );
			continue;
		}
		update_post_meta( $id, $cpt::META_VISIBLE, 0 === $i % 5 ? '0' : '1' );
		update_post_meta( $id, $cpt::META_HTML, $html );
		update_post_meta( $id, $cpt::META_BG_IMAGE, '' );
		update_post_meta( $id, $cpt::META_KIND, $cpt::KIND_CERTIFICATE );
	}
	return $ctx;
}

/**
 * Personal calendars, appointments in every status, a waitlist and blocks.
 *
 * @param array<string, mixed> $ctx Context.
 * @return array<string, mixed>
 */
function ffc_tseed_calendars( array $ctx ): array {
	$calendars = new \FreeFormCertificate\Repositories\CalendarRepository();
	$titles    = array( 'Pedagogical Coordination', 'School Psychologist', 'Principal Office Hours', 'IT Support Desk', 'Library Orientation', 'Speech Therapy', 'Career Guidance', 'Special Education Support', 'Parent Meetings', 'Lab Booking Advice', 'Secretary Service', 'Nutritionist', 'Sports Coordination', 'Arts Workshop Slots', 'Language Tutoring', 'Math Tutoring', 'Science Tutoring', 'Counselling', 'Enrollment Help', 'Document Pickup' );
	$cal_ids   = array();
	foreach ( $titles as $i => $title ) {
		$post = wp_insert_post( array( 'post_type' => 'ffc_self_scheduling', 'post_status' => 'draft', 'post_title' => $title ) );
		if ( ! $post || is_wp_error( $post ) ) {
			ffc_tseed_warn( 'calendar ' . $title, $post );
			continue;
		}
		update_post_meta(
			$post,
			'_ffc_self_scheduling_config',
			array(
				'description'                       => 'Book a 30-minute meeting: ' . strtolower( $title ) . '.',
				'slot_duration'                     => 30,
				'slot_interval'                     => 0,
				'slots_per_day'                     => 0,
				'max_appointments_per_slot'         => 1,
				'advance_booking_min'               => 0,
				'advance_booking_max'               => 90,
				'allow_cancellation'                => 1,
				'cancellation_min_hours'            => 12,
				'minimum_interval_between_bookings' => 0,
				'requires_approval'                 => 1 === $i ? 1 : 0,
				'status'                            => 18 === $i ? 'inactive' : 'active',
				'waitlist_enabled'                  => 0 === $i ? 1 : 0,
				'waitlist_capacity'                 => 0 === $i ? 5 : 0,
				'max_blocks_per_user'               => 0,
				'schedule_type'                     => 'regular',
				'visibility'                        => 0 === $i % 4 ? 'private' : 'public',
				'scheduling_visibility'             => 'public',
				'restrict_viewing_to_hours'         => 0,
				'restrict_booking_to_hours'         => 0,
				'admin_bypass'                      => 1,
			)
		);
		$hours = array();
		foreach ( array( 1, 2, 3, 4, 5 ) as $day ) {
			$hours[] = array( 'day' => $day, 'start' => '08:00', 'end' => '12:00' );
			$hours[] = array( 'day' => $day, 'start' => '13:00', 'end' => '18:00' );
		}
		update_post_meta( $post, '_ffc_self_scheduling_working_hours', $hours );
		wp_update_post( array( 'ID' => $post, 'post_status' => 'publish' ) );
		$cal = $calendars->findByPostId( (int) $post );
		if ( ! $cal ) {
			ffc_tseed_warn( 'calendar row ' . $title, 'not created' );
			continue;
		}
		$cal_ids[] = (int) $cal['id'];
	}
	if ( count( $cal_ids ) < 3 ) {
		return $ctx;
	}

	$handler = new \FreeFormCertificate\SelfScheduling\AppointmentHandler();
	$appts   = new \FreeFormCertificate\Repositories\AppointmentRepository();
	$people  = $ctx['people'];
	$slots   = array( '08:00:00', '09:00:00', '10:30:00', '13:00:00', '14:30:00', '16:00:00' );
	$made    = array();
	$book    = static function ( int $cal, int $day, string $slot, array $p ) use ( $handler, $ctx ) {
		return $handler->process_appointment(
			array(
				'calendar_id'      => $cal,
				'appointment_date' => ffc_tseed_weekday( $day ),
				'start_time'       => $slot,
				'name'             => $p['name'],
				'email'            => $p['email'],
				'cpf_rf'           => $p['cpf'],
				'phone'            => $p['phone'],
				'user_notes'       => 'Seeded appointment.',
				'consent_given'    => 1,
				'user_id'          => (int) ( $ctx['user_ids'][ $p['email'] ] ?? 0 ),
			)
		);
	};

	// 30 bookings over three calendars (the second requires approval).
	for ( $n = 0; $n < 30; $n++ ) {
		$cal = $cal_ids[ $n % 3 ];
		$r   = $book( $cal, 2 + intdiv( $n, 6 ), $slots[ $n % 6 ], $people[ $n ] );
		if ( is_wp_error( $r ) ) {
			ffc_tseed_warn( 'appointment', $r );
			continue;
		}
		$made[] = (int) $r['appointment_id'];
	}
	// Three more on a slot already taken in the waitlisted calendar, booked as
	// an anonymous visitor: the administrator holds the capacity bypass and
	// would simply overbook the slot.
	wp_set_current_user( 0 );
	for ( $n = 30; $n < 33; $n++ ) {
		$r = $handler->process_appointment(
			array(
				'calendar_id'      => $cal_ids[0],
				'appointment_date' => ffc_tseed_weekday( 2 ),
				'start_time'       => $slots[0],
				'name'             => $people[ $n ]['name'],
				'email'            => $people[ $n ]['email'],
				'cpf_rf'           => $people[ $n ]['cpf'],
				'phone'            => $people[ $n ]['phone'],
				'consent_given'    => 1,
				'user_id'          => (int) ( $ctx['user_ids'][ $people[ $n ]['email'] ] ?? 0 ),
			)
		);
		if ( is_wp_error( $r ) ) {
			ffc_tseed_warn( 'waitlist entry', $r );
		} elseif ( empty( $r['waitlisted'] ) ) {
			ffc_tseed_warn( 'waitlist entry', 'booked instead of waitlisted' );
		}
	}
	wp_set_current_user( $ctx['admin_id'] );

	// Walk part of them through the other statuses.
	foreach ( $made as $k => $id ) {
		switch ( $k % 6 ) {
			case 1:
				$appts->cancel( $id, $ctx['admin_id'], 'Rescheduled by the participant.' );
				break;
			case 3:
				$appts->markCompleted( $id );
				break;
			case 5:
				$appts->markNoShow( $id );
				break;
		}
	}

	// Blocked dates: full days, time ranges and a recurring block.
	$blocks = new \FreeFormCertificate\Repositories\BlockedDateRepository();
	for ( $b = 0; $b < 20; $b++ ) {
		$cal  = $cal_ids[ $b % count( $cal_ids ) ];
		$date = ffc_tseed_weekday( 20 + $b );
		if ( 0 === $b % 3 ) {
			$ok = $blocks->createTimeRangeBlock( $cal, $date, '12:00:00', '13:00:00', 'Staff lunch' );
		} elseif ( 19 === $b ) {
			$ok = $blocks->createRecurringBlock( $cal, $date, array( 'type' => 'weekly', 'days' => array( 5 ) ), 'Friday planning' );
		} else {
			$ok = $blocks->createFullDayBlock( $cal, $date, null, 'Teacher training day' );
		}
		if ( ! $ok ) {
			ffc_tseed_warn( 'blocked date', false );
		}
	}

	$ctx['calendar_ids'] = $cal_ids;
	return $ctx;
}

/**
 * Audiences: a hierarchy, members, schedules with environments, bookings,
 * holidays and permissions.
 *
 * @param array<string, mixed> $ctx Context.
 * @return array<string, mixed>
 */
function ffc_tseed_audiences( array $ctx ): array {
	$writer = '\FreeFormCertificate\Audience\AudienceWriter';
	$colors = array( '#2563eb', '#059669', '#d97706', '#7c3aed', '#dc2626' );
	$roots  = array();
	foreach ( array( 'Teachers', 'School Staff', 'Coordinators', 'Interns' ) as $i => $name ) {
		$id = $writer::create( array( 'name' => $name, 'color' => $colors[ $i ], 'allow_self_join' => 3 === $i ? 1 : 0 ) );
		if ( $id ) {
			$roots[] = (int) $id;
		}
	}
	$all  = $roots;
	$subs = array( 'Science', 'Languages', 'Mathematics', 'History', 'Arts', 'Physical Education', 'Kitchen', 'Cleaning', 'Secretariat', 'Library', 'Primary', 'Secondary', 'Morning Shift', 'Evening Shift', 'Night Shift', 'Inclusion Team' );
	foreach ( $subs as $i => $name ) {
		$id = $writer::create( array( 'name' => $name, 'color' => $colors[ $i % 5 ], 'parent_id' => $roots[ $i % count( $roots ) ], 'status' => 15 === $i ? 'inactive' : 'active' ) );
		if ( $id ) {
			$all[] = (int) $id;
		}
	}

	$uids = array_values( $ctx['user_ids'] );
	foreach ( $all as $k => $aid ) {
		$writer::bulk_add_members( $aid, array_slice( $uids, ( $k * 3 ) % 34, 6 ) );
	}

	$schedules = '\FreeFormCertificate\Audience\AudienceScheduleRepository';
	$envs      = '\FreeFormCertificate\Audience\AudienceEnvironmentRepository';
	$bookings  = '\FreeFormCertificate\Audience\AudienceBookingWriter';
	$week      = array();
	foreach ( array( 'mon', 'tue', 'wed', 'thu', 'fri' ) as $d ) {
		$week[ $d ] = array( 'start' => '07:00', 'end' => '19:00', 'closed' => false );
	}
	foreach ( array( 'sat', 'sun' ) as $d ) {
		$week[ $d ] = array( 'start' => '', 'end' => '', 'closed' => true );
	}
	$env_ids = array();
	$sch_ids = array();
	for ( $s = 1; $s <= 20; $s++ ) {
		$sid = $schedules::create(
			array(
				'name'              => sprintf( 'Unit %02d shared rooms', $s ),
				'description'       => 'Rooms any department can book.',
				'environment_label' => 'Room',
				'visibility'        => 0 === $s % 3 ? 'private' : 'public',
			)
		);
		if ( ! $sid ) {
			ffc_tseed_warn( 'audience schedule', false );
			continue;
		}
		$sch_ids[] = (int) $sid;
		foreach ( array( 'Auditorium', 'Science Lab' ) as $e => $room ) {
			$eid = $envs::create( array( 'schedule_id' => $sid, 'name' => $room, 'color' => $colors[ ( $s + $e ) % 5 ], 'working_hours' => $week ) );
			if ( $eid ) {
				$env_ids[] = (int) $eid;
			}
		}
		$envs::add_holiday( (int) $sid, ffc_tseed_weekday( 30 + $s ), 'Municipal holiday' );
		$schedules::set_user_permissions( (int) $sid, $uids[ $s % count( $uids ) ], array( 'can_book' => 1, 'can_cancel_others' => $s % 2, 'can_override_conflicts' => 0 ) );
	}

	$events = array( 'Department meeting', 'Lab safety training', 'Reading club', 'Staff briefing', 'Science fair rehearsal', 'Parent-teacher meeting' );
	$times  = array( array( '08:00:00', '09:30:00' ), array( '10:00:00', '11:30:00' ), array( '14:00:00', '15:00:00' ), array( '15:30:00', '17:00:00' ) );
	for ( $b = 0; $b < 30 && array() !== $env_ids; $b++ ) {
		$t   = $times[ $b % 4 ];
		$ind = 0 === $b % 5;
		$bid = $bookings::create(
			array(
				'environment_id' => $env_ids[ $b % count( $env_ids ) ],
				'booking_date'   => ffc_tseed_weekday( 1 + intdiv( $b, 4 ) ),
				'start_time'     => $t[0],
				'end_time'       => $t[1],
				'booking_type'   => $ind ? 'individual' : 'audience',
				'description'    => $events[ $b % count( $events ) ],
				'audience_ids'   => $ind ? array() : array( $all[ $b % count( $all ) ] ),
				'user_ids'       => $ind ? array( $uids[ $b % count( $uids ) ] ) : array(),
			)
		);
		if ( ! $bid ) {
			ffc_tseed_warn( 'audience booking', false );
			continue;
		}
		if ( 7 === $b % 8 ) {
			$bookings::cancel( (int) $bid, 'Room needed for exams.' );
		}
	}

	$ctx['audience_ids'] = $all;
	return $ctx;
}

/**
 * Custom fields beyond the standard reregistration set, with user values.
 *
 * @param array<string, mixed> $ctx Context.
 * @return array<string, mixed>
 */
function ffc_tseed_custom_fields( array $ctx ): array {
	$seeder = '\FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder';
	$writer = '\FreeFormCertificate\Reregistration\CustomFieldWriter';
	// The standard set arrives through `ffc_audience_created`; seed it here
	// too in case the reregistration module is switched off on this site.
	foreach ( $ctx['audience_ids'] as $aid ) {
		$seeder::seed_for_audience( (int) $aid );
	}
	$types = array( 'text', 'select', 'number', 'date', 'textarea' );
	$made  = array();
	for ( $i = 1; $i <= 20; $i++ ) {
		$type = $types[ $i % count( $types ) ];
		$id   = $writer::create(
			array(
				'audience_id'   => $ctx['audience_ids'][ $i % count( $ctx['audience_ids'] ) ],
				'field_label'   => sprintf( 'Custom question %02d', $i ),
				'field_key'     => sprintf( 'custom_question_%02d', $i ),
				'field_type'    => $type,
				'field_group'   => 'custom',
				'field_options' => 'select' === $type ? array( 'choices' => array( 'Yes', 'No', 'Not sure' ) ) : null,
				'sort_order'    => 100 + $i,
				'is_required'   => $i % 2,
			)
		);
		if ( ! $id ) {
			ffc_tseed_warn( 'custom field', false );
			continue;
		}
		$made[] = (int) $id;
	}
	$uids = array_values( $ctx['user_ids'] );
	foreach ( $made as $k => $fid ) {
		$writer::set_user_field_value( $uids[ $k % count( $uids ) ], $fid, 'Seeded answer ' . ( $k + 1 ) );
	}
	return $ctx;
}

/**
 * Reregistration campaigns in every state, with submissions across statuses.
 *
 * @param array<string, mixed> $ctx Context.
 * @return array<string, mixed>
 */
function ffc_tseed_reregistration( array $ctx ): array {
	$repo     = '\FreeFormCertificate\Reregistration\ReregistrationRepository';
	$writer   = '\FreeFormCertificate\Reregistration\ReregistrationSubmissionWriter';
	$reader   = '\FreeFormCertificate\Reregistration\ReregistrationSubmissionReader';
	$process  = '\FreeFormCertificate\Reregistration\ReregistrationDataProcessor';
	$states   = array( 'active', 'draft', 'expired', 'closed' );
	$by_email = array();
	foreach ( $ctx['people'] as $p ) {
		$by_email[ (int) ( $ctx['user_ids'][ $p['email'] ] ?? 0 ) ] = $p;
	}

	for ( $c = 0; $c < 20; $c++ ) {
		$state = $states[ $c % 4 ];
		$start = 'draft' === $state ? time() + 10 * DAY_IN_SECONDS : time() - ( 30 + $c ) * DAY_IN_SECONDS;
		$end   = 'active' === $state || 'draft' === $state ? $start + 60 * DAY_IN_SECONDS : time() - 2 * DAY_IN_SECONDS;
		$rid   = $repo::create(
			array(
				'title'        => sprintf( 'Annual reregistration %d, %s', 2026 - intdiv( $c, 4 ), $state ),
				'start_date'   => wp_date( 'Y-m-d H:i:s', $start ),
				'end_date'     => wp_date( 'Y-m-d H:i:s', $end ),
				'auto_approve' => 0 === $c % 8 ? 1 : 0,
				'status'       => $state,
			)
		);
		if ( ! $rid ) {
			ffc_tseed_warn( 'reregistration campaign', false );
			continue;
		}
		$audience = $ctx['audience_ids'][ $c % count( $ctx['audience_ids'] ) ];
		$repo::set_audience_ids( (int) $rid, array( $audience ) );
		if ( 'draft' === $state || $c >= 12 ) {
			continue;
		}

		// Members of the audience get a pending row each, as on launch.
		$writer::create_for_audience_members( (int) $rid, array( $audience ) );
		$rereg = $repo::get_by_id( (int) $rid );
		$rows  = $reader::get_by_reregistration( (int) $rid, array( 'orderby' => 'id' ) );
		foreach ( array_values( (array) $rows ) as $k => $row ) {
			$uid = (int) $row->user_id;
			$p   = $by_email[ $uid ] ?? null;
			if ( null === $p || 0 === $k ) {
				continue; // The first stays pending.
			}
			if ( 1 === $k ) {
				$writer::update( (int) $row->id, array( 'status' => 'in_progress' ) );
				continue;
			}
			$fields = array(
				'display_name'    => $p['name'],
				'cpf'             => $p['cpf'],
				'rf'              => $p['rf'],
				'data_nascimento' => $p['birth'],
				'phone'           => $p['phone'],
				'celular'         => $p['phone'],
				'divisao_setor'   => 'Pedagogical Division',
			);
			$process::process_submission( $row, $rereg, array( 'fields' => $fields ), $uid, false );
			if ( 3 === $k ) {
				$writer::approve( (int) $row->id, $ctx['admin_id'] );
			} elseif ( 4 === $k ) {
				$writer::reject( (int) $row->id, $ctx['admin_id'], 'The document number does not match.' );
			} elseif ( 5 === $k && 'expired' === $state ) {
				$writer::update( (int) $row->id, array( 'status' => 'expired' ) );
			}
		}
	}
	return $ctx;
}

/**
 * Recruitment: adjutancies, reasons, notices in every status, candidates,
 * classifications on both lists and calls.
 *
 * @param array<string, mixed> $ctx Context.
 * @return array<string, mixed>
 */
function ffc_tseed_recruitment( array $ctx ): array {
	$ns = '\FreeFormCertificate\Recruitment\\';
	$adjutancies = array();
	$subjects    = array( 'Portuguese', 'Mathematics', 'Science', 'History', 'Geography', 'English', 'Arts', 'Physical Education', 'Music', 'Philosophy', 'Sociology', 'Chemistry', 'Physics', 'Biology', 'Spanish', 'Computing', 'Early Childhood', 'Literacy', 'Special Education', 'School Library' );
	foreach ( $subjects as $i => $s ) {
		$id = call_user_func( array( $ns . 'RecruitmentAdjutancyWriter', 'create' ), sanitize_title( $s ), $s, array( '#2563eb', '#059669', '#d97706', '#7c3aed' )[ $i % 4 ] );
		if ( $id ) {
			$adjutancies[] = (int) $id;
		}
	}
	$applies = array( array( 'denied' ), array( 'granted' ), array( 'appeal_denied' ), array( 'appeal_granted' ), array() );
	$reasons = array();
	for ( $i = 1; $i <= 20; $i++ ) {
		$id = call_user_func( array( $ns . 'RecruitmentReasonWriter', 'create' ), sprintf( 'reason-%02d', $i ), sprintf( 'Reason %02d: documents under review', $i ), '#64748b', $applies[ $i % 5 ] );
		if ( $id ) {
			$reasons[] = (int) $id;
		}
	}

	// Thirty candidates, each with CPF, RF and e-mail.
	$candidates = array();
	foreach ( array_slice( $ctx['people'], 0, 30 ) as $i => $p ) {
		$cid = call_user_func(
			array( $ns . 'CandidatePersister', 'upsert_candidate' ),
			array(
				'name'  => $p['name'],
				'cpf'   => $p['cpf'],
				'rf'    => $p['rf'],
				'email' => $p['email'],
				'phone' => $p['phone'],
				'pcd'   => 0 === $i % 7 ? '1' : '0',
			)
		);
		if ( $cid ) {
			$candidates[] = (int) $cid;
		}
	}

	$writer   = $ns . 'RecruitmentNoticeWriter';
	$machine  = $ns . 'RecruitmentNoticeStateMachine';
	$attach   = $ns . 'RecruitmentNoticeAdjutancyRepository';
	$classif  = $ns . 'RecruitmentClassificationRepository';
	$promote  = $ns . 'RecruitmentPromotionService';
	$calls    = $ns . 'RecruitmentCallService';
	$cmachine = $ns . 'RecruitmentClassificationStateMachine';
	$targets  = array( 'draft', 'preliminary', 'definitive', 'closed' );
	$call_ids = array();
	for ( $n = 0; $n < 20; $n++ ) {
		$target = $targets[ $n % 4 ];
		$nid    = call_user_func( array( $writer, 'create' ), sprintf( 'EDT-%04d/2026', $n + 1 ), sprintf( 'Temporary teachers, notice %02d', $n + 1 ) );
		if ( ! $nid ) {
			ffc_tseed_warn( 'recruitment notice', false );
			continue;
		}
		$adj = array( $adjutancies[ $n % count( $adjutancies ) ], $adjutancies[ ( $n + 1 ) % count( $adjutancies ) ] );
		foreach ( $adj as $a ) {
			call_user_func( array( $attach, 'attach' ), (int) $nid, $a );
		}
		// Six candidates per notice on the preliminary list.
		$prev = array();
		for ( $r = 0; $r < 6; $r++ ) {
			$cid = $candidates[ ( $n * 5 + $r ) % count( $candidates ) ];
			$id  = call_user_func(
				array( $classif, 'create' ),
				array(
					'candidate_id' => $cid,
					'adjutancy_id' => $adj[ $r % 2 ],
					'notice_id'    => (int) $nid,
					'list_type'    => 'preview',
					'rank'         => intdiv( $r, 2 ) + 1,
					'score'        => (string) ( 90 - $r * 3.5 ),
					'time_points'  => (string) ( $r * 1.25 ),
					'hab_emebs'    => $r % 2,
				)
			);
			if ( $id ) {
				$prev[] = (int) $id;
			}
		}
		if ( 'draft' === $target ) {
			continue;
		}
		call_user_func( array( $machine, 'transition_to' ), (int) $nid, 'preliminary' );
		$pstat = array( 'granted', 'denied', 'appeal_granted', 'appeal_denied', 'empty', 'granted' );
		foreach ( $prev as $k => $pid ) {
			$reason = in_array( $pstat[ $k ], array( 'denied', 'appeal_denied' ), true ) ? $reasons[ $k % count( $reasons ) ] : null;
			call_user_func( array( $classif, 'set_preview_status' ), $pid, $pstat[ $k ], $reason );
		}
		if ( 'preliminary' === $target ) {
			continue;
		}
		$snap = call_user_func( array( $promote, 'snapshot_to_definitive' ), (int) $nid );
		if ( empty( $snap['success'] ) ) {
			ffc_tseed_warn( 'recruitment snapshot', implode( '; ', (array) ( $snap['errors'] ?? array( $snap['error'] ?? 'failed' ) ) ) );
			continue;
		}
		// Call the first-ranked of each adjutancy, then move them on.
		$walk = array( 'called', 'accepted', 'hired', 'not_shown', 'withdrew' );
		foreach ( $adj as $j => $a ) {
			$row = call_user_func( array( $classif, 'find_lowest_rank_empty' ), (int) $nid, $a, 'definitive' );
			if ( null === $row ) {
				continue;
			}
			$res = call_user_func( array( $calls, 'call_single' ), (int) $row->id, ffc_tseed_weekday( 5 ), '08:00', $ctx['admin_id'], null, 'Bring your documents.', false );
			if ( empty( $res['success'] ) ) {
				ffc_tseed_warn( 'recruitment call', implode( '; ', (array) $res['errors'] ) );
				continue;
			}
			$call_ids[] = (int) $res['call_ids'][0];
			$next       = $walk[ count( $call_ids ) % 5 ];
			if ( 'called' === $next ) {
				continue;
			}
			$moved = call_user_func( array( $cmachine, 'transition_to' ), (int) $row->id, $next, 'Seeded outcome.' );
			if ( empty( $moved['success'] ) ) {
				ffc_tseed_warn( 'recruitment classification ' . $next, implode( '; ', (array) ( $moved['errors'] ?? array( $moved['error'] ?? 'failed' ) ) ) );
			}
		}
		if ( 'closed' === $target ) {
			call_user_func( array( $machine, 'transition_to' ), (int) $nid, 'closed' );
		}
	}

	// A cancelled call, so the history screen shows one.
	if ( array() !== $call_ids ) {
		$res = call_user_func( array( $calls, 'cancel_call' ), end( $call_ids ), 'Issued by mistake.', $ctx['admin_id'] );
		if ( empty( $res['success'] ) ) {
			ffc_tseed_warn( 'recruitment call cancel', implode( '; ', (array) ( $res['errors'] ?? array() ) ) );
		}
	}
	return $ctx;
}

/**
 * Short URLs.
 *
 * @param array<string, mixed> $ctx Context.
 * @return array<string, mixed>
 */
function ffc_tseed_short_urls( array $ctx ): array {
	$service = new \FreeFormCertificate\UrlShortener\UrlShortenerService();
	for ( $i = 1; $i <= 20; $i++ ) {
		$r = $service->create_short_url( sprintf( 'https://example.org/school-news/item-%02d', $i ), sprintf( 'School news %02d', $i ) );
		if ( empty( $r['success'] ) ) {
			ffc_tseed_warn( 'short url', (string) ( $r['error'] ?? 'failed' ) );
			continue;
		}
		if ( 0 === $i % 6 ) {
			$service->toggle_status( (int) $r['data']['id'] );
		}
	}
	return $ctx;
}

/**
 * Date Messages: rules, runs and the delivery log.
 *
 * @param array<string, mixed> $ctx Context.
 * @return array<string, mixed>
 */
function ffc_tseed_date_messages( array $ctx ): array {
	$ns    = '\FreeFormCertificate\DateMessages\\';
	$rules = array();
	for ( $i = 1; $i <= 20; $i++ ) {
		$rule = call_user_func(
			array( $ns . 'Rule', 'from_array' ),
			array(
				'name'            => sprintf( 'Birthday message %02d', $i ),
				'source'          => 'birthday',
				'offset_days'     => 0 === $i % 3 ? -7 : 0,
				'audience_ids'    => 0 === $i % 2 ? array( $ctx['audience_ids'][ $i % count( $ctx['audience_ids'] ) ] ) : array(),
				'subject'         => 'Happy birthday, {{first_name}}!',
				'body'            => '<p>Everyone at {{site_name}} wishes you a wonderful day.</p>',
				'send_to_user'    => 0 === $i % 3 ? 0 : 1,
				'digest_enabled'  => 0 === $i % 3 ? 1 : 0,
				'digest_mode'     => 'detailed',
				'digest_user_ids' => array( $ctx['admin_id'] ),
				'is_active'       => 0 === $i % 5 ? 0 : 1,
			)
		);
		if ( is_wp_error( $rule ) ) {
			ffc_tseed_warn( 'date message rule', $rule );
			continue;
		}
		$id = call_user_func( array( $ns . 'RuleWriter', 'save' ), $rule );
		if ( is_wp_error( $id ) || ! $id ) {
			ffc_tseed_warn( 'date message rule', $id );
			continue;
		}
		$rules[] = (int) $id;
	}

	$log  = $ns . 'DeliveryLog';
	$uids = array_values( $ctx['user_ids'] );
	foreach ( $rules as $k => $rid ) {
		$day = gmdate( 'Y-m-d', time() - ( $k + 1 ) * DAY_IN_SECONDS );
		$run = call_user_func( array( $log, 'start_run' ), $rid, 0 === $k % 4 ? 'manual' : 'cron', $day, $day, 0 === $k % 4 ? $ctx['admin_id'] : 0 );
		if ( ! $run ) {
			continue;
		}
		for ( $u = 0; $u < 3; $u++ ) {
			if ( call_user_func( array( $log, 'claim' ), $run, $rid, $uids[ ( $k * 3 + $u ) % count( $uids ) ], $day ) ) {
				call_user_func( array( $log, 'bump' ), $run, 'sent' );
			}
		}
		call_user_func( array( $log, 'bump' ), $run, 'opted_out', $k % 2 );
		call_user_func( array( $log, 'bump' ), $run, 'no_email', $k % 3 );
		call_user_func( array( $log, 'finish_run' ), $run );
	}
	return $ctx;
}

/**
 * Activity log entries on top of the ones the writes above recorded.
 *
 * @param array<string, mixed> $ctx Context.
 */
function ffc_tseed_activity_log( array $ctx ): void {
	$log    = '\FreeFormCertificate\Core\ActivityLog';
	$uids   = array_values( $ctx['user_ids'] );
	$levels = array( $log::LEVEL_INFO, $log::LEVEL_WARNING, $log::LEVEL_ERROR, $log::LEVEL_DEBUG );
	for ( $i = 0; $i < 25; $i++ ) {
		switch ( $i % 5 ) {
			case 0:
				$log::log_settings_changed( 'general', $ctx['admin_id'] );
				break;
			case 1:
				$log::log_access_denied( 'invalid_token', 'seed-' . $i );
				break;
			case 2:
				$log::log_profile_updated( $uids[ $i % count( $uids ) ], array( 'phone' ) );
				break;
			case 3:
				$log::log_password_changed( $uids[ $i % count( $uids ) ] );
				break;
			default:
				$log::log( 'seed_note', $levels[ $i % 4 ], array( 'note' => 'Seeded entry ' . $i ), $ctx['admin_id'] );
		}
	}
}

/**
 * Per-table row counts.
 *
 * @param array<int, string> $tables Unprefixed table names.
 */
function ffc_tseed_summary( array $tables ): void {
	global $wpdb;
	foreach ( $tables as $name ) {
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . $name ) );
		printf( "%-40s %6s\n", $name, null === $count ? 'n/a' : (string) $count );
	}
	foreach ( array( 'ffc_form', 'ffc_self_scheduling', \FreeFormCertificate\Admin\CertTemplateCpt::POST_TYPE ) as $type ) {
		printf( "%-40s %6d\n", 'posts:' . $type, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_type = %s', $wpdb->posts, $type ) ) );
	}
	printf( "%-40s %6d\n", 'users:@example.test', count( ffc_tseed_demo_user_ids() ) );
}
