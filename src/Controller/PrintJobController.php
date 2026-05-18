<?php

require_once __DIR__ . '/../Service/AuthService.php';
require_once __DIR__ . '/../Service/PrintJobService.php';
require_once __DIR__ . '/../Service/PrintService.php';
require_once __DIR__ . '/../Service/PageCounter.php';
require_once __DIR__ . '/../Service/QuotaService.php';

class PrintJobController
{
    public function index()
    {
        if (!isset($_SESSION['user'])) {
            header('Location: /login');
            exit;
        }

        $status = $_GET['status'] ?? '';
        $isAdmin = AuthService::isAdmin();
        $service = new PrintJobService();
        $jobs = $service->listForUser($_SESSION['user'], $isAdmin, $status, 150, [
            'cpf' => $_GET['cpf'] ?? '',
            'month' => $_GET['month'] ?? '',
            'error' => $_GET['error'] ?? '',
        ]);
        $stats = $service->statsForUser($_SESSION['user'], $isAdmin);

        require __DIR__ . '/../../views/print_jobs.php';
    }

    public function download()
    {
        if (!isset($_SESSION['user'])) {
            header('Location: /login');
            exit;
        }

        $service = new PrintJobService();
        $job = $service->findVisible($_GET['id'] ?? 0, $_SESSION['user'], AuthService::isAdmin());
        if ($job === null || empty($job['stored_file']) || !$this->isDownloadableFile($job['stored_file'])) {
            http_response_code(404);
            exit('Arquivo não encontrado');
        }

        $name = $job['original_name'] ?: basename($job['stored_file']);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($job['stored_file']));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename($name)) . '"');
        readfile($job['stored_file']);
        exit;
    }

    public function reprint()
    {
        if (!isset($_SESSION['user'])) {
            header('Location: /login');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            exit('Método inválido');
        }

        $token = $_POST['csrf_token'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            $this->finish('Token CSRF inválido', false);
        }

        $isAdmin = AuthService::isAdmin();
        $service = new PrintJobService();
        $job = $service->findVisible($_POST['id'] ?? 0, $_SESSION['user'], $isAdmin);
        if ($job === null || empty($job['stored_file']) || !$this->isDownloadableFile($job['stored_file'])) {
            $this->finish('Arquivo original não encontrado para reimpressão', false);
        }

        $user = $job['user'];
        $copies = max(1, (int) ($job['copies'] ?? 1));
        $numberUp = max(1, (int) ($job['number_up'] ?? 1));
        $sides = $job['sides'] ?: 'one-sided';
        $orientation = $job['orientation'] ?: 'portrait';
        $paper = $job['paper'] ?: 'A4';
        $sourceExt = strtolower(pathinfo($job['stored_file'], PATHINFO_EXTENSION));
        $newJobId = $service->create($user, 'Reimpressão - ' . ($job['original_name'] ?: basename($job['stored_file'])), $job['stored_file'], $sourceExt, $copies, $numberUp, $sides, $orientation, $paper);

        $printer = new PrintService();
        $preparedFile = $job['stored_file'];
        $pages = 0;
        $chargedPages = 0;
        try {
            $preparedFile = $printer->prepareFile($job['stored_file'], $orientation, $paper);
            $pages = PageCounter::count($preparedFile);
            if ($pages < 1) {
                $pages = 1;
            }
            $chargedPages = (int) ceil($pages / $numberUp) * $copies;
            $service->markProcessing($newJobId, $preparedFile, $pages, $chargedPages);
            $completed = $printer->printPrepared($preparedFile, $sourceExt, $copies, $sides, $orientation, 3, $numberUp, ['media' => $paper]);
            $service->updateCupsResult($newJobId, $printer->lastPrintResult());

            if ($completed === true) {
                $service->markCompleted($newJobId, $preparedFile, $pages, $chargedPages);
                (new QuotaService())->register($user, $chargedPages, $job['stored_file']);
                $this->finish('Reimpressão enviada com sucesso', true);
            }

            $service->markFailed($newJobId, $preparedFile, $pages, $chargedPages, 'Reimpressão não concluída');
            $this->finish('Reimpressão não concluída', false);
        } catch (Throwable $e) {
            $service->updateCupsResult($newJobId, $printer->lastPrintResult());
            $service->markFailed($newJobId, $preparedFile, $pages, $chargedPages, $e->getMessage());
            $this->finish('Erro ao reimprimir: ' . $e->getMessage(), false);
        }
    }

    private function isDownloadableFile($path)
    {
        if (!is_string($path) || !is_file($path)) {
            return false;
        }

        $uploadRoot = realpath($_ENV['UPLOAD_PATH'] ?? dirname(__DIR__, 2) . '/storage/uploads');
        $file = realpath($path);

        return $uploadRoot !== false && $file !== false && str_starts_with($file, $uploadRoot . DIRECTORY_SEPARATOR);
    }

    private function finish($message, $success)
    {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => $success, 'message' => $message]);
            exit;
        }

        $_SESSION['flash'] = $message;
        $_SESSION['flash_type'] = $success ? 'success' : 'error';
        header('Location: /prints');
        exit;
    }
}
