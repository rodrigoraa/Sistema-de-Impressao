<?php

class AuthController
{
    public function login()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            $users = require __DIR__ . '/../../storage/users.php';

            $user = $_POST['user'] ?? '';
            $pass = $_POST['pass'] ?? '';

            if (isset($users[$user]) && password_verify($pass, $users[$user])) {
                $_SESSION['user'] = $user;
                header('Location: /');
                exit;
            }

            $_SESSION['flash'] = "Login inválido";
            $_SESSION['flash_type'] = "error";

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