---
name: maintain-hub
description: Diagnose and safely change an existing HUBzero hub through `cli/autohub`, including manifest and template changes, incident investigation, full-state backup/restore, CMS upgrades, and deliberately authorized publication or removal. Use for day-2 work such as a 500 error, an unstyled page, an extension/configuration change, a CMS upgrade, or template restyling. Resolve the deployment target, preserve existing failures as a baseline, protect secrets and state, and verify without expanding scope silently.
---

# Maintain an existing HUBzero hub

Run `cli/autohub` from the repository root and add `--json` to every
non-streaming command. Read `ok`, every item in `checks`, and relevant `next`
suggestions. End each change with full verification and faithfully report any
failure.

## Establish the target and baseline

```bash
cli/autohub status --json
cli/autohub verify --json
```

Record the exact target ID, driver, URL, service state, and failing checks
before changing anything. On Kubernetes, pin context, namespace, and release in
`autohub.yml`; do not rely on an implicit current context for destructive work.

If the baseline is already red, separate pre-existing failures from the user's
requested change. Diagnose failures that block the requested work, but do not
silently expand the task into unrelated repairs. Report unrelated baseline
failures and ask before making materially broader changes.

## Diagnose before changing

Use the narrowest useful sequence:

```bash
cli/autohub doctor --json
cli/autohub logs --errors --json
cli/autohub verify --scope assets --json
cli/autohub db query "SELECT ... FROM jos_extensions" --json
```

Select the relevant verification scope from `site`, `assets`, `mail`, `login`,
or `db`. Keep `db query` read-only and avoid credential, session, token, or
personal-data columns. Use the interactive database shell only when explicitly
needed; it cannot provide a single JSON result.

Common mappings:

| Symptom | Evidence | Usual action |
|---|---|---|
| Site is unstyled or links to `/` | asset verification and LESS output | fix the reported LESS line; run `assets build` |
| Core action returns 500 | doctor reports unreachable SMTP | restore the mail service/relay; verify mail and the action |
| Missing table or column | error log and DB scope | run `migrate`, then full verification |
| Support folder or internal OAuth seed missing | doctor identifies the known seed | run `provision`, then full verification |
| Changed CSS appears stale | no server error; old browser bytes | clear/warm cache and hard-reload |

## Apply supported configuration changes

Represent supported additions and parameter changes in `hub.yml`, then run:

```bash
cli/autohub provision --json
cli/autohub verify --json
```

Provisioning is additive-only. `hub.yml` is authoritative for supported
configuration that provisioning can apply, but it is not a convergent inventory
of live state: removing an entry from YAML does not delete the live object.
Never claim a removal succeeded merely because provisioning passed. For a
requested removal, take a full snapshot, identify the supported disable/removal
operation, confirm the exact target and blast radius, apply it deliberately,
persist any supported state in the manifest, and verify.

Merge component/plugin parameters through `hub.yml`; do not replace JSON blobs
or make a live database edit the final fix. A diagnostic live edit is incomplete
until a reproducible mechanism exists.

## Change templates and styles

After editing template files:

```bash
cli/autohub assets build --json
cli/autohub cache clear --warm --json
cli/autohub verify --scope assets --json
cli/autohub verify --json
```

Then inspect every affected route in Firefox or Chrome. Check anonymous and
authenticated states plus desktop and narrow/mobile widths. Include relevant
group, course, resource, or administrator pages rather than relying on the
homepage sweep. Compilation and HTTP 200 responses do not prove visual
correctness.

Only `site.less` auto-compiles. Rebuild paired `html/**/*.less`, vendor,
`group.css`, course-layout, and other directly loaded CSS. Remember that group/course
styles can load after `site.css`, so inspect specificity and load order. Hard
reload static assets that lack a version query.

Before publishing template commits, run `cli/autohub template status --json`
and review the branch, remote, ahead/behind state, and dirty files. Push only
when the user explicitly asks to publish to that remote. Prefer a normal push;
use `--force` (`--force-with-lease`) only with explicit authorization after
fetching and reviewing the remote divergence. Keep tokens in `.env` and never
put them in an argument, remote URL, manifest, log, or chat.

## Create and restore recovery points

Use a full-state snapshot before risky work:

```bash
cli/autohub backup create --label before-change --json
```

This writes a mode-restricted host snapshot containing the database,
`hub_app` (configuration, uploads, and installed application state), TLS, the
CMS source commit, and project configuration. The project copy contains `.env`;
never commit or casually share it. Copy production snapshots to independent,
off-host/off-cluster storage and periodically test restoration in a separate
project or namespace.

`cli/autohub db dump --json` is a durable host-side database-only export. It is
useful for SQL analysis but is not a complete HUBzero disaster-recovery backup.
The Kubernetes backup CronJob is likewise database-only, lives on a cluster
PVC, and must not be the sole backup.

Restore only during an authorized maintenance window. Resolve the destination
with `status`, state the exact target, then run:

```bash
cli/autohub backup restore backups/<snapshot> \
  --force --confirm '<exact-target-id>' --json
cli/autohub verify --json
```

The restore coordinates the recorded source commit, `hub_app`, TLS, and
database. Review the saved project configuration separately instead of blindly
overwriting the live `.env`.

## Upgrade and roll back the CMS

Test upgrades against a separate project/namespace restored from a representative
snapshot. Never use `destroy` to turn the populated production target into the
“test” hub.

```bash
cli/autohub update --ref <pinned-tag-or-sha> --json
cli/autohub verify --json
```

`update` takes a host-side full-state snapshot before changing source,
dependencies, schema, configuration, caches, or assets. Do not pass
`--no-backup` in routine operation. If verification regresses, restore the
coordinated snapshot rather than restoring only its database under newer source.

## Operational rules

- Never reproduce `.env`, a password, token, session, private key, or sensitive
  query result in chat or logs. If one is exposed, call out rotation/revocation.
- Require explicit user authorization plus exact-target confirmation for
  destroy, restore, forced initialization, or other irreversible changes.
- Keep backups outside the state being destroyed and independently durable for
  production.
- Do not turn unrelated baseline failures into unauthorized work.
- Do not report success while any required check is failed or skipped, or while
  required visual inspection remains incomplete.
