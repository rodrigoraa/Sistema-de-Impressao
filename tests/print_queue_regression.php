<?php

function expect($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (($argv[1] ?? '') !== '--fixture') {
    $root = sys_get_temp_dir() . '/print-queue-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    try {
        $source = dirname(__DIR__);
        foreach (['src', 'tests'] as $directory) {
            exec('cp -R ' . escapeshellarg("$source/$directory") . ' ' . escapeshellarg($root), $output, $code);
            expect($code === 0, 'Falha ao criar fixture');
        }
        passthru(escapeshellarg(PHP_BINARY) . ' -d ' . escapeshellarg('extension_dir=' . ini_get('extension_dir')) . ' ' . escapeshellarg("$root/tests/print_queue_regression.php") . ' --fixture', $code);
        expect($code === 0, 'Regressão da fila falhou');
    } finally {
        exec('rm -rf -- ' . escapeshellarg($root));
    }
    exit;
}

require_once __DIR__ . '/../src/Service/PrintQueueService.php';

class QueuePrinterFixture extends PrintService
{
    public $calls = 0;
    public $mode = 'offline';
    private $result = [];
    public $beforeSend;
    public $onAccepted;
    public $beforeCheck;

    public function printPrepared($file, $ext, $copies, $sides, $orientation, $quality, $numberUp, $options = [])
    {
        $this->calls++;
        expect(file_get_contents($file) === '%PDF-test', 'PDF deve sobreviver à remoção do temporário');
        expect($copies === 2 && $quality === 5 && $options['media'] === 'Letter', 'Opções persistidas devem chegar à impressão');
        $this->result = ['status_cups' => 'preflight_failed', 'error_message' => 'Sem papel'];
        if ($this->beforeCheck !== null) {
            ($this->beforeCheck)();
        }
        if ($this->mode === 'offline') {
            throw new RuntimeException('Sem papel');
        }
        if (!($this->beforeSend)()) {
            return false;
        }
        if ($this->mode === 'crash') {
            ($this->onAccepted)(['status_cups' => 'accepted', 'job_id' => 'fixture-99']);
            exit(17);
        }
        if ($this->mode === 'interrupted') {
            throw new RuntimeException('Conexão interrompida durante lp');
        }
        $this->result = ['status_cups' => 'accepted', 'job_id' => 'fixture-42'];
        return true;
    }

    public function lastPrintResult()
    {
        return $this->mode === 'interrupted' ? [] : $this->result;
    }
}

$_ENV['PRINTER_NAME'] = 'fixture';
$jobs = new PrintJobService();
$printer = new QueuePrinterFixture();
$factory = static function ($job, $onAccepted, $beforeSend) use ($printer) {
    $printer->onAccepted = $onAccepted;
    $printer->beforeSend = $beforeSend;
    return $printer;
};
$queue = new PrintQueueService($factory);
if (isset($argv[2])) {
    $printer->mode = 'crash';
    $queue->run();
    exit(0);
}
$makeJob = static function () use ($queue) {
    $file = tempnam(sys_get_temp_dir(), 'queue-source-');
    file_put_contents($file, '%PDF-test');
    $create = static fn (PrintJobService $service) =>
        $service->create('111', 'atividade.pdf', '/uploads/atividade.pdf', 'pdf', 2, 2, 'one-sided', 'portrait', 'Letter');
    try {
        return $queue->enqueue($create, $file, 3, 4, 5, ['media' => 'Letter'], 'Todas as páginas', 3);
    } finally {
        unlink($file);
    }
};
$job = static fn ($id) => $jobs->findVisible($id, '111');

$canceled = $makeJob();
expect(!$queue->cancel($canceled, '222', false), 'Cancelamento deve ser restrito ao dono ou administrador');
expect($queue->cancel($canceled, '111', false), 'O professor deve poder cancelar o próprio pendente');
expect(!$queue->cancel($canceled, '111', false), 'Cancelamento repetido deve ser recusado');
expect($job($canceled)['status'] === 'canceled', 'Histórico deve exibir cancelado');

$pending = $makeJob();
$legacy = $jobs->create('111', 'antigo.pdf', '/uploads/antigo.pdf', 'pdf', 1, 1, 'one-sided', 'portrait', 'A4');
$queue->run();
expect($job($pending)['status'] === 'queued', 'Pré-validação indisponível deve manter pendente');
expect($printer->calls === 1, 'A fila deve aguardar a próxima execução após pré-validação indisponível');
$printer->mode = 'online';
$queue = new PrintQueueService($factory);
$queue->run();
$queue->run();
expect($job($pending)['status'] === 'completed' && (int) $job($pending)['charged_pages'] === 4, 'Envio deve concluir e contabilizar');
expect($job($pending)['cups_job_id'] === 'fixture-42', 'Identificador CUPS deve ficar no histórico');
expect($job($legacy)['status'] === 'queued', 'Registros legados não devem ser enviados');
$db = Database::connect();
expect((int) $db->querySingle('SELECT COUNT(*) FROM usage') === 1, 'Contabilização deve ocorrer uma única vez');

$interrupted = $makeJob();
$printer->mode = 'interrupted';
$queue->run();
$recover = $makeJob();
passthru(escapeshellarg(PHP_BINARY) . ' -d ' . escapeshellarg('extension_dir=' . ini_get('extension_dir'))
    . ' ' . escapeshellarg(__FILE__) . ' --fixture --crash', $code);
expect($code === 17, 'Fixture deve sair no meio de um envio aceito');
expect($job($recover)['status'] === 'processing' && $job($recover)['cups_job_id'] === 'fixture-99', 'ID do CUPS deve sobreviver à interrupção');
$before = $printer->calls;
$queue->run();
expect($job($interrupted)['status'] === 'interrupted' && $job($recover)['status'] === 'interrupted', 'Falhas ambíguas e envios abandonados devem exigir conferência');
expect($printer->calls === $before, 'Envios interrompidos nunca devem ser reenviados pelo worker');
expect((int) $db->querySingle('SELECT COUNT(*) FROM usage') === 1, 'Envios interrompidos não devem ser contabilizados');
$racing = $makeJob();
$printer->mode = 'online';
$printer->beforeCheck = static function () use ($queue, $racing, $printer) {
    $calls = $printer->calls;
    (new PrintQueueService())->run();
    expect($printer->calls === $calls, 'A segunda leitura deve respeitar o bloqueio do worker');
    expect($queue->cancel($racing, '111', false), 'Cancelamento deve vencer durante pré-validação');
};
$queue->run();
expect($job($racing)['status'] === 'canceled', 'Worker deve preservar o cancelamento concorrente');
expect((int) $db->querySingle('SELECT COUNT(*) FROM usage') === 1, 'Cancelamento concorrente não deve contabilizar');
echo "Fila persistente, cancelamento, contabilização e recuperação sem duplicação: OK\n";
