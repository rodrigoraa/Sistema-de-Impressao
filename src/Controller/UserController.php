<?php

require_once __DIR__ . '/../Service/AuthService.php';
require_once __DIR__ . '/../Service/Database.php';

class UserController
{
    private function db()
    {
        return Database::connect();
    }

    public function index()
    {
        if (!AuthService::isAdmin()) {
            http_response_code(403);
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
        if (!AuthService::isAdmin()) {
            http_response_code(403);
            exit('Acesso negado');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            $db = $this->db();

            $name = trim($_POST['name'] ?? '');
            $cpf = preg_replace('/\\D/', '', $_POST['cpf'] ?? '');
            $role = $_POST['role'] ?? 'user';

            if (!$name || !$cpf) {
                exit('Nome/CPF inválidos');
            }

            if (!in_array($role, ['user', 'admin'])) {
                exit('Role inválida');
            }

            $password = null;
            if ($role === 'admin') {
                $plainPassword = (string) ($_POST['password'] ?? '');
                if ($plainPassword === '') {
                    exit('Senha é obrigatória para admin');
                }
                $password = password_hash($plainPassword, PASSWORD_DEFAULT);
            }

            $stmt = $db->prepare("
                INSERT INTO users (name, cpf, password, role)
                VALUES (:n, :c, :p, :r)
            ");

            $stmt->bindValue(':n', $name);
            $stmt->bindValue(':c', $cpf);
            $stmt->bindValue(':p', $password);
            $stmt->bindValue(':r', $role);

            $res = $stmt->execute();
            if (!$res) {
                exit('Erro ao salvar usuário: ' . htmlspecialchars($db->lastErrorMsg()));
            }

            header('Location: /admin/users');
            exit;
        }

        require __DIR__ . '/../../views/user_create.php';
    }

    public function edit()
    {
        if (!AuthService::isAdmin()) {
            http_response_code(403);
            exit('Acesso negado');
        }

        $id = $_GET['id'] ?? null;

        $db = $this->db();

        $stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->bindValue(':id', $id);

        $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$user) {
            exit('Usuário não encontrado');
        }

        require __DIR__ . '/../../views/user_edit.php';
    }

    public function update()
    {
        if (!AuthService::isAdmin()) {
            http_response_code(403);
            exit('Acesso negado');
        }

        $db = $this->db();

        $id = $_POST['id'];
        $name = trim($_POST['name']);
        $cpf = preg_replace('/\D/', '', $_POST['cpf']);
        $role = $_POST['role'];

        if (!$name || !$cpf) {
            exit('Dados inválidos');
        }

        if (!in_array($role, ['user', 'admin'])) {
            exit('Role inválida');
        }

        // senha opcional
        if (!empty($_POST['password'])) {

            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

            $stmt = $db->prepare("
            UPDATE users 
            SET name = :n, cpf = :c, password = :p, role = :r
            WHERE id = :id
        ");

            $stmt->bindValue(':p', $password);

        } else {

            $stmt = $db->prepare("
            UPDATE users 
            SET name = :n, cpf = :c, role = :r
            WHERE id = :id
        ");
        }

        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':n', $name);
        $stmt->bindValue(':c', $cpf);
        $stmt->bindValue(':r', $role);

        $stmt->execute();

        header('Location: /admin/users');
        exit;
    }
}

