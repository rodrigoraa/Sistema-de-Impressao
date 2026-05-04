<?php

session_start();
var_dump($_SESSION);
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../src/Controller/PrintController.php';
require_once __DIR__ . '/../src/Controller/AuthController.php';
require_once __DIR__ . '/../src/Controller/AdminController.php';
require_once __DIR__ . '/../src/Controller/UserController.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// ROTAS
if ($uri === '/login') {
    (new AuthController())->login();
    exit;
}

if ($uri === '/logout') {
    (new AuthController())->logout();
    exit;
}

if ($uri === '/admin') {
    (new AdminController())->index();
    exit;
}

if ($uri === '/admin/users') {
    (new UserController())->index();
    exit;
}

if ($uri === '/admin/users/create') {
    (new UserController())->create();
    exit;
}

// PROTEÇÃO
if (!isset($_SESSION['user'])) {
    header('Location: /login');
    exit;
}

// PROCESSA
$controller = new PrintController();
$controller->handle();

// VIEW
require __DIR__ . '/../views/print.php';