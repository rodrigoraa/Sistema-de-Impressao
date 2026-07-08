<?php

require_once __DIR__ . '/../src/Service/CupsService.php';
require_once __DIR__ . '/../src/Service/PdfReportService.php';

$cups = new CupsService('kyocera-escola');

$cases = [
    'impressora desativada' => 'printer kyocera-escola disabled since Mon printer-state-message="Paused"',
    'impressora recusando trabalhos' => 'kyocera-escola not accepting requests since Mon',
    'CUPS fora do ar' => 'lpstat: Scheduler is not running',
    'arquivo invalido' => 'lp: Error - cannot open file',
    'erro no lp' => 'lp: Permission denied',
    'trabalho aceito' => 'request id is kyocera-escola-123 (1 file(s))',
    'trabalho cancelado' => 'kyocera-escola-123 user 1024 Mon canceled',
    'falha filtro' => 'stopped "Filter failed"',
    'falta papel' => 'printer-state-reasons=media-empty-warning',
    'desativada por falta de papel' => 'printer kyocera-escola disabled since Mon printer-state-reasons=media-empty-warning',
    'mensagem em portugues sem papel' => 'printer-state-message="falta de papel"',
    'toner' => 'marker-supply-low-warning toner low',
    'sem toner' => 'printer-state-message="sem toner"',
    'offline' => 'printer-state-reasons=offline-report',
    'tampa aberta' => 'printer-state-reasons=door-open-report',
    'atolamento portugues' => 'printer-state-message="papel atolado"',
];

echo "Simulacao de diagnostico CUPS\n";
foreach ($cases as $name => $text) {
    $reason = $cups->classifyReason($text);
    echo "- {$name}: " . ($reason !== '' ? $reason : 'sem erro classificado') . "\n";
}

$ippPaperEvidence = implode("\n", [
    'media-ready = iso_a4_210x297mm',
    'media-col-ready:',
    'media-source=tray-1',
    'printer-input-tray:',
    'name=Cassette 1',
    'level=150',
]);

if ($cups->classifyReason('printer-state-reasons=media-empty-error media-needed-warning', $ippPaperEvidence) !== '') {
    fwrite(STDERR, "IPP com papel nao deve bloquear por falta de papel\n");
    exit(1);
}

if ($cups->classifyReason('printer kyocera-escola disabled since Mon printer-state-reasons=media-empty-warning', $ippPaperEvidence) !== 'impressora desativada') {
    fwrite(STDERR, "IPP com papel deve preservar outros motivos de bloqueio\n");
    exit(1);
}

if ($cups->classifyReason('printer-state-reasons=media-empty-warning', "printer-input-tray:\nlevel=0") !== 'falta de papel') {
    fwrite(STDERR, "IPP sem papel nao deve mascarar falta de papel\n");
    exit(1);
}

echo "IPP com papel: OK\n";

$parseQueue = new ReflectionMethod(CupsService::class, 'parseActiveQueueOutput');
$parseQueue->setAccessible(true);
$queueRows = $parseQueue->invoke($cups, implode("\n", [
    'kyocera-escola-123 professor 2048 Wed Jul 08 10:20:00 2026',
    '        Title: prova-matematica.pdf',
    '        Rank: active',
    'kyocera-escola-124 secretaria 4096 Wed Jul 08 10:21:00 2026',
    '        Status: pending',
]));

if (count($queueRows) !== 2 || $queueRows[0]['job_id'] !== 'kyocera-escola-123' || $queueRows[0]['title'] !== 'prova-matematica.pdf') {
    fwrite(STDERR, "Parser da fila ativa do CUPS inesperado\n");
    exit(1);
}

echo "Fila ativa CUPS: OK\n";

$pdf = (new PdfReportService())->monthlyReport('Relatório mensal de impressões', [
    'month' => '2026-05',
    'cpf' => '',
    'include_failures' => true,
], [
    [
        'cpf' => '12345678900',
        'name' => 'Professor Teste',
        'jobs' => 2,
        'pages' => 10,
        'copies' => 2,
        'charged_pages' => 20,
        'statuses' => 'completed,failed',
        'errors' => 'falta de papel',
    ],
]);

if (substr($pdf, 0, 5) !== '%PDF-') {
    fwrite(STDERR, "PDF invalido\n");
    exit(1);
}

if (strpos($pdf, '/MediaBox [0 0 595 842]') === false) {
    fwrite(STDERR, "PDF mensal nao esta em modo retrato\n");
    exit(1);
}

echo "PDF mensal: OK (" . strlen($pdf) . " bytes)\n";
