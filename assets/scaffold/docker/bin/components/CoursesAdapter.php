<?php
class AutoHubCoursesAdapter extends AutoHubComponentAdapter
{
	public function name() { return 'courses'; }
	public function component() { return 'com_courses'; }
	public function section() { return 'courses'; }
	public function entities()
	{
		return array('course', 'offering', 'section', 'unit', 'asset_group', 'asset');
	}
	public function dependencies()
	{
		return array('internal OAuth client', 'referenced resources and publications');
	}
	public function tables()
	{
		return array('#__courses', '#__courses_offerings', '#__courses_units',
			'#__courses_asset_groups', '#__courses_assets', '#__courses_asset_associations');
	}
	public function requiredColumns()
	{
		return array('#__courses' => array('alias', 'state', 'access'),
			'#__courses_units' => array('offering_id', 'alias', 'ordering'),
			'#__courses_assets' => array('course_id', 'type', 'subtype', 'url'),
			'#__courses_asset_associations' => array('asset_id', 'scope_id', 'scope'));
	}
	public function fields()
	{
		return array('alias', 'title', 'description', 'blurb', 'state',
			'access', 'length', 'effort', 'units');
	}
	public function routes() { return array('/courses'); }
	public function limitations()
	{
		return array('Only native text content, URL, resource, and publication reference assets are supported.',
			'Enrollment, grading, progress, and learner records are never mutated or exported.');
	}
	protected function validateItem(array $item, array &$errors, array &$authorization)
	{
		parent::validateItem($item, $errors, $authorization);
		$unitAliases = array();
		foreach ((array) (isset($item['units']) ? $item['units'] : array()) as $unit)
		{
			$alias = is_array($unit) && isset($unit['alias']) ? $unit['alias'] : '';
			if (!$alias || isset($unitAliases[$alias]))
			{
				$errors[] = $item['alias'] . ': unit aliases must be present and unique';
			}
			$unitAliases[$alias] = true;
			$assetTitles = array();
			foreach ((array) (isset($unit['assets']) ? $unit['assets'] : array()) as $asset)
			{
				$type = is_array($asset) && isset($asset['type']) ? $asset['type'] : '';
				$title = is_array($asset) && isset($asset['title']) ? trim($asset['title']) : ucfirst($type);
				if (!$title || isset($assetTitles[$title]))
				{
					$errors[] = $item['alias'] . ": asset titles must be present and unique within unit '{$alias}'";
				}
				$assetTitles[$title] = true;
				if (!in_array($type, array('content', 'url', 'resource', 'publication'), true))
				{
					$errors[] = $item['alias'] . ": unsupported course asset type '{$type}'";
				}
				elseif ($type === 'resource')
				{
					$alias = isset($asset['resource']) ? $asset['resource'] : '';
					if (!$this->declaredAlias('resources', $alias)
						&& !$this->exists('#__resources', 'alias', $alias))
					{
						$errors[] = $item['alias'] . ": unknown resource '{$alias}'";
					}
				}
				elseif ($type === 'publication')
				{
					$alias = isset($asset['publication']) ? $asset['publication'] : '';
					if (!$this->declaredAlias('publications', $alias)
						&& !$this->exists('#__publications', 'alias', $alias))
					{
						$errors[] = $item['alias'] . ": unknown publication '{$alias}'";
					}
				}
				elseif ($type === 'content' && !isset($asset['content']))
				{
					$errors[] = $item['alias'] . ": content asset '{$title}' needs content";
				}
				elseif ($type === 'url')
				{
					$url = isset($asset['url']) ? $asset['url'] : '';
					if (!$url || (!preg_match('~^https?://~i', $url) && $url[0] !== '/'))
					{
						$errors[] = $item['alias'] . ": URL asset '{$title}' needs an HTTP(S) or site-relative URL";
					}
				}
			}
		}
		if (isset($item['access']))
		{
			$authorization[] = 'access-change';
		}
	}
	protected function liveRecords()
	{
		$where = $this->alias ? " WHERE c.`alias` = " . $this->db->quote($this->alias) : '';
		return $this->rows("SELECT c.`id`, c.`alias`, c.`title`, c.`description`,
			c.`blurb`, c.`state`, c.`access`, c.`length`, c.`effort`,
			COUNT(DISTINCT u.`id`) AS `unit_count`,
			(SELECT COUNT(DISTINCT a.`id`) FROM `#__courses_assets` AS a
				WHERE a.`course_id` = c.`id`) AS `asset_count`
			FROM `#__courses` AS c
			LEFT JOIN `#__courses_offerings` AS o ON o.`course_id` = c.`id`
			LEFT JOIN `#__courses_units` AS u ON u.`offering_id` = o.`id`{$where}
			GROUP BY c.`id` ORDER BY c.`id`");
	}
	protected function differs(array $item, array $live)
	{
		if (parent::differs($item, $live)) return true;
		$states = array('draft' => 0, 'published' => 1);
		if (isset($item['state']) && isset($states[$item['state']])
			&& $states[$item['state']] !== (int) $live['state']) return true;
		if (isset($item['access']) && (int) $item['access'] !== (int) $live['access']) return true;
		return isset($item['units'])
			&& count((array) $item['units']) > (int) $live['unit_count'];
	}
	protected function redactRecord(array $record)
	{
		if ((int) $record['state'] !== 1 || (int) $record['access'] !== 0)
		{
			$record['blurb'] = '[redacted private or unpublished content]';
			$record['description'] = '[redacted private or unpublished content]';
		}
		return $record;
	}
	protected function detailRoute($alias) { return '/courses/' . rawurlencode($alias); }
	protected function verificationChecks(array $item, array $record)
	{
		$units = (array) (isset($item['units']) ? $item['units'] : array());
		$assets = 0;
		foreach ($units as $unit)
		{
			$assets += count((array) (isset($unit['assets']) ? $unit['assets'] : array()));
		}
		$this->db->setQuery("SELECT COUNT(*) FROM `#__developer_applications`
			WHERE `hub_account` = 1 AND `state` = 1");
		$oauth = (int) $this->db->loadResult();
		return array(
			array('name' => 'course internal OAuth client',
				'ok' => $oauth === 1,
				'info' => $oauth === 1 ? 'active hub account client found' : 'missing or ambiguous'),
			array('name' => 'course units ' . $item['alias'],
				'ok' => (int) $record['unit_count'] >= count($units),
				'info' => $record['unit_count'] . ' native unit(s)'),
			array('name' => 'course assets ' . $item['alias'],
				'ok' => (int) $record['asset_count'] >= $assets,
				'info' => $record['asset_count'] . ' native asset(s)'),
		);
	}
}
