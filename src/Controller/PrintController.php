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

        // Se não estiver logado, retorna só a lista (vazia) de usuários
        if (!isset($_SESSION['user'])) {
            return ['userList' => $userList];
        }

        $isAdmin = (($_SESSION['role'] ?? '') === 'admin');

        // Se admin, carrega lista de usuários (nome e cpf) do BD para seleção
        if ($isAdmin) {
            $db = Database::connect();
            $result = $db->query("SELECT name, cpf FROM users ORDER BY name");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $userList[] = ['name' => $row['name'], 'cpf' => $row['cpf']];
            }
        }

        // No GET, apenas retorna a lista de usuários para a view
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return ['userList' => $userList];
        }

        // =======================
        // === Processa POST ====
        // =======================
        // Verifica se arquivo foi enviado
        if (!isset($_FILES['arquivo'])) {
            $this->flash("Arquivo não enviado", false);
            // Não deve haver resposta JSON aqui, pois ainda nem imprimimos nada
        }

        $file = $_FILES['arquivo'];
        $filename = $file['name'];
        $tmpPath = $file['tmp_name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Tipos permitidos
        $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
        if (!in_array($ext, $allowed)) {
            $this->flash("Tipo de arquivo não permitido", false);
        }

        // Caminho de upload (definido via env UPLOAD_PATH)
        $uploadPath = $_ENV['UPLOAD_PATH'] ?? '';
        if (!$uploadPath) {
            $this->flash("UPLOAD_PATH não configurado", false);
        }
        if (!is_dir($uploadPath) && !mkdir($uploadPath, 0775, true)) {
            $this->flash("Não foi possível criar diretório de upload", false);
        }

        // Move arquivo enviado para pasta de uploads
        $newFilename = uniqid() . '_' . basename($filename);
        $dest = rtrim($uploadPath, '/') . '/' . $newFilename;
        if (!move_uploaded_file($tmpPath, $dest)) {
            $this->flash("Erro ao salvar arquivo", false);
        }

        // Define usuário alvo: se admin e selecionou outro usuário válido, usa esse; senão, usuário da sessão.
        $cpfList = array_column($userList, 'cpf');
        if ($isAdmin && !empty($_POST['target_user']) && in_array($_POST['target_user'], $cpfList)) {
            $user = $_POST['target_user'];
        } else {
            $user = $_SESSION['user'];
        }

        // Parâmetros de impressão do formulário
        $copies = max(1, intval($_POST['copies'] ?? 1));
        $sides = ($_POST['sides'] ?? 'one-sided');        // "one-sided" ou "two-sided-long-edge"/"two-sided-short-edge"
        $orientation = $_POST['orientation'] ?? 'portrait'; // "portrait" ou "landscape"
        $quality = intval($_POST['quality'] ?? 3);       // qualidade de impressão (ex: 3=norm)
        $numberUp = intval($_POST['number_up'] ?? 1);    // páginas por folha (1, 2, ...)
        // Outros parâmetros extras (prefixados com opt_)
        $extraOptions = [];
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'opt_') === 0) {
                $realKey = substr($key, 4);
                $extraOptions[$realKey] = $value;
            }
        }

        // Instancia serviço de impressão
        $printer = new PrintService();
        $success = false;

        // Lança o comando de impressão em background (PrintService::print cuida de conversão se necessário)
        try {
            $printer->print($dest, $copies, $sides, $orientation, $quality, $numberUp, $extraOptions);
            $success = true;
        } catch (Throwable $e) {
            // Em caso de erro, registra falha
            $printer->log("Erro interno: " . $e->getMessage());
            $success = false;
        }

        // Conta páginas do PDF (se foi PDF) para cota; PageCounter só conta se existir
        $pages = PageCounter::count($dest);

        // Registra no banco de dados via QuotaService
        $quota = new QuotaService();
        $quota->register($user, $pages * $copies, $dest);

        // Registra log customizado (opcional)
        $this->log($user, $dest, $pages, $copies, $success);

        $msg = $success
            ? "Impressão enviada ({$copies} cópias, {$pages} páginas)"
            : "Erro ao enviar impressão";

        // Responde de acordo com AJAX ou formulário padrão
        if ($this->isAjax()) {
            $this->respond($msg, $success);
        } else {
            $this->flash($msg, $success);
        }
    }

    // Detecta requisição AJAX
    private function isAjax()
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    // Flash: armazena mensagem na sessão e redireciona
    private function flash($msg, $success = true)
    {
        $_SESSION['flash'] = $msg;
        $_SESSION['flash_type'] = $success ? 'success' : 'error';
        header("Location: /");
        exit;
    }

    // Resposta JSON (para AJAX)
    private function respond($msg, $success = true)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $success, 'message' => $msg]);
        exit;
    }

    // Registra log simples (opcional)
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
            ($status ? "OK" : "FAIL")
        );
        @file_put_contents($logPath, $line, FILE_APPEND);
    }
}
