<?php

class AuthService
{
    public static function isAdmin()
    {
        if (!isset($_SESSION['user'])) {
            return false;
        }

        $admins = explode(',', $_ENV['ADMIN_USERS'] ?? '');

        return in_array($_SESSION['user'], array_map('trim', $admins));
    }
}