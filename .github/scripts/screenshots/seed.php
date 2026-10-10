<?php
/**
 * Seed a throwaway WordPress with fictitious data for the README screenshots.
 *
 * Usage: php .github/scripts/screenshots/seed.php /path/to/wp-load.php [manifest.json]
 *
 * Run it once, against an EMPTY install with the plugin active. Every person,
 * e-mail address and document number below is invented; the CPFs are generated
 * to pass the check digit and belong to nobody. Never point this at a site
 * with real data: it writes rows, creates users and changes settings.
 *
 * @package FreeFormCertificate
 */

// phpcs:ignoreFile -- Developer tooling run from the CLI against a throwaway install, never shipped.

if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) ) {
	fwrite( STDERR, "Usage: php seed.php /path/to/wp-load.php [manifest.json]\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
require $argv[1];
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/post.php';

wp_set_current_user( 1 );
mt_srand( 1660 );

// Nothing reaches a real inbox while seeding.
add_filter( 'pre_wp_mail', '__return_false' );

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

$people = array(
	'Ana Beatriz Souza',
	'Bruno Carvalho Lima',
	'Carla Mendes Rocha',
	'Daniel Ferreira Alves',
	'Eduarda Martins Costa',
	'Felipe Ribeiro Santos',
	'Gabriela Nunes Pereira',
	'Henrique Araújo Gomes',
	'Isabela Teixeira Dias',
	'João Pedro Cardoso',
	'Larissa Moreira Barros',
	'Marcos Vinícius Pinto',
	'Natália Correia Freitas',
	'Otávio Castro Monteiro',
	'Paula Fernandes Lopes',
	'Rafael Azevedo Ramos',
	'Sofia Cavalcanti Melo',
	'Thiago Duarte Vieira',
);

$schools = array( 'North Primary School', 'Riverside Middle School', 'Hillcrest High School', 'Lakeview Learning Center' );

// -----------------------------------------------------------------------------
// Site and plugin settings.
// -----------------------------------------------------------------------------
update_option( 'blogname', 'Example School Network' );
update_option( 'blogdescription', 'Training and events for our schools' );
update_option( 'timezone_string', 'America/Sao_Paulo' );
update_option( 'date_format', 'F j, Y' );
update_option( 'WPLANG', '' );

$settings              = (array) get_option( 'ffc_settings', array() );
$settings['dark_mode']   = 'off';
$settings['date_format'] = 'F j, Y';
update_option( 'ffc_settings', $settings );

// -----------------------------------------------------------------------------
// Certificate forms.
// -----------------------------------------------------------------------------
$layouts = array();
foreach ( array( 1, 2, 3 ) as $n ) {
	$layouts[ $n ] = (string) file_get_contents( FFC_PLUGIN_DIR . "templates/certificate-defaults/default_certificate_{$n}.html" );
}

$base_fields = array(
	array( 'type' => 'text', 'label' => 'Full Name', 'name' => 'name', 'required' => '1', 'options' => '' ),
	array( 'type' => 'email', 'label' => 'Email', 'name' => 'email', 'required' => '1', 'options' => '' ),
	array( 'type' => 'text', 'label' => 'CPF / ID', 'name' => 'cpf_rf', 'required' => '1', 'options' => '' ),
	array( 'type' => 'select', 'label' => 'School', 'name' => 'school', 'required' => '1', 'options' => implode( ', ', $schools ) ),
);

$forms = array(
	array( 'Digital Literacy Workshop 2026', 1, 12, 8 ),
	array( 'Inclusive Education: Practical Strategies', 2, 9, 3 ),
	array( 'Science Fair Volunteers', 3, 6, 0 ),
);

$form_ids = array();
foreach ( $forms as $f ) {
	list( $title, $layout, $count, $days_ago ) = $f;
	$id = wp_insert_post(
		array(
			'post_type'   => 'ffc_form',
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_date'   => wp_date( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS ),
		)
	);
	update_post_meta( $id, '_ffc_form_fields', $base_fields );
	update_post_meta(
		$id,
		'_ffc_form_config',
		array(
			'pdf_layout'         => $layouts[ $layout ],
			'bg_image'           => '',
			'enable_restriction' => '',
			'send_user_email'    => '1',
			'email_subject'      => 'Your certificate: {{form_title}}',
			'restrictions'       => array( 'password' => '0', 'allowlist' => '0', 'denylist' => '0', 'ticket' => '0' ),
			'quiz_enabled'       => '0',
		)
	);
	$form_ids[ $title ] = array( (int) $id, $count );
}

// -----------------------------------------------------------------------------
// Submissions (and, through them, the participants' accounts).
// -----------------------------------------------------------------------------
$handler = new \FreeFormCertificate\Submissions\SubmissionHandler();
$user_of = array();
$cursor  = 0;
foreach ( $form_ids as $title => $pair ) {
	list( $form_id, $count ) = $pair;
	$fields = get_post_meta( $form_id, '_ffc_form_fields', true );
	$config = get_post_meta( $form_id, '_ffc_form_config', true );
	for ( $i = 0; $i < $count; $i++ ) {
		$name  = $people[ $cursor % count( $people ) ];
		$email = strtolower( remove_accents( str_replace( ' ', '.', $name ) ) ) . '@example.org';
		++$cursor;
		$cpf  = $user_of[ $email ]['cpf'] ?? ffc_demo_cpf();
		$data = array(
			'name'             => $name,
			'email'            => $email,
			'cpf_rf'           => $cpf,
			'school'           => $schools[ $i % count( $schools ) ],
			'ffc_lgpd_consent' => '1',
		);
		$id = $handler->process_submission( $form_id, $title, $data, $email, $fields, $config );
		if ( is_wp_error( $id ) ) {
			fwrite( STDERR, 'submission: ' . $id->get_error_message() . "\n" );
			continue;
		}
		$user_of[ $email ] = array( 'cpf' => $cpf, 'name' => $name );
	}
}

// Spread the submissions over the last weeks, so the dashboard reads as a
// running programme rather than one burst.
global $wpdb;
$table = $wpdb->prefix . 'ffc_submissions';
foreach ( $wpdb->get_col( "SELECT id FROM {$table} ORDER BY id DESC" ) as $n => $sid ) {
	// `submission_date` is a unix timestamp (Category A); the first few land today.
	$wpdb->update( $table, array( 'submission_date' => time() - ( $n * 29 + 1 ) * HOUR_IN_SECONDS ), array( 'id' => $sid ), array( '%d' ), array( '%d' ) );
}

// Birthdays on the accounts the submissions created, a few in the coming days.
$offset = 0;
foreach ( $user_of as $email => $info ) {
	$user = get_user_by( 'email', $email );
	if ( ! $user ) {
		continue;
	}
	wp_update_user( array( 'ID' => $user->ID, 'display_name' => $info['name'] ) );
	$year = 1975 + ( $offset * 3 ) % 25;
	$date = gmdate( 'm-d', time() + ( $offset * 2 + 1 ) * DAY_IN_SECONDS );
	\FreeFormCertificate\UserDashboard\UserManager::update_extended_profile( $user->ID, array( 'birth_date' => "{$year}-{$date}" ) );
	++$offset;
}

// -----------------------------------------------------------------------------
// Public pages.
// -----------------------------------------------------------------------------
$first_form = reset( $form_ids )[0];
$pages      = array(
	'Get your certificate' => '[ffc_form id="' . $first_form . '"]',
);

// -----------------------------------------------------------------------------
// Personal calendar with appointments.
// -----------------------------------------------------------------------------
$cal_post = wp_insert_post(
	array(
		'post_type'   => 'ffc_self_scheduling',
		'post_status' => 'draft',
		'post_title'  => 'Pedagogical Coordination: Office Hours',
	)
);
update_post_meta(
	$cal_post,
	'_ffc_self_scheduling_config',
	array(
		'description'                       => 'Book a 30-minute meeting with the pedagogical coordination team.',
		'slot_duration'                     => 30,
		'slot_interval'                     => 0,
		'slots_per_day'                     => 0,
		'max_appointments_per_slot'         => 1,
		'advance_booking_min'               => 0,
		'advance_booking_max'               => 45,
		'allow_cancellation'                => 1,
		'cancellation_min_hours'            => 12,
		'minimum_interval_between_bookings' => 0,
		'requires_approval'                 => 0,
		'status'                            => 'active',
		'waitlist_enabled'                  => 0,
		'waitlist_capacity'                 => 0,
		'max_blocks_per_user'               => 0,
		'schedule_type'                     => 'regular',
		'visibility'                        => 'public',
		'scheduling_visibility'             => 'public',
		'restrict_viewing_to_hours'         => 0,
		'restrict_booking_to_hours'         => 0,
		'admin_bypass'                      => 1,
	)
);
$hours = array();
foreach ( array( 1, 2, 3, 4, 5 ) as $day ) {
	$hours[] = array( 'day' => $day, 'start' => '09:00', 'end' => '12:00' );
	$hours[] = array( 'day' => $day, 'start' => '14:00', 'end' => '17:00' );
}
update_post_meta( $cal_post, '_ffc_self_scheduling_working_hours', $hours );
wp_update_post( array( 'ID' => $cal_post, 'post_status' => 'publish' ) );

$calendar = ( new \FreeFormCertificate\Repositories\CalendarRepository() )->findByPostId( (int) $cal_post );
if ( $calendar ) {
	$appointments = new \FreeFormCertificate\SelfScheduling\AppointmentHandler();
	$slots        = array( '09:00:00', '10:30:00', '14:00:00', '15:30:00', '11:00:00', '16:00:00' );
	$day          = 1;
	$booked       = 0;
	foreach ( array_slice( $user_of, 0, 10, true ) as $email => $info ) {
		do {
			$date = gmdate( 'Y-m-d', time() + $day * DAY_IN_SECONDS );
			++$day;
		} while ( in_array( (int) gmdate( 'N', strtotime( $date ) ), array( 6, 7 ), true ) );
		$r = $appointments->process_appointment(
			array(
				'calendar_id'      => (int) $calendar['id'],
				'appointment_date' => $date,
				'start_time'       => $slots[ $booked % count( $slots ) ],
				'name'             => $info['name'],
				'email'            => $email,
				'cpf_rf'           => $info['cpf'],
				'phone'            => '(11) 9' . mt_rand( 1000, 9999 ) . '-' . mt_rand( 1000, 9999 ),
				'consent_given'    => 1,
				'user_id'          => (int) get_user_by( 'email', $email )->ID,
			)
		);
		if ( is_wp_error( $r ) ) {
			fwrite( STDERR, 'appointment: ' . $r->get_error_message() . "\n" );
		}
		++$booked;
	}
	$pages['Book a meeting'] = '[ffc_self_scheduling id="' . $cal_post . '"]';
}

// -----------------------------------------------------------------------------
// Audience calendars: groups, a room schedule and bookings.
// -----------------------------------------------------------------------------
use FreeFormCertificate\Audience\AudienceWriter;
use FreeFormCertificate\Audience\AudienceBookingWriter;
use FreeFormCertificate\Audience\AudienceEnvironmentRepository;
use FreeFormCertificate\Audience\AudienceScheduleRepository;

$teachers  = AudienceWriter::create( array( 'name' => 'Teachers', 'color' => '#2563eb' ) );
$science   = AudienceWriter::create( array( 'name' => 'Science Department', 'color' => '#059669', 'parent_id' => $teachers ) );
$languages = AudienceWriter::create( array( 'name' => 'Languages Department', 'color' => '#d97706', 'parent_id' => $teachers ) );
$staff     = AudienceWriter::create( array( 'name' => 'School Staff', 'color' => '#7c3aed' ) );

$ids = array();
foreach ( $user_of as $email => $info ) {
	$ids[] = (int) get_user_by( 'email', $email )->ID;
}
AudienceWriter::bulk_add_members( (int) $science, array_slice( $ids, 0, 6 ) );
AudienceWriter::bulk_add_members( (int) $languages, array_slice( $ids, 6, 6 ) );
AudienceWriter::bulk_add_members( (int) $staff, array_slice( $ids, 12 ) );

$schedule = AudienceScheduleRepository::create(
	array(
		'name'              => 'Shared Rooms',
		'description'       => 'Rooms any department can book for meetings and classes.',
		'environment_label' => 'Room',
		'visibility'        => 'public',
	)
);
$week_hours = array();
foreach ( array( 'mon', 'tue', 'wed', 'thu', 'fri' ) as $d ) {
	$week_hours[ $d ] = array( 'start' => '07:00', 'end' => '19:00', 'closed' => false );
}
foreach ( array( 'sat', 'sun' ) as $d ) {
	$week_hours[ $d ] = array( 'start' => '', 'end' => '', 'closed' => true );
}
$rooms = array(
	AudienceEnvironmentRepository::create( array( 'schedule_id' => $schedule, 'name' => 'Auditorium', 'color' => '#2563eb', 'working_hours' => $week_hours ) ),
	AudienceEnvironmentRepository::create( array( 'schedule_id' => $schedule, 'name' => 'Science Lab', 'color' => '#059669', 'working_hours' => $week_hours ) ),
	AudienceEnvironmentRepository::create( array( 'schedule_id' => $schedule, 'name' => 'Library', 'color' => '#d97706', 'working_hours' => $week_hours ) ),
);
$events = array(
	array( 'Department meeting', $teachers, '08:00:00', '09:30:00' ),
	array( 'Lab safety training', $science, '10:00:00', '11:30:00' ),
	array( 'Reading club', $languages, '14:00:00', '15:00:00' ),
	array( 'Staff briefing', $staff, '07:30:00', '08:00:00' ),
	array( 'Science fair rehearsal', $science, '15:00:00', '17:00:00' ),
	array( 'Parent-teacher meeting', $teachers, '18:00:00', '19:00:00' ),
);
$month_start = strtotime( gmdate( 'Y-m-01' ) );
for ( $d = 0, $e = 0; $d < 28; $d += 2 ) {
	$date = gmdate( 'Y-m-d', $month_start + $d * DAY_IN_SECONDS );
	if ( (int) gmdate( 'N', strtotime( $date ) ) > 5 ) {
		continue;
	}
	$ev  = $events[ $e % count( $events ) ];
	$bid = AudienceBookingWriter::create(
		array(
			'environment_id' => $rooms[ $e % count( $rooms ) ],
			'booking_date'   => $date,
			'start_time'     => $ev[2],
			'end_time'       => $ev[3],
			'description'    => $ev[0],
		)
	);
	if ( $bid ) {
		AudienceBookingWriter::set_booking_audiences( (int) $bid, array( (int) $ev[1] ) );
	}
	++$e;
}
$pages['Room calendar'] = '[ffc_audience schedule_id="' . $schedule . '"]';

// -----------------------------------------------------------------------------
// Date Messages: a birthday card with background artwork.
// -----------------------------------------------------------------------------
$art = ffc_demo_card_art();
$aid = 0;
if ( '' !== $art ) {
	$upload = wp_upload_bits( 'birthday-card.png', null, $art );
	if ( empty( $upload['error'] ) ) {
		$aid = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Birthday card', 'post_status' => 'inherit' ), $upload['file'] );
		wp_update_attachment_metadata( $aid, wp_generate_attachment_metadata( $aid, $upload['file'] ) );
	}
}

$rule = \FreeFormCertificate\DateMessages\Rule::from_array(
	array(
		'name'         => 'Birthday greeting',
		'source'       => 'birthday',
		'offset_days'  => 0,
		'audience_ids' => array( (int) $teachers, (int) $staff ),
		'subject'      => 'Happy birthday, {{first_name}}!',
		'body'         => '<h2>Happy birthday, {{first_name}}!</h2><p>Everyone at {{site_name}} wishes you a wonderful day and a great year ahead.</p><p>Thank you for everything you do for our students.</p>',
		'appearance'   => array(
			'image_id'       => $aid,
			'fallback_color' => '#fff3e8',
			'text_color'     => '#3b2316',
			'position'       => 'left',
			'text_width'     => 60,
		),
		'send_to_user' => 1,
		'is_active'    => 1,
	)
);
if ( ! is_wp_error( $rule ) ) {
	\FreeFormCertificate\DateMessages\RuleWriter::save( $rule );
}
$reminder = \FreeFormCertificate\DateMessages\Rule::from_array(
	array(
		'name'           => 'Birthday reminder for coordinators',
		'source'         => 'birthday',
		'offset_days'    => -7,
		'subject'        => 'Birthdays next week',
		'body'           => '<p>{{name}} has a birthday on {{date}}.</p>',
		'send_to_user'   => 0,
		'digest_enabled' => 1,
		'digest_mode'    => 'detailed',
		'digest_user_ids' => array( 1 ),
		'is_active'      => 1,
	)
);
if ( ! is_wp_error( $reminder ) ) {
	\FreeFormCertificate\DateMessages\RuleWriter::save( $reminder );
}

// -----------------------------------------------------------------------------
// Short URLs.
// -----------------------------------------------------------------------------
$shortener = new \FreeFormCertificate\UrlShortener\UrlShortenerService();
foreach ( array(
	array( 'https://example.org/events/digital-literacy-2026', 'Digital Literacy Workshop registration' ),
	array( 'https://example.org/forms/science-fair-volunteers', 'Science fair volunteers' ),
	array( 'https://example.org/news/school-calendar-2026', 'School calendar 2026' ),
) as $u ) {
	$shortener->create_short_url( $u[0], $u[1] );
}

// -----------------------------------------------------------------------------
// Pages last, so every shortcode they carry points at something real.
// -----------------------------------------------------------------------------
// The block theme's content column is narrow; a wide group gives the plugin's
// screens the room they have on a real site.
$wide = static function ( string $content ): string {
	return '<!-- wp:group {"align":"wide","layout":{"type":"constrained","contentSize":"1100px"}} --><div class="wp-block-group alignwide">'
		. '<!-- wp:shortcode -->' . $content . '<!-- /wp:shortcode --></div><!-- /wp:group -->';
};
$page_urls = array();
foreach ( $pages as $title => $content ) {
	$pid                 = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $wide( $content ) ) );
	$page_urls[ $title ] = get_permalink( $pid );
}
// The two pages the plugin creates on activation get the same treatment.
foreach ( array( 'ffc_verification_page_id', 'ffc_dashboard_page_id' ) as $option ) {
	$pid = (int) get_option( $option );
	if ( $pid ) {
		$page = get_post( $pid );
		wp_update_post( array( 'ID' => $pid, 'post_content' => $wide( trim( wp_strip_all_tags( $page->post_content ) ) ) ) );
	}
}
// WordPress's own sample content would sit in the menu next to them.
foreach ( array( 'sample-page' => 'page', 'hello-world' => 'post' ) as $slug => $type ) {
	$sample = get_page_by_path( $slug, OBJECT, $type );
	if ( $sample ) {
		wp_delete_post( $sample->ID, true );
	}
}
flush_rewrite_rules();

// One participant signs in for the user-dashboard shot.
$participant = get_user_by( 'email', array_key_first( $user_of ) );
wp_set_password( 'demo-participant', $participant->ID );

// What the capture script needs to find its screens.
$token       = $wpdb->get_var( $wpdb->prepare( 'SELECT magic_token FROM %i WHERE user_id = %d ORDER BY id LIMIT 1', $table, $participant->ID ) );
$manifest    = array(
	'form_id'          => $first_form,
	'calendar_post_id' => (int) $cal_post,
	'rule_id'          => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(id) FROM %i', $wpdb->prefix . 'ffc_date_message_rules' ) ),
	'participant'      => array( 'login' => $participant->user_login, 'password' => 'demo-participant' ),
	'certificate_url'  => add_query_arg( 'token', $token, get_permalink( (int) get_option( 'ffc_verification_page_id' ) ) ),
	'dashboard_url'    => get_permalink( (int) get_option( 'ffc_dashboard_page_id' ) ),
	'pages'            => $page_urls,
);
if ( ! empty( $argv[2] ) ) {
	file_put_contents( $argv[2], wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
}

echo "Seeded.\n";

/**
 * Artwork for the birthday card: balloons and confetti on the right, plain
 * on the left where the text sits. Drawn here so no third-party image ships.
 *
 * @return string PNG bytes, or '' without GD.
 */
function ffc_demo_card_art(): string {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		return '';
	}
	$w  = 1200;
	$h  = 720;
	$im = imagecreatetruecolor( $w, $h );
	imagefill( $im, 0, 0, imagecolorallocate( $im, 255, 243, 232 ) );
	$palette = array( array( 239, 68, 68 ), array( 245, 158, 11 ), array( 16, 185, 129 ), array( 59, 130, 246 ), array( 168, 85, 247 ) );
	for ( $i = 0; $i < 140; $i++ ) {
		$c = $palette[ $i % 5 ];
		$x = mt_rand( 640, $w );
		$y = mt_rand( 0, $h );
		imagefilledrectangle( $im, $x, $y, $x + mt_rand( 6, 14 ), $y + mt_rand( 3, 6 ), imagecolorallocatealpha( $im, $c[0], $c[1], $c[2], 30 ) );
	}
	$balloons = array( array( 860, 240, 0 ), array( 1000, 190, 3 ), array( 940, 330, 1 ), array( 1100, 300, 4 ), array( 780, 360, 2 ) );
	foreach ( $balloons as $b ) {
		list( $cx, $cy, $ci ) = $b;
		$c    = $palette[ $ci ];
		$line = imagecolorallocate( $im, 120, 90, 70 );
		imagesetthickness( $im, 2 );
		imageline( $im, $cx, $cy + 70, $cx - 20 + $ci * 8, 700, $line );
		imagefilledellipse( $im, $cx, $cy, 120, 150, imagecolorallocate( $im, $c[0], $c[1], $c[2] ) );
		imagefilledellipse( $im, $cx - 22, $cy - 35, 26, 36, imagecolorallocatealpha( $im, 255, 255, 255, 70 ) );
		imagefilledpolygon( $im, array( $cx - 8, $cy + 78, $cx + 8, $cy + 78, $cx, $cy + 68 ), imagecolorallocate( $im, $c[0], $c[1], $c[2] ) );
	}
	ob_start();
	imagepng( $im );
	return (string) ob_get_clean();
}
