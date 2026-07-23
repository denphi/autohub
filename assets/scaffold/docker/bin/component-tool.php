<?php
use Symfony\Component\Yaml\Yaml;

$app = require __DIR__ . '/bootstrap-cli.php';
$db = App::get('db');

$args = $argv;
array_shift($args);
$domain = array_shift($args);
$operation = array_shift($args);
$options = array('manifest' => '/etc/hubzero/hub.yml', 'max-items' => 25,
	'alias' => '', 'id' => 0);
while ($args)
{
	$key = ltrim((string) array_shift($args), '-');
	if (!array_key_exists($key, $options) || !$args)
	{
		fwrite(STDERR, "usage: hub-component <domain> <operation> [--manifest path] [--alias alias] [--id n] [--max-items n]\n");
		exit(3);
	}
	$options[$key] = array_shift($args);
}

$classes = array(
	'project' => 'AutoHubProjectsAdapter',
	'resource' => 'AutoHubResourcesAdapter',
	'publication' => 'AutoHubPublicationsAdapter',
	'course' => 'AutoHubCoursesAdapter',
);
$files = array(
	'project' => 'ProjectsAdapter.php',
	'resource' => 'ResourcesAdapter.php',
	'publication' => 'PublicationsAdapter.php',
	'course' => 'CoursesAdapter.php',
);
$operations = array('describe', 'inspect', 'plan', 'verify', 'export');
if (!isset($classes[$domain]) || !in_array($operation, $operations, true))
{
	fwrite(STDERR, "unknown component domain or operation\n");
	exit(3);
}

$manifest = array();
if (is_file($options['manifest']))
{
	$raw = preg_replace_callback('/\$\{([A-Z0-9_]+)\}/', function ($match)
	{
		$value = getenv($match[1]);
		return $value === false ? '' : $value;
	}, file_get_contents($options['manifest']));
	try
	{
		$parsed = Yaml::parse($raw);
		$manifest = is_array($parsed) ? $parsed : array();
	}
	catch (Throwable $e)
	{
		echo json_encode(array('ok' => false, 'errors' => array(
			'manifest YAML error: ' . $e->getMessage())));
		exit(1);
	}
}

require_once __DIR__ . '/components/Adapter.php';
require_once __DIR__ . '/components/' . $files[$domain];
$adapter = new $classes[$domain]($db, $manifest, (int) $options['max-items'],
	$options['alias'], (int) $options['id']);
$description = $adapter->describe();

if ($operation !== 'describe' && (!$description['installed'] || !$description['supported']))
{
	echo json_encode(array(
		'ok' => false,
		'component' => $description['component'],
		'operation' => $operation,
		'errors' => array($description['installed']
			? 'installed schema is not supported: ' . implode(', ', array_merge(
				$description['missing_tables'], $description['missing_columns']))
			: $description['component'] . ' is not installed'),
		'data' => $description,
	));
	exit(1);
}

$result = array('ok' => true, 'component' => $description['component'],
	'operation' => $operation, 'errors' => array(), 'checks' => array(),
	'routes' => $adapter->routes());
if ($operation === 'describe')
{
	$result['data'] = $description;
}
elseif ($operation === 'inspect')
{
	$result['data'] = $adapter->inspect();
}
elseif ($operation === 'plan')
{
	$plan = $adapter->plan();
	$result = array_merge($result, $plan);
	$result['data'] = $plan;
	$result['ok'] = empty($plan['errors']);
}
elseif ($operation === 'verify')
{
	$verify = $adapter->verify();
	$result = array_merge($result, $verify);
	$result['data'] = array('component' => $description, 'verification' => $verify);
	$result['ok'] = empty($verify['errors']);
	foreach ($verify['checks'] as $check)
	{
		$result['ok'] = $result['ok'] && !empty($check['ok']);
	}
}
elseif ($operation === 'export')
{
	$result['data'] = $adapter->export();
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($result['ok'] ? 0 : 1);
