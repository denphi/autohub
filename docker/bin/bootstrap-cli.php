<?php
/**
 * Boot the HUBzero framework for a standalone maintenance script.
 *
 * core/bin/muse boots the app and immediately dispatches a console command;
 * these scripts need the container (db, config, facades) without the dispatch,
 * so they do the same two steps by hand.
 *
 * Returns the Hubzero\Base\Application instance.
 */

$root = getenv('HUB_ROOT') ?: '/var/www/html';

if (!defined('PATH_ROOT'))
{
	define('PATH_ROOT', $root);
}

$autoload = $root . '/core/vendor/autoload.php';

if (!is_file($autoload))
{
	fwrite(STDERR, "[hub] ERROR: {$autoload} missing -- run hub-composer first\n");
	exit(1);
}

// Pulls in core/bootstrap/app.php via composer's "files" autoload, which is
// what defines PATH_APP, JPATH_* and registers the class loader.
require $autoload;

$app = new Hubzero\Base\Application();
$app->load('cli');
$app->boot();

return $app;
