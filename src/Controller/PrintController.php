<?php

require_once __DIR__ . '/../Service/PrintService.php';
require_once __DIR__ . '/../Service/PageCounter.php';
require_once __DIR__ . '/../Service/QuotaService.php';
require_once __DIR__ . '/../Service/Database.php';

class PrintController
{
    public function handle()
    {
        $userList = [];

        if (!isset($_SESSION['user'])) {
            return ['userList' => $userList];
        }

        $isAdmin = ($_SESSION['role'] ?? '') === 'admin';

        if ($isAdmin) {
            $db = Database::connect();
            $result = $db->query("SELECT name, cpf FROM users ORDER BY name");

            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $userList[] = [
                    'name' => $row['name'],
                    'cpf' => $row['cpf']
                ];
            }
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return ['userList' => $userList];
        }

        if (!isset($_FILES['arquivo'])) {
            $this->respond("Arquivo não enviado", false);
        }

        $file = $_FILES['arquivo'];

        $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed)) {
            $this->respond("Tipo de arquivo não permitido", false);
        }

        $uploadPath = $_ENV['UPLOAD_PATH'] ?? '';

        if (!$uploadPath) {
            $this->respond("UPLOAD_PATH não configurado", false);
        }

        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0775, true);
        }

        $filename = uniqid() . '_' . basename($file['name']);
        $dest = rtrim($uploadPath, '/') . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $this->respond("Erro ao salvar arquivo", false);
        }

        // ✔ NÃO converter dentro do request
        $pdf = $dest;

        // ✔ Converter DOC/DOCX em background
        if (in_array($ext, ['doc', 'docx'])) {
            exec(
                "HOME=/tmp libreoffice --headless --convert-to pdf "
                . escapeshellarg($dest)
                . " --outdir /tmp > /dev/null 2>&1 &"
            );
        }

        // ✔ Converter imagem em background (opcional)
        if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            $output = "/tmp/" . uniqid('img_', true) . ".pdf";

            exec(
                "convert "
                . escapeshellarg($dest)
                . " -density 150 -quality 90 "
                . escapeshellarg($output)
                . " > /dev/null 2>&1 &"
            );
        }

        $cpfList = array_column($userList, 'cpf');

        if (
            $isAdmin &&
            !empty($_POST['target_user']) &&
            in_array($_POST['target_user'], $cpfList)
        ) {
            $user = $_POST['target_user'];
        } else {
            $user = $_SESSION['user'];
        }

        $copies = max(1, intval($_POST['copies'] ?? 1));
        $sides = $_POST['sides'] ?? 'one-sided';
        $orientation = $_POST['orientation'] ?? 'portrait';
        $quality = intval($_POST['quality'] ?? 3);
        $numberUp = intval($_POST['number_up'] ?? 1);

        // ✔ Evitar travamento com PageCounter
        $pages = 1;

        $extraOptions = [];

        foreach ($_POST as $key => $value) {
            if (str_starts_with($key, 'opt_')) {
                $realKey = substr($key, 4);
                $extraOptions[$realKey] = $value;
            }
        }

        // ✔ Impressão em background (não bloqueia)
        $cmd = "/usr/bin/lp "
            . "-d " . escapeshellarg($_ENV['PRINTER_NAME']) . " "
            . "-n " . intval($copies) . " "
            . "-o sides=" . $sides . " "
            . "-o orientation-requested=" . ($orientation === 'landscape' ? 4 : 3) . " "
            . "-o print-quality=" . intval($quality) . " ";

        if ($numberUp > 1) {
            $cmd .= "-o number-up=" . intval($numberUp) . " ";
        }

        $cmd .= escapeshellarg($pdf);

        exec($cmd . " > /dev/null 2>&1 &");

        // ✔ Registrar uso
        $quota = new QuotaService();
        $quota->register($user, $pages * $copies, $dest);

        // ✔ Limpeza segura (opcional)
        if (file_exists($dest)) {
            unlink($dest);
        }

        $this->log($user, $dest, $pages, $copies, true);

        $this->respond(
            "Impressão enviada ({$copies} cópias, {$pages} páginas)",
            true
        );
    }

    private function log($user, $file, $pages, $copies, $status)
    {
        $logPath = $_ENV['LOG_PATH'] ?? '';

        if (!$logPath) {
            return;
        }

        $line = date('Y-m-d H:i:s')
            . " | USER: $user | FILE: $file | COPIES: $copies | PAGES: $pages | STATUS: "
            . ($status ? "OK" : "FAIL") . "\n";

        @file_put_contents($logPath, $line, FILE_APPEND);
    }

    private function respond($msg, $success = true)
    {
        header('Content-Type: application/json');

        echo json_encode([
            'success' => $success,
            'message' => $msg
        ]);

        exit;
    }
}