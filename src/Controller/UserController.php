<?php

class UserController
{
    private function db()
    {
        return new SQLite3(__DIR__ . '/../../storage/usage.db');
    }

    public function index()
    {
        if ($_SESSION['role'] !== 'admin') {
            exit('Acesso negado');
        }

        $db = $this->db();

        $result = $db->query("SELECT id, username, role FROM users ORDER BY username");

        $users = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $users[] = $row;
        }

        require __DIR__ . '/../../views/users.php';
    }

    public function create()
    {
        if ($_SESSION['role'] !== 'admin') {
            exit('Acesso negado');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            $db = $this->db();

            $username = trim($_POST['username']);
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $role = $_POST['role'];

            $stmt = $db->prepare("
                INSERT INTO users (username, password, role)
                VALUES (:u, :p, :r)
            ");

            $stmt->bindValue(':u', $username);
            $stmt->bindValue(':p', $password);
            $stmt->bindValue(':r', $role);

            $stmt->execute();

            header('Location: /admin/users');
            exit;
        }

        require __DIR__ . '/../../views/user_create.php';
    }
}