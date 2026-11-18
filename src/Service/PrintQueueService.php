<?php

require_once __DIR__ . '/PrintJobService.php';
require_once __DIR__ . '/PrintService.php';
require_once __DIR__ . '/QuotaService.php';

class PrintQueueService
{
    private const INTERRUPTED = 'O envio foi interrompido e pode ter chegado à impressora. Confira com a equipe de TI antes de imprimir novamente.';

    private SQLite3 $db;
    private PrintJobService $jobs;
    private Closure $printerFactory;

    public function __construct(?Closure $printerFactory = null)
    {
        $this->db = Database::connect();
        $this->db->enableExceptions(true);
        $this->jobs = new PrintJobService($this->db);
        $this->printerFactory = $printerFactory ?? static fn ($job, $onJobId, $beforeSend) => new PrintService($job['printer'], $onJobId, $beforeSend);
    }

    public static function supported(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }

    public static function canPrintAgain(array $job): bool
    {
        return empty($job['queue_payload']) || !in_array($job['status'], ['queued', 'processing', 'interrupted'], true);
    }

    public function enqueue(
        Closure $createJob, string $file, int $pages, int $charge, int $quality, array $options,
        string $selectionLabel, int $selectedPages
    ): int {
        $payload = json_encode(['charge' => $charge, 'quality' => $quality, 'options' => $options], JSON_THROW_ON_ERROR);
        $saved = $this->directory() . '/impressao-' . bin2hex(random_bytes(16)) . '.pdf';
        $source = fopen($file, 'rb');
        if ($source === false) {
            throw new RuntimeException('Não foi possível abrir o PDF para guardar na fila.');
        }
        try {
            $target = fopen($saved, 'xb');
            if ($target === false) {
                throw new RuntimeException('Não foi possível criar o PDF da fila.');
            }
            try {
                $expected = fstat($source)['size'];
                if (!chmod($saved, 0600) || $expected < 1 || stream_copy_to_stream($source, $target) !== $expected
                    || !fflush($target) || (function_exists('fsync') && !fsync($target))) {
                    throw new RuntimeException('Não foi possível guardar o PDF completo na fila.');
                }

                $this->db->exec('BEGIN IMMEDIATE');
                try {
                    $id = $createJob($this->jobs);
                    $this->jobs->updateSelection($id, $selectionLabel, $selectedPages);
                    $stmt = $this->db->prepare("
                        UPDATE print_jobs
                        SET queue_payload = :payload, prepared_file = :file, pages = :pages
                        WHERE id = :id
                    ");
                    $stmt->bindValue(':payload', $payload, SQLITE3_TEXT);
                    $stmt->bindValue(':file', $saved, SQLITE3_TEXT);
                    $stmt->bindValue(':pages', $pages, SQLITE3_INTEGER);
                    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                    $stmt->execute();
                    $this->db->exec('COMMIT');
                    return $id;
                } catch (Throwable $e) {
                    $this->db->exec('ROLLBACK');
                    throw $e;
                }
            } catch (Throwable $e) {
                @unlink($saved);
                throw $e;
            } finally {
                fclose($target);
            }
        } finally {
            fclose($source);
        }
    }

    public function cancel(int $id, string $user, bool $isAdmin): bool
    {
        $job = $this->jobs->findVisible($id, $user, $isAdmin);
        if ($job === null || !$this->transition($id, 'queued', 'canceled', 'Cancelada antes do envio à impressora.')) {
            return false;
        }
        $this->deletePrepared($job);
        return true;
    }

    public function checkStorage(): void
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $this->db->exec("UPDATE print_jobs SET updated_at = updated_at WHERE 0");
            $dir = $this->directory();
            if (!is_readable($dir) || !is_writable($dir)) {
                throw new RuntimeException('O diretório da fila precisa permitir leitura e escrita.');
            }
        } finally {
            $this->db->exec('ROLLBACK');
        }
    }

    public function run(): void
    {
        $lock = fopen($this->directory() . '/worker.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Não foi possível abrir o bloqueio da fila.');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
                if (!$wouldBlock) {
                    throw new RuntimeException('Não foi possível bloquear a fila.');
                }
                return;
            }

            // Só um leitor pode recuperar ou enviar. Uma tentativa de envio abandonada
            // pode ter sido aceita pelo CUPS, inclusive quando o ID ainda não foi gravado.
            $stale = $this->db->query("
                SELECT id, prepared_file FROM print_jobs
                WHERE queue_payload IS NOT NULL AND status = 'processing'
            ");
            $abandoned = [];
            while ($job = $stale->fetchArray(SQLITE3_ASSOC)) {
                $abandoned[] = $job;
            }
            $stale->finalize();
            foreach ($abandoned as $job) {
                if ($this->transition((int) $job['id'], 'processing', 'interrupted', self::INTERRUPTED)) {
                    $this->deletePrepared($job);
                }
            }

            for ($attempts = 0; $attempts < 10; $attempts++) {
                $job = $this->db->querySingle("
                    SELECT * FROM print_jobs
                    WHERE queue_payload IS NOT NULL AND status = 'queued'
                    ORDER BY id LIMIT 1
                ", true);
                if (!$job || !$this->process($job)) {
                    break;
                }
            }
        } finally {
            fclose($lock);
        }
    }

    private function process(array $job): bool
    {
        $id = (int) $job['id'];
        $file = $job['prepared_file'];
        if (!is_file($file) || !is_readable($file) || filesize($file) < 1) {
            $this->transition($id, 'queued', 'failed', 'PDF da fila indisponível. Selecione o arquivo novamente.');
            $this->deletePrepared($job);
            return true;
        }

        $payload = json_decode($job['queue_payload'], true, 512, JSON_THROW_ON_ERROR);
        $started = false;
        $beforeSend = function () use ($id, &$started) {
            $started = $this->transition($id, 'queued', 'processing');
            return $started;
        };
        $printer = ($this->printerFactory)($job, fn ($result) => $this->jobs->updateCupsResult($id, $result), $beforeSend);
        try {
            $sent = $printer->printPrepared(
                $file, $job['source_ext'], (int) $job['copies'], $job['sides'], $job['orientation'],
                $payload['quality'], (int) $job['number_up'], $payload['options']
            );
        } catch (Throwable $e) {
            $sent = false;
            $printer->log('Fila de impressão ' . $id . ': ' . $e->getMessage());
        }
        $result = $printer->lastPrintResult();
        $cupsStatus = $result['status_cups'] ?? '';
        if (!$started) {
            if ($cupsStatus === 'preflight_failed') {
                // O cancelamento pode ter ocorrido durante a consulta. Não altera um trabalho cancelado.
                return !$this->transition($id, 'queued', 'queued', $result['error_message'] ?? 'Aguardando a impressora.');
            }
            if ($this->transition($id, 'queued', 'failed', 'Falha antes do envio. Avise a equipe de TI.')) {
                $this->deletePrepared($job);
            }
            return true;
        }

        $result['diagnostics']['printer'] ??= $job['printer'];
        $this->jobs->updateCupsResult($id, $result);
        if ($sent) {
            $this->db->exec('BEGIN IMMEDIATE');
            try {
                $charge = (int) $payload['charge'];
                $this->jobs->markCompleted($id, $file, (int) $job['pages'], $charge);
                (new QuotaService($this->db))->register($job['user'], $charge, $job['stored_file']);
                $this->db->exec('COMMIT');
            } catch (Throwable $e) {
                $this->db->exec('ROLLBACK');
                throw $e;
            }
        } elseif ($cupsStatus === 'lp_not_found') {
            // Nenhum comando foi executado. A próxima leitura pode tentar de novo.
            $this->transition($id, 'processing', 'queued', $result['error_message'] ?? 'Aguardando a impressora.');
            return false;
        } else {
            $this->transition($id, 'processing', 'interrupted', self::INTERRUPTED);
        }
        $this->deletePrepared($job);
        return true;
    }

    private function transition(int $id, string $from, string $to, ?string $message = null): bool
    {
        $now = date('Y-m-d H:i:s');
        $finished = in_array($to, ['canceled', 'failed', 'interrupted'], true);
        $stmt = $this->db->prepare("
            UPDATE print_jobs
            SET status = :to, error_message = :message, updated_at = :now, completed_at = :finished,
                status_cups = CASE WHEN :to = 'processing' THEN NULL ELSE status_cups END
            WHERE id = :id AND status = :from AND queue_payload IS NOT NULL
        ");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->bindValue(':from', $from, SQLITE3_TEXT);
        $stmt->bindValue(':to', $to, SQLITE3_TEXT);
        $stmt->bindValue(':message', $message, $message === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $stmt->bindValue(':now', $now, SQLITE3_TEXT);
        $stmt->bindValue(':finished', $finished ? $now : null, $finished ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->execute();
        return $this->db->changes() === 1;
    }

    private function directory(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/print-queue';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar o diretório da fila.');
        }
        return $dir;
    }

    private function deletePrepared(array $job): void
    {
        $file = $job['prepared_file'] ?? '';
        if (dirname($file) === $this->directory() && preg_match('/^impressao-[a-f0-9]{32}\.pdf$/D', basename($file))) {
            @unlink($file);
        }
    }
}
