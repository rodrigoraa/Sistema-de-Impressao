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

        // mês selecionado
        $month = $_GET['month'] ?? date('Y-m');

        // 🔹 resumo do mês
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

        $totalMonth = array_sum(array_column($data, 'total'));

        $months = $db->query("
            SELECT DISTINCT strftime('%Y-%m', created_at) as month
            FROM usage
            ORDER BY month DESC
        ");

        $history = $db->query("
            SELECT user, pages, file, created_at
            FROM usage
            ORDER BY created_at DESC
            LIMIT 20
        ");

        require __DIR__ . '/../../views/admin.php';
    }
}