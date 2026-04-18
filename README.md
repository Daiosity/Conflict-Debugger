# Daiosity Conflict Debugger

![Daiosity Conflict Debugger icon](./assets/images/daiosity-conflict-debugger-icon.svg)

Daiosity Conflict Debugger is a WordPress diagnostics plugin focused on one job:

**helping site owners and developers find real plugin conflicts without wasting hours disabling plugins one by one.**

The detector is intentionally conservative, context-aware, and built to prefer exact interference signals over vague overlap.

## Why This Exists

WordPress sites often break in ways that are expensive to trace:

- frontend rendering breaks after a plugin update
- admin screens stop saving properly
- AJAX or REST requests start failing
- checkout, login, editor, or routing behavior changes unexpectedly
- several plugins appear suspicious, but no one knows where to start

Most troubleshooting still depends on manual trial and error. Daiosity Conflict Debugger shortens that path by surfacing:

- where overlap is happening
- what shared resource may be involved
- which request context is affected
- whether the finding is weak, contextual, concrete, or observed breakage

## What Makes It Different

**Promise:** Find likely plugin conflicts before you waste hours disabling plugins manually.

**Design principle:** false positives are worse than missing weak signals.

That means the detector does **not** treat common WordPress behavior as proof of conflict. Shared hooks, broad plugin categories, recent updates, and extreme priorities are weak signals only. High-confidence findings require concrete interference or observed breakage.

## Features

### Core scanning

- manual scan trigger from the WordPress admin
- scan status tracking and persistent scan results
- environment snapshot capture
- recent plugin change awareness
- scan history for comparing results over time

### Conflict detection

- conflict-surface based reasoning instead of broad keyword guessing
- request-context awareness across frontend, admin, REST, AJAX, login, editor, cron, and commerce flows
- exact ownership capture for resources like AJAX actions, REST routes, shortcodes, blocks, and asset handles
- runtime mutation tracking for callback churn and asset dequeue or deregister behavior
- observer-artifact and global-anomaly classification to reduce false positives from tools like Query Monitor

### Runtime evidence

- recent request context capture
- lightweight runtime telemetry
- trace warnings kept separate from actual PHP, log, and request failures in scan summaries
- JS and failed-request evidence surfaced in diagnostics
- log access checks with graceful fallback when direct `debug.log` access is unavailable
- request trace comparison between the most abnormal captured trace and the closest calmer baseline

### Admin UX

- WordPress-native admin screen
- findings tab
- finding detail drilldowns with evidence strength and linked runtime traces
- diagnostics tab
- plugin-focused drilldown tab
- runtime events viewer
- focused diagnostic session workflow for reproducing one issue path at a time
- focused validation mode for one plugin pair, hook, asset handle, REST route, or AJAX action

## How The Detector Works

The detector reasons through this model:

`request -> hook -> callback -> resource -> mutation -> breakage`

It classifies evidence into four tiers:

1. **Weak overlap**
   - shared hooks
   - broad surface overlap
   - recent updates
   - extreme priorities on their own
2. **Contextual risk**
   - same request context
   - same sensitive workflow area
   - same hook family in a risky flow
3. **Concrete interference**
   - same exact resource
   - callback removal or replacement
   - asset deregister or dequeue conflicts
   - same AJAX action, REST route, shortcode, block, slug, or handle
4. **Observed breakage**
   - PHP runtime errors
   - JS failures
   - failed AJAX or REST requests
   - missing assets
   - request-scoped breakage evidence

Severity is capped deliberately:

- weak only: at most `low`
- weak plus contextual: at most `medium`
- `high` requires concrete interference
- `critical` requires observed breakage

## Installation

### WordPress Admin upload

1. Download the latest WordPress admin package from [GitHub Releases](https://github.com/Daiosity/Conflict-Debugger/releases).
2. In WordPress, go to `Plugins > Add New > Upload Plugin`.
3. Upload the ZIP package.
4. Activate **Daiosity Conflict Debugger**.
5. Open `Tools > Daiosity Conflict Debugger`.

### Local development

Copy the plugin folder into:

```text
wp-content/plugins/daiosity-conflict-debugger/
```

Then activate it from the WordPress admin.

## Releases

Release packages are published here:

- [GitHub Releases](https://github.com/Daiosity/Conflict-Debugger/releases)

Release outputs:

- `daiosity-conflict-debugger-wp-admin.zip`
- `daiosity-conflict-debugger.zip`

## Regression Fixtures

Detector regression fixtures live in [`tests/fixtures/`](./tests/fixtures/). They provide small WordPress plugins for:

- normal admin overlap that should stay low/shared-surface
- asset lifecycle mutation
- callback removal
- REST route collision
- AJAX action collision

These fixtures are meant to keep detector trust high as the heuristics and tracing layers evolve. The repository also includes LocalWP helper scripts for resetting telemetry, clearing debug logs, and replaying authenticated admin requests during repeatable test runs.

## Screenshots

Example UI screenshots can be stored in [`docs/screenshots/`](./docs/screenshots/).

## Repository Structure

```text
daiosity-conflict-debugger/
|-- assets/
|-- docs/
|-- includes/
|   |-- Admin/
|   |-- Core/
|   |-- Pro/
|   `-- Support/
|-- languages/
|-- tools/
|-- AGENTS.md
|-- CHANGELOG.md
|-- daiosity-conflict-debugger.php
|-- readme.txt
`-- uninstall.php
```

## Development Notes

- PHP 8.1+ compatible
- namespaced OOP architecture
- WordPress-oriented coding standards
- capability checks, nonces, sanitization, and escaping throughout admin actions
- premium-ready structure without faking premium functionality

## Roadmap

Near-term priorities:

- stronger callback actor attribution so removal events can graduate from trace warnings to conservative pairwise findings when the mutator is proven
- scan diff UX that highlights new findings, resolved findings, and confidence changes
- deeper exact ownership mapping
- improved plugin-focused diagnostics
- safer staging-oriented isolation workflows

See [TASKS.md](./TASKS.md) for the actively maintained implementation list.

Longer-term premium-oriented direction:

- safe test mode
- binary-search conflict isolation
- scheduled scans and alerts
- staging-focused diagnostics and remediation guidance

## Changelog

Release history lives in:

- [`CHANGELOG.md`](./CHANGELOG.md)
- [`readme.txt`](./readme.txt)
