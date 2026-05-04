<?php

require_once __DIR__ . '/../Service/AuthService.php';

class AdminController
{
    public function index()
    {
        // ✔ valida sessão
        if (!isset($_SESSION['user'])) {
            header('Location: /login');
            exit;
        }

        // ✔ valida admin (AGORA NO LUGAR CERTO)
        if (!AuthService::isAdmin()) {
            http_response_code(403);
            exit('Acesso negado');
        }

        $db = new SQLite3(__DIR__ . '/../../storage/usage.db');

        $month = $_GET['month'] ?? date('Y-m');

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