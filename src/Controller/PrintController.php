<?php
require_once __DIR__ . '/../Service/PrintService.php';
require_once __DIR__ . '/../Service/PageCounter.php';
require_once __DIR__ . '/../Service/QuotaService.php';
require_once __DIR__ . '/../Service/Database.php';

class PrintController
{
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
        $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
        if (!in_array($ext, $allowed)) {
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

        // Prepara e dispara a impressão (PrintService faz conversão internamente)
        $printer = new PrintService();
        $printedFile = $dest;
        $errorMessage = '';
        try {
            $printedFile = $printer->print($dest, $copies, $sides, $orientation, $quality, $numberUp, $extraOptions);
            $success = true;
        } catch (Throwable $e) {
            $printer->log("Erro interno ao imprimir: " . $e->getMessage());
            $errorMessage = $e->getMessage();
            $success = false;
        }

        // Contabiliza o arquivo preparado para impressao, inclusive DOC/DOCX/imagem convertidos.
        $pages = PageCounter::count($printedFile);
        if ($success && $pages < 1) {
            $pages = 1;
        }

        // Registra no banco de dados (QuotaService)
        $quota = new QuotaService();
        $quota->register($user, $pages * $copies, $dest);

        // Grava log customizado
        $this->log($user, $dest, $pages, $copies, $success);

        // Mensagem de retorno
        $msg = $success
            ? "Impressão enviada ({$copies} cópias, {$pages} páginas)"
            : "Erro ao enviar impressão" . ($errorMessage ? ": {$errorMessage}" : "");

        // Resposta AJAX vs Flash
        if ($this->isAjax()) {
            $this->respond($msg, $success);
        } else {
            $this->flash($msg, $success);
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
    private function respond($msg, $success = true)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $success, 'message' => $msg]);
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
