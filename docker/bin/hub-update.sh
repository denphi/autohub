#!/bin/bash
# Update a running hub in place: new source, new dependencies, new migrations.
# No image rebuild, no container recreation.
#
# Usage: hub-update [ref]

# shellcheck source=lib.sh
. "$(dirname "$(readlink -f "$0")")/lib.sh"

[ -n "${1:-}" ] && export HUBZERO_REF="$1"

log "updating to ${HUBZERO_REF}"

hub-source-sync --update
hub-composer
hub-config-render
hub-migrate

# Compiled LESS and templates from the previous revision are now stale.
rm -rf "${HUB_ROOT:?}/app/cache"/*
chown -R "$HUB_USER:$HUB_USER" "$HUB_ROOT/app"

hub-assets || warn "template assets failed to compile -- the site will render unstyled"

log "update complete -- reload the web container to clear opcache:"
log "  docker compose restart web"
