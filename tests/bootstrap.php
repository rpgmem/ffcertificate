<?php
/**
 * PHPUnit bootstrap file for FFCertificate unit tests.
 *
 * Unit tests run WITHOUT WordPress loaded. We mock WP functions
 * via Brain\Monkey so tests are fast and isolated.
 *
 * @package FreeFormCertificate\Tests
 */

// Composer autoloader.
$autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! file_exists( $autoloader ) ) {
    echo "Run 'composer install' before running tests.\n";
    exit( 1 );
}

require_once $autoloader;

// Define WordPress constants BEFORE loading plugin files
// (the FFC autoloader calls exit() if ABSPATH is not defined).
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
    define( 'FFC_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'FFC_PLUGIN_URL' ) ) {
    define( 'FFC_PLUGIN_URL', 'https://example.com/wp-content/plugins/ffcertificate/' );
}
if ( ! defined( 'FFC_VERSION' ) ) {
    // Extract version from main plugin file to keep a single source of truth.
    $plugin_contents = file_get_contents( dirname( __DIR__ ) . '/ffcertificate.php' );
    if ( preg_match( "/define\(\s*'FFC_VERSION',\s*'([^']+)'/", $plugin_contents, $version_match ) ) {
        define( 'FFC_VERSION', $version_match[1] );
    } else {
        define( 'FFC_VERSION', 'dev' );
    }
}
if ( ! defined( 'DB_NAME' ) ) {
    define( 'DB_NAME', 'test_db' );
}
if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'OBJECT_K' ) ) {
    define( 'OBJECT_K', 'OBJECT_K' );
}

// WordPress cryptographic constants needed for Encryption::is_configured()
if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
    define( 'SECURE_AUTH_KEY', 'test-secure-auth-key-for-unit-tests-only-32ch' );
}
if ( ! defined( 'LOGGED_IN_KEY' ) ) {
    define( 'LOGGED_IN_KEY', 'test-logged-in-key-for-unit-tests-only-32char' );
}
if ( ! defined( 'AUTH_KEY' ) ) {
    define( 'AUTH_KEY', 'test-auth-key-for-unit-tests' );
}
if ( ! defined( 'NONCE_KEY' ) ) {
    define( 'NONCE_KEY', 'test-nonce-key-for-unit-tests' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

// Stub WP_Query for the obsolete shortcode cleaner (and any other test
// instantiating a WP_Query directly). Consumers seed a FIFO queue in
// `$GLOBALS['ffc_test_wp_query_queue']` — each constructor invocation pops
// the next result array into `->posts`. Constructor args are logged in
// `$GLOBALS['ffc_test_wp_query_calls']` for assertion.
if ( ! class_exists( 'WP_Query' ) ) {
    class WP_Query {
        /** @var array<int, mixed> */
        public $posts = array();

        /** @param array<string, mixed> $args */
        public function __construct( $args = array() ) {
            if ( ! isset( $GLOBALS['ffc_test_wp_query_calls'] ) || ! is_array( $GLOBALS['ffc_test_wp_query_calls'] ) ) {
                $GLOBALS['ffc_test_wp_query_calls'] = array();
            }
            $GLOBALS['ffc_test_wp_query_calls'][] = $args;

            if ( ! isset( $GLOBALS['ffc_test_wp_query_queue'] ) || ! is_array( $GLOBALS['ffc_test_wp_query_queue'] ) || empty( $GLOBALS['ffc_test_wp_query_queue'] ) ) {
                $this->posts = array();
                return;
            }
            $this->posts = array_shift( $GLOBALS['ffc_test_wp_query_queue'] );
        }
    }
}

// Stub WP_REST_Server class for REST controller tests.
if ( ! class_exists( 'WP_REST_Server' ) ) {
    class WP_REST_Server {
        const READABLE  = 'GET';
        const CREATABLE = 'POST';
        const EDITABLE  = 'POST, PUT, PATCH';
        const DELETABLE = 'DELETE';
    }
}

// Stub WP_Error class for unit tests (WordPress is not loaded).
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        protected $code;
        protected $message;
        protected $data;

        public function __construct( $code = '', $message = '', $data = '' ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code() {
            return $this->code;
        }

        public function get_error_message() {
            return $this->message;
        }

        public function get_error_data() {
            return $this->data;
        }
    }
}

// Stub WP_REST_Response class for unit tests (used by audience REST controller).
if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public $data;
        public $status;
        public $headers = array();

        public function __construct( $data = null, $status = 200, $headers = array() ) {
            $this->data    = $data;
            $this->status  = $status;
            $this->headers = $headers;
        }

        public function get_data() {
            return $this->data;
        }

        public function get_status() {
            return $this->status;
        }

        public function get_headers() {
            return $this->headers;
        }
    }
}

// Stub WP_Role class for unit tests.
if ( ! class_exists( 'WP_Role' ) ) {
    class WP_Role {
        public $capabilities = array();

        public function add_cap( $cap, $grant = true ) {
            $this->capabilities[ $cap ] = $grant;
        }

        public function remove_cap( $cap ) {
            unset( $this->capabilities[ $cap ] );
        }
    }
}

// Stub WP_User class for unit tests.
if ( ! class_exists( 'WP_User' ) ) {
    class WP_User {
        public $ID = 0;
        public $user_login = '';
        public $user_email = '';
        public $user_pass = '';
        public $display_name = '';
        public $user_registered = '';
        public $roles = array();
        public $caps = array();

        public function __construct( $id = 0 ) {
            $this->ID = $id;
        }

        public function has_cap( $cap ) {
            return ! empty( $this->caps[ $cap ] );
        }

        public function add_cap( $cap, $grant = true ) {
            $this->caps[ $cap ] = $grant;
        }

        public function remove_cap( $cap ) {
            unset( $this->caps[ $cap ] );
        }

        public function add_role( $role ) {
            $this->roles[] = $role;
        }

        public function set_role( $role ) {
            $this->roles = array( $role );
        }
    }
}

// Create stub for wp-admin/includes/upgrade.php used by activators.
// The activator classes call require_once ABSPATH . 'wp-admin/includes/upgrade.php'
// which needs to exist (even as a no-op) so the require_once doesn't fatal.
$wp_admin_upgrade_dir = ABSPATH . 'wp-admin/includes';
if ( ! is_dir( $wp_admin_upgrade_dir ) ) {
    mkdir( $wp_admin_upgrade_dir, 0777, true );
}
$wp_admin_upgrade_file = $wp_admin_upgrade_dir . '/upgrade.php';
if ( ! file_exists( $wp_admin_upgrade_file ) ) {
    file_put_contents(
        $wp_admin_upgrade_file,
        "<?php\n// Stub for unit tests.\nif ( ! function_exists( 'dbDelta' ) ) {\n    function dbDelta( \$queries = '', \$execute = true ) { return array(); }\n}\n"
    );
}

// Stub WP_List_Table for tests that extend it (e.g. SubmissionsList).
// The real class lives in wp-admin/includes/class-wp-list-table.php and is
// loaded via require_once when WordPress is available, but unit tests run
// without WordPress, so we provide a minimal parent class.
if ( ! class_exists( 'WP_List_Table' ) ) {
    class WP_List_Table {
        protected $_args = array();
        protected $items = array();
        protected $_column_headers = array();
        protected $_pagination_args = array();

        public function __construct( $args = array() ) {
            $this->_args = $args;
        }

        public function prepare_items() {}
        public function display() {}
        public function get_columns() { return array(); }
        protected function get_sortable_columns() { return array(); }
        protected function get_bulk_actions() { return array(); }
        protected function set_pagination_args( $args ) { $this->_pagination_args = $args; }
        public function get_pagenum() { return 1; }
        public function has_items() { return ! empty( $this->items ); }
        public function no_items() { echo 'No items found.'; }
    }
}

// Register the plugin's own PSR-4 autoloader (WordPress file naming conventions).
require_once dirname( __DIR__ ) . '/includes/class-ffc-autoloader.php';
$ffc_autoloader = new \FFC_Autoloader( dirname( __DIR__ ) . '/includes' );
$ffc_autoloader->register();
