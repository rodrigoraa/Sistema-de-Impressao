<?php
require_once __DIR__ . '/../src/Service/PrintService.php';
$service = new PrintService();
if (!method_exists($service, 'adaptivePreviewPlan')) {
    throw new RuntimeException('Implementar política de prévia adaptativa.');
}
function checkAdaptive($expected, $actual, $message) {
    if ($expected !== $actual) throw new RuntimeException($message . ': esperado ' . json_encode($expected) . ', obtido ' . json_encode($actual));
}
$_ENV['PRINT_PREVIEW_MAX_SHEETS'] = 3;
$_ENV['PRINT_PREVIEW_AUTO_MAX_SHEETS'] = 20;
$_ENV['PRINT_PREVIEW_AUTO_MAX_BYTES'] = 10 * 1024 * 1024;

$small = $service->adaptivePreviewPlan(12, 1, 10000);
checkAdaptive(12, $small['preview_sheets'], 'Documento comum exibe tudo, acima do antigo teto de 10');
checkAdaptive(0, $small['additional_sheets'], 'Completa não omite páginas');
$boundary = $service->adaptivePreviewPlan(20, 1, 10 * 1024 * 1024);
checkAdaptive(20, $boundary['preview_sheets'], 'Limites inclusivos');
$large = $service->adaptivePreviewPlan(60, 2, 10000);
checkAdaptive(3, $large['preview_sheets'], 'Grande começa com amostra');
checkAdaptive(30, $large['total_sheets'], 'N-up entra antes da política');
$full = $service->adaptivePreviewPlan(60, 2, 10000, true);
checkAdaptive(30, $full['preview_sheets'], 'Pedido completo exibe todas as páginas da impressão');
checkAdaptive(0, $full['additional_sheets'], 'Completa explícita não trunca');
$heavy = $service->adaptivePreviewPlan(8, 1, 10 * 1024 * 1024 + 1);
checkAdaptive(3, $heavy['preview_sheets'], 'PDF pesado começa em amostra mesmo com poucas páginas');
checkAdaptive(8, $service->adaptivePreviewPlan(8, 1, 20 * 1024 * 1024, true)['preview_sheets'], 'Pedido explícito pode carregar PDF pesado');
$selected = $service->adaptivePreviewPlan(100, 1, 10000, false, ['page-ranges' => '1,3-6']);
checkAdaptive([1,3,4,5,6], $selected['preview_pages'], 'Seleção pequena em documento grande é completa');
checkAdaptive(1, $service->adaptivePreviewPlan(8, 8, 1000)['preview_sheets'], '8-up completo permanece fiel');
$_ENV['PRINT_PREVIEW_AUTO_MAX_SHEETS'] = 5;
checkAdaptive(3, $service->adaptivePreviewPlan(6, 1, 1000)['preview_sheets'], 'Limite configurável é respeitado');
echo "Prévia adaptativa: tamanho, seleção, N-up e modo completo: OK\n";
