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
    define( 'FFC_VERSION', '4.12.6' );
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

// Stub WP_REST_Server class for REST controller tests.
if ( ! class_exists( 'WP_REST_Server' ) ) {
    class WP_REST_Server {
        const READABLE  = 'GET';
        const CREATABLE = 'POST';
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

// Stub WP_Role class for unit tests.
if ( ! class_exists( 'WP_Role' ) ) {
    class WP_Role {
        public $capabilities = array();

        public function add_cap( $cap, $grant = true ) {
            $this->capabilities[ $cap ] = $grant;
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

        public function add_role( $role ) {
            $this->roles[] = $role;
        }

        public function set_role( $role ) {
            $this->roles = array( $role );
        }
    }
}

// Register the plugin's own PSR-4 autoloader (WordPress file naming conventions).
require_once dirname( __DIR__ ) . '/includes/class-ffc-autoloader.php';
$ffc_autoloader = new \FFC_Autoloader( dirname( __DIR__ ) . '/includes' );
$ffc_autoloader->register();
