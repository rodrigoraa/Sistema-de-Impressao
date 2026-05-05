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
            $result = $db->query("SELECT cpf FROM users ORDER BY cpf");

            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $userList[] = $row['cpf'];
            }
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return ['userList' => $userList];
        }

        if (!isset($_FILES['arquivo'])) {
            $this->flash("Arquivo não enviado", false);
        }

        $file = $_FILES['arquivo'];

        $allowed = ['pdf', 'docx', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed)) {
            $this->flash("Tipo de arquivo não permitido", false);
        }

        $uploadPath = $_ENV['UPLOAD_PATH'] ?? '';

        if (!$uploadPath) {
            $this->flash("UPLOAD_PATH não configurado", false);
        }

        $filename = uniqid() . '_' . basename($file['name']);
        $dest = rtrim($uploadPath, '/') . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $this->flash("Erro ao salvar arquivo", false);
        }

        $printer = new PrintService();
        $pdf = $printer->prepareFile($dest);

        if (!$pdf) {
            $this->flash("Erro ao processar arquivo", false);
        }

        $db = Database::connect();

        if (
            $isAdmin &&
            !empty($_POST['target_user']) &&
            in_array($_POST['target_user'], $userList)
        ) {
            $user = $_POST['target_user'];
        } else {
            $user = $_SESSION['user'];
        }

        $copies = intval($_POST['copies'] ?? 1);
        $sides = $_POST['sides'] ?? 'one-sided';
        $orientation = $_POST['orientation'] ?? 'portrait';
        $quality = intval($_POST['quality'] ?? 3);

        $pages = PageCounter::count($pdf);

        $success = $printer->print($pdf, $copies, $sides, $orientation, $quality);

        if ($success) {
            $quota = new QuotaService();
            $quota->register($user, $pages * $copies, $dest);
        }

        $this->log($user, $dest, $pages, $copies, $success);

        $this->flash(
            $success
            ? "Impressão enviada ({$copies} cópias, {$pages} páginas)"
            : "Erro ao imprimir",
            $success
        );
    }

    private function flash($msg, $success = true)
    {
        $_SESSION['flash'] = $msg;
        $_SESSION['flash_type'] = $success ? 'success' : 'error';

        header("Location: /");
        exit;
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
}

