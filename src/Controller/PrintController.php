<?php
require_once __DIR__ . '/../Service/PrintService.php';
require_once __DIR__ . '/../Service/PageCounter.php';
require_once __DIR__ . '/../Service/QuotaService.php';
require_once __DIR__ . '/../Service/Database.php';
require_once __DIR__ . '/../Service/PrintJobService.php';

class PrintController
{
    private $allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
    private $maxUploadBytes = 20 * 1024 * 1024;

    private function validateCsrfOrFail()
    {
        $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $sessionToken = $_SESSION['csrf_token'] ?? '';

        if (is_string($token) && is_string($sessionToken) && $sessionToken !== '' && hash_equals($sessionToken, $token)) {
            return;
        }

        // fallback de compatibilidade: permite envio same-origin sem token explícito
        // para não bloquear impressões em navegadores/proxies que removem campos ocultos.
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $sameOrigin = ($origin !== '' && str_contains($origin, $host))
            || ($referer !== '' && str_contains($referer, $host));

        if ($sameOrigin) {
            $this->logUploadFailure('Aviso CSRF: token ausente/inválido aceito por fallback same-origin');
            return;
        }


        if (!is_string($token) || !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
            if ($this->isAjax()) {
                $this->respond('Token CSRF inválido', false);
            }
            http_response_code(419);
            exit('Token CSRF inválido');
        }
    }

    private function hasAllowedMimeType($tmpPath, $ext)
    {
        if (!is_file($tmpPath)) {
            return false;
        }

        if (!function_exists('finfo_open')) {
            return true;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return true;
        }
        $mime = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        $allowedByExtension = [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword', 'application/x-ole-storage', 'application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png', 'image/x-png', 'application/png', 'application/octet-stream'],
        ];

        if (!isset($allowedByExtension[$ext])) {
            return true;
        }

        if (in_array($mime, $allowedByExtension[$ext], true)) {
            return true;
        }

        // Alguns ambientes retornam MIME genérico para DOC/DOCX/PNG.
        if (in_array($ext, ['doc', 'docx', 'png'], true)) {
            return true;
        }

        return false;
    }

    public function handle()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $userList = [];

        // Se não logado, apenas retorna usuário (lista vazia)
        if (!isset($_SESSION['user'])) {
            return ['userList' => $userList];
        }

        $isAdmin = (($_SESSION['role'] ?? '') === 'admin');
        if ($isAdmin) {
            // Carrega lista de usuários (nome, cpf) para dropdown se for admin
            $db = Database::connect();
            $result = $db->query("SELECT name, cpf FROM users ORDER BY name");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $userList[] = ['name' => $row['name'], 'cpf' => $row['cpf']];
            }
        }

        // Se GET, retorna dados para a view (lista de usuários)
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return ['userList' => $userList];
        }

        // ============================
        // === Processamento POST ====
        // ============================
        $this->validateCsrfOrFail();

        // Validação inicial
        if (!isset($_FILES['arquivo'])) {
            $this->logUploadFailure('Arquivo ausente em $_FILES');
            $this->fail("Arquivo não enviado. Verifique upload_max_filesize e post_max_size no servidor.");
        }
        $file = $_FILES['arquivo'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->logUploadFailure('Erro de upload codigo=' . ($file['error'] ?? UPLOAD_ERR_NO_FILE));
            $this->fail($this->uploadErrorMessage($file['error'] ?? UPLOAD_ERR_NO_FILE));
        }

        $origName = $file['name'];
        $tmpPath = $file['tmp_name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $size = intval($file['size'] ?? 0);
        if ($size < 1 || $size > $this->maxUploadBytes) {
            $this->fail('Arquivo inválido ou acima de 20MB');
        }
        $this->logUploadFailure("Upload recebido: nome={$origName} ext={$ext} size={$size}");

        // Extensões permitidas
        if (!in_array($ext, $this->allowedExtensions, true)) {
            $this->fail("Tipo de arquivo não permitido");
        }
        if (!$this->hasAllowedMimeType($tmpPath, $ext)) {
            $this->logUploadFailure("Aviso de MIME incompatível: nome={$origName} ext={$ext}");
        }

        // Configura caminhos via env (ou config carregada)
        $uploadPath = $_ENV['UPLOAD_PATH'] ?? '';
        if (!$uploadPath) {
            $this->fail("UPLOAD_PATH não configurado");
        }
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0775, true);
        }
        $newFilename = uniqid() . '_' . basename($origName);
        $dest = rtrim($uploadPath, '/') . '/' . $newFilename;
        if (!move_uploaded_file($tmpPath, $dest)) {
            $this->fail("Erro ao salvar arquivo");
        }

        // Determina usuário final da impressão
        $cpfList = array_column($userList, 'cpf');
        if ($isAdmin && !empty($_POST['target_user']) && in_array($_POST['target_user'], $cpfList)) {
            $user = $_POST['target_user'];
        } else {
            $user = $_SESSION['user'];
        }

        // Parâmetros de impressão vindos do formulário
        $copies = max(1, intval($_POST['copies'] ?? 1));
        $sides = ($_POST['sides'] ?? 'one-sided'); // "one-sided" ou "two-sided-short-edge"/"long-edge"
        $orientation = ($_POST['orientation'] ?? 'portrait'); // "portrait" ou "landscape"
        $quality = intval($_POST['quality'] ?? 3);
        $allowedNumberUp = [1, 2, 4, 8];
        $numberUp = intval($_POST['number_up'] ?? 1);
        if (!in_array($numberUp, $allowedNumberUp, true)) {
            $numberUp = 1;
        }

        // Coleta opções extras (opt_*)
        $extraOptions = [];
        foreach ($_POST as $key => $val) {
            if (strpos($key, 'opt_') === 0) {
                $optKey = substr($key, 4);
                $extraOptions[$optKey] = $val;
            }
        }
        if (!empty($_POST['paper'])) {
            $extraOptions['media'] = $_POST['paper'];
        }
        if (!empty($_POST['scale'])) {
            if ($_POST['scale'] === 'fit') {
                $extraOptions['fit-to-page'] = 'true';
            } elseif ($_POST['scale'] === 'custom') {
                $scalePercent = (int) ($_POST['scale_percent'] ?? 100);
                if ($scalePercent >= 10 && $scalePercent <= 400) {
                    $extraOptions['scaling'] = (string) $scalePercent;
                }
            } elseif (is_numeric($_POST['scale'])) {
                $scalePercent = (int) $_POST['scale'];
                if ($scalePercent >= 10 && $scalePercent <= 400) {
                    $extraOptions['scaling'] = (string) $scalePercent;
                }
            }
        }
        if (!empty($_POST['page_ranges']) && $this->isValidPageRanges($_POST['page_ranges'])) {
            $extraOptions['page-ranges'] = preg_replace('/\s+/', '', $_POST['page_ranges']);
        }
        if (in_array($_POST['page_set'] ?? '', ['odd', 'even'], true)) {
            $extraOptions['page-set'] = $_POST['page_set'];
        }
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $field = 'margin_' . $side;
            if (isset($_POST[$field]) && $_POST[$field] !== '' && is_numeric($_POST[$field])) {
                $mm = max(0, min(100, (float) $_POST[$field]));
                $extraOptions['page-' . $side] = (string) (int) round($mm * 72 / 25.4);
            }
        }

        // Prepara, conta paginas e envia para impressao usando o mesmo arquivo convertido.
        $printer = new PrintService();
        $jobService = new PrintJobService();
        $sourceExt = strtolower(pathinfo($dest, PATHINFO_EXTENSION));
        $paper = $extraOptions['media'] ?? $extraOptions['paper'] ?? 'A4';
        $jobId = $jobService->create($user, $origName, $dest, $sourceExt, $copies, $numberUp, $sides, $orientation, $paper);
        $printedFile = $dest;
        $pages = 0;
        $chargedPages = 0;
        $completed = false;
        $errorMessage = '';
        try {
            $originalPages = $sourceExt === 'docx' ? PageCounter::countDocxPages($dest) : 0;
            $printedFile = $printer->prepareFile($dest, $orientation, $paper, $extraOptions);
            $convertedPages = PageCounter::count($printedFile);
            $pages = $convertedPages;
            if ($pages < 1) {
                $pages = $originalPages > 0 ? $originalPages : 1;
            }
            if ($sourceExt === 'docx' && $originalPages > 0 && $convertedPages > 0 && $convertedPages !== $originalPages) {
                $printer->log("DOCX metadado de paginas diverge do PDF convertido: metadado={$originalPages} convertido={$convertedPages} arquivo={$dest}");
            }
            $billableSourcePages = $this->selectedPageCount($pages, $extraOptions);
            $chargedPages = $this->billablePages($billableSourcePages, $copies, $numberUp);
            $jobService->markProcessing($jobId, $printedFile, $pages, $chargedPages);

            $completed = $printer->printPrepared($printedFile, $sourceExt, $copies, $sides, $orientation, $quality, $numberUp, $extraOptions);
            $success = $completed === true;
        } catch (Throwable $e) {
            $printer->log("Erro interno ao imprimir: " . $e->getMessage());
            $errorMessage = $e->getMessage();
            $success = false;
        }

        if ($chargedPages < 1 && $pages > 0) {
            $chargedPages = $this->billablePages($this->selectedPageCount($pages, $extraOptions), $copies, $numberUp);
        }

        if ($success) {
            $jobService->markCompleted($jobId, $printedFile, $pages, $chargedPages);
        } else {
            $jobService->markFailed($jobId, $printedFile, $pages, $chargedPages, $errorMessage ?: 'Impressao nao concluida');
        }

        // Registra no banco apenas quando a impressao foi confirmada.
        if ($success) {
            $quota = new QuotaService();
            $quota->register($user, $chargedPages, $dest);
        }

        // Grava log customizado
        $this->log($user, $dest, $pages, $copies, $success);

        // Mensagem de retorno
        if ($success) {
            $msg = "Impressão concluída ({$pages} páginas, {$numberUp} por folha, {$copies} cópias = {$chargedPages} contabilizadas)";
        } elseif ($completed === false && $pages > 0 && $errorMessage === '') {
            $msg = "Impressão cancelada ou não concluída ({$pages} páginas x {$copies} cópias). Nada foi contabilizado.";
        } else {
            $msg = "Erro ao enviar impressão" . ($errorMessage ? ": {$errorMessage}" : "");
        }

        // Resposta AJAX vs Flash
        if ($this->isAjax()) {
            $this->respond($msg, $success, [
                'pages' => $pages,
                'copies' => $copies,
                'total_pages' => $success ? $chargedPages : 0,
                'counted' => $success,
            ]);
        } else {
            $this->flash($msg, $success);
        }
    }

    public function pageCount()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['user'])) {
            $this->respond('Sessão expirada. Faça login novamente.', false);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond('Método inválido', false);
        }
        $this->validateCsrfOrFail();

        if (!isset($_FILES['arquivo']) || ($_FILES['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->respond('Arquivo não enviado. Verifique upload_max_filesize e post_max_size no servidor.', false);
        }

        $file = $_FILES['arquivo'];
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowedExtensions, true)) {
            $this->respond('Tipo de arquivo não permitido', false);
        }
        if (!$this->hasAllowedMimeType($file['tmp_name'], $ext)) {
            $this->respond('Formato de arquivo não reconhecido para pré-contagem', false);
        }

        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            $this->respond('Documento com 1 página', true, [
                'pages' => 1,
            ]);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'count_') . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $tempFile)) {
            $this->respond('Erro ao preparar arquivo para contagem', false);
        }

        $preparedFile = $tempFile;
        try {
            $printer = new PrintService();
            $paper = $_POST['paper'] ?? 'A4';
            $orientation = $_POST['orientation'] ?? 'portrait';
            $extraOptions = [];
            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                $field = 'margin_' . $side;
                if (isset($_POST[$field]) && $_POST[$field] !== '' && is_numeric($_POST[$field])) {
                    $mm = max(0, min(100, (float) $_POST[$field]));
                    $extraOptions['page-' . $side] = (string) (int) round($mm * 72 / 25.4);
                }
            }
            $originalPages = $ext === 'docx' ? PageCounter::countDocxPages($tempFile) : 0;
            $preparedFile = $printer->prepareFile($tempFile, $orientation, $paper, $extraOptions);
            $convertedPages = PageCounter::count($preparedFile);
            $pages = $convertedPages;
            if ($pages < 1) {
                $pages = $originalPages > 0 ? $originalPages : 1;
            }

            $message = "Documento com {$pages} " . ($pages === 1 ? 'página' : 'páginas');
            $warning = '';
            if ($originalPages > 0 && $convertedPages > $originalPages) {
                $warning = "O DOCX declara {$originalPages} " . ($originalPages === 1 ? 'página' : 'páginas') . ", mas a conversão gerou {$convertedPages}.";
            }

            $this->respond($message, true, [
                'pages' => $pages,
                'original_pages' => $originalPages,
                'converted_pages' => $convertedPages,
                'warning' => $warning,
            ]);
        } catch (Throwable $e) {
            $this->respond('Não foi possível contar as páginas: ' . $e->getMessage(), false);
        } finally {
            @unlink($tempFile);
            if ($preparedFile !== $tempFile) {
                @unlink($preparedFile);
            }
        }
    }

    // Detecta requisição AJAX (XMLHttpRequest)
    private function isAjax()
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    private function billablePages($pages, $copies, $numberUp)
    {
        $pages = max(1, (int) $pages);
        $copies = max(1, (int) $copies);
        $numberUp = max(1, (int) $numberUp);

        return (int) ceil($pages / $numberUp) * $copies;
    }

    private function isValidPageRanges($value)
    {
        $value = preg_replace('/\s+/', '', (string) $value);
        if ($value === '' || !preg_match('/^\d+(-\d+)?(,\d+(-\d+)?)*$/', $value)) {
            return false;
        }

        foreach (explode(',', $value) as $part) {
            if (str_contains($part, '-')) {
                [$start, $end] = array_map('intval', explode('-', $part, 2));
                if ($start < 1 || $end < $start) {
                    return false;
                }
            } elseif ((int) $part < 1) {
                return false;
            }
        }

        return true;
    }

    private function selectedPageCount($totalPages, $extraOptions)
    {
        $totalPages = max(1, (int) $totalPages);
        $selected = [];

        if (!empty($extraOptions['page-ranges']) && $this->isValidPageRanges($extraOptions['page-ranges'])) {
            foreach (explode(',', $extraOptions['page-ranges']) as $part) {
                if (str_contains($part, '-')) {
                    [$start, $end] = array_map('intval', explode('-', $part, 2));
                } else {
                    $start = $end = (int) $part;
                }

                for ($page = max(1, $start); $page <= min($totalPages, $end); $page++) {
                    $selected[$page] = true;
                }
            }
        } else {
            for ($page = 1; $page <= $totalPages; $page++) {
                $selected[$page] = true;
            }
        }

        if (($extraOptions['page-set'] ?? '') === 'odd') {
            $selected = array_filter($selected, fn($on, $page) => ((int) $page) % 2 === 1, ARRAY_FILTER_USE_BOTH);
        } elseif (($extraOptions['page-set'] ?? '') === 'even') {
            $selected = array_filter($selected, fn($on, $page) => ((int) $page) % 2 === 0, ARRAY_FILTER_USE_BOTH);
        }

        return max(1, count($selected));
    }

    // Redireciona com flash message na sessão
    private function flash($msg, $success = true)
    {
        $_SESSION['flash'] = $msg;
        $_SESSION['flash_type'] = $success ? 'success' : 'error';
        header("Location: /");
        exit;
    }

    private function fail($msg)
    {
        if ($this->isAjax()) {
            $this->respond($msg, false);
        }

        $this->flash($msg, false);
    }

    // Responde em JSON (usado para AJAX)
    private function respond($msg, $success = true, $extra = [])
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['success' => $success, 'message' => $msg], $extra));
        exit;
    }

    private function uploadErrorMessage($code)
    {
        $messages = [
            UPLOAD_ERR_INI_SIZE => "Arquivo maior que o limite permitido pelo servidor",
            UPLOAD_ERR_FORM_SIZE => "Arquivo maior que o limite permitido pelo formulário",
            UPLOAD_ERR_PARTIAL => "Upload incompleto",
            UPLOAD_ERR_NO_FILE => "Arquivo não enviado",
            UPLOAD_ERR_NO_TMP_DIR => "Pasta temporária do servidor não configurada",
            UPLOAD_ERR_CANT_WRITE => "Servidor não conseguiu salvar o arquivo enviado",
            UPLOAD_ERR_EXTENSION => "Upload bloqueado por extensão do PHP",
        ];

        return $messages[$code] ?? "Erro no upload do arquivo";
    }

    private function logUploadFailure($msg)
    {
        $logPath = $_ENV['LOG_PATH'] ?? '';
        if (!$logPath) {
            return;
        }

        if (is_dir($logPath)) {
            $logPath = rtrim($logPath, "\\/") . DIRECTORY_SEPARATOR . 'app.log';
        }

        @file_put_contents(
            $logPath,
            date('Y-m-d H:i:s') . " | UPLOAD: {$msg} | upload_max_filesize=" . ini_get('upload_max_filesize') . " | post_max_size=" . ini_get('post_max_size') . "\n",
            FILE_APPEND
        );
    }

    // Log customizado de impressão (opcional)
    private function log($user, $file, $pages, $copies, $status)
    {
        $logPath = $_ENV['LOG_PATH'] ?? '';
        if (!$logPath)
            return;
        $line = sprintf(
            "%s | USER: %s | FILE: %s | COPIES: %d | PAGES: %d | STATUS: %s\n",
            date('Y-m-d H:i:s'),
            $user,
            $file,
            $copies,
            $pages,
            $status ? "OK" : "FAIL"
        );
        @file_put_contents($logPath, $line, FILE_APPEND);
    }
}
