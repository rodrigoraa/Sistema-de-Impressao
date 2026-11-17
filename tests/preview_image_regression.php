<?php

require_once __DIR__ . '/../src/Service/PrintService.php';

$root = sys_get_temp_dir() . '/preview-image-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    // Valid two-page, landscape PDF with visible vector content.
    $stream = "0.1 0.4 0.7 rg 40 40 700 500 re f\n";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Contents 5 0 R >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Contents 5 0 R >>',
        '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . 'endstream',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach ($offsets as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);
    $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    file_put_contents($root . '/source.pdf', $pdf);
    $service = new PrintService();
    if ($service->previewImagePageCount($root . '/source.pdf') !== 2) throw new RuntimeException('Contagem inválida.');
    $service->renderPreviewPageImage($root . '/source.pdf', $root . '/normal.png', 2);
    $size = getimagesize($root . '/normal.png');
    if ($size[0] <= $size[1] || max($size[0], $size[1]) > 1500) throw new RuntimeException('Proporção ou limite inválido.');

    if (PHP_OS_FAMILY !== 'Windows') {
        file_put_contents($root . '/fail', "#!/bin/sh\nexit 1\n");
        chmod($root . '/fail', 0700);
        $_ENV['PDFTOPPM_PATH'] = $_ENV['PDFTOCAIRO_PATH'] = $root . '/fail';
        $service->renderPreviewPageImage($root . '/source.pdf', $root . '/ghostscript.png', 1);
        $size = getimagesize($root . '/ghostscript.png');
        if ($size[0] <= $size[1] || max($size[0], $size[1]) > 1500) throw new RuntimeException('Ghostscript alterou a geometria.');

        file_put_contents($root . '/slow', "#!/bin/sh\nexec sleep 10\n");
        chmod($root . '/slow', 0700);
        $_ENV['PDFINFO_PATH'] = $root . '/slow';
        $started = microtime(true);
        $count = $service->previewImagePageCount($root . '/source.pdf');
        if ($count !== 2 || microtime(true) - $started > 8) throw new RuntimeException('Contagem não respeitou timeout/fallback.');
    }
    echo "Imagem de prévia: PNG real, geometria, Ghostscript e contagem com timeout: OK\n";
} finally {
    foreach (glob($root . '/*') as $file) @unlink($file);
    @rmdir($root);
}
