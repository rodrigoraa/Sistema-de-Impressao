<?php

class AuthController
{
    private function db()
    {
        return new SQLite3(__DIR__ . '/../../storage/usage.db');
    }

    public function login()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            $db = $this->db();

            $cpf = preg_replace('/\D/', '', $_POST['matricula'] ?? '');
            $senha = $_POST['senha'] ?? '';

            $stmt = $db->prepare("SELECT * FROM users WHERE cpf = :cpf");
            $stmt->bindValue(':cpf', $cpf);
            $result = $stmt->execute();

            $user = $result->fetchArray(SQLITE3_ASSOC);

            if (!$user) {
                $_SESSION['flash'] = "CPF não encontrado";
                header('Location: /login');
                exit;
            }

            if ($user['role'] === 'admin') {
                if (!$senha || !password_verify($senha, $user['password'])) {
                    $_SESSION['flash'] = "Senha inválida";
                    header('Location: /login');
                    exit;
                }
            }

            $_SESSION['user'] = $user['cpf'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];

            header('Location: /');
            exit;
        }

        // lista admins
        $db = $this->db();
        $res = $db->query("SELECT cpf FROM users WHERE role = 'admin'");

        $admins = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $admins[] = $row['cpf'];
        }

        var_dump($admins);
        exit;

        require __DIR__ . '/../../views/login.php';
    }

    public function logout()
    {
        session_destroy();
        header('Location: /login');
        exit;
    }
}