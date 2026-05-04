<?php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require_once __DIR__ . '/../src/Controller/PrintController.php';
require_once __DIR__ . '/../src/Controller/AuthController.php';

$uri = $_SERVER['REQUEST_URI'];

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
?>

<!DOCTYPE html>
<html>
<head>
    <title>Impressão</title>
</head>
<body>
    <h2>Enviar arquivo para impressão</h2>
    <form method="post" enctype="multipart/form-data">
        <input type="file" name="arquivo" required>
        <button type="submit">Imprimir</button> <br>
        <a href="/logout">Sair</a>
    </form>
</body>
</html>