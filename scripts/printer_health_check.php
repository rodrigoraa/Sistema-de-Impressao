<?php

require_once __DIR__ . '/../src/Service/CupsService.php';

function loadEnvForCli($path)
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

$root = dirname(__DIR__);
loadEnvForCli($root . '/.env');

$config = is_file($root . '/config/config.php')
    ? require $root . '/config/config.php'
    : [];

$_ENV['PRINTER_NAME'] = $_ENV['PRINTER_NAME'] ?? ($config['printer_name'] ?? '');
$_ENV['LOG_PATH'] = $_ENV['LOG_PATH'] ?? ($config['log_path'] ?? ($root . '/storage/logs/app.log'));
$_ENV['APP_TIMEZONE'] = $_ENV['APP_TIMEZONE'] ?? ($config['app_timezone'] ?? 'America/Cuiaba');
@date_default_timezone_set($_ENV['APP_TIMEZONE']);

$cups = new CupsService();
$status = $cups->diagnostics(true);

$payload = [
    'checked_at' => date('Y-m-d H:i:s'),
    'printer' => $status['printer'] ?? '',
    'can_print' => !empty($status['can_print']),
    'notice_type' => $status['notice_type'] ?? '',
    'notice' => $status['notice'] ?? '',
    'reason' => $status['reason'] ?? '',
    'enabled' => $status['enabled'] ?? null,
    'accepting' => $status['accepting'] ?? null,
    'pending_jobs' => (int) ($status['pending_jobs'] ?? 0),
    'auto_enable_result' => $status['auto_enable_result'] ?? null,
];

$logPath = (string) ($_ENV['LOG_PATH'] ?? ($root . '/storage/logs/app.log'));
$logDir = dirname(is_dir($logPath) ? rtrim($logPath, "\\/") . DIRECTORY_SEPARATOR . 'app.log' : $logPath);
if ($logDir && !is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

$statusPath = $logDir . DIRECTORY_SEPARATOR . 'printer-health-last.json';
@file_put_contents($statusPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$line = sprintf(
    '[%s] printer=%s can_print=%s reason=%s enabled=%s accepting=%s pending=%d notice=%s',
    $payload['checked_at'],
    $payload['printer'] !== '' ? $payload['printer'] : '-',
    $payload['can_print'] ? 'yes' : 'no',
    $payload['reason'] !== '' ? $payload['reason'] : '-',
    $payload['enabled'] === null ? 'n/d' : ($payload['enabled'] ? 'yes' : 'no'),
    $payload['accepting'] === null ? 'n/d' : ($payload['accepting'] ? 'yes' : 'no'),
    $payload['pending_jobs'],
    str_replace(["\r", "\n"], ' ', (string) $payload['notice'])
);

echo $line . PHP_EOL;

