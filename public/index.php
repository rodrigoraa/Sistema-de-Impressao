<?php

$config = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Controller/PrintController.php';

$controller = new PrintController($config);
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
        <button type="submit">Imprimir</button>
    </form>
</body>
</html>