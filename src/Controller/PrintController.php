<?php
require_once __DIR__ . '/../Service/PrintService.php';
require_once __DIR__ . '/../Service/PageCounter.php';
require_once __DIR__ . '/../Service/QuotaService.php';
require_once __DIR__ . '/../Service/Database.php';

class PrintController
{
    public function handle()
    {
        session_start();
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
            $this->flash("Arquivo não enviado", false);
        }
        $file = $_FILES['arquivo'];
        $origName = $file['name'];
        $tmpPath = $file['tmp_name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        // Extensões permitidas
        $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
        if (!in_array($ext, $allowed)) {
            $this->flash("Tipo de arquivo não permitido", false);
        }

        // Configura caminhos via env (ou config carregada)
        $uploadPath = $_ENV['UPLOAD_PATH'] ?? '';
        if (!$uploadPath) {
            $this->flash("UPLOAD_PATH não configurado", false);
        }
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0775, true);
        }
        $newFilename = uniqid() . '_' . basename($origName);
        $dest = rtrim($uploadPath, '/') . '/' . $newFilename;
        if (!move_uploaded_file($tmpPath, $dest)) {
            $this->flash("Erro ao salvar arquivo", false);
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
        $numberUp = intval($_POST['number_up'] ?? 1);
        // Coleta opções extras (opt_*)
        $extraOptions = [];
        foreach ($_POST as $key => $val) {
            if (strpos($key, 'opt_') === 0) {
                $optKey = substr($key, 4);
                $extraOptions[$optKey] = $val;
            }
        }

        // Prepara e dispara a impressão (PrintService faz conversão internamente)
        $printer = new PrintService();
        try {
            $printer->print($dest, $copies, $sides, $orientation, $quality, $numberUp, $extraOptions);
            $success = true;
        } catch (Throwable $e) {
            $printer->log("Erro interno ao imprimir: " . $e->getMessage());
            $success = false;
        }

        // Contabiliza páginas (só conta se for PDF existente)
        $pages = PageCounter::count($dest);

        // Registra no banco de dados (QuotaService)
        $quota = new QuotaService();
        $quota->register($user, $pages * $copies, $dest);

        // Grava log customizado
        $this->log($user, $dest, $pages, $copies, $success);

        // Mensagem de retorno
        $msg = $success
            ? "Impressão enviada ({$copies} cópias, {$pages} páginas)"
            : "Erro ao enviar impressão";

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

    // Responde em JSON (usado para AJAX)
    private function respond($msg, $success = true)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $success, 'message' => $msg]);
        exit;
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
