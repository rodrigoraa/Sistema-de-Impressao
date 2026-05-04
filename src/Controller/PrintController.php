<?php

require_once __DIR__ . '/../Service/PrintService.php';
require_once __DIR__ . '/../Service/PageCounter.php';
require_once __DIR__ . '/../Service/QuotaService.php';

class PrintController
{
    public function handle()
    {
        // ✔ precisa estar logado
        if (!isset($_SESSION['user'])) {
            return;
        }

        // ✔ só processa POST
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

        // ✔ prepara arquivo
        $printer = new PrintService();
        $pdf = $printer->prepareFile($dest);

        if (!$pdf) {
            $this->flash("Erro ao processar arquivo", false);
            return;
        }

        // ✔ conexão com banco
        $db = new SQLite3(__DIR__ . '/../../storage/usage.db');

        // ✔ lista de usuários do sistema
        $result = $db->query("SELECT username, role FROM users");

        $userList = [];
        $userRoles = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $userList[] = $row['username'];
            $userRoles[$row['username']] = $row['role'];
        }

        // ✔ verifica se é admin
        $isAdmin = $_SESSION['role'] === 'admin';

        // ✔ define para quem vai a impressão
        if (
            $isAdmin &&
            !empty($_POST['target_user']) &&
            in_array($_POST['target_user'], $userList)
        ) {
            $user = $_POST['target_user'];
        } else {
            $user = $_SESSION['user'];
        }

        // ✔ opções
        $copies = intval($_POST['copies'] ?? 1);
        $sides = $_POST['sides'] ?? 'one-sided';
        $orientation = $_POST['orientation'] ?? 'portrait';
        $quality = intval($_POST['quality'] ?? 3);

        // ✔ conta páginas
        $pages = PageCounter::count($pdf);

        // ✔ imprime
        $success = $printer->print($pdf, $copies, $sides, $orientation, $quality);

        // ✔ registra uso
        if ($success) {
            $quota = new QuotaService();
            $quota->register($user, $pages * $copies, $dest);
        }

        // ✔ log
        $this->log($user, $dest, $pages, $copies, $success);

        // ✔ feedback
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