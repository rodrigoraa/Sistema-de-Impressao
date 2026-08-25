<?php

require_once __DIR__ . '/../src/Service/TemporaryPrintFileService.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_id('print-preview-regression-' . bin2hex(random_bytes(4)));
    session_start();
}
$_SESSION['user'] = 'professor-a';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'print-storage-test-' . bin2hex(random_bytes(8));
$source = tempnam(sys_get_temp_dir(), 'print-source-');
file_put_contents($source, "arquivo de teste\n");

function removeTestDirectory($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

try {
    $storage = new TemporaryPrintFileService($root, 60);
    $entry = $storage->createFromExisting($source, [
        'original_name' => '../relatorio.txt',
        'extension' => 'txt',
        'size' => filesize($source),
        'mime_type' => 'text/plain',
    ]);
    $token = $entry['token'];
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
        throw new RuntimeException('Token temporário não é criptograficamente imprevisível.');
    }
    $resolved = $storage->entry($token);
    if (!is_file($resolved['source_path']) || !str_starts_with(realpath($resolved['source_path']), realpath($root))) {
        throw new RuntimeException('Arquivo temporário escapou da pasta configurada.');
    }

    $_SESSION['user'] = 'professor-b';
    try {
        $storage->entry($token);
        throw new RuntimeException('Outro usuário conseguiu acessar o token temporário.');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Outro usuário conseguiu acessar o token temporário.') {
            throw $e;
        }
    }
    $_SESSION['user'] = 'professor-a';

    try {
        $storage->entry('../' . $token);
        throw new RuntimeException('Token com path traversal foi aceito.');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Token com path traversal foi aceito.') {
            throw $e;
        }
    }

    $metadataFile = $root . DIRECTORY_SEPARATOR . $token . DIRECTORY_SEPARATOR . 'metadata.json';
    $metadata = json_decode((string) file_get_contents($metadataFile), true);
    $metadata['created_at'] = time() - 120;
    $metadata['touched_at'] = time() - 120;
    file_put_contents($metadataFile, json_encode($metadata));
    $removed = $storage->cleanupExpired();
    if ($removed !== 1 || is_dir($root . DIRECTORY_SEPARATOR . $token)) {
        throw new RuntimeException('Limpeza por TTL não removeu o arquivo expirado.');
    }

    echo "Armazenamento temporário seguro: OK\n";
} finally {
    @unlink($source);
    removeTestDirectory($root);
}
