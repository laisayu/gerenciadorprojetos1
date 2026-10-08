<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->installSqliteTriggers();

            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Project completion constraints require PostgreSQL or SQLite.');
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_project_completion() RETURNS trigger AS $$
            BEGIN
                IF NEW.status = 'completed' AND EXISTS (
                    SELECT 1 FROM tasks
                    WHERE project_id = NEW.id
                      AND (status NOT IN ('completed', 'cancelled') OR status IS NULL)
                ) THEN
                    RAISE EXCEPTION 'projects_completed_tasks_check: O projeto não pode ser concluído enquanto existirem tarefas pendentes.'
                        USING ERRCODE = '23514', CONSTRAINT = 'projects_completed_tasks_check';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER projects_completed_tasks_check
            BEFORE INSERT OR UPDATE ON projects
            FOR EACH ROW EXECUTE FUNCTION enforce_project_completion();

            CREATE OR REPLACE FUNCTION enforce_task_project_completion() RETURNS trigger AS $$
            DECLARE
                project_status text;
            BEGIN
                IF NEW.status NOT IN ('completed', 'cancelled') OR NEW.status IS NULL THEN
                    -- A write serializes task changes with project completion, including
                    -- transactions using REPEATABLE READ (which must retry on conflict).
                    UPDATE projects SET status = status
                    WHERE id = NEW.project_id
                    RETURNING status INTO project_status;

                    IF project_status = 'completed' THEN
                        RAISE EXCEPTION 'tasks_completed_project_check: Reabra o projeto antes de adicionar ou reabrir tarefas pendentes.'
                            USING ERRCODE = '23514', CONSTRAINT = 'tasks_completed_project_check';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER tasks_completed_project_check
            BEFORE INSERT OR UPDATE ON tasks
            FOR EACH ROW EXECUTE FUNCTION enforce_task_project_completion();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['insert', 'update'] as $event) {
                DB::unprepared("DROP TRIGGER IF EXISTS projects_completed_tasks_{$event}");
                DB::unprepared("DROP TRIGGER IF EXISTS tasks_completed_project_{$event}");
            }

            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS tasks_completed_project_check ON tasks;
            DROP TRIGGER IF EXISTS projects_completed_tasks_check ON projects;
            DROP FUNCTION IF EXISTS enforce_task_project_completion();
            DROP FUNCTION IF EXISTS enforce_project_completion();
            SQL);
    }

    private function installSqliteTriggers(): void
    {
        foreach (['insert', 'update'] as $event) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER projects_completed_tasks_{$event}
                BEFORE {$event} ON projects
                WHEN NEW.status = 'completed' AND EXISTS (
                    SELECT 1 FROM tasks WHERE project_id = NEW.id
                    AND (status NOT IN ('completed', 'cancelled') OR status IS NULL)
                )
                BEGIN
                    SELECT RAISE(ABORT, 'projects_completed_tasks_check');
                END;

                CREATE TRIGGER tasks_completed_project_{$event}
                BEFORE {$event} ON tasks
                WHEN (NEW.status NOT IN ('completed', 'cancelled') OR NEW.status IS NULL)
                AND EXISTS (SELECT 1 FROM projects WHERE id = NEW.project_id AND status = 'completed')
                BEGIN
                    SELECT RAISE(ABORT, 'tasks_completed_project_check');
                END;
                SQL);
        }
    }
};
