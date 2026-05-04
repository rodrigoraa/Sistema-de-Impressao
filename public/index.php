<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../src/Controller/PrintController.php';
require_once __DIR__ . '/../src/Controller/AuthController.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($uri === '/login') {
    (new AuthController())->login();
    exit;
}

if ($uri === '/logout') {
    (new AuthController())->logout();
    exit;
}

$controller = new PrintController();
$controller->handle();

if (!isset($_SESSION['user'])) {
    header('Location: /login');
    exit;
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Impressão</title>
    <link rel="stylesheet" href="/css/style.css">
</head>

<body>

    <div class="container">

        <h2>Impressão</h2>

        <form method="post" enctype="multipart/form-data">

            <label>Arquivo</label>
            <input type="file" name="arquivo" required>

            <label>Cópias</label>
            <input type="number" name="copies" value="1" min="1">

            <label>Modo</label>
            <select name="sides">
                <option value="one-sided">Simples</option>
                <option value="two-sided-long-edge">Frente e verso</option>
            </select>

            <label>Orientação</label>
            <select name="orientation">
                <option value="portrait">Retrato</option>
                <option value="landscape">Paisagem</option>
            </select>

            <label>Qualidade</label>
            <select name="quality">
                <option value="3">Normal</option>
                <option value="5">Alta</option>
            </select>

            <button type="submit">Imprimir</button>

        </form>

        <a class="logout" href="/logout">Sair</a>

    </div>

</body>

</html>