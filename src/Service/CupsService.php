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

    public function diagnostics()
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

        $status['printer_exists'] = $printerStatus['return_code'] === 0 && stripos($printerText, 'unknown') === false;
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

        $this->applyUserNotice($status);
        return $status;
    }

    public function preflight($filePath)
    {
        $checks = [
            'ok' => true,
            'reason' => '',
            'diagnostics' => $this->diagnostics(),
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
        if (!$diag['printer_exists']) {
            return $this->failedPreflight($checks, 'Impressora não encontrada no CUPS');
        }
        if (($diag['reason'] ?? '') === 'falta de papel') {
            return $this->failedPreflight($checks, 'Impressora sem papel. Não é possível enviar impressões.');
        }
        if (($diag['reason'] ?? '') === 'atolamento de papel') {
            return $this->failedPreflight($checks, 'Atolamento de papel na impressora. Não é possível enviar impressões.');
        }
        if ($diag['enabled'] === false) {
            return $this->failedPreflight($checks, 'Impressora desativada');
        }
        if ($diag['accepting'] === false) {
            return $this->failedPreflight($checks, 'Impressora não está aceitando impressões');
        }

        return $checks;
    }

    public function enablePrinter()
    {
        if ($this->printerName === '') {
            return ['success' => false, 'message' => 'PRINTER_NAME não configurada', 'commands' => []];
        }
        if ($this->isWindows()) {
            return ['success' => false, 'message' => 'Reativação automática disponível apenas em servidor Linux com CUPS', 'commands' => []];
        }

        $commands = [];
        foreach ([
            'cupsenable' => ['/usr/sbin/cupsenable', '/usr/bin/cupsenable', 'cupsenable'],
            'cupsaccept' => ['/usr/sbin/cupsaccept', '/usr/bin/cupsaccept', 'cupsaccept'],
        ] as $name => $candidates) {
            $bin = $this->findExecutable($candidates);
            if ($bin === null) {
                $commands[$name] = ['return_code' => 127, 'stdout' => '', 'stderr' => $name . ' nao encontrado'];
                continue;
            }
            $commands[$name] = $this->run(escapeshellarg($bin) . ' ' . escapeshellarg($this->printerName) . ' 2>&1');
        }

        $after = $this->diagnostics();
        $success = ($after['enabled'] !== false) && ($after['accepting'] !== false);

        return [
            'success' => $success,
            'message' => $success
                ? 'Comando de reativação enviado. Confira se há papel antes de imprimir.'
                : 'Não foi possível reativar pelo sistema. Verifique permissões do usuário web no CUPS.',
            'commands' => $commands,
            'diagnostics' => $after,
        ];
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

        while (time() <= $deadline) {
            $completed = $this->run(escapeshellarg($lpstat) . ' -W completed -o ' . escapeshellarg($jobId) . ' 2>&1');
            $completedText = $completed['stdout'] . "\n" . $completed['stderr'];
            if ($completed['return_code'] === 0 && preg_match('/(^|\s)' . preg_quote($jobId, '/') . '\s/', $completedText)) {
                $failed = preg_match('/cancel|abort|fail|error/i', $completedText) === 1;
                return [
                    'completed' => !$failed,
                    'status_cups' => $failed ? 'failed_or_canceled' : 'completed',
                    'error_message' => $failed ? $this->classifyReason($completedText) : '',
                ];
            }

            $active = $this->run(escapeshellarg($lpstat) . ' -W not-completed -o ' . escapeshellarg($jobId) . ' 2>&1');
            $activeText = $active['stdout'] . "\n" . $active['stderr'];
            if ($active['return_code'] === 0 && preg_match('/(^|\s)' . preg_quote($jobId, '/') . '\s/', $activeText)) {
                $seen = true;
                sleep(2);
                continue;
            }

            return [
                'completed' => true,
                'status_cups' => $seen ? 'left_queue' : 'accepted_unverified',
                'error_message' => '',
            ];
        }

        return [
            'completed' => false,
            'status_cups' => 'timeout',
            'error_message' => 'Tempo esgotado aguardando conclusão do trabalho no CUPS',
        ];
    }

    public function classifyReason($text)
    {
        $text = strtolower((string) $text);
        $map = [
            'CUPS parado ou erro de comunicação' => ['connection refused', 'bad file descriptor', 'scheduler is not running', 'failed to connect', 'cups server', 'not running'],
            'permissão negada' => ['permission denied', 'forbidden', 'not authorized', 'unauthorized'],
            'falha no filtro do CUPS' => ['filter failed', 'filter error', 'unsupported document-format'],
            'trabalho cancelado' => ['canceled', 'cancelled', 'aborted'],
            'falta de papel' => ['media-empty', 'paper-empty', 'out of paper', 'no paper', 'paper out'],
            'atolamento de papel' => ['paper-jam', 'jammed', 'paper jam'],
            'impressora desativada' => ['disabled', 'paused', 'stopped'],
            'impressora não aceita impressões' => ['not accepting', 'rejecting'],
            'toner ou suprimento' => ['toner', 'marker-supply', 'marker-waste', 'ink-empty', 'ink-low'],
            'offline' => ['offline', 'not connected', 'unreachable'],
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
            $status['notice'] = 'PRINTER_NAME não configurada. Não é possível enviar impressões.';
            return;
        }
        if ($reason === 'Diagnostico CUPS indisponivel no Windows') {
            $status['notice_type'] = 'info';
            $status['notice'] = 'Diagnóstico CUPS indisponível no Windows.';
            return;
        }
        if (($status['cups_active'] ?? null) === false) {
            $status['can_print'] = false;
            $status['notice_type'] = 'danger';
            $status['notice'] = 'CUPS parado ou inacessível. Não é possível enviar impressões.';
            return;
        }
        if (($status['printer_exists'] ?? true) === false) {
            $status['can_print'] = false;
            $status['notice_type'] = 'danger';
            $status['notice'] = 'Impressora não encontrada no CUPS.';
            return;
        }
        if ($reason === 'falta de papel') {
            $status['can_print'] = false;
            $status['notice_type'] = 'danger';
            $status['notice'] = 'Impressora sem papel. Não é possível enviar impressões.';
            return;
        }
        if ($reason === 'atolamento de papel') {
            $status['can_print'] = false;
            $status['notice_type'] = 'danger';
            $status['notice'] = 'Atolamento de papel na impressora. Não é possível enviar impressões.';
            return;
        }
        if ($enabled === false) {
            $status['can_print'] = false;
            $status['notice_type'] = 'warning';
            $status['notice'] = 'Impressora desativada no CUPS. Verifique papel/erro físico e reative a impressora.';
            return;
        }
        if ($accepting === false) {
            $status['can_print'] = false;
            $status['notice_type'] = 'warning';
            $status['notice'] = 'Impressora não está aceitando impressões no CUPS.';
            return;
        }
        if ($reason !== '') {
            $status['notice_type'] = 'warning';
            $status['notice'] = 'Atenção na impressora: ' . $reason . '.';
        }
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
