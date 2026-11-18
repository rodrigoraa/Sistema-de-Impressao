<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/config/load_env.php';
require_once $root . '/src/Service/PrintQueueService.php';

// CLI pode omitir as variáveis do processo de $_ENV por configuração do PHP.
$_ENV += getenv();
loadEnvFile($root . '/.env');
$config = require $root . '/config/config.php';
$_ENV['PRINTER_NAME'] ??= $config['printer_name'] ?? '';
$_ENV['LOG_PATH'] ??= $config['log_path'] ?? ($root . '/storage/logs/app.log');
$_ENV['APP_TIMEZONE'] ??= $config['app_timezone'] ?? 'America/Cuiaba';

try {
    if (!PrintQueueService::supported() || $_ENV['PRINTER_NAME'] === '') {
        throw new RuntimeException('A fila requer Linux e PRINTER_NAME configurada.');
    }
    $queue = new PrintQueueService();
    if (($argv[1] ?? '') === '--check') {
        $queue->checkStorage();
        echo "Banco e armazenamento da fila acessíveis.\n";
    } else {
        $queue->run();
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
