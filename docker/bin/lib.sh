#!/bin/bash
# Shared helpers + defaults for the hub-* scripts. Sourced, never executed.

set -euo pipefail

: "${HUB_ROOT:=/var/www/html}"
: "${HUB_USER:=www-data}"

# --- Source ----------------------------------------------------------------
: "${HUBZERO_REPO:=https://github.com/hubzero/hubzero-cms.git}"
: "${HUBZERO_REF:=2.4-main}"
# 1 = fetch and fast-forward to HUBZERO_REF on every container start.
: "${HUBZERO_AUTO_UPDATE:=0}"

# --- Database --------------------------------------------------------------
: "${DB_HOST:=db}"
: "${DB_PORT:=3306}"
: "${DB_NAME:=hubzero}"
: "${DB_USER:=hubzero}"
: "${DB_PASSWORD:=}"
: "${DB_PREFIX:=jos_}"

# --- Bootstrap -------------------------------------------------------------
: "${HUB_SKIP_BOOTSTRAP:=0}"
: "${HUB_SKIP_COMPOSER:=0}"

# Migrations that cannot succeed here, recorded as applied instead of run.
#
# Migration20250220120652ComTools targets HUBzero's *middleware* database. With
# no middleware configured, Base::getMWDBO() silently falls back to the hub
# database -- where schema.sql does create an unprefixed `host` table, but
# without the `service_host` column the migration's ALTER references. It fails
# on every from-schema install, and because migrations abort on the first fatal
# error it blocks everything after it, including the #__ratelimit table that
# each page load needs. The columns it adds only matter with tool sessions.
: "${HUB_SKIP_MIGRATIONS:=Migration20250220120652ComTools.php}"

# The PHP helpers read all of this with getenv(), and `: "${X:=y}"` only sets a
# shell variable -- without this export, every default above is invisible to
# them and they silently fall back to their own.
export HUB_ROOT HUB_USER \
	HUBZERO_REPO HUBZERO_REF HUBZERO_AUTO_UPDATE \
	DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD DB_PREFIX \
	HUB_SKIP_BOOTSTRAP HUB_SKIP_COMPOSER HUB_SKIP_MIGRATIONS \
	HUB_LOAD_SAMPLE_DATA \
	HUB_ADMIN_USER HUB_ADMIN_PASSWORD HUB_ADMIN_EMAIL HUB_ADMIN_NAME

log()  { printf '\033[0;36m[hub]\033[0m %s\n' "$*" >&2; }
warn() { printf '\033[0;33m[hub] WARN:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[0;31m[hub] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

# Run a command as the web user so files land with usable ownership.
as_web() {
	if [ "$(id -u)" = '0' ]; then
		gosu "$HUB_USER" "$@"
	else
		"$@"
	fi
}

# mysql client bound to the application credentials.
hub_mysql() {
	MYSQL_PWD="$DB_PASSWORD" mysql \
		--host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
		--default-character-set=utf8mb4 "$@"
}

wait_for_db() {
	local attempts="${1:-60}" i=1
	log "waiting for database ${DB_USER}@${DB_HOST}:${DB_PORT}/${DB_NAME}"
	while [ "$i" -le "$attempts" ]; do
		if hub_mysql --database="$DB_NAME" --execute='SELECT 1' >/dev/null 2>&1; then
			log "database is ready"
			return 0
		fi
		sleep 2
		i=$((i + 1))
	done
	die "database did not become ready after $((attempts * 2))s"
}

# True when the schema has already been loaded.
db_is_installed() {
	local count
	count=$(hub_mysql --database="$DB_NAME" --skip-column-names --batch --execute="
		SELECT COUNT(*) FROM information_schema.tables
		WHERE table_schema = DATABASE() AND table_name = '${DB_PREFIX}extensions'" 2>/dev/null || echo 0)
	[ "${count:-0}" -gt 0 ]
}

# True once any account exists, so a restart does not keep nagging about the
# administrator an operator already created by hand.
db_had_users() {
	local count
	count=$(hub_mysql --database="$DB_NAME" --skip-column-names --batch --execute="
		SELECT COUNT(*) FROM \`${DB_PREFIX}users\`" 2>/dev/null || echo 0)
	[ "${count:-0}" -gt 0 ]
}

require_source() {
	[ -f "$HUB_ROOT/core/bootstrap/app.php" ] || die "no HUBzero source at $HUB_ROOT -- run hub-source-sync first"
}

require_vendor() {
	[ -f "$HUB_ROOT/core/vendor/autoload.php" ] || die "composer dependencies missing -- run hub-composer first"
}
