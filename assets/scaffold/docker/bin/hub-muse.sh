#!/bin/bash
# Run HUBzero's CLI (muse) as the web user, from the right working directory.
#
# Usage: hub-muse <command> [args]
#   e.g. hub-muse cron:jobs run
#        hub-muse migration -f

# shellcheck source=lib.sh
. "$(dirname "$(readlink -f "$0")")/lib.sh"

require_source
require_vendor

cd "$HUB_ROOT"
exec_as=(php "$HUB_ROOT/core/bin/muse")

as_web "${exec_as[@]}" "$@"
