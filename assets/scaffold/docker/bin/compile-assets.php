<?php
/**
 * Compile the active template's LESS and report failures loudly.
 *
 * Hubzero\Document\Assets::getSystemStylesheet() wraps the whole compile in a
 * try/catch that returns '' on any error. The template then renders
 * <link href="/"> and you get a completely unstyled site with nothing in the
 * logs -- the failure looks like a CSS problem when it is actually a LESS
 * syntax error, often a single missing semicolon in the template repo.
 *
 * This does the same compile deliberately so the error surfaces at provision
 * time, with the file and line, instead of being discovered by eye.
 */

$app = require __DIR__ . '/bootstrap-cli.php';

$db     = App::get('db');
$root   = getenv('HUB_ROOT') ?: '/var/www/html';
$prefix = $app['config']->get('dbprefix', 'jos_');

if (!class_exists('\Hubzero\Document\Lessc'))
{
	fwrite(STDERR, "[hub] ERROR: LESS parser missing -- run hub-composer\n");
	exit(1);
}

$failed = 0;

foreach (array('site' => 0, 'admin' => 1) as $client => $clientId)
{
	$db->setQuery("SELECT `template` FROM `#__template_styles`
		WHERE `client_id` = " . (int) $clientId . " AND `home` = '1' LIMIT 1");
	$template = $db->loadResult();

	if (!$template)
	{
		continue;
	}

	// A template may live in app/ (custom) or core/ (shipped).
	$base = null;

	foreach (array($root . '/app/templates/', $root . '/core/templates/') as $candidate)
	{
		if (is_dir($candidate . $template))
		{
			$base = $candidate . $template;
			break;
		}
	}

	if (!$base)
	{
		fwrite(STDERR, "[hub] WARN: {$client} template '{$template}' is active but not on disk\n");
		$failed++;
		continue;
	}

	$templateLess = $base . '/less';
	$coreLess     = $root . '/core/assets/less';

	// The template's own site.less wins over the core one, same as Assets does.
	$input = is_file($templateLess . '/site.less')
		? $templateLess . '/site.less'
		: $coreLess . '/site.less';

	if (!is_file($input))
	{
		continue;
	}

	$cacheDir = $root . '/app/cache/' . $client;

	if (!is_dir($cacheDir))
	{
		@mkdir($cacheDir, 0775, true);
	}

	try
	{
		$less = new \Hubzero\Document\Lessc;
		$less->setImportDir(array($templateLess . '/', $coreLess . '/'));

		if ($app['config']->get('application_env', 'production') != 'development')
		{
			$less->setFormatter(new \LesserPHP\FormatterCompressed());
		}

		$compiled = $less->cachedCompile($input);

		// Same rewrite Assets performs, so relative asset URLs resolve.
		$css = str_replace(
			array("'/media/system/", "'/core/assets/"),
			"'/core/assets/",
			$compiled['compiled']
		);

		file_put_contents($cacheDir . '/site.less.cache', serialize($compiled));
		file_put_contents($cacheDir . '/site.css', $css);

		fwrite(STDERR, sprintf(
			"[hub]   %s template '%s': %d KB of CSS\n",
			$client, $template, round(strlen($css) / 1024)
		));
	}
	catch (Throwable $e)
	{
		$failed++;
		fwrite(STDERR, "[hub]   FAILED {$client} template '{$template}': "
			. str_replace("\n", ' ', $e->getMessage()) . "\n");
		fwrite(STDERR, "[hub]   -> the site will render with NO stylesheet until this is fixed\n");
	}
}

exit($failed > 0 ? 1 : 0);
