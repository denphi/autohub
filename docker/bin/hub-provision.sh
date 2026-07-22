#!/bin/bash
# Apply the declarative hub manifest (hub.yml).
#
# Usage: hub-provision [path/to/hub.yml]
#
# The manifest lives at /etc/hubzero/hub.yml, bind-mounted read-only from
# ./hub.yml in the project directory. It is kept out of app/ on purpose: that
# tree is chowned on every boot and a read-only file in it breaks the entrypoint.

# shellcheck source=lib.sh
. "$(dirname "$(readlink -f "$0")")/lib.sh"

require_source
require_vendor
wait_for_db

[ -n "${1:-}" ] && HUB_MANIFEST="$1"
: "${HUB_MANIFEST:=/etc/hubzero/hub.yml}"
export HUB_MANIFEST

if [ ! -f "$HUB_MANIFEST" ]; then
	log "no manifest at $HUB_MANIFEST -- skipping provisioning"
	exit 0
fi

log "provisioning from $HUB_MANIFEST"

as_web php "$(dirname "$(readlink -f "$0")")/provision.php"
