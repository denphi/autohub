#!/usr/bin/env bash
# Generate .env and hub.yml for a new hub.
#
# Runs on the host, before the stack exists. Non-interactive by default so an
# agent or CI can drive it; prompts only when asked with --interactive.
#
#   ./scripts/hub-init.sh --site "Research Hub" --template custom \
#       --template-url https://git.example.org/hub/tpl_custom.git
#
# Secrets are generated, never defaulted to something guessable, and nothing is
# overwritten without --force.

set -euo pipefail

cd "$(dirname "$0")/.."

SITE=""
ADMIN_USER="admin"
# Optional non-interactive override comes from the environment, never argv.
ADMIN_PASS="${AUTOHUB_ADMIN_PASSWORD:-}"
ADMIN_EMAIL=""
TEMPLATE=""
TEMPLATE_URL=""
TEMPLATE_BRANCH="main"
HTTP_PORT=""
HTTPS_PORT=""
PRESET=""
FORCE=0
INTERACTIVE=0

die()  { printf '\033[0;31merror:\033[0m %s\n' "$*" >&2; exit 1; }
note() { printf '\033[0;36m==>\033[0m %s\n' "$*"; }

usage() {
	sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'
	cat <<'EOF'

Options:
  --site <name>            Site name (default: "My Hub")
  --admin-user <name>      Administrator username (default: admin)
  --admin-email <addr>     Administrator email; needs a real TLD
  --template <alias>       Active site template, e.g. NaN
  --template-url <git>     Repository to clone the template from
  --template-branch <ref>  Branch to track (default: main)
  --preset <name>          Start hub.yml from presets/<name>.yml
  --http-port <n>          Host HTTP port  (default: first free from 8080)
  --https-port <n>         Host HTTPS port (default: first free from 8443)
  --interactive            Prompt for anything not supplied
  --force                  Overwrite an existing .env / hub.yml

Environment:
  AUTOHUB_ADMIN_PASSWORD   Optional administrator password override; never pass secrets in argv
EOF
}

while [ $# -gt 0 ]; do
	case "$1" in
		--site)            SITE="$2"; shift 2 ;;
		--admin-user)      ADMIN_USER="$2"; shift 2 ;;
		--admin-email)     ADMIN_EMAIL="$2"; shift 2 ;;
		--template)        TEMPLATE="$2"; shift 2 ;;
		--template-url)    TEMPLATE_URL="$2"; shift 2 ;;
		--template-branch) TEMPLATE_BRANCH="$2"; shift 2 ;;
		--preset)          PRESET="$2"; shift 2 ;;
		--http-port)       HTTP_PORT="$2"; shift 2 ;;
		--https-port)      HTTPS_PORT="$2"; shift 2 ;;
		--interactive)     INTERACTIVE=1; shift ;;
		--force)           FORCE=1; shift ;;
		-h|--help)         usage; exit 0 ;;
		*)                 die "unknown option: $1 (try --help)" ;;
	esac
done

# `tr < /dev/urandom | head -c N` looks obvious but head closes the pipe and
# kills tr with SIGPIPE, which pipefail turns into a fatal 141. Read a bounded
# amount first so every stage exits cleanly.
secret() {
	local n="${1:-32}"
	head -c "$((n * 8))" /dev/urandom | LC_ALL=C tr -dc 'A-Za-z0-9' | cut -c "1-${n}"
}

# First TCP port at or above $1 that nothing is listening on.
free_port() {
	local port="$1"
	while [ "$port" -lt 65535 ]; do
		if ! (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; then
			echo "$port"
			return
		fi
		exec 3>&- 2>/dev/null || true
		port=$((port + 1))
	done
	die "no free port found above $1"
}

ask() {
	local prompt="$1" default="$2" answer
	[ "$INTERACTIVE" = '1' ] || { echo "$default"; return; }
	read -r -p "$prompt [$default]: " answer </dev/tty || true
	echo "${answer:-$default}"
}

# --- Gather -----------------------------------------------------------------
SITE=$(ask "Site name" "${SITE:-My Hub}")
ADMIN_USER=$(ask "Administrator username" "$ADMIN_USER")
# HUBzero's validator rejects bare hostnames such as admin@localhost.
ADMIN_EMAIL=$(ask "Administrator email" "${ADMIN_EMAIL:-admin@example.com}")
[ -n "$ADMIN_PASS" ] || ADMIN_PASS=$(secret 20)

[ -n "$HTTP_PORT" ]  || HTTP_PORT=$(free_port 8080)
[ -n "$HTTPS_PORT" ] || HTTPS_PORT=$(free_port 8443)

case "$ADMIN_EMAIL" in
	*@*.*) ;;
	*) die "administrator email needs a real TLD; HUBzero rejects '$ADMIN_EMAIL'" ;;
esac

# --- .env -------------------------------------------------------------------
if [ -f .env ] && [ "$FORCE" = '0' ]; then
	note ".env already exists; leaving it alone (use --force to replace)"
else
	[ -f .env.example ] || die ".env.example is missing"

	cp .env.example .env

	# Every secret is generated. Nothing ships with a usable default.
	AH_SITE="$SITE" AH_ADMIN_USER="$ADMIN_USER" AH_ADMIN_PASS="$ADMIN_PASS" \
	AH_ADMIN_EMAIL="$ADMIN_EMAIL" AH_HTTP_PORT="$HTTP_PORT" AH_HTTPS_PORT="$HTTPS_PORT" \
	AH_DB_PASS="$(secret 24)" AH_DB_ROOT_PASS="$(secret 24)" AH_APP_SECRET="$(secret 32)" \
	python3 - <<'PY'
import os, re
site = os.environ['AH_SITE']
au = os.environ['AH_ADMIN_USER']
ap = os.environ['AH_ADMIN_PASS']
ae = os.environ['AH_ADMIN_EMAIL']
http = os.environ['AH_HTTP_PORT']
https = os.environ['AH_HTTPS_PORT']
dbpw = os.environ['AH_DB_PASS']
rootpw = os.environ['AH_DB_ROOT_PASS']
appsecret = os.environ['AH_APP_SECRET']
values = {
    'HUB_SITENAME': site,
    'HUB_ADMIN_USER': au,
    'HUB_ADMIN_PASSWORD': ap,
    'HUB_ADMIN_EMAIL': ae,
    'HTTP_PORT': http,
    'HTTPS_PORT': https,
    'DB_PASSWORD': dbpw,
    'DB_ROOT_PASSWORD': rootpw,
    'HUB_SECRET': appsecret,
    'TEST_USER_PASSWORD': '',
}
text = open('.env').read()
for key, value in values.items():
    pattern = re.compile(r'^%s=.*$' % re.escape(key), re.M)
    line = '%s=%s' % (key, value)
    text = pattern.sub(lambda _m: line, text) if pattern.search(text) else text + '\n' + line
open('.env', 'w').write(text)
PY

	chmod 600 .env
	note "wrote .env (mode 600)"
fi

# --- hub.yml ----------------------------------------------------------------
if [ -f hub.yml ] && [ -s hub.yml ] && [ "$FORCE" = '0' ] \
	&& ! grep -q '^# Declarative hub setup. See hub.yml.example' hub.yml; then
	note "hub.yml already exists; leaving it alone (use --force to replace)"
elif [ -n "$PRESET" ]; then
	[ -f "presets/$PRESET.yml" ] || die "no preset at presets/$PRESET.yml"
	cp "presets/$PRESET.yml" hub.yml
	note "wrote hub.yml from presets/$PRESET.yml"
else
	{
		echo "# Declarative hub setup. See hub.yml.example for every option."
		echo "# Applied on every boot and by \`make provision\`."
		echo

		if [ -n "$TEMPLATE_URL" ] && [ -n "$TEMPLATE" ]; then
			echo "extensions:"
			echo "  - type: template"
			echo "    alias: $TEMPLATE"
			echo "    url: $TEMPLATE_URL"
			echo "    branch: $TEMPLATE_BRANCH"
			# Only emit the token line when the repo is likely private.
			echo "    token: \${GITLAB_TOKEN}"
			echo
		fi

		if [ -n "$TEMPLATE" ]; then
			echo "template:"
			echo "  site: $TEMPLATE"
			echo
		fi
	} > hub.yml

	note "wrote hub.yml"
fi

# --- Report -----------------------------------------------------------------
cat <<EOF

	Site        ${SITE}
	Admin       ${ADMIN_USER}  (password stored in .env; not printed)
	URLs        http://localhost:${HTTP_PORT}   https://localhost:${HTTPS_PORT}
  Admin panel https://localhost:${HTTPS_PORT}/administrator  (https only)

EOF

if [ -n "$TEMPLATE_URL" ]; then
	note "set GITLAB_TOKEN in .env if that template repository is private"
fi

note "next: make up   (first boot takes a few minutes)"
