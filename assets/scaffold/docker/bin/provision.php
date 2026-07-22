<?php
/**
 * Apply hub.yml to the hub.
 *
 * The admin UI is the only supported way to set up a HUBzero hub, which makes a
 * hub impossible to rebuild from scratch and impossible to review. This reads a
 * declarative manifest instead and makes the database match it.
 *
 * Every section is idempotent and matched on a natural key (alias, username,
 * title...), so re-running updates in place rather than duplicating. Absent
 * sections are left completely alone -- this never removes anything it did not
 * create, because a half-written manifest must not be able to delete content.
 */

use Symfony\Component\Yaml\Yaml;
use Hubzero\Content\Migration\Base as Migration;

$app = require __DIR__ . '/bootstrap-cli.php';

$db   = App::get('db');
$root = getenv('HUB_ROOT') ?: '/var/www/html';
$file = getenv('HUB_MANIFEST') ?: '/etc/hubzero/hub.yml';

if (!is_file($file))
{
	fwrite(STDERR, "[hub] no manifest at {$file}; nothing to provision\n");
	exit(0);
}

/**
 * Expand ${VAR} from the environment, so secrets stay in .env and the manifest
 * stays committable.
 */
$raw = preg_replace_callback('/\$\{([A-Z0-9_]+)\}/', function ($m)
{
	$value = getenv($m[1]);

	if ($value === false)
	{
		fwrite(STDERR, "[hub] WARN: \${{$m[1]}} in the manifest is not set in the environment\n");
		return '';
	}

	return $value;
}, file_get_contents($file));

try
{
	$manifest = Yaml::parse($raw);
}
catch (Throwable $e)
{
	fwrite(STDERR, '[hub] ERROR: ' . $file . ' is not valid YAML: ' . $e->getMessage() . "\n");
	exit(1);
}

if (!is_array($manifest))
{
	fwrite(STDERR, "[hub] manifest is empty; nothing to provision\n");
	exit(0);
}

$migration = new Migration($db);
$changed   = 0;
$failed    = 0;

function info($message)
{
	fwrite(STDERR, "[hub]   {$message}\n");
}

function section($name)
{
	fwrite(STDERR, "[hub] {$name}\n");
}

/**
 * Run a step, keeping one bad entry from aborting the whole manifest.
 */
function attempt($label, callable $work)
{
	global $changed, $failed;

	try
	{
		if ($work())
		{
			$changed++;
			info($label);
		}
	}
	catch (Throwable $e)
	{
		$failed++;
		fwrite(STDERR, '[hub]   FAILED ' . $label . ': ' . str_replace("\n", ' ', $e->getMessage()) . "\n");
	}
}

/**
 * Merge params into exactly one #__extensions row without clobbering the keys
 * already there.
 *
 * Hubzero's SaveParams macro *replaces* the whole params blob (and its plugin
 * sibling, SavePluginParams, is a PHP-8 landmine -- 3-arg __invoke over a 2-arg
 * parent), so provisioning a single param through it would silently wipe every
 * other setting on the extension. That breaks this file's additive-only
 * promise, so merge into the JSON directly instead. $where is column => value
 * pairs that must identify one row (type/element, plus folder for plugins).
 */
function merge_extension_params($db, array $where, array $params, $label)
{
	$clauses = array();

	foreach ($where as $col => $val)
	{
		$clauses[] = '`' . $col . '` = ' . $db->quote($val);
	}

	$sql = implode(' AND ', $clauses);

	$db->setQuery("SELECT `params` FROM `#__extensions` WHERE " . $sql);
	$current = $db->loadResult();

	if ($current === null)
	{
		throw new Exception($label . ' is not registered');
	}

	$merged = array_merge((array) json_decode((string) $current, true), $params);

	$db->setQuery("UPDATE `#__extensions` SET `params` = " . $db->quote(json_encode($merged)) . "
		WHERE " . $sql);
	$db->query();
}

/**
 * The unconditional seed fixes below repair gaps the CMS installer leaves, so
 * they default ON. `seeds: {name: false}` in the manifest opts one out -- only
 * for a hub that deliberately handles that gap another way.
 */
function seed_enabled($name)
{
	global $manifest;

	$seeds = (isset($manifest['seeds']) && is_array($manifest['seeds'])) ? $manifest['seeds'] : array();

	return !array_key_exists($name, $seeds) || !empty($seeds[$name]);
}

// ---------------------------------------------------------------------------
// Extensions
// ---------------------------------------------------------------------------
$plural = array(
	'template'  => 'templates',
	'component' => 'components',
	'module'    => 'modules',
	'plugin'    => 'plugins',
);

if (!empty($manifest['extensions']))
{
	section('extensions');

	foreach ($manifest['extensions'] as $ext)
	{
		$type   = isset($ext['type']) ? $ext['type'] : '';
		$alias  = isset($ext['alias']) ? $ext['alias'] : '';
		$url    = isset($ext['url']) ? $ext['url'] : '';
		$branch = isset($ext['branch']) ? $ext['branch'] : '';
		$folder = isset($ext['folder']) ? $ext['folder'] : '';

		if (!isset($plural[$type]) || !$alias)
		{
			$failed++;
			fwrite(STDERR, "[hub]   FAILED extension: needs a valid 'type' and an 'alias'\n");
			continue;
		}

		$path = $root . '/app/' . $plural[$type] . ($folder ? '/' . $folder : '') . '/' . $alias;

		attempt("{$type} {$alias}", function () use ($ext, $type, $alias, $url, $branch, $folder, $path, $migration, $db)
		{
			// A token turns https://host/path into https://oauth2:TOKEN@host/path.
			$remote = $url;

			if (!empty($ext['token']) && $url)
			{
				$parts  = parse_url($url);
				$remote = $parts['scheme'] . '://oauth2:' . $ext['token'] . '@'
					. $parts['host'] . (isset($parts['path']) ? $parts['path'] : '');
			}

			if ($url)
			{
				if (!is_dir($path . '/.git'))
				{
					@mkdir(dirname($path), 0775, true);

					$cmd = 'umask 0002 && git clone --quiet '
						. ($branch ? '--branch ' . escapeshellarg($branch) . ' ' : '')
						. escapeshellarg($remote) . ' ' . escapeshellarg($path) . ' 2>&1';
					$out = shell_exec($cmd);

					if (!is_dir($path . '/.git'))
					{
						// Never let a token reach the log.
						throw new Exception(str_replace($remote, $url, (string) $out));
					}
				}
				else
				{
					// Refresh the remote so a rotated token takes effect.
					shell_exec('cd ' . escapeshellarg($path) . ' && git remote set-url origin '
						. escapeshellarg($remote) . ' 2>&1');

					$out = shell_exec('cd ' . escapeshellarg($path) . ' && git fetch --quiet origin '
						. ($branch ? escapeshellarg($branch) : '') . ' 2>&1');

					if ($branch)
					{
						shell_exec('cd ' . escapeshellarg($path)
							. ' && git checkout --quiet ' . escapeshellarg($branch)
							. ' && git merge --quiet --ff-only origin/' . escapeshellarg($branch) . ' 2>&1');
					}
				}
			}

			if (!is_dir($path))
			{
				throw new Exception("{$path} does not exist and no 'url' was given to clone it from");
			}

			// Register with the CMS.
			switch ($type)
			{
				case 'template':
					// client 0 = site. Activation is handled by `template:`.
					$migration->addTemplateEntry($alias, $alias, 0, 1, 0);
					break;

				case 'component':
					$migration->addComponentEntry($alias);
					break;

				case 'module':
					$migration->addModuleEntry($alias);
					break;

				case 'plugin':
					if (!$folder)
					{
						throw new Exception("plugin '{$alias}' needs a 'folder'");
					}
					$migration->addPluginEntry($folder, $alias);
					break;
			}

			// Production state: several custom extensions can be installed but
			// deliberately unpublished.
			if (array_key_exists('published', $ext))
			{
				$enabled = !empty($ext['published']) ? 1 : 0;
				$where   = "`element` = " . $db->quote($type == 'template' ? $alias : $alias);

				if ($type == 'plugin')
				{
					$where .= " AND `folder` = " . $db->quote($folder);
				}

				$db->setQuery("UPDATE `#__extensions` SET `enabled` = " . $enabled . "
					WHERE `type` = " . $db->quote($type) . " AND " . $where);
				$db->query();
			}

			// An extension's migrations directory is its installer.
			if (is_dir($path . '/migrations'))
			{
				foreach (glob($path . '/migrations/Migration*.php') as $m)
				{
					$class = pathinfo($m, PATHINFO_FILENAME);
					require_once $m;

					if (class_exists($class))
					{
						$obj = new $class($db);
						$obj->up();
					}
				}
			}

			return true;
		});
	}
}

// ---------------------------------------------------------------------------
// Active template
// ---------------------------------------------------------------------------
if (!empty($manifest['template']))
{
	section('template');

	foreach (array('site' => 0, 'admin' => 1) as $key => $client)
	{
		if (empty($manifest['template'][$key]))
		{
			continue;
		}

		$element = $manifest['template'][$key];

		attempt("{$key} template -> {$element}", function () use ($element, $client, $db, $migration)
		{
			// InstallTemplateEntry would do this in one call, but its __invoke
			// signature is incompatible with its parent's and loading it is an
			// uncatchable fatal on PHP 8. addTemplateEntry with home=1 is the
			// same thing without the landmine.
			$migration->addTemplateEntry($element, $element, $client, 1, 1);

			// addTemplateEntry only sets home when it creates the row, so make
			// sure an already-registered template still becomes the active one.
			$db->setQuery("UPDATE `#__template_styles` SET `home` = '0' WHERE `client_id` = " . (int) $client);
			$db->query();

			$db->setQuery("UPDATE `#__template_styles` SET `home` = '1'
				WHERE `client_id` = " . (int) $client . " AND `template` = " . $db->quote($element));
			$db->query();

			return true;
		});
	}
}

// ---------------------------------------------------------------------------
// Plugins
// ---------------------------------------------------------------------------
if (!empty($manifest['plugins']))
{
	section('plugins');

	// Plugin params merge into #__extensions.params directly (see
	// merge_extension_params: the savePluginParams macro is a PHP-8 landmine
	// and would replace rather than merge).
	foreach ((array) (isset($manifest['plugins']['params']) ? $manifest['plugins']['params'] : array()) as $spec => $params)
	{
		if (strpos($spec, '/') === false)
		{
			$failed++;
			fwrite(STDERR, "[hub]   FAILED plugin params '{$spec}': expected <folder>/<element>\n");
			continue;
		}

		list($folder, $element) = explode('/', $spec, 2);

		attempt("params {$spec}", function () use ($folder, $element, $params, $spec, $db)
		{
			merge_extension_params($db, array(
				'type'    => 'plugin',
				'folder'  => $folder,
				'element' => $element,
			), (array) $params, "plugin '{$spec}'");

			return true;
		});
	}

	foreach (array('enable', 'disable') as $action)
	{
		foreach ((array) (isset($manifest['plugins'][$action]) ? $manifest['plugins'][$action] : array()) as $spec)
		{
			if (strpos($spec, '/') === false)
			{
				$failed++;
				fwrite(STDERR, "[hub]   FAILED plugin '{$spec}': expected <folder>/<element>\n");
				continue;
			}

			list($folder, $element) = explode('/', $spec, 2);

			attempt("{$action} {$spec}", function () use ($action, $folder, $element, $migration)
			{
				$action == 'enable'
					? $migration->enablePlugin($folder, $element)
					: $migration->disablePlugin($folder, $element);

				return true;
			});
		}
	}
}

// ---------------------------------------------------------------------------
// Component params
// ---------------------------------------------------------------------------
if (!empty($manifest['components']))
{
	section('components');

	foreach ($manifest['components'] as $element => $params)
	{
		attempt("{$element} params", function () use ($element, $params, $db)
		{
			// Merge, not saveParams: that macro replaces the whole blob, so
			// setting one param (e.g. com_courses.tmpl) would wipe the rest.
			merge_extension_params($db, array(
				'type'    => 'component',
				'element' => $element,
			), (array) $params, "component '{$element}'");

			return true;
		});
	}
}

// ---------------------------------------------------------------------------
// Modules
// ---------------------------------------------------------------------------
// Position matters more than it looks: a module in a position the active
// template does not render is invisible with no error anywhere. mod_menu ships
// in Joomla's "position-7", which this template never outputs.
if (!empty($manifest['modules']))
{
	section('modules');

	foreach ($manifest['modules'] as $module => $spec)
	{
		attempt("module {$module}", function () use ($module, $spec, $db)
		{
			$client = isset($spec['client']) ? (int) $spec['client'] : 0;

			$db->setQuery("SELECT `id`, `params` FROM `#__modules`
				WHERE `module` = " . $db->quote($module) . "
				  AND `client_id` = " . $client . "
				ORDER BY `id` LIMIT 1");
			$row = $db->loadObject();

			if (!$row)
			{
				throw new Exception('module is not installed');
			}

			$set = array();

			if (isset($spec['position']))
			{
				$set[] = '`position` = ' . $db->quote($spec['position']);
			}

			if (isset($spec['published']))
			{
				$set[] = '`published` = ' . (int) $spec['published'];
			}

			if (!empty($spec['params']))
			{
				$merged = array_merge(
					(array) json_decode((string) $row->params, true),
					(array) $spec['params']
				);
				$set[] = '`params` = ' . $db->quote(json_encode($merged));
			}

			if (!$set)
			{
				return false;
			}

			$db->setQuery("UPDATE `#__modules` SET " . implode(', ', $set)
				. " WHERE `id` = " . (int) $row->id);
			$db->query();

			return true;
		});
	}
}

// ---------------------------------------------------------------------------
// Admin menu module
// ---------------------------------------------------------------------------
// A fresh install seeds the administrator menu (position 'menu', client_id 1)
// as Joomla's mod_menu, but HUBzero's mod_menu renders front-end menus only --
// in the admin it outputs nothing, leaving the backend with no navigation at
// all. The HUBzero admin tree (Site / Users / Menus / Content / Components /
// Extensions) is produced by mod_adminmenu. Point the admin menu module at it.
// Defaults on (every hub needs it); `seeds: {admin_menu: false}` opts out.
// Idempotent -- once switched, re-running is a no-op.
if (seed_enabled('admin_menu'))
{
section('admin menu');

attempt('administrator menu uses mod_adminmenu', function () use ($db)
{
	// Only act if mod_adminmenu is actually installed and enabled for the admin.
	$db->setQuery("SELECT COUNT(*) FROM `#__extensions`
		WHERE `element` = 'mod_adminmenu' AND `client_id` = 1 AND `enabled` = 1");

	if (!(int) $db->loadResult())
	{
		throw new Exception('mod_adminmenu is not installed/enabled');
	}

	// The published module in the admin 'menu' position.
	$db->setQuery("SELECT `id`, `module` FROM `#__modules`
		WHERE `client_id` = 1 AND `position` = 'menu' AND `published` = 1
		ORDER BY `id` LIMIT 1");
	$row = $db->loadObject();

	if (!$row)
	{
		throw new Exception('no published module in admin position "menu"');
	}

	if ($row->module === 'mod_adminmenu')
	{
		return false; // already correct
	}

	$db->setQuery("UPDATE `#__modules` SET `module` = 'mod_adminmenu'
		WHERE `id` = " . (int) $row->id);
	$db->query();

	return true;
});
} // seed_enabled('admin_menu')

// ---------------------------------------------------------------------------
// Support query folders
// ---------------------------------------------------------------------------
// com_support's ticket list needs core query folders (user_id = 0) to exist:
// tickets.php walks them and dereferences the first query with ->get(), so an
// empty set is a fatal ("Call to a member function get() on null"). The seed is
// shipped by Migration20141010150100ComSupport -- but only inside its "if the
// table does not exist" branch, so on installs where #__support_query_folders
// was created by an earlier migration the seed is skipped and /support/tickets
// 500s. Seed the core folders + their default queries here, mirroring
// QueryFolder::cloneCore's self-seed (minus the per-user clone). Idempotent.
// Defaults on; `seeds: {support_query_folders: false}` opts out.
if (seed_enabled('support_query_folders'))
{
section('support');

attempt('support: seed core query folders', function () use ($db, $root)
{
	$db->setQuery("SELECT COUNT(*) FROM `#__support_query_folders` WHERE `user_id` = 0");

	if ((int) $db->loadResult() > 0)
	{
		return false; // already seeded
	}

	// Components have no autoloader; pull in the two models explicitly.
	require_once $root . '/core/components/com_support/models/queryfolder.php';
	require_once $root . '/core/components/com_support/models/query.php';

	// iscore 1 = staff folder set, iscore 2 = the set shown to regular users.
	$defaults = array(
		1 => array('Common', 'Mine', 'Custom'),
		2 => array('Common', 'Mine'),
	);

	foreach ($defaults as $iscore => $fldrs)
	{
		$i = 1;

		foreach ($fldrs as $title)
		{
			$f = \Components\Support\Models\QueryFolder::blank();
			$f->set('iscore', $iscore);
			$f->set('title', $title);
			$f->set('ordering', $i);
			$f->set('user_id', 0);
			$f->save();

			// alias is generated from the title on save; drives which default
			// query set the folder gets (matches cloneCore).
			switch ($f->get('alias'))
			{
				case 'common':
					\Components\Support\Models\Query::populateDefaults($iscore == 1 ? 'common' : 'commonnotacl', $f->id);
					break;

				case 'mine':
					\Components\Support\Models\Query::populateDefaults('mine', $f->id);
					break;
			}

			$i++;
		}
	}

	return true;
});
} // seed_enabled('support_query_folders')

// ---------------------------------------------------------------------------
// Internal OAuth client
// ---------------------------------------------------------------------------
// First-party API calls -- the course editor's AJAX, tool sessions -- authenticate
// through com_developer's "internal request client": the #__developer_applications
// row with hub_account = 1. The CMS is supposed to self-heal a missing one
// (Oauth\Storage\Mysql::createInternalRequestClient), but that hardcodes
// redirect_uri = 'https://' . $_SERVER['HTTP_HOST'] and the Application model
// rejects it via Validate::url() whenever the host has no TLD -- which every
// localhost hub is (localhost, localhost:8443). So the client is never created,
// and every session-token API request 401s with "invalid_token" -- most visibly,
// saving a course. Seed it directly, which bypasses that redirect_uri validation;
// the session/client_credentials/tool grants never redirect, so the stored value
// is inert. Idempotent on hub_account = 1.
// Defaults on; `seeds: {oauth_internal_client: false}` opts out.
if (seed_enabled('oauth_internal_client'))
{
	section('oauth');

	attempt('oauth: seed internal request client (hub_account)', function () use ($db)
	{
		$db->setQuery("SELECT COUNT(*) FROM `#__developer_applications` WHERE `hub_account` = 1");

		if ((int) $db->loadResult() > 0)
		{
			return false; // already present
		}

		// Mirror createInternalRequestClient()'s id/secret derivation.
		$clientId     = md5(uniqid('0', true));
		$clientSecret = sha1($clientId);
		$site         = getenv('HUB_LIVE_SITE'); // inert for these grants; use it if set

		$db->setQuery("INSERT INTO `#__developer_applications`
			(`name`, `description`, `client_id`, `client_secret`, `redirect_uri`,
			 `grant_types`, `created`, `created_by`, `state`, `hub_account`)
			VALUES ("
			. $db->quote('Hub Account') . ", "
			. $db->quote('Hub account for internal requests. DO NOT DELETE.') . ", "
			. $db->quote($clientId) . ", "
			. $db->quote($clientSecret) . ", "
			. $db->quote($site ?: 'https://localhost') . ", "
			. $db->quote('client_credentials session tool') . ", "
			. "NOW(), 0, 1, 1)");
		$db->query();

		return true;
	});
}

// ---------------------------------------------------------------------------
// Resource types
// ---------------------------------------------------------------------------
if (!empty($manifest['resource_types']))
{
	section('resource types');

	foreach ($manifest['resource_types'] as $type)
	{
		$alias = isset($type['alias']) ? $type['alias'] : '';

		if (!$alias)
		{
			$failed++;
			fwrite(STDERR, "[hub]   FAILED resource type: needs an 'alias'\n");
			continue;
		}

		attempt("resource type {$alias}", function () use ($type, $alias, $db)
		{
			$id = isset($type['id']) ? (int) $type['id'] : 0;

			// Pinned ids keep content portable between hubs.
			$db->setQuery($id
				? "SELECT `id` FROM `#__resource_types` WHERE `id` = " . $id
				: "SELECT `id` FROM `#__resource_types` WHERE `alias` = " . $db->quote($alias));
			$existing = $db->loadResult();

			$fields = array(
				'alias'         => $alias,
				'type'          => isset($type['type']) ? $type['type'] : $alias,
				'category'      => isset($type['category']) ? (int) $type['category'] : 27,
				'description'   => isset($type['description']) ? $type['description'] : '',
				'contributable' => isset($type['contributable']) ? (int) $type['contributable'] : 1,
				'state'         => isset($type['state']) ? (int) $type['state'] : 1,
			);

			$set = array();

			foreach ($fields as $column => $value)
			{
				$set[] = '`' . $column . '` = ' . $db->quote($value);
			}

			if ($existing)
			{
				$db->setQuery("UPDATE `#__resource_types` SET " . implode(', ', $set)
					. " WHERE `id` = " . (int) $existing);
			}
			else
			{
				if ($id)
				{
					$set[] = '`id` = ' . $id;
				}
				$db->setQuery("INSERT INTO `#__resource_types` SET " . implode(', ', $set));
			}

			$db->query();

			return true;
		});
	}
}

// ---------------------------------------------------------------------------
// Resources
// ---------------------------------------------------------------------------
if (!empty($manifest['resources']))
{
	section('resources');

	foreach ($manifest['resources'] as $resource)
	{
		$title = isset($resource['title']) ? $resource['title'] : '';

		if (!$title)
		{
			$failed++;
			fwrite(STDERR, "[hub]   FAILED resource: needs a 'title'\n");
			continue;
		}

		attempt("resource {$title}", function () use ($resource, $title, $db)
		{
			$db->setQuery("SELECT `id` FROM `#__resource_types` WHERE `alias` = "
				. $db->quote(isset($resource['type']) ? $resource['type'] : 'tools'));
			$typeId = (int) $db->loadResult();

			if (!$typeId)
			{
				throw new Exception("unknown resource type '"
					. (isset($resource['type']) ? $resource['type'] : '?') . "'");
			}

			$db->setQuery("SELECT `id` FROM `#__resources` WHERE `title` = " . $db->quote($title));
			$existing = $db->loadResult();

			$fields = array(
				'title'     => $title,
				'type'      => $typeId,
				'published' => isset($resource['published']) ? (int) $resource['published'] : 1,
				'introtext' => isset($resource['introtext']) ? $resource['introtext'] : '',
				'fulltxt'   => isset($resource['fulltxt']) ? $resource['fulltxt'] : '',
				'standalone' => 1,
				'access'    => 0,
			);

			$set = array();

			foreach ($fields as $column => $value)
			{
				$set[] = '`' . $column . '` = ' . $db->quote($value);
			}

			if ($existing)
			{
				$db->setQuery("UPDATE `#__resources` SET " . implode(', ', $set)
					. " WHERE `id` = " . (int) $existing);
			}
			else
			{
				$set[] = '`created` = ' . $db->quote(gmdate('Y-m-d H:i:s'));
				$set[] = '`publish_up` = ' . $db->quote(gmdate('Y-m-d H:i:s'));
				$db->setQuery("INSERT INTO `#__resources` SET " . implode(', ', $set));
			}

			$db->query();

			return true;
		});
	}
}

// ---------------------------------------------------------------------------
// Users
// ---------------------------------------------------------------------------
if (!empty($manifest['users']))
{
	section('users');

	foreach ($manifest['users'] as $spec)
	{
		$username = isset($spec['username']) ? $spec['username'] : '';

		if (!$username)
		{
			$failed++;
			fwrite(STDERR, "[hub]   FAILED user: needs a 'username'\n");
			continue;
		}

		attempt("user {$username}", function () use ($spec, $username, $db)
		{
			$user = Hubzero\User\User::oneByUsername($username);

			if (!$user || !$user->get('id'))
			{
				$user = Hubzero\User\User::blank();
				$user->set(array(
					'username'   => $username,
					'name'       => isset($spec['name']) ? $spec['name'] : $username,
					'email'      => isset($spec['email']) ? $spec['email'] : $username . '@example.com',
					// Request::ip() is null on the command line and the column
					// is NOT NULL.
					'registerIP' => '127.0.0.1',
				));
			}

			$user->set('block', 0);
			$user->set('approved', 2);
			$user->set('activation', 1);

			if (!$user->save())
			{
				throw new Exception(implode('; ', $user->getErrors()));
			}

			if (!empty($spec['password']))
			{
				$id = (int) $user->get('id');

				// changePassword() only auto-creates the shadow row by copying
				// it out of the legacy #__xprofiles table.
				$db->setQuery("INSERT IGNORE INTO `#__users_password` (`user_id`) VALUES (" . $db->quote($id) . ")");
				$db->query();

				Hubzero\User\Password::changePassword($id, $spec['password']);
			}

			return true;
		});
	}
}

// ---------------------------------------------------------------------------
// Groups
// ---------------------------------------------------------------------------
if (!empty($manifest['groups']))
{
	section('groups');

	foreach ($manifest['groups'] as $spec)
	{
		$cn = isset($spec['cn']) ? $spec['cn'] : '';

		if (!$cn)
		{
			$failed++;
			fwrite(STDERR, "[hub]   FAILED group: needs a 'cn'\n");
			continue;
		}

		attempt("group {$cn}", function () use ($spec, $cn)
		{
			$group = Hubzero\User\Group::getInstance($cn);

			// getInstance() hands back an empty object rather than null when the
			// group does not exist, so gidNumber is what actually distinguishes
			// "found" from "new" -- and create() always INSERTs, so calling it
			// on an existing group is a duplicate-key error.
			$isNew = !($group && $group->get('gidNumber'));

			if ($isNew)
			{
				$group = new Hubzero\User\Group();
				$group->set('cn', $cn);
				$group->set('type', isset($spec['type']) ? (int) $spec['type'] : 1);
				$group->set('published', 1);
				$group->set('approved', 1);
				$group->set('created', gmdate('Y-m-d H:i:s'));
				$group->set('created_by', 1000);
			}

			$group->set('description', isset($spec['description']) ? $spec['description'] : $cn);
			$group->set('public_desc', isset($spec['public']) ? (int) $spec['public'] : 1);

			$isNew ? $group->create() : $group->update();

			if (!empty($spec['members']))
			{
				$ids = array();

				foreach ((array) $spec['members'] as $username)
				{
					$member = Hubzero\User\User::oneByUsername($username);

					if ($member && $member->get('id'))
					{
						$ids[] = $member->get('id');
					}
				}

				if ($ids)
				{
					$group->add('members', $ids);
					$group->update();
				}
			}

			return true;
		});
	}
}

// ---------------------------------------------------------------------------
// Menus
// ---------------------------------------------------------------------------

/**
 * Create or update one menu item and return its id.
 *
 * #__menu is a nested set. On update the tree columns are left alone --
 * rewriting parent_id/lft/rgt on an existing row corrupts every sibling. On
 * insert the node is spliced in as the last child of $parentId.
 */
function menuItem($db, $item, $type, $client, $order, $parentId, $parentPath, $level)
{
    $title = isset($item['title']) ? $item['title'] : '';
    $alias = isset($item['alias']) ? $item['alias'] : strtolower(str_replace(' ', '-', $title));

    if (!$title || empty($item['link']))
    {
        throw new Exception("menu item needs a 'title' and a 'link'");
    }

    $itemType = isset($item['type']) ? $item['type'] : 'component';

    // Resolve the component this item points at. Leaving component_id at 0 does
    // not merely lose metadata -- the router then resolves the item to the
    // wrong component entirely (a com_resources link served com_content).
    $componentId = 0;

    if ($itemType == 'component' && preg_match('/option=(com_[a-z0-9_]+)/i', $item['link'], $m))
    {
        $db->setQuery("SELECT `extension_id` FROM `#__extensions`
            WHERE `type` = 'component' AND `element` = " . $db->quote(strtolower($m[1])));
        $componentId = (int) $db->loadResult();

        if (!$componentId)
        {
            throw new Exception("links to {$m[1]}, which is not a registered component");
        }
    }

    // Scope the lookup to the parent. Aliases are unique among siblings, not
    // across the tree -- matching on alias alone silently hijacks an unrelated
    // item elsewhere in the menu and drags it nowhere.
    $db->setQuery("SELECT `id` FROM `#__menu`
        WHERE `menutype` = " . $db->quote($type) . "
          AND `alias` = " . $db->quote($alias) . "
          AND `parent_id` = " . (int) $parentId);
    $existing = $db->loadResult();

    $fields = array(
        'menutype'     => $type,
        'title'        => $title,
        'alias'        => $alias,
        'link'         => $item['link'],
        'type'         => $itemType,
        'published'    => isset($item['published']) ? (int) $item['published'] : 1,
        'access'       => isset($item['access']) ? (int) $item['access'] : 1,
        'home'         => !empty($item['home']) ? 1 : 0,
        'client_id'    => $client,
        'language'     => '*',
        'ordering'     => $order + 1,
        'component_id' => $componentId,
    );

    $set = array();

    foreach ($fields as $column => $value)
    {
        $set[] = '`' . $column . '` = ' . $db->quote($value);
    }

    // Only one item per client may be the front page.
    if (!empty($item['home']))
    {
        $db->setQuery("UPDATE `#__menu` SET `home` = '0' WHERE `client_id` = " . (int) $client);
        $db->query();
    }

    if ($existing)
    {
        $db->setQuery("UPDATE `#__menu` SET " . implode(', ', $set) . " WHERE `id` = " . (int) $existing);
        $db->query();

        return (int) $existing;
    }

    // Splice in as the last child of $parentId: take the parent's right edge,
    // shift everything at or beyond it, and occupy the gap.
    $db->setQuery("SELECT `rgt` FROM `#__menu` WHERE `id` = " . (int) $parentId);
    $edge = (int) $db->loadResult();

    $db->setQuery("UPDATE `#__menu` SET `rgt` = `rgt` + 2 WHERE `rgt` >= " . $edge);
    $db->query();
    $db->setQuery("UPDATE `#__menu` SET `lft` = `lft` + 2 WHERE `lft` > " . $edge);
    $db->query();

    $set[] = '`parent_id` = ' . (int) $parentId;
    $set[] = '`level` = ' . (int) $level;
    $set[] = '`path` = ' . $db->quote(($parentPath ? $parentPath . '/' : '') . $alias);
    $set[] = '`lft` = ' . $edge;
    $set[] = '`rgt` = ' . ($edge + 1);
    $set[] = '`params` = ' . $db->quote('{}');

    $db->setQuery("INSERT INTO `#__menu` SET " . implode(', ', $set));
    $db->query();

    return (int) $db->insertid();
}

if (!empty($manifest['menus']))
{
    section('menus');

    foreach ($manifest['menus'] as $menutype => $spec)
    {
        $client = ($menutype == 'admin') ? 1 : 0;
        $type   = ($menutype == 'site') ? 'mainmenu' : $menutype;

        // Accept either a bare list of items, or a map with `items:` plus
        // options such as `prune:`.
        $items = $spec;
        $prune = false;

        if (is_array($spec) && isset($spec['items']))
        {
            $items = $spec['items'];
            $prune = !empty($spec['prune']);
        }

        $declared = array();

        // The menu type has to exist before items can belong to it.
        $db->setQuery("SELECT `id` FROM `#__menu_types` WHERE `menutype` = " . $db->quote($type));

        if (!$db->loadResult())
        {
            $db->setQuery("INSERT INTO `#__menu_types` SET `menutype` = " . $db->quote($type)
                . ", `title` = " . $db->quote(ucfirst($type)) . ", `description` = ''");
            $db->query();
        }

        foreach ((array) $items as $order => $item)
        {
            $label = isset($item['title']) ? $item['title'] : '?';

            attempt("menu {$type}/{$label}" . (!empty($item['home']) ? ' (home)' : ''),
                function () use ($item, $type, $client, $order, $db)
            {
                // Root of the menu tree is always id 1.
                $parentId = menuItem($db, $item, $type, $client, $order, 1, '', 1);

                if (!empty($item['children']))
                {
                    $db->setQuery("SELECT `path` FROM `#__menu` WHERE `id` = " . $parentId);
                    $parentPath = (string) $db->loadResult();

                    foreach ($item['children'] as $i => $child)
                    {
                        menuItem($db, $child, $type, $client, $i, $parentId, $parentPath, 2);
                    }
                }

                return true;
            });

            // Track the ids this manifest owns, for prune. Ids rather than
            // aliases: two items in different branches may share an alias.
            $alias = isset($item['alias'])
                ? $item['alias']
                : strtolower(str_replace(' ', '-', $item['title']));

            $db->setQuery("SELECT `id` FROM `#__menu`
                WHERE `menutype` = " . $db->quote($type) . "
                  AND `alias` = " . $db->quote($alias) . " AND `parent_id` = 1");
            $ownerId = (int) $db->loadResult();

            if ($ownerId)
            {
                $declared[] = $ownerId;

                $db->setQuery("SELECT `id` FROM `#__menu` WHERE `parent_id` = " . $ownerId);

                foreach ((array) $db->loadColumn() as $childId)
                {
                    $declared[] = (int) $childId;
                }
            }
        }

        // Make the rendered menu match the manifest by retiring anything not
        // declared. Unpublish rather than delete: this is content someone may
        // have built by hand, and an over-eager manifest must not destroy it.
        if ($prune && $declared)
        {
            $db->setQuery("SELECT `id`, `title` FROM `#__menu`
                WHERE `menutype` = " . $db->quote($type) . "
                  AND `client_id` = " . (int) $client . "
                  AND `id` > 1
                  AND `published` = 1
                  AND `id` NOT IN (" . implode(',', array_map('intval', $declared)) . ")");
            $strays = (array) $db->loadObjectList();

            foreach ($strays as $stray)
            {
                $db->setQuery("UPDATE `#__menu` SET `published` = 0 WHERE `id` = " . (int) $stray->id);
                $db->query();
                info("unpublished stray menu item '" . $stray->title . "'");
                $changed++;
            }
        }
    }
}

fwrite(STDERR, sprintf("[hub] provisioning complete: %d applied, %d failed\n", $changed, $failed));

exit($failed > 0 ? 1 : 0);
