<?php
/**
 * Shared read-only contract for native component tooling.
 *
 * Mutations remain in provision.php so one manifest application has one error
 * counter and dependency order. Adapters own discovery, validation, planning,
 * inspection, export, and read-after-write verification.
 */
abstract class AutoHubComponentAdapter
{
	protected $db;
	protected $manifest;
	protected $maxItems;
	protected $alias;
	protected $id;

	public function __construct($db, array $manifest, $maxItems = 25, $alias = '', $id = 0)
	{
		$this->db = $db;
		$this->manifest = $manifest;
		$this->maxItems = max(1, (int) $maxItems);
		$this->alias = trim((string) $alias);
		$this->id = max(0, (int) $id);
	}

	abstract public function name();
	abstract public function component();
	abstract public function section();
	abstract public function tables();
	abstract public function fields();
	abstract public function routes();
	abstract protected function liveRecords();
	public function requiredColumns() { return array(); }

	public function limitations()
	{
		return array();
	}
	public function entities() { return array($this->name()); }
	public function dependencies() { return array(); }

	protected function manifestNaturalKey(array $item)
	{
		return isset($item['alias']) ? trim((string) $item['alias']) : '';
	}

	protected function liveNaturalKeys(array $record)
	{
		return !empty($record['alias']) ? array($record['alias']) : array();
	}

	protected function validNaturalKey($key)
	{
		return (bool) preg_match('/^[a-z0-9][a-z0-9-]*$/', $key);
	}

	protected function redactRecord(array $record)
	{
		return $record;
	}
	protected function exportRecord(array $record)
	{
		$export = array();
		foreach ($this->fields() as $field)
		{
			if (array_key_exists($field, $record))
			{
				$value = $record[$field];
				if (is_string($value) && strpos($value, '[redacted ') === 0)
				{
					continue;
				}
				$export[$field] = $value;
			}
		}
		return $export;
	}
	protected function verificationChecks(array $item, array $record) { return array(); }
	protected function detailRoute($alias) { return null; }

	public function describe()
	{
		$missing = array();
		foreach ($this->tables() as $table)
		{
			if (!$this->tableExists($table))
			{
				$missing[] = $table;
			}
		}
		$missingColumns = array();
		foreach ($this->requiredColumns() as $table => $columns)
		{
			foreach ($columns as $column)
			{
				if (!$this->columnExists($table, $column))
				{
					$missingColumns[] = $table . '.' . $column;
				}
			}
		}
		$registered = $this->componentRegistered();

		return array(
			'component' => $this->component(),
			'adapter' => $this->name(),
			'installed' => $registered && is_dir(Component::path($this->component())),
			'supported' => !$missing && !$missingColumns,
			'manifest_section' => $this->section(),
			'entities' => $this->entities(),
			'operations' => array('describe', 'inspect', 'plan', 'apply', 'verify', 'export'),
			'natural_key' => 'alias',
			'dependencies' => $this->dependencies(),
			'risk' => 'content',
			'fields' => $this->fields(),
			'routes' => $this->routes(),
			'media_rules' => array(
				'root' => 'HUB_CONTENT_ROOT',
				'absolute_paths' => false,
				'path_traversal' => false,
				'executables' => false,
				'maximum_bytes' => (int) (getenv('HUB_CONTENT_MAX_BYTES') ?: 40000000),
			),
			'limitations' => $this->limitations(),
			'missing_tables' => $missing,
			'missing_columns' => $missingColumns,
			'extension_version' => $this->extensionVersion(),
		);
	}

	public function inspect()
	{
		$records = $this->liveRecords();
		if ($this->alias !== '')
		{
			$records = array_values(array_filter($records, function ($row)
			{
				return isset($row['alias']) && $row['alias'] === $this->alias;
			}));
		}
		if ($this->id)
		{
			$records = array_values(array_filter($records, function ($row)
			{
				return isset($row['id']) && (int) $row['id'] === $this->id;
			}));
		}
		$records = array_slice($records, 0, $this->maxItems);
		$records = array_map(array($this, 'redactRecord'), $records);

		return array('count' => count($records), 'records' => $records);
	}

	public function plan()
	{
		$items = isset($this->manifest[$this->section()])
			? (array) $this->manifest[$this->section()] : array();
		if ($this->alias)
		{
			$items = array_values(array_filter($items, function ($item)
			{
				return is_array($item)
					&& $this->manifestNaturalKey($item) === $this->alias;
			}));
		}
		if ($this->id)
		{
			$items = array();
		}
		$errors = array();
		$authorization = array();
		$files = array();

		if (count($items) > $this->maxItems)
		{
			$errors[] = sprintf('%s declares %d items; --max-items is %d',
				$this->section(), count($items), $this->maxItems);
		}

		$live = array();
		foreach ($this->liveRecords() as $record)
		{
			foreach ($this->liveNaturalKeys($record) as $key)
			{
				if (isset($live[$key]) && (int) $live[$key]['id'] !== (int) $record['id'])
				{
					$errors[] = "ambiguous live natural key '{$key}'";
				}
				$live[$key] = $record;
			}
		}

		$seen = array();
		$changes = array('create' => array(), 'update' => array(), 'unchanged' => array());
		foreach ($items as $index => $item)
		{
			if (!is_array($item))
			{
				$errors[] = $this->section() . '[' . $index . '] must be a mapping';
				continue;
			}
			$alias = $this->manifestNaturalKey($item);
			$title = isset($item['title']) ? trim((string) $item['title']) : '';
			if (!$this->validNaturalKey($alias))
			{
				$errors[] = $this->section() . '[' . $index . '] needs a valid natural key';
				continue;
			}
			if (!$title)
			{
				$errors[] = $alias . ': title is required';
			}
			if (isset($seen[$alias]))
			{
				$errors[] = "duplicate manifest alias '{$alias}'";
				continue;
			}
			$seen[$alias] = true;

			foreach ($item as $field => $value)
			{
				if (!in_array($field, $this->fields(), true))
				{
					$errors[] = "{$alias}: unsupported field '{$field}'";
				}
			}

			foreach ((array) (isset($item['files']) ? $item['files'] :
				(isset($item['attachments']) ? $item['attachments'] : array())) as $file)
			{
				if (!is_array($file) || empty($file['path']))
				{
					$errors[] = $alias . ': every file import needs a path';
				}
				else
				{
					$inspection = $this->inspectImport($file);
					if (isset($inspection['error']))
					{
						$errors[] = $alias . ': ' . $inspection['error'];
					}
					else
					{
						$files[] = array('alias' => $alias, 'path' => $file['path'],
							'bytes' => $inspection['bytes'],
							'detected_media_type' => $inspection['media_type']);
					}
				}
			}

			$this->validateItem($item, $errors, $authorization);
			if (!isset($live[$alias]))
			{
				$changes['create'][] = $alias;
			}
			elseif ($this->differs($item, $live[$alias]))
			{
				$changes['update'][] = $alias;
			}
			else
			{
				$changes['unchanged'][] = $alias;
			}
		}
		if (count($files) > $this->maxItems)
		{
			$errors[] = sprintf('manifest imports %d files; --max-items is %d',
				count($files), $this->maxItems);
		}
		$routes = $this->routes();
		foreach ($items as $item)
		{
			$key = is_array($item) ? $this->manifestNaturalKey($item) : '';
			$route = $key ? $this->detailRoute($key) : null;
			if ($route) $routes[] = $route;
		}

		return array(
			'target_section' => $this->section(),
			'max_items' => $this->maxItems,
			'changes' => $changes,
			'files' => $files,
			'authorization' => array_values(array_unique($authorization)),
			'errors' => $errors,
			'routes' => array_values(array_unique($routes)),
		);
	}

	protected function validateItem(array $item, array &$errors, array &$authorization)
	{
		if (isset($item['state']) && in_array($item['state'], array('published', 1, '1'), true))
		{
			$authorization[] = 'publish';
		}
		if (isset($item['published']) && (int) $item['published'] === 1)
		{
			$authorization[] = 'publish';
		}
	}

	protected function differs(array $item, array $live)
	{
		foreach (array('title', 'description', 'abstract', 'introtext') as $field)
		{
			if (array_key_exists($field, $item)
				&& (string) $item[$field] !== (string) (isset($live[$field]) ? $live[$field] : ''))
			{
				return true;
			}
		}
		return false;
	}

	public function verify()
	{
		$plan = $this->plan();
		$checks = array();
		$description = $this->describe();
		$checks[] = array(
			'name' => $this->component() . ' schema',
			'ok' => $description['installed'] && $description['supported'],
			'info' => $description['supported'] ? 'installed schema found'
				: 'missing: ' . implode(', ', array_merge(
					$description['missing_tables'], $description['missing_columns'])),
		);

		$live = array();
		foreach ($this->liveRecords() as $record)
		{
			foreach ($this->liveNaturalKeys($record) as $key)
			{
				$live[$key] = $record;
			}
		}
		$items = isset($this->manifest[$this->section()])
			? (array) $this->manifest[$this->section()] : array();
		if ($this->alias)
		{
			$items = array_values(array_filter($items, function ($item)
			{
				return is_array($item)
					&& $this->manifestNaturalKey($item) === $this->alias;
			}));
		}
		if ($this->id)
		{
			$items = array();
		}
		foreach ($items as $item)
		{
			$alias = is_array($item) ? $this->manifestNaturalKey($item) : '';
			if ($alias)
			{
				$found = isset($live[$alias]);
				$checks[] = array(
					'name' => $this->name() . ' ' . $alias,
					'ok' => $found,
					'info' => $found ? 'native record found' : 'missing',
				);
				if ($found)
				{
					$checks = array_merge($checks,
						$this->verificationChecks($item, $live[$alias]));
				}
			}
		}
		if ($this->id)
		{
			$selected = null;
			foreach ($this->liveRecords() as $record)
			{
				if (isset($record['id']) && (int) $record['id'] === $this->id)
				{
					$selected = $record;
					break;
				}
			}
			$checks[] = array('name' => $this->name() . ' id ' . $this->id,
				'ok' => (bool) $selected,
				'info' => $selected ? 'native record found' : 'missing');
		}

		$routes = $this->routes();
		foreach ($items as $item)
		{
			$key = is_array($item) ? $this->manifestNaturalKey($item) : '';
			$route = $key ? $this->detailRoute($key) : null;
			if ($route) $routes[] = $route;
		}
		return array('checks' => $checks, 'errors' => $plan['errors'],
			'routes' => array_values(array_unique($routes)));
	}

	public function export()
	{
		$records = $this->inspect();
		return array($this->section() => array_map(
			array($this, 'exportRecord'), $records['records']));
	}

	protected function tableExists($table)
	{
		$this->db->setQuery("SHOW TABLES LIKE " . $this->db->quote(str_replace('#__', $this->db->getPrefix(), $table)));
		return (bool) $this->db->loadResult();
	}

	protected function columnExists($table, $column)
	{
		if (!$this->tableExists($table))
		{
			return false;
		}
		$this->db->setQuery("SHOW COLUMNS FROM `" . $table . "` LIKE " . $this->db->quote($column));
		return (bool) $this->db->loadResult();
	}

	protected function componentRegistered()
	{
		$this->db->setQuery("SELECT COUNT(*) FROM `#__extensions`
			WHERE `type` = 'component' AND `element` = " . $this->db->quote($this->component()));
		return (int) $this->db->loadResult() === 1;
	}

	protected function extensionVersion()
	{
		$this->db->setQuery("SELECT `manifest_cache` FROM `#__extensions`
			WHERE `type` = 'component' AND `element` = " . $this->db->quote($this->component()));
		$cache = json_decode((string) $this->db->loadResult(), true);
		return is_array($cache) && isset($cache['version']) ? $cache['version'] : null;
	}

	protected function rows($query)
	{
		$this->db->setQuery($query);
		return array_map(function ($row) { return (array) $row; }, (array) $this->db->loadObjectList());
	}

	protected function declaredAlias($section, $alias)
	{
		foreach ((array) (isset($this->manifest[$section]) ? $this->manifest[$section] : array()) as $item)
		{
			if (is_array($item) && isset($item['alias']) && $item['alias'] === $alias)
			{
				return true;
			}
		}
		return false;
	}

	protected function declaredUsername($username)
	{
		foreach ((array) (isset($this->manifest['users']) ? $this->manifest['users'] : array()) as $item)
		{
			if (is_array($item) && isset($item['username']) && $item['username'] === $username)
			{
				return true;
			}
		}
		return false;
	}

	protected function exists($table, $column, $value)
	{
		$this->db->setQuery("SELECT COUNT(*) FROM `" . $table . "` WHERE `" . $column . "` = "
			. $this->db->quote($value));
		return (int) $this->db->loadResult() === 1;
	}

	protected function inspectImport(array $spec)
	{
		$path = isset($spec['path']) ? str_replace('\\', '/', trim((string) $spec['path'])) : '';
		if (!$path || $path[0] === '/' || preg_match('~(^|/)\.\.(/|$)~', $path))
		{
			return array('error' => 'unsafe or missing content path');
		}
		if (strpos($path, 'content/') === 0) $path = substr($path, 8);
		if (getenv('HUB_CONTENT_DEFERRED') === '1')
		{
			return array('bytes' => 0, 'media_type' => 'deferred-to-staged-validation');
		}
		$root = realpath(getenv('HUB_CONTENT_ROOT') ?: '/etc/hubzero/content');
		if (!$root)
		{
			return array('error' => 'approved content root does not exist');
		}
		$source = realpath($root . '/' . $path);
		if (!$source || !is_file($source)
			|| strpos($source . '/', rtrim($root, '/') . '/') !== 0)
		{
			return array('error' => 'content path is missing or escapes the approved root');
		}
		$bytes = filesize($source);
		$maximum = (int) (getenv('HUB_CONTENT_MAX_BYTES') ?: 40000000);
		if ($bytes <= 0 || $bytes > $maximum)
		{
			return array('error' => "content file is empty or exceeds {$maximum} bytes");
		}
		$mime = (new finfo(FILEINFO_MIME_TYPE))->file($source);
		$forbidden = array('application/x-httpd-php', 'application/x-php',
			'application/x-executable', 'application/x-sharedlib',
			'application/x-shellscript', 'application/x-pie-executable',
			'application/x-mach-binary', 'application/vnd.microsoft.portable-executable',
			'application/x-dosexec', 'text/x-php');
		$signature = file_get_contents($source, false, null, 0, 4);
		if (in_array($mime, $forbidden, true) || $signature === "\x7fELF"
			|| substr($signature, 0, 2) === 'MZ' || substr($signature, 0, 2) === '#!')
		{
			return array('error' => 'executable content is forbidden');
		}
		$declared = !empty($spec['media_type']) ? strtolower($spec['media_type']) : '';
		$compatible = array('text/csv' => array('text/csv', 'text/plain'),
			'application/json' => array('application/json', 'text/plain'));
		if ($declared && $declared !== strtolower($mime)
			&& (!isset($compatible[$declared])
				|| !in_array(strtolower($mime), $compatible[$declared], true)))
		{
			return array('error' => "declared media_type does not match detected '{$mime}'");
		}
		if (strpos($mime, 'image/') === 0 && @getimagesize($source) === false)
		{
			return array('error' => 'image content cannot be decoded');
		}
		if (!\Filesystem::isSafe($source))
		{
			return array('error' => 'content file failed the runtime safety scan');
		}
		return array('bytes' => $bytes, 'media_type' => $mime);
	}
}
