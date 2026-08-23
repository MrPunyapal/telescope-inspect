<?php

namespace MrPunyapal\TelescopeInspect\Entries;

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
