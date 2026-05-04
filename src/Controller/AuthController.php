<?php

class AuthController
{
    public function login()
    {
        session_start();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $users = require __DIR__ . '/../../storage/users.php';

            $user = $_POST['user'] ?? '';
            $pass = $_POST['pass'] ?? '';

            if (isset($users[$user]) && password_verify($pass, $users[$user])) {
                $_SESSION['user'] = $user;
                header('Location: /');
                exit;
            }

            echo "Login inválido";
        }

        echo '
        <form method="post">
            <input name="user" placeholder="Usuário" required>
            <input type="password" name="pass" placeholder="Senha" required>
            <button>Entrar</button>
        </form>';
    }

    public function logout()
    {
        session_start();
        session_destroy();
        header('Location: /login');
    }
}