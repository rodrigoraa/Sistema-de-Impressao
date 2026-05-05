<?php

require_once __DIR__ . '/../Service/AuthService.php';
require_once __DIR__ . '/../Service/Database.php';

class AdminController
{
    public function index()
    {
        // ✔ valida sessão
        if (!isset($_SESSION['user'])) {
            header('Location: /login');
            exit;
        }

        // ✔ valida admin
        if (!AuthService::isAdmin()) {
            http_response_code(403);
            exit('Acesso negado');
        }

        $db = Database::connect();

        $month = $_GET['month'] ?? date('Y-m');

        $stmt = $db->prepare("
                SELECT 
                    u.name,
                    u.cpf,
                    SUM(us.pages) as total
                FROM usage us
                JOIN users u ON u.cpf = us.user
                WHERE strftime('%Y-%m', us.created_at) = :month
                GROUP BY us.user
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

