# Detector Fixtures

These small fixture plugins exist to regression-test the detector against a few
known-good and known-bad patterns.

They are not packaged into release ZIPs.

## Scenarios

### 1. Normal admin overlap

- `admin-overlap-alpha`
- `admin-overlap-beta`

Expected result:
- broad admin overlap only
- should stay in `overlap` or `shared_surface`
- should not become `high` or `probable_conflict`

### 2. Asset lifecycle mutation

- `asset-owner-alpha`
- `asset-mutator-beta`

Expected result:
- `asset-owner-alpha` registers and enqueues `pcd-fixture-shared-admin-style`
- `asset-mutator-beta` dequeues and deregisters the same handle later
- should produce asset lifecycle mutation evidence with concrete resource matching

### 3. Callback removal

- `callback-owner-alpha`
- `callback-remover-beta`

Expected result:
- `callback-owner-alpha` adds a callback on `template_redirect`
- `callback-remover-beta` removes that callback
- should produce callback mutation evidence and stronger pair attribution

### 4. REST route collision

- `rest-route-alpha`
- `rest-route-beta`

Expected result:
- both register the same REST route
- should produce an exact route-collision finding instead of vague API overlap

### 5. AJAX action collision

- `ajax-action-alpha`
- `ajax-action-beta`

Expected result:
- both attach to the same `wp_ajax_` and `wp_ajax_nopriv_` action
- should produce exact action-collision evidence

## How to Use

1. Copy one scenario pair into a local WordPress test site.
2. Activate the pair you want to test.
3. Reproduce the relevant request path if needed.
4. Run Plugin Conflict Debugger.
5. Confirm the finding category, severity, confidence, and wording match the expected outcome above.

