<?php

require_once __DIR__ . '/../src/Controller/PrintController.php';

$controller = new PrintController();
$method = new ReflectionMethod(PrintController::class, 'safeErrorMessage');
$method->setAccessible(true);

$cases = [
    '<!DOCTYPE html><html><body>Gateway time-out</body></html>' => 'Gateway time-out',
    'Falha em D:\\Projetos\\Sistema Web - Impressao\\storage\\uploads\\arquivo.pdf' => 'Falha em [arquivo]',
    'Falha em /var/www/Impressao/Sistema-de-Impressao/storage/uploads/arquivo.pdf' => 'Falha em [arquivo]',
];

foreach ($cases as $input => $expectedNeedle) {
    $out = $method->invoke($controller, $input);
    if (str_contains($out, '<') || str_contains($out, 'D:\\Projetos') || str_contains($out, '/var/www/')) {
        fwrite(STDERR, "Mensagem insegura: {$out}\n");
        exit(1);
    }
    if (!str_contains($out, $expectedNeedle)) {
        fwrite(STDERR, "Mensagem sanitizada inesperada: {$out}\n");
        exit(1);
    }
}

echo "Regressoes de seguranca: OK\n";

