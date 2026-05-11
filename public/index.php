<?php

ini_set('log_errors', 1);
ini_set('error_log', '/tmp/php_error.log');

function loadEnvFile($path)
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);

        if ($key === '' || array_key_exists($key, $_ENV)) {
            continue;
        }

        $len = strlen($value);
        if ($len >= 2) {
            $first = $value[0];
            $last = $value[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        $_ENV[$key] = $value;
    }
}

$projectRoot = dirname(__DIR__);

// composer/vendor é opcional (muitos hosts não sobem o vendor no deploy)
$autoload = $projectRoot . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

loadEnvFile($projectRoot . '/.env');

// defaults via config/config.php
$config = is_file($projectRoot . '/config/config.php')
    ? require $projectRoot . '/config/config.php'
    : [];

$_ENV['PRINTER_NAME'] = $_ENV['PRINTER_NAME'] ?? ($config['printer_name'] ?? '');
$_ENV['UPLOAD_PATH'] = $_ENV['UPLOAD_PATH'] ?? ($config['upload_path'] ?? ($projectRoot . '/storage/uploads/'));
$_ENV['LOG_PATH'] = $_ENV['LOG_PATH'] ?? ($config['log_path'] ?? ($projectRoot . '/storage/logs/app.log'));

$sessionPath = (string) ($_ENV['SESSION_PATH'] ?? ($config['session_path'] ?? ($projectRoot . '/storage/sessions')));
if ($sessionPath && !is_dir($sessionPath)) {
    @mkdir($sessionPath, 0775, true);
}
if ($sessionPath && is_dir($sessionPath)) {
    session_save_path($sessionPath);
}

$sessionDays = max(1, (int) ($_ENV['SESSION_LIFETIME_DAYS'] ?? ($config['session_lifetime_days'] ?? 30)));
$sessionLifetime = $sessionDays * 24 * 60 * 60;
ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
ini_set('session.cookie_lifetime', (string) $sessionLifetime);
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Renova o prazo do cookie a cada acesso enquanto o usuário permanecer logado.
if (isset($_SESSION['user'])) {
    setcookie(session_name(), session_id(), [
        'expires' => time() + $sessionLifetime,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// garante diretórios
$uploadDir = rtrim((string) ($_ENV['UPLOAD_PATH'] ?? ''), '/');
if ($uploadDir && !is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}

$logPath = (string) ($_ENV['LOG_PATH'] ?? '');
$logDir = $logPath ? dirname($logPath) : '';
if ($logDir && !is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

require_once $projectRoot . '/src/Service/Database.php';
require_once $projectRoot . '/src/Controller/PrintController.php';
require_once $projectRoot . '/src/Controller/AuthController.php';
require_once $projectRoot . '/src/Controller/AdminController.php';
require_once $projectRoot . '/src/Controller/UserController.php';
require_once $projectRoot . '/src/Controller/SetupController.php';
require_once $projectRoot . '/src/Controller/PrinterController.php';
require_once $projectRoot . '/src/Controller/PrintJobController.php';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// normaliza quando a app roda em subpasta (ex: /public)
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($basePath === '/') {
    $basePath = '';
}

if ($basePath !== '') {
    if ($uri === $basePath) {
        $uri = '/';
    } elseif (str_starts_with($uri, $basePath . '/')) {
        $uri = substr($uri, strlen($basePath));
        if ($uri === '') {
            $uri = '/';
        }
    }
}

// primeira execução: cria admin inicial
$db = Database::connect();
if (!Database::hasAnyAdmin($db) && $uri !== '/setup') {
    header('Location: /setup');
    exit;
}

// ROTAS
if ($uri === '/setup') {
    (new SetupController())->index();
    exit;
}

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

if ($uri === '/admin/users/edit') {
    (new UserController())->edit();
    exit;
}

if ($uri === '/admin/users/update') {
    (new UserController())->update();
    exit;
}

if ($uri === '/admin/users/delete') {
    (new UserController())->delete();
    exit;
}

if ($uri === '/printer/options') {
    (new PrinterController())->options();
    exit;
}

// PROTEÇÃO
if (!isset($_SESSION['user'])) {
    header('Location: /login');
    exit;
}

if ($uri === '/print/page-count') {
    (new PrintController())->pageCount();
    exit;
}

if ($uri === '/prints') {
    (new PrintJobController())->index();
    exit;
}

if ($uri === '/prints/download') {
    (new PrintJobController())->download();
    exit;
}

if ($uri === '/prints/reprint') {
    (new PrintJobController())->reprint();
    exit;
}

// PROCESSA
$controller = new PrintController();
$viewData = $controller->handle();
$userList = is_array($viewData) ? ($viewData['userList'] ?? []) : [];

// VIEW
require $projectRoot . '/views/print.php';
