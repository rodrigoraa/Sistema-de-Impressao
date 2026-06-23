<?php
require_once __DIR__ . '/PrintController.php';

class ShareTargetController
{
    private $ttlSeconds = 6 * 60 * 60;

    public function handle()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $printController = new PrintController();
        $this->cleanupExpiredSharedFiles();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($this->isConfirmationPost()) {
                $this->handleConfirmation($printController);
                return;
            }

            $this->receiveSharedFile($printController);
            return;
        }

        $this->showConfirmation($printController);
    }

    private function receiveSharedFile(PrintController $printController)
    {
        $file = $this->uploadedFileFromRequest();

        try {
            $fileInfo = $printController->storeUploadedFile($file, $this->shareDir());
        } catch (UploadValidationException $e) {
            $this->logShareTargetIssue('Falha ao receber arquivo compartilhado: ' . $e->getMessage());
            $_SESSION['flash'] = 'Não foi possível receber o arquivo compartilhado. ' . $this->shareUploadMessage($e);
            $_SESSION['flash_type'] = 'error';
            header('Location: ' . (isset($_SESSION['user']) ? '/' : '/login'));
            exit;
        }

        $token = bin2hex(random_bytes(16));
        $_SESSION['shared_files'][$token] = [
            'stored_file' => $fileInfo['stored_file'],
            'original_name' => $fileInfo['original_name'],
            'extension' => $fileInfo['extension'],
            'size' => (int) $fileInfo['size'],
            'mime_type' => (string) $fileInfo['mime_type'],
            'created_at' => time(),
        ];

        $target = '/share-target.php?token=' . rawurlencode($token);
        if (!isset($_SESSION['user'])) {
            $_SESSION['after_login_redirect'] = $target;
            $_SESSION['flash'] = 'Arquivo recebido. Entre no sistema para revisar e confirmar a impressão.';
            $_SESSION['flash_type'] = 'error';
            header('Location: /login');
            exit;
        }

        header('Location: ' . $target);
        exit;
    }

    private function handleConfirmation(PrintController $printController)
    {
        $this->validateCsrfOrFail();

        if (!isset($_SESSION['user'])) {
            $token = $this->requestedToken();
            $_SESSION['after_login_redirect'] = '/share-target.php' . ($token !== '' ? '?token=' . rawurlencode($token) : '');
            header('Location: /login');
            exit;
        }

        $token = $this->requestedToken();
        $entry = $this->sharedEntry($token);
        if ($entry === null) {
            $this->flashAndRedirect('Arquivo compartilhado não encontrado ou expirado.', false, '/');
        }

        $action = $_POST['share_action'] ?? '';
        if ($action === 'cancel') {
            $this->deleteSharedFile($entry);
            unset($_SESSION['shared_files'][$token]);
            $this->flashAndRedirect('Impressão cancelada. O arquivo compartilhado temporário foi removido.', true, '/');
        }

        if ($action !== 'print') {
            $this->flashAndRedirect('Ação inválida para o arquivo compartilhado.', false, '/share-target.php?token=' . rawurlencode($token));
        }

        try {
            $dest = $printController->moveExistingFileToUploadPath($entry['stored_file'], $entry['original_name']);
            unset($_SESSION['shared_files'][$token]);
            $printController->printSavedFile(
                $dest,
                $entry['original_name'],
                $entry['extension'],
                (int) $entry['size'],
                (string) $entry['mime_type']
            );
        } catch (Throwable $e) {
            $this->flashAndRedirect('Não foi possível preparar a impressão compartilhada: ' . $this->safeMessage($e->getMessage()), false, '/share-target.php?token=' . rawurlencode($token));
        }
    }

    private function showConfirmation(PrintController $printController)
    {
        if (!isset($_SESSION['user'])) {
            $token = $this->requestedToken();
            $_SESSION['after_login_redirect'] = '/share-target.php' . ($token !== '' ? '?token=' . rawurlencode($token) : '');
            header('Location: /login');
            exit;
        }

        $token = $this->requestedToken();
        $entry = $this->sharedEntry($token);
        if ($entry === null) {
            $this->flashAndRedirect('Arquivo compartilhado não encontrado ou expirado.', false, '/');
        }

        $context = $printController->formContext();
        $userList = $context['userList'] ?? [];
        $printerStatus = $context['printerStatus'] ?? [];
        $maxUploadMb = $context['maxUploadMb'] ?? $printController->maxUploadMegabytes();
        $shareToken = $token;
        $sharedFile = [
            'original_name' => $entry['original_name'],
            'extension' => $entry['extension'],
            'mime_type' => $entry['mime_type'],
            'size' => (int) $entry['size'],
            'size_label' => $this->formatBytes((int) $entry['size']),
        ];

        require __DIR__ . '/../../views/share_confirm.php';
    }

    private function isConfirmationPost()
    {
        return isset($_POST['share_action']) || isset($_POST['share_token']);
    }

    private function uploadedFileFromRequest()
    {
        if (isset($_FILES['arquivo'])) {
            return $this->normalizeUploadedFile($_FILES['arquivo']);
        }

        foreach ($_FILES as $field => $file) {
            $normalized = $this->normalizeUploadedFile($file);
            if ($normalized !== null) {
                $this->logShareTargetIssue("Arquivo recebido pelo campo alternativo '{$field}'.");
                return $normalized;
            }
        }

        $this->logShareTargetIssue('POST de compartilhamento sem arquivo em $_FILES.');
        return null;
    }

    private function normalizeUploadedFile($file)
    {
        if (!is_array($file)) {
            return null;
        }

        if (isset($file['name']) && is_array($file['name'])) {
            foreach (array_keys($file['name']) as $index) {
                $candidate = [
                    'name' => $file['name'][$index] ?? '',
                    'type' => $file['type'][$index] ?? '',
                    'tmp_name' => $file['tmp_name'][$index] ?? '',
                    'error' => $file['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $file['size'][$index] ?? 0,
                ];

                if (($candidate['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE || $candidate['name'] !== '') {
                    return $candidate;
                }
            }

            return null;
        }

        return $file;
    }

    private function requestedToken()
    {
        $token = (string) ($_POST['share_token'] ?? ($_GET['token'] ?? ''));
        if ($token !== '') {
            return $token;
        }

        $files = $_SESSION['shared_files'] ?? [];
        if (is_array($files) && count($files) === 1) {
            return (string) array_key_first($files);
        }

        return '';
    }

    private function sharedEntry($token)
    {
        if ($token === '' || empty($_SESSION['shared_files'][$token]) || !is_array($_SESSION['shared_files'][$token])) {
            return null;
        }

        $entry = $_SESSION['shared_files'][$token];
        if (!$this->isSafeSharedPath($entry['stored_file'] ?? '') || !is_file($entry['stored_file'])) {
            unset($_SESSION['shared_files'][$token]);
            return null;
        }

        return $entry;
    }

    private function cleanupExpiredSharedFiles()
    {
        if (empty($_SESSION['shared_files']) || !is_array($_SESSION['shared_files'])) {
            return;
        }

        $now = time();
        foreach ($_SESSION['shared_files'] as $token => $entry) {
            $createdAt = (int) ($entry['created_at'] ?? 0);
            if ($createdAt < 1 || ($now - $createdAt) > $this->ttlSeconds || !$this->isSafeSharedPath($entry['stored_file'] ?? '')) {
                $this->deleteSharedFile($entry);
                unset($_SESSION['shared_files'][$token]);
            }
        }
    }

    private function shareDir()
    {
        $dir = (string) ($_ENV['SHARE_TARGET_PATH'] ?? dirname(__DIR__, 2) . '/storage/share-target');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private function isSafeSharedPath($path)
    {
        $base = realpath($this->shareDir());
        $real = is_string($path) ? realpath($path) : false;
        if ($base === false || $real === false) {
            return false;
        }

        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
        $real = str_replace('\\', '/', $real);

        return str_starts_with($real, $base);
    }

    private function deleteSharedFile($entry)
    {
        $path = is_array($entry) ? (string) ($entry['stored_file'] ?? '') : '';
        if ($path !== '' && $this->isSafeSharedPath($path) && is_file($path)) {
            @unlink($path);
        }
    }

    private function validateCsrfOrFail()
    {
        $token = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (!is_string($token) || !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
            http_response_code(419);
            exit('Token CSRF inválido');
        }
    }

    private function flashAndRedirect($message, $success, $target)
    {
        $_SESSION['flash'] = $message;
        $_SESSION['flash_type'] = $success ? 'success' : 'error';
        header('Location: ' . $target);
        exit;
    }

    private function formatBytes($bytes)
    {
        $bytes = max(0, (int) $bytes);
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 2, ',', '.') . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        }

        return $bytes . ' B';
    }

    private function safeMessage($message)
    {
        $message = trim(strip_tags((string) $message));
        $message = preg_replace('/\s+/', ' ', $message);

        return strlen($message) > 180 ? substr($message, 0, 180) . '...' : ($message ?: 'erro interno');
    }

    private function shareUploadMessage(UploadValidationException $e)
    {
        if ($e->category() !== 'arquivo_ausente') {
            return $e->getMessage();
        }

        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 0) {
            return 'O servidor recebeu ' . $this->formatBytes($contentLength) . ', mas o PHP descartou o arquivo antes da validação. Aumente upload_max_filesize e post_max_size no servidor e reinicie o PHP/Apache.';
        }

        return 'Nenhum arquivo chegou ao servidor. Abra o documento no aplicativo e compartilhe o arquivo em si, não apenas o link ou texto.';
    }

    private function logShareTargetIssue($message)
    {
        $logPath = (string) ($_ENV['LOG_PATH'] ?? '');
        if ($logPath === '') {
            return;
        }

        $dir = dirname($logPath);
        if ($dir && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $contentLength = preg_replace('/\D+/', '', (string) ($_SERVER['CONTENT_LENGTH'] ?? ''));
        $contentType = $this->safeMessage((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        $fields = $this->safeMessage(implode(',', array_keys($_FILES)));
        $line = sprintf(
            "%s | SHARE_TARGET: %s | content_length=%s | content_type=%s | file_fields=%s\n",
            date('Y-m-d H:i:s'),
            $this->safeMessage($message),
            $contentLength !== '' ? $contentLength : '-',
            $contentType !== '' ? $contentType : '-',
            $fields !== '' ? $fields : '-'
        );

        @file_put_contents($logPath, $line, FILE_APPEND);
    }
}
