<?php

require_once __DIR__ . '/../src/Controller/PrintController.php';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'png-preview-test-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$source = $root . DIRECTORY_SEPARATOR . 'image.png';
$originalLimit = ini_get('memory_limit');
$storage = null;
$token = '';

try {
    // Exercise the actual endpoint in isolation: preview() sends headers and exits.
    $request = <<<'PHP'
require $argv[1];
session_save_path($argv[2]);
session_start();
$_SESSION['user'] = 'png-preview-test';
$_SESSION['csrf_token'] = 'test-token';
$_ENV['PRINT_TEMP_PATH'] = $argv[2] . '/files';
$_ENV['LOG_PATH'] = $argv[2] . '/test.log';
$source = $argv[2] . '/image.png';
file_put_contents($source, 'invalid PNG');
$storage = new TemporaryPrintFileService();
$entry = $storage->createFromExisting($source, ['extension' => 'png', 'original_name' => 'image.png']);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf_token' => 'test-token', 'upload_token' => $entry['token']];
(new PrintController())->preview();
PHP;
    $process = proc_open(
        [PHP_BINARY, '-r', $request, __DIR__ . '/../src/Controller/PrintController.php', $root],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $response = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || $error !== '' || !str_contains($response, 'conversão para PDF')
        || str_contains($response, 'pode ser enviado para impressão')) {
        throw new RuntimeException('Aviso da previa esconde falha de conversao: ' . $response . $error);
    }

    if (function_exists('imagecreatefrompng')) {
        ini_set('memory_limit', '256M');
        session_save_path($root);
        session_start();
        $_SESSION['user'] = 'png-preview-test';
        $_ENV['LOG_PATH'] = $root . DIRECTORY_SEPARATOR . 'test.log';
        $image = imagecreatetruecolor(1600, 1500);
        imagepng($image, $source, 0);
        imagedestroy($image);
        unset($image);
        clearstatcache(true, $source);
        if (filesize($source) < 7000000) {
            throw new RuntimeException('Amostra nao atingiu 7 MB.');
        }
        $storage = new TemporaryPrintFileService($root . DIRECTORY_SEPARATOR . 'large');
        $entry = $storage->createFromExisting($source, ['extension' => 'png', 'original_name' => 'image.png']);
        $token = $entry['token'];
        $pdf = $storage->preparedPdf($token, 'portrait', 'A4');
        if (!str_starts_with(file_get_contents($pdf), '%PDF-')
            || $storage->preparedPdf($token, 'portrait', 'A4') !== $pdf
            || $storage->existingPreparedPdf($token, 'portrait', 'A4') !== $pdf) {
            throw new RuntimeException('Previa e impressao nao reutilizam o PDF do PNG grande.');
        }
        echo "Preparacao compartilhada da previa PNG de 7 MB: OK\n";
    } else {
        echo "Preparacao PNG grande nao executada (GD ausente).\n";
    }
    echo "Aviso de falha de conversao na previa: OK\n";
} finally {
    ini_set('memory_limit', $originalLimit);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}
