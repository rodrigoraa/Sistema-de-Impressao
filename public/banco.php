<?php

$db = new SQLite3(__DIR__ . '/storage/usage.db');

$db->exec("
CREATE TABLE IF NOT EXISTS usage (
    user TEXT,
    pages INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");