<?php
require_once __DIR__ . '/../Service/PrintService.php';
require_once __DIR__ . '/../Service/PageCounter.php';
require_once __DIR__ . '/../Service/QuotaService.php';
require_once __DIR__ . '/../Service/Database.php';
require_once __DIR__ . '/../Service/PrintJobService.php';
require_once __DIR__ . '/../Service/CupsService.php';
require_once __DIR__ . '/../Service/TemporaryPrintFileService.php';

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
    private $maxUploadBytes = 100 * 1024 * 1024;

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

    public function savedFilePageInfo($filePath, $ext, $paper = 'A4', $orientation = 'auto', $extraOptions = [])
    {
        $filePath = (string) $filePath;
        $ext = strtolower((string) $ext);
        $paper = in_array((string) $paper, ['A4', 'Letter'], true) ? (string) $paper : 'A4';
        $orientation = $this->normalizeOrientation($orientation);

        if (!is_file($filePath)) {
            return [
                'success' => false,
                'message' => 'Arquivo não encontrado para contagem de páginas.',
            ];
        }

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return [
                'success' => true,
                'pages' => 1,
                'original_pages' => 0,
                'converted_pages' => 1,
                'warning' => '',
                'advice' => '',
            ];
        }

        $preparedFile = $filePath;
        try {
            $printer = new PrintService();
            $originalPages = $ext === 'docx' ? PageCounter::countDocxPages($filePath) : 0;
            $preparedFile = $printer->prepareFile($filePath, $orientation, $paper, is_array($extraOptions) ? $extraOptions : []);
            $convertedPages = PageCounter::count($preparedFile);
            $pages = $convertedPages;
            if ($pages < 1) {
                $pages = $originalPages > 0 ? $originalPages : 1;
            }

            $warning = '';
            if ($originalPages > 0 && $convertedPages > $originalPages) {
                $warning = "O DOCX declara {$originalPages} " . ($originalPages === 1 ? 'página' : 'páginas') . ", mas a conversão gerou {$convertedPages}.";
            }

            return [
                'success' => true,
                'pages' => $pages,
                'original_pages' => $originalPages,
                'converted_pages' => $convertedPages,
                'warning' => $warning,
                'large_document' => $this->isLargeDocument($pages),
                'advice' => $this->largeDocumentAdvice($pages),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Não foi possível contar as páginas: ' . $this->safeErrorMessage($e->getMessage()),
            ];
        } finally {
            if ($preparedFile !== $filePath) {
                @unlink($preparedFile);
            }
        }
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

        $uploadToken = trim((string) ($_POST['upload_token'] ?? ''));
        if ($uploadToken !== '') {
            try {
                $temporary = new TemporaryPrintFileService();
                $entry = $temporary->entry($uploadToken);
                $orientation = $this->normalizeOrientation($_POST['orientation'] ?? 'auto');
                $paper = $this->normalizedPaper($_POST['paper'] ?? 'A4');
                $extraOptions = $this->requestExtraOptions();
                $preparedHint = $temporary->existingPreparedPdf($uploadToken, $orientation, $paper, $extraOptions);
                $dest = $this->targetPathForOriginalName($_ENV['UPLOAD_PATH'] ?? '', $entry['original_name']);
                $temporary->moveSourceTo($uploadToken, $dest);
                register_shutdown_function(static function () use ($temporary, $uploadToken) {
                    $temporary->destroy($uploadToken);
                });
                $this->processSavedFile(
                    $dest,
                    $entry['original_name'],
                    $entry['extension'],
                    (int) $entry['size'],
                    (string) $entry['mime_type'],
                    $user,
                    $jobService,
                    $preparedHint
                );
                return $context;
            } catch (Throwable $e) {
                $this->registerFailedAttempt($jobService, $user, 'arquivo_temporario', $e->getMessage());
                $this->fail('O arquivo temporário expirou ou não está mais disponível. Selecione-o novamente.');
            }
        }

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
        $preparedHint = null;
        $uploadToken = trim((string) ($_POST['upload_token'] ?? ''));
        if ($uploadToken !== '') {
            try {
                $temporary = new TemporaryPrintFileService();
                $preparedHint = $temporary->existingPreparedPdf(
                    $uploadToken,
                    $this->normalizeOrientation($_POST['orientation'] ?? 'auto'),
                    $this->normalizedPaper($_POST['paper'] ?? 'A4'),
                    $this->requestExtraOptions()
                );
                register_shutdown_function(static function () use ($temporary, $uploadToken) {
                    $temporary->destroy($uploadToken);
                });
            } catch (Throwable $e) {
                $preparedHint = null;
            }
        }
        $this->processSavedFile($dest, $origName, $ext, $size, $mimeType, $user, new PrintJobService(), $preparedHint);
    }

    private function processSavedFile($dest, $origName, $ext, $size, $mimeType, $user, $jobService, $preparedFileHint = null)
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

        $extraOptions = $this->requestExtraOptions();

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
            $printedFile = is_string($preparedFileHint) && is_file($preparedFileHint)
                ? $preparedFileHint
                : $printer->prepareFile($dest, $orientation, $paper, $extraOptions);
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
            if ($this->shouldApplyLargeDocumentLayout($pages, $numberUp, $sides)) {
                $numberUp = 2;
                $jobService->updateLayout($jobId, $numberUp, $sides);
                $printer->log('Documento grande: aplicado automaticamente 2 por folha. paginas=' . $pages . ' arquivo=' . $printedFile);
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

    public function uploadTemporary()
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

        $temporary = new TemporaryPrintFileService();
        try {
            $temporary->cleanupExpired();
            $fileInfo = $this->validateUploadFile($_FILES['arquivo'] ?? null);
            $entry = $temporary->createFromUpload($fileInfo);
            $pageInfo = $this->temporaryPageInfo(
                $entry['token'],
                $_POST['paper'] ?? 'A4',
                $_POST['orientation'] ?? 'auto',
                $this->requestExtraOptions()
            );
            $this->respond('Arquivo recebido e validado.', true, array_merge($pageInfo, [
                'upload_token' => $entry['token'],
                'original_name' => $entry['original_name'],
                'extension' => $entry['extension'],
                'size' => (int) $entry['size'],
                'expires_in' => max(60, (int) ($_ENV['PRINT_PREVIEW_TTL_SECONDS'] ?? 1800)),
            ]));
        } catch (UploadValidationException $e) {
            $this->respond($e->getMessage(), false);
        } catch (Throwable $e) {
            $this->logUploadFailure('Falha no upload temporário: ' . $e->getMessage());
            $this->respond('Não foi possível preparar o arquivo. Tente selecioná-lo novamente.', false);
        }
    }

    public function createTemporaryCopyForSavedFile($filePath, $originalName, $extension, $size, $mimeType)
    {
        $temporary = new TemporaryPrintFileService();
        $temporary->cleanupExpired();

        return $temporary->createFromExisting($filePath, [
            'original_name' => $originalName,
            'extension' => $extension,
            'size' => (int) $size,
            'mime_type' => $mimeType,
        ]);
    }

    public function destroyTemporaryUpload($token)
    {
        (new TemporaryPrintFileService())->destroy((string) $token);
    }

    public function temporaryPageInfo($token, $paper = 'A4', $orientation = 'auto', $extraOptions = [])
    {
        $temporary = new TemporaryPrintFileService();
        $entry = $temporary->entry((string) $token);
        $paper = $this->normalizedPaper($paper);
        $orientation = $this->normalizeOrientation($orientation);
        $printer = new PrintService();
        $preparationOrientation = $this->preparationOrientation($printer, $entry['source_path'], $entry['extension'], $orientation);
        if (in_array($entry['extension'], ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return [
                'pages' => 1,
                'original_pages' => 0,
                'converted_pages' => 1,
                'warning' => '',
                'large_document' => false,
                'advice' => '',
                'resolved_orientation' => $preparationOrientation,
            ];
        }
        $originalPages = $entry['extension'] === 'docx' ? PageCounter::countDocxPages($entry['source_path']) : 0;
        $preparedFile = $temporary->preparedPdf((string) $token, $preparationOrientation, $paper, is_array($extraOptions) ? $extraOptions : []);
        $convertedPages = PageCounter::count($preparedFile);
        $pages = $convertedPages > 0 ? $convertedPages : ($originalPages > 0 ? $originalPages : 1);
        $warning = '';
        if ($originalPages > 0 && $convertedPages > $originalPages) {
            $warning = "O DOCX declara {$originalPages} " . ($originalPages === 1 ? 'página' : 'páginas') . ", mas a conversão gerou {$convertedPages}.";
        }

        return [
            'pages' => $pages,
            'original_pages' => $originalPages,
            'converted_pages' => $convertedPages,
            'warning' => $warning,
            'large_document' => $this->isLargeDocument($pages),
            'advice' => $this->largeDocumentAdvice($pages),
            'resolved_orientation' => $preparationOrientation,
        ];
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

        $uploadToken = trim((string) ($_POST['upload_token'] ?? ''));
        if ($uploadToken !== '') {
            try {
                $info = $this->temporaryPageInfo(
                    $uploadToken,
                    $_POST['paper'] ?? 'A4',
                    $_POST['orientation'] ?? 'auto',
                    $this->requestExtraOptions()
                );
                $pages = (int) ($info['pages'] ?? 1);
                $this->respond("Documento com {$pages} " . ($pages === 1 ? 'página' : 'páginas'), true, $info);
            } catch (Throwable $e) {
                $this->respond('Não foi possível contar as páginas do arquivo temporário.', false);
            }
        }

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
                'large_document' => $this->isLargeDocument($pages),
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

        $temporary = new TemporaryPrintFileService();
        $ephemeralToken = '';
        try {
            $uploadToken = trim((string) ($_POST['upload_token'] ?? ''));
            if ($uploadToken === '') {
                $fileInfo = $this->validateUploadFile($_FILES['arquivo'] ?? null);
                $created = $temporary->createFromUpload($fileInfo);
                $uploadToken = $created['token'];
                $ephemeralToken = $uploadToken;
            }

            $entry = $temporary->entry($uploadToken);
            $paper = $this->normalizedPaper($_POST['paper'] ?? 'A4');
            $orientation = $this->normalizeOrientation($_POST['orientation'] ?? 'auto');
            $numberUp = (int) ($_POST['number_up'] ?? 1);
            $numberUp = in_array($numberUp, [1, 2, 4, 8], true) ? $numberUp : 1;
            $extraOptions = $this->requestExtraOptions();
            $printer = new PrintService();
            $preparationOrientation = $this->preparationOrientation($printer, $entry['source_path'], $entry['extension'], $orientation);
            $preparedFile = $temporary->preparedPdf($uploadToken, $preparationOrientation, $paper, $extraOptions);
            $totalPages = PageCounter::count($preparedFile);
            if ($totalPages < 1) {
                $totalPages = 1;
            }

            $cacheParameters = [
                'paper' => $paper,
                'orientation' => $orientation,
                'resolved_orientation' => $preparationOrientation,
                'number_up' => $numberUp,
                'options' => $extraOptions,
                'max_sheets' => max(1, (int) ($_ENV['PRINT_PREVIEW_MAX_SHEETS'] ?? 3)),
            ];
            $previewFile = $temporary->previewCachePath($uploadToken, $cacheParameters);
            $plan = $printer->previewPlan(
                $totalPages,
                $numberUp,
                max(1, (int) ($_ENV['PRINT_PREVIEW_MAX_SHEETS'] ?? 3)),
                $extraOptions
            );
            if (!is_file($previewFile) || filesize($previewFile) < 1) {
                $buildingFile = $previewFile . '.building-' . bin2hex(random_bytes(6)) . '.pdf';
                try {
                    $plan = $printer->generatePreviewPdf(
                        $preparedFile,
                        $buildingFile,
                        $totalPages,
                        $numberUp,
                        $paper,
                        $orientation,
                        $extraOptions
                    );
                    if (!@rename($buildingFile, $previewFile)) {
                        throw new RuntimeException('Não foi possível finalizar o cache da pré-visualização.');
                    }
                } finally {
                    @unlink($buildingFile);
                }
            }

            header('X-Preview-Document-Pages: ' . $plan['document_pages']);
            header('X-Preview-Selected-Pages: ' . $plan['selected_page_count']);
            header('X-Preview-Total-Sheets: ' . $plan['total_sheets']);
            header('X-Preview-Sheets: ' . $plan['preview_sheets']);
            header('X-Preview-Additional-Sheets: ' . $plan['additional_sheets']);
            header('X-Preview-Number-Up: ' . $plan['number_up']);

            header('Content-Type: application/pdf');
            header('Content-Length: ' . filesize($previewFile));
            header('Content-Disposition: inline; filename="' . $this->previewPdfName($entry['original_name'] ?? 'documento.pdf') . '"');
            header('Cache-Control: no-store, private');
            readfile($previewFile);
        } catch (Throwable $e) {
            (new PrintService())->log('Falha ao gerar preview: ' . $e->getMessage());
            http_response_code(500);
            $message = str_contains(strtolower($e->getMessage()), 'demorando')
                ? 'A pré-visualização está demorando mais que o esperado. Você ainda pode imprimir o documento.'
                : 'Não foi possível gerar a pré-visualização, mas o arquivo pode ser enviado para impressão.';
            echo $message;
        } finally {
            if ($ephemeralToken !== '') {
                $temporary->destroy($ephemeralToken);
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
        if (!$this->isLargeDocument($pages)) {
            return '';
        }

        return 'Documento com muitas páginas. O sistema selecionou 2 por folha. Se preferir, altere para 1 por folha ou escolha frente e verso antes de imprimir.';
    }

    private function isLargeDocument($pages)
    {
        $threshold = max(2, (int) ($_ENV['LARGE_DOCUMENT_PAGE_WARNING'] ?? 2));

        return (int) $pages >= $threshold;
    }

    private function shouldApplyLargeDocumentLayout($pages, $numberUp, $sides)
    {
        $layoutChoice = (string) ($_POST['large_document_layout'] ?? 'auto');
        if ($layoutChoice === 'manual') {
            return false;
        }

        return $this->isLargeDocument($pages)
            && (int) $numberUp === 1
            && (string) $sides === 'one-sided';
    }

    private function normalizeOrientation($orientation)
    {
        $orientation = (string) $orientation;
        return in_array($orientation, ['auto', 'portrait', 'landscape'], true) ? $orientation : 'auto';
    }

    private function normalizedPaper($paper)
    {
        return in_array((string) $paper, ['A4', 'Letter'], true) ? (string) $paper : 'A4';
    }

    private function preparationOrientation($printer, $filePath, $extension, $orientation)
    {
        if ($orientation !== 'auto') {
            return $orientation;
        }

        if (in_array((string) $extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return $printer->detectImageOrientation($filePath) ?: 'portrait';
        }

        return $orientation;
    }

    private function requestExtraOptions()
    {
        $extraOptions = [];
        foreach ($_POST as $key => $value) {
            if (strpos((string) $key, 'opt_') !== 0 || is_array($value)) {
                continue;
            }
            $extraOptions[substr((string) $key, 4)] = (string) $value;
        }

        $extraOptions['media'] = $this->normalizedPaper($_POST['paper'] ?? 'A4');
        $scale = (string) ($_POST['scale'] ?? 'fit');
        if ($scale === 'fit') {
            $extraOptions['fit-to-page'] = 'true';
        } elseif ($scale === 'custom') {
            $scalePercent = (int) ($_POST['scale_percent'] ?? 100);
            if ($scalePercent >= 10 && $scalePercent <= 400) {
                $extraOptions['scaling'] = (string) $scalePercent;
            }
        } elseif (is_numeric($scale)) {
            $scalePercent = (int) $scale;
            if ($scalePercent >= 10 && $scalePercent <= 400) {
                $extraOptions['scaling'] = (string) $scalePercent;
            }
        }

        if (!empty($_POST['page_ranges']) && $this->isValidPageRanges($_POST['page_ranges'])) {
            $extraOptions['page-ranges'] = preg_replace('/\s+/', '', (string) $_POST['page_ranges']);
        }
        if (in_array($_POST['page_set'] ?? '', ['odd', 'even'], true)) {
            $extraOptions['page-set'] = (string) $_POST['page_set'];
        }
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $field = 'margin_' . $side;
            if (isset($_POST[$field]) && $_POST[$field] !== '' && is_numeric($_POST[$field])) {
                $mm = max(0, min(100, (float) $_POST[$field]));
                $extraOptions['page-' . $side] = (string) (int) round($mm * 72 / 25.4);
            }
        }

        return $extraOptions;
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
