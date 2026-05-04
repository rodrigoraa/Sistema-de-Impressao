<?php

require_once __DIR__ . '/../Service/PrintService.php';
require_once __DIR__ . '/../Service/PageCounter.php';
require_once __DIR__ . '/../Service/QuotaService.php';

class PrintController
{
    public function handle()
    {
        if (!isset($_SESSION['user'])) {
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (!isset($_FILES['arquivo'])) {
            $this->flash("Arquivo não enviado", false);
            return;
        }

        $file = $_FILES['arquivo'];

        // ✔ valida extensão
        $allowed = ['pdf', 'docx', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed)) {
            $this->flash("Tipo de arquivo não permitido", false);
            return;
        }

        $uploadPath = $_ENV['UPLOAD_PATH'];

        $filename = uniqid() . '_' . basename($file['name']);
        $dest = rtrim($uploadPath, '/') . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $this->flash("Erro ao salvar arquivo", false);
            return;
        }

        $printer = new PrintService();
        $pdf = $printer->prepareFile($dest);

        if (!$pdf) {
            $this->flash("Erro ao processar arquivo", false);
            return;
        }

        $user = $_SESSION['user'];

        $copies = intval($_POST['copies'] ?? 1);
        $sides = $_POST['sides'] ?? 'one-sided';
        $orientation = $_POST['orientation'] ?? 'portrait';
        $quality = intval($_POST['quality'] ?? 3);

        $pages = PageCounter::count($pdf);

        $quota = new QuotaService();

        // ✔ imprime primeiro
        $success = $printer->print($pdf, $copies, $sides, $orientation, $quality);

        // ✔ registra apenas se imprimir
        if ($success) {
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
        $logPath = $_ENV['LOG_PATH'];

        $line = date('Y-m-d H:i:s')
            . " | USER: $user | FILE: $file | COPIES: $copies | PAGES: $pages | STATUS: "
            . ($status ? "OK" : "FAIL") . "\n";

        file_put_contents($logPath, $line, FILE_APPEND);
    }
}