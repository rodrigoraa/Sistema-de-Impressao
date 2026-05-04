<?php

class AdminController
{
    public function index()
    {
        session_start();

        if (!isset($_SESSION['user'])) {
            header('Location: /login');
            exit;
        }

        $db = new SQLite3(__DIR__ . '/../../storage/usage.db');

        $result = $db->query("
            SELECT user, SUM(pages) as total 
            FROM usage 
            GROUP BY user
        ");

        echo "<h2>Uso de Impressão</h2>";
        echo "<table border='1'>";
        echo "<tr><th>Usuário</th><th>Páginas</th></tr>";

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            echo "<tr>";
            echo "<td>{$row['user']}</td>";
            echo "<td>{$row['total']}</td>";
            echo "</tr>";
        }

        echo "</table>";
    }
}