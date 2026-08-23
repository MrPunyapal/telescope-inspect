<?php

namespace MrPunyapal\TelescopeInspect\Entries;

use Illuminate\Support\Str;
use MrPunyapal\TelescopeInspect\Output\Duration;

/**
 * The Telescope entry types this package can inspect.
 *
 * Each case mirrors a constant on Laravel\Telescope\EntryType.
 */
enum EntryType: string
{
    case Batch = 'batch';
    case Cache = 'cache';
    case Command = 'command';
    case Dump = 'dump';
    case Event = 'event';
    case Exception = 'exception';
    case Gate = 'gate';
    case HttpClientRequest = 'client_request';
    case Job = 'job';
    case Log = 'log';
    case Mail = 'mail';
    case Model = 'model';
    case Notification = 'notification';
    case Query = 'query';
    case Redis = 'redis';
    case Request = 'request';
    case Schedule = 'schedule';
    case View = 'view';

    /**
     * All inspectable entry types in a stable, documented order.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            self::Request,
            self::Query,
            self::Exception,
            self::Job,
            self::Command,
            self::Schedule,
            self::Cache,
            self::Dump,
            self::Event,
            self::Gate,
            self::HttpClientRequest,
            self::Log,
            self::Mail,
            self::Model,
            self::Notification,
            self::Redis,
            self::View,
            self::Batch,
        ];
    }

    /**
     * Human-facing plural label used in output headings.
     */
    public function label(): string
    {
        return match ($this) {
            self::Batch => 'Batches',
            self::Cache => 'Cache',
            self::Command => 'Commands',
            self::Dump => 'Dumps',
            self::Event => 'Events',
            self::Exception => 'Exceptions',
            self::Gate => 'Gate Checks',
            self::HttpClientRequest => 'HTTP Client',
            self::Job => 'Jobs',
            self::Log => 'Logs',
            self::Mail => 'Mail',
            self::Model => 'Models',
            self::Notification => 'Notifications',
            self::Query => 'Queries',
            self::Redis => 'Redis',
            self::Request => 'Requests',
            self::Schedule => 'Scheduled Tasks',
            self::View => 'Views',
        };
    }

    /**
     * The Artisan flag that selects this type, e.g. "requests" for --requests.
     */
    public function flagName(): string
    {
        return match ($this) {
            self::Batch => 'batches',
            self::Cache => 'cache',
            self::Command => 'commands',
            self::Dump => 'dumps',
            self::Event => 'events',
            self::Exception => 'exceptions',
            self::Gate => 'gates',
            self::HttpClientRequest => 'http',
            self::Job => 'jobs',
            self::Log => 'logs',
            self::Mail => 'mail',
            self::Model => 'models',
            self::Notification => 'notifications',
            self::Query => 'queries',
            self::Redis => 'redis',
            self::Request => 'requests',
            self::Schedule => 'schedule',
            self::View => 'views',
        };
    }

    /**
     * Whether --min-duration can meaningfully filter this type.
     */
    public function supportsDurationFilter(): bool
    {
        return in_array($this, [self::Request, self::Query, self::Redis, self::HttpClientRequest], true);
    }

    /**
     * Whether --method / --status / --route filters apply to this type.
     */
    public function supportsHttpFilters(): bool
    {
        return in_array($this, [self::Request, self::HttpClientRequest], true);
    }

    /**
     * Whether the --connection filter applies to this type.
     */
    public function supportsConnectionFilter(): bool
    {
        return in_array($this, [self::Query, self::Job, self::Redis, self::Batch], true);
    }

    /**
     * One-line summary of a normalized entry, used by batch and watch output.
     *
     * @param  array<string, mixed>  $fields
     */
    public function headline(array $fields): string
    {
        return trim(match ($this) {
            self::Request, self::HttpClientRequest => sprintf(
                '%s %s %s%s',
                (string) ($fields['method'] ?? ''),
                Str::limit((string) ($fields['uri'] ?? ''), 70),
                isset($fields['response_status']) ? (string) $fields['response_status'] : '',
                isset($fields['duration_ms']) ? ' '.Duration::milliseconds($fields['duration_ms']) : ''
            ),
            self::Query => sprintf(
                '%s [%s %s]',
                Str::limit((string) ($fields['sql'] ?? ''), 80),
                (string) ($fields['connection'] ?? '?'),
                Duration::milliseconds($fields['duration_ms'] ?? null)
            ),
            self::Exception => sprintf(
                '%s: %s',
                (string) ($fields['class'] ?? ''),
                Str::limit((string) ($fields['message'] ?? ''), 90)
            ),
            self::Job => sprintf(
                '%s [%s] queue=%s',
                (string) ($fields['name'] ?? ''),
                (string) ($fields['status'] ?? ''),
                (string) ($fields['queue'] ?? 'default')
            ),
            self::Command => sprintf(
                '$ %s (exit %s)',
                (string) ($fields['command'] ?? ''),
                (string) ($fields['exit_code'] ?? '?')
            ),
            self::Cache => sprintf(
                '%s %s',
                (string) ($fields['operation'] ?? ''),
                (string) ($fields['key'] ?? '')
            ),
            self::Log => sprintf(
                '[%s] %s',
                (string) ($fields['level'] ?? ''),
                Str::limit((string) ($fields['message'] ?? ''), 90)
            ),
            self::Redis => sprintf(
                '%s [%s]',
                Str::limit((string) ($fields['command'] ?? ''), 80),
                Duration::milliseconds($fields['duration_ms'] ?? null)
            ),
            self::Model => sprintf(
                '%s %s',
                (string) ($fields['action'] ?? ''),
                (string) ($fields['model'] ?? '')
            ),
            self::Event => (string) ($fields['name'] ?? ''),
            self::Gate => sprintf(
                '%s -> %s',
                (string) ($fields['ability'] ?? ''),
                (string) ($fields['result'] ?? '')
            ),
            self::Mail => (string) ($fields['subject'] ?? '(no subject)'),
            self::Notification => sprintf(
                '%s via %s',
                (string) ($fields['notification'] ?? ''),
                (string) ($fields['channel'] ?? '?')
            ),
            self::Schedule => sprintf(
                '%s [%s]',
                (string) ($fields['command'] ?? ''),
                (string) ($fields['expression'] ?? '')
            ),
            // Dump content is gated behind --full; keep the line useful.
            self::Dump => ($fields['dump'] ?? null) === null || $fields['dump'] === ''
                ? '(dump content redacted)'
                : Str::limit((string) $fields['dump'], 100),
            self::View => (string) ($fields['name'] ?? ''),
            self::Batch => sprintf('%s (%s jobs)', (string) ($fields['name'] ?? ''), (string) ($fields['total_jobs'] ?? '?')),
        });
    }

    /**
     * Default columns used when listing entries of this type in a table.
     *
     * @return list<string> normalized field keys
     */
    public function listColumns(): array
    {
        return match ($this) {
            self::Batch => ['name', 'total_jobs', 'pending_jobs', 'failed_jobs'],
            self::Cache => ['operation', 'key'],
            self::Command => ['command', 'exit_code'],
            self::Dump => ['dump'],
            self::Event => ['name', 'listeners'],
            self::Exception => ['class', 'message', 'file', 'line'],
            self::Gate => ['ability', 'result'],
            self::HttpClientRequest => ['method', 'uri', 'response_status', 'duration_ms'],
            self::Job => ['name', 'queue', 'status'],
            self::Log => ['level', 'message'],
            self::Mail => ['subject', 'mailable'],
            self::Model => ['action', 'model'],
            self::Notification => ['notification', 'channel'],
            self::Query => ['sql', 'connection', 'duration_ms'],
            self::Redis => ['command', 'duration_ms'],
            self::Request => ['method', 'uri', 'response_status', 'duration_ms'],
            self::Schedule => ['command', 'expression'],
            self::View => ['name'],
        };
    }
}
