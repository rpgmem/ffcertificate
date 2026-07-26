<?php
/**
 * Emit a PHPUnit configuration for one CI coverage shard.
 *
 * The Unit test files are partitioned into N shards; this prints a PHPUnit XML
 * config whose <testsuite> lists only the current shard's files (as explicit
 * <file> entries — passing many paths on the CLI is unreliable, PHPUnit 9 only
 * honours the first). The <coverage> include/exclude mirrors phpunit.xml.dist
 * exactly, so each shard's coverage uses the same source scope and the merged
 * clover denominator matches a single-process run.
 *
 * Usage:  php .github/scripts/shard-tests.php <shardIndex 1-based> <shardTotal> > phpunit.shard-N.xml
 *
 * Balancing: process-isolated classes (@runTestsInSeparateProcesses /
 * @runClassInSeparateProcess) fork a PHP process per test method and dominate
 * the wall-clock, so a file's cost is ~proportional to its test-method count,
 * not to the file itself. We therefore weight each file by its test-method
 * count and pack the shards with a greedy longest-processing-time-first (LPT)
 * bin-packer instead of a flat round-robin: isolated files are packed FIRST
 * (heaviest first, each onto the currently-lightest shard) so the slow forks
 * spread evenly, then the in-process files are packed the same way into the
 * running totals. This drops the isolated-method spread across shards from
 * ~31% (round-robin by file count, which treated a 129-method file the same as
 * a 3-method one) to ~0%, so the coverage job finishes closer to the ideal
 * max(shard) wall-clock (#809). Ordering is fully deterministic — files sort by
 * (weight desc, path) and ties for the lightest shard break to the lowest
 * index — so the partition is identical on every runner and every re-run.
 */

if ( $argc < 3 ) {
	fwrite( STDERR, "usage: shard-tests.php <shardIndex> <shardTotal>\n" );
	exit( 2 );
}

$shard = (int) $argv[1];
$total = (int) $argv[2];

if ( $shard < 1 || $total < 1 || $shard > $total ) {
	fwrite( STDERR, "invalid shard/total: {$shard}/{$total}\n" );
	exit( 2 );
}

$root  = dirname( __DIR__, 2 );
$files = glob( $root . '/tests/Unit/*Test.php' );
sort( $files );

/**
 * Weight a test file by its cost proxy: the number of test methods it runs.
 * For an isolated file this equals the number of forked PHP processes; for an
 * in-process file it is the number of test cases. `max( 1, … )` keeps a file
 * with no directly-matched method (e.g. all data-provider driven) as a unit of
 * work rather than weightless.
 */
$weight_of = static function ( $src ) {
	$methods  = preg_match_all( '/function\s+test\w*\s*\(/', $src );
	$methods += preg_match_all( '/^\s*\*\s*@test\b/m', $src );
	return max( 1, (int) $methods );
};

$isolated = array();
$inline   = array();
foreach ( $files as $file ) {
	$src  = (string) file_get_contents( $file );
	$head = substr( $src, 0, 4096 );
	$rec  = array(
		'file' => $file,
		'w'    => $weight_of( $src ),
	);
	// Match only real annotations (leading docblock tag), not prose mentions.
	if ( preg_match( '/^\s*\*\s*@run(Tests|Class)?InSeparateProcess(es)?\b/m', $head ) ) {
		$isolated[] = $rec;
	} else {
		$inline[] = $rec;
	}
}

$buckets = array_fill( 1, $total, array() );
$load    = array_fill( 1, $total, 0 );

/**
 * Greedy longest-processing-time-first packer: assign each item (heaviest
 * first) to the currently-lightest shard. Sorting by (weight desc, path)
 * and breaking the lightest-shard tie to the lowest index makes the whole
 * partition deterministic across runners.
 */
$pack = static function ( array $items ) use ( &$buckets, &$load, $total ) {
	usort(
		$items,
		static function ( $a, $b ) {
			return $b['w'] <=> $a['w'] ?: strcmp( $a['file'], $b['file'] );
		}
	);
	foreach ( $items as $it ) {
		$min = 1;
		for ( $s = 2; $s <= $total; $s++ ) {
			if ( $load[ $s ] < $load[ $min ] ) {
				$min = $s;
			}
		}
		$buckets[ $min ][] = $it['file'];
		$load[ $min ]     += $it['w'];
	}
};

// Pack the wall-clock-dominating isolated files first, then the in-process ones.
$pack( $isolated );
$pack( $inline );

// Emit each shard's files in natural (sorted-path) order regardless of the
// packing order, so a shard's within-run test order stays stable.
$mine = $buckets[ $shard ];
sort( $mine );

// Emit absolute paths so the generated config is location-independent: it can
// live in a temp dir and every separate-process test child resolves bootstrap,
// files and coverage dirs the same way regardless of CWD.
$entries = '';
foreach ( $mine as $file ) {
	$abs      = $root . '/tests/Unit/' . basename( $file );
	$entries .= "            <file>" . htmlspecialchars( $abs, ENT_XML1 ) . "</file>\n";
}
$bootstrap = htmlspecialchars( $root . '/tests/bootstrap.php', ENT_XML1 );
$inc       = htmlspecialchars( $root . '/includes', ENT_XML1 );

echo '<?xml version="1.0"?>' . "\n";
echo <<<XML
<phpunit
    bootstrap="{$bootstrap}"
    backupGlobals="false"
    colors="true"
    convertErrorsToExceptions="true"
    convertNoticesToExceptions="true"
    convertWarningsToExceptions="true"
    >
    <testsuites>
        <testsuite name="shard-{$shard}">
{$entries}        </testsuite>
    </testsuites>
    <coverage>
        <include>
            <directory suffix=".php">{$inc}</directory>
        </include>
        <exclude>
            <directory suffix=".php">{$inc}/libraries</directory>
            <directory suffix=".php">{$inc}/views</directory>
            <directory suffix=".php">{$inc}/admin/views</directory>
            <directory suffix=".php">{$inc}/settings/views</directory>
        </exclude>
    </coverage>
</phpunit>

XML;
