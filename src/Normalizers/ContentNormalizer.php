<?php

namespace MrPunyapal\TelescopeInspect\Normalizers;

use Illuminate\Support\Arr;
use MrPunyapal\TelescopeInspect\Entries\EntryType;

/**
 * Turns raw decoded Telescope entry content into the package's stable
 * normalized field schema.
 *
 * The normalized keys produced here are the package's machine-readable
 * contract and must not change in breaking ways across patch releases.
 *
 * Sensitive or bulky values (request payloads, stack traces, bindings...)
 * are removed unless explicitly requested; remaining long strings are
 * truncated to a configurable limit.
 */
final class ContentNormalizer
{
    /**
     * Field names that are considered sensitive across all entry types.
     *
     * @var list<string>
     */
    public const SENSITIVE_FIELDS = [
        'arguments',
        'bindings',
        'changes',
        'context',
        'data',
        'headers',
        'html',
        'line_preview',
        'options',
        'payload',
        'raw',
        'response',
        'response_headers',
        'session',
        'trace',
        'value',
    ];

    /**
     * @param  int  $valueLimit  maximum length for any string value
     */
    public function __construct(
        private readonly int $valueLimit = 1000,
    ) {}

    /**
     * Normalize decoded Telescope content into stable fields.
     *
     * Sensitive fields are nulled unless explicitly requested (listings with
     * --full, and single-entry --show lookups).
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function normalize(EntryType $type, array $content, bool $withSensitiveValues = false): array
    {
        $this->withSensitiveValues = $withSensitiveValues;

        try {
            return match ($type) {
                EntryType::Request => $this->request($content),
                EntryType::HttpClientRequest => $this->httpClientRequest($content),
                EntryType::Query => $this->query($content),
                EntryType::Exception => $this->exception($content),
                EntryType::Job => $this->job($content),
                EntryType::Command => $this->command($content),
                EntryType::Schedule => $this->schedule($content),
                EntryType::Cache => $this->cache($content),
                EntryType::Dump => $this->dump($content),
                EntryType::Event => $this->event($content),
                EntryType::Gate => $this->gate($content),
                EntryType::Log => $this->log($content),
                EntryType::Mail => $this->mail($content),
                EntryType::Model => $this->model($content),
                EntryType::Notification => $this->notification($content),
                EntryType::Redis => $this->redis($content),
                EntryType::View => $this->view($content),
                EntryType::Batch => $this->batch($content),
            };
        } finally {
            $this->withSensitiveValues = false;
        }
    }

    private bool $withSensitiveValues = false;

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function request(array $content): array
    {
        return [
            'method' => $this->text(Arr::get($content, 'method')),
            'uri' => $this->text(Arr::get($content, 'uri')),
            'controller_action' => $this->nullableText(Arr::get($content, 'controller_action')),
            'middleware' => $this->list(Arr::get($content, 'middleware')),
            'response_status' => $this->intOrNull(Arr::get($content, 'response_status')),
            'duration_ms' => $this->intOrNull(Arr::get($content, 'duration')),
            'memory_mb' => $this->floatOrNull(Arr::get($content, 'memory')),
            'ip_address' => $this->nullableText(Arr::get($content, 'ip_address')),
            'headers' => $this->sensitive(Arr::get($content, 'headers')),
            'payload' => $this->sensitive(Arr::get($content, 'payload')),
            'session' => $this->sensitive(Arr::get($content, 'session')),
            'response' => $this->sensitive(Arr::get($content, 'response')),
            'response_headers' => $this->sensitive(Arr::get($content, 'response_headers')),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function httpClientRequest(array $content): array
    {
        return [
            'method' => $this->text(Arr::get($content, 'method')),
            'uri' => $this->text(Arr::get($content, 'uri')),
            'response_status' => $this->intOrNull(Arr::get($content, 'response_status')),
            'duration_ms' => $this->floatOrNull(Arr::get($content, 'duration')),
            'headers' => $this->sensitive(Arr::get($content, 'headers')),
            'payload' => $this->sensitive(Arr::get($content, 'payload')),
            'response' => $this->sensitive(Arr::get($content, 'response')),
            'response_headers' => $this->sensitive(Arr::get($content, 'response_headers')),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function query(array $content): array
    {
        return [
            'sql' => $this->text(Arr::get($content, 'sql')),
            'connection' => $this->nullableText(Arr::get($content, 'connection')),
            'driver' => $this->nullableText(Arr::get($content, 'driver')),
            'duration_ms' => $this->floatOrNull(Arr::get($content, 'time')),
            'slow' => (bool) Arr::get($content, 'slow', false),
            'file' => $this->nullableText(Arr::get($content, 'file')),
            'line' => $this->intOrNull(Arr::get($content, 'line')),
            'query_hash' => $this->nullableText(Arr::get($content, 'hash')),
            'bindings' => $this->sensitive(Arr::get($content, 'bindings')),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function exception(array $content): array
    {
        return [
            'class' => $this->text(Arr::get($content, 'class')),
            'message' => $this->text(Arr::get($content, 'message')),
            'file' => $this->nullableText(Arr::get($content, 'file')),
            'line' => $this->intOrNull(Arr::get($content, 'line')),
            'context' => $this->sensitive(Arr::get($content, 'context')),
            'trace' => $this->sensitive(Arr::get($content, 'trace')),
            'line_preview' => $this->sensitive(Arr::get($content, 'line_preview')),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function job(array $content): array
    {
        return [
            'name' => $this->text(Arr::get($content, 'name')),
            'queue' => $this->nullableText(Arr::get($content, 'queue')),
            'connection' => $this->nullableText(Arr::get($content, 'connection')),
            'status' => $this->nullableText(Arr::get($content, 'status')) ?? 'pending',
            'tries' => $this->intOrNull(Arr::get($content, 'tries')),
            'timeout' => $this->intOrNull(Arr::get($content, 'timeout')),
            'exception_message' => $this->nullableText(
                Arr::get($content, 'exception.message')
                ?? (is_string(Arr::get($content, 'exception')) ? Arr::get($content, 'exception') : null)
            ),
            'data' => $this->sensitive(Arr::get($content, 'data')),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function command(array $content): array
    {
        return [
            'command' => $this->text(Arr::get($content, 'command')),
            'exit_code' => $this->intOrNull(Arr::get($content, 'exit_code')),
            'arguments' => $this->sensitive(Arr::get($content, 'arguments')),
            'options' => $this->sensitive(Arr::get($content, 'options')),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function schedule(array $content): array
    {
        return [
            'command' => $this->text(Arr::get($content, 'command')),
            'description' => $this->nullableText(Arr::get($content, 'description')),
            'expression' => $this->nullableText(Arr::get($content, 'expression')),
            'timezone' => $this->nullableText(Arr::get($content, 'timezone')),
            'user' => $this->nullableText(Arr::get($content, 'user')),
            'output' => $this->nullableText(Arr::get($content, 'output')),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function cache(array $content): array
    {
        return [
            'operation' => $this->text(Arr::get($content, 'type')),
            'key' => $this->text(Arr::get($content, 'key')),
            'expiration' => $this->nullableText(Arr::get($content, 'expiration')),
            'value' => $this->sensitive(Arr::get($content, 'value')),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function dump(array $content): array
    {
        return [
            'dump' => $this->text(strip_tags((string) Arr::get($content, 'dump', ''))),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function event(array $content): array
    {
        return [
            'name' => $this->text(Arr::get($content, 'name')),
            'listeners' => $this->listenerNames($this->list(Arr::get($content, 'listeners'))),
            'broadcast' => (bool) Arr::get($content, 'broadcast', false),
            'payload' => $this->sensitive(Arr::get($content, 'payload')),
        ];
    }

    /**
     * Extract listener names from Telescope's listener formatting.
     *
     * @param  list<mixed>  $listeners
     * @return list<string>
     */
    private function listenerNames(array $listeners): array
    {
        return collect($listeners)
            ->map(fn ($listener): ?string => is_array($listener)
                ? ($listener['name'] ?? null)
                : (is_string($listener) ? $listener : null))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function gate(array $content): array
    {
        return [
            'ability' => $this->text(Arr::get($content, 'ability')),
            'result' => $this->nullableText(Arr::get($content, 'result')),
            'message' => $this->nullableText(Arr::get($content, 'message')),
            'file' => $this->nullableText(Arr::get($content, 'file')),
            'line' => $this->intOrNull(Arr::get($content, 'line')),
            'arguments' => $this->sensitive(Arr::get($content, 'arguments')),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function log(array $content): array
    {
        return [
            'level' => $this->text(Arr::get($content, 'level')),
            'message' => $this->text(Arr::get($content, 'message')),
            'context' => $this->sensitive(Arr::get($content, 'context')),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function mail(array $content): array
    {
        return [
            'mailable' => $this->nullableText(Arr::get($content, 'mailable')),
            'subject' => $this->nullableText(Arr::get($content, 'subject')),
            'queued' => (bool) Arr::get($content, 'queued', false),
            'from' => $this->addresses(Arr::get($content, 'from')),
            'to' => $this->addresses(Arr::get($content, 'to')),
            'cc' => $this->addresses(Arr::get($content, 'cc')),
            'bcc' => $this->addresses(Arr::get($content, 'bcc')),
            'reply_to' => $this->addresses(Arr::get($content, 'replyTo')),
            'html' => $this->sensitive(Arr::get($content, 'html')),
            'raw' => $this->sensitive(Arr::get($content, 'raw')),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function model(array $content): array
    {
        return [
            'action' => $this->text(Arr::get($content, 'action')),
            'model' => $this->text(Arr::get($content, 'model')),
            'count' => $this->intOrNull(Arr::get($content, 'count')),
            'changes' => $this->sensitive(Arr::get($content, 'changes')),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function notification(array $content): array
    {
        return [
            'notification' => $this->text(Arr::get($content, 'notification')),
            'channel' => $this->nullableText(Arr::get($content, 'channel')),
            'queued' => (bool) Arr::get($content, 'queued', false),
            'notifiable' => $this->nullableText(Arr::get($content, 'notifiable')),
            'response' => $this->sensitive(Arr::get($content, 'response')),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function redis(array $content): array
    {
        return [
            'command' => $this->text(Arr::get($content, 'command')),
            'connection' => $this->nullableText(Arr::get($content, 'connection')),
            'duration_ms' => $this->floatOrNull(Arr::get($content, 'time')),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function view(array $content): array
    {
        return [
            'name' => $this->text(Arr::get($content, 'name')),
            'path' => $this->nullableText(Arr::get($content, 'path')),
            'shared_keys' => $this->list(Arr::get($content, 'data')),
            'composers' => $this->composerNames(Arr::get($content, 'composers')),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function batch(array $content): array
    {
        return [
            'name' => $this->nullableText(Arr::get($content, 'name')),
            'total_jobs' => $this->intOrNull(Arr::get($content, 'totalJobs')),
            'pending_jobs' => $this->intOrNull(Arr::get($content, 'pendingJobs')),
            'failed_jobs' => $this->intOrNull(Arr::get($content, 'failedJobs')),
            'processed_jobs' => $this->intOrNull(Arr::get($content, 'processedJobs')),
            'progress' => $this->floatOrNull(Arr::get($content, 'progress')),
            'queue' => $this->nullableText(Arr::get($content, 'queue')),
            'connection' => $this->nullableText(Arr::get($content, 'connection')),
            'allows_failures' => (bool) Arr::get($content, 'allowsFailures', false),
            'cancelled_at' => $this->nullableText(Arr::get($content, 'cancelledAt')),
            'finished_at' => $this->nullableText(Arr::get($content, 'finishedAt')),
            'options' => $this->sensitive(Arr::get($content, 'options')),
        ];
    }

    /**
     * Flatten mail address maps ("email" => ["Name"]) to a name/email list.
     *
     * @return list<string>
     */
    private function addresses(mixed $addresses): array
    {
        if (! is_array($addresses)) {
            return [];
        }

        return collect($addresses)
            ->map(fn ($names, $email): string => filled($names) && ! is_numeric($email)
                ? trim(($names === true ? '' : implode(', ', (array) $names))." <{$email}>", ' ')
                : (string) $email)
            ->map(fn (string $address): string => $this->truncate($address))
            ->values()
            ->all();
    }

    /**
     * Extract composer names from Telescope's composer formatting.
     *
     * @return list<string>
     */
    private function composerNames(mixed $composers): array
    {
        if (! is_array($composers)) {
            return [];
        }

        return collect($composers)
            ->map(fn ($composer): ?string => is_array($composer) ? ($composer['name'] ?? null) : null)
            ->filter()
            ->map(fn (string $composer): string => $this->truncate($composer))
            ->values()
            ->all();
    }

    /**
     * A required textual value.
     */
    private function text(mixed $value): string
    {
        $string = (is_scalar($value) || $value === null)
            ? (string) $value
            : ((string) json_encode($value));

        return $this->truncate($string);
    }

    /**
     * An optional textual value; empty strings normalize to null.
     */
    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = is_scalar($value) ? (string) $value : (string) json_encode($value);

        return $string === '' ? null : $this->truncate($string);
    }

    /**
     * A list of scalar values rendered as strings.
     *
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn ($item): string => $this->truncate(
                is_scalar($item) ? (string) $item : (string) json_encode($item)
            ))
            ->values()
            ->all();
    }

    /**
     * A potentially bulky or sensitive value.
     *
     * Nulled entirely unless the caller opted into sensitive values; when
     * included, oversized structures degrade to a truncated JSON string
     * instead of being silently lost to an invalid re-decode.
     */
    private function sensitive(mixed $value): mixed
    {
        if (! $this->withSensitiveValues) {
            return null;
        }

        if ($value === null || is_scalar($value)) {
            return $this->truncate((string) $value);
        }

        $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded !== false && mb_strlen($encoded) <= $this->valueLimit) {
            return json_decode($encoded, true);
        }

        return ['_truncated' => true, 'preview' => $this->truncate($encoded === false ? '' : $encoded)];
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    /**
     * Truncate long strings so output stays predictable and bounded.
     */
    private function truncate(string $value): string
    {
        return mb_strlen($value) <= $this->valueLimit
            ? $value
            : mb_substr($value, 0, $this->valueLimit).'…';
    }
}
