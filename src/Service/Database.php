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
        $db->exec('PRAGMA foreign_keys = ON');

        self::ensureSchema($db);

        return $db;
    }

    public static function ensureSchema(SQLite3 $db)
    {
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

        $db->exec("CREATE INDEX IF NOT EXISTS idx_usage_user ON usage(user)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_usage_created_at ON usage(created_at)");
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

