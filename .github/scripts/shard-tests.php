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
 * the wall-clock, so they are round-robined across shards FIRST (spreading the
 * slow ones evenly), then the in-process files are round-robined too. Ordering
 * is stable (sorted paths + fixed round-robin), so the partition is identical
 * on every runner and every re-run.
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

$isolated = array();
$inline   = array();
foreach ( $files as $file ) {
	$head = (string) file_get_contents( $file, false, null, 0, 4096 );
	// Match only real annotations (leading docblock tag), not prose mentions.
	if ( preg_match( '/^\s*\*\s*@run(Tests|Class)?InSeparateProcess(es)?\b/m', $head ) ) {
		$isolated[] = $file;
	} else {
		$inline[] = $file;
	}
}

$buckets = array_fill( 1, $total, array() );
$i       = 0;
foreach ( $isolated as $file ) {
	$buckets[ ( $i % $total ) + 1 ][] = $file;
	++$i;
}
$i = 0;
foreach ( $inline as $file ) {
	$buckets[ ( $i % $total ) + 1 ][] = $file;
	++$i;
}

$entries = '';
foreach ( $buckets[ $shard ] as $file ) {
	$rel      = 'tests/Unit/' . basename( $file );
	$entries .= "            <file>" . htmlspecialchars( $rel, ENT_XML1 ) . "</file>\n";
}

echo '<?xml version="1.0"?>' . "\n";
echo <<<XML
<phpunit
    bootstrap="tests/bootstrap.php"
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
            <directory suffix=".php">./includes</directory>
        </include>
        <exclude>
            <directory suffix=".php">./includes/libraries</directory>
            <directory suffix=".php">./includes/views</directory>
            <directory suffix=".php">./includes/admin/views</directory>
            <directory suffix=".php">./includes/settings/views</directory>
        </exclude>
    </coverage>
</phpunit>

XML;
