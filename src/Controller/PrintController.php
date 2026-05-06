<?php
require_once __DIR__ . '/../Service/PrintService.php';
require_once __DIR__ . '/../Service/PageCounter.php';
require_once __DIR__ . '/../Service/QuotaService.php';
require_once __DIR__ . '/../Service/Database.php';

class PrintController
{
    private $allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

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

        // Validação inicial
        if (!isset($_FILES['arquivo'])) {
            $this->logUploadFailure('Arquivo ausente em $_FILES');
            $this->fail("Arquivo não enviado");
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
        $this->logUploadFailure("Upload recebido: nome={$origName} ext={$ext} size={$size}");

        // Extensões permitidas
        if (!in_array($ext, $this->allowedExtensions, true)) {
            $this->fail("Tipo de arquivo não permitido");
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
            } elseif (is_numeric($_POST['scale'])) {
                $extraOptions['scaling'] = $_POST['scale'];
            }
        }

        // Prepara, conta paginas e envia para impressao usando o mesmo arquivo convertido.
        $printer = new PrintService();
        $printedFile = $dest;
        $pages = 0;
        $completed = false;
        $errorMessage = '';
        try {
            $sourceExt = strtolower(pathinfo($dest, PATHINFO_EXTENSION));
            $originalPages = $sourceExt === 'docx' ? PageCounter::countDocxPages($dest) : 0;
            $printedFile = $printer->prepareFile($dest, $orientation, $extraOptions['media'] ?? $extraOptions['paper'] ?? 'A4');
            $convertedPages = PageCounter::count($printedFile);
            $pages = $convertedPages;
            if ($pages < 1) {
                $pages = $originalPages > 0 ? $originalPages : 1;
            }

            $completed = $printer->printPrepared($printedFile, $sourceExt, $copies, $sides, $orientation, $quality, $numberUp, $extraOptions);
            $success = $completed === true;
        } catch (Throwable $e) {
            $printer->log("Erro interno ao imprimir: " . $e->getMessage());
            $errorMessage = $e->getMessage();
            $success = false;
        }

        $totalPages = $pages * $copies;

        // Registra no banco apenas quando a impressao foi confirmada.
        if ($success) {
            $quota = new QuotaService();
            $quota->register($user, $totalPages, $dest);
        }

        // Grava log customizado
        $this->log($user, $dest, $pages, $copies, $success);

        // Mensagem de retorno
        if ($success) {
            $msg = "Impressão concluída ({$pages} páginas x {$copies} cópias = {$totalPages} páginas contabilizadas)";
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
                'total_pages' => $success ? $totalPages : 0,
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

        if (!isset($_FILES['arquivo']) || ($_FILES['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->respond('Arquivo não enviado', false);
        }

        $file = $_FILES['arquivo'];
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowedExtensions, true)) {
            $this->respond('Tipo de arquivo não permitido', false);
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
            $originalPages = $ext === 'docx' ? PageCounter::countDocxPages($tempFile) : 0;
            $preparedFile = $printer->prepareFile($tempFile, $orientation, $paper);
            $convertedPages = PageCounter::count($preparedFile);
            $pages = $convertedPages;
            if ($pages < 1) {
                $pages = $originalPages > 0 ? $originalPages : 1;
            }

            $message = "Documento com {$pages} " . ($pages === 1 ? 'página' : 'páginas');
            if ($ext === 'docx' && $originalPages > 0 && $convertedPages > 0 && $originalPages !== $convertedPages) {
                $message .= " após conversão";
            }

            $this->respond($message, true, [
                'pages' => $pages,
                'original_pages' => $originalPages,
                'converted_pages' => $convertedPages,
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
