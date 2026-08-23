# Configuration

Publish the config only if you need to change something:

```bash
php artisan vendor:publish --tag=telescope-inspect-config
```

Defaults are tuned for safety and speed; most installs never need this file.

```php
return [

    // Omit sensitive content fields (payloads, bindings, traces...) from output.
    // The --full flag overrides this at runtime.
    'redact_sensitive' => true,

    // Max characters for any string value in normalized output.
    'value_limit' => 1000,

    // How many newest rows content-level filters may scan per query.
    'scan_limit' => 5000,

    // How many newest entries analysis may aggregate per type.
    'analysis_max_rows' => 5000,

    // "Slow" threshold in ms used by --fail-on=slow-requests / slow-queries
    // when --min-duration is not provided.
    'slow_threshold_ms' => 500,
];
```

## Guidance

- **redact_sensitive** — leave `true` everywhere except machines where dumping full payloads is acceptable. Prefer the runtime `--full` flag over a global off-switch.
- **value_limit** — raise it if your team consumes JSON programmatically and wants complete SQL or messages; lower it if consumers are token-constrained (AI agents).
- **scan_limit / analysis_max_rows** — both bound work on large Telescope tables. Lower them on shared production machines; the summaries remain representative because they always cover the *most recent* matching entries.
- **slow_threshold_ms** — set it to what "slow" means for *your* app so CI gates read naturally.
