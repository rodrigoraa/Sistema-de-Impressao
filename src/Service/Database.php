<?php

class Database
{
    public static function connect()
    {
        if (!class_exists('SQLite3')) {
            throw new RuntimeException(
                'Extensão SQLite3 não está habilitada no PHP (instale/ative php-sqlite3).'
            );
        }

        $dbPath = __DIR__ . '/../../storage/usage.db';
        $dbDir = dirname($dbPath);

        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0775, true);
        }

        $db = new SQLite3($dbPath);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA foreign_keys = ON');

        self::ensureSchema($db);

        return $db;
    }

    public static function ensureSchema(SQLite3 $db)
    {
        $timezone = $_ENV['APP_TIMEZONE'] ?? 'America/Cuiaba';
        if (is_string($timezone) && $timezone !== '') {
            @date_default_timezone_set($timezone);
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                cpf TEXT NOT NULL UNIQUE,
                password TEXT NULL,
                role TEXT NOT NULL DEFAULT 'user'
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS usage (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user TEXT NOT NULL,
                pages INTEGER NOT NULL,
                file TEXT NULL,
                created_at TEXT NOT NULL
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS print_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user TEXT NOT NULL,
                nome_professor TEXT NULL,
                original_name TEXT NOT NULL,
                stored_file TEXT NULL,
                prepared_file TEXT NULL,
                source_ext TEXT NULL,
                mime_type TEXT NULL,
                file_size INTEGER NOT NULL DEFAULT 0,
                pages INTEGER NOT NULL DEFAULT 0,
                confirmed_pages INTEGER NOT NULL DEFAULT 0,
                copies INTEGER NOT NULL DEFAULT 1,
                number_up INTEGER NOT NULL DEFAULT 1,
                charged_pages INTEGER NOT NULL DEFAULT 0,
                sides TEXT NULL,
                orientation TEXT NULL,
                paper TEXT NULL,
                status TEXT NOT NULL DEFAULT 'queued',
                cups_job_id TEXT NULL,
                status_cups TEXT NULL,
                error_message TEXT NULL,
                cups_stdout TEXT NULL,
                cups_stderr TEXT NULL,
                return_code INTEGER NULL,
                printer TEXT NULL,
                printer_enabled INTEGER NULL,
                printer_accepting INTEGER NULL,
                printer_state TEXT NULL,
                printer_state_message TEXT NULL,
                error_category TEXT NULL,
                month_ref TEXT NULL,
                entered_accumulator INTEGER NOT NULL DEFAULT 0,
                observations TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                completed_at TEXT NULL
            )
        ");

        $columns = [
            'nome_professor' => 'TEXT NULL',
            'mime_type' => 'TEXT NULL',
            'file_size' => 'INTEGER NOT NULL DEFAULT 0',
            'confirmed_pages' => 'INTEGER NOT NULL DEFAULT 0',
            'cups_job_id' => 'TEXT NULL',
            'status_cups' => 'TEXT NULL',
            'cups_stdout' => 'TEXT NULL',
            'cups_stderr' => 'TEXT NULL',
            'return_code' => 'INTEGER NULL',
            'printer' => 'TEXT NULL',
            'printer_enabled' => 'INTEGER NULL',
            'printer_accepting' => 'INTEGER NULL',
            'printer_state' => 'TEXT NULL',
            'printer_state_message' => 'TEXT NULL',
            'error_category' => 'TEXT NULL',
            'month_ref' => 'TEXT NULL',
            'entered_accumulator' => 'INTEGER NOT NULL DEFAULT 0',
            'observations' => 'TEXT NULL',
        ];

        foreach ($columns as $name => $definition) {
            self::addColumnIfMissing($db, 'print_jobs', $name, $definition);
        }

        $db->exec("CREATE INDEX IF NOT EXISTS idx_usage_user ON usage(user)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_usage_created_at ON usage(created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_print_jobs_user ON print_jobs(user)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_print_jobs_status ON print_jobs(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_print_jobs_created_at ON print_jobs(created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_print_jobs_month_ref ON print_jobs(month_ref)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_print_jobs_cups_job_id ON print_jobs(cups_job_id)");
    }

    private static function addColumnIfMissing(SQLite3 $db, $table, $column, $definition)
    {
        $result = $db->query("PRAGMA table_info(" . $table . ")");
        if (!$result) {
            throw new RuntimeException('Falha ao verificar schema da tabela ' . $table . ': ' . $db->lastErrorMsg());
        }
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (($row['name'] ?? '') === $column) {
                return;
            }
        }

        $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    public static function hasAnyUsers(SQLite3 $db)
    {
        return (int) $db->querySingle("SELECT COUNT(*) FROM users") > 0;
    }

    public static function hasAnyAdmin(SQLite3 $db)
    {
        return (int) $db->querySingle("SELECT COUNT(*) FROM users WHERE role = 'admin'") > 0;
    }
}

