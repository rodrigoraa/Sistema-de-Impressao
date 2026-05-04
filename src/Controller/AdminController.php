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

        // mês selecionado (ex: 2026-05)
        $month = $_GET['month'] ?? date('Y-m');

        // consulta: total por usuário no mês
        $stmt = $db->prepare("
            SELECT user, SUM(pages) as total
            FROM usage
            WHERE strftime('%Y-%m', created_at) = :month
            GROUP BY user
            ORDER BY total DESC
        ");

        $stmt->bindValue(':month', $month);
        $result = $stmt->execute();

        $data = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $data[] = $row;
        }

        require __DIR__ . '/../../views/admin.php';
    }
}