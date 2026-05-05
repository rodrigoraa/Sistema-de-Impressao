<?php

class AuthService
{
    public static function isAdmin()
    {
        if (!isset($_SESSION['user'])) {
            return false;
        }

        if (isset($_SESSION['role'])) {
            return $_SESSION['role'] === 'admin';
        }

        // fallback legado (caso role não esteja na sessão)
        $admins = explode(',', $_ENV['ADMIN_USERS'] ?? '');

        return in_array($_SESSION['user'], array_map('trim', $admins));
    }
}

