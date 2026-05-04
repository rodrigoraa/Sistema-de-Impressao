<?php

class AdminController
{
    public function index()
    {
        if (!isset($_SESSION['user'])) {
            header('Location: /login');
            exit;
        }

        $db = new SQLite3(__DIR__ . '/../../storage/usage.db');

        $result = $db->query("
            SELECT user, SUM(pages) as total 
            FROM usage 
            GROUP BY user
            ORDER BY total DESC
        ");

        $data = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $data[] = $row;
        }

        require __DIR__ . '/../../views/admin.php';
    }
}