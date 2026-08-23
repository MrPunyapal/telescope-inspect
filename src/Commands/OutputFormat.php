<?php

namespace MrPunyapal\TelescopeInspect\Commands;

/**
 * The resolved output format for one command run.
 *
 * @internal
 */
final class OutputFormat
{
    public function __construct(
        public readonly bool $isJson,
        public readonly bool $isNdjson,
        /** Name of the detected AI agent when auto switching applied. */
        public readonly ?string $agentName = null,
    ) {}
}
