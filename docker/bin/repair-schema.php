<?php
/**
 * Reconcile the database with the code, by replaying the migrations whose
 * effects are missing.
 *
 * Upstream does not support installing the CMS from the git repository alone --
 * their Debian/RedHat packages own database setup -- and it shows.
 * core/bootstrap/Install/sql/mysql/{schema,data}.sql are a dump of some older
 * hub and they lag the migrations badly: missing tables, missing columns, and
 * an #__extensions table that still lists plugins renamed years ago. Left
 * alone, the result is a hub where nobody can log in.
 *
 * Neither blunt instrument works. Replaying every migration trips a PHP 8
 * fatal in Hubzero\Content\Migration\Macros\SavePluginParams (an incompatible
 * __invoke signature) that cannot be caught, and `muse migration -f` aborts on
 * the first error regardless. So this replays a targeted subset, worked out
 * from the migrations themselves rather than from a hand-written list:
 *
 *   Phase 1  migrations that CREATE a table the database does not have,
 *            skipping tables a later migration deliberately dropped
 *   Phase 2  migrations that ADD a column the database does not have
 *   Phase 3  every extension's own migrations -- an extension's migrations
 *            directory *is* its install script, per HUBzero's documentation,
 *            and it is the only place some columns are defined (the `secret`
 *            column on #__users, which plg_user_hubzero writes on every login,
 *            is declared as a PHP array there rather than as literal SQL)
 *
 * Everything runs in one chronological pass, because migrations are written to
 * apply in order from any earlier state. Failures are tolerated per migration:
 * one unused component must not stop the ones that matter.
 *
 * Safe to re-run; the migrations involved are guarded with tableExists() /
 * tableHasField() checks.
 */

$app = require __DIR__ . '/bootstrap-cli.php';

$db      = App::get('db');
$root    = getenv('HUB_ROOT') ?: '/var/www/html';
$prefix  = $app['config']->get('dbprefix', 'jos_');
$verbose = getenv('HUB_VERBOSE_REPAIR') === '1';

/**
 * Current tables and their columns, keyed without the table prefix.
 */
$schema = array();

foreach ((array) $db->getTableList() as $table)
{
	if (strpos($table, $prefix) !== 0)
	{
		continue;
	}

	$name = substr($table, strlen($prefix));
	$schema[$name] = array();

	foreach (array_keys((array) $db->getTableColumns($table, true)) as $column)
	{
		$schema[$name][strtolower($column)] = true;
	}
}

$migration = new Hubzero\Content\Migration();

if ($migration->find() === false)
{
	fwrite(STDERR, "[hub] ERROR: could not enumerate migrations\n");
	exit(1);
}

$files = $migration->get('files');

// Sort by the timestamp in the filename so "later" really means later.
usort($files, function ($a, $b)
{
	return strcmp(basename($a), basename($b));
});

// Words that follow ADD but are not column names.
$notColumns = array('key', 'index', 'unique', 'primary', 'constraint', 'fulltext', 'spatial', 'foreign', 'column');

$queue         = array();
$createdBy     = array();  // table => migration paths that create it
$droppedTable  = array();  // table => basename of the last migration to drop it
$droppedColumn = array();  // "table.column" => basename of the last migration to drop it
$addedBy       = array();  // "table.column" => migration paths that add it

foreach ($files as $path)
{
	$source = @file_get_contents($path);

	if ($source === false)
	{
		continue;
	}

	// Loading this macro is an uncatchable fatal on PHP 8.
	if (strpos($source, 'savePluginParams') !== false)
	{
		continue;
	}

	// --- Phase 3: extension install scripts ---------------------------------
	// core/migrations holds the CMS's own history and must not be replayed
	// wholesale; core/{plugins,components,modules}/*/migrations are per-
	// extension installers and are meant to be idempotent.
	if (preg_match('#/core/(plugins|components|modules)/#', $path))
	{
		$queue[$path] = true;
	}

	if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?#__([a-z0-9_]+)`?/i', $source, $m))
	{
		foreach (array_unique($m[1]) as $table)
		{
			$createdBy[strtolower($table)][] = $path;
		}
	}

	if (preg_match_all('/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?#__([a-z0-9_]+)`?/i', $source, $m))
	{
		foreach (array_unique($m[1]) as $table)
		{
			$table = strtolower($table);

			// down() drops what up() created; only count drops from elsewhere.
			if (!isset($createdBy[$table]) || !in_array($path, $createdBy[$table]))
			{
				$droppedTable[$table] = basename($path);
			}
		}
	}

	// ALTER TABLE `#__x` ADD COLUMN `y` ... , ADD `z` ...
	if (preg_match_all('/ALTER\s+TABLE\s+`?#__([a-z0-9_]+)`?(.*?);/is', $source, $alters, PREG_SET_ORDER))
	{
		foreach ($alters as $alter)
		{
			$table = strtolower($alter[1]);

			if (preg_match_all('/\bADD\s+(?:COLUMN\s+)?`?([a-z0-9_]+)`?/i', $alter[2], $cols))
			{
				foreach ($cols[1] as $column)
				{
					$column = strtolower($column);

					if (!in_array($column, $notColumns))
					{
						$addedBy[$table . '.' . $column][] = $path;
					}
				}
			}

			if (preg_match_all('/\bDROP\s+(?:COLUMN\s+)?`?([a-z0-9_]+)`?/i', $alter[2], $cols))
			{
				foreach ($cols[1] as $column)
				{
					$column = strtolower($column);

					if (!in_array($column, $notColumns))
					{
						$droppedColumn[$table . '.' . $column] = basename($path);
					}
				}
			}
		}
	}
}

// --- Phase 1: missing tables ------------------------------------------------
$missingTables = 0;

foreach ($createdBy as $table => $paths)
{
	if (isset($schema[$table]))
	{
		continue;
	}

	$last = basename(end($paths));

	// A later migration deliberately removed this table -- leave it gone.
	if (isset($droppedTable[$table]) && strcmp($droppedTable[$table], $last) > 0)
	{
		continue;
	}

	// The newest migration that creates it defines its final shape.
	$queue[end($paths)] = true;
	$missingTables++;
}

// --- Phase 2: missing columns -----------------------------------------------
$missingColumns = 0;

foreach ($addedBy as $key => $paths)
{
	list($table, $column) = explode('.', $key, 2);

	// Only care about columns on tables that actually exist.
	if (!isset($schema[$table]) || isset($schema[$table][$column]))
	{
		continue;
	}

	$last = basename(end($paths));

	if (isset($droppedColumn[$key]) && strcmp($droppedColumn[$key], $last) > 0)
	{
		continue;
	}

	$queue[end($paths)] = true;
	$missingColumns++;
}

// --- Run --------------------------------------------------------------------
$queue = array_keys($queue);

usort($queue, function ($a, $b)
{
	return strcmp(basename($a), basename($b));
});

if (!$queue)
{
	fwrite(STDERR, "[hub] database matches the code; no repair needed\n");
	exit(0);
}

fwrite(STDERR, sprintf(
	"[hub] repairing database: %d migrations to replay (%d missing tables, %d missing columns)\n",
	count($queue), $missingTables, $missingColumns
));

$ok = $bad = 0;
$failures = array();

foreach ($queue as $path)
{
	$name = pathinfo(basename($path), PATHINFO_FILENAME);

	require_once $path;

	if (!class_exists($name))
	{
		continue;
	}

	try
	{
		$class = new $name($db);
		$class->up();

		foreach ((array) $class->getErrors() as $error)
		{
			if ($error['type'] == 'fatal')
			{
				throw new Exception($error['message']);
			}
		}

		$ok++;
	}
	catch (Throwable $e)
	{
		// Expected for components this hub does not use, and for migrations
		// that assume state the bundled schema never had. Not fatal.
		$bad++;
		$failures[basename($path)] = $e->getMessage();
	}
}

fwrite(STDERR, sprintf("[hub] repair complete: %d migrations applied, %d skipped on error\n", $ok, $bad));

if ($bad && $verbose)
{
	foreach ($failures as $file => $message)
	{
		fwrite(STDERR, '[hub]   ' . $file . ': ' . str_replace("\n", ' ', $message) . "\n");
	}
}
elseif ($bad)
{
	fwrite(STDERR, "[hub] set HUB_VERBOSE_REPAIR=1 to see why\n");
}
