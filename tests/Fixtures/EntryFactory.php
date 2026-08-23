<?php

namespace MrPunyapal\TelescopeInspect\Tests\Fixtures;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds realistic Telescope storage rows for tests.
 *
 * Field names mirror what Laravel Telescope 5.x watchers actually record,
 * verified against vendor/laravel/telescope/src/Watchers.
 */
class EntryFactory
{
    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    /**
     * Record an HTTP request entry. Returns the request uuid; the batch id
     * shared by the request and its child entries is available via
     * lastBatchId() — mirroring Telescope, where one flush batch id is
     * assigned to every entry recorded during a request.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function request(array $overrides = [], ?CarbonInterface $createdAt = null): string
    {
        $uuid = entryUuid();
        $this->lastBatch = (string) Str::uuid();

        $content = array_merge([
            'ip_address' => '127.0.0.1',
            'uri' => 'http://localhost/api/orders',
            'method' => 'GET',
            'controller_action' => 'App\Http\Controllers\OrderController@index',
            'middleware' => ['api'],
            'headers' => ['user-agent' => ['testing/1.0']],
            'payload' => [],
            'session' => [],
            'response_headers' => [],
            'response_status' => 200,
            'response' => '{"ok":true}',
            'duration' => 250,
            'memory' => 12.5,
        ], $overrides);

        $this->add('request', $uuid, $this->lastBatch, $content, $createdAt);

        return $uuid;
    }

    /**
     * The batch id of the most recently created request entry.
     */
    public function lastBatchId(): string
    {
        return $this->lastBatch;
    }

    private ?string $lastBatch = null;

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function query(string $sql, string $batchId, array $overrides = [], ?CarbonInterface $createdAt = null): void
    {
        $content = array_merge([
            'connection' => 'sqlite',
            'driver' => 'sqlite',
            'bindings' => [],
            'sql' => $sql,
            'time' => 10,
            'slow' => false,
            'file' => '/app/routes/web.php',
            'line' => 20,
            'hash' => md5($sql),
        ], $overrides);

        $this->add('query', entryUuid(), $batchId, $content, $createdAt);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function exception(string $class, string $message, array $overrides = [], ?CarbonInterface $createdAt = null): string
    {
        $uuid = entryUuid();

        $content = array_merge([
            'class' => $class,
            'file' => '/app/app/Http/Middleware/Boom.php',
            'line' => 17,
            'message' => $message,
            'context' => [],
            'trace' => [['file' => '/app/app/Http/Middleware/Boom.php', 'line' => 17]],
            'line_preview' => ['17' => 'throw new RuntimeException("boom");'],
        ], $overrides);

        $this->add('exception', $uuid, $uuid, $content, $createdAt, ["Exception: {$class}"]);

        return $uuid;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function job(string $name, string $status, array $overrides = [], ?CarbonInterface $createdAt = null): string
    {
        $uuid = entryUuid();

        $content = array_merge([
            'connection' => 'redis',
            'queue' => 'default',
            'name' => $name,
            'tries' => 1,
            'timeout' => 30,
            'data' => ['command' => $name],
            'status' => $status,
        ], $overrides);

        if ($status === 'failed' && ! isset($content['exception'])) {
            $content['exception'] = [
                'message' => 'The job failed after 1 attempts.',
                'trace' => [],
                'line' => 42,
                'line_preview' => [],
            ];
        }

        $this->add('job', $uuid, $uuid, $content, $createdAt, ["{$name}: {$status}"]);

        return $uuid;
    }

    /**
     * A generic entry with full control over content.
     *
     * @param  array<string, mixed>  $content
     * @param  list<string>  $tags
     */
    public function add(
        string $type,
        string $uuid,
        ?string $batchId = null,
        array $content = [],
        ?CarbonInterface $createdAt = null,
        array $tags = [],
    ): string {
        $this->rows[] = [
            'uuid' => $uuid,
            'batch_id' => $batchId ?? $uuid,
            'family_hash' => null,
            'should_display_on_index' => true,
            'type' => $type,
            'content' => json_encode($content) ?: '{}',
            'created_at' => ($createdAt ?? now())->format('Y-m-d H:i:s'),
            '_tags' => $tags,
        ];

        return $uuid;
    }

    /**
     * Persist all pending rows to Telescope's storage.
     */
    public function persist(): void
    {
        foreach (array_splice($this->rows, 0) as $row) {
            $tags = $row['_tags'];
            unset($row['_tags']);

            DB::table('telescope_entries')->insert($row);

            foreach ((array) $tags as $tag) {
                DB::table('telescope_entries_tags')->insert([
                    'entry_uuid' => $row['uuid'],
                    'tag' => $tag,
                ]);
            }
        }
    }
}
