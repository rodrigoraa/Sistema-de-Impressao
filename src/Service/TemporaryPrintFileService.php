<?php

require_once __DIR__ . '/PrintService.php';

class TemporaryPrintFileService
{
    private $root;
    private $ttlSeconds;

    public function __construct($root = null, $ttlSeconds = null)
    {
        $this->root = rtrim((string) ($root ?? ($_ENV['PRINT_TEMP_PATH'] ?? dirname(__DIR__, 2) . '/storage/print-temp')), "\\/");
        $this->ttlSeconds = max(60, (int) ($ttlSeconds ?? ($_ENV['PRINT_PREVIEW_TTL_SECONDS'] ?? 1800)));
        $this->ensureRoot();
    }

    public function createFromUpload($fileInfo)
    {
        if (!is_array($fileInfo) || !is_file($fileInfo['tmp_path'] ?? '')) {
            throw new RuntimeException('Arquivo temporário de upload não encontrado.');
        }

        $token = bin2hex(random_bytes(32));
        $dir = $this->tokenDirectory($token);
        if (!mkdir($dir, 0700, true)) {
            throw new RuntimeException('Não foi possível preparar o armazenamento temporário.');
        }

        $extension = strtolower((string) ($fileInfo['extension'] ?? ''));
        $source = $dir . DIRECTORY_SEPARATOR . 'source-' . bin2hex(random_bytes(12)) . '.' . $extension;
        if (!move_uploaded_file((string) $fileInfo['tmp_path'], $source)) {
            $this->removeDirectory($dir);
            throw new RuntimeException('Não foi possível armazenar o arquivo temporário.');
        }

        $metadata = $this->baseMetadata($token, $fileInfo, basename($source));
        $this->writeMetadata($token, $metadata);

        return $metadata;
    }

    public function createFromExisting($sourcePath, $fileInfo)
    {
        if (!is_file($sourcePath)) {
            throw new RuntimeException('Arquivo de origem não encontrado.');
        }

        $token = bin2hex(random_bytes(32));
        $dir = $this->tokenDirectory($token);
        if (!mkdir($dir, 0700, true)) {
            throw new RuntimeException('Não foi possível preparar o armazenamento temporário.');
        }

        $extension = strtolower((string) ($fileInfo['extension'] ?? pathinfo($sourcePath, PATHINFO_EXTENSION)));
        $source = $dir . DIRECTORY_SEPARATOR . 'source-' . bin2hex(random_bytes(12)) . '.' . $extension;
        if (!copy($sourcePath, $source)) {
            $this->removeDirectory($dir);
            throw new RuntimeException('Não foi possível copiar o arquivo para a pré-visualização.');
        }

        $fileInfo['size'] = (int) ($fileInfo['size'] ?? filesize($sourcePath));
        $fileInfo['tmp_path'] = $source;
        $metadata = $this->baseMetadata($token, $fileInfo, basename($source));
        $this->writeMetadata($token, $metadata);

        return $metadata;
    }

    public function entry($token)
    {
        $metadata = $this->readMetadata($token);
        $source = $this->safeChildPath($token, (string) ($metadata['source_file'] ?? ''));
        if ($source === null || !is_file($source)) {
            $this->destroy($token);
            throw new RuntimeException('Arquivo temporário não encontrado ou expirado.');
        }

        $metadata['source_path'] = $source;
        $metadata['token'] = (string) $token;
        $metadata['touched_at'] = time();
        $this->writeMetadata($token, $metadata);

        return $metadata;
    }

    public function preparedPdf($token, $orientation, $paper, $extraOptions = [])
    {
        $metadata = $this->entry($token);
        $source = $metadata['source_path'];
        $extension = strtolower((string) ($metadata['extension'] ?? ''));
        if ($extension === 'pdf') {
            return $source;
        }

        $cacheKey = $this->conversionCacheKey($orientation, $paper, $extraOptions);
        $cachedName = (string) (($metadata['prepared_files'][$cacheKey] ?? ''));
        if ($cachedName !== '') {
            $cached = $this->safeChildPath($token, $cachedName);
            if ($cached !== null && is_file($cached) && filesize($cached) > 0) {
                return $cached;
            }
        }

        $printer = new PrintService();
        $generated = $printer->prepareFile($source, $orientation, $paper, $extraOptions);
        if (!is_file($generated) || strtolower(pathinfo($generated, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new RuntimeException('A conversão temporária não gerou um PDF válido.');
        }

        $targetName = 'prepared-' . $cacheKey . '-' . bin2hex(random_bytes(6)) . '.pdf';
        $target = $this->tokenDirectory($token) . DIRECTORY_SEPARATOR . $targetName;
        if (!copy($generated, $target)) {
            if ($generated !== $source) {
                @unlink($generated);
            }
            throw new RuntimeException('Não foi possível guardar a conversão temporária.');
        }
        @chmod($target, 0600);
        if ($generated !== $source) {
            @unlink($generated);
        }

        $metadata = $this->readMetadata($token);
        $metadata['prepared_files'][$cacheKey] = $targetName;
        $metadata['touched_at'] = time();
        $this->writeMetadata($token, $metadata);

        return $target;
    }

    public function existingPreparedPdf($token, $orientation, $paper, $extraOptions = [])
    {
        $metadata = $this->entry($token);
        if (strtolower((string) ($metadata['extension'] ?? '')) === 'pdf') {
            return null;
        }

        $cacheKey = $this->conversionCacheKey($orientation, $paper, $extraOptions);
        $name = (string) (($metadata['prepared_files'][$cacheKey] ?? ''));
        $path = $name !== '' ? $this->safeChildPath($token, $name) : null;

        return $path !== null && is_file($path) ? $path : null;
    }

    public function previewCachePath($token, $parameters)
    {
        $this->entry($token);
        $key = hash('sha256', json_encode($this->normalizeArray($parameters), JSON_UNESCAPED_SLASHES));

        return $this->tokenDirectory($token) . DIRECTORY_SEPARATOR . 'preview-' . $key . '.pdf';
    }

    public function moveSourceTo($token, $destination)
    {
        $metadata = $this->entry($token);
        $source = $metadata['source_path'];
        if (!@rename($source, $destination)) {
            if (!@copy($source, $destination)) {
                throw new RuntimeException('Não foi possível preparar o arquivo temporário para impressão.');
            }
            @unlink($source);
        }

        return $destination;
    }

    public function destroy($token)
    {
        if (!$this->validToken($token)) {
            return;
        }

        $this->removeDirectory($this->tokenDirectory($token));
    }

    public function cleanupExpired()
    {
        $this->ensureRoot();
        $now = time();
        $removed = 0;
        foreach (new DirectoryIterator($this->root) as $item) {
            if ($item->isDot() || !$item->isDir() || !$this->validToken($item->getFilename())) {
                continue;
            }

            $metadataFile = $item->getPathname() . DIRECTORY_SEPARATOR . 'metadata.json';
            $metadata = is_file($metadataFile) ? json_decode((string) @file_get_contents($metadataFile), true) : null;
            $touchedAt = is_array($metadata) ? (int) ($metadata['touched_at'] ?? $metadata['created_at'] ?? 0) : 0;
            if ($touchedAt < 1 || ($now - $touchedAt) > $this->ttlSeconds) {
                $this->removeDirectory($item->getPathname());
                $removed++;
            }
        }

        return $removed;
    }

    private function baseMetadata($token, $fileInfo, $sourceFile)
    {
        $now = time();
        return [
            'version' => 1,
            'token' => $token,
            'owner_key' => $this->ownerKey(),
            'original_name' => (string) ($fileInfo['original_name'] ?? 'arquivo'),
            'extension' => strtolower((string) ($fileInfo['extension'] ?? '')),
            'size' => (int) ($fileInfo['size'] ?? 0),
            'mime_type' => (string) ($fileInfo['mime_type'] ?? ''),
            'source_file' => $sourceFile,
            'prepared_files' => [],
            'created_at' => $now,
            'touched_at' => $now,
        ];
    }

    private function conversionCacheKey($orientation, $paper, $extraOptions)
    {
        $conversionOptions = [];
        foreach (['page-top', 'page-right', 'page-bottom', 'page-left'] as $key) {
            if (isset($extraOptions[$key])) {
                $conversionOptions[$key] = (string) $extraOptions[$key];
            }
        }

        return substr(hash('sha256', json_encode([
            'orientation' => (string) $orientation,
            'paper' => (string) $paper,
            'options' => $conversionOptions,
        ], JSON_UNESCAPED_SLASHES)), 0, 32);
    }

    private function readMetadata($token)
    {
        if (!$this->validToken($token)) {
            throw new RuntimeException('Identificador temporário inválido.');
        }

        $file = $this->tokenDirectory($token) . DIRECTORY_SEPARATOR . 'metadata.json';
        $metadata = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (!is_array($metadata)
            || !isset($metadata['owner_key'])
            || !hash_equals((string) $metadata['owner_key'], $this->ownerKey())) {
            throw new RuntimeException('Arquivo temporário não encontrado ou expirado.');
        }

        $touchedAt = (int) ($metadata['touched_at'] ?? $metadata['created_at'] ?? 0);
        if ($touchedAt < 1 || (time() - $touchedAt) > $this->ttlSeconds) {
            $this->destroy($token);
            throw new RuntimeException('Arquivo temporário não encontrado ou expirado.');
        }

        return $metadata;
    }

    private function writeMetadata($token, $metadata)
    {
        $file = $this->tokenDirectory($token) . DIRECTORY_SEPARATOR . 'metadata.json';
        $temporary = $file . '.tmp-' . bin2hex(random_bytes(4));
        $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($temporary, $json, LOCK_EX) === false || !@rename($temporary, $file)) {
            @unlink($temporary);
            throw new RuntimeException('Não foi possível atualizar o armazenamento temporário.');
        }
        @chmod($file, 0600);
    }

    private function safeChildPath($token, $name)
    {
        if (!$this->validToken($token) || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
            return null;
        }

        $dir = realpath($this->tokenDirectory($token));
        if ($dir === false) {
            return null;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $name;
        $normalizedDir = rtrim(str_replace('\\', '/', $dir), '/') . '/';
        $normalizedPath = str_replace('\\', '/', $path);

        return str_starts_with($normalizedPath, $normalizedDir) ? $path : null;
    }

    private function ownerKey()
    {
        $session = session_status() === PHP_SESSION_ACTIVE ? session_id() : '';
        $user = (string) ($_SESSION['user'] ?? '');

        return hash('sha256', $session . '|' . $user);
    }

    private function tokenDirectory($token)
    {
        return $this->root . DIRECTORY_SEPARATOR . (string) $token;
    }

    private function validToken($token)
    {
        return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
    }

    private function ensureRoot()
    {
        if (!is_dir($this->root) && !mkdir($this->root, 0700, true)) {
            throw new RuntimeException('Não foi possível preparar a pasta temporária.');
        }
    }

    private function normalizeArray($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeArray($item);
        }

        return $value;
    }

    private function removeDirectory($dir)
    {
        $root = realpath($this->root);
        $real = realpath($dir);
        if ($root === false || $real === false) {
            return;
        }

        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $normalizedReal = rtrim(str_replace('\\', '/', $real), '/') . '/';
        if ($normalizedReal === $normalizedRoot || !str_starts_with($normalizedReal, $normalizedRoot)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($real);
    }
}
