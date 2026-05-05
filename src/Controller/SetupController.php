<?php

require_once __DIR__ . '/../Service/Database.php';

class SetupController
{
    public function index()
    {
        $db = Database::connect();

        if (Database::hasAnyAdmin($db)) {
            header('Location: /login');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = trim($_POST['name'] ?? '');
            $cpf = preg_replace('/\\D/', '', $_POST['cpf'] ?? '');
            $password = (string) ($_POST['password'] ?? '');

            if (!$name || !$cpf || $password === '') {
                $_SESSION['flash'] = 'Preencha nome, CPF e senha.';
                header('Location: /setup');
                exit;
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $db->prepare("
                INSERT INTO users (name, cpf, password, role)
                VALUES (:n, :c, :p, 'admin')
            ");
            $stmt->bindValue(':n', $name);
            $stmt->bindValue(':c', $cpf);
            $stmt->bindValue(':p', $hash);

            $res = $stmt->execute();
            if (!$res) {
                $_SESSION['flash'] = 'Erro ao criar admin: ' . $db->lastErrorMsg();
                header('Location: /setup');
                exit;
            }

            $_SESSION['flash'] = 'Admin criado. Faça login.';
            header('Location: /login');
            exit;
        }

        require __DIR__ . '/../../views/setup.php';
    }
}

