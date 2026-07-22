#!/bin/bash
# Load the HUBzero schema into an empty database, then baseline migrations.
#
# Nothing in the CMS codebase references core/bootstrap/Install/sql/mysql/*.sql
# -- upstream expects an operator to pipe them into mysql by hand. This does it.
#
# Usage: hub-db-init [--force]

# shellcheck source=lib.sh
. "$(dirname "$(readlink -f "$0")")/lib.sh"

require_source

here="$(dirname "$(readlink -f "$0")")"
sql="$HUB_ROOT/core/bootstrap/Install/sql/mysql"
[ -d "$sql" ] || die "no SQL bundle at $sql"

wait_for_db

if db_is_installed && [ "${1:-}" != '--force' ]; then
	log "database already installed (${DB_PREFIX}extensions exists)"
	exit 0
fi

# The bundled SQL uses HUBzero's '#__' table-prefix placeholder, the same
# substitution Hubzero\Database\Driver performs at runtime.
load() {
	local file="$1"
	[ -f "$file" ] || { warn "missing $(basename "$file") -- skipping"; return 0; }

	log "loading $(basename "$file")"
	sed "s/\`#__/\`${DB_PREFIX}/g" "$file" \
		| hub_mysql --database="$DB_NAME" \
		|| die "failed loading $(basename "$file")"
}

load "$sql/schema.sql"
load "$sql/data.sql"

if [ "$HUB_LOAD_SAMPLE_DATA" = '1' ]; then
	load "$sql/sample.sql"
fi

log "schema loaded"

# schema.sql is a dump taken at release time, so the migrations that produced it
# are recorded as done. Anything committed upstream since then still has to run
# -- the caller does that with hub-migrate.
hub-migrate --baseline

# Close the gap between the bundled schema and the code: missing tables,
# missing columns, and unregistered extensions. Works out what to replay from
# the migrations themselves rather than from a list that would rot.
as_web php "$here/repair-schema.php"
