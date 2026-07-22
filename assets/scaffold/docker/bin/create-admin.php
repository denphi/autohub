<?php
/**
 * Create (or reset) the initial Super User.
 *
 * data.sql seeds the usergroup tree but no accounts, and there is no
 * `muse user:create`, so a fresh hub ships with nobody able to log in.
 *
 * Idempotent: re-running updates the password of the existing account rather
 * than creating a duplicate.
 */

use Hubzero\User\User;
use Hubzero\User\Password;

$app = require __DIR__ . '/bootstrap-cli.php';

$username = getenv('HUB_ADMIN_USER')     ?: 'admin';
$password = getenv('HUB_ADMIN_PASSWORD') ?: '';
$email    = getenv('HUB_ADMIN_EMAIL')    ?: 'admin@localhost';
$name     = getenv('HUB_ADMIN_NAME')     ?: 'Administrator';

if ($password === '')
{
	fwrite(STDERR, "[hub] ERROR: HUB_ADMIN_PASSWORD is empty\n");
	exit(1);
}

$user = User::oneByUsername($username);

if (!$user || !$user->get('id'))
{
	$user = User::blank();
	$user->set(array(
		'username' => $username,
		'name'     => $name,
		'email'    => $email,
		// registerDate and access come from User::$initiate, but registerIP
		// there resolves to Request::ip(), which is null on the command line --
		// and the column is NOT NULL. This account really is provisioned from
		// inside the container, so say so.
		'registerIP' => '127.0.0.1',
	));
	$created = true;
}
else
{
	$created = false;
}

// approved=2 is "approved", activation>=1 is "email confirmed", block=0 is
// "not suspended". All three gate login in com_members.
$user->set('block', 0);
$user->set('approved', 2);
$user->set('activation', 1);

// User::save() turns 'accessgroups' into #__user_usergroup_map rows.
// Group 8 = "Super Users", seeded by data.sql.
$user->set('accessgroups', array(8));

if (!$user->save())
{
	fwrite(STDERR, "[hub] ERROR: could not save user: "
		. implode('; ', $user->getErrors()) . "\n");
	exit(1);
}

$id = (int) $user->get('id');

// changePassword() -> getInstance() -> read() only auto-creates the shadow row
// by copying it out of the legacy #__xprofiles table, and a fresh install has
// no row there. Without this seed it just returns false. Password's constructor
// is private, so there is no object-level way to do it either.
$db = App::get('db');
$db->setQuery("INSERT IGNORE INTO `#__users_password` (`user_id`) VALUES (" . $db->quote($id) . ")");
$db->query();

if (!Password::changePassword($id, $password))
{
	fwrite(STDERR, "[hub] ERROR: could not set password for '{$username}'\n");
	exit(1);
}

fwrite(STDERR, sprintf(
	"[hub] %s Super User '%s' (id %d, %s)\n",
	$created ? 'created' : 'updated',
	$username,
	$id,
	$email
));
