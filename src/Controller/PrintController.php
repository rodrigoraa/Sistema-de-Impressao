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
            echo "Arquivo não enviado";
            return;
        }

        $file = $_FILES['arquivo'];
        $uploadPath = $_ENV['UPLOAD_PATH'];

        $filename = uniqid() . '_' . basename($file['name']);
        $dest = rtrim($uploadPath, '/') . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            echo "Erro ao salvar arquivo";
            return;
        }

        $printer = new PrintService();
        $pdf = $printer->prepareFile($dest);

        if (!$pdf) {
            echo "Erro ao processar arquivo";
            return;
        }

        $user = $_SESSION['user'];

        $copies = $_POST['copies'] ?? 1;
        $sides = $_POST['sides'] ?? 'one-sided';
        $orientation = $_POST['orientation'] ?? 'portrait';
        $quality = $_POST['quality'] ?? 3;

        $pages = PageCounter::count($pdf);

        $quota = new QuotaService();

        if (!$quota->canPrint($user, $pages * $copies)) {
            echo "Limite excedido";
            return;
        }

        $success = $printer->print($pdf, $copies, $sides, $orientation, $quality);

        if ($success) {
            $quota->register($user, $pages * $copies);
        }

        $this->log($user, $dest, $pages, $copies, $success);

        echo $success ? "Enviado com sucesso" : "Erro ao imprimir";
    }

    private function log($user, $file, $pages, $copies, $status)
    {
        $logPath = $_ENV['LOG_PATH'];

        $line = date('Y-m-d H:i:s') . " | $user | {$copies}x{$pages} páginas | " . ($status ? "OK" : "FAIL") . "\n";

        file_put_contents($logPath, $line, FILE_APPEND);
    }
}