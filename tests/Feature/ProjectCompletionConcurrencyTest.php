<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectCompletionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! extension_loaded('pgsql')) {
            $this->markTestSkipped('Requires a dedicated PostgreSQL test database and ext-pgsql.');
        }
    }

    #[DataProvider('concurrentWrites')]
    public function test_concurrent_completion_and_reopening_cannot_both_succeed(string $firstWrite, string $isolation): void
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Concurrent project', 'manager_id' => $user->id, 'status' => 'in_progress']);
        $task = $project->tasks()->create([
            'title' => 'Concurrent task', 'responsible_id' => $user->id,
            'created_by' => $user->id, 'status' => 'completed',
        ]);
        $config = DB::connection()->getConfig();
        $parameters = [
            'host' => $config['host'], 'port' => $config['port'], 'dbname' => $config['database'],
            'user' => $config['username'], 'password' => $config['password'],
            'options' => '-c statement_timeout=5000',
        ];
        $connectionString = implode(' ', array_map(
            fn (string $key, mixed $value): string => $key."='".str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value)."'",
            array_keys($parameters), array_values($parameters),
        ));
        $second = pg_connect($connectionString, PGSQL_CONNECT_FORCE_NEW);
        $this->assertNotFalse($second);

        try {
            pg_query($second, 'SET search_path TO '.pg_escape_identifier($second, DB::selectOne('SELECT current_schema() AS name')->name));
            pg_query($second, "BEGIN ISOLATION LEVEL {$isolation}");
            pg_query($second, 'SELECT count(*) FROM projects');
            DB::beginTransaction();

            if ($firstWrite === 'complete') {
                DB::table('projects')->where('id', $project->id)->update(['status' => 'completed']);
                pg_send_query_params($second, "UPDATE tasks SET status = 'todo' WHERE id = $1", [$task->id]);
            } else {
                DB::table('tasks')->where('id', $task->id)->update(['status' => 'todo']);
                pg_send_query_params($second, "UPDATE projects SET status = 'completed' WHERE id = $1", [$project->id]);
            }

            $deadline = microtime(true) + 3;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [pg_get_pid($second)]);
                if ($waiting?->wait_event_type === 'Lock') {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);

            $this->assertSame('Lock', $waiting?->wait_event_type, 'The second writer must wait for the first transaction.');
            DB::commit();
            $result = pg_get_result($second);

            $this->assertSame(PGSQL_FATAL_ERROR, pg_result_status($result));
            $this->assertContains(pg_result_error_field($result, PGSQL_DIAG_SQLSTATE), ['23514', '40001']);
            $this->assertDatabaseHas('projects', [
                'id' => $project->id, 'status' => $firstWrite === 'complete' ? 'completed' : 'in_progress',
            ]);
            $this->assertDatabaseHas('tasks', [
                'id' => $task->id, 'status' => $firstWrite === 'complete' ? 'completed' : 'todo',
            ]);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            while (pg_get_result($second) !== false) {
                // Drain the asynchronous query before rolling back the connection.
            }
            pg_query($second, 'ROLLBACK');
            pg_close($second);
        }
    }

    public static function concurrentWrites(): array
    {
        return [
            'complete first, read committed' => ['complete', 'READ COMMITTED'],
            'reopen first, read committed' => ['reopen', 'READ COMMITTED'],
            'complete first, repeatable read' => ['complete', 'REPEATABLE READ'],
            'reopen first, repeatable read' => ['reopen', 'REPEATABLE READ'],
        ];
    }
}
