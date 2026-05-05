<?php
require_once __DIR__ . '/../Service/PageCounter.php';
require_once __DIR__ . '/../Service/QuotaService.php';
require_once __DIR__ . '/../Service/Database.php';

class PrintController
{
    public function handle()
    {
        $userList = [];

        // Sessão válida?
        if (!isset($_SESSION['user'])) {
            return ['userList' => $userList];
        }

        // Se admin, buscar lista de usuários
        $isAdmin = ($_SESSION['role'] ?? '') === 'admin';
        if ($isAdmin) {
            $db = Database::connect();
            $result = $db->query("SELECT name, cpf FROM users ORDER BY name");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $userList[] = ['name' => $row['name'], 'cpf' => $row['cpf']];
            }
        }

        // Apenas GET exibe formulário
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return ['userList' => $userList];
        }

        // Valida envio de arquivo
        if (!isset($_FILES['arquivo'])) {
            $this->flash("Arquivo não enviado", false);
        }
        $file = $_FILES['arquivo'];
        $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            $this->flash("Tipo de arquivo não permitido", false);
        }

        // Caminho de upload configurado
        $uploadPath = $_ENV['UPLOAD_PATH'] ?? '';
        if (!$uploadPath) {
            $this->flash("UPLOAD_PATH não configurado", false);
        }
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0775, true);
        }

        // Salva o arquivo
        $filename = uniqid() . '_' . basename($file['name']);
        $dest = rtrim($uploadPath, '/') . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $this->flash("Erro ao salvar arquivo", false);
        }

        // Determina usuário-alvo
        $cpfList = array_column($userList, 'cpf');
        if ($isAdmin && !empty($_POST['target_user']) && in_array($_POST['target_user'], $cpfList)) {
            $user = $_POST['target_user'];
        } else {
            $user = $_SESSION['user'];
        }

        // Configurações de impressão
        $copies = max(1, intval($_POST['copies'] ?? 1));
        $sides = $_POST['sides'] ?? 'one-sided';
        $orientation = $_POST['orientation'] ?? 'portrait';
        $quality = intval($_POST['quality'] ?? 3);
        $numberUp = intval($_POST['number_up'] ?? 1);

        // Contagem de páginas: ignoramos e deixamos 1 para evitar bloqueio
        $pages = 1;

        // Converte e imprime em background
        $printerName = $_ENV['PRINTER_NAME'] ?? '';
        if (!$printerName) {
            $this->flash("PRINTER_NAME não configurado", false);
        }

        // Se for DOC/DOCX: converte e imprime o PDF gerado
        if (in_array($ext, ['doc', 'docx'])) {
            $pdfFile = "/tmp/" . pathinfo($dest, PATHINFO_FILENAME) . ".pdf";
            // Converte para PDF em background e, após conversão, imprime
            $cmd = "HOME=/tmp soffice --headless --convert-to pdf:writer_pdf_Export "
                . escapeshellarg($dest) . " --outdir /tmp && "
                . "/usr/bin/lp -d " . escapeshellarg($printerName)
                . " -n " . intval($copies)
                . " -o sides=" . escapeshellarg($sides)
                . " -o orientation-requested=" . intval(($orientation === 'landscape') ? 4 : 3)
                . " -o print-quality=" . intval($quality)
                . ($numberUp > 1 ? " -o number-up=" . intval($numberUp) : "")
                . " " . escapeshellarg($pdfFile)
                . " > /dev/null 2>&1 &";
            exec($cmd);
        }
        // Imagem: converte e imprime
        elseif (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            $pdfFile = "/tmp/" . uniqid('img_', true) . ".pdf";
            $cmd = "convert " . escapeshellarg($dest)
                . " -density 150 -quality 90 " . escapeshellarg($pdfFile)
                . " && /usr/bin/lp -d " . escapeshellarg($printerName)
                . " -n " . intval($copies)
                . " " . escapeshellarg($pdfFile)
                . " > /dev/null 2>&1 &";
            exec($cmd);
        }
        // PDF: imprime direto
        else {
            $cmd = "/usr/bin/lp -d " . escapeshellarg($printerName)
                . " -n " . intval($copies)
                . " -o sides=" . escapeshellarg($sides)
                . " -o orientation-requested=" . intval(($orientation === 'landscape') ? 4 : 3)
                . " -o print-quality=" . intval($quality)
                . ($numberUp > 1 ? " -o number-up=" . intval($numberUp) : "")
                . " " . escapeshellarg($dest)
                . " > /dev/null 2>&1 &";
            exec($cmd);
        }

        // Registra cota de páginas (aproximado)
        $quota = new QuotaService();
        $quota->register($user, $pages * $copies, $dest);

        // Limpeza de segurança: mantém somente arquivos necessários
        if (file_exists($dest)) {
            unlink($dest);
        }

        $this->log($user, $dest, $pages, $copies, true);
        $this->flash("Impressão enviada ({$copies} cópias, {$pages} páginas)", true);
    }

    private function log($user, $file, $pages, $copies, $status)
    {
        $logPath = $_ENV['LOG_PATH'] ?? '';
        if (!$logPath)
            return;
        $line = date('Y-m-d H:i:s')
            . " | USER: $user | FILE: $file | COPIES: $copies | PAGES: $pages | STATUS: "
            . ($status ? "OK" : "FAIL") . "\n";
        @file_put_contents($logPath, $line, FILE_APPEND);
    }

    private function flash($msg, $success = true)
    {
        // Armazena mensagem na sessão e redireciona
        $_SESSION['flash'] = $msg;
        $_SESSION['flash_type'] = $success ? 'success' : 'error';
        header("Location: /");
        exit;
    }
}
