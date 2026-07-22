#!/bin/bash
# Compile the active template's LESS, reporting syntax errors instead of
# silently serving an unstyled site.
#
# Usage: hub-assets [--clean]

# shellcheck source=lib.sh
. "$(dirname "$(readlink -f "$0")")/lib.sh"

require_source
require_vendor
wait_for_db

if [ "${1:-}" = '--clean' ]; then
	log "clearing compiled assets"
	rm -rf "${HUB_ROOT:?}/app/cache/site"/* "${HUB_ROOT:?}/app/cache/admin"/*
fi

log "compiling template assets"

as_web php "$(dirname "$(readlink -f "$0")")/compile-assets.php"
rc=$?

chown -R "$HUB_USER:$HUB_USER" "$HUB_ROOT/app/cache" 2>/dev/null || true

exit $rc
