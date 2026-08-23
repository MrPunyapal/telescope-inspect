<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sensitive value redaction
    |--------------------------------------------------------------------------
    |
    | Telescope already masks configured hidden parameters and headers before
    | storing entries. On top of that, Telescope Inspect omits bulky or
    | potentially sensitive content fields (request payloads, stack traces,
    | query bindings, cache values...) from its output by default.
    |
    | When disabled, every stored field is emitted (still truncated by the
    | value limit below). The --full flag overrides this at runtime.
    |
    */

    'redact_sensitive' => true,

    /*
    |--------------------------------------------------------------------------
    | Value limit
    |--------------------------------------------------------------------------
    |
    | Maximum length of any string value in normalized output. Long values
    | are truncated with an ellipsis so output stays bounded and predictable.
    |
    */

    'value_limit' => 1000,

    /*
    |--------------------------------------------------------------------------
    | Scan limit
    |--------------------------------------------------------------------------
    |
    | Maximum number of most-recent rows considered, both for content-level
    | filtering (route, status, duration...) and for per-type analysis
    | summaries. This is the effective memory dial together with the value
    | limit above; it is clamped to 100..50000.
    |
    */

    'scan_limit' => 5000,

    /*
    |--------------------------------------------------------------------------
    | Slow threshold
    |--------------------------------------------------------------------------
    |
    | Milliseconds considered "slow" by --fail-on=slow-requests and
    | --fail-on=slow-queries when --min-duration is not provided.
    |
    */

    'slow_threshold_ms' => 500,

];
