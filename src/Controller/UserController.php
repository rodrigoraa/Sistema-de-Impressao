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

        $result = $db->query("SELECT id, name, cpf, role FROM users ORDER BY name");

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

            $name = trim($_POST['name']);
            $cpf = preg_replace('/\D/', '', $_POST['cpf']);
            $password = !empty($_POST['password'])
                ? password_hash($_POST['password'], PASSWORD_DEFAULT)
                : null;

            $role = $_POST['role'];

            $stmt = $db->prepare("
                INSERT INTO users (name, cpf, password, role)
                VALUES (:n, :c, :p, :r)
            ");

            $stmt->bindValue(':n', $name);
            $stmt->bindValue(':c', $cpf);
            $stmt->bindValue(':p', $password);
            $stmt->bindValue(':r', $role);

            $stmt->execute();

            header('Location: /admin/users');
            exit;
        }

        require __DIR__ . '/../../views/user_create.php';
    }
}