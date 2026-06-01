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
        if (!$isAdmin && in_array($status, ['active', 'queued', 'processing'], true)) {
            $status = '';
            $_GET['status'] = '';
        }
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
        if ($this->hasPartialSelection($job)) {
            $this->finish('Esta impressão usou seleção de páginas. Para evitar imprimir páginas a mais, faça uma nova impressão escolhendo o mesmo intervalo.', false);
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
            $service->updateSelection($newJobId, $pages === 1 ? 'Página 1' : "Todas as páginas ({$pages})", $pages);
            $service->markProcessing($newJobId, $preparedFile, $pages, $chargedPages);
            $completed = $printer->printPrepared($preparedFile, $sourceExt, $copies, $sides, $orientation, 3, $numberUp, ['media' => $paper]);
            $service->updateCupsResult($newJobId, $printer->lastPrintResult());

            if ($completed === true) {
                $service->markCompleted($newJobId, $preparedFile, $pages, $chargedPages);
                (new QuotaService())->register($user, $chargedPages, $job['stored_file']);
                $this->finish('Reimpressão concluída. ' . $this->cupsConfirmationMessage($printer->lastPrintResult()['status_cups'] ?? ''), true);
            }

            $service->markFailed($newJobId, $preparedFile, $pages, $chargedPages, 'Reimpressão não concluída');
            $this->finish('A reimpressão não foi confirmada. Nada foi contabilizado.', false);
        } catch (Throwable $e) {
            $service->updateCupsResult($newJobId, $printer->lastPrintResult());
            $service->markFailed($newJobId, $preparedFile, $pages, $chargedPages, $e->getMessage());
            $this->finish('Não foi possível reimprimir: ' . $e->getMessage(), false);
        }
    }

    private function hasPartialSelection($job)
    {
        $pages = max(0, (int) ($job['pages'] ?? 0));
        $selected = max(0, (int) ($job['selected_pages_count'] ?? 0));
        $label = trim((string) ($job['selected_pages_label'] ?? ''));

        if ($pages < 1 || $selected < 1 || $selected >= $pages) {
            return false;
        }

        return $label !== '';
    }

    public function adjustAccounting()
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
            $this->finish('Token CSRF inválido', false);
        }

        try {
            $chargedPages = max(0, (int) ($_POST['charged_pages'] ?? 0));
            $reason = (string) ($_POST['reason'] ?? '');
            $result = (new PrintJobService())->adjustAccounting($_POST['id'] ?? 0, $chargedPages, $reason, $_SESSION['user']);
            $this->finish('Contabilização atualizada. Antes: ' . (int) $result['previous'] . ' folha(s). Agora: ' . (int) $result['current'] . ' folha(s).', true);
        } catch (Throwable $e) {
            $this->finish('Não foi possível corrigir a contabilização: ' . $e->getMessage(), false);
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

    private function cupsConfirmationMessage($statusCups)
    {
        return match ((string) $statusCups) {
            'completed' => 'O servidor confirmou a conclusão da impressão.',
            'left_queue' => 'O servidor enviou o trabalho para a impressora, mas não recebeu confirmação final.',
            'accepted_unverified' => 'O servidor aceitou o trabalho, mas não conseguiu confirmar o fim da impressão.',
            'accepted_unidentified' => 'O servidor aceitou o trabalho, mas não identificou o número dele na fila.',
            'accepted' => 'O servidor aceitou o trabalho para impressão.',
            default => 'Status CUPS: ' . ((string) $statusCups !== '' ? (string) $statusCups : 'não informado') . '.',
        };
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
