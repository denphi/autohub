<?php
/**
 * Record named migrations as applied without running them.
 *
 * The escape hatch for migrations that cannot succeed on this deployment.
 * `muse migration` only offers log-only mode for a single named extension, so
 * this drives Hubzero\Content\Migration directly.
 *
 * Reads a comma-separated list of migration filenames from HUB_SKIP_MIGRATIONS.
 */

$app = require __DIR__ . '/bootstrap-cli.php';

$root = getenv('HUB_ROOT') ?: '/var/www/html';
$skip = array_filter(array_map('trim', explode(',', (string) getenv('HUB_SKIP_MIGRATIONS'))));

if (!$skip)
{
	exit(0);
}

$migration = new Hubzero\Content\Migration();

if ($migration->find() === false)
{
	fwrite(STDERR, "[hub] ERROR: could not enumerate migrations\n");
	exit(1);
}

// Filenames already recorded, so re-running this is a no-op.
$already = array();

foreach ((array) $migration->history() as $row)
{
	$already[is_object($row) ? $row->file : $row['file']] = true;
}

$known   = array();
$skipped = 0;

foreach ($migration->get('files') as $path)
{
	$known[basename($path)] = $path;
}

foreach ($skip as $file)
{
	if (!isset($known[$file]))
	{
		fwrite(STDERR, "[hub] WARN: HUB_SKIP_MIGRATIONS names '{$file}', which does not exist\n");
		continue;
	}

	if (isset($already[$file]))
	{
		continue;
	}

	$scope = str_replace($root . DIRECTORY_SEPARATOR, '', dirname($known[$file]));

	if ($migration->recordMigration($file, $scope, hash('md5', $file), 'up'))
	{
		fwrite(STDERR, "[hub] skipping migration {$file} (recorded as applied)\n");
		$skipped++;
	}
	else
	{
		fwrite(STDERR, "[hub] ERROR: could not record {$file}\n");
		exit(1);
	}
}

exit(0);
