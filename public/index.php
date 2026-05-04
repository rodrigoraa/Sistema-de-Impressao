<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Controller/AdminController.php';

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

if ($uri === '/admin') {
    (new AdminController())->index();
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
    
    <input type="file" name="arquivo" required><br><br>

    <label>Cópias:</label>
    <input type="number" name="copies" value="1" min="1"><br><br>

    <label>Modo:</label>
    <select name="sides">
        <option value="one-sided">Simples</option>
        <option value="two-sided-long-edge">Frente e verso</option>
    </select><br><br>

    <button type="submit">Imprimir</button><br><br>

    <a href="/logout">Sair</a>

</form>

</body>
</html>