<?php

class CupsService
{
    private $printerName;
    private $logPath;

    public function __construct($printerName = null)
    {
        $this->printerName = $printerName ?? ($_ENV['PRINTER_NAME'] ?? '');
        $this->logPath = $_ENV['LOG_PATH'] ?? (dirname(__DIR__, 2) . '/storage/logs/app.log');
    }

    public function printerName()
    {
        return $this->printerName;
    }

    public function diagnostics($attemptAutoEnable = false)
    {
        $status = [
            'printer' => $this->printerName,
            'cups_active' => null,
            'printer_exists' => false,
            'enabled' => null,
            'accepting' => null,
            'printer_state' => '',
            'printer_state_message' => '',
            'reason' => '',
            'last_error' => '',
            'pending_jobs' => 0,
            'completed_jobs' => 0,
            'canceled_jobs' => 0,
            'failed_jobs' => 0,
            'can_print' => true,
            'notice_type' => 'success',
            'notice' => '',
            'auto_enable_result' => null,
            'raw' => [],
        ];

        if ($this->printerName === '') {
            $status['reason'] = 'PRINTER_NAME nao configurada';
            $status['last_error'] = $status['reason'];
            $this->applyUserNotice($status);
            return $status;
        }

        if ($this->isWindows()) {
            $status['reason'] = 'Diagnostico CUPS indisponivel no Windows';
            $this->applyUserNotice($status);
            return $status;
        }

        $lpstat = $this->findExecutable(['/usr/bin/lpstat', '/bin/lpstat', 'lpstat']);
        if ($lpstat === null) {
            $status['cups_active'] = false;
            $status['reason'] = 'lpstat nao encontrado';
            $status['last_error'] = $status['reason'];
            $this->applyUserNotice($status);
            return $status;
        }

        $printer = escapeshellarg($this->printerName);
        $printerStatus = $this->run(escapeshellarg($lpstat) . ' -p ' . $printer . ' 2>&1');
        $acceptStatus = $this->run(escapeshellarg($lpstat) . ' -a ' . $printer . ' 2>&1');
        $stateStatus = $this->run(escapeshellarg($lpstat) . ' -l -p ' . $printer . ' 2>&1');
        $pending = $this->run(escapeshellarg($lpstat) . ' -o ' . $printer . ' 2>&1');
        $completed = $this->run(escapeshellarg($lpstat) . ' -W completed -o ' . $printer . ' 2>&1');

        $status['raw'] = [
            'printer' => $printerStatus,
            'accepting' => $acceptStatus,
            'state' => $stateStatus,
            'pending' => $pending,
            'completed' => $completed,
        ];

        $status['cups_active'] = !($printerStatus['return_code'] !== 0 && $this->looksLikeCupsDown($printerStatus['stdout'] . "\n" . $printerStatus['stderr']));
        $printerText = trim($printerStatus['stdout'] . "\n" . $printerStatus['stderr']);
        $acceptText = trim($acceptStatus['stdout'] . "\n" . $acceptStatus['stderr']);
        $stateText = trim($stateStatus['stdout'] . "\n" . $stateStatus['stderr']);

        $printerKnownByAnyCommand = stripos($printerText . "\n" . $acceptText . "\n" . $stateText, 'unknown') === false
            && (
                preg_match('/printer\s+' . preg_quote($this->printerName, '/') . '\b/i', $printerText . "\n" . $stateText)
                || preg_match('/\b' . preg_quote($this->printerName, '/') . '\s+(accepting|not accepting)\b/i', $acceptText)
                || $stateStatus['return_code'] === 0
            );
        $status['printer_exists'] = $printerStatus['return_code'] === 0
            ? stripos($printerText, 'unknown') === false
            : $printerKnownByAnyCommand;
        if (preg_match('/\bis\s+disabled\b/i', $printerText)) {
            $status['enabled'] = false;
        } elseif (preg_match('/\bis\s+idle\b|\bis\s+printing\b|\bis\s+enabled\b/i', $printerText)) {
            $status['enabled'] = true;
        }

        if (preg_match('/\bnot accepting requests\b/i', $acceptText)) {
            $status['accepting'] = false;
        } elseif (preg_match('/\baccepting requests\b/i', $acceptText)) {
            $status['accepting'] = true;
        }

        if (preg_match('/printer-state\s*=\s*([^\s]+)/i', $stateText, $match)) {
            $status['printer_state'] = trim($match[1]);
        } elseif (preg_match('/printer\s+' . preg_quote($this->printerName, '/') . '\s+is\s+([^\n]+)/i', $printerText, $match)) {
            $status['printer_state'] = trim($match[1]);
        }

        if (preg_match('/printer-state-message\s*=\s*(.+)/i', $stateText, $match)) {
            $status['printer_state_message'] = trim($match[1], " \t\r\n\"");
        }

        $status['pending_jobs'] = $this->countJobLines($pending['stdout']);
        $status['completed_jobs'] = $this->countJobLines($completed['stdout']);
        $status['canceled_jobs'] = $this->countMatchingJobLines($completed['stdout'], '/cancel|abort/i');
        $status['failed_jobs'] = $this->countMatchingJobLines($completed['stdout'], '/fail|error|stopped/i');

        $combined = trim($printerText . "\n" . $acceptText . "\n" . $stateText);
        $status['reason'] = $this->classifyReason($combined);
        if ($printerStatus['return_code'] !== 0 || $acceptStatus['return_code'] !== 0 || $stateStatus['return_code'] !== 0) {
            $status['last_error'] = $this->truncate($combined, 1000);
        } elseif ($status['reason'] !== '') {
            $status['last_error'] = $status['reason'];
        }

        if ($attemptAutoEnable && $this->shouldAttemptAutoEnable($status)) {
            $autoEnable = $this->enablePrinter('auto');
            $freshStatus = $this->diagnostics(false);
            $freshStatus['auto_enable_result'] = $autoEnable;
            if (!empty($autoEnable['success'])) {
                $freshStatus['notice_type'] = 'success';
                $freshStatus['notice'] = 'Impressora reativada automaticamente. Já é possível enviar impressões.';
                $freshStatus['can_print'] = true;
            }
            return $freshStatus;
        }

        $this->applyUserNotice($status);
        return $status;
    }

    public function preflight($filePath)
    {
        $checks = [
            'ok' => true,
            'reason' => '',
            'diagnostics' => $this->diagnostics(true),
        ];

        if (!is_file($filePath)) {
            return $this->failedPreflight($checks, 'Arquivo não encontrado');
        }
        if (@filesize($filePath) < 1) {
            return $this->failedPreflight($checks, 'Arquivo vazio');
        }

        $diag = $checks['diagnostics'];
        if ($diag['cups_active'] === false) {
            return $this->failedPreflight($checks, 'CUPS parado ou inacessível');
        }
        if (($diag['reason'] ?? '') === 'falta de papel') {
            return $this->failedPreflight($checks, 'Impressora sem papel. Não é possível enviar impressões.');
        }
        if (($diag['reason'] ?? '') === 'atolamento de papel') {
            return $this->failedPreflight($checks, 'Atolamento de papel na impressora. Não é possível enviar impressões.');
        }
        if (!$diag['printer_exists']) {
            return $this->failedPreflight($checks, 'Impressora não encontrada no CUPS');
        }
        if ($diag['enabled'] === false) {
            return $this->failedPreflight($checks, 'Impressora desativada');
        }
        if ($diag['accepting'] === false) {
            return $this->failedPreflight($checks, 'Impressora não está aceitando impressões');
        }

        return $checks;
    }

    public function enablePrinter($source = 'manual')
    {
        if ($this->printerName === '') {
            $result = ['success' => false, 'message' => 'PRINTER_NAME não configurada', 'commands' => [], 'source' => $source, 'created_at' => date('Y-m-d H:i:s')];
            $this->writeEnableAttempt($source, $result);
            return $result;
        }
        if ($this->isWindows()) {
            $result = ['success' => false, 'message' => 'Reativação automática disponível apenas em servidor Linux com CUPS', 'commands' => [], 'source' => $source, 'created_at' => date('Y-m-d H:i:s')];
            $this->writeEnableAttempt($source, $result);
            return $result;
        }

        $commands = [];
        foreach ([
            'cupsenable' => ['/usr/sbin/cupsenable', '/usr/bin/cupsenable', 'cupsenable'],
            'cupsaccept' => ['/usr/sbin/cupsaccept', '/usr/bin/cupsaccept', 'cupsaccept'],
        ] as $name => $candidates) {
            $commands[$name] = $this->runCupsAdminCommand($name, $candidates);
        }

        $after = $this->diagnostics();
        $commandFailures = array_filter($commands, fn($cmd) => (int) ($cmd['return_code'] ?? 1) !== 0);
        $success = empty($commandFailures) && ($after['enabled'] !== false) && ($after['accepting'] !== false);

        $result = [
            'success' => $success,
            'message' => $success
                ? 'Comando de reativação enviado. Confira se há papel antes de imprimir.'
                : $this->enablePrinterFailureMessage($commands, $after),
            'commands' => $commands,
            'diagnostics' => $after,
            'source' => in_array($source, ['auto', 'manual'], true) ? $source : 'manual',
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $this->writeEnableAttempt($source, $result);

        return $result;
    }

    public function lastEnableAttempt()
    {
        $path = $this->enableAttemptPath();
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    public function runLp($filePath, $copies, $sides, $orientation, $quality, $numberUp, $extraOptions)
    {
        $lp = $this->findExecutable(['/usr/bin/lp', '/bin/lp', 'lp']);
        if ($lp === null) {
            return [
                'success' => false,
                'job_id' => null,
                'stdout' => '',
                'stderr' => 'Comando lp/CUPS nao encontrado no servidor',
                'return_code' => 127,
                'status_cups' => 'lp_not_found',
                'error_category' => 'cups_indisponivel',
                'error_message' => 'Comando lp/CUPS nao encontrado no servidor',
            ];
        }

        $args = [
            escapeshellarg($lp),
            '-d ' . escapeshellarg($this->printerName),
        ];
        if ((int) $copies > 1) {
            $args[] = '-n ' . (int) $copies;
        }
        if (in_array($orientation, ['portrait', 'landscape'], true)) {
            $args[] = '-o orientation-requested=' . ($orientation === 'landscape' ? '4' : '3');
        }
        $args[] = '-o print-quality=' . (int) $quality;
        $args[] = '-o sides=' . escapeshellarg($this->cupsSides($sides));
        if ((int) $numberUp > 1) {
            $args[] = '-o number-up=' . (int) $numberUp;
            $args[] = '-o number-up-layout=lrtb';
        }
        foreach ($this->cupsExtraOptions($extraOptions) as $key => $val) {
            $args[] = '-o ' . escapeshellarg($key . '=' . $val);
        }
        $args[] = escapeshellarg($filePath);

        $result = $this->run(implode(' ', $args));
        $text = trim($result['stdout'] . "\n" . $result['stderr']);
        $jobId = $this->extractCupsJobId($text);
        $errorCategory = $this->classifyReason($text);

        return [
            'success' => $result['return_code'] === 0,
            'job_id' => $jobId,
            'stdout' => $this->truncate($result['stdout'], 4000),
            'stderr' => $this->truncate($result['stderr'], 4000),
            'return_code' => $result['return_code'],
            'status_cups' => $result['return_code'] === 0 ? 'accepted' : 'lp_failed',
            'error_category' => $errorCategory,
            'error_message' => $result['return_code'] === 0 ? '' : ($errorCategory ?: 'Falha no comando lp/lpr'),
        ];
    }

    public function waitForJob($jobId)
    {
        $lpstat = $this->findExecutable(['/usr/bin/lpstat', '/bin/lpstat', 'lpstat']);
        if ($lpstat === null || $jobId === null || $jobId === '') {
            return [
                'completed' => true,
                'status_cups' => 'accepted_unverified',
                'error_message' => '',
            ];
        }

        $waitSeconds = max(1, (int) ($_ENV['PRINT_JOB_WAIT_SECONDS'] ?? 120));
        $deadline = time() + $waitSeconds;
        $seen = false;
        $lastDiagnostics = null;

        while (time() <= $deadline) {
            $completed = $this->run(escapeshellarg($lpstat) . ' -W completed -o ' . escapeshellarg($jobId) . ' 2>&1');
            $completedText = $completed['stdout'] . "\n" . $completed['stderr'];
            if ($completed['return_code'] === 0 && preg_match('/(^|\s)' . preg_quote($jobId, '/') . '\s/', $completedText)) {
                $failed = preg_match('/cancel|abort|fail|error/i', $completedText) === 1;
                return [
                    'completed' => !$failed,
                    'status_cups' => $failed ? 'failed_or_canceled' : 'completed',
                    'error_message' => $failed ? $this->classifyReason($completedText) : '',
                    'diagnostics' => $lastDiagnostics,
                ];
            }

            $active = $this->run(escapeshellarg($lpstat) . ' -W not-completed -o ' . escapeshellarg($jobId) . ' 2>&1');
            $activeText = $active['stdout'] . "\n" . $active['stderr'];
            if ($active['return_code'] === 0 && preg_match('/(^|\s)' . preg_quote($jobId, '/') . '\s/', $activeText)) {
                $seen = true;
                $activeReason = $this->classifyReason($activeText);
                if ($this->isBlockingPrinterReason($activeReason)) {
                    $lastDiagnostics = $this->diagnostics(false);
                    return [
                        'completed' => false,
                        'status_cups' => 'printer_fault',
                        'error_message' => $activeReason,
                        'diagnostics' => $lastDiagnostics,
                    ];
                }

                $lastDiagnostics = $this->diagnostics(false);
                $diagReason = $lastDiagnostics['reason'] ?? '';
                if ($this->isBlockingPrinterReason($diagReason)) {
                    return [
                        'completed' => false,
                        'status_cups' => 'printer_fault',
                        'error_message' => $diagReason,
                        'diagnostics' => $lastDiagnostics,
                    ];
                }

                sleep(2);
                continue;
            }

            return $this->monitorPrinterAfterJobLeavesQueue($seen);
        }

        return [
            'completed' => false,
            'status_cups' => 'timeout',
            'error_message' => 'Tempo esgotado aguardando conclusão do trabalho no CUPS',
            'diagnostics' => $lastDiagnostics,
        ];
    }

    private function monitorPrinterAfterJobLeavesQueue($seen)
    {
        $monitorSeconds = max(0, (int) ($_ENV['PRINT_JOB_AFTER_QUEUE_MONITOR_SECONDS'] ?? 45));
        if ($monitorSeconds < 1) {
            return [
                'completed' => true,
                'status_cups' => $seen ? 'left_queue' : 'accepted_unverified',
                'error_message' => '',
            ];
        }

        $deadline = time() + $monitorSeconds;
        $idleSeenAt = null;
        $idleConfirmSeconds = max(2, (int) ($_ENV['PRINT_JOB_IDLE_CONFIRM_SECONDS'] ?? 6));
        $lastDiagnostics = null;

        while (time() <= $deadline) {
            $lastDiagnostics = $this->diagnostics(false);
            $reason = $lastDiagnostics['reason'] ?? '';
            if ($this->isBlockingPrinterReason($reason)) {
                return [
                    'completed' => false,
                    'status_cups' => 'printer_fault',
                    'error_message' => $reason,
                    'diagnostics' => $lastDiagnostics,
                ];
            }

            $state = strtolower((string) ($lastDiagnostics['printer_state'] ?? ''));
            $message = strtolower((string) ($lastDiagnostics['printer_state_message'] ?? ''));
            $isPrinting = str_contains($state, 'printing') || str_contains($message, 'printing') || str_contains($message, 'imprimindo');
            $pendingJobs = (int) ($lastDiagnostics['pending_jobs'] ?? 0);

            if (!$isPrinting && $pendingJobs < 1) {
                if ($idleSeenAt === null) {
                    $idleSeenAt = time();
                }
                if ((time() - $idleSeenAt) >= $idleConfirmSeconds) {
                    return [
                        'completed' => true,
                        'status_cups' => $seen ? 'left_queue' : 'accepted_unverified',
                        'error_message' => '',
                        'diagnostics' => $lastDiagnostics,
                    ];
                }
            } else {
                $idleSeenAt = null;
            }

            sleep(2);
        }

        return [
            'completed' => true,
            'status_cups' => $seen ? 'left_queue' : 'accepted_unverified',
            'error_message' => '',
            'diagnostics' => $lastDiagnostics,
        ];
    }

    private function isBlockingPrinterReason($reason)
    {
        return in_array((string) $reason, [
            'falta de papel',
            'atolamento de papel',
            'tampa aberta',
            'toner ou suprimento',
            'offline',
            'erro de comunicação',
            'falha no filtro do CUPS',
            'impressora desativada',
        ], true);
    }

    public function classifyReason($text)
    {
        $text = strtolower((string) $text);
        $map = [
            'CUPS parado ou erro de comunicação' => ['connection refused', 'bad file descriptor', 'scheduler is not running', 'failed to connect', 'cups server', 'not running'],
            'permissão negada' => ['permission denied', 'forbidden', 'not authorized', 'unauthorized'],
            'falha no filtro do CUPS' => ['filter failed', 'filter error', 'unsupported document-format'],
            'trabalho cancelado' => ['canceled', 'cancelled', 'aborted'],
            'falta de papel' => ['media-empty', 'paper-empty', 'media-needed', 'out of paper', 'no paper', 'paper out', 'falta de papel', 'sem papel'],
            'atolamento de papel' => ['paper-jam', 'jammed', 'paper jam', 'atolamento', 'papel atolado'],
            'tampa aberta' => ['door-open', 'cover-open', 'tampa aberta', 'porta aberta'],
            'impressora desativada' => ['disabled', 'paused', 'stopped'],
            'impressora não aceita impressões' => ['not accepting', 'rejecting'],
            'toner ou suprimento' => ['toner', 'marker-supply', 'marker-waste', 'ink-empty', 'ink-low', 'toner-empty', 'toner-low', 'sem toner', 'toner baixo', 'suprimento'],
            'offline' => ['offline', 'not connected', 'unreachable', 'desconectada', 'fora da rede'],
            'formato não suportado' => ['unsupported format', 'unsupported document', 'unsupported document-format'],
            'arquivo inválido' => ['empty file', 'no file', 'cannot open file', 'not a pdf'],
            'tempo esgotado' => ['timeout', 'timed out'],
            'erro de comunicação' => ['backend failed', 'communication', 'network host', 'broken pipe'],
        ];

        foreach ($map as $label => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $label;
                }
            }
        }

        return '';
    }

    private function applyUserNotice(&$status)
    {
        $reason = $status['reason'] ?? '';
        $enabled = $status['enabled'] ?? null;
        $accepting = $status['accepting'] ?? null;

        $status['can_print'] = true;
        $status['notice_type'] = 'success';
        $status['notice'] = 'Impressora pronta para receber impressões.';

        if ($reason === 'PRINTER_NAME nao configurada') {
            $status['can_print'] = false;
            $status['notice_type'] = 'danger';
            $status['notice'] = 'A impressora do sistema ainda não foi configurada. Avise a equipe de TI.';
            return;
        }
        if ($reason === 'Diagnostico CUPS indisponivel no Windows') {
            $status['notice_type'] = 'info';
            $status['notice'] = 'O diagnóstico detalhado da impressora não está disponível neste servidor.';
            return;
        }
        if (($status['cups_active'] ?? null) === false) {
            $status['can_print'] = false;
            $status['notice_type'] = 'danger';
            $status['notice'] = 'O serviço de impressão do servidor está parado ou inacessível. Avise a equipe de TI.';
            return;
        }
        if ($reason === 'falta de papel') {
            $status['can_print'] = false;
            $status['notice_type'] = $enabled === false ? 'warning' : 'danger';
            $status['notice'] = $enabled === false
                ? 'A impressora está desativada porque ficou sem papel. Coloque papel e avise a equipe de TI para reativar.'
                : 'A impressora está sem papel. Coloque papel ou avise a equipe de TI.';
            return;
        }
        if ($reason === 'atolamento de papel') {
            $status['can_print'] = false;
            $status['notice_type'] = 'danger';
            $status['notice'] = 'Há papel atolado na impressora. Não envie novas impressões; avise a equipe de TI.';
            return;
        }
        if ($reason === 'toner ou suprimento') {
            $status['can_print'] = false;
            $status['notice_type'] = 'danger';
            $status['notice'] = 'A impressora está com problema de toner ou suprimento. Avise a equipe de TI.';
            return;
        }
        if ($reason === 'offline') {
            $status['can_print'] = false;
            $status['notice_type'] = 'danger';
            $status['notice'] = 'A impressora está offline ou desconectada da rede. Avise a equipe de TI.';
            return;
        }
        if ($reason === 'tampa aberta') {
            $status['can_print'] = false;
            $status['notice_type'] = 'warning';
            $status['notice'] = 'A tampa ou porta da impressora está aberta. Feche corretamente ou avise a equipe de TI.';
            return;
        }
        if ($reason === 'falha no filtro do CUPS') {
            $status['can_print'] = false;
            $status['notice_type'] = 'danger';
            $status['notice'] = 'O servidor não conseguiu processar o arquivo para impressão. Avise a equipe de TI.';
            return;
        }
        if ($reason === 'erro de comunicação') {
            $status['can_print'] = false;
            $status['notice_type'] = 'danger';
            $status['notice'] = 'O servidor perdeu comunicação com a impressora. Avise a equipe de TI.';
            return;
        }
        if (($status['printer_exists'] ?? true) === false) {
            $status['can_print'] = false;
            $status['notice_type'] = 'danger';
            $status['notice'] = 'A impressora configurada não foi encontrada pelo servidor. Avise a equipe de TI.';
            return;
        }
        if ($enabled === false) {
            $status['can_print'] = false;
            $status['notice_type'] = 'warning';
            $status['notice'] = 'A impressora está desativada no servidor. Avise a equipe de TI para reativar.';
            return;
        }
        if ($accepting === false) {
            $status['can_print'] = false;
            $status['notice_type'] = 'warning';
            $status['notice'] = 'A impressora não está aceitando novas impressões no servidor. Avise a equipe de TI.';
            return;
        }
        if ($reason !== '') {
            $status['notice_type'] = 'warning';
            $status['notice'] = 'Atenção na impressora: ' . $reason . '. Informe a equipe de TI.';
        }
    }

    private function enablePrinterFailureMessage($commands, $diagnostics)
    {
        $text = strtolower(json_encode($commands, JSON_UNESCAPED_UNICODE) ?: '');
        if (str_contains($text, 'a password is required') || str_contains($text, 'sudo: a password')) {
            return 'O servidor tentou reativar com sudo, mas o sudoers ainda não permite executar sem senha.';
        }
        if (str_contains($text, 'client-error-forbidden') || str_contains($text, 'forbidden') || str_contains($text, 'permission denied')) {
            return 'O servidor recebeu o comando, mas o CUPS negou permissão para reativar a impressora.';
        }
        if (str_contains($text, 'nao encontrado') || str_contains($text, 'not found')) {
            return 'O servidor recebeu o comando, mas não encontrou cupsenable/cupsaccept.';
        }
        if (($diagnostics['reason'] ?? '') === 'falta de papel') {
            return 'O servidor recebeu o comando, mas a impressora continua sem papel.';
        }

        return 'O servidor recebeu o comando, mas a impressora ainda não ficou pronta. Veja o retorno do CUPS abaixo.';
    }

    private function writeEnableAttempt($source, $result)
    {
        $payload = [
            'source' => in_array($source, ['auto', 'manual'], true) ? $source : 'manual',
            'printer' => $this->printerName,
            'created_at' => $result['created_at'] ?? date('Y-m-d H:i:s'),
            'success' => !empty($result['success']),
            'message' => $result['message'] ?? '',
            'commands' => $result['commands'] ?? [],
            'diagnostics' => $result['diagnostics'] ?? [],
        ];

        $path = $this->enableAttemptPath();
        $dir = dirname($path);
        if ($dir && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function enableAttemptPath()
    {
        $dir = dirname($this->logPath);
        if ($dir === '' || !is_dir($dir)) {
            $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        }

        return rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . 'cups-enable-last-' . substr(sha1($this->printerName ?: 'printer'), 0, 12) . '.json';
    }

    private function shouldAttemptAutoEnable($status)
    {
        $autoEnable = strtolower(trim((string) ($_ENV['CUPS_AUTO_ENABLE'] ?? '1')));
        if (in_array($autoEnable, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }
        if ($this->printerName === '' || $this->isWindows()) {
            return false;
        }
        if (($status['cups_active'] ?? null) === false || ($status['printer_exists'] ?? false) === false) {
            return false;
        }
        if (($status['enabled'] ?? null) !== false && ($status['accepting'] ?? null) !== false) {
            return false;
        }

        return $this->autoEnableThrottleAllows();
    }

    private function autoEnableThrottleAllows()
    {
        $seconds = max(10, (int) ($_ENV['CUPS_AUTO_ENABLE_INTERVAL_SECONDS'] ?? 60));
        $dir = dirname($this->logPath);
        if ($dir === '' || !is_dir($dir)) {
            $dir = sys_get_temp_dir();
        }
        $stamp = rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . 'cups-auto-enable-' . substr(sha1($this->printerName), 0, 12) . '.stamp';
        $last = is_file($stamp) ? (int) @filemtime($stamp) : 0;
        if ($last > 0 && (time() - $last) < $seconds) {
            return false;
        }

        @touch($stamp);
        return true;
    }

    private function runCupsAdminCommand($name, $candidates)
    {
        $bin = $this->findExecutable($candidates);
        if ($bin === null) {
            return ['return_code' => 127, 'stdout' => '', 'stderr' => $name . ' nao encontrado', 'used_sudo' => false];
        }

        $direct = $this->run(escapeshellarg($bin) . ' ' . escapeshellarg($this->printerName) . ' 2>&1');
        $direct['used_sudo'] = false;
        if ((int) $direct['return_code'] === 0 || !$this->looksLikePermissionDenied($direct['stdout'] . "\n" . $direct['stderr'])) {
            return $direct;
        }

        $sudo = $this->findExecutable(['/usr/bin/sudo', '/bin/sudo', 'sudo']);
        if ($sudo === null) {
            $direct['stderr'] = trim($direct['stderr'] . "\nsudo nao encontrado para tentar reativacao com privilegio");
            return $direct;
        }

        $withSudo = $this->run(escapeshellarg($sudo) . ' -n ' . escapeshellarg($bin) . ' ' . escapeshellarg($this->printerName) . ' 2>&1');
        $withSudo['used_sudo'] = true;
        $withSudo['direct_return_code'] = $direct['return_code'];
        $withSudo['direct_stdout'] = $direct['stdout'];
        $withSudo['direct_stderr'] = $direct['stderr'];

        return $withSudo;
    }

    private function looksLikePermissionDenied($text)
    {
        $text = strtolower((string) $text);
        return str_contains($text, 'client-error-forbidden')
            || str_contains($text, 'forbidden')
            || str_contains($text, 'permission denied')
            || str_contains($text, 'not authorized')
            || str_contains($text, 'unauthorized');
    }

    public function run($command)
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptor, $pipes);
        if (!is_resource($process)) {
            return ['stdout' => '', 'stderr' => 'Falha ao iniciar comando', 'return_code' => 127];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);

        $this->log('CUPS CMD: ' . $command . ' | code=' . $code . ' | stdout=' . trim($stdout) . ' | stderr=' . trim($stderr));

        return [
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
            'return_code' => (int) $code,
        ];
    }

    private function failedPreflight($checks, $reason)
    {
        $checks['ok'] = false;
        $checks['reason'] = $reason;
        return $checks;
    }

    private function countJobLines($text)
    {
        $count = 0;
        foreach (preg_split('/\R/', trim((string) $text)) as $line) {
            if (preg_match('/^\S+-\d+\s+/', $line)) {
                $count++;
            }
        }

        return $count;
    }

    private function countMatchingJobLines($text, $pattern)
    {
        $count = 0;
        foreach (preg_split('/\R/', trim((string) $text)) as $line) {
            if (preg_match('/^\S+-\d+\s+/', $line) && preg_match($pattern, $line)) {
                $count++;
            }
        }

        return $count;
    }

    private function extractCupsJobId($output)
    {
        if (!preg_match('/\b([A-Za-z0-9_.:@-]+-\d+)\b/', (string) $output, $match)) {
            return null;
        }

        return $match[1];
    }

    private function cupsSides($sides)
    {
        if ($sides === 'two-sided-short-edge') {
            return 'two-sided-short-edge';
        }
        if ($sides === 'two-sided-long-edge') {
            return 'two-sided-long-edge';
        }
        return 'one-sided';
    }

    private function cupsExtraOptions($extraOptions)
    {
        $allowed = [
            'media', 'paper', 'fit-to-page', 'scaling', 'page-ranges', 'page-set',
            'page-top', 'page-right', 'page-bottom', 'page-left',
        ];

        $safe = [];
        foreach ((array) $extraOptions as $key => $value) {
            $key = (string) $key;
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $value = (string) $value;
            if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                continue;
            }
            $safe[$key === 'paper' ? 'media' : $key] = $value;
        }

        return $safe;
    }

    private function looksLikeCupsDown($text)
    {
        $text = strtolower((string) $text);
        return str_contains($text, 'scheduler is not running')
            || str_contains($text, 'failed to connect')
            || str_contains($text, 'connection refused');
    }

    private function findExecutable($candidates)
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            if (is_file($candidate)) {
                return $candidate;
            }
            if (strpos($candidate, DIRECTORY_SEPARATOR) !== false || preg_match('/^[A-Z]:\\\\/i', $candidate)) {
                continue;
            }
            $command = $this->isWindows() ? 'where ' : 'command -v ';
            $stderr = $this->isWindows() ? ' 2>NUL' : ' 2>/dev/null';
            exec($command . escapeshellarg($candidate) . $stderr, $output, $status);
            if ($status === 0 && !empty($output[0]) && is_file($output[0])) {
                return $output[0];
            }
        }

        return null;
    }

    private function truncate($value, $limit)
    {
        $value = (string) $value;
        return strlen($value) > $limit ? substr($value, 0, $limit) : $value;
    }

    private function isWindows()
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    private function log($msg)
    {
        $path = $this->logPath;
        if (is_dir($path)) {
            $path = rtrim($path, "\\/") . DIRECTORY_SEPARATOR . 'app.log';
        }
        $dir = dirname($path);
        if ($dir && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL, FILE_APPEND);
    }
}
