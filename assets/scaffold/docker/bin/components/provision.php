<?php
/**
 * Native provisioning for Projects, Resources, Publications, and Courses.
 *
 * This file is loaded by provision.php after declared users and groups exist.
 * All relationships are additive: missing manifest entries never imply delete.
 */

function autohub_user($username, $db)
{
	$user = \Hubzero\User\User::oneByUsername((string) $username);
	if (!$user || !$user->get('id'))
	{
		throw new Exception("unknown user '{$username}'");
	}
	return $user;
}

function autohub_state($value, array $states, $default)
{
	if ($value === null || $value === '')
	{
		return $default;
	}
	if (is_numeric($value))
	{
		return (int) $value;
	}
	if (!array_key_exists((string) $value, $states))
	{
		throw new Exception("unsupported state '{$value}'");
	}
	return $states[(string) $value];
}

function autohub_authorized($name)
{
	$grants = array_filter(array_map('trim',
		explode(',', getenv('HUB_COMPONENT_AUTHORIZATION') ?: '')));
	return in_array($name, $grants, true);
}

/**
 * Resolve and inspect a user-approved import without trusting its filename.
 */
function autohub_import_file(array $spec)
{
	$root = realpath(getenv('HUB_CONTENT_ROOT') ?: '/etc/hubzero/content');
	$path = isset($spec['path']) ? str_replace('\\', '/', trim((string) $spec['path'])) : '';
	if (!$root || !$path || $path[0] === '/' || preg_match('~(^|/)\.\.(/|$)~', $path))
	{
		throw new Exception("unsafe or missing content path");
	}
	// `content/foo` is accepted because manifests commonly describe paths from
	// the host project root while the mount itself already points at content/.
	if (strpos($path, 'content/') === 0)
	{
		$path = substr($path, 8);
	}
	$source = realpath($root . '/' . $path);
	if (!$source || !is_file($source)
		|| strpos($source . '/', rtrim($root, '/') . '/') !== 0)
	{
		throw new Exception("content path is missing or escapes the approved root");
	}
	$max = (int) (getenv('HUB_CONTENT_MAX_BYTES') ?: 40000000);
	$size = filesize($source);
	if ($size <= 0 || $size > $max)
	{
		throw new Exception("content file is empty or exceeds {$max} bytes");
	}
	$mime = (new finfo(FILEINFO_MIME_TYPE))->file($source);
	$forbidden = array('application/x-httpd-php', 'application/x-php',
		'application/x-executable', 'application/x-sharedlib',
		'application/x-shellscript', 'application/x-pie-executable',
		'application/x-mach-binary', 'application/vnd.microsoft.portable-executable',
		'application/x-dosexec', 'text/x-php');
	if (in_array($mime, $forbidden, true))
	{
		throw new Exception("executable content type '{$mime}' is forbidden");
	}
	$signature = file_get_contents($source, false, null, 0, 4);
	if ($signature === "\x7fELF" || substr($signature, 0, 2) === 'MZ'
		|| substr($signature, 0, 2) === '#!')
	{
		throw new Exception("executable file signature is forbidden");
	}
	$declared = !empty($spec['media_type']) ? strtolower($spec['media_type']) : '';
	$compatible = array(
		'text/csv' => array('text/csv', 'text/plain'),
		'application/json' => array('application/json', 'text/plain'),
	);
	if ($declared && strtolower($mime) !== $declared
		&& (!isset($compatible[$declared]) || !in_array(strtolower($mime), $compatible[$declared], true)))
	{
		throw new Exception("declared media_type does not match detected '{$mime}'");
	}
	if (strpos($mime, 'image/') === 0 && @getimagesize($source) === false)
	{
		throw new Exception("image content cannot be decoded");
	}
	if (!\Filesystem::isSafe($source))
	{
		throw new Exception("content file failed the runtime safety scan");
	}
	$original = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($source));
	if (!$original || $original[0] === '.')
	{
		throw new Exception("unsafe content filename");
	}
	$hash = hash_file('sha256', $source);
	$extension = pathinfo($original, PATHINFO_EXTENSION);
	$stem = pathinfo($original, PATHINFO_FILENAME);
	$name = $stem . '-' . substr($hash, 0, 12) . ($extension ? '.' . $extension : '');
	return array('source' => $source, 'name' => $name, 'original_name' => $original,
		'mime' => $mime, 'size' => $size, 'sha256' => $hash);
}

function autohub_mkdir($path)
{
	if (!is_dir($path) && !\Filesystem::makeDirectory($path, 0755, true, true))
	{
		throw new Exception("cannot create component storage directory");
	}
}

function autohub_resource_relationships(array $manifest, $db)
{
	if (empty($manifest['resources']))
	{
		return;
	}
	require_once Component::path('com_resources') . '/models/entry.php';
	require_once Component::path('com_resources') . '/helpers/tags.php';

	foreach ((array) $manifest['resources'] as $spec)
	{
		$title = isset($spec['title']) ? $spec['title'] : '';
		$alias = isset($spec['alias']) ? $spec['alias'] : '';
		attempt("resource relationships " . ($alias ?: $title), function () use ($spec, $title, $alias, $db)
		{
			$where = $alias ? "`alias` = " . $db->quote($alias) : "`title` = " . $db->quote($title);
			$db->setQuery("SELECT `id` FROM `#__resources` WHERE {$where}");
			$id = (int) $db->loadResult();
			if (!$id)
			{
				throw new Exception("native resource record was not found");
			}

			if (isset($spec['contributors']))
			{
				$order = 1;
				foreach ((array) $spec['contributors'] as $contributor)
				{
					$username = is_array($contributor)
						? (isset($contributor['username']) ? $contributor['username'] : '')
						: $contributor;
					$user = autohub_user($username, $db);
					$name = $user->get('name') ?: $username;
					$db->setQuery("INSERT INTO `#__author_assoc`
						(`subtable`, `subid`, `authorid`, `ordering`, `role`, `name`)
						VALUES ('resources', " . $id . ", " . (int) $user->get('id') . ",
							" . $order . ", " . $db->quote(is_array($contributor) && isset($contributor['role'])
								? $contributor['role'] : '') . ", " . $db->quote($name) . ")
						ON DUPLICATE KEY UPDATE `ordering` = VALUES(`ordering`),
							`role` = VALUES(`role`), `name` = VALUES(`name`)");
					$db->query();
					$order++;
				}
			}

			if (isset($spec['tags']))
			{
				$tags = new \Components\Resources\Helpers\Tags($id);
				if (!$tags->setTags(implode(',', (array) $spec['tags']), 0, 0))
				{
					throw new Exception("native tag association failed");
				}
			}

			foreach ((array) (isset($spec['files']) ? $spec['files'] : array()) as $fileSpec)
			{
				$file = autohub_import_file((array) $fileSpec);
				$db->setQuery("SELECT c.`id` FROM `#__resources` AS c
					INNER JOIN `#__resource_assoc` AS a ON a.`child_id` = c.`id`
					WHERE a.`parent_id` = {$id} AND (c.`path` = " . $db->quote($file['name'])
						. " OR c.`path` LIKE " . $db->quote('%/' . $file['name']) . ")");
				$childId = (int) $db->loadResult();
				if ($childId)
				{
					continue; // Existing native file records are immutable here.
				}

				$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
				$types = array('pdf' => 33, 'zip' => 38, 'tar' => 38,
					'mov' => 15, 'swf' => 32, 'ppt' => 35,
					'asf' => 37, 'asx' => 37, 'wmv' => 37);
				$child = \Components\Resources\Models\Entry::blank();
				$child->set(array(
					'title' => isset($fileSpec['title']) ? $fileSpec['title'] : $file['original_name'],
					'introtext' => isset($fileSpec['description']) ? $fileSpec['description'] : $file['original_name'],
					'created' => gmdate('Y-m-d H:i:s'), 'created_by' => 0,
					'published' => 1, 'publish_up' => gmdate('Y-m-d H:i:s'),
					'standalone' => 0, 'access' => 0, 'path' => '',
					'type' => isset($types[$extension]) ? $types[$extension] : 13,
				));
				if (!$child->save())
				{
					throw new Exception($child->getError());
				}
				$destination = $child->filespace();
				autohub_mkdir($destination);
				if (!copy($file['source'], $destination . '/' . $file['name']))
				{
					throw new Exception("resource file copy failed");
				}
				$child->set('path', ltrim($child->relativepath() . '/' . $file['name'], '/'));
				if (!$child->save() || !$child->makeChildOf($id))
				{
					throw new Exception($child->getError() ?: "resource association failed");
				}
			}
			return true;
		});
	}
}

function autohub_projects(array $manifest, $db)
{
	if (empty($manifest['projects']))
	{
		return;
	}
	require_once Component::path('com_projects') . '/models/orm/project.php';
	require_once Component::path('com_projects') . '/models/orm/owner.php';
	require_once Component::path('com_projects') . '/models/repo.php';

	foreach ((array) $manifest['projects'] as $spec)
	{
		$alias = isset($spec['alias']) ? trim((string) $spec['alias']) : '';
		attempt("project {$alias}", function () use ($spec, $alias, $db)
		{
			if (!preg_match('/^[a-z][a-z0-9]{2,29}$/', $alias) || empty($spec['title']))
			{
				throw new Exception("project alias must be 3-30 lowercase alphanumeric characters");
			}
			$db->setQuery("SELECT `id` FROM `#__projects` WHERE `alias` = " . $db->quote($alias));
			$id = (int) $db->loadResult();
			$isNew = !$id;
			$project = $id
				? \Components\Projects\Models\Orm\Project::one($id)
				: \Components\Projects\Models\Orm\Project::blank();

			$team = isset($spec['team']) ? (array) $spec['team'] : array();
			if ($team && !autohub_authorized('membership-change'))
			{
				throw new Exception("team changes require --authorize membership-change");
			}
			if (isset($spec['access']) && !autohub_authorized('access-change'))
			{
				throw new Exception("project access changes require --authorize access-change");
			}
			if (isset($spec['status']) && $spec['status'] === 'archived'
				&& !autohub_authorized('archive'))
			{
				throw new Exception("project archival requires --authorize archive");
			}
			$owner = null;
			foreach ($team as $candidate)
			{
				if (!is_array($candidate) || empty($candidate['username']))
				{
					throw new Exception("every project team member needs a username and role");
				}
				if (isset($candidate['role']) && $candidate['role'] === 'manager' && !$owner)
				{
					$owner = autohub_user($candidate['username'], $db);
				}
			}
			if ($isNew && !$owner)
			{
				throw new Exception("new projects require at least one declared team manager");
			}
			$fields = array('alias' => $alias, 'title' => $spec['title']);
			if (array_key_exists('description', $spec)) $fields['about'] = $spec['description'];
			if (array_key_exists('status', $spec))
			{
				$fields['state'] = autohub_state($spec['status'],
					array('active' => 1, 'archived' => 3, 'pending' => 5), 1);
			}
			if (array_key_exists('access', $spec))
			{
				$fields['access'] = autohub_state($spec['access'],
					array('public' => 0, 'private' => 1, 'open' => -1), 0);
				$fields['private'] = $fields['access'] === 1 ? 1 : 0;
			}
			if ($isNew)
			{
				$fields += array('state' => 1, 'type' => 1, 'provisioned' => 0,
					'private' => 0, 'access' => 0, 'created' => gmdate('Y-m-d H:i:s'),
					'owned_by_user' => (int) $owner->get('id'),
					'created_by_user' => (int) $owner->get('id'), 'setup_stage' => 2);
			}
			$project->set($fields);
			if (!$project->save())
			{
				throw new Exception($project->getError());
			}

			foreach ($team as $member)
			{
				$user = autohub_user(isset($member['username']) ? $member['username'] : '', $db);
				$role = isset($member['role']) && $member['role'] === 'manager' ? 1 : 0;
				$link = \Components\Projects\Models\Orm\Owner::oneByProjectAndUser(
					$project->get('id'), $user->get('id'));
				$link = $link ?: \Components\Projects\Models\Orm\Owner::blank();
				$link->set(array('projectid' => $project->get('id'),
					'userid' => $user->get('id'), 'groupid' => 0,
					'added' => gmdate('Y-m-d H:i:s'), 'status' => 1,
					'role' => $role, 'native' => 1));
				if (!$link->save())
				{
					throw new Exception($link->getError());
				}
			}

			if ($isNew)
			{
				$project->set('setup_stage', 3);
				$project->save();
				if (!$project->syncSystemGroup())
				{
					throw new Exception($project->getError());
				}
				$repo = new \Components\Projects\Models\Repo($project, 'local');
				if (!$repo->iniLocal())
				{
					throw new Exception($repo->getError());
				}
			}
			return true;
		});
	}
}

function autohub_publications(array $manifest, $db)
{
	if (empty($manifest['publications']))
	{
		return;
	}
	foreach (array('publication', 'version', 'author', 'attachment') as $model)
	{
		require_once Component::path('com_publications') . '/models/orm/' . $model . '.php';
	}

	foreach ((array) $manifest['publications'] as $spec)
	{
		$alias = isset($spec['alias']) ? trim((string) $spec['alias']) : '';
		attempt("publication {$alias}", function () use ($spec, $alias, $db)
		{
			if (!$alias || empty($spec['title']) || empty($spec['project']) || empty($spec['authors']))
			{
				throw new Exception("alias, title, project, and authors are required");
			}
			$db->setQuery("SELECT `id` FROM `#__projects` WHERE `alias` = " . $db->quote($spec['project']));
			$projectId = (int) $db->loadResult();
			if (!$projectId) throw new Exception("unknown project '{$spec['project']}'");

			$category = isset($spec['category']) ? $spec['category'] : 'dataset';
			$db->setQuery("SELECT `id` FROM `#__publication_categories`
				WHERE (`alias` = " . $db->quote($category) . " OR `url_alias` = " . $db->quote($category) . ")
				AND `state` = 1");
			$categoryId = (int) $db->loadResult();
			if (!$categoryId) throw new Exception("unknown active publication category '{$category}'");

			$attachments = isset($spec['attachments']) ? (array) $spec['attachments'] : array();
			$allImages = !empty($attachments);
			foreach ($attachments as $attachmentSpec)
			{
				$allImages = $allImages && !empty($attachmentSpec['media_type'])
					&& strpos(strtolower($attachmentSpec['media_type']), 'image/') === 0;
			}
			$masterAlias = $allImages ? 'gallery' : 'files';
			$db->setQuery("SELECT `id` FROM `#__publication_master_types`
				WHERE `alias` = " . $db->quote($masterAlias));
			$masterType = (int) $db->loadResult();
			if (!$masterType) throw new Exception("publication master type '{$masterAlias}' is unavailable");

			$firstAuthor = autohub_user(is_array($spec['authors'][0])
				? $spec['authors'][0]['username'] : $spec['authors'][0], $db);
			$db->setQuery("SELECT `id` FROM `#__publications` WHERE `alias` = " . $db->quote($alias));
			$publicationId = (int) $db->loadResult();
			$publication = $publicationId
				? \Components\Publications\Models\Orm\Publication::one($publicationId)
				: \Components\Publications\Models\Orm\Publication::blank();
			$publication->set(array('alias' => $alias, 'category' => $categoryId,
				'master_type' => $masterType, 'project_id' => $projectId,
				'access' => isset($spec['access']) ? (int) $spec['access'] : 0,
				'created_by' => $firstAuthor->get('id')));
			if (!$publicationId) $publication->set('created', gmdate('Y-m-d H:i:s'));
			if (!$publication->save()) throw new Exception($publication->getError());

			$db->setQuery("SELECT `id` FROM `#__publication_versions`
				WHERE `publication_id` = " . (int) $publication->get('id') . " AND `main` = 1
				ORDER BY `id` DESC LIMIT 1");
			$versionId = (int) $db->loadResult();
			$version = $versionId
				? \Components\Publications\Models\Orm\Version::one($versionId)
				: \Components\Publications\Models\Orm\Version::blank();
			$state = autohub_state(isset($spec['state']) ? $spec['state'] : 'draft',
				array('draft' => 3, 'published' => 1, 'pending' => 5), 3);
			if ($state === 1 && !autohub_authorized('publish'))
			{
				throw new Exception("publishing requires --authorize publish");
			}
			if (isset($spec['access']) && !autohub_authorized('access-change'))
			{
				throw new Exception("publication access changes require --authorize access-change");
			}
			$versionFields = array('publication_id' => $publication->get('id'), 'main' => 1,
				'title' => $spec['title'], 'state' => $state,
				'description' => isset($spec['description']) ? $spec['description'] : '',
				'abstract' => isset($spec['abstract']) ? $spec['abstract'] : '',
				'created_by' => $firstAuthor->get('id'), 'modified_by' => $firstAuthor->get('id'),
				'access' => isset($spec['access']) ? (int) $spec['access'] : 0);
			if (!$versionId)
			{
				$versionFields += array('created' => gmdate('Y-m-d H:i:s'),
					'version_label' => '1.0', 'version_number' => 1,
					'secret' => substr(bin2hex(random_bytes(8)), 0, 10));
			}
			if ($state === 1)
			{
				$versionFields['published_up'] = gmdate('Y-m-d H:i:s');
			}
			if (!empty($spec['license']))
			{
				$license = $spec['license'];
				$db->setQuery("SELECT `id`, `text` FROM `#__publication_licenses`
					WHERE `active` = 1 AND (`id` = " . (int) $license . "
						OR `name` = " . $db->quote($license) . "
						OR `title` = " . $db->quote($license) . ") LIMIT 1");
				$row = $db->loadObject();
				if (!$row) throw new Exception("unknown active publication license '{$license}'");
				$versionFields['license_type'] = (int) $row->id;
				$versionFields['license_text'] = $row->text;
			}
			$version->set($versionFields);
			if (!$version->save()) throw new Exception($version->getError());

			$order = 1;
			foreach ((array) $spec['authors'] as $authorSpec)
			{
				$username = is_array($authorSpec) ? $authorSpec['username'] : $authorSpec;
				$user = autohub_user($username, $db);
				$db->setQuery("SELECT `id` FROM `#__project_owners`
					WHERE `projectid` = {$projectId} AND `userid` = " . (int) $user->get('id'));
				$ownerId = (int) $db->loadResult();
				$db->setQuery("SELECT `id` FROM `#__publication_authors`
					WHERE `publication_version_id` = " . (int) $version->get('id')
					. " AND `user_id` = " . (int) $user->get('id'));
				$authorId = (int) $db->loadResult();
				$author = $authorId
					? \Components\Publications\Models\Orm\Author::one($authorId)
					: \Components\Publications\Models\Orm\Author::blank();
				$author->set(array('publication_version_id' => $version->get('id'),
					'user_id' => $user->get('id'), 'project_owner_id' => $ownerId,
					'ordering' => $order++, 'role' => is_array($authorSpec) && isset($authorSpec['role'])
						? $authorSpec['role'] : 'Author',
					'name' => $user->get('name') ?: $username, 'status' => 1,
					'created_by' => $firstAuthor->get('id')));
				if (!$authorId) $author->set('created', gmdate('Y-m-d H:i:s'));
				if (!$author->save()) throw new Exception($author->getError());
			}

			foreach ($attachments as $index => $attachmentSpec)
			{
				$file = autohub_import_file((array) $attachmentSpec);
				$db->setQuery("SELECT `id` FROM `#__publication_attachments`
					WHERE `publication_version_id` = " . (int) $version->get('id')
					. " AND `path` = " . $db->quote($file['name']));
				$attachmentId = (int) $db->loadResult();
				if (!$attachmentId)
				{
					autohub_mkdir($version->filespace());
					if (!copy($file['source'], $version->filespace() . '/' . $file['name']))
					{
						throw new Exception("publication attachment copy failed");
					}
					$attachment = \Components\Publications\Models\Orm\Attachment::blank();
					$attachment->set(array(
						'publication_id' => $publication->get('id'),
						'publication_version_id' => $version->get('id'),
						'title' => isset($attachmentSpec['title']) ? $attachmentSpec['title'] : $file['original_name'],
						'path' => $file['name'], 'type' => 'file', 'role' => $index === 0 ? 1 : 0,
						'ordering' => $index + 1, 'content_hash' => $file['sha256'],
						'created' => gmdate('Y-m-d H:i:s'), 'created_by' => $firstAuthor->get('id'),
						'attribs' => json_encode(array_filter(array(
							'description' => isset($attachmentSpec['description']) ? $attachmentSpec['description'] : null,
							'alt' => isset($attachmentSpec['alt']) ? $attachmentSpec['alt'] : null,
							'credit' => isset($attachmentSpec['credit']) ? $attachmentSpec['credit'] : null,
							'media_type' => $file['mime'],
						), function ($value) { return $value !== null && $value !== ''; })),
					));
					if (!$attachment->save()) throw new Exception($attachment->getError());
				}
			}
			return true;
		});
	}
}

function autohub_courses(array $manifest, $db)
{
	if (empty($manifest['courses']))
	{
		return;
	}
	foreach (array('course', 'offering', 'section', 'unit', 'assetgroup', 'asset') as $model)
	{
		require_once Component::path('com_courses') . '/models/orm/' . $model . '.php';
	}
	foreach ((array) $manifest['courses'] as $spec)
	{
		$alias = isset($spec['alias']) ? trim((string) $spec['alias']) : '';
		attempt("course {$alias}", function () use ($spec, $alias, $db)
		{
			if (!$alias || empty($spec['title'])) throw new Exception("course alias and title are required");
			$course = \Components\Courses\Models\Orm\Course::oneByAlias($alias);
			$course = $course && !$course->isNew()
				? $course : \Components\Courses\Models\Orm\Course::blank();
			$state = autohub_state(isset($spec['state']) ? $spec['state'] : 'draft',
				array('draft' => 0, 'published' => 1), 0);
			if ($state === 1 && !autohub_authorized('publish'))
			{
				throw new Exception("publishing requires --authorize publish");
			}
			if (isset($spec['access']) && !autohub_authorized('access-change'))
			{
				throw new Exception("course access changes require --authorize access-change");
			}
			$course->set(array(
				'alias' => $alias, 'title' => $spec['title'], 'state' => $state,
				'access' => isset($spec['access']) ? (int) $spec['access'] : 0,
				'blurb' => isset($spec['blurb']) ? $spec['blurb'] : '',
				'description' => isset($spec['description']) ? $spec['description'] : '',
				'params' => '{}', 'created_by' => 0,
				'length' => isset($spec['length']) ? $spec['length'] : null,
				'effort' => isset($spec['effort']) ? $spec['effort'] : null,
			));
			if ($course->isNew()) $course->set('created', gmdate('Y-m-d H:i:s'));
			if (!$course->save()) throw new Exception($course->getError());

			$db->setQuery("SELECT `id` FROM `#__courses_offerings`
				WHERE `course_id` = " . (int) $course->get('id') . " AND `alias` = 'default'");
			$offeringId = (int) $db->loadResult();
			$offering = $offeringId
				? \Components\Courses\Models\Orm\Offering::one($offeringId)
				: \Components\Courses\Models\Orm\Offering::blank();
			$offering->set(array('course_id' => $course->get('id'), 'alias' => 'default',
				'title' => $spec['title'], 'term' => '', 'state' => $state, 'params' => '{}'));
			if (!$offering->save()) throw new Exception($offering->getError());

			$db->setQuery("SELECT `id` FROM `#__courses_offering_sections`
				WHERE `offering_id` = " . (int) $offering->get('id') . " AND `is_default` = 1");
			$sectionId = (int) $db->loadResult();
			$section = $sectionId
				? \Components\Courses\Models\Orm\Section::one($sectionId)
				: \Components\Courses\Models\Orm\Section::blank();
			$section->set(array('offering_id' => $offering->get('id'), 'is_default' => 1,
				'alias' => 'default', 'title' => 'Default', 'state' => 1,
				'enrollment' => 0, 'grade_policy_id' => 1, 'params' => '{}'));
			if (!$section->save()) throw new Exception($section->getError());

			foreach ((array) (isset($spec['units']) ? $spec['units'] : array()) as $unitOrder => $unitSpec)
			{
				$unitAlias = isset($unitSpec['alias']) ? $unitSpec['alias'] : '';
				if (!$unitAlias) throw new Exception("every course unit needs an alias");
				$db->setQuery("SELECT `id` FROM `#__courses_units`
					WHERE `offering_id` = " . (int) $offering->get('id')
					. " AND `alias` = " . $db->quote($unitAlias));
				$unitId = (int) $db->loadResult();
				$unit = $unitId ? \Components\Courses\Models\Orm\Unit::one($unitId)
					: \Components\Courses\Models\Orm\Unit::blank();
				$unit->set(array('offering_id' => $offering->get('id'), 'alias' => $unitAlias,
					'title' => isset($unitSpec['title']) ? $unitSpec['title'] : $unitAlias,
					'description' => isset($unitSpec['description']) ? $unitSpec['description'] : '',
					'ordering' => isset($unitSpec['order']) ? (int) $unitSpec['order'] : $unitOrder + 1,
					'state' => 1));
				if (!$unit->save()) throw new Exception($unit->getError());

				$db->setQuery("SELECT `id` FROM `#__courses_asset_groups`
					WHERE `unit_id` = " . (int) $unit->get('id') . " AND `alias` = 'content'");
				$groupId = (int) $db->loadResult();
				$group = $groupId ? \Components\Courses\Models\Orm\Assetgroup::one($groupId)
					: \Components\Courses\Models\Orm\Assetgroup::blank();
				$group->set(array('unit_id' => $unit->get('id'), 'alias' => 'content',
					'title' => 'Content', 'description' => '', 'ordering' => 1,
					'parent' => 0, 'state' => 1, 'params' => '{}'));
				if (!$group->save()) throw new Exception($group->getError());

				foreach ((array) (isset($unitSpec['assets']) ? $unitSpec['assets'] : array()) as $assetOrder => $assetSpec)
				{
					$type = isset($assetSpec['type']) ? $assetSpec['type'] : '';
					$title = isset($assetSpec['title']) ? $assetSpec['title'] : ucfirst($type);
					$content = ''; $url = ''; $nativeType = 'text'; $subtype = 'content';
					if ($type === 'content')
					{
						$content = isset($assetSpec['content']) ? $assetSpec['content'] : '';
					}
					elseif ($type === 'url')
					{
						$nativeType = 'url'; $subtype = 'link';
						$url = isset($assetSpec['url']) ? $assetSpec['url'] : '';
					}
					elseif ($type === 'resource' || $type === 'publication')
					{
						$reference = isset($assetSpec[$type]) ? $assetSpec[$type] : '';
						$table = $type === 'resource' ? '#__resources' : '#__publications';
						$db->setQuery("SELECT `id` FROM `{$table}` WHERE `alias` = " . $db->quote($reference));
						if (!(int) $db->loadResult()) throw new Exception("unknown {$type} '{$reference}'");
						$nativeType = 'url'; $subtype = $type;
						$url = '/' . ($type === 'resource' ? 'resources' : 'publications') . '/' . $reference;
					}
					else
					{
						throw new Exception("unsupported course asset type '{$type}'");
					}
					$db->setQuery("SELECT a.`id` FROM `#__courses_assets` AS a
						INNER JOIN `#__courses_asset_associations` AS x ON x.`asset_id` = a.`id`
						WHERE x.`scope` = 'asset_group' AND x.`scope_id` = " . (int) $group->get('id')
						. " AND a.`title` = " . $db->quote($title) . " LIMIT 1");
					$assetId = (int) $db->loadResult();
					$asset = $assetId ? \Components\Courses\Models\Orm\Asset::one($assetId)
						: \Components\Courses\Models\Orm\Asset::blank();
					$asset->set(array('title' => $title, 'content' => $content,
						'type' => $nativeType, 'subtype' => $subtype, 'url' => $url,
						'state' => 1, 'course_id' => $course->get('id')));
					if (!$asset->save()) throw new Exception($asset->getError());
					$db->setQuery("INSERT INTO `#__courses_asset_associations`
						(`asset_id`, `scope_id`, `scope`, `ordering`) VALUES ("
						. (int) $asset->get('id') . ", " . (int) $group->get('id')
						. ", 'asset_group', " . ($assetOrder + 1) . ")
						ON DUPLICATE KEY UPDATE `ordering` = VALUES(`ordering`)");
					// No unique key exists upstream, so only insert for new assets.
					if (!$assetId) $db->query();
					elseif ($assetId)
					{
						$db->setQuery("UPDATE `#__courses_asset_associations` SET `ordering` = "
							. ($assetOrder + 1) . " WHERE `asset_id` = " . $assetId
							. " AND `scope_id` = " . (int) $group->get('id')
							. " AND `scope` = 'asset_group'");
						$db->query();
					}
				}
			}
			// Native-model read after the hierarchy writes.
			if (!\Components\Courses\Models\Orm\Course::one($course->get('id')))
			{
				throw new Exception("course read-after-write failed");
			}
			return true;
		});
	}
}

function autohub_provision_component_content(array $manifest, $db)
{
	autohub_resource_relationships($manifest, $db);
	autohub_projects($manifest, $db);
	autohub_publications($manifest, $db);
	autohub_courses($manifest, $db);
}
