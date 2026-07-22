---
name: spin-hub
description: Create or deliberately reset a HUBzero CMS hub from this repository with the `cli/autohub` CLI. Use when asked to scaffold, provision, replicate, or reset a hub; install its initial template/extensions; or deploy the same manifest with Docker or Kubernetes. Resolve the target, protect credentials and existing state, and verify the resulting site before reporting success.
---

# Create or reset a HUBzero hub

Run `cli/autohub` from the repository root. Use `--json` for every
non-streaming command and treat its `{ok, action, details, checks, next}` object
and exit status as the contract. Never infer success from a command merely
finishing. Do not combine `--json` with interactive shells or streaming logs.

## Resolve the requested shape

Create a HUBzero hub with the requested site identity:

```bash
cli/autohub init --site "Research Hub" --json
```

`init` writes `.env` with mode 600 and a minimal or preset-backed `hub.yml`.
It refuses to replace existing files without both `--force` and the exact
target ID. Never use `--force` merely to make initialization succeed.

Generated credentials remain in `.env`. Report that credentials were created
and where they are stored; never reproduce a password or token in chat, JSON,
logs, commits, or command arguments. For a private template repository, store
its token only in `.env` and reference `${GITLAB_TOKEN}` from `hub.yml`.

## Boot and prove the hub

```bash
cli/autohub up --wait --json
cli/autohub verify --json
```

First boot can take several minutes while it clones the CMS, installs Composer
dependencies, loads and repairs the schema, and provisions the manifest.
`up --wait` watches the bootstrap marker. Do not replace it with ad-hoc log
polling.

Require every `verify` check to pass. A missing administrator credential makes
the login check fail rather than silently reducing coverage. If verification
fails, use:

```bash
cli/autohub doctor --json
cli/autohub logs --errors --json
```

Follow only relevant, safe suggestions from `next`; re-run full verification
after the fix. Report each remaining failed check rather than rounding partial
success up to done.

For a custom template or visual change, verification is necessary but not
sufficient. Open the affected routes in Firefox or Chrome and inspect the
authenticated and anonymous states at desktop and narrow/mobile widths. Include
group, course, or other non-homepage routes affected by the change. Do not use
HTTP status or the homepage asset sweep as proof that appearance is correct.

## Apply initial hub configuration

Edit `hub.yml`, then run:

```bash
cli/autohub provision --json
cli/autohub assets build --json
cli/autohub cache clear --warm --json
cli/autohub verify --json
```

Treat `hub.yml` as the reproducible source for supported additions and
configuration. Provisioning is additive-only: deleting an entry from the file
does not remove the corresponding live object. Do not promise convergent
deletion. Handle removals as an explicit maintenance operation with a backup,
an exact target, a supported disable/removal mechanism, and post-change
verification.

Sections include `extensions`, `template`, `plugins`, `components`, `modules`,
`resource_types`, `resources`, `users`, `groups`, `menus`, and `seeds`. Read
`hub.yml.example` for field details.

## Reset an existing hub

Reset only when the user explicitly asks to destroy the resolved hub. First
capture the target and a recovery point:

```bash
cli/autohub status --json
cli/autohub backup create --label pre-reset --json
```

The snapshot is written under host-side `backups/`, outside Docker volumes and
Kubernetes PVCs. It contains the database, `hub_app`, TLS material, and project
configuration, including secrets. Keep it mode-restricted and copy production
snapshots to independently durable/off-host storage before destruction.

Read the exact target ID from `status`, state it to the user, and use it
literally. Also pass the completed snapshot directory returned by `backup
create`:

```bash
cli/autohub destroy --force --confirm '<exact-target-id>' \
  --snapshot 'backups/<completed-snapshot>' --json
cli/autohub up --wait --json
cli/autohub verify --json
```

Do not destroy a target when the requested identity and resolved identity
differ. The confirmation protects against a wrong project, Docker directory,
Kubernetes context, namespace, or Helm release; it does not replace user
authorization or a durable backup.

## Select Docker or Kubernetes

Docker is the default. For Kubernetes, create `autohub.yml` from
`autohub.yml.example` and pin `context`, `namespace`, and `release`; avoid an
implicit current context for destructive work. Set `kubernetes.ingress_host`
to a reachable HTTPS hostname so HTTP verification can run. The same CLI flow
then uses the Helm chart under `deploy/chart/`.

## Preserve the hard-won constraints

- Keep `.env` for secrets and infrastructure, `hub.yml` for supported hub
  configuration, and `autohub.yml` for the deployment target.
- Compile every paired/nonstandard template asset with `assets build`; only
  `site.less` automatically produces `site.css` on request, and HUBzero hides
  LESS failures by serving an unstyled page.
- Clear and warm server caches after CSS changes, then hard-reload the browser;
  static files without a version query can remain cached.
- Use HTTPS for administrator login. Local Docker uses a self-signed
  certificate; production must use a trusted certificate.
- Use `hub.yml` to reproduce hub shape. Replicating content also requires the
  coordinated database and `hub_app` state from a full snapshot.
- Stay behind the CLI. Use `db query` only for read-only diagnosis and never
  select credential/token fields. Use interactive `db shell` or raw `muse`
  only when the user request requires it and JSON automation is not expected.
