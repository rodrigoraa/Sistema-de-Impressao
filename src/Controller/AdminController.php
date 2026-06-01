<?php

require_once __DIR__ . '/../Service/AuthService.php';
require_once __DIR__ . '/../Service/Database.php';
require_once __DIR__ . '/../Service/PrintJobService.php';
require_once __DIR__ . '/../Service/CupsService.php';
require_once __DIR__ . '/../Service/PdfReportService.php';
require_once __DIR__ . '/../Service/StorageCleanupService.php';

class AdminController
{
    public function index()
    {
        // ✔ valida sessão
        if (!isset($_SESSION['user'])) {
            header('Location: /login');
            exit;
        }

        // ✔ valida admin
        if (!AuthService::isAdmin()) {
            http_response_code(403);
            exit('Acesso negado');
        }

        $month = $_GET['month'] ?? date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }
        $cpf = preg_replace('/\D/', '', $_GET['cpf'] ?? '');
        $includeFailures = !empty($_GET['include_failures']);

        $jobService = new PrintJobService();
        $data = $jobService->monthlySummary($month, $cpf, $includeFailures);

        $totalMonth = 0;
        foreach ($data as $row) {
            $totalMonth += (int) ($row['charged_pages'] ?? 0);
        }
        $jobStats = $jobService->statsForUser($_SESSION['user'], true);
        $printerStatus = (new CupsService())->diagnostics();
        $storageService = new StorageCleanupService();
        $storageStats = $storageService->stats();

        require __DIR__ . '/../../views/admin.php';
    }

    public function cleanupStorage()
    {
        if (!isset($_SESSION['user'])) {
            header('Location: /login');
            exit;
        }
        if (!AuthService::isAdmin()) {
            http_response_code(403);
            exit('Acesso negado');
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            exit('Método inválido');
        }

        $token = $_POST['csrf_token'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(419);
            exit('Token CSRF inválido');
        }

        $areas = $_POST['areas'] ?? [];
        $olderThanDays = max(0, (int) ($_POST['older_than_days'] ?? 30));
        $result = (new StorageCleanupService())->cleanup($areas, $olderThanDays);

        $message = 'Limpeza concluída: ' . (int) $result['files'] . ' arquivo(s) apagado(s), '
            . (new StorageCleanupService())->formatBytes($result['bytes']) . ' liberados.';
        if (!empty($result['errors'])) {
            $message .= ' Alguns arquivos não puderam ser apagados por permissão.';
        }

        $_SESSION['flash'] = $message;
        $_SESSION['flash_type'] = empty($result['errors']) ? 'success' : 'error';
        header('Location: /admin');
        exit;
    }

    public function enablePrinter()
    {
        if (!isset($_SESSION['user'])) {
            header('Location: /login');
            exit;
        }
        if (!AuthService::isAdmin()) {
            http_response_code(403);
            exit('Acesso negado');
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            exit('Método inválido');
        }

        $token = $_POST['csrf_token'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(419);
            exit('Token CSRF inválido');
        }

        $result = (new CupsService())->enablePrinter();
        $_SESSION['flash'] = $result['message'] ?? 'Comando executado.';
        $_SESSION['flash_type'] = !empty($result['success']) ? 'success' : 'error';
        $_SESSION['cups_enable_result'] = $result;
        header('Location: /admin');
        exit;
    }

    public function monthlyPdf()
    {
        if (!isset($_SESSION['user'])) {
            header('Location: /login');
            exit;
        }
        if (!AuthService::isAdmin()) {
            http_response_code(403);
            exit('Acesso negado');
        }

        $month = $_GET['month'] ?? date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }
        $cpf = preg_replace('/\D/', '', $_GET['cpf'] ?? '');
        $includeFailures = !empty($_GET['include_failures']);
        $rows = (new PrintJobService())->monthlySummary($month, $cpf, $includeFailures);
        $pdf = (new PdfReportService())->monthlyReport('Relatório mensal de impressões', [
            'month' => $month,
            'cpf' => $cpf,
            'include_failures' => $includeFailures,
        ], $rows);

        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($pdf));
        header('Content-Disposition: attachment; filename="relatorio-impressoes-' . $month . '.pdf"');
        echo $pdf;
        exit;
    }
}

