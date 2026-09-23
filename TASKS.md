# Daiosity Conflict Debugger Task List

This task list keeps the next diagnostics milestones concrete and visible.

## Current Focus

- [x] Validate evidence claims before scoring and keep displayed proof counts consistent
- [x] Scope findings to a concrete resource and individual request trace
- [x] Normalize REST method overlaps and WordPress HEAD fallback
- [x] Prevent mutation attribution from spreading to incidental trace owners
- [x] Treat asset priority-boundary snapshots as partial attribution

- [x] WordPress 7.1.2 bootstrap, scan and Plugin Check 2.1.0 validation
- [x] Administrator-only browser telemetry and bounded diagnostic redaction
- [x] Protected diagnostic deletion and queued-job lifecycle cleanup
- [x] Remove unfinished Pro features from the distributed plugin
- [x] Inspect the actual installer package in GitHub Actions

- [x] Causal trace event foundation and request-scope-aware runtime telemetry
- [x] Asset lifecycle tracing with owner and mutator attribution states
- [x] Callback mutation tracing foundation with request scope and partial actor attribution
- [x] Callback mutation validation mode and deeper actor attribution
- [x] Finding detail view that links one finding to its exact trace, evidence, and score caps
- [x] LocalWP regression lab reset/request helpers for repeatable fixture runs
- [x] Centralized finding trust policy with attribution, contamination, observer, and confidence hard gates

## Next Up

- [x] Focused validation controls for one plugin pair, one hook, one asset handle, one REST route, or one AJAX action
- [x] Detector fixtures for known-good and known-bad conflict patterns
- [x] Scan diff UX that highlights new findings, resolved findings, and confidence changes between scans
- [x] Improved direct log diagnostics with clearer fallback reasons and alternate path support
- [ ] Capture direct remover callbacks at the mutation call site instead of relying on snapshot deltas
- [ ] Add request replay assertions that correlate a mutation and failure on the same trace

## Product Polish

- [ ] Add real dashboard screenshots to `docs/screenshots/`
- [ ] Refine GitHub release notes and packaged asset presentation
- [ ] Add GitHub labels and milestones for detector, telemetry, UI, packaging, and docs work
- [x] Add automated policy regression and ZIP validation to GitHub Actions

## Premium-Ready Follow-Up

- [ ] Staging-only safe isolation workflow
- [ ] Pair-scoped validation replay and comparison
- [ ] Scheduled scans and alerts once trace quality is stable
