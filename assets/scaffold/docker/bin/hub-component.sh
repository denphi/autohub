#!/bin/bash
set -euo pipefail

exec gosu www-data php /usr/local/lib/hubzero/component-tool.php "$@"
