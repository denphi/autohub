<?php
class AutoHubResourcesAdapter extends AutoHubComponentAdapter
{
	public function name() { return 'resources'; }
	public function component() { return 'com_resources'; }
	public function section() { return 'resources'; }
	public function entities() { return array('resource', 'resource_type', 'contributor', 'tag', 'attachment'); }
	public function dependencies() { return array('com_members', 'com_tags', 'resource_types'); }
	public function tables()
	{
		return array('#__resources', '#__resource_types', '#__resource_assoc',
			'#__author_assoc', '#__tags_object');
	}
	public function requiredColumns()
	{
		return array('#__resources' => array('alias', 'path', 'standalone', 'published'),
			'#__resource_assoc' => array('parent_id', 'child_id'),
			'#__author_assoc' => array('subtable', 'subid', 'authorid'),
			'#__tags_object' => array('objectid', 'tagid', 'tbl'));
	}
	public function fields()
	{
		return array('alias', 'title', 'type', 'introtext', 'fulltxt',
			'published', 'access', 'contributors', 'tags', 'files');
	}
	public function routes() { return array('/resources', '/resources/browse'); }
	public function limitations()
	{
		return array('File imports create native child resource records; removal is intentionally unsupported.');
	}
	protected function manifestNaturalKey(array $item)
	{
		$alias = isset($item['alias']) ? trim((string) $item['alias']) : '';
		return $alias ?: 'title:' . trim(isset($item['title']) ? (string) $item['title'] : '');
	}
	protected function liveNaturalKeys(array $record)
	{
		$keys = !empty($record['alias']) ? array($record['alias']) : array();
		if (!empty($record['title'])) $keys[] = 'title:' . $record['title'];
		return $keys;
	}
	protected function validNaturalKey($key)
	{
		return strpos($key, 'title:') === 0
			? strlen(substr($key, 6)) > 0
			: parent::validNaturalKey($key);
	}
	protected function validateItem(array $item, array &$errors, array &$authorization)
	{
		parent::validateItem($item, $errors, $authorization);
		$key = $this->manifestNaturalKey($item);
		$type = isset($item['type']) ? $item['type'] : '';
		if (!$type || (!$this->declaredAlias('resource_types', $type)
			&& !$this->exists('#__resource_types', 'alias', $type)))
		{
			$errors[] = $key . ": unknown resource type '{$type}'";
		}
		foreach ((array) (isset($item['contributors']) ? $item['contributors'] : array()) as $entry)
		{
			$username = is_array($entry)
				? (isset($entry['username']) ? $entry['username'] : '') : $entry;
			if (!$username || (!$this->declaredUsername($username)
				&& !$this->exists('#__users', 'username', $username)))
			{
				$errors[] = $key . ": unknown contributor '{$username}'";
			}
		}
	}
	protected function liveRecords()
	{
		$where = $this->alias ? " WHERE r.`alias` = " . $this->db->quote($this->alias) : '';
		return $this->rows("SELECT r.`id`, r.`alias`, r.`title`, t.`alias` AS `type`,
			r.`introtext`, r.`fulltxt`, r.`published`, r.`access`,
			(SELECT COUNT(*) FROM `#__author_assoc` AS au
				WHERE au.`subtable` = 'resources' AND au.`subid` = r.`id`) AS `contributor_count`,
			(SELECT COUNT(*) FROM `#__resource_assoc` AS ra
				WHERE ra.`parent_id` = r.`id`) AS `file_count`,
			(SELECT COUNT(*) FROM `#__tags_object` AS tag
				WHERE tag.`tbl` = 'resources' AND tag.`objectid` = r.`id`) AS `tag_count`
			FROM `#__resources` AS r
			LEFT JOIN `#__resource_types` AS t ON t.`id` = r.`type`{$where}
			ORDER BY r.`id`");
	}
	protected function differs(array $item, array $live)
	{
		if (parent::differs($item, $live)) return true;
		foreach (array('published', 'access') as $field)
		{
			if (isset($item[$field]) && (int) $item[$field] !== (int) $live[$field]) return true;
		}
		return isset($item['type']) && (string) $item['type'] !== (string) $live['type'];
	}
	protected function redactRecord(array $record)
	{
		if ((int) $record['published'] !== 1 || (int) $record['access'] !== 0)
		{
			$record['introtext'] = '[redacted private or unpublished content]';
			$record['fulltxt'] = '[redacted private or unpublished content]';
		}
		return $record;
	}
	protected function detailRoute($alias)
	{
		return strpos($alias, 'title:') === 0 ? null : '/resources/' . rawurlencode($alias);
	}
	protected function verificationChecks(array $item, array $record)
	{
		$checks = array();
		foreach (array('contributors' => 'contributor_count', 'files' => 'file_count',
			'tags' => 'tag_count') as $field => $count)
		{
			if (isset($item[$field]))
			{
				$expected = count((array) $item[$field]);
				$checks[] = array('name' => 'resource ' . $field . ' ' . $this->manifestNaturalKey($item),
					'ok' => (int) $record[$count] >= $expected,
					'info' => $record[$count] . ' native association(s)');
			}
		}
		return $checks;
	}
}
