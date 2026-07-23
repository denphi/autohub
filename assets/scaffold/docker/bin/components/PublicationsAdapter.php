<?php
class AutoHubPublicationsAdapter extends AutoHubComponentAdapter
{
	public function name() { return 'publications'; }
	public function component() { return 'com_publications'; }
	public function section() { return 'publications'; }
	public function entities() { return array('publication', 'version', 'author', 'attachment'); }
	public function dependencies()
	{
		return array('com_projects', 'com_members', 'publication categories',
			'publication master types', 'publication licenses');
	}
	public function tables()
	{
		return array('#__publications', '#__publication_versions',
			'#__publication_authors', '#__publication_attachments',
			'#__publication_categories', '#__publication_master_types',
			'#__publication_licenses');
	}
	public function requiredColumns()
	{
		return array('#__publications' => array('alias', 'project_id', 'master_type'),
			'#__publication_versions' => array('publication_id', 'main', 'secret', 'state'),
			'#__publication_attachments' => array('publication_version_id', 'path', 'attribs'));
	}
	public function fields()
	{
		return array('alias', 'title', 'project', 'abstract', 'description',
			'category', 'license', 'authors', 'state', 'attachments');
	}
	public function routes() { return array('/publications', '/publications/browse'); }
	public function limitations()
	{
		return array('Alternative text, credit, and attachment descriptions are stored in native attachment attribs.',
			'Published-version replacement and withdrawal are intentionally unsupported.');
	}
	protected function validateItem(array $item, array &$errors, array &$authorization)
	{
		parent::validateItem($item, $errors, $authorization);
		if (empty($item['project']))
		{
			$errors[] = $item['alias'] . ': project is required';
		}
		elseif (!$this->declaredAlias('projects', $item['project'])
			&& !$this->exists('#__projects', 'alias', $item['project']))
		{
			$errors[] = $item['alias'] . ": unknown project '" . $item['project'] . "'";
		}
		elseif ($this->declaredAlias('projects', $item['project'])
			&& !preg_match('/^[a-z][a-z0-9]{2,29}$/', $item['project']))
		{
			$errors[] = $item['alias'] . ": referenced project alias is invalid for this HUBzero revision";
		}
		if (empty($item['authors']))
		{
			$errors[] = $item['alias'] . ': at least one author is required';
		}
		if (isset($item['access']))
		{
			$authorization[] = 'access-change';
		}
		foreach ((array) (isset($item['authors']) ? $item['authors'] : array()) as $entry)
		{
			$username = is_array($entry)
				? (isset($entry['username']) ? $entry['username'] : '') : $entry;
			if (!$username || (!$this->declaredUsername($username)
				&& !$this->exists('#__users', 'username', $username)))
			{
				$errors[] = $item['alias'] . ": unknown author '{$username}'";
			}
		}
		$category = isset($item['category']) ? $item['category'] : 'dataset';
		$this->db->setQuery("SELECT COUNT(*) FROM `#__publication_categories`
			WHERE `state` = 1 AND (`alias` = " . $this->db->quote($category)
			. " OR `url_alias` = " . $this->db->quote($category) . ")");
		if ((int) $this->db->loadResult() !== 1)
		{
			$errors[] = $item['alias'] . ": unknown active publication category '{$category}'";
		}
		if (!empty($item['license']))
		{
			$license = $item['license'];
			$this->db->setQuery("SELECT COUNT(*) FROM `#__publication_licenses`
				WHERE `active` = 1 AND (`id` = " . (int) $license
				. " OR `name` = " . $this->db->quote($license)
				. " OR `title` = " . $this->db->quote($license) . ")");
			if ((int) $this->db->loadResult() !== 1)
			{
				$errors[] = $item['alias'] . ": unknown active publication license '{$license}'";
			}
		}
		$attachments = (array) (isset($item['attachments']) ? $item['attachments'] : array());
		$allImages = !empty($attachments);
		foreach ($attachments as $attachment)
		{
			$allImages = $allImages && is_array($attachment)
				&& !empty($attachment['media_type'])
				&& strpos(strtolower($attachment['media_type']), 'image/') === 0;
		}
		$master = $allImages ? 'gallery' : 'files';
		if (!$this->exists('#__publication_master_types', 'alias', $master))
		{
			$errors[] = $item['alias'] . ": publication master type '{$master}' is unavailable";
		}
	}
	protected function liveRecords()
	{
		$where = $this->alias ? " WHERE p.`alias` = " . $this->db->quote($this->alias) : '';
		return $this->rows("SELECT p.`id`, p.`alias`, v.`title`, pr.`alias` AS `project`,
			v.`abstract`, v.`description`, v.`state`, v.`version_number`,
			v.`license_type`, v.`access`,
			(SELECT COUNT(*) FROM `#__publication_authors` AS a
				WHERE a.`publication_version_id` = v.`id` AND a.`status` = 1) AS `author_count`,
			(SELECT COUNT(*) FROM `#__publication_attachments` AS x
				WHERE x.`publication_version_id` = v.`id`) AS `attachment_count`
			FROM `#__publications` AS p
			LEFT JOIN `#__publication_versions` AS v ON v.`publication_id` = p.`id` AND v.`main` = 1
			LEFT JOIN `#__projects` AS pr ON pr.`id` = p.`project_id`{$where}
			ORDER BY p.`id`");
	}
	protected function differs(array $item, array $live)
	{
		if (parent::differs($item, $live)) return true;
		$states = array('draft' => 3, 'published' => 1, 'pending' => 5);
		if (isset($item['state']) && isset($states[$item['state']])
			&& $states[$item['state']] !== (int) $live['state']) return true;
		if (isset($item['project']) && $item['project'] !== $live['project']) return true;
		if (isset($item['authors'])
			&& count((array) $item['authors']) > (int) $live['author_count']) return true;
		if (isset($item['attachments'])
			&& count((array) $item['attachments']) > (int) $live['attachment_count']) return true;
		return false;
	}
	protected function redactRecord(array $record)
	{
		if ((int) $record['state'] !== 1 || (int) $record['access'] !== 0)
		{
			$record['abstract'] = '[redacted private or unpublished content]';
			$record['description'] = '[redacted private or unpublished content]';
		}
		return $record;
	}
	protected function detailRoute($alias) { return '/publications/' . rawurlencode($alias); }
	protected function verificationChecks(array $item, array $record)
	{
		$checks = array();
		foreach (array('authors' => 'author_count', 'attachments' => 'attachment_count') as $field => $count)
		{
			if (isset($item[$field]))
			{
				$expected = count((array) $item[$field]);
				$checks[] = array('name' => 'publication ' . $field . ' ' . $item['alias'],
					'ok' => (int) $record[$count] >= $expected,
					'info' => $record[$count] . ' native record(s)');
			}
		}
		return $checks;
	}
}
