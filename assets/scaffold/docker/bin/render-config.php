<?php
/**
 * Render app/config/*.php from the environment.
 *
 * HUBzero has no notion of environment variables: Hubzero\Config\FileLoader
 * reads plain PHP arrays out of app/config/. This script is the bridge, and it
 * replaces the interactive /install wizard (Bootstrap\Install\Providers\
 * WizardServiceProvider) for unattended deployments.
 *
 * Defaults are read from core/bootstrap/Install/config/ rather than duplicated
 * here, so new upstream settings appear automatically on the next update.
 *
 * Precedence, lowest to highest:
 *   1. upstream defaults      core/bootstrap/Install/config/<group>.php
 *   2. existing values        app/config/<group>.php   (admin edits survive)
 *   3. mapped env vars        DB_HOST, HUB_SITENAME, ...
 *   4. explicit overrides     HUBCFG_<group>__<key>=<value>
 */

$root      = getenv('HUB_ROOT') ?: '/var/www/html';
$defaults  = $root . '/core/bootstrap/Install/config';
$target    = $root . '/app/config';

if (!is_dir($defaults))
{
	fwrite(STDERR, "[hub] ERROR: no config templates at {$defaults}\n");
	exit(1);
}

/**
 * Environment variable -> "group.key". Anything not listed keeps its upstream
 * default and stays editable from the admin UI.
 */
$map = array(
	'DB_HOST'                => 'database.host',
	'DB_NAME'                => 'database.db',
	'DB_USER'                => 'database.user',
	'DB_PASSWORD'            => 'database.password',
	'DB_PREFIX'              => 'database.dbprefix',

	'HUB_SITENAME'           => 'app.sitename',
	'HUB_ENV'                => 'app.application_env',
	'HUB_DEBUG'              => 'app.debug',
	'HUB_ERROR_REPORTING'    => 'app.error_reporting',
	'HUB_TIMEZONE'           => 'app.offset',
	'HUB_FORCE_SSL'          => 'app.force_ssl',
	'HUB_LIVE_SITE'          => 'app.live_site',
	'HUB_EDITOR'             => 'app.editor',
	'HUB_LIST_LIMIT'         => 'app.list_limit',

	'HUB_CACHING'            => 'cache.caching',
	'HUB_CACHE_HANDLER'      => 'cache.cache_handler',
	'HUB_CACHE_TIME'         => 'cache.cachetime',

	'HUB_SESSION_HANDLER'    => 'session.session_handler',
	'HUB_SESSION_LIFETIME'   => 'session.lifetime',

	'HUB_MAILER'             => 'mail.mailer',
	'HUB_MAIL_FROM'          => 'mail.mailfrom',
	'HUB_MAIL_FROMNAME'      => 'mail.fromname',
	'HUB_SMTP_HOST'          => 'mail.smtphost',
	'HUB_SMTP_PORT'          => 'mail.smtpport',
	'HUB_SMTP_USER'          => 'mail.smtpuser',
	'HUB_SMTP_PASSWORD'      => 'mail.smtppass',
	'HUB_SMTP_SECURE'        => 'mail.smtpsecure',
	'HUB_SMTP_AUTH'          => 'mail.smtpauth',

	'HUB_META_DESCRIPTION'   => 'meta.MetaDesc',
	'HUB_META_KEYWORDS'      => 'meta.MetaKeys',

	'HUB_OFFLINE'            => 'offline.offline',
	'HUB_OFFLINE_MESSAGE'    => 'offline.offline_message',

	'HUB_SEF'                => 'seo.sef',
	'HUB_SEF_REWRITE'        => 'seo.sef_rewrite',
);

// ---------------------------------------------------------------------------
// 1. Upstream defaults
// ---------------------------------------------------------------------------
$config = array();

foreach (glob($defaults . '/*.php') as $file)
{
	$group = basename($file, '.php');
	$value = require $file;

	if (is_array($value))
	{
		$config[$group] = $value;
	}
}

// ---------------------------------------------------------------------------
// 2. Existing values (preserve anything an administrator changed in the UI)
// ---------------------------------------------------------------------------
foreach (glob($target . '/*.php') as $file)
{
	$group = basename($file, '.php');
	$value = @require $file;

	if (is_array($value))
	{
		$config[$group] = array_merge(
			isset($config[$group]) ? $config[$group] : array(),
			$value
		);
	}
}

// ---------------------------------------------------------------------------
// 3 + 4. Environment
// ---------------------------------------------------------------------------
$set = function ($path, $value) use (&$config)
{
	list($group, $key) = explode('.', $path, 2);
	$config[$group][$key] = $value;
};

foreach ($map as $env => $path)
{
	$value = getenv($env);

	// An unset variable means "leave it alone"; an empty one is a real value
	// (e.g. HUB_LIVE_SITE='' to let the app auto-detect its own URL).
	if ($value !== false)
	{
		$set($path, $value);
	}
}

foreach ($_ENV + $_SERVER as $env => $value)
{
	if (strpos($env, 'HUBCFG_') === 0 && strpos($env, '__') !== false)
	{
		list($group, $key) = explode('__', substr($env, strlen('HUBCFG_')), 2);
		$config[strtolower($group)][$key] = $value;
	}
}

// ---------------------------------------------------------------------------
// Values that are not free-form settings
// ---------------------------------------------------------------------------

// Paths are fixed by the image layout, not by the operator.
$config['app']['log_path'] = $root . '/app/logs';
$config['app']['tmp_path'] = $root . '/app/tmp';

// mysqli/mysql are dead in PHP 8; the framework shims 'mysql' to 'pdo' anyway.
$config['database']['dbtype'] = 'pdo';

// The secret keys sessions and tokens. Generate once, then never change it --
// rotating it silently invalidates every active session and reset token.
if (empty($config['app']['secret'])
 || $config['app']['secret'] === 'youshouldreallychangethis')
{
	$secret = getenv('HUB_SECRET');

	if (!$secret)
	{
		$secret = substr(bin2hex(random_bytes(32)), 0, 32);
	}

	$config['app']['secret'] = $secret;
}

if (empty($config['database']['password']))
{
	fwrite(STDERR, "[hub] ERROR: DB_PASSWORD is empty. HUBzero treats a blank\n"
		. "      database password as 'not configured' and redirects to /install.\n");
	exit(1);
}

// ---------------------------------------------------------------------------
// Write
// ---------------------------------------------------------------------------
if (!is_dir($target) && !@mkdir($target, 0750, true))
{
	fwrite(STDERR, "[hub] ERROR: cannot create {$target}\n");
	exit(1);
}

$changed = 0;

foreach ($config as $group => $values)
{
	$file     = $target . '/' . $group . '.php';
	$contents = "<?php\n"
		. "// Generated by hub-config-render. Environment variables win on restart;\n"
		. "// values not driven by the environment are preserved.\n"
		. 'return ' . var_export($values, true) . ";\n";

	if (is_file($file) && file_get_contents($file) === $contents)
	{
		continue;
	}

	if (file_put_contents($file, $contents, LOCK_EX) === false)
	{
		fwrite(STDERR, "[hub] ERROR: cannot write {$file}\n");
		exit(1);
	}

	// Contains database credentials and the app secret.
	@chmod($file, 0640);
	$changed++;
}

fwrite(STDERR, sprintf(
	"[hub] config rendered into %s (%d file%s changed)\n",
	$target, $changed, $changed === 1 ? '' : 's'
));
