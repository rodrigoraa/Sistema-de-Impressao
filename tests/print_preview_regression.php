<?php

require_once __DIR__ . '/../src/Service/PrintService.php';

function assertSameValue($expected, $actual, $label)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $label . ': esperado=' . json_encode($expected) . ' obtido=' . json_encode($actual) . PHP_EOL);
        exit(1);
    }
}

$service = new PrintService();

$oneUp = $service->previewPlan(6, 1, 3);
assertSameValue([1, 2, 3], $oneUp['preview_pages'], '1 por folha limita a três páginas');
assertSameValue(6, $oneUp['total_sheets'], '1 por folha calcula seis lados');
assertSameValue(3, $oneUp['preview_sheets'], '1 por folha mostra três lados');

$twoUp = $service->previewPlan(6, 2, 3);
assertSameValue([1, 2, 3, 4, 5, 6], $twoUp['preview_pages'], '2 por folha agrupa páginas 1-6');
assertSameValue(3, $twoUp['total_sheets'], '2 por folha calcula três lados');
assertSameValue(3, $twoUp['preview_sheets'], '2 por folha mostra três lados');

$fourUp = $service->previewPlan(6, 4, 3);
assertSameValue([1, 2, 3, 4, 5, 6], $fourUp['preview_pages'], '4 por folha mantém a ordem das seis páginas');
assertSameValue(2, $fourUp['total_sheets'], '4 por folha calcula dois lados');
assertSameValue(2, $fourUp['preview_sheets'], '4 por folha mostra dois lados');

$large = $service->previewPlan(40, 2, 3);
assertSameValue([1, 2, 3, 4, 5, 6], $large['preview_pages'], 'documento grande processa somente seis páginas');
assertSameValue(20, $large['total_sheets'], 'documento grande calcula vinte lados');
assertSameValue(3, $large['preview_sheets'], 'documento grande limita a amostra');
assertSameValue(17, $large['additional_sheets'], 'documento grande informa lados adicionais');

$selected = $service->previewPlan(10, 2, 3, ['page-ranges' => '1,3-5']);
assertSameValue([1, 3, 4, 5], $selected['preview_pages'], 'seleção é aplicada antes do N-up');
assertSameValue(4, $selected['selected_page_count'], 'seleção conta quatro páginas');
assertSameValue(2, $selected['total_sheets'], 'seleção gera dois lados');

$eightUp = $service->previewPlan(40, 8, 3);
assertSameValue(range(1, 24), $eightUp['preview_pages'], '8 por folha limita a vinte e quatro páginas');
assertSameValue(5, $eightUp['total_sheets'], '8 por folha calcula cinco lados');
assertSameValue(3, $eightUp['preview_sheets'], '8 por folha mostra três lados');

$cupsSource = (string) file_get_contents(__DIR__ . '/../src/Service/CupsService.php');
if (!str_contains($cupsSource, "number-up-layout=lrtb") || !str_contains($cupsSource, "number-up='")) {
    fwrite(STDERR, "O fluxo CUPS deixou de aplicar number-up/lrtb.\n");
    exit(1);
}

$printSource = (string) file_get_contents(__DIR__ . '/../src/Service/PrintService.php');
foreach (["'-i', 'application/pdf'", "'-m', 'application/vnd.cups-pdf'"] as $needle) {
    if (!str_contains($printSource, $needle)) {
        fwrite(STDERR, "A pré-visualização deixou de forçar o filtro CUPS pdftopdf: {$needle}\n");
        exit(1);
    }
}

if (!str_contains($cupsSource, '$this->truncate(trim($stdout), 4000)')
    || !str_contains($cupsSource, '$this->truncate(trim($stderr), 4000)')) {
    fwrite(STDERR, "O log CUPS deixou de limitar stdout/stderr.\n");
    exit(1);
}

$viewSource = (string) file_get_contents(__DIR__ . '/../views/print.php');
foreach (["fetch('/print/upload'", "printData.delete('arquivo')", 'new AbortController()', "'number_up'"] as $needle) {
    if (!str_contains($viewSource, $needle)) {
        fwrite(STDERR, "Proteção de upload/concorrência ausente na interface: {$needle}\n");
        exit(1);
    }
}

echo "Regressões da pré-visualização: OK\n";
