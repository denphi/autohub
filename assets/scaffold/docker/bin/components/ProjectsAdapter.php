<?php
class AutoHubProjectsAdapter extends AutoHubComponentAdapter
{
	public function name() { return 'projects'; }
	public function component() { return 'com_projects'; }
	public function section() { return 'projects'; }
	public function entities() { return array('project', 'owner', 'repository'); }
	public function dependencies() { return array('com_members', 'declared users'); }
	public function tables() { return array('#__projects', '#__project_owners'); }
	public function requiredColumns()
	{
		return array('#__projects' => array('alias', 'about', 'state', 'access'),
			'#__project_owners' => array('projectid', 'userid', 'role', 'status'));
	}
	public function fields()
	{
		return array('alias', 'title', 'description', 'status', 'access', 'team');
	}
	public function routes() { return array('/projects'); }
	public function limitations()
	{
		return array('Invitations, member removal, ownership transfer, and repository migration are not automated.');
	}
	protected function validateItem(array $item, array &$errors, array &$authorization)
	{
		parent::validateItem($item, $errors, $authorization);
		$alias = isset($item['alias']) ? $item['alias'] : '';
		if (!preg_match('/^[a-z][a-z0-9]{2,29}$/', $alias))
		{
			$errors[] = $alias . ': this HUBzero revision requires project aliases to be 3-30 lowercase alphanumeric characters';
		}
		if (!empty($item['team']))
		{
			$authorization[] = 'membership-change';
			$managerCount = 0;
			foreach ((array) $item['team'] as $member)
			{
				if (!is_array($member) || empty($member['username']))
				{
					$errors[] = $item['alias'] . ': every team member needs username';
				}
				elseif (!$this->declaredUsername($member['username'])
					&& !$this->exists('#__users', 'username', $member['username']))
				{
					$errors[] = $item['alias'] . ": unknown team user '" . $member['username'] . "'";
				}
				$role = isset($member['role']) ? $member['role'] : 'collaborator';
				if ($role === 'manager') $managerCount++;
				if (!in_array($role, array('manager', 'collaborator'), true))
				{
					$errors[] = $item['alias'] . ": unsupported team role '{$role}'";
				}
			}
			if (!$managerCount)
			{
				$errors[] = $item['alias'] . ': at least one team member must be a manager';
			}
		}
		if (isset($item['access']))
		{
			$authorization[] = 'access-change';
		}
		if (isset($item['status']) && $item['status'] === 'archived')
		{
			$authorization[] = 'archive';
		}
	}
	protected function liveRecords()
	{
		$where = $this->alias ? " WHERE p.`alias` = " . $this->db->quote($this->alias) : '';
		return $this->rows("SELECT p.`id`, p.`alias`, p.`title`, p.`about` AS `description`,
			p.`state` AS `status`, p.`access`,
			(SELECT COUNT(*) FROM `#__project_owners` AS o
				WHERE o.`projectid` = p.`id` AND o.`status` = 1) AS `team_count`
			FROM `#__projects` AS p{$where}
			ORDER BY p.`id`");
	}
	protected function differs(array $item, array $live)
	{
		if (parent::differs($item, $live)) return true;
		$states = array('active' => 1, 'archived' => 3, 'pending' => 5);
		$access = array('public' => 0, 'private' => 1, 'open' => -1);
		if (isset($item['status']) && isset($states[$item['status']])
			&& $states[$item['status']] !== (int) $live['status']) return true;
		if (isset($item['access']) && isset($access[$item['access']])
			&& $access[$item['access']] !== (int) $live['access']) return true;
		if (isset($item['team']) && count((array) $item['team']) > (int) $live['team_count']) return true;
		return false;
	}
	protected function redactRecord(array $record)
	{
		if ((int) $record['access'] !== 0 || (int) $record['status'] !== 1)
		{
			$record['description'] = '[redacted private or unpublished content]';
		}
		return $record;
	}
	protected function detailRoute($alias) { return '/projects/' . rawurlencode($alias); }
	protected function verificationChecks(array $item, array $record)
	{
		$expected = count((array) (isset($item['team']) ? $item['team'] : array()));
		return $expected ? array(array('name' => 'project team ' . $item['alias'],
			'ok' => (int) $record['team_count'] >= $expected,
			'info' => $record['team_count'] . ' active native owner(s)')) : array();
	}
}
