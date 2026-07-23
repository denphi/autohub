# autohub — Design

**Goal:** create and maintain HUBzero hubs from scratch, with a single CLI that
drives the infrastructure (Docker today, Kubernetes next), designed from day one
to be operated by an AI agent through a Skill.

This document captures requirements learned from real HUBzero deployments —
every requirement below traces to something that broke,
surprised us, or had to be discovered the hard way.

## Contents

- [Vision](#1-vision)
- [Current state](#2-current-state-what-already-works)
- [Requirements](#3-requirements--what-production-like-hubzero-testing-taught-us)
- [CLI contract and command tree](#4-the-cli)
- [Unified skill](#5-the-skill-)
- [Manifest evolution](#6-manifest-evolution)
- [Roadmap](#7-roadmap)
- [Open questions](#8-open-questions)
- [Incident index](#appendix-a--incident--requirement-index)

---

## 1. Vision

```
 operator (human or AI via Skill)
        │
        ▼
   autohub CLI  ──────────────  one stable, scriptable interface
        │
   driver layer
   ┌────┴────────┐
   ▼             ▼
 docker       kubernetes        same image, same manifest, same commands
 compose      (helm/operator)
        │
        ▼
   hub.yml (declarative manifest)  +  .env (infrastructure/secrets)
        │
        ▼
   HUBzero CMS (source in a volume, image has no app code)
```

Three ideas carry the whole design:

1. **The CLI is the contract.** Humans, CI, and the AI Skill all talk to the
   same `autohub` commands. The Skill never reaches around the CLI into raw
   `docker compose exec` — if the Skill needs something, the CLI grows a
   command. This is what makes the Kubernetes port possible: only the driver
   changes, the contract doesn't.
2. **Declarative beats imperative.** The admin UI is the only supported way to
   configure upstream HUBzero, which makes hubs unreproducible. `hub.yml` +
   `provision.php` already invert that; the design doubles down: anything we
   ever configured by hand during deployment must become a
   manifest key or a provisioning default.
3. **Verification is part of every operation.** A hub can boot and still be
   unusable (we proved this repeatedly). Every mutating command ends by
   checking, and reports machine-readable results.

### Non-goals

- Not a HUBzero fork. CMS bugs get fixed upstream (or in the hub's fork); this
  repo only carries infrastructure and provisioning.
- Not a PaaS. One `autohub` project = one hub. Multi-hub is many projects
  (docker) or many namespaces (k8s), not a control plane.
- No GUI. The admin UI already exists; our surface is CLI + manifest.

---

## 2. Current state (what already works)

| Piece | Where | Status |
|---|---|---|
| App-less image (PHP 8.2-apache, no CMS code) | `docker/Dockerfile` | done |
| First-boot: clone → composer → schema → repair → admin user | `docker/bin/entrypoint.sh`, `hub-db-init.sh`, `repair-schema.php` | done, zero-touch |
| Declarative provisioning | `hub.yml` + `docker/bin/provision.php` | done, idempotent, additive-only |
| Unconditional seed fixes (admin menu, support folders) | `provision.php` | done |
| Scaffold (secrets, ports, preset) | `scripts/hub-init.sh` | done |
| Ops entry points | `Makefile` (17 targets) + `hub-*` scripts in-container | done |
| Asset pipeline w/ real error reporting | `hub-assets.sh`, `compile-assets.php` | done |
| Backup/restore, source-sync, TLS, cron, mail sink | `hub-backup.sh`, `hub-source-sync.sh`, `hub-tls.sh`, `hub-cron.sh`, mailpit service | done |
| AI entry point | root `SKILL.md` | unified create + maintain workflow |

These primitives motivated the unified CLI and skill contract described below.

---

## 3. Requirements — what production-like HUBzero testing taught us

Each requirement (R#) is traced to a concrete incident.

### 3.1 Provisioning must close the "installed but not working" gaps

HUBzero's install path leaves a hub that *boots* but fails in use. Everything in
this list was found by hitting the failure live:

- **R1 — seed what migrations forgot.** `Migration20141010150100ComSupport`
  only seeds support query folders inside its "table doesn't exist" branch, so
  `/support/tickets` fatals on every fresh install (`get() on null`). Same
  class: the admin menu ships as `mod_menu`, which renders nothing in admin.
  Both are now unconditional steps in `provision.php`. **Rule: any "works on
  prod but not on a fresh install" bug becomes a provisioning step, not a wiki
  note.** Known-open item of this class: the internal OAuth client
  (`hub_account=1`) is never created, so the course editor's API calls 401
  (`Oauth/Storage/Mysql.php:92` chokes storing tokens for a missing client).
- **R2 — component params are configuration.** Components can select custom
  layouts through parameters such as `tmpl`; setting one only in the live DB
  will not survive a rebuild. `hub.yml` needs a
  `components.<name>.params` surface that provision merges (it already merges
  module params — extend the same mechanism).
- **R3 — mail must never 500 the hub.** Saving a group fires a notification;
  with no reachable SMTP the mailer throws an *uncaught* exception → 500 on a
  core action. Mailpit is now in the base stack; k8s must ship an equivalent
  default (sink or relay), and "mail unreachable" should degrade to a logged
  error, never a 500 (worth an upstream patch, but the infra default is ours).
- **R4 — extensions = clone + register + migrate.** MUSE "sync" only clones;
  the `#__extensions` row comes from the extension's own migration (if it has
  one) or `addModuleEntry`/`addComponentEntry`. `provision.php` already does
  all three; the CLI must expose it (`autohub ext install`) so nobody re-learns
  this through the admin UI.

### 3.2 The template/asset pipeline needs first-class commands

- **R5 — only `site.css` is auto-built.** Paired `html/**/*.{less,css}`,
  `component.css`, vendor, and course-layout CSS are loaded directly; editing the `.less`
  silently does nothing and desyncs the pair (this cost us the `.metadata` and
  `.aside .container` customizations). The CLI needs `autohub assets build`
  that compiles *everything* it knows about, and a `--check` that flags
  less/css pairs whose compiled output diverges.
- **R6 — cache clearing is a deploy step, not folklore.** Production served a
  stale `site.css` because in production mode HUBzero serves the cached file
  as long as it exists; `muse cache css clear` targets the *wrong path* for
  the per-client layout. `autohub cache clear` must remove
  `app/cache/{site,admin,api,...}` (what `muse cache clear` does) and then warm
  the cache with one request. Static files without `?v=` busters (group.css,
  vendor and course-layout assets) additionally need a documented hard-refresh caveat — or a
  buster added upstream.
- **R7 — compile errors must be loud.** `Assets::getSystemStylesheet()`
  swallows LESS errors and serves an unstyled site. `hub-assets` already
  surfaces file/line; keep that behavior in the CLI and fail the command.

### 3.3 Secrets

- **R8 — tokens never persist.** During the work, GitLab PATs ended up in
  `.git/config` remotes (and in chat, forcing rotation — twice). The pattern
  that works: token in `.env` only, injected per-invocation via `GIT_ASKPASS`,
  remotes stored tokenless. The CLI must own this (`autohub ext install`,
  `autohub template push`) so tokens never appear in argv, config, or logs.
  Log filters must scrub `glpat-*` and friends.

### 3.4 Verification is a product feature

- **R9 — `autohub verify` as a first-class command.** The checks we ran by
  hand after every change, codified: page statuses (`/`, key components,
  `/administrator/`), asset sweep (every linked css/js resolves, no
  MIME/nosniff blocks — the 0-byte `mod_whatsnew_custom.css` 403 was exactly
  this), compiled CSS exists and is non-trivial, admin login E2E, mail
  reachable, DB migrations current. Output JSON, exit non-zero on failure.
  Every mutating command runs a scoped subset automatically.
- **R10 — diagnosis needs a front door.** The recurring loop was: hit a 500 →
  `docker compose logs | grep cms.ERROR` → read the stack trace → query the DB
  with the `jos_` prefix. `autohub logs --errors`, `autohub db shell`, and
  `autohub doctor` (log-pattern → known-cause table, seeded from the Skill's
  troubleshooting table) shortcut that loop for both humans and the agent.

### 3.5 Driver requirements (from running the docker stack)

- **R11 — state lives in three places** and each needs a k8s answer:
  `hub_src` (CMS checkout; PVC), `hub_app` (config/uploads/logs/cache; PVC),
  the database (StatefulSet or external). The image stays app-less in both
  drivers.
- **R12 — the stack is 4 services**: web, cron (same image, `hub-cron`), db,
  mail. In k8s: Deployment, CronJob (or sidecar loop), StatefulSet/external
  DB, mailpit Deployment (or SMTP relay Secret).
- **R13 — HTTPS is not optional.** `com_login` hardcodes an https redirect;
  Apache header limits needed raising for HUBzero's cookie load
  (`LimitRequestFieldSize 32768`). Docker: self-signed via `hub-tls.sh`;
  k8s: Ingress + cert-manager, same header limits on the ingress/pod.

---

## 4. The CLI

Name: `autohub`. Single entry point at [`cli/autohub`](../assets/scaffold/cli/autohub) — a
zero-dependency Python 3 script (stdlib only; see §8 for why not Go). It does
**not** shell out to the Makefile: it drives `docker compose` directly through
the driver (§4.3) and calls the same in-container `hub-*` commands the
entrypoint uses, so the CLI and a from-scratch boot exercise identical code.
The Makefile stays as a thin human convenience; the CLI is the contract.

### 4.1 Contract (what makes it Skill-drivable)

- **Non-interactive by default.** Prompts only behind `--interactive`.
  (`hub-init.sh` already follows this.)
- **`--json` on every command.** Human text on stdout by default; with
  `--json`, a single JSON object: `{ok, action, details, checks[], next[]}` —
  `next[]` is a list of suggested follow-up commands, which is what lets an
  agent chain operations without hardcoded playbooks.
- **Exit codes:** 0 ok · 1 operation failed · 2 verification failed after an
  otherwise-successful operation · 3 bad usage.
- **Idempotent.** Re-running any command on a converged hub is a no-op that
  reports "already".
- **Secrets-safe.** No secret in argv or output; everything via env/files
  (R8).

### 4.2 Command tree (v1 = shipped)

Every command takes the global flags `--json`, `--dev`, `--project-dir` in
either position. What's implemented in [`cli/autohub`](../assets/scaffold/cli/autohub) today:

```
autohub init      [--site --preset --template-url --force]  → scripts/hub-init.sh
autohub up        [--wait --timeout]                 → compose up -d --build; --wait blocks on "bootstrap complete"
autohub down                                         → compose down (keeps volumes)
autohub destroy   --force --confirm <target> --snapshot <dir> → delete volumes only after target + recovery validation
autohub status                                       → per-service state/health + url (R11)
autohub provision                                    → hub-provision; parses "N applied, M failed" (R1–R4)
autohub verify    [--scope all|site|assets|components|mail|login|db] [--route PATH]   (R9)
autohub assets    build|clean|lint                   → host LESS preflight + hub-assets (R5)
autohub cache     clear [--warm]                     → hub-muse cache clear (+ warm request) (R6)
autohub doctor    [--tail]                           → recent web logs → known-cause table (R10)
autohub logs      [--errors --tail --limit --follow] → web logs, secret-scrubbed
autohub db        shell|query <SQL>|dump|restore     → mariadb client; host-side dump, guarded restore
autohub backup    create|restore                     → host-side DB + hub_app + TLS + config snapshot/restore
autohub migrate                                      → hub-migrate
autohub ext       list|install|enable|disable        → jos_extensions list; mutate via hub.yml + provision (R4)
autohub admin     <user>                             → hub-admin; password comes from .env, never argv
autohub template  create|status|push [--name --branch --force]  → local starter or template git repo (R8)
autohub update    [--ref --no-backup]                → full-state snapshot, hub-update, restart web (M6 safety)
autohub muse      <args…>                            → hub-muse passthrough
```

**Initial live M1/M2 result** (against a running custom-template test stack):
`status` reports all four services healthy. Verification covers the homepage,
administrator, compiled stylesheet, denied private config, SMTP,
administrator authentication, and application bootstrap. The component scope
now inventories primary-menu plus standard native routes and sweeps linked CSS,
JavaScript, images, and fonts on every route. The original asset sweep caught a
real 403 on a 0-byte `mod_whatsnew_custom.css`.

An unavailable administrator-login check now fails verification rather than
silently reducing coverage. Visual/template work still requires browser
inspection of affected routes and viewports because asset delivery cannot prove
layout correctness.

Every row from the original tree is now wired. `template push` (M6) supplies
its token via GIT_ASKPASS with the *username* `oauth2` embedded in the push URL
(not a secret) so the token — kept in the container's `$GITLAB_TOKEN` from
`.env` — reaches only git's password prompt: never argv, the askpass file, or
`.git/config`, and the stored remote stays tokenless. *`template status`
verified live against a custom template repository; `push` was not run (it writes to a
real remote).*

### 4.3 Driver abstraction

Every command resolves operations through a driver interface; v1 has exactly
one implementation.

```
interface Driver:
  exec(service, cmd)          # docker compose exec web … | kubectl exec …
  copy(src, dst)              # docker compose cp        | kubectl cp
  logs(service, opts)
  up(profile) / down() / destroy()
  status() -> {services: [{name, state, health}]}
  url() -> https endpoint     # localhost:PORT           | ingress host
```

Selected by `driver: docker|kubernetes` in a new small project file
(`autohub.yml`, distinct from `hub.yml` — see §6). Commands above the driver
line never mention docker or kubectl.

### 4.4 Kubernetes driver ✅ (verified on minikube)

Shipped: [`KubernetesDriver`](../assets/scaffold/cli/autohub) (same Driver interface, drives
`kubectl`/`helm`), driver selection via [`autohub.yml`](../assets/scaffold/autohub.yml.example),
and a Helm chart under [`deploy/chart/`](../assets/scaffold/deploy/chart/). Selected by
`driver: kubernetes` — every `autohub` command then targets the cluster with no
change to the command surface. **Verified end-to-end on minikube**: `helm lint`
clean, `autohub up` installs the release and a from-scratch first boot completes
(`bootstrap complete`), then site/admin return 200, `site.css` compiles,
`config` is denied 403, and admin login works — all against the cluster.

Two bugs the live run caught (now fixed): a `wait-for-db` initContainer used
`nc`, which the image lacks, so the web pod hung in Init — removed it, since the
entrypoint's own `wait_for_db` (mariadb client) already gates first boot; and
`up()` on the k8s path could fail silently because `cmd_up` ignored the driver's
return code. `up()` now also creates the `.env`-backed Secret over stdin
(values never on argv), passes the real `hub.yml` via `--set-file`, and carries
non-secret hub identity from `.env`.

- **Chart = the compose topology.** `web` Deployment (replicas 1), `db`
  StatefulSet (relaxed sql-mode + utf8mb3, as compose), optional `mail`
  (mailpit) Deployment, `hub.yml` in a ConfigMap mounted at
  `/etc/hubzero/hub.yml`, `.env` secrets in a Secret, PVCs for src/app/tls,
  optional Ingress.
- **Cron is a sidecar in the web pod**, not its own workload — that's what lets
  it share the RWO `src`/`app` volumes (a separate pod can't co-mount them).
- **First boot stays entrypoint-driven.** The same image + entrypoint run the
  full bootstrap in the web pod (an initContainer just waits for the db);
  readiness gates on the `bootstrap complete`-backed HTTP probe. **Provisioning
  and asset builds run *inside* the live web pod** (`kubectl exec`), not as a
  separate Job — a Job can't mount the RWO PVC the web pod holds. This is a
  deliberate change from the earlier "provision as a Job" sketch.
- Out of scope: multi-replica web (HUBzero sessions/uploads are not
  share-nothing; needs RWX volumes or session externalization first). `Recreate`
  strategy is used so RWO volumes hand off cleanly on redeploy.

---

## 5. The Skill ✅

The repository is a single standard skill package at [`SKILL.md`](../SKILL.md):
**the Skill teaches when to act; the CLI knows how.** The skill routes create,
diagnose, change, backup, restore, upgrade, and reset work while the deployable
project lives under `assets/scaffold/`.

- Creation uses `scripts/create_project.py`, then `autohub init` → `up --wait`
  → `verify` inside the generated project.
- Day-2 work starts with a `status`/`verify` baseline, then narrows through
  `doctor`, error logs, scoped verification, and read-only DB queries.
- Content-rich site builds map ordinary pages to native `com_content` articles,
  research objects to resources, communities to groups, and routes to menus;
  templates render those components instead of becoming page routers.
- Reset, forced initialization, and restoration resolve an exact target and
  require a host-side recovery point outside the volumes/PVCs being changed.
- Visual work requires browser QA; database-only dumps and CronJobs remain
  partial rather than disaster-recovery backups.
- The JSON contract (§4.1) keeps agent behavior synchronized with CLI behavior.

---

## 6. Manifest evolution

Split concerns across three files (two exist):

| File | Owns | Committed? |
|---|---|---|
| `.env` | secrets, ports, hosts, TLS, mail relay | no |
| `hub.yml` | the hub: template, extensions, plugins, components (+params, R2), content seeds, menus | yes |
| `autohub.yml` (new) | infrastructure choice: driver, k8s context/namespace, resource sizing, backup schedule | yes |

`hub.yml` additions driven by deployment testing:
- ✅ **Component params merge** (R2). `components:` now merges into
  `#__extensions.params` via `merge_extension_params()` instead of the
  `saveParams` macro, which *replaced* the whole blob — setting one key would
  have silently wiped the rest, violating the additive-only contract. Plugin
  params share the same helper. *Verified live: a probe parameter merged into
  `com_courses` existing keys without loss.*
- ✅ **`seeds:` opt-outs.** The unconditional fixes (`admin_menu`,
  `support_query_folders`) default on; `seeds: {name: false}` skips one.
  *Verified live both directions.*
- ✅ **Preset composition — decided: no runtime layering.** `hub.yml` is the
  single, complete, authoritative definition; you *update the whole file*, not
  layer deltas on a base. `hub-init` may copy `presets/<name>.yml` as a complete
  scaffold, while organization-specific fragments remain **author-time** inputs,
  not merges the engine performs. List sections
  use replace semantics — the file's list is the complete intended set. No
  deep-merge code in `provision.php`. (Provisioning itself stays additive-only;
  "complete manifest" is authoring discipline, not destructive convergence.)

---

## 7. Roadmap

| Milestone | Deliverable | Definition of done |
|---|---|---|
| **M1 — CLI skeleton** ✅ | `autohub` wrapping existing scripts, docker driver, `--json` | create flow runs end-to-end via CLI only — *done; `status`/`provision`/`assets`/`cache`/`up --wait` live against the running stack* |
| **M2 — verify/doctor** ✅ | R9 + R10 commands | agent detects & explains the failure classes we hit without human hints — *done; `verify` includes multi-route component/asset sweeps and structured login diagnostics; `doctor` retains the log-pattern table* |
| **M3 — Skill v3** ✅ | unified root `autohub` skill + bundled project scaffold | fresh conversation, empty dir → working custom-template hub, zero human commands — *single skill shipped, built on the CLI's `--json`/`next[]` contract* |
| **M4 — manifest v2** ✅ | component params, seeds (incl. OAuth internal client), preset layering | rebuilt-from-scratch hub needs no manual DB edits (course editor works) — *param-merge + `seeds:` opt-outs + OAuth internal-client seed all shipped & verified live; preset layering **decided out** (manifest is complete/authoritative, §6)* |
| **M5 — k8s driver** ✅ | Helm chart + driver impl | same Skill flow against a kind/minikube cluster — *verified end-to-end on minikube: `autohub up` → helm install → first boot → **site/admin 200, site.css compiled, config denied 403, admin login OK**; `status` and `destroy` exercised through the k8s driver* |
| **M6 — day-2 ops** ✅ | backup schedule, `update` with source-sync safety, template push flows | *`update` takes a host-side full-state snapshot (DB, hub_app, TLS, source revision and project config), `template push` ships with tokenless-remote/askpass hygiene, and the recurring database-only **backup CronJob** (own PVC, retention, driven from `autohub.yml`) renders clean. Production exports snapshots off-cluster.* |

---

## 8. Open questions

1. **CLI language.** ~~Go vs Python~~ **Resolved: Python 3, stdlib only.** The
   CLI's whole job is orchestrating `docker compose` and the PHP/bash `hub-*`
   scripts; a zero-dependency script that already runs everywhere those do beat
   a static binary's distribution edge, and kept M1 to a single file with no
   build step. Revisit only if we ship the CLI to hosts without Python.
2. **Where does the CLI run?** Host-side (drives docker/kubectl) is the
   default; some commands must run in-container (`provision`, `assets`). The
   driver hides this, but error reporting must make the boundary visible.
3. **Mail failure semantics (R3).** Infra default (sink) vs patching HUBzero to
   catch mailer exceptions. Do both? The patch would go to the CMS fork.
4. **Session/upload storage for k8s multi-replica** — deferred past M5.
5. **The `hubzero-cms/` checkout in this repo** — used for dev bind-mount;
   formalize as an optional `autohub dev` mode or drop from the design.

---

## Appendix A — incident → requirement index

| Incident (this repo's history) | Requirement |
|---|---|
| `/support/tickets` 500 — unseeded query folders | R1 |
| Admin backend had no menu (`mod_menu` vs `mod_adminmenu`) | R1 |
| Course editor 401s — missing internal OAuth client | R1 (open) |
| Course pages ignored a selected custom layout until a DB parameter edit | R2 |
| Group save 500 — no SMTP host resolvable | R3 |
| `mod_whatsnew_custom` cloned but invisible until registered | R4 |
| `.metadata`/`.aside` customizations lost in paired css/less | R5 |
| Prod served stale `site.css`; `muse cache css clear` wrong path | R6 |
| Whole site unstyled from one missing `;` in LESS | R7 |
| GitLab PATs in remotes/chat → rotations | R8 |
| “Boots but unusable” found only by hand-run checks | R9 |
| Every 500 diagnosed via log grep + `jos_` DB spelunking | R10 |
| Apache 400s from HUBzero cookie load until header limits raised | R13 |
