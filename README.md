# AutoHub

AutoHub is an agent-agnostic [Agent Skill](https://agentskills.io) for creating,
deploying, diagnosing, and safely maintaining
[HUBzero CMS](https://github.com/hubzero/hubzero-cms) hubs on Docker Compose or
Kubernetes.

The skill gives an AI coding agent a guarded operating workflow. The bundled
`cli/autohub` command provides the deterministic implementation, structured
JSON results, deployment-driver abstraction, and safety checks.

## What the skill does

- Creates a new AutoHub project from the bundled deployment scaffold.
- Initializes and starts HUBzero on Docker Compose or Kubernetes.
- Configures browser-trusted local Docker HTTPS and verifies host trust without
  insecure bypasses.
- Establishes a health baseline before changing an existing hub.
- Diagnoses site, asset, mail, login, and database failures.
- Applies declarative hub configuration from `hub.yml`.
- Provisions editable native articles and connects menu routes by article alias.
- Generates project-local templates with a native-component-first style
  baseline and no manual Compose edits.
- Verifies standard public component routes and every route's linked assets.
- Diagnoses consent interstitials separately from invalid administrator
  credentials.
- Lints legacy LESS compatibility and requires browser verification for visual
  changes.
- Creates coordinated database, application-data, TLS, source, and
  configuration snapshots.
- Guards restore, upgrade, reset, and destroy operations with explicit
  authorization and exact-target confirmation.

## Agent compatibility

AutoHub follows the open Agent Skills structure shared by
[Claude Code](https://code.claude.com/docs/en/skills) and
[Codex](https://learn.chatgpt.com/docs/build-skills.md):

```text
autohub/
├── SKILL.md
├── scripts/
├── references/
└── assets/
```

The skill contains no agent-specific instructions or metadata. Keep the
installed directory name `autohub`, because it must match the `name` in
`SKILL.md`.

Install or link this repository into the discovery location used by your
agent:

| Agent | Personal location | Project location |
|---|---|---|
| Claude Code | `~/.claude/skills/autohub` | `.claude/skills/autohub` |
| Codex | `~/.agents/skills/autohub` | `.agents/skills/autohub` |

These are installation locations only. The source repository remains one
portable skill package and does not contain `.claude` or `.agents` wrappers.

## Example requests

Once installed, ask the agent naturally or invoke the `autohub` skill using
the syntax supported by that agent. For example:

- “Create a new HUBzero project in `../research-hub` and verify it.”
- “Diagnose why this hub's administrator login is failing.”
- “Add this extension to `hub.yml`, provision it, and verify the result.”
- “Create a recovery point, upgrade to this pinned tag, and check for
  regressions.”
- “Update the active template and verify the affected desktop and mobile
  routes.”

The agent reads [`SKILL.md`](SKILL.md), selects the appropriate guarded
workflow, and operates through `cli/autohub` rather than assembling raw Docker
or Kubernetes commands.

## Create a project manually

From this repository, copy the immutable scaffold into a new or empty target:

```bash
python3 scripts/create_project.py ../research-hub --json
cd ../research-hub
cli/autohub init --site "Research Hub" --json
cli/autohub tls setup --json
cli/autohub template create --name researchhub --json
cli/autohub assets lint --json
cli/autohub up --wait --json
cli/autohub verify --scope tls --json
cli/autohub verify --json
```

The creator refuses to merge into a non-empty directory. Initialization writes
generated credentials to `.env` with mode `600`, assigns a project-specific
Docker Compose namespace, probes published ports, and does not print secrets.

First boot can take several minutes while the CMS source, dependencies, schema,
configuration, and assets are prepared.

Local trusted HTTPS uses mkcert. Its first setup installs a local CA into the
host trust stores and may request administrator credentials, so an agent must
obtain authorization before running it. The ignored `.autohub/tls/` directory
contains the project leaf certificate and key. Never copy or commit mkcert's
`rootCA-key.pem`.

## Architecture

```text
human or AI agent
       │
       ▼
    SKILL.md             guarded workflow and safety policy
       │
       ▼
  cli/autohub            stable command and JSON contract
       │
   ┌───┴────────┐
   ▼            ▼
Docker       Kubernetes  deployment drivers
Compose      + Helm
   └─────┬──────┘
         ▼
    HUBzero CMS
```

The runtime image contains PHP, Apache, extensions, and AutoHub management
commands—not a baked-in CMS checkout. The selected HUBzero source revision is
placed in persistent storage during first boot, which allows source updates
without rebuilding the runtime image.

## Configuration boundaries

| File | Responsibility | Commit it? |
|---|---|---|
| `.env` | Secrets, ports, database, mail, TLS, and infrastructure settings | No |
| `hub.yml` | Extensions, template, plugins, component parameters, and supported hub content | Yes |
| `autohub.yml` | Deployment driver and exact Docker or Kubernetes target | Yes, if it contains no secrets |

Provisioning is idempotent and additive-only. Removing an entry from `hub.yml`
does not delete the corresponding live object.

## Safety model

AutoHub treats operational verification as part of every change:

- Non-streaming commands support `--json` and return one
  `{ok, action, details, checks, next}` object.
- Destructive commands require explicit authorization, `--force`, and the
  resolved target identifier.
- Risky operations require a completed recovery point outside the storage being
  changed.
- Passwords, tokens, sessions, private keys, and `.env` contents must never be
  reproduced in output or commits.
- A certificate warning is a failed local deployment prerequisite; browser QA
  must not silently switch to HTTP and claim completion.
- Database dumps are identified as database-only; they are not presented as
  complete disaster-recovery backups.
- Template changes require browser inspection because successful compilation
  and HTTP responses do not prove visual correctness.
- Custom templates must cover standard native component surfaces such as
  resources, groups, members, search, and support at desktop and mobile widths.

## Repository layout

| Path | Purpose |
|---|---|
| [`SKILL.md`](SKILL.md) | Agent workflow, routing, safety rules, and operating invariants |
| [`scripts/create_project.py`](scripts/create_project.py) | Copies the bundled scaffold into a new project |
| [`assets/scaffold/`](assets/scaffold/) | Docker, Kubernetes, CLI, provisioning, and initialization assets |
| [`references/scaffold.md`](references/scaffold.md) | Generated-project setup, configuration, first boot, and production reference |
| [`references/content-and-templates.md`](references/content-and-templates.md) | Native content ownership and template architecture |
| [`references/native-component-styling.md`](references/native-component-styling.md) | Component styling contract and visual acceptance matrix |
| [`references/design.md`](references/design.md) | CLI contract, drivers, requirements, verification, and architecture decisions |

## Requirements

Creating a project from the bundled scaffold requires Python 3; the creator
uses only the standard library.

For Docker deployments, the generated project requires Docker Engine 20.10 or
newer with Compose v2. The image supports `amd64` and `arm64`. Browser-trusted
local HTTPS additionally requires
[mkcert](https://github.com/FiloSottile/mkcert); Firefox may require its
platform-specific NSS package.

Kubernetes deployments require access to the selected cluster plus `kubectl`
and Helm. Ingress and certificate management depend on the generated project's
cluster configuration.

## Further reading

- [Skill operating instructions](SKILL.md)
- [Scaffold and deployment reference](references/scaffold.md)
- [Architecture and design](references/design.md)
- [Open Agent Skills specification](https://agentskills.io/specification)
