<?php
/**
 * Mark migrations that predate the bundled schema as already applied.
 *
 * core/bootstrap/Install/sql/mysql/schema.sql is a mysqldump taken at release
 * time, so replaying the ~1400 migrations that produced it would fail on
 * duplicate columns and existing tables. But the dump is NOT current: upstream
 * keeps committing migrations after cutting the release, and those DO need to
 * run. (#__ratelimit, added 2025-03-05, is missing from the 2.4.1 schema --
 * without it, every single page load fatals in RateLimitServiceProvider.)
 *
 * So this splits at the schema's build date: everything at or before it is
 * recorded as done, everything after is left pending for `hub-migrate`.
 *
 * The cutoff is HDATE from core/bin/muse -- upstream's own build timestamp,
 * regenerated alongside schema.sql -- so a future release moves it without
 * anyone editing this file. HUB_SCHEMA_CUTOFF (YYYYMMDDHHMMSS) overrides it.
 */

$app = require __DIR__ . '/bootstrap-cli.php';

$root = getenv('HUB_ROOT') ?: '/var/www/html';

/**
 * Resolve the timestamp that schema.sql corresponds to, as YYYYMMDDHHMMSS.
 */
$cutoff = getenv('HUB_SCHEMA_CUTOFF');

if (!$cutoff)
{
	$muse = @file_get_contents($root . '/core/bin/muse');

	if ($muse && preg_match("/HDATE'\s*,\s*'(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2}):(\d{2})'/", $muse, $m))
	{
		$cutoff = $m[1] . $m[2] . $m[3] . $m[4] . $m[5] . $m[6];
	}
}

if (!preg_match('/^\d{14}$/', (string) $cutoff))
{
	fwrite(STDERR, "[hub] ERROR: could not determine the schema build date.\n"
		. "      Set HUB_SCHEMA_CUTOFF=YYYYMMDDHHMMSS to the date schema.sql was dumped.\n");
	exit(1);
}

$migration = new Hubzero\Content\Migration();

if ($migration->find() === false)
{
	fwrite(STDERR, "[hub] ERROR: could not enumerate migrations\n");
	exit(1);
}

$baselined = 0;
$pending   = 0;
$failed    = 0;

foreach ($migration->get('files') as $path)
{
	$file = basename($path);

	if (!preg_match('/^Migration(\d{14})/', $file, $m))
	{
		continue;
	}

	// Newer than the schema dump: a real change that has to run for real.
	if ($m[1] > $cutoff)
	{
		$pending++;
		continue;
	}

	// Same scope string Migration::migrate() computes, so its "already run?"
	// lookup matches these rows.
	$scope = str_replace($root . DIRECTORY_SEPARATOR, '', dirname($path));

	if ($migration->recordMigration($file, $scope, hash('md5', $file), 'up'))
	{
		$baselined++;
	}
	else
	{
		$failed++;
	}
}

foreach ($migration->get('log') as $line)
{
	fwrite(STDERR, '[hub]   ' . $line['message'] . "\n");
}

if ($failed)
{
	fwrite(STDERR, "[hub] ERROR: {$failed} migration records could not be written\n");
	exit(1);
}

fwrite(STDERR, sprintf(
	"[hub] baselined %d migrations at or before %s; %d left to run\n",
	$baselined,
	preg_replace('/^(\d{4})(\d{2})(\d{2}).*/', '$1-$2-$3', $cutoff),
	$pending
));
