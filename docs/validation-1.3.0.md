# Validation: 1.3.0

Validated on 23 September 2026 against the local WordPress 7.1.2 installation,
PHP 8.2.29 and Plugin Check 2.1.0.

## Results

- All 33 standalone policy/investigation regression cases passed.
- Privacy, payload bounds and browser authorization regressions passed.
- All 37 production PHP files passed lint.
- The packaged ZIP installed successfully over the active local plugin.
- WordPress integration passed for bounded storage, redaction, administrator-only
  reporting and real REST method dispatch, including HEAD fallback.
- Plugin Check against the installed ZIP, with its CLI runtime bootstrap enabled,
  completed successfully with no errors or warnings.
- A complete scan reported healthy status, zero likely conflicts and zero trace
  warnings with the debugger and Plugin Check active. Four historical error
  signals remained; existing logs were not cleared to obtain a clean result.
- Consecutive builds produced identical ZIP hashes and validated the required
  single-root layout with forward-slash paths.

ZIP SHA-256:
`E639F347D7560CD0BE9647A84AAD6EE3615F68D76B7A128BD629401887E63951`

## Commands

```powershell
php tests/policy-regression.php
php tests/privacy-regression.php
./tools/build-standard-zip.ps1
& 'WordPress Site/wp.bat' plugin install build/daiosity-conflict-debugger.zip --force --activate
& 'WordPress Site/wp.bat' eval-file tests/wordpress-regression.php
& 'WordPress Site/wp.bat' plugin check daiosity-conflict-debugger '--require=WordPress Site/app/public/wp-content/plugins/plugin-check/cli.php' --format=strict-json
```

## Boundaries

This pass did not validate a full multisite matrix, browser rendering across themes,
or all third-party plugin combinations. Synthetic direct-attribution fixtures test
the proof contract; they do not claim the snapshot tracer captures mutation call
sites. Automatic production replay and plugin isolation are not implemented.
WordPress.org review and approval are separate from these automated checks.

See [Investigation Precision](investigation-precision.md) for the architecture and
current attribution limits.
