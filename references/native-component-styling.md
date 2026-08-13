# Native component styling and acceptance

Use this reference whenever a custom HUBzero site template is created or
changed. A template is complete only when it presents native components
consistently, not when its authored landing pages look finished.

## Contents

- [Use two coverage levels](#use-two-coverage-levels)
- [Build the base layer first](#build-the-base-layer-first)
- [Keep branded page styles scoped](#keep-branded-page-styles-scoped)
- [Test the route and state matrix](#test-the-route-and-state-matrix)
- [Run functional and visual verification](#run-functional-and-visual-verification)

## Use two coverage levels

Apply both levels to every custom template:

1. **Baseline compatibility** — every reachable public native component must be
   readable, responsive, keyboard accessible, and visually coherent in empty
   and error states.
2. **Branded component coverage** — every component used or linked by the hub
   must also have a populated listing, detail view, form/action state, and any
   component-specific override tested.

Do not infer that an unlinked component is irrelevant. Standard HUBzero routes
remain reachable and may be used by administrators, search engines, account
flows, or future content.

## Build the base layer first

A template **layers on core's stylesheet; it does not replace it**. The first
rule of `less/site.less` is:

```less
@import "../../../../core/assets/less/site.less";
```

Without that import the compiled `site.css` contains none of HUBzero's
`.grid`/`.col.span*` system, `#content-header`, fontcons, tabs, notifications,
or tooltips, and every native component route (`/resources`, `/groups`,
`/members`, `/support`) renders with collapsed columns and unstyled chrome —
while generic-selector checks still pass, because the template styles inputs
and tables in general. A compiled stylesheet in the low tens of kilobytes is a
red flag; the stock `welcome` template compiles to roughly 78KB. Core's
variables are camelCase and do not collide with a template's own tokens, so the
import is safe. The architecture audit fails a template whose stylesheet
neither imports core nor defines the grid primitives itself.

**The shell must emit the component name and `#content`.** Core scopes a large
share of its component CSS under both:

```css
.com_resources .resource-type { ... }
#content.com_members { ... }
```

Core's own templates supply them, so a shell that omits either makes those
rules match nothing, on every component route, in every hub built from that
template — which reads as "core's CSS is ugly" rather than "core's CSS never
applied". Emit both in `index.php` and `component.php`:

```php
$option = Request::getCmd('option', '');
...
<body class="ah-site <?php echo $esc($option); ?>">
<main id="content" class="ah-main <?php echo $esc($option); ?>" tabindex="-1">
```

The skip link and any `#main-content` selectors move with it.

**Restyle core's shared container primitives first.** `com_kb`, `com_groups`,
`com_support` and `com_answers` build their pages from blocks core paints:

| Selector | Core value |
|---|---|
| `.container`, `.container-block` | `#ececec` |
| `.data-entry` | `#666` |
| `.subject .container h3`, `.container-block h3` | a 30px diagonal hatch |

On a modern template these read as broken rather than plain — flat grey slabs,
a dark grey search bar, candy-stripes across every block heading. Restyling
these five selectors once fixes every component that uses them, so do it before
chasing any single component's appearance.

Write them as rules, not variable redefinitions. LesserPHP evaluates in source
order: a redefinition placed *after* the core import never reaches rules core
has already compiled, and one placed *before* it is overwritten by core's own
`variables.less`.

**`icon-` is a reserved class prefix.** Core's `icons.less` injects fontcons
pseudo-elements into every element matching `*[class^="icon-"]` or
`*[class*=" icon-"]`. A layout class such as `.icon-card` or `.icon-hero`
therefore inherits invisible `::before` content — observed as a four-column
grid rendering five items with an empty first cell, with nothing in any log.
Use any other prefix for template classes; `template create` refuses an
`icon-*` template name for the same reason.

Constrain the main content column itself (the starter's `.ah-main`) rather
than an enumerated list of component wrapper classes: components emit markup a
template cannot predict (`#content-header`, `section.section`, …), and any
wrapper missing from a child-selector width rule runs flush against the
viewport edge.

Keep unscoped rules restrained because they affect every component. Cover these
shared surfaces before designing landing-page wrappers:

| Surface | Required behavior |
|---|---|
| Typography | Native `h1`–`h4` remain readable and do not inherit hero scale |
| Main/aside layout | Columns have `min-width: 0`, collapse on narrow screens, and do not overflow |
| Headers | Component title, description, metadata, and action areas have stable spacing |
| Filters/search | Labels, inputs, selects, help text, and submit/reset actions are aligned and usable |
| Buttons/actions | Primary, secondary, disabled, hover, and focus states are distinguishable |
| Lists/results | Empty, single, and multi-record states have consistent spacing and metadata |
| Tables | Cells are legible; narrow layouts scroll or reflow instead of clipping |
| Tabs/pagination | Active state is visible without relying on color alone |
| Messages | Success, warning, validation, and error states remain visible |
| Forms | Controls fit the viewport, labels remain associated, and validation is clear |
| Media | Images, SVG, video, and long links cannot create horizontal overflow |
| Component-only view | `component.php` preserves typography, messages, and controls |
| Error view | `error.php` returns an honest error presentation with a recovery route |

Start from the baseline generated by:

```bash
cli/autohub template create --name <alias> --json
```

Adapt its palette and chrome while retaining the native-component primitives.
Use narrowly scoped `html/<component>/` overrides only when the component markup
cannot be styled safely. Overrides may change presentation, not record
ownership, routing, or permissions.

Prefer a stylesheet rule to a view override wherever the difference is
presentational: CSS can restyle, show/hide, reorder within a formatting context,
and add pseudo-content, and it does not block core upgrades. It cannot change
data or text, add or remove attributes, unwrap an element, or alter logic — take
the override for those. Before writing either, read
[template-override-mechanics.md](template-override-mechanics.md): component and
plugin stylesheets load *after* the template's and frequently scope under an id,
so a rule that looks correct can never apply, and a stylesheet override that
omits `@import url("/core/…")` silently discards all of core's styling for that
extension.

## Keep branded page styles scoped

Place expressive hero typography, cards, diagrams, and landing-page grids under
a project-specific wrapper such as `.project-page`. Do not make every native
component heading inherit a five-rem hero size or require project-specific
classes to make a form usable.

Use one visible semantic page title:

- Prefer HUBzero's native component/article title and style its heading.
- If an authored landing-page body contains its own `<h1>`, suppress native
  title and metadata output through declared component/article options.
- Verify the final DOM has exactly one visible `<h1>`.
- Do not hide duplicate titles with request-path dispatch, adjacent selectors,
  or a selector coupled to one article wrapper.

Use base-URL-aware helpers for template assets. Avoid hard-coded
`/app/templates/...` paths because deployment subpaths and template runtime
locations can differ — a protected template resolves under `/core`, and the
directory name changes when the template is renamed:

```php
(App::get('template')->protected ? '/core' : '/app')
    . '/templates/' . App::get('template')->template . '/js/…'
```

For component, plugin, and module assets prefer
`\Hubzero\Document\Assets::addPluginScript()` and its stylesheet counterpart,
which resolve the template override themselves. Note that a file placed outside
the path they resolve — `html/<extension>/<file>`, with no view directory — is
skipped without an error.

## Test the route and state matrix

Always inspect these routes when they are reachable:

| Route | Minimum state |
|---|---|
| `/resources` | Empty or listing view; filter/search controls |
| `/groups` | Empty or listing view |
| `/members` | Directory/account entry state permitted by configuration |
| `/search` | Empty query and no-results state |
| `/support` | Public landing or authentication boundary |
| Auth/login route | Anonymous form, validation, focus, and error state |
| Unknown route | Honest 404/error template |
| `?tmpl=component` | Reduced component-only presentation |

For every component represented in `hub.yml`, additionally inspect:

- a populated listing;
- one detail record;
- filters, pagination, tabs, or sidebars that the component emits;
- anonymous and authenticated states when permissions differ;
- a validation or empty-result state;
- desktop and narrow/mobile viewports.

Use a separate fixture project or representative declared records when final
content should remain minimal. Do not leave hidden template-side fixtures or
misclassify ordinary pages as resources merely to exercise a view.

## Run functional and visual verification

Run the deterministic prerequisites:

```bash
cli/autohub assets lint --json
cli/autohub assets build --json
cli/autohub cache clear --warm --json
cli/autohub verify --scope assets --json
cli/autohub verify --scope components --json
```

Add authored paths that are not in the primary menu:

```bash
cli/autohub verify --scope components \
  --route /learn --route /about --json
```

The component verification scope checks HTTP responses and sweeps linked CSS,
JavaScript, images, and fonts. It cannot prove layout or computed styling.

When the hub carries template overrides, also audit them: every override
reachable, every stylesheet override importing its core counterpart, no
duplicate DOM ids from stripped instance suffixes, and no hard-coded template
paths. See [template-override-mechanics.md](template-override-mechanics.md).

Use browser inspection to confirm:

- no horizontal overflow at desktop and narrow/mobile widths;
- a restrained, coherent heading hierarchy;
- visible keyboard focus and logical tab order;
- readable filters, inputs, buttons, tables, metadata, and empty states;
- no missing assets or browser console errors;
- adequate text and control contrast;
- correct active navigation and component ownership;
- populated component list/detail states when the hub uses that component.

For local visual inspection and administrator authentication, use HTTPS only
after `cli/autohub verify --scope tls --json` passes. If the browser rejects
the certificate, run the authorized `cli/autohub tls setup --json` workflow,
recreate the web service, and retry. Do not use HTTP to hide a trust failure and
then report browser verification as complete. If host-trust authorization or
browser inspection is unavailable, report the corresponding verification as
incomplete.
