<?php

class AuthController
{
    public function login()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            $db = new SQLite3(__DIR__ . '/../../storage/usage.db');

            $stmt = $db->prepare("SELECT * FROM users WHERE username = :u");
            $stmt->bindValue(':u', $_POST['user']);
            $result = $stmt->execute();

            $user = $result->fetchArray(SQLITE3_ASSOC);

            if ($user && password_verify($_POST['pass'], $user['password'])) {
                $_SESSION['user'] = $user['username'];
                $_SESSION['role'] = $user['role'];

                header('Location: /');
                exit;
            }

            $_SESSION['flash'] = "Login inválido";
            header('Location: /login');
            exit;
        }

        require __DIR__ . '/../../views/login.php';
    }

    public function logout()
    {
        session_destroy();
        header('Location: /login');
        exit;
    }
}