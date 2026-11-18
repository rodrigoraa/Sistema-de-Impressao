<?php

require_once __DIR__ . '/../Service/AuthService.php';
require_once __DIR__ . '/../Service/CupsService.php';
require_once __DIR__ . '/../Service/PrintJobService.php';
require_once __DIR__ . '/../Service/PrintQueueService.php';
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
            'search' => is_string($_GET['search'] ?? '') ? substr($_GET['search'] ?? '', 0, 150) : '',
            'cpf' => $_GET['cpf'] ?? '',
            'month' => $_GET['month'] ?? '',
            'error' => $_GET['error'] ?? '',
        ]);
        $stats = $service->statsForUser($_SESSION['user'], $isAdmin);
        $cupsQueue = null;
        $cupsQueueJobsById = [];
        if ($isAdmin && $status === 'active') {
            $cupsQueue = (new CupsService())->activeQueue();
            $cupsIds = array_column($cupsQueue['jobs'] ?? [], 'job_id');
            $cupsQueueJobsById = $service->listByCupsJobIds($cupsIds);
            $stats['active'] = max((int) ($stats['active'] ?? 0), count($cupsQueue['jobs'] ?? []));
        }

        require __DIR__ . '/../../views/print_jobs.php';
    }

    public function download()
    {
        if (!isset($_SESSION['user'])) {
            header('Location: /login');
            exit;
        }

        $service = new PrintJobService();
        $job = $service->findVisible((int) ($_GET['id'] ?? 0), $_SESSION['user'], AuthService::isAdmin());
        if ($job === null || empty($job['stored_file']) || !$this->isDownloadableFile($job['stored_file'])) {
            http_response_code(404);
            exit('Arquivo não encontrado');
        }

        $name = $this->downloadFilename($job['original_name'] ?: basename($job['stored_file']));
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($job['stored_file']));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        readfile($job['stored_file']);
        exit;
    }

    // Preparing a review never dispatches a print or changes the original job.
    public function reuse()
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
        $expected = $_SESSION['csrf_token'] ?? '';
        if (!is_string($token) || !is_string($expected) || $expected === '' || !hash_equals($expected, $token)) {
            http_response_code(419);
            exit('Token CSRF inválido');
        }
        $job = (new PrintJobService())->findVisible((int) ($_POST['id'] ?? 0), $_SESSION['user'], AuthService::isAdmin());
        if ($job === null || !$this->isDownloadableFile($job['stored_file'] ?? null)) {
            http_response_code(404);
            exit('Arquivo não encontrado para reutilização.');
        }
        if (!PrintQueueService::canPrintAgain($job)) {
            $this->finish('Confira o resultado desta impressão antes de usar o arquivo novamente.', false);
        }
        require_once __DIR__ . '/PrintController.php';
        $controller = new PrintController();
        $reviewOptions = [
            'sides' => in_array($job['sides'], ['one-sided', 'two-sided-long-edge', 'two-sided-short-edge'], true) ? $job['sides'] : 'one-sided',
            'number_up' => in_array((int) $job['number_up'], [1, 2, 4, 8], true) ? (string) $job['number_up'] : '1',
            'orientation' => in_array($job['orientation'], ['auto', 'portrait', 'landscape'], true) ? $job['orientation'] : 'auto',
            'paper' => in_array($job['paper'], ['A4', 'Letter'], true) ? $job['paper'] : 'A4',
        ];
        try {
            $range = $this->reusePageRange($job);
            if ($range !== '') $reviewOptions['page_ranges'] = $range;
        } catch (RuntimeException $e) {
            $this->finish('Não foi possível recuperar a seleção completa de páginas. Faça uma nova impressão escolhendo o intervalo desejado.', false);
            return;
        }
        try {
            $entry = $controller->createTemporaryCopyForSavedFile($job['stored_file'], $job['original_name'],
                strtolower(pathinfo($job['stored_file'], PATHINFO_EXTENSION)), (int) filesize($job['stored_file']),
                (string) ($job['mime_type'] ?? ''));
            $pageInfo = $controller->temporaryPageInfo($entry['token'], $reviewOptions['paper'], $reviewOptions['orientation']);
        } catch (Throwable $e) {
            if (isset($entry['token'])) $controller->destroyTemporaryUpload($entry['token']);
            $this->finish('Não foi possível preparar o arquivo para revisão. Faça um novo envio.', false);
            return;
        }
        $context = $controller->formContext();
        $userList = $context['userList'] ?? [];
        $printerStatus = $context['printerStatus'] ?? [];
        $maxUploadMb = $context['maxUploadMb'];
        $allowedExtensions = $context['allowedExtensions'];
        $reviewMode = true;
        $sharedFile = array_merge($pageInfo, [
            'upload_token' => $entry['token'], 'original_name' => $job['original_name'],
            'size_label' => round(filesize($job['stored_file']) / 1024) . ' KB',
            'page_label' => (int) $pageInfo['pages'] . ' páginas',
            'page_advice' => 'Arquivo recuperado com 1 cópia. Confira as opções e a seleção de páginas antes de enviar.',
        ]);
        require __DIR__ . '/../../views/print.php';
    }

    private function reusePageRange($job)
    {
        $pages = max(0, (int) ($job['pages'] ?? 0));
        $selected = max(0, (int) ($job['selected_pages_count'] ?? 0));
        if ($selected === 0 || $selected >= $pages) return '';

        // This is a display label capped at 120 bytes, not a complete machine selection.
        $label = (string) ($job['selected_pages_label'] ?? '');
        if (strlen($label) >= 120 || !preg_match('/^Páginas? ([0-9, -]+)$/u', $label, $matches)) {
            throw new RuntimeException('Seleção incompleta ou especial.');
        }
        $range = preg_replace('/\s+/', '', $matches[1]);
        if (!preg_match('/^[0-9]+(?:-[0-9]+)?(?:,[0-9]+(?:-[0-9]+)?)*$/', $range)) {
            throw new RuntimeException('Intervalo inválido.');
        }
        $plan = (new PrintService())->previewPlan($pages, 1, 1, ['page-ranges' => $range]);
        if ((int) $plan['selected_page_count'] !== $selected) {
            throw new RuntimeException('Seleção diverge do registro original.');
        }
        return $range;
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
        $job = $service->findVisible((int) ($_POST['id'] ?? 0), $_SESSION['user'], $isAdmin);
        if ($job === null || empty($job['stored_file']) || !$this->isDownloadableFile($job['stored_file'])) {
            $this->finish('Arquivo original não encontrado para reimpressão', false);
        }
        if (!PrintQueueService::canPrintAgain($job)) {
            $this->finish('Confira o resultado desta impressão antes de reenviar.', false);
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
            $this->finish('Não foi possível reimprimir. Verifique o aviso da impressora ou informe a equipe de TI.', false);
        }
    }

    public function cancel()
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
        $expected = $_SESSION['csrf_token'] ?? '';
        if (!is_string($token) || $expected === '' || !hash_equals($expected, $token)) {
            $this->finish('Token CSRF inválido', false, '/prints?status=active');
        }

        $service = new PrintJobService();
        $isAdmin = AuthService::isAdmin();
        $id = (int) ($_POST['id'] ?? 0);
        $cupsJobId = trim((string) ($_POST['cups_job_id'] ?? ''));
        $job = $cupsJobId === '' ? $service->findVisible($id, $_SESSION['user'], $isAdmin) : null;
        if (!empty($job['queue_payload'])) {
            $canceled = (new PrintQueueService())->cancel($id, $_SESSION['user'], $isAdmin);
            $this->finish(
                $canceled ? 'Impressão cancelada antes do envio.' : 'O trabalho saiu da fila de espera. Confira a situação em Meus envios.',
                $canceled
            );
        }
        if (!$isAdmin) {
            http_response_code(403);
            exit('Acesso negado');
        }

        if ($cupsJobId !== '') {
            $job = $service->findByCupsJobId($cupsJobId);
        } else {
            if ($job === null || !empty($job['legacy_usage'])) {
                $this->finish('Impressão não encontrada para cancelamento.', false, '/prints?status=active');
            }
            $cupsJobId = trim((string) ($job['cups_job_id'] ?? ''));
        }

        if (!empty($job['queue_payload']) && $job['status'] === 'processing') {
            $this->finish('O servidor está registrando o envio. Atualize a fila do CUPS para cancelar depois.', false, '/prints?status=active');
        }

        if ($cupsJobId === '') {
            $this->finish('Esta impressão ainda não tem número no CUPS. Atualize a fila em alguns segundos e tente novamente.', false, '/prints?status=active');
        }

        $result = (new CupsService($job['printer'] ?? null))->cancelJob($cupsJobId);
        if ($job !== null) {
            $service->updateCupsResult((int) $job['id'], $result);
        }
        if (!empty($result['success'])) {
            if ($job !== null) {
                try {
                    $service->markCanceled((int) $job['id'], $_SESSION['user'], 'Trabalho cancelado pelo administrador.');
                    $this->finish('Impressão cancelada na fila do CUPS.', true, '/prints?status=active');
                } catch (Throwable $e) {
                    $this->finish('Cancelamento enviado ao CUPS, mas o registro local já havia mudado. Atualize a fila para conferir o estado atual.', true, '/prints?status=active');
                }
            }

            $this->finish('Impressão cancelada no CUPS. Não havia registro local vinculado a esse trabalho.', true, '/prints?status=active');
        }

        $message = trim((string) (($result['error_message'] ?? '') ?: ($result['stderr'] ?? '') ?: ($result['stdout'] ?? '')));
        $this->finish('Não foi possível cancelar a impressão' . ($message !== '' ? ': ' . $this->safeOutputMessage($message) : '.'), false, '/prints?status=active');
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

    private function downloadFilename($name)
    {
        $name = basename((string) $name);
        $name = preg_replace('/[\r\n"]+/', '', $name);
        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name);
        $name = trim((string) $name, " ._\t");

        return $name !== '' ? $name : 'arquivo';
    }

    private function safeOutputMessage($message)
    {
        $message = trim(strip_tags((string) $message));
        $message = preg_replace('/\s+/', ' ', $message);

        return substr((string) $message, 0, 220);
    }

    private function cupsConfirmationMessage($statusCups)
    {
        return match ((string) $statusCups) {
            'completed' => 'O servidor confirmou a conclusão da impressão.',
            'printer_fault' => 'O servidor detectou falha na impressora durante ou logo após o envio.',
            'left_queue' => 'O trabalho foi enviado para a impressora.',
            'accepted_unverified' => 'O trabalho foi enviado para a impressora.',
            'accepted_unidentified' => 'O trabalho foi enviado para a impressora.',
            'accepted' => 'O trabalho foi enviado para a impressora.',
            default => 'Status CUPS: ' . ((string) $statusCups !== '' ? (string) $statusCups : 'não informado') . '.',
        };
    }

    private function finish($message, $success, $redirect = '/prints')
    {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => $success, 'message' => $message]);
            exit;
        }

        $_SESSION['flash'] = $message;
        $_SESSION['flash_type'] = $success ? 'success' : 'error';
        header('Location: ' . $redirect);
        exit;
    }
}
