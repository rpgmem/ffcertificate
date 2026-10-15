<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\UpgradeStub;
use PHPUnit\Framework\TestCase;

/**
 * The `upgrade.php` stub has one writer, and it declares `dbDelta()` (#1803).
 *
 * Four activator tests used to write their own, empty, copy when the file
 * was missing. The bootstrap's write ran first, so theirs never did — but a
 * change to the bootstrap would have let an empty stub win the
 * `require_once`, leaving `dbDelta` undefined for every later test.
 *
 * @covers \FreeFormCertificate\Tests\Support\UpgradeStub
 */
class UpgradeStubOwnerTest extends TestCase {

	public function test_the_bootstrap_wrote_the_stub_the_activators_require(): void {
		$file = UpgradeStub::path( ABSPATH );

		$this->assertFileExists( $file );
		$this->assertSame( UpgradeStub::CONTENTS, file_get_contents( $file ) );
	}

	public function test_the_stub_declares_db_delta(): void {
		$tokens   = token_get_all( UpgradeStub::CONTENTS );
		$declared = array();
		$count    = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
				continue;
			}
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
					$declared[] = $tokens[ $j ][1];
					break;
				}
			}
		}

		$this->assertSame( array( 'dbDelta' ), $declared );
	}

	public function test_ensure_rewrites_a_stub_that_lacks_db_delta(): void {
		$root = sys_get_temp_dir() . '/ffc-upgrade-stub-' . getmypid() . '/';
		$file = UpgradeStub::path( $root );
		mkdir( dirname( $file ), 0777, true );
		file_put_contents( $file, "<?php\n// Stub for unit tests.\n" );

		try {
			$this->assertSame( $file, UpgradeStub::ensure( $root ) );
			$this->assertSame( UpgradeStub::CONTENTS, file_get_contents( $file ) );
		} finally {
			unlink( $file );
			rmdir( dirname( $file ) );
			rmdir( $root . 'wp-admin' );
			rmdir( $root );
		}
	}

	public function test_no_other_file_under_tests_writes_the_stub(): void {
		$tests   = dirname( __DIR__ );
		$own     = array(
			realpath( __FILE__ ),
			realpath( $tests . '/Support/UpgradeStub.php' ),
		);
		$writes  = '/\b(?:file_put_contents|fwrite|fopen|copy|rename|touch)\s*\(/';
		$scanned = 0;
		$writers = array();

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $tests, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $files as $info ) {
			if ( 'php' !== $info->getExtension() ) {
				continue;
			}
			++$scanned;
			$path = (string) $info->getRealPath();
			if ( in_array( $path, $own, true ) ) {
				continue;
			}
			$source = (string) file_get_contents( $path );
			if ( false !== strpos( $source, 'upgrade.php' ) && preg_match( $writes, $source ) ) {
				$writers[] = substr( $path, strlen( $tests ) + 1 );
			}
		}

		// Self-check: the scan reached every PHP file, counted by a second traversal.
		$this->assertSame( $this->count_php_files( $tests ), $scanned );
		$this->assertSame(
			array(),
			$writers,
			'Only tests/Support/UpgradeStub.php writes the upgrade.php stub; these files mention it and write files.'
		);
	}

	/**
	 * Counts PHP files with scandir(), independently of the scan above.
	 *
	 * @param string $dir Directory to count.
	 * @return int
	 */
	private function count_php_files( string $dir ): int {
		$count = 0;
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$count += $this->count_php_files( $path );
			} elseif ( '.php' === substr( $entry, -4 ) ) {
				++$count;
			}
		}
		return $count;
	}
}
