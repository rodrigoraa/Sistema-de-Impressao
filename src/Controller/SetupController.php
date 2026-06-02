<?php

require_once __DIR__ . '/../Service/Database.php';

class SetupController
{
    private function validateCsrfOrFail()
    {
        $token = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (!is_string($token) || !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
            http_response_code(419);
            exit('Token CSRF inválido');
        }
    }

    public function index()
    {
        $db = Database::connect();

        if (Database::hasAnyAdmin($db)) {
            header('Location: /login');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCsrfOrFail();

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
                $_SESSION['flash'] = 'Erro ao criar administrador. Verifique os dados e tente novamente.';
                header('Location: /setup');
                exit;
            }

            $_SESSION['flash'] = 'Administrador criado. Faça login.';
            header('Location: /login');
            exit;
        }

        require __DIR__ . '/../../views/setup.php';
    }
}

