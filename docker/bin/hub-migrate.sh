#!/bin/bash
# Run pending migrations, or baseline a freshly loaded schema.
#
# Usage:
#   hub-migrate              apply pending migrations
#   hub-migrate --dry-run    show what would run, change nothing
#   hub-migrate --baseline   record all migrations as applied without running them

# shellcheck source=lib.sh
. "$(dirname "$(readlink -f "$0")")/lib.sh"

require_source
require_vendor
wait_for_db

here="$(dirname "$(readlink -f "$0")")"

case "${1:-}" in
	--baseline)
		as_web php "$here/migrate-baseline.php"
		;;
	--dry-run)
		# muse defaults to a dry run; -f is what makes it commit.
		as_web php "$HUB_ROOT/core/bin/muse" migration --no-ansi
		;;
	*)
		# Retire known-incompatible migrations first, so they do not abort the
		# run every single time.
		as_web php "$here/record-migrations.php"

		log "applying pending migrations"
		as_web php "$HUB_ROOT/core/bin/muse" migration -f --no-ansi
		;;
esac
