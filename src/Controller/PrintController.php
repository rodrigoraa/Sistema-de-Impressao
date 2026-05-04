<?php

require_once __DIR__ . '/../Service/PrintService.php';

class PrintController
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function handle()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (!isset($_FILES['arquivo'])) {
            echo "Arquivo não enviado";
            return;
        }

        $file = $_FILES['arquivo'];
        $dest = $this->config['upload_path'] . basename($file['name']);

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            echo "Erro ao salvar arquivo";
            return;
        }

        $printer = new PrintService($this->config['printer_name']);
        $success = $printer->printFile($dest);

        $this->log($_SERVER['REMOTE_USER'] ?? 'anon', $dest, $success);

        echo $success ? "Enviado para impressão" : "Erro ao imprimir";
    }

    private function log($user, $file, $status)
    {
        $line = date('Y-m-d H:i:s') . " | $user | $file | " . ($status ? "OK" : "FAIL") . "\n";
        file_put_contents($this->config['log_path'], $line, FILE_APPEND);
    }
}