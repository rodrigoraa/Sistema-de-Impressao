<?php

require_once __DIR__ . '/../src/Service/CupsService.php';
require_once __DIR__ . '/../src/Service/PdfReportService.php';

$cups = new CupsService('kyocera-escola');

$cases = [
    'impressora disabled' => 'printer kyocera-escola disabled since Mon printer-state-message="Paused"',
    'rejecting jobs' => 'kyocera-escola not accepting requests since Mon',
    'CUPS fora do ar' => 'lpstat: Scheduler is not running',
    'arquivo invalido' => 'lp: Error - cannot open file',
    'erro no lp' => 'lp: Permission denied',
    'job aceito' => 'request id is kyocera-escola-123 (1 file(s))',
    'job cancelado' => 'kyocera-escola-123 user 1024 Mon canceled',
    'falha filtro' => 'stopped "Filter failed"',
    'falta papel' => 'printer-state-reasons=media-empty-warning',
    'toner' => 'marker-supply-low-warning toner low',
];

echo "Simulacao de diagnostico CUPS\n";
foreach ($cases as $name => $text) {
    $reason = $cups->classifyReason($text);
    echo "- {$name}: " . ($reason !== '' ? $reason : 'sem erro classificado') . "\n";
}

$pdf = (new PdfReportService())->monthlyReport('Relatorio mensal de impressoes', [
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

echo "PDF mensal: OK (" . strlen($pdf) . " bytes)\n";
