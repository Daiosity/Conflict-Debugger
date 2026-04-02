# Changelog

All notable changes to `Plugin Conflict Debugger` are tracked here.

## 1.0.22

- Added a focused Diagnostic Session workflow so users can target one site area, reproduce the issue, and capture session-tagged telemetry for that trace.
- Tagged request contexts and runtime events with diagnostic session IDs so the runtime viewer can collapse around the active or latest session instead of unrelated recent traffic.
- Added start/end session controls in Diagnostics and session-aware runtime event filtering in the dashboard.

## 1.0.21

- Added a Recent Runtime Events viewer in Diagnostics for JavaScript errors, failed requests, and mutation signals captured during recent site activity.
- Linked findings to matching runtime events so you can jump from a flagged interaction to the concrete telemetry behind it.
- Stored structured runtime events separately in scan results so the dashboard can review observed breakage without flattening everything into generic error rows.

## 1.0.20

- Added a plugin drilldown tab so you can inspect one plugin at a time, including its current findings, related plugins, categories, and request contexts.
- Added a compare-scans panel that highlights new and resolved findings between the latest two scans.
- Polished the dashboard styles and interactions so the new drilldown workflow is easier to navigate.

## 1.0.19

- Added a Log Access Check diagnostics panel that explains whether `debug.log` is enabled, exists, and is readable by PHP.
- Added lightweight scan history so recent scan outcomes can be compared over time from within the dashboard.

## 1.0.18

- Replaced archive creation with normalized ZIP entry generation so package paths use standard forward slashes instead of Windows backslashes during extraction.

## 1.0.17

- Fixed the host-friendly ZIP build so `plugin-conflict-debugger.zip` is truly flat at archive root while `plugin-conflict-debugger-wp-admin.zip` keeps the folder-inside structure for standard WordPress uploads.

## 1.0.16

- Renamed packaging outputs so the flat host-friendly ZIP now uses the plain plugin slug filename and the folder-inside package is clearly labeled for WP Admin uploads.
- Reduced duplicate diagnostics wording by keeping the log-access warning out of the analysis notes list when it is already shown as the top notice.

## 1.0.15

- Redesigned the admin dashboard with full-width findings and WordPress-style tabs for findings, diagnostics, and pro preview.
- Moved recent request contexts into a dedicated diagnostics view and improved long URL wrapping so request data no longer overflows its panel.
- Promoted site status into the summary row so key scan signals are visible without crowding the findings layout.

## 1.0.14

- Added two packaging targets: a standard WordPress uploader ZIP and a host-extract ZIP for control panels that create their own destination folder during extraction.

## 1.0.13

- Restored strict WordPress-standard ZIP packaging with a single `plugin-conflict-debugger/` folder inside the archive so subfolders like `includes/` and `assets/` install correctly.

## 1.0.12

- Added observer/debug plugin awareness so Query Monitor-style tooling is treated more conservatively than ordinary business-logic plugins.
- Grouped repeated callback-churn fingerprints into observer-artifact/global-anomaly findings instead of over-attributing them as multiple confirmed pairwise conflicts.
- Split execution surface from shared resource and tightened confirmed scoring so callback snapshot churn cannot become a 100% confirmed pairwise conflict without direct pair-specific causality.

## 1.0.11

- Added a strict standard-release build script that packages only the WordPress plugin files into a single top-level `plugin-conflict-debugger` folder.
- Standardized the rolling install ZIP to the WordPress-native folder-inside-zip format only.

## 1.0.10

- Added a safer bootstrap fallback so the plugin does not hard-fatal if a host extracts the ZIP into an unusual structure.
- Switched release packaging guidance back to the standard WordPress format with a single top-level plugin folder inside the ZIP.

## 1.0.9

- Added runtime mutation tracking for callback removal/replacement on sensitive hooks and asset queue/deregister mutations after enqueue.
- Integrated mutation events into the detector as concrete interference signals instead of treating them as generic overlap.
- Improved pair matching for runtime events by carrying owner slugs alongside resource hints.

## 1.0.8

- Added exact ownership snapshots for shortcode tags, block types, AJAX actions, and asset handles.
- Added request resource hints so runtime telemetry can associate observed breakage with concrete resources active on the affected request.
- Improved observed-breakage matching by using resource ownership maps instead of relying only on plugin names appearing in logs.

## 1.0.7

- Added lightweight request-context capture for frontend, admin, login, REST, AJAX, editor, checkout/cart/product, and cron requests.
- Added observed-breakage collection for JavaScript errors, failed same-origin REST/AJAX responses, missing assets, fatal runtime errors, and HTTP 4xx/5xx responses.
- Integrated recent request contexts and runtime telemetry into scan analysis to make findings more request-aware and trustworthy.

## 1.0.6

- Refactored scoring around weak overlap, contextual risk, concrete interference, and observed breakage tiers.
- Added strict severity caps so shared hooks, shared surfaces, recent updates, and extreme priorities cannot escalate into false critical findings.
- Updated finding wording and dashboard output to distinguish overlap, risk, interference, and confirmed conflict signals.

## 1.0.5

- Added exact registry-based conflict detection for duplicate post type keys, taxonomy keys, rewrite slugs, REST bases, query vars, and admin menu/page slugs.
- Hardened runtime registry collection so WordPress hook argument differences do not break diagnostics.
- Improved uninstall cleanup to remove stored registry snapshots.

## 1.0.4

- Refactored conflict detection around broader WordPress conflict surfaces instead of WooCommerce-heavy logic.
- Added structured evidence items and affected-area output for frontend, admin, editor, login, API/AJAX, forms, caching, SEO, routing, content model, notifications, security, and background jobs.
- Expanded plugin category inference to support more generic WordPress site types.

## 1.0.3

- Improved findings table readability with wider explanation/action columns and expandable evidence.
- Added addon-parent relationship awareness to reduce false positives in likely extension/plugin-suite pairs.

## 1.0.2

- Improved scan UX: scans now run in background with progress polling.
- Fixed completion refresh loop by preventing repeated reloads per scan token.
- Updated plugin metadata author and version.

## 1.0.0

- Initial production-leaning release with manual scans, heuristic findings, dashboard UI, and pro-ready architecture.
