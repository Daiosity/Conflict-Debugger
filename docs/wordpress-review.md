# WordPress Directory Review

Use `build/daiosity-conflict-debugger.zip` for submission. Do not submit the GitHub
source-code archive: it includes development files and regression fixtures.

## Validation

```powershell
php tests/policy-regression.php
php tests/privacy-regression.php
./tools/build-standard-zip.ps1
```

On a disposable WordPress installation with Plugin Check activated:

```sh
wp plugin check daiosity-conflict-debugger --format=strict-json
wp plugin check daiosity-conflict-debugger --require=wp-content/plugins/plugin-check/cli.php --format=strict-json
wp eval-file /path/to/repository/tests/wordpress-regression.php
```

Plugin Check covers automated rules; the directory team also reviews behavior,
security, naming, licensing and documentation. A clean report does not guarantee
approval. Reply in the existing review thread with the corrected ZIP and a brief
description of the fixes.

## Data and Permissions

- Analysis runs on the site's server; no external AI service or diagnostic upload.
- Browser capture requires `manage_options` and reports require a WordPress nonce.
- Browser messages cannot declare server-side mutation attribution.
- Diagnostic URLs omit query values, fragments and embedded credentials. Error
  text receives best-effort secret/email redaction. Review text before sharing.
- The dashboard deletion action requires the same capability and its own nonce.
- Deactivation cancels queued scans; uninstall removes saved data across multisite.
- Background loopbacks use a randomly generated scan token and worker key, compared
  with `hash_equals`. They return only completion status.

Custom local log paths can be supplied by trusted site code using the
`PluginConflictDebugger/log_paths` filter. Do not accept paths from request input.

The previous development-only `daiosity_conflict_debugger_log_paths` filter was
renamed to follow the plugin namespace. Update any custom integration to the new name.

## Reviewer Notes

This release removes unfinished Pro placeholders. Available features run locally
without a subscription. The plugin uses heuristics and runtime observations; it
does not promise automatic root-cause identification or deactivate other plugins.

Guidelines: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
