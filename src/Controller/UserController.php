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
}

