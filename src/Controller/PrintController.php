<?php
require_once __DIR__ . '/../Service/PrintService.php';
require_once __DIR__ . '/../Service/PageCounter.php';
require_once __DIR__ . '/../Service/QuotaService.php';
require_once __DIR__ . '/../Service/Database.php';
require_once __DIR__ . '/../Service/PrintJobService.php';
require_once __DIR__ . '/../Service/CupsService.php';

class UploadValidationException extends RuntimeException
{
    private $category;

    public function __construct($message, $category = 'arquivo_invalido')
    {
        parent::__construct($message);
        $this->category = $category;
    }

    public function category()
    {
        return $this->category;
    }
}

class PrintController
{
    private $allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp', 'txt'];
    private $maxUploadBytes = 20 * 1024 * 1024;

    public function allowedExtensions()
    {
        return $this->allowedExtensions;
    }

    public function maxUploadBytes()
    {
        return max(1, (int) ($_ENV['MAX_UPLOAD_BYTES'] ?? $this->maxUploadBytes));
    }

    public function maxUploadMegabytes()
    {
        return (int) ceil($this->maxUploadBytes() / 1024 / 1024);
    }

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

    private function hasAllowedMimeType($tmpPath, $ext, $mime = null)
    {
        if (!is_file($tmpPath)) {
            return false;
        }

        if ($mime === null && !function_exists('finfo_open')) {
            return true;
        }

        if ($mime === null) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo === false) {
                return true;
            }
            $mime = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
        }

        $allowedByExtension = [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword', 'application/x-ole-storage', 'application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png', 'image/x-png', 'application/png', 'application/octet-stream'],
            'webp' => ['image/webp'],
            'txt' => ['text/plain'],
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

    public function validateUploadFile($file)
    {
        if (!is_array($file)) {
            throw new UploadValidationException(
                'Arquivo não enviado. Verifique upload_max_filesize e post_max_size no servidor.',
                'arquivo_ausente'
            );
        }

        if (isset($file['name']) && is_array($file['name'])) {
            $file = [
                'name' => $file['name'][0] ?? '',
                'type' => $file['type'][0] ?? '',
                'tmp_name' => $file['tmp_name'][0] ?? '',
                'error' => $file['error'][0] ?? UPLOAD_ERR_NO_FILE,
                'size' => $file['size'][0] ?? 0,
            ];
        }

        $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new UploadValidationException($this->uploadErrorMessage($errorCode), 'falha_upload');
        }

        $origName = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'arquivo')));
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $size = (int) ($file['size'] ?? 0);

        if ($size < 1 || $size > $this->maxUploadBytes()) {
            throw new UploadValidationException('Arquivo inválido ou acima de ' . $this->maxUploadMegabytes() . 'MB', 'arquivo_invalido');
        }

        if ($ext === '' || !in_array($ext, $this->allowedExtensions, true)) {
            throw new UploadValidationException('Tipo de arquivo não permitido', 'formato_nao_suportado');
        }

        $mimeType = $this->detectMimeType($tmpPath);
        if (!$this->hasAllowedMimeType($tmpPath, $ext, $mimeType)) {
            $this->logUploadFailure("MIME incompatível: nome={$origName} ext={$ext} mime={$mimeType}");
            throw new UploadValidationException('Formato de arquivo não suportado', 'formato_nao_suportado');
        }

        return [
            'original_name' => $origName !== '' ? $origName : 'arquivo.' . $ext,
            'tmp_path' => $tmpPath,
            'extension' => $ext,
            'size' => $size,
            'mime_type' => $mimeType,
        ];
    }

    public function storeUploadedFile($file, $targetDir)
    {
        $info = $this->validateUploadFile($file);
        $dest = $this->targetPathForOriginalName($targetDir, $info['original_name']);

        if (!move_uploaded_file($info['tmp_path'], $dest)) {
            throw new UploadValidationException('Erro ao salvar arquivo', 'falha_armazenamento');
        }

        $info['stored_file'] = $dest;
        return $info;
    }

    public function moveExistingFileToUploadPath($sourcePath, $originalName)
    {
        if (!is_file($sourcePath)) {
            throw new RuntimeException('Arquivo compartilhado não encontrado');
        }

        $dest = $this->targetPathForOriginalName($_ENV['UPLOAD_PATH'] ?? '', $originalName);
        if (!@rename($sourcePath, $dest)) {
            if (!@copy($sourcePath, $dest)) {
                throw new RuntimeException('Não foi possível preparar o arquivo compartilhado para impressão');
            }
            @unlink($sourcePath);
        }

        return $dest;
    }

    private function targetPathForOriginalName($targetDir, $originalName)
    {
        $targetDir = rtrim((string) $targetDir, "\\/");
        if ($targetDir === '') {
            throw new UploadValidationException('UPLOAD_PATH não configurado', 'falha_configuracao');
        }

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true)) {
            throw new UploadValidationException('Erro ao preparar pasta de upload', 'falha_armazenamento');
        }

        $baseDir = realpath($targetDir);
        if ($baseDir === false || !is_dir($baseDir)) {
            throw new UploadValidationException('Pasta de upload inválida', 'falha_configuracao');
        }

        $filename = bin2hex(random_bytes(12)) . '_' . $this->sanitizeFilename($originalName);
        $dest = $baseDir . DIRECTORY_SEPARATOR . $filename;
        $normalizedBase = rtrim(str_replace('\\', '/', $baseDir), '/') . '/';
        $normalizedDest = str_replace('\\', '/', $dest);
        if (!str_starts_with($normalizedDest, $normalizedBase)) {
            throw new UploadValidationException('Caminho de upload inválido', 'falha_armazenamento');
        }

        return $dest;
    }

    public function formContext()
    {
        $userList = [];

        if (!isset($_SESSION['user'])) {
            return ['userList' => $userList];
        }

        if (($_SESSION['role'] ?? '') === 'admin') {
            $db = Database::connect();
            $result = $db->query("SELECT name, cpf FROM users ORDER BY name");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $userList[] = ['name' => $row['name'], 'cpf' => $row['cpf']];
            }
        }

        return [
            'userList' => $userList,
            'printerStatus' => (new CupsService())->diagnostics(true),
            'maxUploadMb' => $this->maxUploadMegabytes(),
            'allowedExtensions' => $this->allowedExtensions,
        ];
    }

    public function handle()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $context = $this->formContext();
        $userList = $context['userList'] ?? [];

        if (!isset($_SESSION['user'])) {
            return $context;
        }

        $isAdmin = (($_SESSION['role'] ?? '') === 'admin');

        // Se GET, retorna dados para a view (lista de usuários)
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $context;
        }

        // ============================
        // === Processamento POST ====
        // ============================
        $this->validateCsrfOrFail();

        // Determina usuário final da impressão
        $user = $this->resolvePrintUser($isAdmin, $userList);
        $jobService = new PrintJobService();

        try {
            $fileInfo = $this->storeUploadedFile($_FILES['arquivo'] ?? null, $_ENV['UPLOAD_PATH'] ?? '');
        } catch (UploadValidationException $e) {
            $rawFile = $_FILES['arquivo'] ?? null;
            $originalName = 'arquivo';
            if (is_array($rawFile)) {
                $rawName = $rawFile['name'] ?? 'arquivo';
                $originalName = is_array($rawName) ? (string) ($rawName[0] ?? 'arquivo') : (string) $rawName;
            }
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $rawSize = is_array($rawFile) ? ($rawFile['size'] ?? 0) : 0;
            $size = is_array($rawSize) ? (int) ($rawSize[0] ?? 0) : (int) $rawSize;
            $this->registerFailedAttempt($jobService, $user, $e->category(), $e->getMessage(), $originalName, null, $extension, $size);
            $this->fail($e->getMessage());
        }

        $this->logUploadFailure("Upload recebido: nome={$fileInfo['original_name']} ext={$fileInfo['extension']} size={$fileInfo['size']}");

        $this->processSavedFile(
            $fileInfo['stored_file'],
            $fileInfo['original_name'],
            $fileInfo['extension'],
            (int) $fileInfo['size'],
            (string) $fileInfo['mime_type'],
            $user,
            $jobService
        );
    }

    public function printSavedFile($dest, $origName, $ext, $size, $mimeType)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $this->validateCsrfOrFail();

        $context = $this->formContext();
        $userList = $context['userList'] ?? [];
        $isAdmin = (($_SESSION['role'] ?? '') === 'admin');
        $user = $this->resolvePrintUser($isAdmin, $userList);
        $this->processSavedFile($dest, $origName, $ext, $size, $mimeType, $user, new PrintJobService());
    }

    private function processSavedFile($dest, $origName, $ext, $size, $mimeType, $user, $jobService)
    {

        // Parâmetros de impressão vindos do formulário
        $copies = max(1, intval($_POST['copies'] ?? 1));
        $sides = ($_POST['sides'] ?? 'one-sided'); // "one-sided" ou "two-sided-short-edge"/"long-edge"
        $orientation = $this->normalizeOrientation($_POST['orientation'] ?? 'auto');
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
        $sourceExt = strtolower(pathinfo($dest, PATHINFO_EXTENSION));
        $printer = new PrintService();
        if ($orientation === 'auto' && in_array($sourceExt, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $detectedOrientation = $printer->detectImageOrientation($dest);
            if ($detectedOrientation !== null) {
                $orientation = $detectedOrientation;
            }
        } elseif ($orientation === 'auto' && $sourceExt === 'pdf') {
            $detectedOrientation = $printer->detectPdfOrientation($dest);
            if ($detectedOrientation !== null) {
                $orientation = $detectedOrientation;
            }
        }
        $paper = $extraOptions['media'] ?? $extraOptions['paper'] ?? 'A4';
        $jobId = $jobService->create($user, $origName, $dest, $sourceExt, $copies, $numberUp, $sides, $orientation, $paper, [
            'mime_type' => $mimeType,
            'file_size' => $size,
            'printer' => $_ENV['PRINTER_NAME'] ?? '',
        ]);
        $printedFile = $dest;
        $pages = 0;
        $chargedPages = 0;
        $completed = false;
        $errorMessage = '';
        $billableSourcePages = 0;
        $printedPagesLabel = '';
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
            if ($orientation === 'auto' && in_array($sourceExt, ['pdf', 'doc', 'docx', 'txt'], true)) {
                $detectedOrientation = $printer->detectPdfOrientation($printedFile);
                if ($detectedOrientation !== null) {
                    $printer->log('Documento: orientacao automatica ' . $detectedOrientation . ' aplicada no lugar de ' . $orientation . ' arquivo=' . $printedFile);
                    $orientation = $detectedOrientation;
                    $jobService->updateOrientation($jobId, $orientation);
                }
            }
            $billableSourcePages = $this->selectedPageCount($pages, $extraOptions);
            $printedPagesLabel = $this->printedPagesLabel($pages, $extraOptions, $billableSourcePages);
            $jobService->updateSelection($jobId, $printedPagesLabel, $billableSourcePages);
            $printOptions = $extraOptions;
            if ($this->hasPageSelection($extraOptions)) {
                $selectedFile = $printer->selectPdfPages($printedFile, $pages, $extraOptions);
                if ($selectedFile !== null) {
                    $printedFile = $selectedFile;
                    unset($printOptions['page-ranges'], $printOptions['page-set']);
                    $printer->log('Selecao de paginas aplicada antes do number-up: selecionadas=' . $billableSourcePages . ' arquivo=' . $printedFile);
                } elseif ($numberUp > 1) {
                    throw new RuntimeException('Nao foi possivel preparar o intervalo de paginas antes do modo ' . $numberUp . ' por folha. Instale qpdf, Ghostscript ou poppler-utils no servidor.');
                }
            }
            $chargedPages = $this->billablePages($billableSourcePages, $copies, $numberUp);
            $jobService->markProcessing($jobId, $printedFile, $pages, $chargedPages);

            $completed = $printer->printPrepared($printedFile, $sourceExt, $copies, $sides, $orientation, $quality, $numberUp, $printOptions);
            $jobService->updateCupsResult($jobId, $printer->lastPrintResult());
            $success = $completed === true;
        } catch (Throwable $e) {
            $printer->log("Erro interno ao imprimir: " . $e->getMessage());
            $errorMessage = $this->safeErrorMessage($e->getMessage());
            $jobService->updateCupsResult($jobId, $printer->lastPrintResult());
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
            $printResult = $printer->lastPrintResult();
            $printedPagesLabel = $printedPagesLabel ?: $this->printedPagesLabel($pages, $extraOptions, $billableSourcePages ?: $pages);
            $sourceLabel = ($billableSourcePages ?: $pages) === 1 ? '1 página selecionada' : (($billableSourcePages ?: $pages) . ' páginas selecionadas');
            $copiesLabel = $copies === 1 ? '1 cópia' : "{$copies} cópias";
            $chargedLabel = $chargedPages === 1 ? '1 contabilizada' : "{$chargedPages} contabilizadas";
            $cupsConfirmation = $this->cupsConfirmationMessage($printResult['status_cups'] ?? '');
            $msg = "Impressão concluída. {$printedPagesLabel}; {$sourceLabel}; {$numberUp} por folha; {$copiesLabel}; {$chargedLabel}. {$cupsConfirmation}.";
        } elseif ($completed === false && $pages > 0 && $errorMessage === '') {
            $msg = "A impressão não foi confirmada. Documento com {$pages} página(s), {$copies} cópia(s). Nada foi contabilizado.";
        } else {
            $msg = "Não foi possível enviar a impressão" . ($errorMessage ? ": {$errorMessage}" : ". Verifique o aviso da impressora ou informe a equipe de TI.");
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
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > $this->maxUploadBytes()) {
            $this->respond('Arquivo inválido ou acima de ' . $this->maxUploadMegabytes() . 'MB', false);
        }
        if (!$this->hasAllowedMimeType($file['tmp_name'], $ext)) {
            $this->respond('Formato de arquivo não reconhecido para pré-contagem', false);
        }

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
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
            $orientation = $this->normalizeOrientation($_POST['orientation'] ?? 'auto');
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
            $advice = $this->largeDocumentAdvice($pages);

            $this->respond($message, true, [
                'pages' => $pages,
                'original_pages' => $originalPages,
                'converted_pages' => $convertedPages,
                'warning' => $warning,
                'advice' => $advice,
            ]);
        } catch (Throwable $e) {
            $this->respond('Não foi possível contar as páginas: ' . $this->safeErrorMessage($e->getMessage()), false);
        } finally {
            @unlink($tempFile);
            if ($preparedFile !== $tempFile) {
                @unlink($preparedFile);
            }
        }
    }

    public function preview()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['user'])) {
            http_response_code(401);
            exit('Sessão expirada. Faça login novamente.');
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            exit('Método inválido');
        }

        $this->validateCsrfOrFail();

        if (!isset($_FILES['arquivo']) || ($_FILES['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            http_response_code(400);
            exit('Arquivo não enviado. Verifique upload_max_filesize e post_max_size no servidor.');
        }

        $file = $_FILES['arquivo'];
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'doc', 'docx', 'txt'], true)) {
            http_response_code(400);
            exit('Pré-visualização disponível apenas para PDF, DOC, DOCX e TXT.');
        }

        $size = intval($file['size'] ?? 0);
        if ($size < 1 || $size > $this->maxUploadBytes()) {
            http_response_code(400);
            exit('Arquivo inválido ou acima de ' . $this->maxUploadMegabytes() . 'MB.');
        }

        if (!$this->hasAllowedMimeType($file['tmp_name'], $ext)) {
            http_response_code(400);
            exit('Formato de arquivo não reconhecido para pré-visualização.');
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'preview_') . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $tempFile)) {
            http_response_code(500);
            exit('Erro ao preparar arquivo para pré-visualização.');
        }

        $previewFile = $tempFile;
        try {
            if (in_array($ext, ['doc', 'docx', 'txt'], true)) {
                $printer = new PrintService();
                $paper = $_POST['paper'] ?? 'A4';
                $orientation = $this->normalizeOrientation($_POST['orientation'] ?? 'auto');
                $extraOptions = [];
                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                    $field = 'margin_' . $side;
                    if (isset($_POST[$field]) && $_POST[$field] !== '' && is_numeric($_POST[$field])) {
                        $mm = max(0, min(100, (float) $_POST[$field]));
                        $extraOptions['page-' . $side] = (string) (int) round($mm * 72 / 25.4);
                    }
                }
                $previewFile = $printer->prepareFile($tempFile, $orientation, $paper, $extraOptions);
            }

            if (!is_file($previewFile)) {
                throw new RuntimeException('PDF de pré-visualização não foi gerado.');
            }

            header('Content-Type: application/pdf');
            header('Content-Length: ' . filesize($previewFile));
            header('Content-Disposition: inline; filename="' . $this->previewPdfName($file['name'] ?? 'documento.pdf') . '"');
            header('Cache-Control: no-store, private');
            readfile($previewFile);
        } catch (Throwable $e) {
            http_response_code(500);
            echo 'Não foi possível gerar a pré-visualização: ' . $this->safeErrorMessage($e->getMessage());
        } finally {
            @unlink($tempFile);
            if ($previewFile !== $tempFile) {
                @unlink($previewFile);
            }
        }

        exit;
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

    private function printedPagesLabel($totalPages, $extraOptions, $selectedCount)
    {
        $totalPages = max(1, (int) $totalPages);
        $selectedCount = max(1, (int) $selectedCount);
        $ranges = trim((string) ($extraOptions['page-ranges'] ?? ''));
        $pageSet = $extraOptions['page-set'] ?? '';

        if ($ranges !== '') {
            $label = 'Páginas ' . str_replace(',', ', ', preg_replace('/\s+/', '', $ranges));
        } else {
            $label = $totalPages === 1 ? 'Página 1' : "Páginas 1-{$totalPages}";
        }

        if ($pageSet === 'odd') {
            $label .= ' ímpares';
        } elseif ($pageSet === 'even') {
            $label .= ' pares';
        }

        if ($ranges === '' && $selectedCount >= $totalPages && $pageSet === '') {
            return $totalPages === 1 ? 'Página 1' : "Todas as páginas ({$totalPages})";
        }

        return $label;
    }

    private function cupsConfirmationMessage($statusCups)
    {
        return match ((string) $statusCups) {
            'completed' => 'O servidor confirmou a conclusão da impressão',
            'printer_fault' => 'O servidor detectou falha na impressora durante ou logo após o envio',
            'left_queue' => 'O trabalho foi enviado para a impressora',
            'accepted_unverified' => 'O trabalho foi enviado para a impressora',
            'accepted_unidentified' => 'O trabalho foi enviado para a impressora',
            'accepted' => 'O trabalho foi enviado para a impressora',
            default => 'Status CUPS: ' . ((string) $statusCups !== '' ? (string) $statusCups : 'não informado'),
        };
    }

    private function hasPageSelection($extraOptions)
    {
        return !empty($extraOptions['page-ranges'])
            || in_array($extraOptions['page-set'] ?? '', ['odd', 'even'], true);
    }

    private function largeDocumentAdvice($pages)
    {
        $pages = (int) $pages;
        $threshold = max(2, (int) ($_ENV['LARGE_DOCUMENT_PAGE_WARNING'] ?? 4));
        if ($pages < $threshold) {
            return '';
        }

        return 'Documento com muitas páginas. Tente imprimir em frente e verso ou 2 por folha.';
    }

    private function normalizeOrientation($orientation)
    {
        $orientation = (string) $orientation;
        return in_array($orientation, ['auto', 'portrait', 'landscape'], true) ? $orientation : 'auto';
    }

    private function resolvePrintUser($isAdmin, $userList)
    {
        if (!$isAdmin) {
            return $_SESSION['user'];
        }

        $target = preg_replace('/\D/', '', (string) ($_POST['target_user'] ?? ''));
        $search = trim((string) ($_POST['target_user_search'] ?? ''));

        if ($target === '' && $search === '') {
            return $_SESSION['user'];
        }

        foreach ($userList as $user) {
            $cpf = (string) ($user['cpf'] ?? '');
            $name = (string) ($user['name'] ?? '');
            if ($target !== '' && $target === $cpf) {
                return $cpf;
            }

            if ($search !== '' && $this->sameTeacherSearch($search, $name, $cpf)) {
                return $cpf;
            }
        }

        $this->fail('Selecione um professor válido da lista antes de imprimir.');
    }

    private function sameTeacherSearch($search, $name, $cpf)
    {
        $digits = preg_replace('/\D/', '', $search);
        if ($digits !== '' && $digits === $cpf) {
            return true;
        }

        $normalizedSearch = $this->normalizeTeacherSearch($search);
        if ($normalizedSearch === '') {
            return false;
        }

        return $normalizedSearch === $this->normalizeTeacherSearch($name)
            || $normalizedSearch === $this->normalizeTeacherSearch($name . ' - ' . $cpf);
    }

    private function normalizeTeacherSearch($value)
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $value);
        $value = $value === false ? '' : $value;
        $value = strtolower($value);
        $value = preg_replace('/\s+/', ' ', $value);

        return trim($value);
    }

    private function previewPdfName($name)
    {
        $base = pathinfo((string) $name, PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);
        $base = trim((string) $base, '._-');

        return ($base !== '' ? $base : 'documento') . '.pdf';
    }

    private function safeErrorMessage($message)
    {
        $message = trim(strip_tags((string) $message));
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $message = str_replace([dirname(__DIR__, 2), $root], '[projeto]', $message);
        $message = preg_replace('/\[projeto\][\\\\\/][^\s]+/', '[arquivo]', $message);
        $message = preg_replace('/[A-Z]:\\\\[^\s]+/i', '[arquivo]', $message);
        $message = preg_replace('/(^|\s)\/[^\s]+/', '$1[arquivo]', $message);
        $message = preg_replace('/\s+/', ' ', (string) $message);

        if ($message === '') {
            return 'erro interno';
        }

        return strlen($message) > 220 ? substr($message, 0, 220) . '...' : $message;
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
            UPLOAD_ERR_PARTIAL => "Envio incompleto",
            UPLOAD_ERR_NO_FILE => "Arquivo não enviado",
            UPLOAD_ERR_NO_TMP_DIR => "Pasta temporária do servidor não configurada",
            UPLOAD_ERR_CANT_WRITE => "Servidor não conseguiu salvar o arquivo enviado",
            UPLOAD_ERR_EXTENSION => "Envio bloqueado por extensão do PHP",
        ];

        return $messages[$code] ?? "Erro no envio do arquivo";
    }

    private function detectMimeType($filePath)
    {
        if (!is_file($filePath) || !function_exists('finfo_open')) {
            return '';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return '';
        }
        $mime = finfo_file($finfo, $filePath) ?: '';
        finfo_close($finfo);

        return $mime;
    }

    private function sanitizeFilename($name)
    {
        $base = basename((string) $name);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);
        $base = trim((string) $base, '._-');

        return $base !== '' ? $base : 'arquivo';
    }

    private function registerFailedAttempt($jobService, $user, $category, $message, $originalName = 'arquivo', $storedFile = null, $ext = '', $size = 0)
    {
        try {
            $jobId = $jobService->create($user, $originalName ?: 'arquivo', $storedFile, strtolower((string) $ext), 1, 1, 'one-sided', 'portrait', 'A4', [
                'file_size' => (int) $size,
                'mime_type' => $storedFile ? $this->detectMimeType($storedFile) : '',
                'printer' => $_ENV['PRINTER_NAME'] ?? '',
            ]);
            $jobService->markPreValidationFailed($jobId, $message, [
                'status_cups' => 'falha_pre_validacao',
                'error_category' => $category,
                'error_message' => $message,
            ]);
        } catch (Throwable $e) {
            $this->logUploadFailure('Falha ao registrar tentativa invalida: ' . $e->getMessage());
        }
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
