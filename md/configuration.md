---
order: 7
---

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

    // Switch to JSON automatically when an AI coding agent is detected
    // (laravel/agent-detector). --human overrides this at runtime.
    'auto_json_for_agents' => true,

    // Max characters for any string value in normalized output.
    'value_limit' => 1000,

    // How many newest rows content-level filters may scan per query. Also
    // bounds per-type analysis aggregation; clamped internally to 100..50000.
    'scan_limit' => 5000,

    // "Slow" threshold in ms used by --fail-on=slow-requests / slow-queries
    // when --min-duration is not provided.
    'slow_threshold_ms' => 500,
];
```

## Guidance

- **redact_sensitive**: leave `true` everywhere except machines where dumping full payloads is acceptable. Prefer the runtime `--full` flag over a global off-switch.
- **auto_json_for_agents**: keep `true` so agents that shell into the app get parseable JSON without special flags. Set it to `false` if you always want tables and rely on explicit `--json`.
- **value_limit**: raise it if your team consumes JSON programmatically and wants complete SQL or messages; lower it if consumers are token-constrained (AI agents).
- **scan_limit**: bounds both content-level filtering *and* per-type analysis aggregation (there is no separate analysis knob). Lower it on shared production machines; summaries remain representative because they always cover the most recent matching entries.
- **slow_threshold_ms**: set it to what "slow" means for *your* app so CI gates read naturally.
