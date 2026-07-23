#!/bin/bash
# Make sure a TLS certificate exists, generating a self-signed fallback if
# `autohub tls setup` or an operator has not mounted a trusted pair.
#
# HUBzero does not treat HTTPS as optional: com_login's auth controller
# hardcodes a redirect to https:// with no config to disable it, so *nobody can
# log in* over plain HTTP. A hub without TLS is not merely less secure, it is
# unusable.
#
# `autohub tls setup` mounts a locally trusted development pair. Production
# operators can mount a publicly trusted pair at $HUB_TLS_DIR. This script
# leaves anything already there alone.

# shellcheck source=lib.sh
. "$(dirname "$(readlink -f "$0")")/lib.sh"

: "${HUB_TLS_DIR:=/etc/hubzero/tls}"
: "${HUB_TLS_CN:=localhost}"

crt="$HUB_TLS_DIR/hub.crt"
key="$HUB_TLS_DIR/hub.key"

mkdir -p "$HUB_TLS_DIR"

if [ -s "$crt" ] && [ -s "$key" ]; then
	log "using TLS certificate $crt"
	exit 0
fi

log "generating a self-signed certificate for '$HUB_TLS_CN' (replace it for production)"

# SANs matter: browsers ignore CN. This fallback includes the common local
# names, but its issuer is intentionally not installed into host trust stores.
openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
	-keyout "$key" -out "$crt" \
	-subj "/CN=${HUB_TLS_CN}" \
	-addext "subjectAltName=DNS:${HUB_TLS_CN},DNS:localhost,IP:127.0.0.1" \
	2>/dev/null || die "openssl failed to generate a certificate"

chmod 0600 "$key"
chmod 0644 "$crt"

log "self-signed certificate written to $crt"
